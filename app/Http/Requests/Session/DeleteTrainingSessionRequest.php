<?php

namespace App\Http\Requests\Session;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class DeleteTrainingSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var User|null $user */
        $user = $this->user();

        return $user !== null
            && $user->can('delete', $this->route('session'));
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [];
    }
}
