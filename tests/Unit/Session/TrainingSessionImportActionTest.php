<?php

use App\Actions\Session\TrainingSessionImportAction;
use App\Enums\Session\SessionStatus;
use App\Exceptions\Cycle\CycleDayNotInActiveCycleException;
use App\Jobs\Session\SessionAnalysisJob;
use App\Models\SetLog;
use App\Models\TrainingSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

// Unit coverage for docs/plans/import-training-day-xlsx-spec.md §8, Action TC-16..TC-17.
uses(TestCase::class, RefreshDatabase::class);

function importAction(): TrainingSessionImportAction
{
    return app(TrainingSessionImportAction::class);
}

// TC-16
it('opens, logs, and completes the session, then dispatches the analysis job', function () {
    Bus::fake([SessionAnalysisJob::class]);

    $user = User::factory()->create();
    [$routine, $day] = importRoutineWithActiveDay($user);
    prescribeExercise($day, 'Sentadilla');

    $file = buildXlsxUploadedFile([
        importRow('Sentadilla', 100, 8),
        importRow('Sentadilla', 102.5, 7),
    ]);

    $session = importAction()->handle($user, $routine, $day, $file);

    expect($session->status)->toBe(SessionStatus::Completed)
        ->and($session->cycle_day_id)->toBe($day->id)
        ->and(SetLog::query()->where('session_id', $session->id)->count())->toBe(2)
        ->and(SetLog::query()->where('session_id', $session->id)->pluck('set_number')->sort()->values()->all())->toBe([1, 2]);

    Bus::assertDispatched(SessionAnalysisJob::class, fn (SessionAnalysisJob $job): bool => $job->session->is($session));
});

// TC-17
it('persists nothing when the import fails validation', function () {
    Bus::fake([SessionAnalysisJob::class]);

    $user = User::factory()->create();
    [$routine, $day] = importRoutineWithActiveDay($user);
    prescribeExercise($day, 'Sentadilla');

    $file = buildXlsxUploadedFile([
        importRow('Sentadilla', -5, 8),
    ]);

    expect(fn () => importAction()->handle($user, $routine, $day, $file))
        ->toThrow(ValidationException::class);

    expect(TrainingSession::query()->count())->toBe(0)
        ->and(SetLog::query()->count())->toBe(0);

    Bus::assertNotDispatched(SessionAnalysisJob::class);
});

it('propagates a guard exception from the import service unchanged', function () {
    $user = User::factory()->create();
    [$routine] = importRoutineWithActiveDay($user);
    [, $foreignDay] = importRoutineWithActiveDay(User::factory()->create());

    $file = buildXlsxUploadedFile([]);

    expect(fn () => importAction()->handle($user, $routine, $foreignDay, $file))
        ->toThrow(CycleDayNotInActiveCycleException::class);

    expect(TrainingSession::query()->count())->toBe(0);
});
