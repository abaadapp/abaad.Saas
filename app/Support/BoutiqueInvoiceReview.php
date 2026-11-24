<?php

namespace App\Support;

use App\Models\Boutique;
use App\Models\CustomerInvoice;
use App\Models\Order;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * بنودُ فواتير العملاء التي فيها صنفُ بوتيكٍ ولم تدخل حسابَه — قراءةٌ للمراجعة.
 *
 * ═══ لمَ توجد ═══
 *
 * فاتورةُ العميل بلا طلب (اليدويّةُ وطلبُ واتساب الآليّ) لا تحمل بنودُها صاحبَ
 * الصنف ولا نسبتَه، وحسابُ البوتيك يُقرأ من بنود الطلبات وحدها. فيُباع صنفُه فيها
 * ولا يُستحقّ له شيء. وإصلاحُه هجرةٌ وقرارٌ ماليّ؛ وهذا الصنفُ يقول أين هو حتّى ذلك.
 *
 * ═══ ولا يحسب مستحقًّا ═══
 *
 * النسبةُ وصاحبُ الصنف يومَ البيع لم يُحفظا على البند. فلا يُضرب ثمنٌ في نسبة
 * اليوم، ولا يُنسب بندٌ إلى بوتيك: يُعرض المبلغُ كما كُتب، وبوتيكُ الصنف **اليوم**
 * باسمه هذا، و**قرينةٌ** من لقطةٍ محفوظة — آخرُ بيعٍ للصنف نفسِه في طلبٍ قبل
 * الفاتورة (`order_items.boutique_*`) — ليقرّر المحاسب. ولا يكتب شيئًا.
 */
final class BoutiqueInvoiceReview
{
    public const PER_PAGE = 25;

    public const MANUAL = 'manual';

    public const WHATSAPP = 'whatsapp';

    /**
     * ما يطلبه الرابط — مُنقًّى. وبوتيكٌ من متجرٍ آخر يُقرأ «الكلّ» كما في `Boutiques::scope`.
     *
     * @return array{period: ?string, boutique: ?int, page: int}
     */
    public static function filters(Request $request, int $businessId): array
    {
        $boutique = $request->query('boutique');
        $period = $request->query('period');
        $page = $request->query('page');

        return [
            // «كلّ الأشهر» حين لا يُطلب شهر: المراجعةُ للتاريخ كلِّه
            'period' => is_string($period) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) ? $period : null,
            'boutique' => is_string($boutique) && ctype_digit($boutique)
                && Boutique::where('business_id', $businessId)->whereKey((int) $boutique)->exists() ? (int) $boutique : null,
            'page' => is_string($page) && ctype_digit($page) && (int) $page > 0 ? min((int) $page, 100000) : 1,
        ];
    }

    /**
     * البنودُ المقصودة: صادرةٌ بلا طلب، وصنفُها لبوتيكٍ اليوم — أو بيع يومًا لبوتيك.
     *
     * والبندُ المولودُ من بند طلبٍ خارجٌ (`order_item_id`)، وفاتورةُ الطلب أو الشهر
     * خارجة (`customer_invoice_orders`): بيعُهما في الطلب ومحسوبٌ هناك.
     */
    private static function lines(int $businessId, array $f): Builder
    {
        $was = fn ($q) => $q->selectRaw('1')->from('order_items as oi')
            ->whereColumn('oi.product_id', 'p.id')->whereNotNull('oi.boutique_id')
            ->when($f['boutique'] !== null, fn ($w) => $w->where('oi.boutique_id', $f['boutique']));

        return DB::table('customer_invoice_items as cii')
            ->join('customer_invoices as ci', 'ci.id', '=', 'cii.customer_invoice_id')
            ->join('products as p', 'p.id', '=', 'cii.product_id')
            ->where('ci.business_id', $businessId)
            ->where('p.business_id', $businessId)
            ->where('ci.status', CustomerInvoice::ISSUED)
            ->whereNull('cii.order_item_id')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('customer_invoice_orders as cio')->whereColumn('cio.customer_invoice_id', 'ci.id'))
            ->when($f['period'] !== null, function ($q) use ($f) {
                $from = Carbon::createFromFormat('Y-m-d', $f['period'].'-01')->startOfMonth();
                $q->where('ci.issued_at', '>=', $from->toDateString())->where('ci.issued_at', '<', $from->copy()->addMonth()->toDateString());
            })
            ->where(fn ($q) => $q
                ->when($f['boutique'] !== null, fn ($w) => $w->where('p.boutique_id', $f['boutique']), fn ($w) => $w->whereNotNull('p.boutique_id'))
                ->orWhereExists($was));
    }

    /**
     * كم بندًا وكم فاتورةً وبكم كُتبت — عددٌ ومبلغٌ كما كُتبا، لا مستحقّ.
     *
     * @return array{lines: int, invoices: int, amount: float}
     */
    public static function summary(int $businessId, array $f): array
    {
        $row = self::lines($businessId, $f)
            ->selectRaw('COUNT(*) as line_count, COUNT(DISTINCT ci.id) as invoice_count, COALESCE(SUM(cii.line_total), 0) as amount')
            ->first();

        return [
            'lines' => (int) $row->line_count,
            'invoices' => (int) $row->invoice_count,
            'amount' => round((float) $row->amount, 3) + 0.0,
        ];
    }

    /** صفحةٌ من البنود — الأحدثُ أوّلًا، بعددٍ ثابتٍ من الاستعلامات */
    public static function rows(int $businessId, array $f): LengthAwarePaginator
    {
        $page = self::lines($businessId, $f)
            ->leftJoin('boutiques as b', 'b.id', '=', 'p.boutique_id')
            ->orderByDesc('ci.issued_at')->orderByDesc('ci.id')->orderBy('cii.sort_order')->orderBy('cii.id')
            ->select([
                'cii.id', 'cii.product_id', 'cii.description', 'cii.quantity', 'cii.unit_price', 'cii.discount', 'cii.tax_amount', 'cii.line_total',
                'ci.id as invoice_id', 'ci.number', 'ci.issued_at', 'ci.issued_by_at', 'ci.form_token', 'b.name as boutique_today',
            ])
            ->paginate(self::PER_PAGE, ['*'], 'page', $f['page']);

        $items = collect($page->items());
        $invoices = CustomerInvoice::where('business_id', $businessId)->whereIn('id', $items->pluck('invoice_id')->unique())
            ->select('customer_invoices.*')
            ->selectRaw(CustomerInvoice::paidSql().' as paid_sum')
            ->selectRaw(CustomerInvoice::creditedSql().' as credited_sum')
            ->get()->keyBy('id');
        $evidence = self::evidence($businessId, $items);

        $page->setCollection($items->map(function ($r) use ($invoices, $evidence) {
            $invoice = $invoices->get($r->invoice_id);
            $paid = round((float) ($invoice?->paid_sum ?? 0), 3);
            $credited = round((float) ($invoice?->credited_sum ?? 0), 3);
            $balance = round(max(0.0, (float) ($invoice?->total ?? 0) - $paid - $credited), 3);

            return [
                'id' => (int) $r->id,
                'invoice_id' => (int) $r->invoice_id,
                'number' => (string) $r->number,
                'issued_at' => Carbon::parse($r->issued_at)->format('Y-m-d'),
                'source' => str_starts_with((string) $r->form_token, WhatsAppOrderCheckout::FORM_TOKEN) ? self::WHATSAPP : self::MANUAL,
                'description' => (string) $r->description,
                'quantity' => round((float) $r->quantity, 3),
                'unit_price' => round((float) $r->unit_price, 3),
                'discount' => round((float) $r->discount, 3),
                'amount' => round((float) $r->line_total, 3),
                'payment' => match (true) {
                    // رُدّت كلُّها بإشعار ولم يُدفع منها شيء — ليست «مدفوعة»
                    $balance <= 0 && $paid <= 0 && $credited > 0 => 'credited',
                    $balance <= 0 => 'paid',
                    default => $paid > 0 ? 'partial' : 'unpaid',
                },
                // إشعارٌ على الفاتورة مبلغٌ بلا بنود: لا يُعرف أردّ هذا البندَ أم غيرَه
                'credited' => $credited + 0.0,
                'boutique_today' => $r->boutique_today,
                'evidence' => $evidence[(int) $r->id] ?? null,
            ];
        }));

        return $page;
    }

    /**
     * القرينةُ لكلّ بند: آخرُ بيعٍ للصنف نفسِه في طلبٍ لا يتأخّر عن إصدار الفاتورة.
     *
     * لقطةٌ كُتبت ساعةَ ذلك البيع — لا نسبةُ اليوم. وما لا بيعَ قبله `null`: لا
     * تُؤخذ لقطةٌ بعده، فقد يكون الصنفُ نُقل بينهما. واستعلامٌ واحدٌ للصفحة (اتّحادٌ بعدد
     * بنودها)، لا تُحمَّل بيعاتُ الصنف كلُّها.
     *
     * @return array<int, array{boutique: ?string, rate: ?float, order: string, at: string}>
     */
    private static function evidence(int $businessId, $items): array
    {
        $products = $items->pluck('product_id')->filter()->unique()->values();
        if ($products->isEmpty()) {
            return [];
        }

        $union = null;
        foreach ($items as $r) {
            $until = $r->issued_by_at ?? Carbon::parse($r->issued_at)->endOfDay()->toDateTimeString();
            $one = DB::query()->fromSub(
                DB::table('order_items as oi')->join('orders as o', 'o.id', '=', 'oi.order_id')
                    ->where('o.business_id', $businessId)->where('o.is_held', false)->where('o.status', '!=', Order::CANCELLED)
                    ->where('oi.product_id', $r->product_id)->where('o.ordered_at', '<=', $until)
                    ->orderByDesc('o.ordered_at')->orderByDesc('oi.id')->limit(1)
                    ->selectRaw('? as line_id, oi.boutique_id, oi.boutique_name, oi.boutique_rate, o.number, o.ordered_at', [(int) $r->id]),
                'x',
            );
            $union = $union === null ? $one : $union->unionAll($one);
        }

        return $union->get()->mapWithKeys(fn ($e) => [(int) $e->line_id => [
            'boutique' => $e->boutique_id === null ? null : (string) $e->boutique_name,
            'rate' => $e->boutique_id === null || $e->boutique_rate === null ? null : round((float) $e->boutique_rate, 2),
            'order' => (string) $e->number,
            'at' => Carbon::parse($e->ordered_at)->format('Y-m-d'),
        ]])->all();
    }
}
