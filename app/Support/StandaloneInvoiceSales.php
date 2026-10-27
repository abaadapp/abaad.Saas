<?php

namespace App\Support;

use App\Models\CustomerCreditNote;
use App\Models\CustomerInvoice;
use App\Models\CustomerPaymentLink;
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
 *
 * ═══ والفاتورةُ التي طُلب مالُها برابط دفع — يومَ يصل مالُها ═══
 *
 * «أصدر الفاتورة وأنشئ رابط دفع» ضغطةٌ واحدة. فكانت الفاتورةُ تدخل «مبيعات
 * اليوم» بإجماليّها لحظةَ يُرسَل الرابط — قبل أن يدفع العميلُ شيئًا — فيقرأ
 * التاجرُ مالًا لم يصل، ولو رفض العميلُ أو انقضى الرابط.
 *
 * فالفاتورةُ التي فُتح عليها رابطُ دفعٍ حقيقيّ يومَ صدورها (`billedByLink`) تُعدّ هنا
 * **بما سُدّد منها فعلًا، يومَ سداده** (`collections`) — بالرابط أو نقدًا أو
 * بأيّ وسيلة — لا بإجماليّها يومَ صدورها. فرابطٌ معلّقٌ أو مرفوضٌ أو منتهٍ
 * لا يُعدّ، والجزئيُّ بقدره، والباقي يُعدّ يومَ يُدفع. ولا يتجاوز ما يُعدّ
 * إجماليَّها: التخصيصُ لا يزيد على الباقي.
 *
 * **والدفترُ لا يتغيّر:** الفاتورةُ رُحّلت إيرادًا وضريبةً يومَ صدورها كما
 * كانت، و«صافي الأرباح» وتقريرُ المبيعات وإقرارُ الضريبة تقرؤها هناك. هذا
 * عرضُ «مبيعات اليوم» التشغيليّ وحده، لا اعترافٌ بالإيراد.
 *
 * ورابطُ التجربة (`is_live = false`) ليس طلبَ مالٍ حقيقيّ، والرابطُ الذي فشل
 * إنشاؤه لم يصل أحدًا — فلا يجعلان الفاتورةَ «مطلوبةً برابط». والفاتورةُ
 * المولودةُ من طلبات لا تُعدّ هنا أصلًا: طلباتُها عُدّت يومَ بيعها.
 */
final class StandaloneInvoiceSales
{
    /** المجموعُ من يوم `$from` إلى ما قبل يوم `$to` */
    public static function total(int $businessId, CarbonInterface $from, ?CarbonInterface $to = null, ?int $branchId = null): float
    {
        return round((float) self::lines($businessId, $from, $to, $branchId)
            ->sum(DB::raw('journal_lines.credit - journal_lines.debit'))
            + (float) self::collections($businessId, $from, $to, $branchId)->sum('a.amount'), 3);
    }

    /**
     * مقسومةً على الزمن — والخانةُ يومُ القيد.
     *
     * @return array<string, float> [مفتاح الخانة => المبلغ]
     */
    public static function byBucket(int $businessId, string $unit, CarbonInterface $from, ?CarbonInterface $to = null, ?int $branchId = null): array
    {
        $issued = self::lines($businessId, $from, $to, $branchId)
            ->selectRaw(Demo::bucketSql('je.entry_date', $unit).' as bucket, SUM(journal_lines.credit - journal_lines.debit) as v')
            ->groupBy('bucket')->get();
        $collected = self::collections($businessId, $from, $to, $branchId)
            ->selectRaw(Demo::bucketSql('p.occurred_at', $unit).' as bucket, SUM(a.amount) as v')
            ->groupBy('bucket')->get();

        $out = [];
        foreach ($issued->concat($collected) as $r) {
            $out[(string) $r->bucket] = round(($out[(string) $r->bucket] ?? 0) + (float) $r->v, 3);
        }

        return $out;
    }

    /**
     * فواتيرُ هذا المتجر التي فُتح عليها رابطُ دفعٍ حقيقيّ — معلّقًا أو مدفوعًا
     * أو منتهيًا أو ملغى. وما فشل إنشاؤه لم يصل العميلَ، ورابطُ التجربة ليس
     * طلبَ مال.
     *
     * ═══ ورابطٌ فُتح يومَ الفاتورة لا بعده ═══
     *
     * فاتورةٌ صدرت الاثنين بلا رابط عُدّت في مبيعات الاثنين، ثمّ أُرسل لها رابطٌ
     * الثلاثاء ودُفع الأربعاء. لو صار رابطُ الثلاثاء يُخرجها من الاثنين لتغيّر
     * رقمُ يومٍ مضى — قرأه التاجرُ ووصله في الملخّص اليوميّ — ولَعادت يومَ
     * الأربعاء بيعًا لم يقع فيه. فهي بيعُ الاثنين كما كانت، ومالُ الأربعاء
     * تحصيلٌ في «تحصيلات اليوم» (`Settlements::collections`) لا بيعٌ ثانٍ.
     *
     * فالمطلوبةُ برابطٍ ما فُتح رابطُها يومَ صدورها أو قبله — بتاريخها
     * (`issued_at`) أو بيومِ إصدارها فعلًا (`issued_by_at`) إن صدرت بتاريخٍ مضى:
     * «أصدر الفاتورة وأنشئ رابط دفع» ضغطةٌ واحدةٌ في يومٍ واحد.
     */
    private static function billedByLink(Builder $q, int $businessId): Builder
    {
        return $q->selectRaw('1')->from('customer_payment_links as bl')
            ->join('customer_invoices as bi', fn ($j) => $j->on('bi.id', '=', 'bl.customer_invoice_id')
                ->on('bi.business_id', '=', 'bl.business_id'))
            ->where('bl.business_id', $businessId)
            ->where('bl.is_live', true)
            ->where('bl.status', '!=', CustomerPaymentLink::FAILED)
            ->where(fn ($w) => $w->whereRaw('DATE(bl.created_at) <= DATE(bi.issued_at)')
                ->orWhereRaw('DATE(bl.created_at) <= DATE(bi.issued_by_at)'));
    }

    /**
     * ما سُدّد من الفواتير المطلوبة برابط — يومَ سداده.
     *
     * بالقاعدة التي تقول «المسدَّد» في كلّ مكان (`CustomerInvoice::paidSql`):
     * تخصيصٌ من تحصيلٍ لم يُلغَ ولم يرجع شيكُه. فإشعارٌ مكرّرٌ لا يُعدّ مرّتين
     * (تحصيلٌ واحدٌ للرابط)، وتحصيلٌ أُلغي أو استُرجع يخرج، وزيادةُ الدفع تبقى
     * رصيدًا للعميل لا بيعًا (التخصيصُ بقدر الباقي). واليومُ يومُ التحصيل —
     * والرابطُ يكتبه بيوم المتجر (`PaymentLinks::settle`)، لا يومَ فُتح الرابط.
     */
    private static function collections(int $businessId, CarbonInterface $from, ?CarbonInterface $to, ?int $branchId): Builder
    {
        return DB::table('customer_payment_allocations as a')
            ->join('customer_payments as p', 'p.id', '=', 'a.customer_payment_id')
            ->join('customer_invoices as ci', 'ci.id', '=', 'a.customer_invoice_id')
            ->where('p.business_id', $businessId)
            ->where('ci.business_id', $businessId)
            ->whereNull('p.cancelled_at')
            ->where(fn ($q) => $q->whereNull('p.cheque_status')->orWhere('p.cheque_status', '<>', Cheques::BOUNCED))
            ->whereExists(fn ($q) => self::billedByLink($q, $businessId)->whereColumn('bl.customer_invoice_id', 'ci.id'))
            // والمولودةُ من طلباتٍ عُدّت طلباتُها يومَ بيعها
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('customer_invoice_orders as cio')->whereColumn('cio.customer_invoice_id', 'ci.id'))
            ->where('p.occurred_at', '>=', $from->toDateString())
            ->when($to, fn ($q) => $q->where('p.occurred_at', '<', $to->toDateString()))
            ->when($branchId !== null, fn ($q) => $q->where('ci.branch_id', $branchId));
    }

    /** @return Builder */
    private static function lines(int $businessId, CarbonInterface $from, ?CarbonInterface $to, ?int $branchId)
    {
        /*
         * فاتورةُ السطر — من مصدر قيده، أو من الأصل الذي يعكسه؛ وإشعارُ الدائن
         * بفاتورته. وسطرٌ لا مصدرَ له يبقى كما كان: لا يُسقطه شرطٌ لا يعرفه.
         */
        $source = 'COALESCE(orig.sourceable_type, je.sourceable_type)';
        $sourceId = 'COALESCE(orig.sourceable_id, je.sourceable_id)';
        $invoice = "(CASE WHEN {$source} = ? THEN {$sourceId}"
            ." ELSE (SELECT n.customer_invoice_id FROM customer_credit_notes n WHERE n.id = {$sourceId} AND {$source} = ?) END)";

        return CustomerInvoices::ledgerLines($businessId)
            ->where(fn ($q) => $q->where('a.system_key', 'tax_payable')->orWhere('a.type', Ledger::REVENUE_TYPE))
            // والمطلوبةُ برابطٍ تُعدّ بما سُدّد منها (`collections`) لا بقيد صدورها
            ->whereNotExists(fn ($q) => self::billedByLink($q, $businessId)
                ->whereRaw("bl.customer_invoice_id = {$invoice}", [CustomerInvoice::class, CustomerCreditNote::class]))
            ->where('je.entry_date', '>=', $from->toDateString())
            ->when($to, fn ($q) => $q->where('je.entry_date', '<', $to->toDateString()))
            ->when($branchId !== null, fn ($q) => $q->where('je.branch_id', $branchId));
    }
}
