<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Transaction;
use App\Support\OrderStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * فاتورةٌ بيعت ولا قيدَ دخلٍ لها — تُكتب لها واحد.
 *
 * المالية كلُّها تقرأ `transactions`: إجمالي المبيعات، وصافي الإيراد،
 * والضريبة المحصّلة، ووسائل الدفع. والربحية تقرأ منها الإيراد وتقرأ
 * التكلفة من بنود الطلبات.
 *
 * فما دخل النظام من بابٍ لا يكتب القيد — بذورٌ تجريبية، أو استيرادٌ، أو
 * نقلٌ من نظامٍ قديم — يظهر تكلفةً بلا إيراد. رأيتُها على المتجر التجريبيّ:
 * ألفٌ وثمانٍ وخمسون فاتورة بلا قيد، فقالت الشاشة «خسارةٌ صافية بمليون
 * ريال» على متجرٍ باع مليونين وسبعمئة ألف.
 *
 * والأمر يُكتب مرّةً ويُعاد بلا أثر: ما له قيدٌ يُترك.
 */
class RepairOrderIncome extends Command
{
    protected $signature = 'finance:repair-order-income {--business= : متجرٌ بعينه} {--dry-run : اعرض ولا تكتب}';

    protected $description = 'يكتب قيد الدخل الناقص للفواتير المباعة';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        /*
         * الملغى لا قيدَ له، والمعلّق لم يُبَع بعد.
         *
         * وهي القاعدة نفسها التي يقرأ بها `Order::scopeSold` التقارير —
         * فلو خالفتها هنا لكتبتُ دخلًا على بيعةٍ لا تعدّها التقارير بيعة.
         */
        $query = Order::query()
            ->where('is_held', false)
            ->where('status', '!=', OrderStatus::CANCELLED)
            ->whereDoesntHave('transactions', fn ($q) => $q->where('type', 'دخل'))
            ->when($this->option('business'), fn ($q, $id) => $q->where('business_id', $id))
            ->orderBy('id');

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('كلّ فاتورةٍ مباعة لها قيدُ دخلها.');

            return self::SUCCESS;
        }

        $this->line("فواتير بلا قيد دخل: {$total}");

        $written = 0;
        $sum = 0.0;

        $query->chunkById(200, function ($orders) use ($dry, &$written, &$sum) {
            foreach ($orders as $order) {
                $sum += (float) $order->total;
                $written++;

                if ($dry) {
                    continue;
                }

                DB::transaction(fn () => Transaction::create([
                    'business_id' => $order->business_id,
                    'order_id' => $order->id,
                    'reference' => $order->number,
                    'description' => 'مبيعات نقطة البيع — ' . ($order->customer_name ?? 'عميل نقدي'),
                    'method' => $order->payment_method ?? 'نقدي',
                    'type' => 'دخل',
                    'amount' => $order->total,
                    'tax_amount' => $order->tax ?? 0,
                    'employee_name' => $order->employee_name,
                    // تاريخُ البيع لا تاريخُ الإصلاح: قيدٌ بتاريخ اليوم يجعل
                    // مبيعات العام كلّها تظهر في شهرٍ واحد
                    'occurred_at' => $order->ordered_at ?? $order->created_at,
                ]));
            }
        });

        $this->info(($dry ? 'كان سيُكتب ' : 'كُتب ') . $written . ' قيدًا بمجموع ' . number_format($sum, 3));

        return self::SUCCESS;
    }
}
