<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class VoteCountUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public int $pollId,
        public int $totalVotes,
        public array $options,
    ) {
    }

    public function broadcastOn(): Channel
    {
        return new Channel("polls.{$this->pollId}");
    }

    public function broadcastAs(): string
    {
        return "votes.updated";
    }
}
