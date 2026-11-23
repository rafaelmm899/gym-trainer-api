<?php

namespace App\Console\Commands\Exercise;

use App\Actions\Exercise\ExerciseImportAction;
use App\Exceptions\Exercise\ExerciseDatasetUnreadableException;
use Illuminate\Console\Command;

final class ImportExercisesCommand extends Command
{
    protected $signature = 'exercises:import {--path= : Local dataset JSON file; by default the pinned dataset is downloaded}';

    protected $description = 'Load the exercise catalogue from the exercises dataset and the hand-written exercises. Safe to re-run.';

    public function handle(ExerciseImportAction $action): int
    {
        try {
            $report = $action->handle($this->option('path') ?: null);
        } catch (ExerciseDatasetUnreadableException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Exercises: {$report->created} created, {$report->updated} updated, {$report->unchanged} unchanged.");

        foreach ($report->repaired as $name) {
            $this->line("Repaired the encoding of: {$name}");
        }

        foreach ($report->suspicious as $name) {
            $this->warn("Unexpected non-ASCII characters in: {$name}");
        }

        foreach ($report->rejected as $rejection) {
            $this->error("Rejected {$rejection['id']}: {$rejection['reason']}");
        }

        return $report->rejected === [] ? self::SUCCESS : self::FAILURE;
    }
}
