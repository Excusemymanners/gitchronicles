<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A question for the assistant (Faza 8): the question itself and, optionally, the last messages
 * of the conversation (so a follow-up question can be understood). The conversation lives only
 * in the visitor's browser, so the browser sends it with every question.
 */
class AskAssistantRequest extends FormRequest
{
    /**
     * Everyone may ask, guests included: repositories are public (the rate limit protects the endpoint).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'question' => ['required', 'string', 'min:3', 'max:1000'],
            'history' => ['sometimes', 'array', 'max:6'],
            'history.*' => ['array:role,content'],
            'history.*.role' => ['required', Rule::in(['user', 'assistant'])],
            'history.*.content' => ['required', 'string', 'max:4000'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'question.required' => 'Scrie o întrebare.',
            'question.string' => 'Scrie o întrebare.',
            'question.min' => 'Întrebarea trebuie să aibă cel puțin 3 caractere.',
            'question.max' => 'Întrebarea poate avea cel mult 1.000 de caractere.',
            'history.array' => 'Conversația trimisă nu are forma corectă.',
            'history.max' => 'Conversația trimisă poate avea cel mult 6 mesaje.',
            'history.*' => 'Conversația trimisă nu are forma corectă.',
            'history.*.role' => 'Conversația trimisă nu are forma corectă.',
            'history.*.content.max' => 'Un mesaj al conversației poate avea cel mult 4.000 de caractere.',
            'history.*.content' => 'Conversația trimisă nu are forma corectă.',
        ];
    }
}
