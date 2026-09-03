<?php

namespace Database\Factories;

use App\Enums\Shared\MuscleGroup;
use App\Models\Exercise;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Exercise>
 */
class ExerciseFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = Str::title(fake()->unique()->words(2, true));

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'primary_muscle_group' => fake()->randomElement(MuscleGroup::cases()),
            'created_by_ai' => true,
        ];
    }
}
