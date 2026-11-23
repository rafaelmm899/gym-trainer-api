<?php

namespace App\Http\Resources\Exercise;

use Illuminate\Http\Request;

/**
 * One exercise with its step-by-step instructions, in the language of the
 * request's `Accept-Language` header: the best match among
 * `config('catalog.locales')`, falling back to the first of them (`en`), and to
 * English again when the exercise has no steps in the chosen language.
 */
class ExerciseDetailResource extends ExerciseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $locales = (array) config('catalog.locales');
        $locale = $request->getPreferredLanguage($locales) ?? $locales[0];
        $instructions = $this->instructions ?? [];

        return [
            ...parent::toArray($request),
            'instructions' => $instructions[$locale] ?? $instructions[$locales[0]] ?? [],
        ];
    }
}
