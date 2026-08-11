<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// قلبُ الاشتراكات المنتهية إلى «منتهي» أول كل يوم — قبل أن يفتح أحد لوحته
Schedule::command('subscriptions:expire')->dailyAt('00:10')->withoutOverlapping();

// نسخ احتياطي تلقائي يومي لكل المتاجر (الساعة 02:00)
Schedule::command('backup:run')->dailyAt('02:00')->withoutOverlapping();

/*
 * محو ما انقضت مهلته في سلّة المحذوفات (02:30) — بعد النسخ الاحتياطي لا
 * قبله: المحو لا رجعة فيه، وأقرب نسخةٍ إليه يجب أن تكون قد التقطت الصفوف
 * قبل ذهابها. نصف ساعةٍ تكفي لأكبر متجرٍ عندنا بمراحل.
 */
Schedule::command('trash:purge')->dailyAt('02:30')->withoutOverlapping();

/*
 * إنذار الاشتراك (07:30) — قبل `subscriptions:expire` بيومٍ في المعنى لا في
 * الساعة: الإنذار يُرسل ما دام المتجر يعمل، فلو تأخّر عن قلب الحالة لوصل
 * الخبر بعد وقوعه.
 */
Schedule::command('subscriptions:notify')->dailyAt('07:30')->withoutOverlapping();

// تنبيه انخفاض المخزون بالبريد يوميًا (الساعة 08:00)
Schedule::command('alerts:low-stock')->dailyAt('08:00')->withoutOverlapping();

// تقرير الأداء الشهري بالبريد (أول كل شهر 07:00)
Schedule::command('reports:email')->monthlyOn(1, '07:00')->withoutOverlapping();

// تنبيهات ذكية فورية بالبريد (تراجع مبيعات/منتجات راكدة/عملاء متعثرون) يوميًا 08:30
Schedule::command('alerts:smart')->dailyAt('08:30')->withoutOverlapping();

// ملخّص الأداء اليومي بالبريد لصاحب النشاط نهاية كل يوم (23:55)
Schedule::command('report:daily-summary')->dailyAt('23:55')->withoutOverlapping();
