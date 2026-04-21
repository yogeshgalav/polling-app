<?php

namespace App\Actions\Polls;

use App\Events\VoteCountUpdated;
use App\Models\Poll;
use App\Models\PollOption;
use App\Models\Vote;
use App\Repositories\GuestRepo;
use App\Support\CachedPollIds;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CastPollVoteAction
{
    /**
     * @return array{
     *     status: 'lock_timeout'|'already_voted'|'success',
     *     voted_option_id?: int,
     *     total_votes?: int,
     *     options?: list<array{id: int, votes_count: int}>
     * }
     */
    public function execute(
        Poll $poll,
        int $pollOptionId,
        ?int $userId,
        string $deviceId,
        ?string $ip,
        ?string $userAgent,
    ): array {
        PollOption::query()
            ->whereKey($pollOptionId)
            ->where('poll_id', $poll->id)
            ->firstOrFail();

        $guest = GuestRepo::firstOrCreateByUserOrDeviceId(
            $userId,
            $deviceId !== '' ? $deviceId : null,
            $ip,
            $userAgent,
        );

        $lockKey = "poll:{$poll->id}:guest:{$guest->id}:vote";
        $lockTtlSeconds = 10;
        $waitSeconds = 3;

        try {
            $result = Cache::lock($lockKey, $lockTtlSeconds)->block(
                $waitSeconds,
                function () use ($poll, $guest, $pollOptionId): array {
                    $alreadyVoted = Vote::query()
                        ->where('poll_id', $poll->id)
                        ->where('guest_id', $guest->id)
                        ->whereNull('deleted_at')
                        ->exists();

                    if ($alreadyVoted) {
                        return ['already_voted' => true];
                    }

                    try {
                        DB::transaction(function () use ($poll, $guest, $pollOptionId): void {
                            $option = PollOption::query()
                                ->whereKey($pollOptionId)
                                ->where('poll_id', $poll->id)
                                ->lockForUpdate()
                                ->firstOrFail();

                            Vote::query()->create([
                                'poll_id' => $poll->id,
                                'poll_option_id' => $option->id,
                                'guest_id' => $guest->id,
                            ]);

                            $option->increment('votes_count');
                        });
                    } catch (QueryException $e) {
                        if (($e->errorInfo[0] ?? null) === '23000') {
                            return ['already_voted' => true];
                        }

                        throw $e;
                    }

                    return ['already_voted' => false];
                },
            );
        } catch (LockTimeoutException) {
            return ['status' => 'lock_timeout'];
        }

        if ($result['already_voted']) {
            return ['status' => 'already_voted'];
        }

        $this->refreshCachedPollAfterVote($poll->id, $pollOptionId);

        $poll->refresh()->load('options');
        $totalVotes = (int) $poll->options->sum('votes_count');
        $options = $poll->options
            ->map(
                fn ($option) => [
                    'id' => $option->id,
                    'votes_count' => (int) $option->votes_count,
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

        return [
            'status' => 'success',
            'voted_option_id' => $pollOptionId,
            'total_votes' => $totalVotes,
            'options' => $options,
        ];
    }

    private function refreshCachedPollAfterVote(int $pollId, int $pollOptionId): void
    {
        // Keep DB ordering and cachedPollIds ordering in sync:
        // voting updates counts, but does NOT reorder cachedPollIds.
        if (! CachedPollIds::contains($pollId)) {
            return;
        }

        $key = "poll:{$pollId}";
        $cached = Cache::get($key);

        if (is_array($cached) && isset($cached['options']) && is_array($cached['options'])) {
            $cached['voted_option_id'] = null;
            $cached['total_votes'] = (int) ($cached['total_votes'] ?? 0) + 1;
            $cached['options'] = collect($cached['options'])
                ->map(function ($opt) use ($pollOptionId) {
                    if (! is_array($opt)) {
                        return $opt;
                    }
                    if ((int) ($opt['id'] ?? 0) !== $pollOptionId) {
                        return $opt;
                    }
                    $opt['votes_count'] = (int) ($opt['votes_count'] ?? 0) + 1;
                    return $opt;
                })
                ->values()
                ->all();

            Cache::put($key, $cached, now()->addSeconds(CachedPollIds::TTL_SECONDS));
            return;
        }

        // Cache miss or unexpected payload; rebuild minimal feed payload from DB.
        $poll = Poll::query()->with('options')->find($pollId);
        if ($poll === null) {
            Cache::forget($key);
            return;
        }

        $options = $poll->options
            ->map(fn ($opt) => [
                'id' => (int) $opt->id,
                'label' => (string) $opt->label,
                'votes_count' => (int) $opt->votes_count,
            ])
            ->values()
            ->all();

        Cache::put($key, [
            'id' => (int) $poll->id,
            'title' => (string) $poll->title,
            'slug' => (string) $poll->slug,
            'is_open' => $poll->isOpen(),
            'expires_at' => $poll->expires_at?->toIso8601String(),
            'voted_option_id' => null,
            'total_votes' => (int) collect($options)->sum('votes_count'),
            'options' => $options,
        ], now()->addSeconds(CachedPollIds::TTL_SECONDS));
    }
}
