<?php

namespace App\Http\Controllers\Admin;
use Inertia\Inertia;
use App\Models\Poll;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class PollController extends Controller
{
    public function index()
    {
        $polls = Poll::withCount("options")
            ->where("admin_id", auth()->user()->admin->id)
            ->latest()
            ->get();

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
            "question" => ["required", "string", "max:255"],
            "options" => ["required", "array", "min:2"],
            "options.*" => ["required", "string", "distinct", "max:255"],
        ]);

        $poll = auth()
            ->user()
            ->admin->polls()
            ->create([
                "question" => $validated["question"],
            ]);

        foreach ($validated["options"] as $option) {
            $poll->options()->create([
                "option_text" => $option,
            ]);
        }

        return redirect()->route("admin.polls.index");
    }
}
