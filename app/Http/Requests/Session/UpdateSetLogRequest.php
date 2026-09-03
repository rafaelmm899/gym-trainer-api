<?php

namespace App\Http\Requests\Session;

use App\Models\SetLog;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSetLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var User|null $user */
        $user = $this->user();

        return $user !== null
            && $user->can('update', [SetLog::class, $this->route('session')]);
    }

    /**
     * `set_number` and the exercise are immutable through this endpoint — a
     * value sent for them is not in `rules()`, so it never reaches `validated()`.
     *
     * @return array<string, array<int, ValidationRule|Closure|string>>
     */
    public function rules(): array
    {
        return [
            'weight_kg' => ['required', 'numeric', 'min:0', 'max:1000', 'decimal:0,2'],
            'reps' => ['required', 'integer', 'min:1', 'max:100'],
            'rpe' => ['nullable', 'numeric', 'min:0', 'max:10', $this->halfStepRule()],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('note')) && trim($this->input('note')) === '') {
            $this->merge(['note' => null]);
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
