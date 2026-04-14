<?php

namespace App\Http\Controllers;

use App\Events\VoteCountUpdated;
use App\Http\Requests\VotePollRequest;
use App\Models\Guest;
use App\Models\Poll;
use App\Models\PollOption;
use App\Models\Vote;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class PollController extends Controller
{

    public function index(Request $request)
    {
        return Inertia::render("Polls/Index", [
            "polls" => [],
        ]);
    }

    public function show(Request $request, Poll $poll)
    {
        return Inertia::render("Polls/Show", [
            "poll" => null,
        ]);
    }

    public function feed(Request $request)
    {
        return response()->json([
            "data" => [],
        ]);
    }

    public function vote(VotePollRequest $request, Poll $poll)
    {
        $validated = $request->validated();

        DB::transaction(function () use ($poll, $request, $validated): void {
            $guest = Guest::create([
                "user_id" => $request->user()?->id,
                "ip" => $request->ip(),
                "user_agent" => $request->userAgent(),
            ]);

            Vote::create([
                "poll_id" => $poll->id,
                "poll_option_id" => $validated["poll_option_id"],
                "guest_id" => $guest->id,
            ]);

            PollOption::where("id", $validated["poll_option_id"])->increment(
                "votes_count",
            );
        });

        $poll->load("options");
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

        return response()->json([], 201);
    }
}
