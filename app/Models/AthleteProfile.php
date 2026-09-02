<?php

namespace App\Models;

use App\Enums\Profile\ExperienceLevel;
use App\Enums\Shared\Goal;
use Carbon\CarbonImmutable;
use Database\Factories\AthleteProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property ExperienceLevel $experience_level
 * @property int $days_per_week
 * @property int $session_minutes
 * @property Goal $goal
 * @property string|null $notes
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $user
 *
 * @method static \Database\Factories\AthleteProfileFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AthleteProfile newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AthleteProfile newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AthleteProfile query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AthleteProfile whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AthleteProfile whereDaysPerWeek($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AthleteProfile whereExperienceLevel($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AthleteProfile whereGoal($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AthleteProfile whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AthleteProfile whereNotes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AthleteProfile whereSessionMinutes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AthleteProfile whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AthleteProfile whereUserId($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['user_id', 'experience_level', 'days_per_week', 'session_minutes', 'goal', 'notes'])]
class AthleteProfile extends Model
{
    /** @use HasFactory<AthleteProfileFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'experience_level' => ExperienceLevel::class,
            'days_per_week' => 'integer',
            'session_minutes' => 'integer',
            'goal' => Goal::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
