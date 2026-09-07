<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Support\Receivables;
use App\Support\WhatsAppAutomation;
use App\Support\WhatsAppEvent;
use Illuminate\Console\Command;

/**
 * تذكيرُ السداد — يوميًّا، بلا يدٍ تضغط.
 *
 * والتاجرُ لا يتذكّر أن يطالب. فاتورةٌ تستحقّ بعد ثلاثة أيّامٍ تُذكَّر مرّة،
 * وفاتورةٌ تجاوزت استحقاقَها تُذكَّر مرّة — ولا ثالثة: فاتورةٌ تأخّرت شهرًا
 * لا تُرسل ثلاثين رسالة، ومن أراد الإلحاح يضغط الزرّ بيده.
 *
 * ومنعُ التكرار في `dedupe_key` لا في عمودٍ يُكتب هنا: عمودٌ «آخرُ تذكير»
 * يحتاج أن يُحدَّث في كلّ مسارٍ يُرسل، وأوّلُ مسارٍ يُنسى فيه يعيد الإرسال.
 *
 * ولا تُرسَل إلّا لمن فعّل المفتاح في «إشعارات واتساب» — قرارُ التاجر لا
 * قرارُنا. ومتجرٌ لم يربط واتساب لا يُقيَّد له صفٌّ ولا تُستهلك حصّة.
 */
class RemindUnpaidInvoices extends Command
{
    /** الأيّامُ التي يُذكَّر قبلها بالاستحقاق — ثلاثةٌ تكفي لترتيب حوالة */
    private const DUE_SOON_DAYS = 3;

    protected $signature = 'invoices:remind {--business= : متجرٌ بعينه}';

    protected $description = 'يرسل تذكير سداد للفواتير المستحقّة قريبًا والمتأخّرة';

    public function handle(): int
    {
        $businesses = Business::query()->real()
            ->when($this->option('business'), fn ($q, $id) => $q->whereKey($id))
            ->pluck('id');

        $queued = 0;
        $soon = now()->addDays(self::DUE_SOON_DAYS)->endOfDay();

        foreach ($businesses as $businessId) {
            foreach (Receivables::openInvoices($businessId) as $invoice) {
                if ($invoice->due_at === null) {
                    // بلا موعدٍ متّفقٍ عليه لا يُقال إنّه تأخّر
                    continue;
                }

                $event = match (true) {
                    $invoice->daysOverdue() > 0 => WhatsAppEvent::INVOICE_OVERDUE,
                    $invoice->due_at->endOfDay()->lte($soon) => WhatsAppEvent::INVOICE_DUE_SOON,
                    default => null,
                };

                if ($event === null) {
                    continue;
                }

                if (WhatsAppAutomation::handleInvoice($invoice, $event) !== null) {
                    $queued++;
                }
            }
        }

        $this->info("تذكيرات: {$queued}");

        return self::SUCCESS;
    }
}
