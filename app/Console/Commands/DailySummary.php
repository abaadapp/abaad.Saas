<?php

namespace App\Console\Commands;

use App\Mail\DailySummaryMail;
use App\Models\Business;
use App\Models\Setting;
use App\Support\Demo;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/**
 * ملخّص أداء يومي تلقائي بالبريد لأصحاب المتاجر (مبيعات اليوم، الطلبات، صافي الأرباح، الأكثر مبيعًا…).
 * يُجدول نهاية كل يوم في routes/console.php. تشغيل يدوي: php artisan report:daily-summary
 * يحترم إعداد notify_daily_summary لكل متجر (مفعّل افتراضيًا).
 */
class DailySummary extends Command
{
    protected $signature = 'report:daily-summary {--date= : تاريخ بصيغة YYYY-MM-DD (افتراضي: اليوم)}';

    protected $description = 'إرسال ملخّص الأداء اليومي بالبريد لأصحاب المتاجر';

    public function handle(): int
    {
        $date = $this->option('date')
            ? Carbon::createFromFormat('Y-m-d', $this->option('date'))->startOfDay()
            : now()->startOfDay();
        $dateLabel = $date->locale('ar')->translatedFormat('l، j F Y');

        $sent = 0;
        Business::whereNotNull('email')->each(function (Business $business) use ($date, $dateLabel, &$sent) {
            $pref = Setting::where('business_id', $business->id)->where('key', 'notify_daily_summary')->value('value');
            if ($pref === '0' || $pref === 0 || $pref === false) {
                return; // معطّل صراحةً
            }

            $summary = Demo::dailySummaryFor($business->id, $date);

            // لا نرسل ملخّصًا لمتجر بلا أي نشاط في اليوم
            if ($summary['orders'] === 0 && $summary['sales'] <= 0) {
                return;
            }

            Mail::to($business->email)->send(new DailySummaryMail($business->name, $summary, $dateLabel));
            $this->line('✓ ' . $business->name . ' — ' . number_format($summary['sales'], 3) . ' → ' . $business->email);
            $sent++;
        });

        $this->info($sent ? __('تم إرسال :count ملخّص يومي.', ['count' => $sent]) : __('لا نشاط اليوم — لم يُرسَل شيء.'));

        return self::SUCCESS;
    }
}
