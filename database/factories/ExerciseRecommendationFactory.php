<?php

namespace Database\Factories;

use App\Enums\Recommendation\RecommendationAction;
use App\Models\Exercise;
use App\Models\ExerciseRecommendation;
use App\Models\Routine;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExerciseRecommendation>
 */
class ExerciseRecommendationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'routine_id' => Routine::factory(),
            'exercise_id' => Exercise::factory(),
            'source_session_id' => null,
            'target_weight_kg' => fake()->randomFloat(2, 20, 120),
            'target_sets' => fake()->numberBetween(2, 5),
            'target_rep_min' => 8,
            'target_rep_max' => 12,
            'action' => RecommendationAction::Hold,
            'explanation' => fake()->sentence(),
        ];
    }
}
