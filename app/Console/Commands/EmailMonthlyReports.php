<?php

namespace App\Console\Commands;

use App\Mail\MonthlyReportMail;
use App\Models\Business;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * إرسال تقرير أداء شهري بالبريد لأصحاب المتاجر (بيانات الشهر السابق).
 * يُجدول شهريًا في routes/console.php. تشغيل يدوي: php artisan reports:email
 */
class EmailMonthlyReports extends Command
{
    protected $signature = 'reports:email {--month= : شهر بصيغة YYYY-MM (افتراضي: الشهر السابق)}';

    protected $description = 'إرسال تقرير الأداء الشهري بالبريد لأصحاب المتاجر';

    public function handle(): int
    {
        $ref = $this->option('month')
            ? \Illuminate\Support\Carbon::createFromFormat('Y-m', $this->option('month'))->startOfMonth()
            : now()->subMonthNoOverflow()->startOfMonth();
        $start = $ref->copy()->startOfMonth();
        $end = $ref->copy()->endOfMonth();
        $period = $start->locale('ar')->translatedFormat('F Y');

        $sent = 0;
        Business::whereNotNull('email')->each(function (Business $b) use ($start, $end, $period, &$sent) {
            $q = Order::where('business_id', $b->id)->where('is_held', false)->whereBetween('ordered_at', [$start, $end]);
            $sales = (float) (clone $q)->sum('total');
            $orders = (clone $q)->count();

            if ($orders === 0 && $sales <= 0) {
                return; // لا نرسل لمتجر بلا نشاط
            }

            $topItem = OrderItem::whereHas('order', fn ($w) => $w->where('business_id', $b->id)->where('is_held', false)->whereBetween('ordered_at', [$start, $end]))
                ->selectRaw('name, SUM(quantity) as q')->groupBy('name')->orderByDesc('q')->first();

            $money = fn ($v) => number_format((float) $v, 3, '.', ',') . ' ' . __('ر.ع');
            $stats = [
                ['label' => __('إجمالي المبيعات'), 'value' => $money($sales)],
                ['label' => __('عدد الطلبات'), 'value' => (string) $orders],
                ['label' => __('متوسط قيمة الطلب'), 'value' => $money($orders ? $sales / $orders : 0)],
                ['label' => __('المنتج الأكثر مبيعًا'), 'value' => $topItem->name ?? '—'],
            ];

            Mail::to($b->email)->send(new MonthlyReportMail($b->name, $period, $stats));
            $this->line("✓ {$b->name} → {$b->email}");
            $sent++;
        });

        $this->info($sent ? __('تم إرسال :count تقرير عن :period.', ['count' => $sent, 'period' => $period]) : __('لا توجد متاجر ذات نشاط لإرسال تقارير.'));

        return self::SUCCESS;
    }
}
