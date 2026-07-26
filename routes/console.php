<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// نسخ احتياطي تلقائي يومي لكل المتاجر (الساعة 02:00)
Schedule::command('backup:run')->dailyAt('02:00')->withoutOverlapping();

// تنبيه انخفاض المخزون بالبريد يوميًا (الساعة 08:00)
Schedule::command('alerts:low-stock')->dailyAt('08:00')->withoutOverlapping();

// تقرير الأداء الشهري بالبريد (أول كل شهر 07:00)
Schedule::command('reports:email')->monthlyOn(1, '07:00')->withoutOverlapping();

// تنبيهات ذكية فورية بالبريد (تراجع مبيعات/منتجات راكدة/عملاء متعثرون) يوميًا 08:30
Schedule::command('alerts:smart')->dailyAt('08:30')->withoutOverlapping();

// ملخّص الأداء اليومي بالبريد لصاحب النشاط نهاية كل يوم (23:55)
Schedule::command('report:daily-summary')->dailyAt('23:55')->withoutOverlapping();
