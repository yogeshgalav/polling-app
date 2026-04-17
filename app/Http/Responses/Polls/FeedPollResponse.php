<?php

namespace App\Http\Responses\Polls;

use App\Models\Poll;

class FeedPollResponse
{
    public function __construct(
        private readonly PollOptionResponse $pollOptionResponse,
    ) {
    }

    public function __invoke(Poll $poll): array
    {
        return [
            "id" => $poll->id,
            "title" => $poll->title,
            "slug" => $poll->slug,
            "is_open" => $poll->isOpen(),
            "expires_at" => $poll->expires_at?->toIso8601String(),
            "voted_option_id" => null,
            "total_votes" => (int) $poll->options->sum("votes_count"),
            "options" => $this->pollOptionResponse->collection(
                $poll->options,
                includeVoteCounts: true,
            ),
        ];
    }
}

