<?php

namespace App\Console\Commands;

use App\Mail\LowStockMail;
use App\Models\Business;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * إرسال تنبيه بريد إلكتروني لأصحاب المتاجر التي بها منتجات منخفضة المخزون.
 * يُجدول يوميًا في routes/console.php. تشغيل يدوي: php artisan alerts:low-stock
 */
class LowStockAlerts extends Command
{
    protected $signature = 'alerts:low-stock';

    protected $description = 'إرسال تنبيهات بريدية لانخفاض المخزون لكل متجر';

    public function handle(): int
    {
        $sent = 0;

        Business::whereNotNull('email')->each(function (Business $business) use (&$sent) {
            // القاعدة نفسها التي تلوّن الحالة وتبني قائمة إعادة الطلب
            $low = Product::where('business_id', $business->id)
                ->get()
                ->filter(fn ($p) => Product::statusFor((int) $p->quantity, (int) $p->alert_qty) !== 'متوفر')
                ->sortBy('quantity')
                ->values();

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
