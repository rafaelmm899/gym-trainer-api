<?php

namespace App\Models;

use App\Enums\Shared\MuscleGroup;
use App\Models\Concerns\HasPublicUuid;
use Carbon\CarbonImmutable;
use Database\Factories\ExerciseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A row in the global exercise catalogue, shared by every user. Most rows come
 * from the exercises dataset (`exercises:import`, keyed by `external_id`); the AI
 * may still name an exercise freely, in which case `ExerciseCatalogService`
 * normalises the name to `slug` and reuses the row if it exists.
 *
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string $slug
 * @property MuscleGroup|null $primary_muscle_group
 * @property bool $created_by_ai
 * @property string|null $external_id
 * @property string|null $equipment
 * @property string|null $target_muscle
 * @property list<string>|null $secondary_muscles
 * @property array<string, list<string>>|null $instructions
 * @property string|null $media_url
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 *
 * @method static \Database\Factories\ExerciseFactory factory($count = null, $state = [])
 * @method static string slugFor(string $name)
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
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Exercise whereEquipment($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Exercise whereExternalId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Exercise whereInstructions($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Exercise whereMediaUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Exercise whereSecondaryMuscles($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Exercise whereTargetMuscle($value)
 *
 * @mixin \Eloquent
 */
#[Fillable([
    'name', 'slug', 'primary_muscle_group', 'created_by_ai', 'external_id',
    'equipment', 'target_muscle', 'secondary_muscles', 'instructions', 'media_url',
])]
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
            'secondary_muscles' => 'array',
            'instructions' => 'array',
        ];
    }

    /**
     * The one slug rule of the catalogue. Unicode hyphens (U+2010 to U+2015,
     * including the non-breaking hyphen U+2011) become `-` first: `Str::ascii()`
     * would otherwise delete them and `Pull‑Ups` would slug to `pullups`.
     */
    public static function slugFor(string $name): string
    {
        return Str::slug(Str::ascii(preg_replace('/[\x{2010}-\x{2015}]/u', '-', $name) ?? $name));
    }
}
