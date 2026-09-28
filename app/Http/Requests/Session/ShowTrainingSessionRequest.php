<?php

namespace App\Http\Requests\Session;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class ShowTrainingSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var User|null $user */
        $user = $this->user();

        return $user !== null
            && $user->can('view', $this->route('session'));
    }

    /**
     * @return array<string, never>
     */
    public function rules(): array
    {
        return [];
    }
}
