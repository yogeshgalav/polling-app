<?php

namespace App\Repositories;

use App\Models\Vote;

class VoteRepo
{
    public function appendGuestVotes(
        array $pollFeed,
        ?int $userId,
        string $deviceId,
    ): array {
        $guest = GuestRepo::find($userId, $deviceId);
        if ($guest === null || empty($pollFeed["data"])) {
            return $pollFeed;
        }

        $pollIds = collect($pollFeed["data"])->pluck("id")->all();
        $votesByPollId = Vote::query()
            ->where("guest_id", $guest->id)
            ->whereIn("poll_id", $pollIds)
            ->pluck("poll_option_id", "poll_id");

        $pollFeed["data"] = collect($pollFeed["data"])
            ->map(function (array $poll) use ($votesByPollId): array {
                $votedOptionId = $votesByPollId->get($poll["id"]);
                $poll["voted_option_id"] =
                    $votedOptionId === null ? null : (int) $votedOptionId;

                return $poll;
            })
            ->values()
            ->all();

        return $pollFeed;
    }
}

