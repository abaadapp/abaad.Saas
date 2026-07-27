<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Transaction;
use Illuminate\Console\Command;

/**
 * تسجيل معاملات دخل للمبيعات القديمة (طلبات نقطة البيع المكتملة) التي أُنشئت قبل ربط
 * المبيعات بالمالية — معاملة واحدة لكل طلب (تتخطّى ما سبق تسجيله عبر order_id).
 * تشغيل: php artisan finance:backfill-sales
 */
class BackfillSalesTransactions extends Command
{
    protected $signature = 'finance:backfill-sales {--business= : حصر التنفيذ بنشاط محدد}';

    protected $description = 'إنشاء معاملات دخل للمبيعات القديمة التي لا تملك معاملة مالية';

    public function handle(): int
    {
        $created = 0;

        Order::where('is_held', false)
            ->when($this->option('business'), fn ($q) => $q->where('business_id', $this->option('business')))
            ->whereNotIn('id', Transaction::whereNotNull('order_id')->pluck('order_id'))
            ->orderBy('id')
            ->chunk(200, function ($orders) use (&$created) {
                foreach ($orders as $order) {
                    Transaction::create([
                        'business_id' => $order->business_id,
                        'order_id' => $order->id,
                        'reference' => $order->number,
                        'description' => 'مبيعات نقطة البيع — ' . ($order->customer_name ?? 'عميل نقدي'),
                        'method' => $order->payment_method ?? 'نقدي',
                        'type' => 'دخل',
                        'amount' => $order->total,
                        'tax_amount' => $order->tax ?? 0,
                        'employee_name' => $order->employee_name ?? '—',
                        'occurred_at' => $order->ordered_at ?? $order->created_at,
                    ]);
                    $created++;
                }
            });

        $this->info("تم إنشاء {$created} معاملة دخل للمبيعات القديمة.");

        return self::SUCCESS;
    }
}
