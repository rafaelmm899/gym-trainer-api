<?php

namespace App\Http\Requests\Session;

use App\Models\SetLog;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class LogSetRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var User|null $user */
        $user = $this->user();

        return $user !== null
            && $user->can('create', [SetLog::class, $this->route('session')]);
    }

    /**
     * @return array<string, array<int, ValidationRule|Closure|string>>
     */
    public function rules(): array
    {
        return [
            'day_exercise_id' => ['nullable', 'uuid', 'exists:day_exercises,uuid', 'required_without:exercise_id', 'prohibits:exercise_id'],
            'exercise_id' => ['nullable', 'uuid', 'exists:exercises,uuid', 'required_without:day_exercise_id', 'prohibits:day_exercise_id'],
            'set_number' => ['required', 'integer', 'min:1'],
            'weight_kg' => ['required', 'numeric', 'min:0', 'max:1000', 'decimal:0,2'],
            'reps' => ['required', 'integer', 'min:1', 'max:100'],
            'rpe' => ['nullable', 'numeric', 'min:0', 'max:10', $this->halfStepRule()],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['day_exercise_id', 'exercise_id', 'note'] as $key) {
            $value = $this->input($key);

            if (is_string($value) && trim($value) === '') {
                $this->merge([$key => null]);
            }
        }
    }

    private function halfStepRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (fmod((float) $value * 2, 1.0) !== 0.0) {
                $fail('The :attribute must be in increments of 0.5.');
            }
        };
    }
}
