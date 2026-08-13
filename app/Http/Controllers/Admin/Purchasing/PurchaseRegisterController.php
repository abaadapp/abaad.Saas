<?php

namespace App\Http\Controllers\Admin\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Support\Demo;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * قائمة المشتريات — ما اشتراه المتجر فعلًا، من البابين معًا.
 *
 * الشراء يدخل من طريقين: أمرُ شراءٍ يُستلم، وسندُ مورّدٍ يُسجَّل بلا أمر
 * (شراءٌ عاجل من السوق). وقائمةٌ تعرض أحدهما تُخفي نصف ما اشتُري.
 *
 * والعدّ مرّتين هو الخطر المقابل: أمرٌ استُلم ثمّ فُوتر هو شراءٌ واحد بورقتين.
 * فيُعرض الأمرُ ويُستثنى سندُه — لا العكس، لأن الأمر يحمل ما دخل المخزن
 * والسند يحمل ما استُحقّ فقط.
 */
class PurchaseRegisterController extends Controller
{
    private function bid(): int { return auth()->user()->business_id ?? Demo::bid(); }

    public function index(Request $request): Response
    {
        $bid = $this->bid();

        $month = (string) $request->query('month', now()->format('Y-m'));
        $span = null;

        if (preg_match('/^\d{4}-\d{2}$/', $month)) {
            $first = Carbon::createFromFormat('Y-m-d', $month.'-01');
            $span = [$first->copy()->startOfMonth(), $first->copy()->endOfMonth()];
        } else {
            $month = '';
        }

        $supplierId = $request->query('supplier');

        $orders = PurchaseOrder::where('business_id', $bid)->with('supplier')
            ->when($span, fn ($q) => $q->whereBetween('ordered_at', $span))
            ->when($supplierId, fn ($q) => $q->where('supplier_id', $supplierId))
            ->orderByDesc('ordered_at')->get()
            ->map(fn ($o) => [
                'key' => 'po-'.$o->id,
                'date' => optional($o->ordered_at)->format('Y-m-d'),
                'reference' => $o->number,
                'supplier' => $o->supplier?->name ?? $o->supplier_name ?? '—',
                'source' => 'أمر شراء',
                'total' => (float) $o->total,
                'status' => $o->status,
                'items' => $o->items()->count(),
            ]);

        // السند المربوط بأمرٍ هو الشراء نفسه بورقةٍ ثانية — فلا يُعدّ معه
        $invoices = SupplierInvoice::where('business_id', $bid)->with('supplier')
            ->whereNull('purchase_order_id')
            ->when($span, fn ($q) => $q->whereBetween('issued_at', $span))
            ->when($supplierId, fn ($q) => $q->where('supplier_id', $supplierId))
            ->orderByDesc('issued_at')->get()
            ->map(fn ($i) => [
                'key' => 'si-'.$i->id,
                'date' => optional($i->issued_at)->format('Y-m-d'),
                'reference' => $i->supplier_ref,
                'supplier' => $i->supplier?->name ?? '—',
                'source' => 'سند مورّد',
                'total' => (float) $i->total,
                'status' => $i->status,
                'items' => 0,
            ]);

        $rows = $orders->concat($invoices)->sortByDesc('date')->values();

        return Inertia::render('Admin/Purchases/Register', [
            'rows' => $rows->all(),
            'summary' => [
                'count' => $rows->count(),
                'total' => round($rows->sum('total'), 3),
                'orders' => $orders->count(),
                'invoices' => $invoices->count(),
                // ما على المتجر لموردّيه كلّهم — لا يخصّ الشهر المعروض
                'outstanding' => round(
                    (float) SupplierInvoice::where('business_id', $bid)->sum('total')
                    - (float) SupplierInvoice::where('business_id', $bid)->sum('paid'),
                    3
                ),
            ],
            'month' => $month,
            'months' => $this->months($bid),
            'suppliers' => Supplier::where('business_id', $bid)->orderBy('name')
                ->get(['id', 'name'])->map(fn ($s) => ['value' => $s->id, 'label' => $s->name])->all(),
            'filters' => ['supplier' => $supplierId],
        ]);
    }

    /**
     * الشهور التي فيها شراءٌ فعلًا — والجاري معها دائمًا.
     *
     * قائمةٌ من التقويم تعرض شهورًا فارغة، وقائمةٌ من البيانات وحدها تُسقط
     * الشهر الجاري قبل أوّل شراءٍ فيه فلا يجد التاجر شهره.
     */
    private function months(int $bid): array
    {
        $fromOrders = PurchaseOrder::where('business_id', $bid)->whereNotNull('ordered_at')
            ->get(['ordered_at'])->map(fn ($o) => $o->ordered_at->format('Y-m'))->all();

        $fromInvoices = SupplierInvoice::where('business_id', $bid)->whereNotNull('issued_at')
            ->get(['issued_at'])->map(fn ($i) => $i->issued_at->format('Y-m'))->all();

        return collect($fromOrders)->concat($fromInvoices)
            ->push(now()->format('Y-m'))
            ->unique()->sortDesc()->values()->all();
    }
}
