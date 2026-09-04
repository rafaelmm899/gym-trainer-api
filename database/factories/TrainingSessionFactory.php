<?php

namespace Database\Factories;

use App\Enums\Session\AnalysisState;
use App\Enums\Session\SessionStatus;
use App\Models\CycleDay;
use App\Models\Routine;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TrainingSession>
 */
class TrainingSessionFactory extends Factory
{
    /**
     * A free (off-plan) session by default: the default `CycleDay::factory()`
     * would build a day on an unrelated cycle/routine chain. Planned-session
     * tests pass a real day via {@see self::planned()} or `cycle_day_id`.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'routine_id' => Routine::factory(),
            'cycle_day_id' => null,
            'status' => SessionStatus::InProgress,
            'analysis_state' => AnalysisState::Pending,
            'started_at' => now(),
            'completed_at' => null,
            'note' => null,
            'perceived_effort' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (): array => [
            'status' => SessionStatus::Completed,
            'completed_at' => now(),
        ]);
    }

    public function planned(CycleDay $day): static
    {
        return $this->state(fn (): array => [
            'cycle_day_id' => $day->id,
        ]);
    }
}
