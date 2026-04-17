<?php

namespace App\Actions\Polls;

use App\Events\VoteCountUpdated;
use App\Models\Poll;
use App\Models\PollOption;
use App\Models\Vote;
use App\Repositories\GuestRepo;
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
}
