<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Carbon\CarbonImmutable;
use Database\Factories\SetLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One executed set inside a training session: weight lifted, reps completed,
 * optional RPE and note. `exercise_id` references the global catalogue directly
 * so free (off-plan) sessions record too; `set_number` is the 1-based index of
 * the set within its exercise in that session.
 *
 * @property int $id
 * @property string $uuid
 * @property int $session_id
 * @property int $exercise_id
 * @property int $set_number
 * @property string $weight_kg
 * @property int $reps
 * @property string|null $rpe
 * @property string|null $note
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read TrainingSession $session
 * @property-read Exercise $exercise
 *
 * @method static \Database\Factories\SetLogFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SetLog newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SetLog newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SetLog query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SetLog whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SetLog whereExerciseId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SetLog whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SetLog whereNote($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SetLog whereReps($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SetLog whereRpe($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SetLog whereSessionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SetLog whereSetNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SetLog whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SetLog whereUuid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SetLog whereWeightKg($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['session_id', 'exercise_id', 'set_number', 'weight_kg', 'reps', 'rpe', 'note'])]
class SetLog extends Model
{
    /** @use HasFactory<SetLogFactory> */
    use HasFactory, HasPublicUuid;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'set_number' => 'integer',
            'weight_kg' => 'decimal:2',
            'reps' => 'integer',
            'rpe' => 'decimal:1',
        ];
    }

    /**
     * @return BelongsTo<TrainingSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(TrainingSession::class);
    }

    /**
     * @return BelongsTo<Exercise, $this>
     */
    public function exercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }
}
