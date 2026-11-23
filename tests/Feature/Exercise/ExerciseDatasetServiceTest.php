<?php

use App\Data\Exercise\ExerciseDatasetData;
use App\Enums\Shared\MuscleGroup;
use App\Exceptions\Exercise\ExerciseDatasetUnreadableException;
use App\Services\Exercise\ExerciseDatasetService;
use Illuminate\Support\Facades\Http;

function parsedDataset(): ExerciseDatasetData
{
    $service = app(ExerciseDatasetService::class);

    return $service->parse($service->load(datasetFixturePath()));
}

it('maps a record to an exercise with a title-case name and the mapped group', function () {
    $row = collect(parsedDataset()->rows)->firstWhere('externalId', '0025');

    expect($row->name)->toBe('Barbell Bench Press')
        ->and($row->slug)->toBeNull()
        ->and($row->equipment)->toBe('barbell')
        ->and($row->targetMuscle)->toBe('pectorals')
        ->and($row->secondaryMuscles)->toBe(['triceps', 'shoulders'])
        ->and($row->primaryMuscleGroup)->toBe(MuscleGroup::Chest)
        ->and($row->instructions)->toHaveKeys(['en', 'es'])
        ->and($row->instructions['es'][0])->toBe('Túmbate en un banco.');
});

it('keeps only the configured locales', function () {
    config(['catalog.locales' => ['en']]);

    $row = collect(parsedDataset()->rows)->firstWhere('externalId', '0025');

    expect(array_keys($row->instructions))->toBe(['en']);
});

it('keeps only English when a record has no Spanish steps', function () {
    $row = collect(parsedDataset()->rows)->firstWhere('externalId', '3001');

    expect(array_keys($row->instructions))->toBe(['en']);
});

it('rejects an unknown target and a record without steps, and keeps the rest', function () {
    $dataset = parsedDataset();

    expect($dataset->rows)->toHaveCount(10)
        ->and(collect($dataset->rejected)->pluck('id')->all())->toBe(['2000', '3000'])
        ->and(collect($dataset->rejected)->firstWhere('id', '2000')['reason'])->toContain("unknown target 'xyz'")
        ->and(collect($dataset->rejected)->firstWhere('id', '3000')['reason'])->toContain('instruction steps');
});

it('repairs a damaged name and reports it', function () {
    $dataset = parsedDataset();
    $row = collect($dataset->rows)->firstWhere('externalId', '0739');

    expect($row->name)->toBe('Sled 45° Leg Press')
        ->and($dataset->repaired)->toBe(['Sled 45° Leg Press']);
});

it('reports a name with unexpected non-ASCII characters without changing it', function () {
    $record = json_decode(file_get_contents(datasetFixturePath()), true)[1];
    $record['name'] = 'barbell bench préss';

    $dataset = app(ExerciseDatasetService::class)->parse([$record]);

    expect($dataset->suspicious)->toBe(['Barbell Bench Préss'])
        ->and($dataset->rows[0]->name)->toBe('Barbell Bench Préss');
});

it('rejects records that are not objects or have no id', function () {
    $dataset = app(ExerciseDatasetService::class)->parse(['text', ['name' => 'x']]);

    expect($dataset->rows)->toBe([])
        ->and($dataset->rejected)->toHaveCount(2);
});

it('fails on a file that does not exist or is not a JSON list', function () {
    $service = app(ExerciseDatasetService::class);
    $notAList = tempnam(sys_get_temp_dir(), 'ds');
    file_put_contents($notAList, '{"a": 1}');

    expect(fn () => $service->load('/nonexistent/exercises.json'))->toThrow(ExerciseDatasetUnreadableException::class)
        ->and(fn () => $service->load($notAList))->toThrow(ExerciseDatasetUnreadableException::class);

    file_put_contents($notAList, '{broken');
    expect(fn () => $service->load($notAList))->toThrow(ExerciseDatasetUnreadableException::class);
    unlink($notAList);
});

it('downloads the pinned dataset when no path is given', function () {
    Http::fake([config('catalog.dataset.url') => Http::response(file_get_contents(datasetFixturePath()))]);

    expect(app(ExerciseDatasetService::class)->load())->toHaveCount(12);
    Http::assertSentCount(1);
});

it('fails when the download fails', function () {
    Http::fake([config('catalog.dataset.url') => Http::response('nope', 500)]);

    expect(fn () => app(ExerciseDatasetService::class)->load())->toThrow(ExerciseDatasetUnreadableException::class);
});
