<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Carbon\CarbonImmutable;
use Database\Factories\DayExerciseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The prescription of one exercise within a cycle day: how much volume and
 * intensity to target, plus the AI's per-exercise rationale.
 *
 * @property int $id
 * @property string $uuid
 * @property int $cycle_day_id
 * @property int $exercise_id
 * @property int $order
 * @property int $sets
 * @property int $rep_min
 * @property int $rep_max
 * @property string|null $target_weight_kg
 * @property string|null $target_rpe
 * @property int $rest_seconds
 * @property string $rationale
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read CycleDay $cycleDay
 * @property-read Exercise $exercise
 *
 * @method static \Database\Factories\DayExerciseFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DayExercise newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DayExercise newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DayExercise query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DayExercise whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DayExercise whereCycleDayId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DayExercise whereExerciseId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DayExercise whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DayExercise whereOrder($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DayExercise whereRationale($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DayExercise whereRepMax($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DayExercise whereRepMin($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DayExercise whereRestSeconds($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DayExercise whereSets($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DayExercise whereTargetRpe($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DayExercise whereTargetWeightKg($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DayExercise whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DayExercise whereUuid($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['exercise_id', 'order', 'sets', 'rep_min', 'rep_max', 'target_weight_kg', 'target_rpe', 'rest_seconds', 'rationale'])]
class DayExercise extends Model
{
    /** @use HasFactory<DayExerciseFactory> */
    use HasFactory, HasPublicUuid;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sets' => 'integer',
            'rep_min' => 'integer',
            'rep_max' => 'integer',
            'target_weight_kg' => 'decimal:2',
            'target_rpe' => 'decimal:1',
            'rest_seconds' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<CycleDay, $this>
     */
    public function cycleDay(): BelongsTo
    {
        return $this->belongsTo(CycleDay::class);
    }

    /**
     * @return BelongsTo<Exercise, $this>
     */
    public function exercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }
}
