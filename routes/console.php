<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Retries with exponential backoff. Locally: run `php artisan schedule:work` in its own window.
Schedule::command('sync:retry-due')->everyMinute()->withoutOverlapping();
