<?php

use App\Exceptions\Exercise\ExerciseMuscleGroupRequiredException;
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

// TC-7
it('refuses to create an exercise without a valid muscle group', function (?string $hint) {
    expect(fn () => app(ExerciseCatalogService::class)->resolve('Sled Push', $hint))
        ->toThrow(ExerciseMuscleGroupRequiredException::class);

    $this->assertDatabaseCount('exercises', 0);
})->with(['no hint' => null, 'unknown hint' => 'conditioning', 'empty hint' => '']);

// TC-8
it('reuses an existing exercise without a hint', function () {
    $existing = Exercise::factory()->create(['name' => 'Sled Push', 'slug' => 'sled-push']);

    $resolved = app(ExerciseCatalogService::class)->resolve('Sled Push', null);

    expect($resolved->id)->toBe($existing->id);
    $this->assertDatabaseCount('exercises', 1);
});

// TC-5
it('gives the same row for a non-breaking hyphen and a plain hyphen', function (string $name) {
    $existing = Exercise::factory()->create(['name' => 'Pull-Ups', 'slug' => 'pull-ups']);

    $resolved = app(ExerciseCatalogService::class)->resolve($name, 'back');

    expect($resolved->id)->toBe($existing->id);
    $this->assertDatabaseCount('exercises', 1);
})->with([
    'non-breaking hyphen' => "Pull\u{2011}Ups",
    'plain hyphen' => 'Pull-Ups',
]);

it('creates a new exercise with the hyphen kept in its slug', function () {
    $created = app(ExerciseCatalogService::class)->resolve("One\u{2011}Arm Dumbbell Row", 'back');

    expect($created->slug)->toBe('one-arm-dumbbell-row');
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
