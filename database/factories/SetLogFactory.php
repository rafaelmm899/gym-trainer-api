<?php

namespace Database\Factories;

use App\Models\Exercise;
use App\Models\SetLog;
use App\Models\TrainingSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SetLog>
 */
class SetLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'session_id' => TrainingSession::factory(),
            'exercise_id' => Exercise::factory(),
            'set_number' => 1,
            'weight_kg' => fake()->randomFloat(2, 20, 150),
            'reps' => fake()->numberBetween(3, 12),
            'rpe' => fake()->optional()->randomElement([6.0, 6.5, 7.0, 7.5, 8.0, 8.5, 9.0]),
            'note' => fake()->optional()->sentence(),
        ];
    }
}
