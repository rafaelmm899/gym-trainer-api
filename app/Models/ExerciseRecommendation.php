<?php

namespace App\Models;

use App\Enums\Recommendation\RecommendationAction;
use App\Models\Concerns\HasPublicUuid;
use Carbon\CarbonImmutable;
use Database\Factories\ExerciseRecommendationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The next-time target the AI analyst leaves for one exercise within one
 * routine, after analyzing a completed session. Scoped to
 * `(user_id, routine_id, exercise_id)` — a new analysis overwrites the
 * existing row for that triple; no history is kept.
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property int $routine_id
 * @property int $exercise_id
 * @property int|null $source_session_id
 * @property string $target_weight_kg
 * @property int $target_sets
 * @property int $target_rep_min
 * @property int $target_rep_max
 * @property RecommendationAction $action
 * @property string $explanation
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $user
 * @property-read Routine $routine
 * @property-read Exercise $exercise
 * @property-read TrainingSession|null $sourceSession
 *
 * @method static \Database\Factories\ExerciseRecommendationFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExerciseRecommendation newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExerciseRecommendation newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExerciseRecommendation query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExerciseRecommendation whereAction($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExerciseRecommendation whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExerciseRecommendation whereExerciseId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExerciseRecommendation whereExplanation($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExerciseRecommendation whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExerciseRecommendation whereRoutineId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExerciseRecommendation whereSourceSessionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExerciseRecommendation whereTargetRepMax($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExerciseRecommendation whereTargetRepMin($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExerciseRecommendation whereTargetSets($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExerciseRecommendation whereTargetWeightKg($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExerciseRecommendation whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExerciseRecommendation whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ExerciseRecommendation whereUuid($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['user_id', 'routine_id', 'exercise_id', 'source_session_id', 'target_weight_kg', 'target_sets', 'target_rep_min', 'target_rep_max', 'action', 'explanation'])]
class ExerciseRecommendation extends Model
{
    /** @use HasFactory<ExerciseRecommendationFactory> */
    use HasFactory, HasPublicUuid;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'target_weight_kg' => 'decimal:2',
            'target_sets' => 'integer',
            'target_rep_min' => 'integer',
            'target_rep_max' => 'integer',
            'action' => RecommendationAction::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Routine, $this>
     */
    public function routine(): BelongsTo
    {
        return $this->belongsTo(Routine::class);
    }

    /**
     * @return BelongsTo<Exercise, $this>
     */
    public function exercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }

    /**
     * The session whose analysis produced this recommendation. `null` is
     * possible only if that session is later deleted (traceability, not a
     * normal state — sessions have no delete endpoint in v1).
     *
     * @return BelongsTo<TrainingSession, $this>
     */
    public function sourceSession(): BelongsTo
    {
        return $this->belongsTo(TrainingSession::class, 'source_session_id');
    }
}
