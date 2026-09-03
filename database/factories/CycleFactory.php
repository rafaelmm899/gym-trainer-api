<?php

namespace Database\Factories;

use App\Enums\Cycle\CycleStatus;
use App\Models\Cycle;
use App\Models\Routine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cycle>
 */
class CycleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'routine_id' => Routine::factory(),
            'sequence_number' => 1,
            'status' => CycleStatus::Draft,
            'split_rationale' => fake()->paragraph(),
            'generated_at' => now(),
        ];
    }

    public function generating(): static
    {
        return $this->state(fn (): array => [
            'status' => CycleStatus::Generating,
            'split_rationale' => null,
            'generated_at' => null,
        ]);
    }

    public function active(): static
    {
        return $this->state(fn (): array => [
            'status' => CycleStatus::Active,
            'activated_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (): array => [
            'status' => CycleStatus::Completed,
            'activated_at' => now()->subWeek(),
            'completed_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => CycleStatus::Failed,
            'split_rationale' => null,
            'generated_at' => null,
        ]);
    }
}
