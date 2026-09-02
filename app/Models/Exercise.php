<?php

namespace App\Models;

use App\Enums\Shared\MuscleGroup;
use App\Models\Concerns\HasPublicUuid;
use Carbon\CarbonImmutable;
use Database\Factories\ExerciseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A row in the global exercise catalogue, shared by every user. The AI names
 * exercises freely; `ExerciseCatalogService` normalises each name to `slug` and
 * reuses the row if it exists, so tracking stays consistent without a fixed list.
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string $slug
 * @property MuscleGroup|null $primary_muscle_group
 * @property bool $created_by_ai
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 *
 * @method static \Database\Factories\ExerciseFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Exercise newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Exercise newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Exercise query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Exercise whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Exercise whereCreatedByAi($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Exercise whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Exercise whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Exercise wherePrimaryMuscleGroup($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Exercise whereSlug($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Exercise whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Exercise whereUuid($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['name', 'slug', 'primary_muscle_group', 'created_by_ai'])]
class Exercise extends Model
{
    /** @use HasFactory<ExerciseFactory> */
    use HasFactory, HasPublicUuid;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'primary_muscle_group' => MuscleGroup::class,
            'created_by_ai' => 'boolean',
        ];
    }
}
