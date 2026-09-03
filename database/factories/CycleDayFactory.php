<?php

namespace Database\Factories;

use App\Enums\Shared\MuscleGroup;
use App\Models\Cycle;
use App\Models\CycleDay;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CycleDay>
 */
class CycleDayFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cycle_id' => Cycle::factory(),
            'order' => 1,
            'label' => fake()->randomElement(['Chest', 'Back', 'Legs', 'Shoulders', 'Arms']),
            'focus_muscle_groups' => fake()->randomElements(MuscleGroup::values(), 2),
            'rationale' => fake()->sentence(),
        ];
    }
}
