<?php

namespace App\Http\Controllers;

use App\Http\Requests\VoteRequest;
use App\Http\Responses\Polls\FeedPollResponse;
use App\Http\Responses\Polls\ShowPollResponse;
use App\Events\VoteCountUpdated;
use App\Models\Poll;
use App\Models\PollOption;
use App\Models\Vote;
use App\Repositories\GuestRepo;
use App\Repositories\VoteRepo;
use App\Support\PollDeviceId;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
        $deviceId = PollDeviceId::get($request);

        return Inertia::render("Polls/Index", [
            "polls" => $this->pollFeed($request, $deviceId),
        ]);
    }

    public function show(Request $request, Poll $poll)
    {
        $poll->load("options");
        $deviceId = PollDeviceId::get($request);
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
        $deviceId = PollDeviceId::get($request);

        return response()->json($this->pollFeed($request, $deviceId));
    }

    public function vote(VoteRequest $request, Poll $poll)
    {
        $validated = $request->validated();
        $userId = $request->user()?->id;
        $deviceId = PollDeviceId::get($request);
        $ip = $request->ip();

        PollOption::query()
            ->whereKey($validated["poll_option_id"])
            ->where("poll_id", $poll->id)
            ->firstOrFail();

        $guest = GuestRepo::firstOrCreateByUserOrDeviceId(
            $userId,
            $deviceId !== "" ? $deviceId : null,
            $ip,
            $request->userAgent(),
        );

        $lockKey = "poll:{$poll->id}:guest:{$guest->id}:vote";
        $lockTtlSeconds = 10;
        $waitSeconds = 3;

        try {
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

                    try {
                        DB::transaction(function () use ($poll, $guest, $validated): void {
                            $option = PollOption::query()
                                ->whereKey($validated["poll_option_id"])
                                ->where("poll_id", $poll->id)
                                ->lockForUpdate()
                                ->firstOrFail();

                            Vote::query()->create([
                                "poll_id" => $poll->id,
                                "poll_option_id" => $option->id,
                                "guest_id" => $guest->id,
                            ]);

                            $option->increment("votes_count");
                        });
                    } catch (QueryException $e) {
                        if (($e->errorInfo[0] ?? null) === "23000") {
                            return ["already_voted" => true];
                        }

                        throw $e;
                    }

                    return ["already_voted" => false];
                },
            );
        } catch (LockTimeoutException) {
            return response()->json(
                ["message" => "Vote is being processed. Please retry."],
                429,
            );
        }

        if ($result["already_voted"]) {
            return response()->json(["message" => "Already voted"], 403);
        }

        $poll->refresh()->load("options");
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

        return response()->json(
            [
                "voted_option_id" => (int) $validated["poll_option_id"],
                "total_votes" => $totalVotes,
                "options" => $options,
            ],
            201,
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
