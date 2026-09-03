<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Support\Books;
use Illuminate\Console\Command;

/**
 * ترحيل المبيعات القديمة إلى دفتر الأستاذ — بيدٍ لا تلقائيًّا.
 *
 * صار البيع يُرحَّل لحظةَ وقوعه، وما بيع قبل هذه النسخة لا قيد له: الدفتر
 * يعرف ما خرج من الصندوق ولا يعرف ما دخله، فرصيدُ الصندوق فيه سالبٌ بمقدار
 * المصروفات كلّها.
 *
 * والاستدراك لا يقع في هجرة: هو يكتب مئاتِ القيود في دفترٍ ماليّ ويغيّر كلّ
 * تقريرٍ تاريخيّ، وذلك قرار صاحب النشاط لا أثرٌ جانبيّ لنشرة. فيُشغَّل حين
 * يُقرَّر، ويُقرأ أثرُه قبل ذلك بـ`--dry-run`.
 *
 * ولا يُرحَّل شيءٌ مرّتين: الفاتورة التي لها قيدٌ حيّ تُتخطّى، فتشغيلُه مرّتين
 * كتشغيله مرّة (انظر Books::recordSale).
 */
class PostSalesToLedger extends Command
{
    protected $signature = 'sales:post-ledger
        {--business= : رقم النشاط — وبلا تحديدٍ: كل الأنشطة}
        {--from= : من تاريخ (Y-m-d)}
        {--dry-run : يعدّ ولا يكتب}';

    protected $description = 'ترحيل مبيعات نقطة البيع القديمة إلى دفتر الأستاذ (قيد لكل فاتورة)';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $orders = Order::query()
            // الملغاة والمعلّقة ليست بيعًا — ولا يُرحَّل ما لا يُقرأ بيعًا
            ->sold()
            ->when($this->option('business'), fn ($q) => $q->where('business_id', $this->option('business')))
            ->when($this->option('from'), fn ($q) => $q->whereDate('ordered_at', '>=', $this->option('from')))
            ->orderBy('id');

        $posted = 0;
        $skipped = 0;
        $failed = 0;

        $orders->chunkById(200, function ($chunk) use ($dry, &$posted, &$skipped, &$failed) {
            foreach ($chunk as $order) {
                if (Books::liveEntryFor($order)) {
                    $skipped++;

                    continue;
                }

                if ($dry) {
                    $posted++;

                    continue;
                }

                try {
                    Books::recordSale($order) ? $posted++ : $skipped++;
                } catch (\Throwable $e) {
                    $failed++;
                    $this->warn("تعذّر ترحيل {$order->number}: ".$e->getMessage());
                }
            }
        });

        $this->info($dry
            ? "سيُرحَّل {$posted} فاتورة، ويُتخطّى {$skipped} لها قيدٌ أصلًا."
            : "رُحّلت {$posted} فاتورة، وتُخطّي {$skipped}، وتعذّر {$failed}.");

        return self::SUCCESS;
    }
}
