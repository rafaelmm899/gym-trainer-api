<?php

namespace App\Http\Requests\Profile;

use App\Enums\Profile\ExperienceLevel;
use App\Enums\Shared\Goal;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAthleteProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        // `auth:sanctum` already guarantees an authenticated caller, and the
        // endpoint only ever touches that caller's own profile (no id in the
        // URL). There is no cross-user path to gate, so no Policy — see the
        // spec §5.2, the same documented exception as `register`.
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'experience_level' => ['required', Rule::enum(ExperienceLevel::class)],
            'days_per_week' => ['required', 'integer', 'between:1,7'],
            'session_minutes' => ['required', 'integer', 'between:10,240'],
            'goal' => ['required', Rule::enum(Goal::class)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->notes) && trim($this->notes) === '') {
            $this->merge(['notes' => null]);
        }
    }
}
