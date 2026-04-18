<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Poll;
use App\Models\PollOption;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PollApiController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(Poll::class, 'poll');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'options' => ['required', 'array', 'min:2'],
            'options.*' => ['required', 'string', 'distinct', 'max:255'],
        ]);

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

            for ($page = 1; $page <= 10; $page++) {
                Cache::forget('polls:'.$page);
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

    public function update(Request $request, Poll $poll)
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'options' => ['required', 'array', 'min:2'],
            'options.*.id' => ['nullable', 'integer'],
            'options.*.label' => ['required', 'string', 'max:255'],
        ]);

        $incomingOptions = collect($validated['options'])
            ->map(function (array $option): array {
                $label = trim((string) ($option['label'] ?? ''));

                return [
                    'id' => isset($option['id']) ? (int) $option['id'] : null,
                    'label' => $label,
                ];
            })
            ->filter(fn (array $option) => $option['label'] !== '')
            ->values();

        if ($incomingOptions->count() < 2) {
            throw ValidationException::withMessages([
                'options' => 'Enter at least two different choices.',
            ]);
        }

        $labels = $incomingOptions->pluck('label')->map(
            fn ($s) => mb_strtolower($s),
        );
        if ($labels->count() !== $labels->unique()->count()) {
            throw ValidationException::withMessages([
                'options' => 'Choices must be distinct.',
            ]);
        }

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

            $invalidIds = $incomingIds->diff($existingById->keys());
            if ($invalidIds->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'options' => 'One or more choices are invalid.',
                ]);
            }

            $poll->update([
                'title' => trim($validated['title']),
            ]);

            foreach ($incomingOptions as $option) {
                if ($option['id'] !== null) {
                    $existingById
                        ->get($option['id'])
                        ?->update(['label' => $option['label']]);

                    continue;
                }

                $poll->options()->create([
                    'label' => $option['label'],
                ]);
            }

            $deleteIds = $existingById->keys()->diff($incomingIds);

            if ($deleteIds->isNotEmpty()) {
                $optionsToDelete = $poll->options->whereIn(
                    'id',
                    $deleteIds->all(),
                );
                $hasVotes = $optionsToDelete->contains(
                    fn (PollOption $option) => (int) $option->votes_count > 0,
                );

                if ($hasVotes) {
                    throw ValidationException::withMessages([
                        'options' => "You can't remove a choice that already has votes.",
                    ]);
                }

                PollOption::query()->whereIn('id', $deleteIds->all())->delete();
            }

            for ($page = 1; $page <= 10; $page++) {
                Cache::forget('polls:'.$page);
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

        for ($page = 1; $page <= 10; $page++) {
            Cache::forget('polls:'.$page);
        }

        return response()->noContent();
    }
}
