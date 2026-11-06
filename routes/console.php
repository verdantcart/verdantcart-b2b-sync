<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Retries with exponential backoff. Locally: run `php artisan schedule:work` in its own window.
Schedule::command('sync:retry-due')->everyMinute()->withoutOverlapping();

// Proves the queue workers are alive: the dashboard's "Worker last seen" tile shows when this
// last ran, so a restart (which empties the demo's in-container Redis) never leaves it at "never".
Schedule::command('queue:heartbeat --wait=0')->everyFiveMinutes()->withoutOverlapping();
