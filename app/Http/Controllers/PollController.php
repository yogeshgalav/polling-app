<?php

namespace App\Http\Controllers;

use App\Actions\Polls\CastPollVoteAction;
use App\Http\Requests\VoteRequest;
use App\Http\Responses\Polls\FeedPollResponse;
use App\Http\Responses\Polls\ShowPollResponse;
use App\Models\Poll;
use App\Models\Vote;
use App\Repositories\GuestRepo;
use App\Repositories\VoteRepo;
use App\Support\PollDeviceId;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;

class PollController extends Controller
{
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
        $pollFeed = null;

        if (
            ! app()->isProduction() ||
            ! preg_match('/^([1-9]|10)$/', $request->page)
        ) {
            $pollFeed = $this->paginatedPollFeed();
        } else {
            // for production cache feed forever until new poll is created.
            $pollFeed = Cache::rememberForever(
                'polls:'.$request->page,
                fn () => $this->paginatedPollFeed(),
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
            ->with('options')
            ->orderBy('created_at', 'desc');

        // if ($request->user() !== null) {
        //     $pollsQuery->where("created_by", "!=", $request->user()->id);
        // }

        $polls = $pollsQuery->paginate(10);

        return [
            'data' => collect($polls->items())
                ->map(fn ($poll) => ($this->feedPollResponse)($poll))
                ->values()
                ->all(),
            'current_page' => $polls->currentPage(),
            'last_page' => $polls->lastPage(),
        ];
    }
}
