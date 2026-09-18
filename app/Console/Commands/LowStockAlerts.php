<?php

namespace App\Console\Commands;

use App\Mail\LowStockMail;
use App\Models\Business;
use App\Models\Product;
use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * إرسال تنبيه بريد إلكتروني لأصحاب المتاجر التي بها منتجات منخفضة المخزون.
 * يُجدول يوميًا في routes/console.php. تشغيل يدوي: php artisan alerts:low-stock
 *
 * ═══ ويحترم «التنبيهات الذكية» ═══
 *
 * المفتاحُ في الإعدادات لافتتُه: «التنبيهات الذكية — نفاد المخزون، تراجع
 * المبيعات، والعملاء المتعثّرون». وكان يُطفئ `alerts:smart` وحدَها، وهذه
 * المهمّةُ لا تسأل عنه: يطفئه التاجرُ فيصله بريدُ المخزون كلَّ صباحٍ من
 * بابٍ آخر، ولا مقبضَ في الشاشة يوقفه.
 *
 * ومقبضٌ يُدير بعضَ ما تقوله لافتتُه أسوأ من مقبضٍ لا يُدير شيئًا: الأوّل
 * يُجرَّب فيبدو أنّه عمل، فيُبحث عن العطب في غير موضعه.
 */
class LowStockAlerts extends Command
{
    protected $signature = 'alerts:low-stock';

    protected $description = 'إرسال تنبيهات بريدية لانخفاض المخزون لكل متجر';

    public function handle(): int
    {
        $sent = 0;

        Business::whereNotNull('email')->each(function (Business $business) use (&$sent) {
            $pref = Setting::where('business_id', $business->id)
                ->where('key', 'notify_smart_alerts')->value('value');

            if ($pref === '0' || $pref === 0 || $pref === false) {
                return; // معطّل صراحةً
            }

            /*
             * والقاعدةُ واحدةٌ مع الجرس — `Product::scopeNeedsStockAlert`.
             *
             * كانت هنا `statusFor` وهي تعدّ صنفًا كميّتُه صفرٌ وحدُّه صفر.
             * فمن كتب `alert_qty = 0` على صنفٍ يقول «لا تنبّهني بهذا» يسكت
             * عنه الجرسُ ويصله بريدٌ كلَّ صباح. والصفرُ اختيارٌ لا سهو:
             * الافتراضُ عشرة.
             *
             * والترشيحُ في القاعدة لا في الذاكرة: كان يُقرأ كلُّ صنفٍ في
             * المتجر ثمّ يُرشَّح — والباقةُ تبيع مئةَ ألف صنف.
             */
            $low = Product::where('business_id', $business->id)
                ->needsStockAlert()->orderBy('quantity')->get();

            if ($low->isEmpty()) {
                return;
            }

            Mail::to($business->email)->send(new LowStockMail($business->name, $low));
            $this->line(__('✓ :name — :count منتج → :email', ['name' => $business->name, 'count' => $low->count(), 'email' => $business->email]));
            $sent++;
        });

        $this->info($sent ? __('تم إرسال :count تنبيه.', ['count' => $sent]) : __('لا توجد متاجر بحاجة لتنبيه.'));

        return self::SUCCESS;
    }
}
