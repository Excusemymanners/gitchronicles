<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A question for the assistant (Faza 8): the question itself and, optionally, the visitor's earlier
 * questions of the conversation (so a follow-up question can be understood). The conversation lives only
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
            // Only the visitor's own earlier questions: an "assistant" message sent by the browser could
            // put words in Claude's mouth (security audit AI-LAYER-08).
            'history.*.role' => ['required', Rule::in(['user'])],
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
            'question.required' => __('assistant.validation.question_required'),
            'question.string' => __('assistant.validation.question_required'),
            'question.min' => __('assistant.validation.question_min'),
            'question.max' => __('assistant.validation.question_max'),
            'history.array' => __('assistant.validation.history_shape'),
            'history.max' => __('assistant.validation.history_max'),
            'history.*' => __('assistant.validation.history_shape'),
            'history.*.role' => __('assistant.validation.history_shape'),
            'history.*.content.max' => __('assistant.validation.history_content_max'),
            'history.*.content' => __('assistant.validation.history_shape'),
        ];
    }
}
