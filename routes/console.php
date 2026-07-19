<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// نسخ احتياطي تلقائي يومي لكل المتاجر (الساعة 02:00)
Schedule::command('backup:run')->dailyAt('02:00')->withoutOverlapping();
