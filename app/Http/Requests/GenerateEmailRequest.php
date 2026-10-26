<?php

namespace App\Http\Requests;

use App\Enums\Tone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GenerateEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'purpose' => ['required', 'string', 'min:5', 'max:500'],
            // A name has no line breaks or angle brackets; rejecting them also keeps
            // the value from breaking out of its tag in the prompt.
            'recipient_name' => ['required', 'string', 'max:100', 'not_regex:/[\r\n<>]/'],
            'tone' => ['required', Rule::enum(Tone::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tone.enum' => 'The tone must be one of: '.implode(', ', array_column(Tone::cases(), 'value')).'.',
            'recipient_name.not_regex' => 'The recipient name must not contain line breaks or angle brackets.',
        ];
    }
}
