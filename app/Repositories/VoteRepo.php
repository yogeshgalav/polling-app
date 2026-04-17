<?php

namespace App\Repositories;

use App\Models\PollOption;
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

        $votedPollIds = $votesByPollId
            ->filter(static fn ($optionId) => $optionId !== null)
            ->keys()
            ->all();

        $optionRowsByPollId = collect();
        if ($votedPollIds !== []) {
            $optionRowsByPollId = PollOption::query()
                ->whereIn("poll_id", $votedPollIds)
                ->get(["id", "poll_id", "votes_count"])
                ->groupBy("poll_id");
        }

        $pollFeed["data"] = collect($pollFeed["data"])
            ->map(function (array $poll) use ($votesByPollId, $optionRowsByPollId): array {
                $votedOptionId = $votesByPollId->get($poll["id"]);
                $poll["voted_option_id"] =
                    $votedOptionId === null ? null : (int) $votedOptionId;

                if ($poll["voted_option_id"] === null) {
                    return $poll;
                }

                $rows = $optionRowsByPollId->get($poll["id"]);
                if ($rows === null) {
                    return $poll;
                }

                $countByOptionId = $rows->keyBy("id")->map(
                    static fn ($row) => (int) $row->votes_count,
                );

                $poll["options"] = collect($poll["options"] ?? [])
                    ->map(function (array $opt) use ($countByOptionId): array {
                        $id = $opt["id"];
                        $opt["votes_count"] = (int) $countByOptionId->get(
                            $id,
                            (int) ($opt["votes_count"] ?? 0),
                        );

                        return $opt;
                    })
                    ->values()
                    ->all();
                $poll["total_votes"] = (int) $rows->sum("votes_count");

                return $poll;
            })
            ->values()
            ->all();

        return $pollFeed;
    }
}

