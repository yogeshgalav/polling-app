<?php

namespace App\Http\Responses\Polls;

use App\Models\PollOption;
use Illuminate\Support\Collection;

class PollOptionResponse
{
    public function __invoke(PollOption $option, bool $includeVoteCounts): array
    {
        return collect([
            "id" => $option->id,
            "label" => $option->label,
        ])
            ->when(
                $includeVoteCounts,
                fn(Collection $data) => $data->put(
                    "votes_count",
                    (int) $option->votes_count,
                ),
            )
            ->all();
    }

    public function collection(Collection $options, bool $includeVoteCounts): array
    {
        return $options
            ->map(
                fn(PollOption $option) => ($this)(
                    $option,
                    includeVoteCounts: $includeVoteCounts,
                ),
            )
            ->values()
            ->all();
    }
}

