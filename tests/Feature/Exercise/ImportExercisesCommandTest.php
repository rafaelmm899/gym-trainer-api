<?php

use App\Enums\Shared\MuscleGroup;
use App\Models\Exercise;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\PendingCommand;

function importFixture(): PendingCommand
{
    return test()->artisan('exercises:import', ['--path' => datasetFixturePath()]);
}

// Catalogue spec TC-13
it('creates an exercise from every valid dataset record', function () {
    importFixture()->assertExitCode(1);

    $bench = Exercise::query()->where('external_id', '0025')->sole();

    expect($bench->name)->toBe('Barbell Bench Press')
        ->and($bench->slug)->toBe('barbell-bench-press')
        ->and($bench->equipment)->toBe('barbell')
        ->and($bench->target_muscle)->toBe('pectorals')
        ->and($bench->secondary_muscles)->toBe(['triceps', 'shoulders'])
        ->and($bench->primary_muscle_group)->toBe(MuscleGroup::Chest)
        ->and(array_keys($bench->instructions))->toBe(['en', 'es'])
        ->and($bench->created_by_ai)->toBeFalse()
        ->and($bench->media_url)->toBeNull()
        ->and(Exercise::query()->whereNotNull('external_id')->count())->toBe(10);
});

// Catalogue spec TC-14
it('is idempotent', function () {
    importFixture();
    $before = Exercise::query()->orderBy('id')->pluck('uuid', 'id')->all();

    test()->artisan('exercises:import', ['--path' => datasetFixturePath()])
        ->expectsOutputToContain('0 created, 0 updated, 16 unchanged')
        ->assertExitCode(1);

    expect(Exercise::query()->orderBy('id')->pluck('uuid', 'id')->all())->toBe($before);
});

// Catalogue spec TC-15
it('rejects a record with an unknown target and keeps the rest', function () {
    importFixture()
        ->expectsOutputToContain("Rejected 2000: unknown target 'xyz'")
        ->expectsOutputToContain('Rejected 3000')
        ->assertExitCode(1);

    expect(Exercise::query()->where('external_id', '2000')->exists())->toBeFalse()
        ->and(Exercise::query()->where('external_id', '3000')->exists())->toBeFalse()
        ->and(Exercise::query()->where('external_id', '0001')->exists())->toBeTrue();
});

it('exits cleanly when every record is valid', function () {
    $valid = collect(json_decode(file_get_contents(datasetFixturePath()), true))
        ->reject(fn (array $record): bool => in_array($record['id'], ['2000', '3000'], true))
        ->values();
    $path = tempnam(sys_get_temp_dir(), 'ds');
    file_put_contents($path, $valid->toJson());

    test()->artisan('exercises:import', ['--path' => $path])->assertExitCode(0);

    unlink($path);
});

// Catalogue spec TC-16
it('keeps two records with the same name apart', function () {
    importFixture();

    $first = Exercise::query()->where('external_id', '0088')->sole();
    $second = Exercise::query()->where('external_id', '1371')->sole();

    expect($first->slug)->toBe('barbell-seated-calf-raise')
        ->and($second->slug)->toBe('barbell-seated-calf-raise-1371')
        ->and($first->name)->toBe($second->name);
});

// Catalogue spec TC-17
it('gives a new row a suffixed slug when another row already uses it', function () {
    $legacy = Exercise::factory()->create(['name' => 'Barbell Bench Press', 'slug' => 'barbell-bench-press', 'created_by_ai' => true]);

    importFixture();

    $imported = Exercise::query()->where('external_id', '0025')->sole();

    expect($imported->slug)->toBe('barbell-bench-press-0025')
        ->and($imported->id)->not->toBe($legacy->id)
        ->and($legacy->fresh()->external_id)->toBeNull();
});

// Catalogue spec TC-18
it('repairs and reports a damaged name', function () {
    importFixture()->expectsOutputToContain('Repaired the encoding of: Sled 45° Leg Press');

    $sled = Exercise::query()->where('external_id', '0739')->sole();

    expect($sled->name)->toBe('Sled 45° Leg Press')
        ->and($sled->slug)->toBe('sled-45-leg-press');
});

// Catalogue spec TC-19
it('refreshes an existing row by external id without renaming it', function () {
    $existing = Exercise::factory()->create([
        'external_id' => '0025',
        'name' => 'My Bench',
        'slug' => 'my-bench',
        'primary_muscle_group' => MuscleGroup::Back,
        'created_by_ai' => true,
        'equipment' => null,
    ]);

    importFixture();

    $existing->refresh();

    expect($existing->equipment)->toBe('barbell')
        ->and($existing->target_muscle)->toBe('pectorals')
        ->and($existing->instructions)->toHaveKeys(['en', 'es'])
        ->and($existing->name)->toBe('My Bench')
        ->and($existing->slug)->toBe('my-bench')
        ->and($existing->primary_muscle_group)->toBe(MuscleGroup::Back)
        ->and($existing->created_by_ai)->toBeTrue()
        ->and(Exercise::query()->where('external_id', '0025')->count())->toBe(1);
});

it('fills a missing muscle group from the target', function () {
    Exercise::factory()->create(['external_id' => '0025', 'name' => 'My Bench', 'slug' => 'my-bench', 'primary_muscle_group' => null]);

    importFixture();

    expect(Exercise::query()->where('external_id', '0025')->sole()->primary_muscle_group)->toBe(MuscleGroup::Chest);
});

// Catalogue spec TC-20
it('stores only English steps for a record without Spanish ones', function () {
    importFixture();

    expect(array_keys(Exercise::query()->where('external_id', '3001')->sole()->instructions))->toBe(['en']);
});

// Catalogue spec TC-21
it('writes nothing when the source is unreadable', function (string $contents) {
    $path = tempnam(sys_get_temp_dir(), 'ds');
    file_put_contents($path, $contents);

    test()->artisan('exercises:import', ['--path' => $path])->assertExitCode(1);

    expect(Exercise::query()->count())->toBe(0);
    unlink($path);
})->with(['an object' => '{"a": 1}', 'broken json' => '{broken']);

it('writes nothing when the path does not exist', function () {
    test()->artisan('exercises:import', ['--path' => '/nonexistent/exercises.json'])
        ->expectsOutputToContain('cannot be read')
        ->assertExitCode(1);

    expect(Exercise::query()->count())->toBe(0);
});

// Catalogue spec TC-22
it('downloads the pinned dataset when no path is given', function () {
    Http::fake([config('catalog.dataset.url') => Http::response(file_get_contents(datasetFixturePath()))]);

    test()->artisan('exercises:import')->assertExitCode(1);

    expect(Exercise::query()->where('external_id', '0025')->exists())->toBeTrue();
    Http::assertSentCount(1);
});

// Catalogue spec TC-23
it('creates the six hand-written exercises', function () {
    importFixture();

    $manual = Exercise::query()->whereNull('external_id')->orderBy('slug')->get();

    expect($manual->pluck('slug')->all())->toBe([
        'alternating-dumbbell-row', 'barbell-hip-thrust', 'bulgarian-split-squat',
        'cable-woodchopper', 'face-pull', 'plank',
    ]);

    $plank = $manual->firstWhere('slug', 'plank');

    expect($plank->name)->toBe('Plank')
        ->and($plank->created_by_ai)->toBeFalse()
        ->and($plank->primary_muscle_group)->toBe(MuscleGroup::Core)
        ->and(array_keys($plank->instructions))->toBe(['en', 'es'])
        ->and($manual->firstWhere('slug', 'face-pull')->primary_muscle_group)->toBe(MuscleGroup::Shoulders);
});

// Catalogue spec TC-24
it('enriches an existing exercise with a hand-written entry by slug', function () {
    $plank = Exercise::factory()->create([
        'name' => 'Plank',
        'slug' => 'plank',
        'primary_muscle_group' => MuscleGroup::Core,
        'created_by_ai' => true,
    ]);

    importFixture();

    $plank->refresh();

    expect(Exercise::query()->where('slug', 'plank')->count())->toBe(1)
        ->and($plank->equipment)->toBe('body weight')
        ->and($plank->target_muscle)->toBe('abs')
        ->and($plank->instructions)->toHaveKeys(['en', 'es'])
        ->and($plank->created_by_ai)->toBeTrue()
        ->and($plank->name)->toBe('Plank');
});
