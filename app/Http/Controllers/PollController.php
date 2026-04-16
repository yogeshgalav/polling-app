<?php

namespace App\Http\Controllers;

use App\Http\Requests\VoteRequest;
use App\Http\Responses\Polls\FeedPollResponse;
use App\Http\Responses\Polls\ShowPollResponse;
use App\Jobs\ProcessPollVote;
use App\Models\Guest;
use App\Models\Poll;
use App\Models\PollOption;
use App\Models\Vote;
use App\Repositories\GuestRepo;
use App\Repositories\VoteRepo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

class PollController extends Controller
{
    public function __construct(
        private readonly FeedPollResponse $feedPollResponse,
        private readonly ShowPollResponse $showPollResponse,
        private readonly VoteRepo $voteRepo,
    ) {}

    public function index(Request $request)
    {
        $deviceId =
            (string) ($request->cookie("poll_device_id") ?? Str::uuid());

        return Inertia::render("Polls/Index", [
            "polls" => $this->pollFeed($request, $deviceId),
        ]);
    }

    public function show(Request $request, Poll $poll)
    {
        $poll->load("options");
        $deviceId =
            (string) ($request->cookie("poll_device_id") ?? Str::uuid());
        $guest = GuestRepo::find($request->user()?->id, $deviceId);
        $votedOptionId =
            $guest === null
                ? null
                : Vote::query()
                    ->where("poll_id", $poll->id)
                    ->where("guest_id", $guest->id)
                    ->value("poll_option_id");
        $votedOptionId = $votedOptionId === null ? null : (int) $votedOptionId;

        return Inertia::render("Polls/Show", [
            "poll" => ($this->showPollResponse)($poll, $votedOptionId),
        ]);
    }

    public function feed(Request $request)
    {
        $deviceId =
            (string) ($request->cookie("poll_device_id") ?? Str::uuid());

        return response()->json($this->pollFeed($request, $deviceId));
    }

    public function vote(VoteRequest $request, Poll $poll)
    {
        $validated = $request->validated();
        $selectedOptionId = (int) $validated["poll_option_id"];
        $deviceId = (string) $request->cookie("poll_device_id");

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
            deviceId: $deviceId,
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

    private function pollFeed(Request $request, string $deviceId): array
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

        return $this->voteRepo->appendGuestVotes(
            $pollFeed,
            $request->user()?->id,
            $deviceId,
        );
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
                ->map(fn($poll) => ($this->feedPollResponse)($poll))
                ->values()
                ->all(),
            "current_page" => $polls->currentPage(),
            "last_page" => $polls->lastPage(),
        ];
    }
}
