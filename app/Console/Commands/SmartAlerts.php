<?php

namespace App\Console\Commands;

use App\Mail\SmartAlertMail;
use App\Models\Business;
use App\Models\Setting;
use App\Support\Demo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * إرسال التنبيهات الذكية بالبريد لأصحاب المتاجر (تراجع مبيعات، منتجات راكدة، عملاء متعثرون).
 * يُجدول يوميًا في routes/console.php. تشغيل يدوي: php artisan alerts:smart
 * يحترم إعداد notify_smart_alerts لكل متجر (مفعّل افتراضيًا).
 */
class SmartAlerts extends Command
{
    protected $signature = 'alerts:smart';

    protected $description = 'إرسال التنبيهات الذكية بالبريد لكل متجر';

    public function handle(): int
    {
        $sent = 0;

        Business::whereNotNull('email')->each(function (Business $business) use (&$sent) {
            $pref = Setting::where('business_id', $business->id)->where('key', 'notify_smart_alerts')->value('value');
            if ($pref === '0' || $pref === 0 || $pref === false) {
                return; // معطّل صراحةً
            }

            $alerts = Demo::smartAlertsFor($business->id);
            if (empty($alerts)) {
                return;
            }

            Mail::to($business->email)->send(new SmartAlertMail($business->name, $alerts));
            $this->line(__('✓ :name — :count تنبيه → :email', ['name' => $business->name, 'count' => count($alerts), 'email' => $business->email]));
            $sent++;
        });

        $this->info($sent ? __('تم إرسال :count رسالة تنبيهات.', ['count' => $sent]) : __('لا توجد تنبيهات لإرسالها.'));

        return self::SUCCESS;
    }
}
