<?php

namespace App\Console\Commands;

use App\Support\InvoiceLoyalty;
use Illuminate\Console\Command;

/**
 * نقاطُ روابطَ سُدّدت ولم تُحسم — سقط حسمُها بعد أن ثبت مالُها.
 *
 * يمسّ ما كتب عليه التحصيلُ «بانتظار النقاط» وحده، فلا يمنح نقاطًا بأثرٍ رجعيّ
 * لما سُدّد قبل هذه الميزة. وآمنٌ أن يُكرَّر: الحسمُ مرّةً تحت قفل الرابط.
 */
class ReconcileInvoiceLinkLoyalty extends Command
{
    protected $signature = 'customer-invoices:reconcile-loyalty';

    protected $description = 'حسمُ نقاط الولاء لروابط دفعٍ سُدّدت ولم تُحسم نقاطُها';

    public function handle(): int
    {
        $this->info('كُتبت نقاطٌ لـ: '.InvoiceLoyalty::reconcile());

        return self::SUCCESS;
    }
}
