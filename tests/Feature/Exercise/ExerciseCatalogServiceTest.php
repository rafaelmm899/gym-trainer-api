<?php

use App\Models\Exercise;
use App\Services\Exercise\ExerciseCatalogService;
use Illuminate\Support\Facades\Log;

// TC-30
it('normalises a name to a slug and creates the row', function () {
    $exercise = app(ExerciseCatalogService::class)->resolve('Press de Banca Inclinado', 'chest');

    expect($exercise)->toBeInstanceOf(Exercise::class)
        ->and($exercise->slug)->toBe('press-de-banca-inclinado')
        ->and($exercise->name)->toBe('Press de Banca Inclinado')
        ->and($exercise->primary_muscle_group->value)->toBe('chest')
        ->and($exercise->created_by_ai)->toBeTrue();

    $this->assertDatabaseCount('exercises', 1);
});

// TC-31
it('is case, accent and whitespace insensitive on the slug key', function (string $name) {
    $existing = Exercise::factory()->create(['slug' => 'barbell-bench-press', 'name' => 'Barbell Bench Press']);

    $resolved = app(ExerciseCatalogService::class)->resolve($name, 'chest');

    expect($resolved->id)->toBe($existing->id)
        ->and($resolved->name)->toBe('Barbell Bench Press');
    $this->assertDatabaseCount('exercises', 1);
})->with([
    'spaced and accented' => '  BARBELL  Bench  Préss ',
    'lowercased' => 'barbell bench press',
    'hyphenated' => 'Barbell-Bench-Press',
]);

// TC-32
it('stores null when the muscle-group hint is not a known group', function () {
    $exercise = app(ExerciseCatalogService::class)->resolve('Sled Push', 'conditioning');

    expect($exercise->primary_muscle_group)->toBeNull();
    $this->assertDatabaseCount('exercises', 1);
});

// TC-33
it('rejects a blank name', function (string $name) {
    expect(fn () => app(ExerciseCatalogService::class)->resolve($name, 'chest'))
        ->toThrow(InvalidArgumentException::class);

    $this->assertDatabaseCount('exercises', 0);
})->with(['empty' => '', 'whitespace' => '   ']);

// TC-34
it('logs a probable near-duplicate but still returns the new row', function () {
    Exercise::factory()->create(['name' => 'Bench Press', 'slug' => 'bench-press']);
    Log::spy();

    $created = app(ExerciseCatalogService::class)->resolve('Barbell Bench Press', 'chest');

    expect($created->slug)->toBe('barbell-bench-press');
    $this->assertDatabaseCount('exercises', 2);
    Log::shouldHaveReceived('info')->once()->withArgs(
        fn (string $message): bool => str_contains($message, 'barbell-bench-press') && str_contains($message, 'bench-press')
    );
});
