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
            $low = Product::where('business_id', $business->id)
                ->whereColumn('quantity', '<', 'alert_qty')
                ->orderBy('quantity')->get();

            if ($low->isEmpty()) {
                return;
            }

            Mail::to($business->email)->send(new LowStockMail($business->name, $low));
            $this->line("✓ {$business->name} — {$low->count()} منتج → {$business->email}");
            $sent++;
        });

        $this->info($sent ? "تم إرسال {$sent} تنبيه." : 'لا توجد متاجر بحاجة لتنبيه.');

        return self::SUCCESS;
    }
}
