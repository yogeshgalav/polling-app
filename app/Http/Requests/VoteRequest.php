<?php

namespace App\Http\Requests;

use App\Models\Guest;
use App\Models\Poll;
use App\Models\Vote;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class VoteRequest extends FormRequest
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
            "poll_option_id" => "required|integer|exists:poll_options,id",
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

            // i could also have checked for the guest_id duplicacy but that will run extra queries here.
        });
    }
}
