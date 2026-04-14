<?php

namespace App\Http\Controllers;

use App\Events\VoteCountUpdated;
use App\Http\Requests\VoteRequest;
use App\Models\Guest;
use App\Models\Poll;
use App\Models\PollOption;
use App\Models\Vote;
use App\Repositories\GuestRepo;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class PollController extends Controller
{

    public function index(Request $request)
    {
        return Inertia::render("Polls/Index", [
            "polls" => $this->paginatedPollFeed($request),
        ]);
    }

    public function show(Request $request, Poll $poll)
    {
        $poll->load("options");
        $guest = GuestRepo::find($request->user()?->id, $request->ip());

        return Inertia::render("Polls/Show", [
            "poll" => $this->pollResponse($poll,$guest, true),
        ]);
    }

    public function feed(Request $request)
    {
        return response()->json($this->paginatedPollFeed($request));
    }

    public function vote(VoteRequest $request, Poll $poll)
    {
        $validated = $request->validated();
        $userId = $request->user()?->id;
        $guest = GuestRepo::firstOrCreateByUserOrIp(
            userId: $userId,
            ip: $request->ip(),
            userAgent: $request->userAgent(),
        );

        $lockKey = "poll:{$poll->id}:guest:{$guest->id}:vote";
        $lockTtlSeconds = 10;
        $waitSeconds = 3;

        try {
            // checking idepontency and recording vote should be atomic
            // although db level unique check is already there this is just for application layer check.
            // apply cache lock to prevent multiple votes from same guest at the same time which can happen due to multiple quick requests 
            // or websocket events from client side.
            $result = Cache::lock($lockKey, $lockTtlSeconds)->block(
                $waitSeconds,
                function () use ($poll, $guest, $validated): array {
                    $alreadyVoted = Vote::query()
                        ->where("poll_id", $poll->id)
                        ->where("guest_id", $guest->id)
                        ->exists();

                    if ($alreadyVoted) {
                        return ["already_voted" => true];
                    }

                    DB::transaction(function () use ($poll, $guest, $validated): void {
                        Vote::query()->create([
                            "poll_id" => $poll->id,
                            "poll_option_id" => $validated["poll_option_id"],
                            "guest_id" => $guest->id,
                        ]);

                        PollOption::query()
                            ->where("id", $validated["poll_option_id"])
                            ->where("poll_id", $poll->id)
                            ->increment("votes_count");
                    });

                    return ["already_voted" => false];
                },
            );
        } catch (LockTimeoutException $exception) {
            return response()->json([
                "message" => "Vote is being processed. Please retry.",
            ], 429);
        }

        if ($result["already_voted"]) {
            return response()->json([
                "message" => "Already voted",
            ], 403);
        }

        $poll->load("options");
        $totalVotes = (int) $poll->options->sum("votes_count");
        $options = $poll->options
            ->map(
                fn ($option) => [
                    "id" => $option->id,
                    "votes_count" => (int) $option->votes_count,
                ],
            )
            ->values()
            ->all();

        broadcast(
            new VoteCountUpdated(
                pollId: $poll->id,
                totalVotes: $totalVotes,
                options: $options,
            ),
        );

        return response()->json([
            "voted_option_id" => (int) $validated["poll_option_id"],
            "total_votes" => $totalVotes,
            "options" => $options,
        ], 201);
    }

    private function paginatedPollFeed(Request $request): array
    {
        if (! app()->isProduction() || !preg_match('/^([1-9]|10)$/', $request->page)) {
            return $this->pollFeed($request);
        }

        // for production cache feed forver until new poll is created.
        return Cache::rememberForever('polls:'.$request->page,
            fn () => $this->pollFeed($request),
        );
    }

    private function pollFeed(Request $request): array
    {
        $guest = GuestRepo::find($request->user()?->id, $request->ip());
        $pollsQuery = Poll::query()
            ->with("options")
            ->orderBy('created_at', 'desc');

        // if ($request->user() !== null) {
        //     $pollsQuery->where("created_by", "!=", $request->user()->id);
        // }

        $polls = $pollsQuery->paginate(10);

        return [
            "data" => collect($polls->items())
                ->map(
                    fn ($poll) => $this->pollResponse($poll,$guest,false ),
                )
                ->values()
                ->all(),
            "current_page" => $polls->currentPage(),
            "last_page" => $polls->lastPage(),
        ];
    }

    private function pollResponse($poll, $guest, $has_voted)
    {
        $votedOptionId = null;

        if ($guest !== null) {
            $votedOptionId = Vote::query()
                ->where("poll_id", $poll->id)
                ->where("guest_id", $guest->id)
                ->value("poll_option_id");
        }

        $includeVoteCounts = $has_voted && $votedOptionId !== null;
        return [
            "id" => $poll->id,
            "title" => $poll->title,
            "slug" => $poll->slug,
            "is_open" => $poll->isOpen(),
            "expires_at" => $poll->expires_at?->toIso8601String(),
            "voted_option_id" => $votedOptionId,
            "total_votes" => (int) $poll->options->sum("votes_count"),
            "options" => $poll->options->map(
                    fn ( $option) => array_filter([
                        "id" => $option->id,
                        "label" => $option->label,
                        "votes_count" => $includeVoteCounts
                            ? (int) $option->votes_count
                            : null,
                    ], fn ($value) => $value !== null),
                )
                ->values()
                ->all(),
        ];
    }
}
