<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Horizon maintenance tasks
Schedule::command('horizon:snapshot')->everyFiveMinutes();
Schedule::command('horizon:clear-metrics')->hourly();
Schedule::command('horizon:clear')->daily();
