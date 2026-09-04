<?php

namespace App\Http\Requests\Session;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CompleteTrainingSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var User|null $user */
        $user = $this->user();

        return $user !== null
            && $user->can('complete', $this->route('session'));
    }

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:1000'],
            'perceived_effort' => ['nullable', 'integer', 'min:1', 'max:5'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('note')) && trim($this->input('note')) === '') {
            $this->merge(['note' => null]);
        }
    }
}
