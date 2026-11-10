<?php

namespace App\Http\Requests;

use App\Support\GitHubUrl;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreRepositoryRequest extends FormRequest
{
    /**
     * Everyone may submit a link, guests included: an invalid link gets its error right away,
     * and RepositoryController::store() sends a guest to log in before anything is created.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Bring the pasted link to its canonical form before validating it, so
     * "github.com/Laravel/Framework.git" and "https://github.com/laravel/framework"
     * count as the same repository.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('url'))) {
            $this->merge([
                'url' => GitHubUrl::normalize($this->input('url')),
            ]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * There is no "unique" rule: a repository that already exists is not an error,
     * the controller opens its page instead.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'url' => ['required', 'string', 'max:255', 'regex:'.GitHubUrl::PATTERN],
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
            'url.regex' => 'Introdu un link de GitHub de forma https://github.com/proprietar/nume.',
        ];
    }
}
