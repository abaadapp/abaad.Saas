<?php

namespace App\Console\Commands;

use App\Support\CustomerInvoicePreparations;
use Illuminate\Console\Command;

/**
 * فواتيرُ دُفعت عبر رابطٍ موثَّق ولم تظهر مهامُّها — تُعاد.
 *
 * التفعيلُ يقع بعد أن يثبت المال (`PaymentLinks::callback`)، وسقوطُه هناك
 * لا يُسقط التحصيل. وهذا ما يُعيده: لا يُفعّل إلّا ما يُفعّله الإشعارُ نفسُه
 * — رابطٌ يُحتسب وفاتورةٌ سُدّدت كلُّها — ولا يُفعّل مرّتين.
 */
class ReconcileCustomerInvoicePreparations extends Command
{
    protected $signature = 'customer-invoices:reconcile-preparations';

    protected $description = 'إعادةُ تفعيل مهامّ التجهيز لفواتير سُدّدت ولم تظهر';

    public function handle(): int
    {
        $this->info('ظهرت: '.CustomerInvoicePreparations::reconcile());

        return self::SUCCESS;
    }
}
