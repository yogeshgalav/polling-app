<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Poll;
use App\Models\PollOption;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class PollPageController extends Controller
{
    public function index()
    {
        $polls = Poll::withCount('options')
            ->withSum('options', 'votes_count')
            ->where('created_by', Auth::id())
            ->latest()
            ->get()
            ->map(
                fn (Poll $poll) => [
                    'id' => $poll->id,
                    'title' => $poll->title,
                    'slug' => $poll->slug,
                    'options_count' => $poll->options_count,
                    'has_votes' => (int) ($poll->options_sum_votes_count ?? 0) > 0,
                    'created_at' => $poll->created_at?->toIso8601String(),
                    'share_url' => url()->route('polls.show', $poll),
                ],
            );

        return Inertia::render('Admin/Polls/Index', [
            'polls' => $polls,
        ]);
    }

    public function create()
    {
        return Inertia::render('Admin/Polls/Create');
    }

    public function results(Poll $poll)
    {
        $poll->load('options');

        return Inertia::render('Admin/Polls/Results', [
            'poll' => [
                'id' => $poll->id,
                'title' => $poll->title,
                'slug' => $poll->slug,
                'share_url' => url()->route('polls.show', $poll),
                'total_votes' => (int) $poll->options->sum('votes_count'),
                'options' => $poll->options
                    ->map(fn (PollOption $option) => [
                        'id' => $option->id,
                        'label' => $option->label,
                        'votes_count' => (int) $option->votes_count,
                    ])
                    ->values()
                    ->all(),
            ],
        ]);
    }

    public function edit(Poll $poll)
    {
        $poll->load('options');

        return Inertia::render('Admin/Polls/Edit', [
            'poll' => [
                'id' => $poll->id,
                'title' => $poll->title,
                'slug' => $poll->slug,
                'share_url' => url()->route('polls.show', $poll),
                'options' => $poll->options
                    ->map(fn (PollOption $option) => [
                        'id' => $option->id,
                        'label' => $option->label,
                        'votes_count' => (int) $option->votes_count,
                    ])
                    ->values()
                    ->all(),
            ],
        ]);
    }
}
