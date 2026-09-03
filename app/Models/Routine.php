<?php

namespace App\Models;

use App\Enums\Routine\RoutineStatus;
use App\Enums\Shared\Goal;
use App\Models\Concerns\HasPublicUuid;
use Carbon\CarbonImmutable;
use Database\Factories\RoutineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property string $name
 * @property Goal $goal
 * @property string|null $hint
 * @property int $days_per_cycle
 * @property RoutineStatus $status
 * @property CarbonImmutable|null $archived_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $user
 * @property-read Collection<int, Cycle> $cycles
 * @property-read int|null $cycles_count
 * @property-read Cycle|null $cycle
 * @property-read Collection<int, TrainingSession> $trainingSessions
 * @property-read int|null $training_sessions_count
 *
 * @method static \Database\Factories\RoutineFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Routine newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Routine newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Routine query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Routine whereArchivedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Routine whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Routine whereDaysPerCycle($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Routine whereGoal($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Routine whereHint($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Routine whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Routine whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Routine whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Routine whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Routine whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Routine whereUuid($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['name', 'goal', 'hint', 'status'])]
class Routine extends Model
{
    /** @use HasFactory<RoutineFactory> */
    use HasFactory, HasPublicUuid;

    /**
     * Mirrors the `days_per_cycle` database default onto a freshly-created
     * instance, so the `201` body carries `5` without a reload (a
     * recently-created model returns `null`, not the DB default, for an unset
     * column under `preventAccessingMissingAttributes`). Fixed at 5 in v1;
     * never accepted from the request.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'days_per_cycle' => 5,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'goal' => Goal::class,
            'status' => RoutineStatus::class,
            'days_per_cycle' => 'integer',
            'archived_at' => 'datetime',
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
     * @return HasMany<Cycle, $this>
     */
    public function cycles(): HasMany
    {
        return $this->hasMany(Cycle::class);
    }

    /**
     * The routine's current cycle — the one with the highest `sequence_number`.
     * In v1 a routine has exactly one cycle (the first), created synchronously
     * with the routine.
     *
     * @return HasOne<Cycle, $this>
     */
    public function cycle(): HasOne
    {
        return $this->hasOne(Cycle::class)->ofMany('sequence_number', 'max');
    }

    /**
     * @return HasMany<TrainingSession, $this>
     */
    public function trainingSessions(): HasMany
    {
        return $this->hasMany(TrainingSession::class);
    }
}
