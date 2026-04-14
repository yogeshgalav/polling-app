<?php

namespace App\Http\Controllers;

use App\Events\VoteCountUpdated;
use App\Http\Requests\VoteRequest;
use App\Models\Poll;
use App\Models\PollOption;
use App\Models\Vote;
use App\Repositories\GuestRepo;
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

    public function vote(VoteRequest $request, Poll $poll)
    {
        $validated = $request->validated();
        $userId = $request->user()?->id;
        $guest = GuestRepo::firstOrCreateByUserOrIp(
            userId: $userId,
            ip: $request->ip(),
            userAgent: $request->userAgent(),
        );

        if (Vote::where("poll_id", $poll->id)->where("guest_id", $guest->id)->exists()) {
            return response()->json([
                "message" => "Already voted",
            ], 403);
        }

        DB::transaction(function () use ($poll, $guest, $validated): void { 
    
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
