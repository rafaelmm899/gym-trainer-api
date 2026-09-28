<?php

namespace App\Http\Requests\Session;

use App\Enums\Session\SessionStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListTrainingSessionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,50'],
            'cycle_day' => ['sometimes', 'uuid'],
            'status' => ['sometimes', Rule::enum(SessionStatus::class)],
        ];
    }
}
