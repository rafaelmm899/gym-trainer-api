<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Fills a random (v4) public `uuid` on create and makes it the route key.
 *
 * The bigint `id` stays the internal primary key and the target of every foreign
 * key; `uuid` is the only identifier that crosses the API boundary
 * (`docs/plans/data-model.md` §Identificadores) — it is the `id` field of every
 * JSON Resource and resolves `/api/v1/{resource}/{model}` route-model binding.
 * Deliberately not Laravel's `HasUuids`: that would turn the primary key itself
 * into a non-incrementing string.
 */
trait HasPublicUuid
{
    protected static function bootHasPublicUuid(): void
    {
        static::creating(function (Model $model): void {
            if (blank($model->getAttribute('uuid'))) {
                $model->setAttribute('uuid', (string) Str::uuid());
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
