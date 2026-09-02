<?php

namespace App\Http\Requests\Routine;

use App\Enums\Shared\Goal;
use App\Models\Routine;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRoutineRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var User|null $user */
        $user = $this->user();

        return $user !== null && $user->can('create', Routine::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'goal' => ['required', Rule::enum(Goal::class)],
            'hint' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->hint) && trim($this->hint) === '') {
            $this->merge(['hint' => null]);
        }
    }
}
