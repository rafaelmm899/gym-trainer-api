<?php

namespace App\Http\Requests\Session;

use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreTrainingSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var User|null $user */
        $user = $this->user();

        return $user !== null
            && $user->can('create', [TrainingSession::class, $this->route('routine')]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'day' => ['nullable', 'uuid', 'exists:cycle_days,uuid'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->day) && trim($this->day) === '') {
            $this->merge(['day' => null]);
        }
    }
}
