<?php

namespace Database\Factories;

use App\Enums\Routine\RoutineStatus;
use App\Enums\Shared\Goal;
use App\Models\Routine;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Routine>
 */
class RoutineFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(2, true),
            'goal' => fake()->randomElement(Goal::cases()),
            'hint' => fake()->optional()->sentence(),
            'status' => RoutineStatus::Active,
        ];
    }

    /**
     * A routine that was archived when the user activated another one. Two
     * `active` routines for the same user violate the partial unique index, so
     * "routine history" scenarios use this state (or a second user).
     */
    public function archived(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => RoutineStatus::Archived,
            'archived_at' => now(),
        ]);
    }
}
