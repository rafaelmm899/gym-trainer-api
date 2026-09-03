<?php

namespace App\Models;

use App\Enums\Session\AnalysisState;
use App\Enums\Session\SessionStatus;
use App\Models\Concerns\HasPublicUuid;
use Carbon\CarbonImmutable;
use Database\Factories\TrainingSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One training day the user actually executed — a day of the active cycle
 * (`cycle_day_id` set) or a free, off-plan session (`cycle_day_id` null). Born
 * `in_progress` / `analysis_state = pending`; sets are logged into it and it is
 * closed by a later story.
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property int $routine_id
 * @property int|null $cycle_day_id
 * @property SessionStatus $status
 * @property AnalysisState $analysis_state
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $completed_at
 * @property string|null $conversation_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $user
 * @property-read Routine $routine
 * @property-read CycleDay|null $cycleDay
 * @property-read Collection<int, SetLog> $sets
 * @property-read int|null $sets_count
 *
 * @method static \Database\Factories\TrainingSessionFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TrainingSession newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TrainingSession newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TrainingSession query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TrainingSession whereAnalysisState($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TrainingSession whereCompletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TrainingSession whereConversationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TrainingSession whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TrainingSession whereCycleDayId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TrainingSession whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TrainingSession whereRoutineId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TrainingSession whereStartedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TrainingSession whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TrainingSession whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TrainingSession whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TrainingSession whereUuid($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['user_id', 'cycle_day_id', 'status', 'analysis_state', 'started_at', 'completed_at'])]
class TrainingSession extends Model
{
    /** @use HasFactory<TrainingSessionFactory> */
    use HasFactory, HasPublicUuid;

    /**
     * Mirrors the `analysis_state` database default onto a freshly-created
     * instance so the `201` body serialises `pending` without a reload — a
     * recently-created model returns `null`, not the DB default, for an unset
     * column under `preventAccessingMissingAttributes`. The endpoint never sets
     * `analysis_state`; the "close session" story moves it on.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'analysis_state' => AnalysisState::Pending->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SessionStatus::class,
            'analysis_state' => AnalysisState::class,
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
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
     * The planned day this session executed. `null` for a free session.
     *
     * @return BelongsTo<CycleDay, $this>
     */
    public function cycleDay(): BelongsTo
    {
        return $this->belongsTo(CycleDay::class);
    }

    /**
     * The sets logged into this session. Named `sets` (not `setLogs`) so the
     * `sessions/{session}/sets/{set}` route resolves `{set}` through it via
     * scoped binding.
     *
     * @return HasMany<SetLog, $this>
     */
    public function sets(): HasMany
    {
        return $this->hasMany(SetLog::class, 'session_id');
    }
}
