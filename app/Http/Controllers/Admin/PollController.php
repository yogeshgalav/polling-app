<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Poll;
use App\Models\PollOption;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Illuminate\Validation\ValidationException;

class PollController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(Poll::class, "poll");
    }

    public function index()
    {
        $polls = Poll::withCount("options")
            ->withSum("options", "votes_count")
            ->where("created_by", Auth::id())
            ->latest()
            ->get()
            ->map(
                fn (Poll $poll) => [
                    "id" => $poll->id,
                    "title" => $poll->title,
                    "slug" => $poll->slug,
                    "options_count" => $poll->options_count,
                    "has_votes" => (int) ($poll->options_sum_votes_count ?? 0) > 0,
                    "created_at" => $poll->created_at?->toIso8601String(),
                    "share_url" => url()->route("polls.show", $poll),
                ],
            );

        return Inertia::render("Admin/Polls/Index", [
            "polls" => $polls,
        ]);
    }

    public function create()
    {
        return Inertia::render("Admin/Polls/Create");
    }

    public function results(Poll $poll)
    {
        $this->authorize("viewResults", $poll);
        $poll->load("options");

        return Inertia::render("Admin/Polls/Results", [
            "poll" => [
                "id" => $poll->id,
                "title" => $poll->title,
                "slug" => $poll->slug,
                "share_url" => url()->route("polls.show", $poll),
                "total_votes" => (int) $poll->options->sum("votes_count"),
                "options" => $poll->options
                    ->map(fn(PollOption $option) => [
                        "id" => $option->id,
                        "label" => $option->label,
                        "votes_count" => (int) $option->votes_count,
                    ])
                    ->values()
                    ->all(),
            ],
        ]);
    }

    public function edit(Poll $poll)
    {
        $poll->load("options");

        return Inertia::render("Admin/Polls/Edit", [
            "poll" => [
                "id" => $poll->id,
                "title" => $poll->title,
                "slug" => $poll->slug,
                "share_url" => url()->route("polls.show", $poll),
                "options" => $poll->options
                    ->map(fn(PollOption $option) => [
                        "id" => $option->id,
                        "label" => $option->label,
                        "votes_count" => (int) $option->votes_count,
                    ])
                    ->values()
                    ->all(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            "title" => ["required", "string", "max:255"],
            "options" => ["required", "array", "min:2"],
            "options.*" => ["required", "string", "distinct", "max:255"],
        ]);

        $baseSlug = Str::slug($validated["title"]);
        $slug = $baseSlug;
        $n = 1;
        while (Poll::where("slug", $slug)->exists()) {
            $slug = $baseSlug . "-" . $n++;
        }

        $poll = Auth::user()->polls()->create([
            "title" => $validated["title"],
            "slug" => $slug,
        ]);

        foreach ($validated["options"] as $option) {
            $poll->options()->create([
                "label" => $option,
            ]);
        }

        for ($page = 1; $page <= 10; $page++) {
            Cache::forget('polls:'.$page);
        }
        return redirect()->route("admin.polls.index");
    }

    public function update(Request $request, Poll $poll)
    {
        $validated = $request->validate([
            "title" => ["required", "string", "max:255"],
            "options" => ["required", "array", "min:2"],
            "options.*.id" => ["nullable", "integer"],
            "options.*.label" => ["required", "string", "max:255"],
        ]);

        $incomingOptions = collect($validated["options"])
            ->map(function (array $option): array {
                $label = trim((string) ($option["label"] ?? ""));

                return [
                    "id" => isset($option["id"]) ? (int) $option["id"] : null,
                    "label" => $label,
                ];
            })
            ->filter(fn(array $option) => $option["label"] !== "")
            ->values();

        if ($incomingOptions->count() < 2) {
            throw ValidationException::withMessages([
                "options" => "Enter at least two different choices.",
            ]);
        }

        $labels = $incomingOptions->pluck("label")->map(fn($s) => mb_strtolower($s));
        if ($labels->count() !== $labels->unique()->count()) {
            throw ValidationException::withMessages([
                "options" => "Choices must be distinct.",
            ]);
        }

        $poll->load("options");
        $existingById = $poll->options->keyBy("id");
        $incomingIds = $incomingOptions
            ->pluck("id")
            ->filter(fn($id) => $id !== null)
            ->map(fn($id) => (int) $id)
            ->values();

        $invalidIds = $incomingIds->diff($existingById->keys());
        if ($invalidIds->isNotEmpty()) {
            throw ValidationException::withMessages([
                "options" => "One or more choices are invalid.",
            ]);
        }

        $poll->update([
            "title" => trim($validated["title"]),
        ]);

        foreach ($incomingOptions as $option) {
            if ($option["id"] !== null) {
                $existingById
                    ->get($option["id"])
                    ?->update(["label" => $option["label"]]);
                continue;
            }

            $poll->options()->create([
                "label" => $option["label"],
            ]);
        }

        $deleteIds = $existingById
            ->keys()
            ->diff($incomingIds);

        if ($deleteIds->isNotEmpty()) {
            $optionsToDelete = $poll->options->whereIn("id", $deleteIds->all());
            $hasVotes = $optionsToDelete->contains(
                fn(PollOption $option) => (int) $option->votes_count > 0,
            );

            if ($hasVotes) {
                throw ValidationException::withMessages([
                    "options" =>
                        "You can't remove a choice that already has votes.",
                ]);
            }

            PollOption::query()->whereIn("id", $deleteIds->all())->delete();
        }

        for ($page = 1; $page <= 10; $page++) {
            Cache::forget("polls:" . $page);
        }

        return redirect()->route("admin.polls.index");
    }

    public function destroy(Poll $poll)
    {
        $poll->delete();

        for ($page = 1; $page <= 10; $page++) {
            Cache::forget("polls:" . $page);
        }

        return redirect()->route("admin.polls.index");
    }
}
