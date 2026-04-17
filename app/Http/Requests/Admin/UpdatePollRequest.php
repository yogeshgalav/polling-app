<?php

namespace App\Http\Requests\Admin;

use App\Models\Poll;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Validator;

class UpdatePollRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'options' => ['required', 'array', 'min:2'],
            'options.*.id' => ['nullable', 'integer'],
            'options.*.label' => ['required', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            /** @var Poll|null $poll */
            $poll = $this->route('poll');
            if (! $poll instanceof Poll) {
                return;
            }

            $incomingOptions = $this->normalizedOptions();

            if ($incomingOptions->count() < 2) {
                $validator->errors()->add('options', 'Enter at least two different choices.');

                return;
            }

            $labels = $incomingOptions->pluck('label')->map(fn (string $s) => mb_strtolower($s));
            if ($labels->count() !== $labels->unique()->count()) {
                $validator->errors()->add('options', 'Choices must be distinct.');

                return;
            }

            $poll->load('options');
            $existingById = $poll->options->keyBy('id');
            $incomingIds = $incomingOptions
                ->pluck('id')
                ->filter(fn (?int $id) => $id !== null)
                ->map(fn (int $id) => $id)
                ->values();

            $invalidIds = $incomingIds->diff($existingById->keys());
            if ($invalidIds->isNotEmpty()) {
                $validator->errors()->add('options', 'One or more choices are invalid.');
            }
        });
    }

    /**
     * @return Collection<int, array{id: int|null, label: string}>
     */
    public function normalizedOptions(): Collection
    {
        $options = $this->input('options', []);

        if (! is_array($options)) {
            return collect();
        }

        return collect($options)
            ->map(function (mixed $option): array {
                $option = is_array($option) ? $option : [];
                $label = trim((string) ($option['label'] ?? ''));

                return [
                    'id' => isset($option['id']) ? (int) $option['id'] : null,
                    'label' => $label,
                ];
            })
            ->filter(fn (array $option) => $option['label'] !== '')
            ->values();
    }
}
