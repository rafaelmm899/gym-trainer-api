<?php

namespace App\Http\Requests\Profile;

use App\Enums\Profile\ExperienceLevel;
use App\Enums\Shared\Goal;
use App\Models\AthleteProfile;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAthleteProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user instanceof User) {
            return false;
        }

        $profile = $user->athleteProfile()->first();

        return $profile === null
            ? $user->can('create', AthleteProfile::class)
            : $user->can('update', $profile);
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
