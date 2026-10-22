<?php

use App\Services\SourceCommandRegistry;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The cycle owns source frequency, checkpoints, expiration and scheduler health.
// Do not schedule the individual collectors alongside it.
Schedule::command('alertapy:cycle')
    ->everyMinute()
    ->withoutOverlapping((int) ceil(SourceCommandRegistry::lockSeconds() / 60));

Schedule::command('alertapy:sync-ine-territories')
    ->weeklyOn(1, '03:20')
    ->withoutOverlapping(30);

Schedule::command('alertapy:sync-critical-infrastructure')
    ->monthlyOn(1, '04:10')
    ->withoutOverlapping(120);
