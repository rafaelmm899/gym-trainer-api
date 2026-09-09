<?php

use App\Enums\Recommendation\RecommendationAction;
use App\Enums\Recommendation\RecommendationStatus;
use App\Exports\Cycle\CycleDayExport;
use App\Models\Cycle;
use App\Models\CycleDay;
use App\Models\DayExercise;
use App\Models\Exercise;
use App\Models\ExerciseRecommendation;
use App\Models\Routine;
use App\Models\User;
use App\Services\Recommendation\RecommendationCatalogService;
use Dedoc\Scramble\Generator;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

// docs/plans/export-training-day-csv-spec.md §8, TC-1..TC-13.
// (The route inheriting the root security scheme is asserted in
// tests/Feature/Auth/DocsSecurityTest.php.)

beforeEach(function () {
    $this->withHeader('Origin', config('app.url'));
    $this->user = User::factory()->create();
});

function exportUrl(Routine $routine, CycleDay $day): string
{
    return "/api/v1/routines/{$routine->uuid}/cycle-days/{$day->uuid}/export";
}

function expectedExportFilename(Routine $routine, CycleDay $day): string
{
    return (new CycleDayExport($routine, $day, app(RecommendationCatalogService::class)))->filename;
}

/**
 * A routine owned by the given user with one `active` cycle and one day.
 *
 * @param  array<string, mixed>  $cycleAttributes
 * @param  array<string, mixed>  $dayAttributes
 * @return array{0: Routine, 1: CycleDay}
 */
function routineWithActiveDay(User $user, array $cycleAttributes = [], array $dayAttributes = []): array
{
    $routine = Routine::factory()->for($user)->create();
    $cycle = Cycle::factory()->active()->for($routine)->create($cycleAttributes);
    $day = CycleDay::factory()->for($cycle)->create($dayAttributes);

    return [$routine, $day];
}

// ---------------------------------------------------------------------------
// Feature — happy path
// ---------------------------------------------------------------------------

// TC-1
it('downloads the day as an .xlsx workbook scoped to that day', function () {
    Excel::fake();

    $routine = Routine::factory()->for($this->user)->create(['name' => 'Volumen invierno']);
    $cycle = Cycle::factory()->active()->for($routine)->create(['sequence_number' => 3]);

    $dayOne = CycleDay::factory()->for($cycle)->create(['order' => 1, 'label' => 'Empuje']);
    $press = Exercise::factory()->create(['name' => 'Press banca']);
    DayExercise::factory()->for($dayOne)->for($press)->create(['order' => 1]);
    ExerciseRecommendation::factory()->for($this->user)->for($routine)->for($press)->create([
        'status' => RecommendationStatus::Active,
        'explanation' => 'solo dia 1',
    ]);

    $dayThree = CycleDay::factory()->for($cycle)->create([
        'order' => 3,
        'label' => 'Piernas',
        'focus_muscle_groups' => ['quads', 'glutes'],
    ]);
    DayExercise::factory()->for($dayThree)->for(Exercise::factory()->create(['name' => 'Sentadilla']))
        ->create(['order' => 1, 'sets' => 4]);
    DayExercise::factory()->for($dayThree)->for(Exercise::factory()->create(['name' => 'Zancada']))
        ->create(['order' => 2, 'sets' => 3]);

    $this->actingAs($this->user)->get(exportUrl($routine, $dayThree))->assertOk();

    Excel::assertDownloaded('volumen-invierno-ciclo-3-dia-3-piernas.xlsx', function (CycleDayExport $export): bool {
        $comments = exportCommentLines($export->headings());
        $data = $export->array();

        expect($comments[0])->toBe('# rutina: Volumen invierno | ciclo 3 | dia 3 (Piernas) | foco: quads, glutes')
            ->and($export->headings())->toContain([
                'exercise', 'set_number', 'prescribed_weight_kg', 'prescribed_reps', 'prescribed_rpe',
                'rest_seconds', 'recommended_weight_kg', 'recommended_action', 'weight_kg', 'reps', 'rpe', 'note',
            ])
            ->and($data)->toHaveCount(7)
            ->and(array_column($data, 1))->toBe([1, 2, 3, 4, 1, 2, 3])
            ->and(collect($data)->every(fn (array $r): bool => $r[8] === null && $r[9] === null && $r[10] === null && $r[11] === null))->toBeTrue()
            ->and(implode("\n", $comments).implode(',', array_column($data, 0)))
            ->not->toContain('Press banca')
            ->not->toContain('solo dia 1');

        return true;
    });
});

// TC-2
it('fills the recommended columns and the # line for an exercise with an active recommendation', function () {
    Excel::fake();

    [$routine, $day] = routineWithActiveDay($this->user);
    $squat = Exercise::factory()->create(['name' => 'Sentadilla']);
    DayExercise::factory()->for($day)->for($squat)->create(['sets' => 2]);
    ExerciseRecommendation::factory()->for($this->user)->for($routine)->for($squat)->create([
        'status' => RecommendationStatus::Active,
        'target_weight_kg' => 102.5,
        'action' => RecommendationAction::AdvanceWeight,
        'explanation' => 'Subiste las 4x5 a RPE 7',
    ]);

    $this->actingAs($this->user)->get(exportUrl($routine, $day))->assertOk();

    Excel::assertDownloaded(expectedExportFilename($routine, $day), function (CycleDayExport $export): bool {
        $data = $export->array();

        expect(collect($data)->every(fn (array $r): bool => $r[6] === 102.5 && $r[7] === 'advance_weight'))->toBeTrue()
            ->and(implode("\n", exportCommentLines($export->headings())))
            ->toContain('Recomendacion: advance_weight — Subiste las 4x5 a RPE 7');

        return true;
    });
});

// ---------------------------------------------------------------------------
// Feature — authorization & errors
// ---------------------------------------------------------------------------

// TC-3
it('rejects a {day} that belongs to another routine', function () {
    [$routine] = routineWithActiveDay($this->user);
    [, $foreignDay] = routineWithActiveDay(User::factory()->create());

    $this->actingAs($this->user)
        ->getJson("/api/v1/routines/{$routine->uuid}/cycle-days/{$foreignDay->uuid}/export")
        ->assertStatus(422)
        ->assertJsonPath('data.code', 'CYCLE_DAY_NOT_IN_ACTIVE_CYCLE');
});

// TC-4
it('rejects a {day} from a non-active cycle of the same routine', function () {
    $routine = Routine::factory()->for($this->user)->create();
    $oldCycle = Cycle::factory()->completed()->for($routine)->create(['sequence_number' => 1]);
    Cycle::factory()->active()->for($routine)->create(['sequence_number' => 2]);
    $oldDay = CycleDay::factory()->for($oldCycle)->create();

    $this->actingAs($this->user)
        ->getJson(exportUrl($routine, $oldDay))
        ->assertStatus(422)
        ->assertJsonPath('data.code', 'CYCLE_DAY_NOT_IN_ACTIVE_CYCLE');
});

// TC-5
it('rejects a routine whose current cycle is not active', function () {
    $routine = Routine::factory()->for($this->user)->create();
    $cycle = Cycle::factory()->generating()->for($routine)->create();
    $day = CycleDay::factory()->for($cycle)->create();

    $this->actingAs($this->user)
        ->getJson(exportUrl($routine, $day))
        ->assertStatus(422)
        ->assertJsonPath('data.code', 'ROUTINE_HAS_NO_ACTIVE_CYCLE');
});

// TC-6
it('rejects an archived routine with ROUTINE_HAS_NO_ACTIVE_CYCLE', function () {
    $routine = Routine::factory()->for($this->user)->archived()->create();
    $cycle = Cycle::factory()->completed()->for($routine)->create();
    $day = CycleDay::factory()->for($cycle)->create();

    $this->actingAs($this->user)
        ->getJson(exportUrl($routine, $day))
        ->assertStatus(422)
        ->assertJsonPath('data.code', 'ROUTINE_HAS_NO_ACTIVE_CYCLE');
});

// TC-7
it('returns 403 for a routine owned by another user', function () {
    [$routine, $day] = routineWithActiveDay(User::factory()->create());

    $this->actingAs($this->user)
        ->getJson(exportUrl($routine, $day))
        ->assertForbidden()
        ->assertJsonPath('data.code', 'AUTHORIZATION_EXCEPTION');
});

// TC-8
it('returns 404 for an unknown routine uuid', function () {
    $this->actingAs($this->user)
        ->getJson('/api/v1/routines/'.Str::uuid()->toString().'/cycle-days/'.Str::uuid()->toString().'/export')
        ->assertNotFound()
        ->assertJsonPath('data.code', 'NOT_FOUND_EXCEPTION');
});

// TC-9
it('returns 404 for an unknown day uuid', function () {
    $routine = Routine::factory()->for($this->user)->create();
    Cycle::factory()->active()->for($routine)->create();

    $this->actingAs($this->user)
        ->getJson("/api/v1/routines/{$routine->uuid}/cycle-days/".Str::uuid()->toString().'/export')
        ->assertNotFound()
        ->assertJsonPath('data.code', 'NOT_FOUND_EXCEPTION');
});

// TC-10
it('returns 404 for a non-uuid path segment', function () {
    $this->actingAs($this->user)
        ->getJson('/api/v1/routines/not-a-uuid/cycle-days/also-bad/export')
        ->assertNotFound()
        ->assertJsonPath('data.code', 'NOT_FOUND_EXCEPTION');
});

// TC-11
it('rejects an unauthenticated request', function () {
    [$routine, $day] = routineWithActiveDay($this->user);

    $this->getJson(exportUrl($routine, $day))
        ->assertUnauthorized()
        ->assertJsonPath('data.code', 'AUTHENTICATION_EXCEPTION');
});

// TC-12
it('renders without tripping strict-mode lazy loading', function () {
    Excel::fake();

    [$routine, $day] = routineWithActiveDay($this->user);
    $withRec = Exercise::factory()->create(['name' => 'A']);
    DayExercise::factory()->for($day)->for($withRec)->create(['order' => 1, 'sets' => 2]);
    DayExercise::factory()->for($day)->for(Exercise::factory()->create(['name' => 'B']))->create(['order' => 2, 'sets' => 2]);
    DayExercise::factory()->for($day)->for(Exercise::factory()->create(['name' => 'C']))->create(['order' => 3, 'sets' => 1]);
    ExerciseRecommendation::factory()->for($this->user)->for($routine)->for($withRec)->create([
        'status' => RecommendationStatus::Active,
    ]);

    $this->actingAs($this->user)->get(exportUrl($routine, $day))->assertOk();

    Excel::assertDownloaded(expectedExportFilename($routine, $day));
});

// TC-13
it('streams a genuine, non-empty xlsx file', function () {
    [$routine, $day] = routineWithActiveDay($this->user);
    DayExercise::factory()->for($day)->create(['sets' => 2]);

    $response = $this->actingAs($this->user)->get(exportUrl($routine, $day));

    $response->assertOk()
        ->assertDownload(expectedExportFilename($routine, $day))
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    expect(substr($response->streamedContent(), 0, 2))->toBe('PK'); // xlsx is a zip archive
});

// ---------------------------------------------------------------------------
// Feature — docs
// ---------------------------------------------------------------------------

// TC-14 (companion assertion; the security-scheme check is in DocsSecurityTest)
it('documents the export response as a spreadsheet, not application/json', function () {
    $spec = app(Generator::class)();

    $content = $spec['paths']['/api/v1/routines/{routine}/cycle-days/{day}/export']['get']['responses'][200]['content'];

    expect($content)->toHaveKey('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
        ->and($content)->not->toHaveKey('application/json');
});
