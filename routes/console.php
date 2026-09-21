<?php

use App\Console\Commands\Session\RetryFailedSessionAnalysesCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command(RetryFailedSessionAnalysesCommand::class)
    ->cron('0 8,14,20 * * *')
    ->timezone(config('app.timezone'))
    ->withoutOverlapping();
