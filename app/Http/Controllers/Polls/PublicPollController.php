<?php

namespace App\Http\Controllers\Polls;

use App\Actions\Polls\CastPollVoteAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\VoteRequest;
use App\Http\Responses\Polls\FeedPollResponse;
use App\Http\Responses\Polls\ShowPollResponse;
use App\Models\Poll;
use App\Models\Vote;
use App\Repositories\GuestRepo;
use App\Repositories\VoteRepo;
use App\Support\CachedPollIds;
use App\Support\PollDeviceId;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;

class PublicPollController extends Controller
{
    private const PER_PAGE = 10;

    public function __construct(
        private readonly FeedPollResponse $feedPollResponse,
        private readonly ShowPollResponse $showPollResponse,
        private readonly VoteRepo $voteRepo,
        private readonly CastPollVoteAction $castPollVoteAction,
    ) {}

    public function index(Request $request)
    {
        $deviceId = PollDeviceId::get($request);

        return Inertia::render('Polls/Index', [
            'polls' => $this->pollFeed($request, $deviceId),
        ]);
    }

    public function show(Request $request, Poll $poll)
    {
        $poll->load('options');
        $deviceId = PollDeviceId::get($request);
        $guest = GuestRepo::find($request->user()?->id, $deviceId);
        $votedOptionId =
            $guest === null
                ? null
                : Vote::query()
                    ->where('poll_id', $poll->id)
                    ->where('guest_id', $guest->id)
                    ->whereNull('deleted_at')
                    ->value('poll_option_id');
        $votedOptionId = $votedOptionId === null ? null : (int) $votedOptionId;

        return Inertia::render('Polls/Show', [
            'poll' => ($this->showPollResponse)($poll, $votedOptionId),
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
        $pollOptionId = (int) $validated['poll_option_id'];

        $result = $this->castPollVoteAction->execute(
            $poll,
            $pollOptionId,
            $request->user()?->id,
            PollDeviceId::get($request),
            $request->ip(),
            $request->userAgent(),
        );

        return match ($result['status']) {
            'lock_timeout' => response()->json(
                ['message' => 'Vote is being processed. Please retry.'],
                429,
            ),
            'already_voted' => response()->json(
                ['message' => 'Already voted'],
                403,
            ),
            'success' => response()->json(
                [
                    'voted_option_id' => $result['voted_option_id'],
                    'total_votes' => $result['total_votes'],
                    'options' => $result['options'],
                ],
                201,
            ),
        };
    }

    private function pollFeed(Request $request, string $deviceId): array
    {
        $page = (int) ($request->page ?? 1);
        $page = $page > 0 ? $page : 1;

        // Serve first 50 polls from cache (max 5 pages).
        if ($page <= (int) ceil(CachedPollIds::LIMIT / self::PER_PAGE)) {
            $pollFeed = $this->cachedPollFeed($page);

            return $this->voteRepo->appendGuestVotes(
                $pollFeed,
                $request->user()?->id,
                $deviceId,
            );
        }

        return $this->voteRepo->appendGuestVotes(
            $this->paginatedPollFeed($page),
            $request->user()?->id,
            $deviceId,
        );
    }

    private function cachedPollFeed(int $page): array
    {
        $ids = $this->cachedPollIds();
        $offset = ($page - 1) * self::PER_PAGE;
        $pageIds = array_slice($ids, $offset, self::PER_PAGE);

        $data = collect($pageIds)
            ->map(fn (int $pollId) => $this->cachedPoll($pollId))
            ->filter()
            ->values()
            ->all();

        $total = Poll::query()->count();
        $lastPage = (int) max(1, (int) ceil($total / self::PER_PAGE));

        return [
            'data' => $data,
            'current_page' => $page,
            'last_page' => $lastPage,
        ];
    }

    private function cachedPollIds(): array
    {
        return CachedPollIds::getOrBuild(function (): array {
            return Poll::query()
                ->orderBy('updated_at', 'desc')
                ->limit(CachedPollIds::LIMIT)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();
        });
    }

    private function cachedPoll(int $pollId): ?array
    {
        $key = "poll:{$pollId}";

        $payload = Cache::remember($key, now()->addSeconds(CachedPollIds::TTL_SECONDS), function () use ($pollId): ?array {
            $poll = Poll::query()->with('options')->find($pollId);
            if ($poll === null) {
                return null;
            }

            return ($this->feedPollResponse)($poll);
        });

        if (! is_array($payload)) {
            Cache::forget($key);
            return null;
        }

        return $payload;
    }

    private function paginatedPollFeed(int $page): array
    {
        $total = Poll::query()->count();
        $lastPage = (int) max(1, (int) ceil($total / self::PER_PAGE));

        // For page >= 6, continue AFTER the first 50 cached polls.
        $offset = CachedPollIds::LIMIT + max(0, $page - 6) * self::PER_PAGE;

        $polls = Poll::query()
            ->with('options')
            ->orderBy('updated_at', 'desc')
            ->offset($offset)
            ->limit(self::PER_PAGE)
            ->get();

        return [
            'data' => collect($polls)
                ->map(fn ($poll) => ($this->feedPollResponse)($poll))
                ->values()
                ->all(),
            'current_page' => $page,
            'last_page' => $lastPage,
        ];
    }
}

