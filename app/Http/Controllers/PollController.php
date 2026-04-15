<?php

namespace App\Http\Controllers;

use App\Http\Requests\VoteRequest;
use App\Jobs\ProcessPollVote;
use App\Models\Guest;
use App\Models\Poll;
use App\Models\PollOption;
use App\Models\Vote;
use App\Repositories\GuestRepo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PollController extends Controller
{
    public function index(Request $request)
    {
        return Inertia::render("Polls/Index", [
            "polls" => $this->pollFeed($request),
        ]);
    }

    public function show(Request $request, Poll $poll)
    {
        $poll->load("options");
        $guest = GuestRepo::find($request->user()?->id, $request->ip());
        $votedOptionId =
            $guest === null
                ? null
                : Vote::query()
                    ->where("poll_id", $poll->id)
                    ->where("guest_id", $guest->id)
                    ->value("poll_option_id");
        $votedOptionId = $votedOptionId === null ? null : (int) $votedOptionId;

        return Inertia::render("Polls/Show", [
            "poll" => $this->showPollResponse($poll, $votedOptionId),
        ]);
    }

    public function feed(Request $request)
    {
        return response()->json($this->pollFeed($request));
    }

    public function vote(VoteRequest $request, Poll $poll)
    {
        $validated = $request->validated();
        $selectedOptionId = (int) $validated["poll_option_id"];

        $poll->loadMissing("options");
        $currentTotalVotes = (int) $poll->options->sum("votes_count");
        $options = $poll->options
            ->map(
                fn($option) => [
                    "id" => $option->id,
                    "votes_count" =>
                        (int) $option->votes_count +
                        ($option->id === $selectedOptionId ? 1 : 0),
                ],
            )
            ->values()
            ->all();

        ProcessPollVote::dispatch(
            pollId: $poll->id,
            pollOptionId: $selectedOptionId,
            userId: $request->user()?->id,
            ip: (string) $request->ip(),
            userAgent: $request->userAgent(),
        )->onQueue("high");

        return response()->json(
            [
                "message" => "Vote queued for processing",
                "voted_option_id" => $selectedOptionId,
                "total_votes" => $currentTotalVotes + 1,
                "options" => $options,
            ],
            202,
        );
    }

    private function pollFeed(Request $request): array
    {
        $pollFeed = null;

        if (
            !app()->isProduction() ||
            !preg_match('/^([1-9]|10)$/', $request->page)
        ) {
            $pollFeed = $this->paginatedPollFeed();
        } else {
            // for production cache feed forever until new poll is created.
            $pollFeed = Cache::rememberForever(
                "polls:" . $request->page,
                fn() => $this->paginatedPollFeed(),
            );
        }

        return $this->appendGuestVotes($pollFeed, $request);
    }

    private function paginatedPollFeed(): array
    {
        $pollsQuery = Poll::query()
            ->with("options")
            ->orderBy("created_at", "desc");

        // if ($request->user() !== null) {
        //     $pollsQuery->where("created_by", "!=", $request->user()->id);
        // }

        $polls = $pollsQuery->paginate(10);

        return [
            "data" => collect($polls->items())
                ->map(fn($poll) => $this->feedPollResponse($poll))
                ->values()
                ->all(),
            "current_page" => $polls->currentPage(),
            "last_page" => $polls->lastPage(),
        ];
    }

    private function appendGuestVotes(array $pollFeed, Request $request): array
    {
        $guest = GuestRepo::find($request->user()?->id, $request->ip());
        if ($guest === null || empty($pollFeed["data"])) {
            return $pollFeed;
        }

        $pollIds = collect($pollFeed["data"])->pluck("id")->all();
        $votesByPollId = Vote::query()
            ->where("guest_id", $guest->id)
            ->whereIn("poll_id", $pollIds)
            ->pluck("poll_option_id", "poll_id");

        $pollFeed["data"] = collect($pollFeed["data"])
            ->map(function (array $poll) use ($votesByPollId): array {
                $votedOptionId = $votesByPollId->get($poll["id"]);
                $poll["voted_option_id"] =
                    $votedOptionId === null ? null : (int) $votedOptionId;

                return $poll;
            })
            ->values()
            ->all();

        return $pollFeed;
    }

    private function feedPollResponse(Poll $poll): array
    {
        return [
            "id" => $poll->id,
            "title" => $poll->title,
            "slug" => $poll->slug,
            "is_open" => $poll->isOpen(),
            "expires_at" => $poll->expires_at?->toIso8601String(),
            "voted_option_id" => null,
            "total_votes" => (int) $poll->options->sum("votes_count"),
            "options" => $poll->options
                ->map(
                    fn($option) => array_filter(
                        [
                            "id" => $option->id,
                            "label" => $option->label,
                            "votes_count" => null,
                        ],
                        fn($value) => $value !== null,
                    ),
                )
                ->values()
                ->all(),
        ];
    }

    private function showPollResponse(Poll $poll, ?int $votedOptionId): array
    {
        $includeVoteCounts = $votedOptionId !== null;

        return [
            "id" => $poll->id,
            "title" => $poll->title,
            "slug" => $poll->slug,
            "is_open" => $poll->isOpen(),
            "expires_at" => $poll->expires_at?->toIso8601String(),
            "voted_option_id" => $votedOptionId,
            "total_votes" => (int) $poll->options->sum("votes_count"),
            "options" => $poll->options
                ->map(
                    fn($option) => array_filter(
                        [
                            "id" => $option->id,
                            "label" => $option->label,
                            "votes_count" => $includeVoteCounts
                                ? (int) $option->votes_count
                                : null,
                        ],
                        fn($value) => $value !== null,
                    ),
                )
                ->values()
                ->all(),
        ];
    }
}
