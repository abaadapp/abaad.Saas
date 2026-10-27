<?php

namespace App\Support;

use App\Models\CustomerPayment;
use App\Models\SupplierInvoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * التحصيلُ والسدادُ في المدة — مالٌ قُبض من العملاء أو دُفع للموردين فعلًا.
 *
 * ═══ قراءةٌ لا حساب ═══
 *
 * رقمان للعلم لا يدخلان ربحًا ولا ذمّة: المبيعاتُ تُقرأ من الطلبات، وتكلفةُ
 * البضاعة من المباع، والمصروفاتُ من المصروفات (`Demo::reportSummary`).
 * والتحصيلُ تسويةُ ذمّةٍ بِيعت قبلُ، والسدادُ تسويةُ سندٍ دخلت كلفتُه المخزونَ
 * يومَ اعتُمد — فعدُّهما في الربح عدٌّ للشيء مرّتين.
 *
 * ═══ ومن أين ═══
 *
 * التحصيل: صفوفُ `customer_payments` نفسُها — المستندُ الذي يُخصَّص على
 * الفواتير ويُرحَّل قيدُه. لا مجموعُ الفواتير ولا حالُها «مدفوعة».
 *   - الملغى لا يُعدّ (`CustomerPayment::live`): عُكس قيدُه وسقط تخصيصُه.
 *   - والشيكُ ليس مالًا حتّى يُصرف (`Cheques`): تحت التحصيل لا يُعدّ،
 *     والمرتجعُ لا يُعدّ، والمحصَّلُ يُعدّ **بتاريخ صرفه** (`cheque_settled_at`).
 *   - وغيرُ الشيك بتاريخ قبضه (`occurred_at`).
 *   - وما زاد عن الفواتير يُعدّ: مالٌ قُبض فعلًا وبقي رصيدًا للعميل.
 *   - والإشعارُ الدائن لا يمسّه: يُنقص ذمّةً ولا يُخرج مالًا.
 *
 * السداد: قيودُ «سداد مورّد» على سندات الموردين — وهي ما تقرؤه صفحةُ السند
 * نفسُها في سجلّ سداداته (`SupplierInvoiceController::show`). فعمودُ
 * `supplier_invoices.paid` مجموعٌ لا يقول **متى**، والقيدُ يقول.
 *   - كلُّ دفعةٍ قيدٌ بتاريخها (`entry_date` = تاريخُ السداد المكتوب).
 *   - والمعكوسُ لا يُعدّ (`reversed_at`) — ولا قيدُ عكسه، فمصدرُه غيرُه.
 *
 * ═══ والفرع ═══
 *
 * الملخّصُ المالي للمتجر كلِّه، بلا مبدّل فرع: ونسبةُ التحصيل والسداد إلى
 * فرعٍ لا يكتبها النظام (لا عمودَ فرعٍ في التحصيل، وقيدُ السداد بلا فرع).
 * فلا تُخترع هنا.
 *
 * ═══ والمدة ═══
 *
 * بداية الفترة من `Demo::rangeStart` كما يقرؤها سائرُ الملخّص، ولا نهايةَ
 * لها غيرُ اليوم — والتاريخان عمودا `date`، فيُقارنان بتاريخٍ لا بلحظة:
 * SQLite تقرؤهما نصًّا، و`'2026-10-04' >= '2026-10-04 00:00:00'` كاذبة.
 */
final class Settlements
{
    /** مصدرُ قيد السداد — كما يكتبه `SupplierInvoiceController::pay` */
    public const SUPPLIER_SOURCE = 'سداد مورّد';

    /**
     * تحصيلاتُ العملاء في المدة — المبلغُ وعددُ العمليات.
     *
     * @return array{amount: float, count: int}
     */
    public static function collections(int $businessId, ?Carbon $start): array
    {
        $day = $start?->toDateString();

        $row = CustomerPayment::where('business_id', $businessId)->live()
            ->where(function ($q) use ($start, $day) {
                $q->where(fn ($q) => $q->whereNull('cheque_status')
                    ->when($day, fn ($q) => $q->where('occurred_at', '>=', $day)))
                    ->orWhere(fn ($q) => $q->where('cheque_status', Cheques::CLEARED)
                        ->when($start, fn ($q) => $q->where('cheque_settled_at', '>=', $start)));
            })
            ->selectRaw('COUNT(*) c, COALESCE(SUM(amount),0) s')
            ->first();

        return ['amount' => round((float) $row->s, 3), 'count' => (int) $row->c];
    }

    /**
     * مدفوعاتُ الموردين في المدة — المبلغُ وعددُ العمليات.
     *
     * والمبلغُ مجموعُ الجانب المدين من القيد: سطرُ «الموردين» وحده مدين فيه
     * — وهو ما يعرضه سجلُّ السدادات في صفحة السند.
     *
     * @return array{amount: float, count: int}
     */
    public static function supplierPayments(int $businessId, ?Carbon $start): array
    {
        $row = DB::table('journal_entries as e')
            ->join('journal_lines as l', 'l.journal_entry_id', '=', 'e.id')
            ->where('e.business_id', $businessId)
            ->where('e.source', self::SUPPLIER_SOURCE)
            ->where('e.sourceable_type', SupplierInvoice::class)
            ->whereNull('e.reversed_at')
            ->when($start, fn ($q) => $q->where('e.entry_date', '>=', $start->toDateString()))
            ->selectRaw('COUNT(DISTINCT e.id) c, COALESCE(SUM(l.debit),0) s')
            ->first();

        return ['amount' => round((float) $row->s, 3), 'count' => (int) $row->c];
    }
}
