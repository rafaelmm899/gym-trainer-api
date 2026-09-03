<?php

namespace App\Models;

use App\Enums\Cycle\CycleStatus;
use App\Models\Concerns\HasPublicUuid;
use Carbon\CarbonImmutable;
use Database\Factories\CycleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One week of a routine: its place in the sequence, its lifecycle state, and the
 * AI's rationale for the split. The synchronous first cycle is born `draft`.
 *
 * @property int $id
 * @property string $uuid
 * @property int $routine_id
 * @property int $sequence_number
 * @property CycleStatus $status
 * @property string|null $split_rationale
 * @property string|null $conversation_id
 * @property CarbonImmutable|null $generated_at
 * @property CarbonImmutable|null $activated_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Routine $routine
 * @property-read Collection<int, CycleDay> $cycleDays
 * @property-read int|null $cycle_days_count
 *
 * @method static \Database\Factories\CycleFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cycle newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cycle newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cycle query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cycle whereActivatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cycle whereCompletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cycle whereConversationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cycle whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cycle whereGeneratedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cycle whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cycle whereRoutineId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cycle whereSequenceNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cycle whereSplitRationale($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cycle whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cycle whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Cycle whereUuid($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['sequence_number', 'status', 'split_rationale', 'conversation_id', 'generated_at', 'activated_at', 'completed_at'])]
class Cycle extends Model
{
    /** @use HasFactory<CycleFactory> */
    use HasFactory, HasPublicUuid;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CycleStatus::class,
            'generated_at' => 'immutable_datetime',
            'activated_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Routine, $this>
     */
    public function routine(): BelongsTo
    {
        return $this->belongsTo(Routine::class);
    }

    /**
     * @return HasMany<CycleDay, $this>
     */
    public function cycleDays(): HasMany
    {
        return $this->hasMany(CycleDay::class)->orderBy('order');
    }
}
