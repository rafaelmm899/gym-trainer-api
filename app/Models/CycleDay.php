<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Carbon\CarbonImmutable;
use Database\Factories\CycleDayFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One day within a cycle, ordered 1 to 5. Not tied to a calendar day — the user
 * trains at their own pace.
 *
 * @property int $id
 * @property string $uuid
 * @property int $cycle_id
 * @property int $order
 * @property string $label
 * @property list<string> $focus_muscle_groups
 * @property string $rationale
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Cycle $cycle
 * @property-read Collection<int, DayExercise> $dayExercises
 * @property-read int|null $day_exercises_count
 *
 * @method static \Database\Factories\CycleDayFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CycleDay newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CycleDay newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CycleDay query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CycleDay whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CycleDay whereCycleId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CycleDay whereFocusMuscleGroups($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CycleDay whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CycleDay whereLabel($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CycleDay whereOrder($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CycleDay whereRationale($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CycleDay whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CycleDay whereUuid($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['order', 'label', 'focus_muscle_groups', 'rationale'])]
class CycleDay extends Model
{
    /** @use HasFactory<CycleDayFactory> */
    use HasFactory, HasPublicUuid;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'focus_muscle_groups' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Cycle, $this>
     */
    public function cycle(): BelongsTo
    {
        return $this->belongsTo(Cycle::class);
    }

    /**
     * @return HasMany<DayExercise, $this>
     */
    public function dayExercises(): HasMany
    {
        return $this->hasMany(DayExercise::class)->orderBy('order');
    }
}
