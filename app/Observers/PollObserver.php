<?php

namespace App\Observers;

use App\Http\Responses\Polls\FeedPollResponse;
use App\Models\Poll;
use App\Support\CachedPollIds;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PollObserver
{
    public function created(Poll $poll): void
    {
        DB::afterCommit(function () use ($poll): void {
            $result = CachedPollIds::add((int) $poll->id);
            if (($result['evicted_id'] ?? null) !== null) {
                Cache::forget('poll:'.$result['evicted_id']);
            }

            $fresh = Poll::query()->with('options')->find($poll->id);
            if ($fresh === null) {
                return;
            }

            /** @var FeedPollResponse $feed */
            $feed = app(FeedPollResponse::class);
            Cache::put(
                "poll:{$fresh->id}",
                $feed($fresh),
                now()->addSeconds(CachedPollIds::TTL_SECONDS),
            );
        });
    }

    public function updated(Poll $poll): void
    {
        DB::afterCommit(function () use ($poll): void {
            $result = CachedPollIds::touch((int) $poll->id);
            if (($result['evicted_id'] ?? null) !== null) {
                Cache::forget('poll:'.$result['evicted_id']);
            }

            $fresh = Poll::query()->with('options')->find($poll->id);
            if ($fresh === null) {
                Cache::forget("poll:{$poll->id}");
                return;
            }

            /** @var FeedPollResponse $feed */
            $feed = app(FeedPollResponse::class);
            Cache::put(
                "poll:{$fresh->id}",
                $feed($fresh),
                now()->addSeconds(CachedPollIds::TTL_SECONDS),
            );
        });
    }

    public function deleted(Poll $poll): void
    {
        Cache::forget("poll:{$poll->id}");
        CachedPollIds::remove((int) $poll->id);
    }
}

