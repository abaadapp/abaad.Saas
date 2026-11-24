<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Support\CustomerInvoicePreparations;
use Illuminate\Console\Command;

/**
 * فواتيرُ سُدّدت كلُّها وطُلب تجهيزُها ولم تظهر مهامُّها — تُعاد.
 *
 * التفعيلُ يقع بعد أن يثبت المال (إشعارُ Paymob، وتحصيلٌ مسجَّل)، وسقوطُه
 * هناك لا يُسقط التحصيل. وهذا ما يُعيده — ولا يُفعّل إلّا ما يُفعّله السدادُ
 * نفسُه، ولا مرّتين. ويعمل كلَّ عشر دقائق لكلّ المتاجر المفتوحة لها.
 *
 *   --dry-run  يعدّ لكلّ متجرٍ ما يعود، ولا يكتب شيئًا.
 *   --history  يعيد معه ما سُدّد قديمًا وفات موعدُه: ربّما سُلّم بيدٍ، فلا
 *              يعود وحده. بصمت: لا ورقةَ تُطبع ولا رسالةَ لزبون.
 */
class ReconcileCustomerInvoicePreparations extends Command
{
    protected $signature = 'customer-invoices:reconcile-preparations
        {--history : يعيد معه ما سُدّد قديمًا وفات موعدُه}
        {--dry-run : يعدّ ولا يكتب}';

    protected $description = 'إعادةُ تفعيل مهامّ التجهيز لفواتير سُدّدت ولم تظهر';

    public function handle(): int
    {
        if ($this->option('dry-run')) {
            $pending = CustomerInvoicePreparations::pending();
            $names = Business::whereIn('id', array_keys($pending))->pluck('name', 'id');

            foreach ($pending as $bid => $n) {
                $this->line("#{$bid} {$names[$bid]} — تعود وحدها: {$n['live']}، ولا تعود إلا بـ--history: {$n['history']}");
            }
            $this->info('المجموع — وحدها: '.array_sum(array_column($pending, 'live')).'، بالأمر: '.array_sum(array_column($pending, 'history')));

            return self::SUCCESS;
        }

        $this->info('ظهرت: '.CustomerInvoicePreparations::reconcile((bool) $this->option('history')));

        return self::SUCCESS;
    }
}
