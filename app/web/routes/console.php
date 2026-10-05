<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('characterization-documents:purge-pending')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('characterization-documents:recover-stale-extractions')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Admission remains inside the command; no scheduler flag implies activation.
if (app()->environment('testing') && config('services.learning_batch.enabled') === true
    && config('services.learning_batch.trusted_launcher') === true) {
    Schedule::command('learning:batch')->daily();
}
