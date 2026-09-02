<?php

namespace Database\Factories;

use App\Enums\Profile\ExperienceLevel;
use App\Enums\Shared\Goal;
use App\Models\AthleteProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AthleteProfile>
 */
class AthleteProfileFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'experience_level' => fake()->randomElement(ExperienceLevel::cases()),
            'days_per_week' => fake()->numberBetween(2, 6),
            'session_minutes' => fake()->randomElement([30, 45, 60, 75, 90]),
            'goal' => fake()->randomElement(Goal::cases()),
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
