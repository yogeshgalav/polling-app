<?php

namespace App\Http\Requests;

use App\Models\Poll;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class VotePollRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var Poll|null $poll */
        $poll = $this->route("poll");

        return [
            "poll_option_id" => [
                "required",
                "integer",
                Rule::exists("poll_options", "id")->where(
                    fn ($query) => $query->where("poll_id", $poll?->id),
                ),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var Poll|null $poll */
            $poll = $this->route("poll");

            if ($poll !== null && ! $poll->isOpen()) {
                $validator->errors()->add("poll", "This poll is closed.");
            }
        });
    }
}
