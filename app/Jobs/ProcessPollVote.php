<?php

namespace App\Jobs;

use App\Events\VoteCountUpdated;
use App\Models\Poll;
use App\Models\PollOption;
use App\Models\Vote;
use App\Repositories\GuestRepo;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ProcessPollVote implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $pollId,
        public int $pollOptionId,
        public ?int $userId,
        public string $ip,
        public ?string $userAgent,
    ) {
    }

    public function handle(): void
    {
        $poll = Poll::query()->with("options")->find($this->pollId);
        if ($poll === null) {
            return;
        }

        $guest = GuestRepo::firstOrCreateByUserOrIp(
            userId: $this->userId,
            ip: $this->ip,
            userAgent: $this->userAgent,
        );

        $lockKey = "poll:{$poll->id}:guest:{$guest->id}:vote";
        $lockTtlSeconds = 10;
        $waitSeconds = 3;

        $didRecordVote = false;

        try {
            Cache::lock($lockKey, $lockTtlSeconds)->block(
                $waitSeconds,
                function () use ($poll, $guest, &$didRecordVote): void {
                    $alreadyVoted = Vote::query()
                        ->where("poll_id", $poll->id)
                        ->where("guest_id", $guest->id)
                        ->exists();

                    if ($alreadyVoted) {
                        return;
                    }

                    DB::transaction(function () use (
                        $poll,
                        $guest,
                        &$didRecordVote,
                    ): void {
                        Vote::query()->create([
                            "poll_id" => $poll->id,
                            "poll_option_id" => $this->pollOptionId,
                            "guest_id" => $guest->id,
                        ]);

                        PollOption::query()
                            ->where("id", $this->pollOptionId)
                            ->where("poll_id", $poll->id)
                            ->increment("votes_count");

                        $didRecordVote = true;
                    });
                },
            );
        } catch (LockTimeoutException $exception) {
            return;
        } catch (QueryException $exception) {
            // tolerate duplicate vote attempts due to retries/races
            return;
        }

        if (!$didRecordVote) {
            return;
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
    }
}
