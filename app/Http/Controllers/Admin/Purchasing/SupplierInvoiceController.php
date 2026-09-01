<?php

namespace App\Http\Controllers\Admin\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\JournalEntry;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Support\Demo;
use App\Support\Ledger;
use App\Support\Pagination;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * سندات المورّدين — ما لهم علينا، ومتى يُستحقّ.
 *
 * السند هو الباب الذي تدخل منه الذمّة إلى الدفتر: أمر الشراء يُحرّك المخزون
 * ولا يُنشئ التزامًا (قد يُلغى قبل أن يُفوتر)، والسند يُنشئه. ولهذا يُرحَّل
 * السند لا الأمر، وإلا ظهر على المتجر دَينٌ لمجرّد أنه طلب بضاعة.
 *
 * والسداد قيدٌ ثانٍ لا تعديلٌ للأول: الأوّل يقول «عليّ»، والثاني يقول «دفعتُ».
 * ودمجُهما يُخفي متى نشأ الدَّين ومتى انتهى.
 */
class SupplierInvoiceController extends Controller
{
    /**
     * ما يُرتَّب في سندات الموردين.
     *
     * و«المتبقّي» عمودٌ محسوب (الإجمالي ناقص المدفوع) لا عمودَ قاعدة، فيُرتَّب
     * بالفرق نفسه لا باسمٍ لا وجود له.
     */
    private const SORTS = [
        'reference' => 'supplier_ref',
        'issued_at' => 'issued_at',
        'due_at' => 'due_at',
        'total' => 'total',
        'status' => 'status',
    ];

    private function bid(): int { return auth()->user()->business_id ?? Demo::bid(); }

    public function index(Request $request): Response
    {
        $bid = $this->bid();
        Ledger::ensureSystemAccounts($bid);

        $q = SupplierInvoice::where('business_id', $bid)->with(['supplier', 'purchaseOrder']);

        if ($s = trim((string) $request->query('q'))) {
            $q->where(fn ($w) => $w->where('supplier_ref', 'like', "%{$s}%")
                ->orWhere('notes', 'like', "%{$s}%")
                ->orWhereHas('supplier', fn ($sp) => $sp->where('name', 'like', "%{$s}%")));
        }
        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }
        if ($supplier = $request->query('supplier')) {
            $q->where('supplier_id', $supplier);
        }

        \App\Support\Sort::apply($q, $request, self::SORTS, fn ($w) => $w->orderByDesc('issued_at')->orderByDesc('id'));

        $invoices = $q->paginate((int) $request->query('per_page', 20))->withQueryString();

        $all = SupplierInvoice::where('business_id', $bid)->get();

        return Inertia::render('Admin/Purchases/Invoices', [
            'invoices' => collect($invoices->items())->map(fn ($i) => [
                'id' => $i->id,
                'supplier' => $i->supplier?->name ?? '—',
                'supplier_id' => $i->supplier_id,
                'reference' => $i->supplier_ref,
                'order' => $i->purchaseOrder?->number,
                'issued_at' => optional($i->issued_at)->format('Y-m-d'),
                'due_at' => optional($i->due_at)->format('Y-m-d'),
                'subtotal' => (float) $i->subtotal,
                'tax' => (float) $i->tax,
                'total' => (float) $i->total,
                'paid' => (float) $i->paid,
                'outstanding' => $i->outstanding(),
                'status' => $i->status,
                'overdue' => $i->isOverdue(),
                'notes' => $i->notes,
            ])->all(),
            'pagination' => Pagination::meta($invoices),
            'filters' => $request->only('q', 'status', 'supplier')
                + \App\Support\Sort::params($request, self::SORTS),
            'sorts' => \App\Support\Sort::keys(self::SORTS),
            'suppliers' => Supplier::where('business_id', $bid)->orderBy('name')
                // بلغة الواجهة — القائمة تُقرأ، و`name` يبقى ما يُبحث به
                ->get(['id', 'name', 'name_en'])
                ->map(fn ($s) => ['value' => $s->id, 'label' => \App\Support\Demo::ln($s->name, $s->name_en)])->all(),
            // أوامرُ لم تُفوتَر بعد — ربط السند بأمره يمنع عدّ الشراء مرّتين
            'orders' => PurchaseOrder::where('business_id', $bid)
                ->whereDoesntHave('invoices')->orderByDesc('id')->limit(100)
                ->get(['id', 'number', 'supplier_id', 'total'])
                ->map(fn ($o) => [
                    'value' => $o->id,
                    'label' => $o->number.' — '.number_format((float) $o->total, 3),
                    'supplier_id' => $o->supplier_id,
                    'total' => (float) $o->total,
                ])->all(),
            'summary' => [
                'count' => $all->count(),
                'outstanding' => round($all->sum(fn ($i) => $i->outstanding()), 3),
                'overdue' => $all->filter(fn ($i) => $i->isOverdue())->count(),
                'overdue_value' => round($all->filter(fn ($i) => $i->isOverdue())->sum(fn ($i) => $i->outstanding()), 3),
            ],
            'today' => now()->format('Y-m-d'),
        ]);
    }

    public function store(Request $request)
    {
        $bid = $this->bid();

        $data = $request->validate([
            'supplier_id' => ['required', Rule::exists('suppliers', 'id')->where('business_id', $bid)],
            /*
             * وأمرُ الشراء يكون لهذا المورّد، ولا يكون مفوتَرًا من قبل.
             *
             * القائمة في الشاشة تعرض غير المفوتَر وحده — والتحقّق كان يقبل أيّ
             * رقم. فسندان على أمرٍ واحد يعدّان الشراء مرّتين: دَينٌ مضاعف على
             * المتجر في حساب الموردين، وتكلفةٌ مضاعفة في المخزون بالدفتر.
             * وأمرُ مورّدٍ آخر يُعلَّق على سند هذا فيختلط الحسابان.
             */
            'purchase_order_id' => [
                'nullable',
                Rule::exists('purchase_orders', 'id')->where('business_id', $bid)
                    ->where(fn ($q) => $q->where('supplier_id', $request->input('supplier_id'))),
                Rule::unique('supplier_invoices', 'purchase_order_id')->where('business_id', $bid),
            ],
            'supplier_ref' => ['required', 'string', 'max:60'],
            'issued_at' => ['required', 'date'],
            'due_at' => ['nullable', 'date', 'after_or_equal:issued_at'],
            'subtotal' => ['required', 'numeric', 'min:0'],
            'tax' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [
            'purchase_order_id.exists' => __('أمر الشراء ليس لهذا المورّد'),
            'purchase_order_id.unique' => __('هذا الأمر مفوتَرٌ بسندٍ سابق'),
        ]);

        /*
         * السند الواحد لا يُدخَل مرّتين.
         *
         * القيد الفريد في القاعدة يمنعه، لكنّه يمنعه بخطأ ٥٠٠ لا برسالة —
         * وهو أكثر أخطاء الإدخال شيوعًا: يُدخِله المحاسب ثم يُدخِله من بعده
         * ظنًّا أنه لم يُسجَّل، فيتضاعف الدَّين على المتجر.
         */
        $exists = SupplierInvoice::where('business_id', $bid)
            ->where('supplier_id', $data['supplier_id'])
            ->where('supplier_ref', $data['supplier_ref'])->exists();

        if ($exists) {
            return back()->withInput()->withErrors([
                'supplier_ref' => __('سندٌ بهذا الرقم مسجَّل لهذا المورّد أصلًا'),
            ]);
        }

        $subtotal = round((float) $data['subtotal'], 3);
        $tax = round((float) ($data['tax'] ?? 0), 3);
        $total = round($subtotal + $tax, 3);

        if ($total <= 0) {
            return back()->withInput()->withErrors(['subtotal' => __('السند بلا مبلغ لا يُسجَّل')]);
        }

        try {
            DB::transaction(function () use ($bid, $data, $subtotal, $tax, $total) {
                $invoice = SupplierInvoice::create([
                    'business_id' => $bid,
                    'supplier_id' => $data['supplier_id'],
                    'purchase_order_id' => $data['purchase_order_id'] ?? null,
                    'supplier_ref' => $data['supplier_ref'],
                    'issued_at' => $data['issued_at'],
                    'due_at' => $data['due_at'] ?? null,
                    'subtotal' => $subtotal,
                    'tax' => $tax,
                    'total' => $total,
                    'notes' => $data['notes'] ?? null,
                ]);

                /*
                 * الضريبة تدخل في التكلفة لا في حسابٍ مستقلّ.
                 *
                 * فصلُها يصحّ لمن كان مسجَّلًا في الضريبة فيستردّها؛ ومن لم
                 * يكن فالضريبة عنده جزءٌ من ثمن البضاعة. وفصلُها عن غير
                 * المسجَّل يُنشئ أصلًا لا يُستردّ أبدًا ويُنقص تكلفة المخزون.
                 */
                Ledger::post(
                    $bid,
                    __('سند مورّد: ').$data['supplier_ref'],
                    [
                        ['account' => 'inventory', 'debit' => $total, 'memo' => $invoice->supplier?->name],
                        ['account' => 'payable', 'credit' => $total],
                    ],
                    Carbon::parse($data['issued_at']),
                    'سند مورّد',
                    null,
                    auth()->id(),
                    $invoice,
                );

                $invoice->syncStatus();
            });
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['subtotal' => $e->getMessage()]);
        }

        \App\Support\Activity::log('created', 'سجّل سند مورّد '.$data['supplier_ref'].' بقيمة '.$total);

        return back()->with('toast', ['msg' => __('سُجّل السند'), 'type' => 'success']);
    }

    /** تسجيل دفعة على سند — كاملةً أو جزءًا */
    public function pay(Request $request, $id)
    {
        $bid = $this->bid();
        $invoice = SupplierInvoice::where('business_id', $bid)->findOrFail($id);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.001'],
            'paid_at' => ['required', 'date'],
            'from' => ['required', Rule::in(['cash', 'bank'])],
        ]);

        $amount = round((float) $data['amount'], 3);

        /*
         * لا يُدفع أكثر ممّا عليه.
         *
         * الزيادة تجعل «المدفوع» يتجاوز «الإجمالي» فيصير المستحقّ سالبًا،
         * ويُقرأ في مجموع الذمم كأنّ المورّد يدين لنا. وهو خطأ إدخالٍ لا
         * حالةٌ واقعية: من دفع زيادةً فله سندٌ آخر أو إشعارٌ دائن.
         */
        if ($amount - $invoice->outstanding() > 0.0005) {
            return back()->withErrors([
                'amount' => __('المستحقّ على هذا السند :v فقط', ['v' => number_format($invoice->outstanding(), 3)]),
            ]);
        }

        try {
            DB::transaction(function () use ($bid, $invoice, $amount, $data) {
                /*
                 * والمستحقّ يُقرأ ثانيةً تحت قفل.
                 *
                 * الفحص أعلاه يقع على نسخةٍ قُرئت قبل المعاملة. فضغطتان على
                 * «سداد» بالمبلغ نفسه — وهي أشيع ما يقع حين يبطؤ الردّ —
                 * تمرّان كلتاهما: يخرج المال مرّتين من الصندوق، ويصير المدفوع
                 * أكبر من الإجمالي، فيُقرأ المستحقّ سالبًا في مجموع الذمم
                 * كأنّ المورّد يدين لنا.
                 */
                $locked = SupplierInvoice::where('business_id', $bid)
                    ->lockForUpdate()->findOrFail($invoice->id);

                if ($amount - $locked->outstanding() > 0.0005) {
                    throw new RuntimeException(__('المستحقّ على هذا السند :v فقط', [
                        'v' => number_format($locked->outstanding(), 3),
                    ]));
                }

                Ledger::post(
                    $bid,
                    __('سداد سند مورّد: ').$invoice->supplier_ref,
                    [
                        ['account' => 'payable', 'debit' => $amount, 'memo' => $invoice->supplier?->name],
                        ['account' => $data['from'], 'credit' => $amount],
                    ],
                    Carbon::parse($data['paid_at']),
                    'سداد مورّد',
                    null,
                    auth()->id(),
                    $invoice,
                );

                $locked->update(['paid' => round((float) $locked->paid + $amount, 3)]);
                $locked->syncStatus();
                $invoice->setAttribute('paid', $locked->paid);
            });
        } catch (RuntimeException $e) {
            return back()->withErrors(['amount' => $e->getMessage()]);
        }

        \App\Support\Activity::log('updated', 'سدّد '.$amount.' على السند '.$invoice->supplier_ref, ['subject_id' => $invoice->id]);

        return back()->with('toast', ['msg' => __('سُجّل السداد'), 'type' => 'success']);
    }

    public function destroy($id)
    {
        $bid = $this->bid();
        $invoice = SupplierInvoice::where('business_id', $bid)->findOrFail($id);

        /*
         * سندٌ سُدّد منه شيء لا يُحذف.
         *
         * حذفُه يترك قيد السداد يتيمًا: مالٌ خرج من الصندوق مقابل ذمّةٍ لا
         * وجود لها، فلا يتوازن حساب الموردين ولا يُعرف لمن دُفع.
         */
        if ((float) $invoice->paid > 0) {
            return back()->with('toast', [
                'msg' => __('سُدّد من هذا السند — لا يُحذف بعد أن خرج مقابله مال'),
                'type' => 'warning',
            ]);
        }

        DB::transaction(function () use ($invoice) {
            // القيد يتبع سنده: قيدٌ يتيم يُبقي الدَّين في الدفتر بلا مستند
            JournalEntry::where('sourceable_type', SupplierInvoice::class)
                ->where('sourceable_id', $invoice->id)->delete();
            $invoice->delete();
        });

        \App\Support\Activity::log('deleted', 'حذف السند: '.$invoice->supplier_ref, ['subject_id' => $invoice->id]);

        return back()->with('toast', ['msg' => __('حُذف السند'), 'type' => 'warning']);
    }
}
