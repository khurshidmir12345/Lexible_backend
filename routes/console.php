<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| Translating four hundred thousand words is not one job — the provider's
| daily quota stops it around seventeen thousand, so it is a job that runs
| every night and picks up where it left off.
|
| The queue orders itself: teachable words first, commonest first, so the
| vocabulary a learner actually meets is finished within the first few nights
| and the long tail fills in behind it. `--retry` brings back yesterday's
| quota failures, which is what most of a night's queue is after the first
| pass. Nothing here needs supervising; it simply stops being useful once
| every word has a translation.
*/
Schedule::command('dictionary:translate --limit=40000 --retry --lang=uz')
    ->dailyAt('01:00')
    ->withoutOverlapping(600)
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/translate-uz.log'));
