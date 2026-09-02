<?php

namespace Database\Factories;

use App\Models\CycleDay;
use App\Models\DayExercise;
use App\Models\Exercise;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DayExercise>
 */
class DayExerciseFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cycle_day_id' => CycleDay::factory(),
            'exercise_id' => Exercise::factory(),
            'order' => 1,
            'sets' => fake()->numberBetween(2, 5),
            'rep_min' => 6,
            'rep_max' => 12,
            'target_weight_kg' => fake()->randomFloat(2, 20, 120),
            'target_rpe' => fake()->randomElement([7.0, 7.5, 8.0, 8.5]),
            'rest_seconds' => fake()->randomElement([60, 90, 120, 180]),
            'rationale' => fake()->sentence(),
        ];
    }
}
