<?php

namespace App\Console\Commands;

use App\Support\PaymentLinks;
use Illuminate\Console\Command;

/**
 * روابطُ دفعٍ انقضت مهلتُها ← «منتهٍ».
 *
 * لا يمسّ تحصيلًا ولا يُلغي شيئًا عند Paymob: الرابطُ ينتهي عندها بمهلته.
 * وإشعارُ دفعٍ يصل بعدها لمالٍ قُبض قبلها يُسجَّل كما هو (`PaymentLinks::callback`).
 */
class ExpirePaymentLinks extends Command
{
    protected $signature = 'payment-links:expire';

    protected $description = 'وسمُ روابط الدفع المنتهية';

    public function handle(): int
    {
        $this->info('انتهى: '.PaymentLinks::expireDue());

        return self::SUCCESS;
    }
}
