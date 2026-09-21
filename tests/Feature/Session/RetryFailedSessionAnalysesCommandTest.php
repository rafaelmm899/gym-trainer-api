<?php

use App\Actions\Session\SessionAnalysisRetryAction;
use App\Enums\Session\AnalysisState;
use App\Jobs\Session\SessionAnalysisJob;
use App\Models\TrainingSession;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Bus;

// TC-1
it('dispatches SessionAnalysisJob for every session in analysis_state failed', function () {
    Bus::fake([SessionAnalysisJob::class]);
    $sessions = TrainingSession::factory()->count(3)->state(['analysis_state' => AnalysisState::Failed])->create();

    $this->artisan('sessions:retry-failed-analysis')->assertSuccessful();

    Bus::assertDispatchedTimes(SessionAnalysisJob::class, 3);
    foreach ($sessions as $session) {
        Bus::assertDispatched(SessionAnalysisJob::class, fn (SessionAnalysisJob $job): bool => $job->session->is($session));
    }
});

// TC-2
it('does not dispatch for sessions in pending, processing, or done', function () {
    Bus::fake([SessionAnalysisJob::class]);
    TrainingSession::factory()->state(['analysis_state' => AnalysisState::Pending])->create();
    TrainingSession::factory()->state(['analysis_state' => AnalysisState::Processing])->create();
    TrainingSession::factory()->state(['analysis_state' => AnalysisState::Done])->create();

    $this->artisan('sessions:retry-failed-analysis')->assertSuccessful();

    Bus::assertNotDispatched(SessionAnalysisJob::class);
});

// TC-3
it('only re-dispatches the failed sessions in a mixed set', function () {
    Bus::fake([SessionAnalysisJob::class]);
    $failed = TrainingSession::factory()->count(2)->state(['analysis_state' => AnalysisState::Failed])->create();
    TrainingSession::factory()->state(['analysis_state' => AnalysisState::Pending])->create();
    TrainingSession::factory()->state(['analysis_state' => AnalysisState::Processing])->create();
    TrainingSession::factory()->state(['analysis_state' => AnalysisState::Done])->create();

    $this->artisan('sessions:retry-failed-analysis')->assertSuccessful();

    Bus::assertDispatchedTimes(SessionAnalysisJob::class, 2);
    foreach ($failed as $session) {
        Bus::assertDispatched(SessionAnalysisJob::class, fn (SessionAnalysisJob $job): bool => $job->session->is($session));
    }
});

// TC-4
it('runs cleanly when there are no failed sessions', function () {
    Bus::fake([SessionAnalysisJob::class]);

    $this->artisan('sessions:retry-failed-analysis')->assertSuccessful();

    Bus::assertNotDispatched(SessionAnalysisJob::class);
});

// TC-5
it('SessionAnalysisRetryAction returns exactly the sessions it acted on', function () {
    Bus::fake([SessionAnalysisJob::class]);
    $failed = TrainingSession::factory()->count(2)->state(['analysis_state' => AnalysisState::Failed])->create();
    TrainingSession::factory()->state(['analysis_state' => AnalysisState::Done])->create();

    $result = app(SessionAnalysisRetryAction::class)->handle();

    expect($result->pluck('id')->sort()->values()->all())->toBe($failed->pluck('id')->sort()->values()->all());
});

// TC-6
it('is registered on the schedule with the agreed cadence and overlap protection', function () {
    $this->artisan('schedule:list');

    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains($event->command, 'sessions:retry-failed-analysis'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 8,14,20 * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});
