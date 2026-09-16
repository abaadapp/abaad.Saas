<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\PurchaseOrder;
use App\Support\GoodsReceipts;
use App\Support\Ledger;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * يستدرك بضاعةً دخلت الرفَّ ولم يعرفها الدفتر.
 *
 * ═══ العطبُ الذي خلّف هذه الصفوف ═══
 *
 * كان المخزونُ يُقيَّد مدينًا عند **سند المورّد** وحده، والبضاعةُ تدخل عند
 * **إذن الاستلام**. فأمرُ شراءٍ استُلمت بضاعتُه ولم يصل سندُه قطّ يجلس على
 * الرفّ بلا قيد — ثمّ يُباع فيُنقص المخزونَ بتكلفته. وقيس على الإنتاج يوم
 * كُتب هذا الأمر: أربعةُ أوامرَ في «متجري» بـ٢٦٠٫٦٥٤ ر.ع.
 *
 * وأُصلح البابُ في `GoodsReceipts::approve` — لكنّ ما دخل قبله بقي بلا قيد.
 *
 * ═══ ولا يُضاعِف ═══
 *
 * أمرٌ استُلم **وفُوتر** قُيّد مخزونُه بالسند القديم. فالمستدرَكُ هو الفارق
 * وحدَه: قيمةُ ما وصل بإشعاراتٍ لا قيدَ لها، ناقصًا ما فُوتر من الأمر.
 * وصفرٌ أو أقلُّ يعني أنّ الدفتر يعرفه — فلا يُلمس.
 *
 * وكلُّ قيدٍ يُكتب مشيرًا إلى أمر الشراء (`sourceable`)، فمن استُدرك مرّةً
 * لا يُستدرك ثانيةً ولو نُودي الأمرُ كلَّ ليلة.
 *
 * ═══ ويُشغَّل بعد النشر لا قبله ═══
 *
 * يعتمد على أنّ الإشعارات التي لا قيدَ لها هي إشعاراتُ ما قبل الإصلاح. وما
 * يُعتمد بعده يُقيَّد لحظتَه، فلا يراه هذا الأمرُ أصلًا.
 */
class PostMissingGoodsReceipts extends Command
{
    protected $signature = 'finance:post-missing-goods-receipts
        {--business= : متجرٌ بعينه}
        {--dry-run : عدٌّ بلا كتابة}';

    protected $description = 'ترحيل بضاعةٍ استُلمت ولم يصل سندُها — إلى «بضاعة مستلمة بلا فاتورة»';

    /** مصدرُ القيد — يميّز المستدرَك عن المُقيَّد في وقته */
    public const SOURCE = 'استدراك استلام';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $posted = 0;
        $value = 0.0;

        $businesses = Business::query()
            ->when($this->option('business'), fn ($q, $id) => $q->whereKey($id))
            ->orderBy('id')->get();

        foreach ($businesses as $business) {
            $rows = [];

            foreach ($this->candidates((int) $business->id) as $po) {
                $gap = $this->gap($po);

                if ($gap <= 0) {
                    continue;
                }

                $rows[] = [$po, $gap];
            }

            if (! $rows) {
                continue;
            }

            $sum = round(array_sum(array_column($rows, 1)), 3);
            $this->line('  '.$business->name.' — '.count($rows).' أمرًا بـ'.number_format($sum, 3).' ر.ع');

            if ($dry) {
                $posted += count($rows);
                $value += $sum;

                continue;
            }

            foreach ($rows as [$po, $gap]) {
                Ledger::post(
                    (int) $business->id,
                    __('استدراك استلام — أمر ').$po->number,
                    [
                        ['account' => 'inventory', 'debit' => $gap],
                        ['account' => 'goods_received_not_invoiced', 'credit' => $gap,
                            'memo' => $po->supplier_name ?: $po->supplier?->name],
                    ],
                    /* بتاريخ آخر استلامٍ لا تاريخِ اليوم: القيدُ يخصّ يومَ وصلت البضاعة */
                    $this->receivedAt($po),
                    self::SOURCE,
                    $po->branch_id,
                    null,
                    $po,
                );

                $posted++;
                $value += $gap;
            }
        }

        if ($posted === 0) {
            $this->info('لا استلامَ ينتظر ترحيلًا.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info(($dry ? 'عدٌّ بلا كتابة — ' : '').'رُحّل '.$posted.' أمرًا بـ'.number_format($value, 3).' ر.ع');

        if ($dry) {
            return self::SUCCESS;
        }

        /*
         * والميزانُ يُقرأ بعد الكتابة لا يُفترض — كما في `finance:post-missing-stock-losses`.
         */
        foreach ($businesses as $business) {
            $tb = Ledger::trialBalance((int) $business->id);
            $ok = abs($tb['total_debit'] - $tb['total_credit']) < 0.0005;
            $this->line(($ok ? '  <fg=green>✓</> ' : '  <fg=red>✗</> ').$business->name.' — مدين '
                .number_format($tb['total_debit'], 3).' / دائن '.number_format($tb['total_credit'], 3));
        }

        return self::SUCCESS;
    }

    /**
     * أوامرُ الشراء التي وصلت بضاعتُها — وحدَها.
     *
     * ولا شرطَ ثانٍ يمنع التكرار: القياسُ نفسُه يمنعه. أمرٌ استُدرك قبلُ
     * صار مُعترَفًا به في الدفتر، ففجوتُه صفرٌ ولا يُلمس — انظر `gap`.
     * وحارسٌ ثانٍ لسؤالٍ واحد يفترق عنه يومَ يُبدَّل أحدُهما.
     *
     * @return Collection<int, PurchaseOrder>
     */
    private function candidates(int $businessId)
    {
        return PurchaseOrder::where('business_id', $businessId)
            ->whereExists(function ($q) {
                $q->selectRaw('1')->from('purchase_order_items as i')
                    ->whereColumn('i.purchase_order_id', 'purchase_orders.id')
                    ->whereNotNull('i.product_id')
                    ->where('i.received_quantity', '>', 0);
            })
            ->orderBy('id')->get();
    }

    /**
     * ما وصل ولم يعترف به الدفتر — وصفرٌ أو أقلُّ يعني أنّه يعرفه.
     *
     * ═══ ولمَ يُقاس المُعترَفُ به من الدفتر لا من السندات ═══
     *
     * كان: «ما وصل ناقصًا ما فُوتر». وهو يفترض أنّ كلَّ سندٍ قُيّد مخزونًا،
     * وأنّ كلَّ استلامٍ لم يُفوتر لم يُقيَّد. والفرضان يسقطان معًا: سندٌ بلا
     * أمرٍ لا يُنسب، واستلامٌ استُدرك أمسِ يُستدرك اليوم ثانيةً.
     *
     * والدفترُ يقول الجواب بلا فرض: كم قُيّد مخزونًا لهذا الأمر من أيّ باب.
     */
    private function gap(PurchaseOrder $po): float
    {
        return round(
            GoodsReceipts::receivedOnOrder((int) $po->id)
            - GoodsReceipts::recognizedStock((int) $po->business_id, (int) $po->id),
            3,
        );
    }

    /** تاريخُ آخر استلامٍ معتمَدٍ على الأمر — وإلّا فاليوم */
    private function receivedAt(PurchaseOrder $po): Carbon
    {
        $at = DB::table('goods_receipt_notes')
            ->where('purchase_order_id', $po->id)
            ->where('status', GoodsReceipts::APPROVED)
            ->max('received_at');

        return $at ? Carbon::parse($at) : now();
    }
}
