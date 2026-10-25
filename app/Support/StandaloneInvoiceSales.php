<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * مبيعاتُ فواتير العملاء التي لا طلبَ لها — من قيودها، شاملةً ضريبتَها.
 *
 * ═══ العطب الذي وُضعت له ═══
 *
 * «مبيعات اليوم» و«مبيعات الشهر» ومخطّطُ السنة وتوزيعُ الدفع في اللوحة،
 * والملخّصُ اليوميّ وتنبيهُ «مبيعات اليوم»، كانت تجمع `orders` وحدها.
 * وفاتورةُ العميل اليدويّة لا طلبَ لها: تُرحَّل إيرادًا وضريبةً يومَ صدورها
 * (`CustomerInvoices::post`)، فيعدّها «صافي الأرباح» في اللوحة نفسِها
 * وتقريرُ المبيعات، وتغيب عن «مبيعات اليوم». فاتورةٌ بمئةٍ صدرت وسُدّدت
 * ووصلت لوحةَ التجهيز، والبطاقةُ تقول صفرًا.
 *
 * ═══ ولمَ القيدُ لا جدولُ الفواتير ═══
 *
 * هذه سطورُ `CustomerInvoices::ledgerLines` — ما يقرؤه إقرارُ الضريبة بعد
 * الطلبات (`ReportData`):
 *   - الفاتورةُ يومَ صدورها (`issued_at`)، لا يومَ سدادها ولا يومَ تجهيزها؛
 *   - إشعارُ الدائن اليدويّ يُنقصها يومَه، وإلغاؤها عكسٌ في يومه؛
 *   - المولودةُ من طلبٍ (`coversOrders`) لا قيدَ لها، فلا تُعدّ بعد طلبها؛
 *   - والتحصيلُ قيدٌ على الذمّة والصندوق لا على الإيراد، فلا يُعدّ بيعًا.
 * فالطلباتُ ثمّ هذه السطور يعدّان كلَّ بيعةٍ مرّةً.
 *
 * ═══ والضريبة ═══
 *
 * `orders.total` شاملٌ للضريبة، فيُجمع إليه مثلُه: سطرُ الإيراد (دائنٌ ناقصَ
 * مدين) وسطرُ «ضريبة مستحقّة» من القيد نفسه — أي إجماليُّ الفاتورة.
 *
 * ═══ والفرع ═══
 *
 * فرعُ القيد، كما يقرؤه `Ledger::netRevenue` تحت «صافي الأرباح». والفاتورةُ
 * بلا فرع — وهي كلُّ ما يُكتب من شاشة الفواتير اليوم — في «كل الفروع» وحده:
 * لا تُنسب إلى فرعٍ لم يبعها، ولا تُعدّ في كلّ فرع.
 */
final class StandaloneInvoiceSales
{
    /** المجموعُ من يوم `$from` إلى ما قبل يوم `$to` */
    public static function total(int $businessId, CarbonInterface $from, ?CarbonInterface $to = null, ?int $branchId = null): float
    {
        return round((float) self::lines($businessId, $from, $to, $branchId)
            ->sum(DB::raw('journal_lines.credit - journal_lines.debit')), 3);
    }

    /**
     * مقسومةً على الزمن — والخانةُ يومُ القيد.
     *
     * @return array<string, float> [مفتاح الخانة => المبلغ]
     */
    public static function byBucket(int $businessId, string $unit, CarbonInterface $from, ?CarbonInterface $to = null, ?int $branchId = null): array
    {
        $bucket = Demo::bucketSql('je.entry_date', $unit);

        return self::lines($businessId, $from, $to, $branchId)
            ->selectRaw("{$bucket} as bucket, SUM(journal_lines.credit - journal_lines.debit) as v")
            ->groupBy('bucket')
            ->get()
            ->mapWithKeys(fn ($r) => [(string) $r->bucket => round((float) $r->v, 3)])
            ->all();
    }

    /** @return Builder */
    private static function lines(int $businessId, CarbonInterface $from, ?CarbonInterface $to, ?int $branchId)
    {
        return CustomerInvoices::ledgerLines($businessId)
            ->where(fn ($q) => $q->where('a.system_key', 'tax_payable')->orWhere('a.type', Ledger::REVENUE_TYPE))
            ->where('je.entry_date', '>=', $from->toDateString())
            ->when($to, fn ($q) => $q->where('je.entry_date', '<', $to->toDateString()))
            ->when($branchId !== null, fn ($q) => $q->where('je.branch_id', $branchId));
    }
}
