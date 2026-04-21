<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePollRequest;
use App\Http\Requests\Admin\UpdatePollRequest;
use App\Models\Poll;
use App\Models\PollOption;
use App\Models\Vote;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PollApiController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(Poll::class, 'poll');
    }

    public function store(StorePollRequest $request)
    {
        $validated = $request->validated();

        $poll = DB::transaction(function () use ($validated) {
            $baseSlug = Str::slug($validated['title']);

            $existing = Poll::query()
                ->where(function ($query) use ($baseSlug) {
                    $query
                        ->where('slug', $baseSlug)
                        ->orWhere('slug', 'like', $baseSlug.'-%');
                })
                ->pluck('slug');

            if ($existing->isEmpty() || ! $existing->contains($baseSlug)) {
                $slug = $baseSlug;
            } else {
                $max = 0;

                foreach ($existing as $candidate) {
                    if ($candidate === $baseSlug) {
                        continue;
                    }

                    $parts = explode('-', $candidate);
                    $last = (string) (end($parts) ?: '');

                    if (ctype_digit($last)) {
                        $max = max($max, (int) $last);
                    } else {
                        $max = max($max, 1);
                    }
                }

                $slug = $baseSlug.'-'.($max + 1);
            }

            $poll = Auth::user()->polls()->create([
                'title' => trim($validated['title']),
                'slug' => $slug,
            ]);

            foreach ($validated['options'] as $option) {
                $poll->options()->create([
                    'label' => $option,
                ]);
            }

            return $poll;
        });

        return response()->json([
            'poll' => [
                'id' => $poll->id,
                'slug' => $poll->slug,
            ],
            'redirect' => route('admin.polls.index'),
        ]);
    }

    public function update(UpdatePollRequest $request, Poll $poll)
    {
        $validated = $request->validated();
        $incomingOptions = $request->normalizedOptions();

        DB::transaction(function () use (
            $poll,
            $validated,
            $incomingOptions,
        ) {
            $poll->load('options');
            $existingById = $poll->options->keyBy('id');
            $incomingIds = $incomingOptions
                ->pluck('id')
                ->filter(fn ($id) => $id !== null)
                ->map(fn ($id) => (int) $id)
                ->values();

            $poll->update([
                'title' => trim($validated['title']),
            ]);

            foreach ($incomingOptions as $option) {
                if ($option['id'] !== null) {
                    /** @var PollOption|null $existing */
                    $existing = $existingById->get($option['id']);

                    if ($existing === null) {
                        continue;
                    }

                    $nextLabel = $option['label'];
                    $labelChanged = $existing->label !== $nextLabel;

                    if ($labelChanged) {
                        Vote::query()
                            ->where('poll_id', $poll->id)
                            ->where('poll_option_id', $existing->id)
                            ->whereNull('deleted_at')
                            ->delete();

                        $existing->update([
                            'label' => $nextLabel,
                            'votes_count' => 0,
                        ]);
                    } else {
                        $existing->update(['label' => $nextLabel]);
                    }

                    continue;
                }

                $poll->options()->create([
                    'label' => $option['label'],
                ]);
            }

            $deleteIds = $existingById->keys()->diff($incomingIds);

            if ($deleteIds->isNotEmpty()) {
                Vote::query()
                    ->where('poll_id', $poll->id)
                    ->whereIn('poll_option_id', $deleteIds->all())
                    ->whereNull('deleted_at')
                    ->delete();

                PollOption::query()
                    ->whereIn('id', $deleteIds->all())
                    ->update(['votes_count' => 0]);

                PollOption::query()->whereIn('id', $deleteIds->all())->delete();
            }
        });

        $poll->refresh();

        return response()->json([
            'poll' => [
                'id' => $poll->id,
                'slug' => $poll->slug,
            ],
            'redirect' => route('admin.polls.index'),
        ]);
    }

    public function destroy(Poll $poll)
    {
        $poll->delete();

        return response()->noContent();
    }
}
