<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Poll;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Illuminate\Support\Facades\Cache;

class PollController extends Controller
{
    public function index()
    {
        $polls = Poll::withCount("options")
            ->where("created_by", auth()->id())
            ->latest()
            ->get()
            ->map(
                fn (Poll $poll) => [
                    "id" => $poll->id,
                    "title" => $poll->title,
                    "slug" => $poll->slug,
                    "options_count" => $poll->options_count,
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

        $poll = auth()->user()->polls()->create([
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
}
