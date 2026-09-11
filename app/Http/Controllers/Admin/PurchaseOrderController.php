<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\GoodsReceiptNote;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Support\Activity;
use App\Support\Demo;
use App\Support\DocumentPaper;
use App\Support\DocumentRenderer;
use App\Support\GoodsReceipts;
use App\Support\InvoiceBranding;
use App\Support\Money;
use App\Support\Permissions;
use App\Support\PurchaseOrders;
use App\Support\PurchaseOrderTotals;
use App\Support\PurchaseUnits;
use App\Support\ReceiveRefused;
use App\Support\Search;
use App\Support\SupplierInvoices;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class PurchaseOrderController extends Controller
{
    private function bid(): int
    {
        return auth()->user()->business_id ?? Demo::bid();
    }

    /** أوامر الشراء — ما طُلب، وما استُلم منه */
    public function index(Request $request): Response
    {
        $s = Demo::purchaseOrderStats();
        $mayRead = (bool) auth()->user()?->may(Permissions::ATTACHMENT_VIEW);

        return Inertia::render('Admin/Purchases/Index', [
            'stats' => [
                ['label' => __('إجمالي الأوامر'), 'value' => (string) $s['total'], 'icon' => 'clipboard-list', 'color' => 'primary'],
                ['label' => __('قيد التنفيذ'), 'value' => (string) $s['pending'], 'icon' => 'clock', 'color' => 'warning'],
                ['label' => __('مستلمة'), 'value' => (string) $s['received'], 'icon' => 'package-check', 'color' => 'success'],
                ['label' => __('قيمة قيد الاستلام'), 'value' => Demo::money($s['value']), 'icon' => 'wallet', 'color' => 'info'],
            ],
            /*
             * ورابطُ الإيصال بابٌ يسأل — لا مسارٌ على القرص العامّ.
             *
             * ومن لا يملك فتحَ المرفقات لا يُبنى له رابط.
             */
            'orders' => array_map(function ($o) use ($mayRead) {
                $o['receipt'] = $o['receipt'] && $mayRead
                    ? route('admin.purchases.receiptFile', $o['id'])
                    : null;

                return $o;
            }, Demo::purchaseOrders()),
            'reorder' => Demo::reorderSuggestions(),
            /*
             * بحثٌ يصل مع الرابط.
             *
             * إشعار الاستلام يقود إلى أمره، والقائمة أربعةٌ وستّون أمرًا:
             * رابطٌ يُنزلك في أوّلها ويتركك تبحث ليس رابطًا. فيصل معه رقم
             * الأمر ويُملأ به حقل البحث، فتفتح الصفحة على أمرٍ واحد.
             */
            'q' => Search::term($request) ?: null,
        ]);
    }

    /**
     * صفحةُ الأمر الواحد — ما طُلب، وما وصل منه، وما حُرّر عليه.
     *
     * ═══ ولماذا صفحةٌ لا سطرٌ في جدول ═══
     *
     * القائمة تقول «مستلم جزئيًا» ولا تقول أيُّ صنفٍ بقي ولا كم، ولا مَن
     * وقّع على ما دخل الرفّ، ولا أيَّ سندٍ حُرّر على الأمر. وثلاثةُ
     * مستنداتٍ تشير إلى أمرٍ واحد — أوراقُ الاستلام، وسنداتُ المورّد،
     * وبنودُ الأمر نفسِه — كانت تُقرأ في ثلاث شاشاتٍ لا يجمعها رابط، فمن
     * أراد أن يعرف حالَ أمرٍ واحدٍ بحث عن رقمه ثلاث مرّات.
     *
     * ═══ والخطُّ الزمنيُّ يُبنى من الصفوف لا يُخزَّن ═══
     *
     * لا عمودَ جديد ولا جدولَ أحداث: كلُّ حدثٍ هنا ختمُ وقتٍ مكتوبٌ أصلًا
     * (`created_at`، `approved_at`، `rejected_at`). وسجلٌّ ثانٍ للأحداث
     * يفترق يومًا عمّا تقوله المستندات — والمستندُ هو الحقّ لا صداه.
     *
     * ═══ وهي قراءةٌ محضة ═══
     *
     * لا تكتب صفًّا ولا تحرّك رفًّا ولا تمسّ قيدًا. والاستلامُ والحذفُ من
     * هنا يمرّان على البابين القائمين أنفسِهما بحرّاسهما — لا نسخةَ ثانية.
     */
    public function show(int|string $id): Response
    {
        $bid = $this->bid();
        $user = auth()->user();
        $mayAttachment = (bool) $user?->may(Permissions::ATTACHMENT_VIEW);

        $po = PurchaseOrder::where('business_id', $bid)
            ->with(['items' => fn ($q) => $q->orderBy('id')])
            ->findOrFail($id);

        $branch = $po->branch_id ? Branch::where('business_id', $bid)->find($po->branch_id) : null;

        $notes = GoodsReceiptNote::where('business_id', $bid)
            ->where('purchase_order_id', $po->id)
            ->with(['items' => fn ($q) => $q->orderBy('id')])
            ->orderBy('id')->get();

        $invoices = SupplierInvoice::where('business_id', $bid)
            ->where('purchase_order_id', $po->id)
            ->orderBy('id')->get();

        /*
         * وأسماءُ من وقّعوا تُقرأ دفعةً واحدة.
         *
         * قراءةُ اسمٍ عند كلّ حدثٍ تفتح استعلامًا لكلّ سطرٍ في الخطّ الزمنيّ
         * — وأمرٌ عليه عشرُ أوراقٍ يصير ثلاثين استعلامًا لثلاثة أسماء.
         */
        $actors = User::whereIn('id', $notes->pluck('submitted_by')
            ->merge($notes->pluck('approved_by'))->merge($notes->pluck('rejected_by'))
            ->merge($invoices->pluck('approved_by'))->merge($invoices->pluck('rejected_by'))
            ->filter()->unique()->values()->all())->pluck('name', 'id');

        // ما سُجّل ولم يُعتمد بعد — بندًا بندًا، فالمتبقّي وحده لا يقول أين ذهب
        $pending = GoodsReceipts::pendingQuantities($po->id);

        return Inertia::render('Admin/Purchases/Show', [
            'order' => [
                'id' => $po->id,
                'number' => $po->number,
                'status' => $po->status,
                'supplier' => $po->supplier_name ?? optional($po->supplier)->name ?? '—',
                'supplier_reference' => $po->supplier_reference,
                'branch' => $branch?->name,
                'notes' => $po->notes,
                'ordered_at' => optional($po->ordered_at)->format('Y-m-d'),
                'expected_delivery_at' => optional($po->expected_delivery_at)->format('Y-m-d'),
                'received_at' => optional($po->received_at)->format('Y-m-d'),
                'items_subtotal' => (float) $po->items_subtotal,
                'supplier_discount' => (float) $po->supplier_discount,
                'shipping_cost' => (float) $po->shipping_cost,
                'tax' => (float) $po->tax,
                'tax_rate' => (float) $po->tax_rate,
                'total' => (float) $po->total,
                /*
                 * ووجودُ المرفق سؤالٌ غيرُ «هل تقرؤه؟».
                 *
                 * من لا يملك فتحَ المرفقات لا يُبنى له رابط — وتقول له الشاشة
                 * إنّ ثمّة مرفقًا لا يُفتح، لا إنّه لا مرفق. انظر ما جرى في
                 * إيصال أمر الشراء حين قرأت الشاشةُ الفراغَ «لا إيصال».
                 */
                'has_attachment' => $po->attachment !== null,
                'attachment' => $po->attachment && $mayAttachment
                    ? route('admin.purchases.attachment', $po->id) : null,
                'attachment_name' => $po->attachment_name,
                'has_receipt' => $po->receipt !== null,
                'receipt' => $po->receipt && $mayAttachment
                    ? route('admin.purchases.receiptFile', $po->id) : null,
                'receipt_name' => $po->receipt_name,
                'items' => $po->items->map(fn ($i) => [
                    'id' => $i->id,
                    'name' => $i->name,
                    // الكميّاتُ كلُّها بوحدة الشراء — والتحويل عند الرفّ وحده
                    'purchase_unit' => $i->purchase_unit,
                    'units_per_purchase_unit' => (float) $i->units_per_purchase_unit,
                    'quantity' => (int) $i->quantity,
                    'received' => (int) $i->received_quantity,
                    'pending' => (float) ($pending[$i->id] ?? 0),
                    'remaining' => $i->remaining,
                    'base_quantity' => $i->base_quantity,
                    'cost' => (float) $i->cost,
                    'line_total' => (float) $i->line_total,
                ])->all(),
            ],
            'receipts' => $notes->map(fn ($n) => [
                'id' => $n->id,
                'number' => $n->number,
                'status' => $n->status,
                'received_at' => optional($n->received_at)->format('Y-m-d'),
                'receiver' => $n->receiver,
                'quantity' => (float) $n->items->sum('quantity'),
                'lines' => $n->items->count(),
                'rejection_reason' => $n->rejection_reason,
                'pdf' => route('admin.inventory.receipts.pdf', $n->id),
            ])->all(),
            'invoices' => $invoices->map(fn ($v) => [
                'id' => $v->id,
                'reference' => $v->supplier_ref,
                'approval_status' => $v->approval_status,
                'status' => $v->status,
                'issued_at' => optional($v->issued_at)->format('Y-m-d'),
                'due_at' => optional($v->due_at)->format('Y-m-d'),
                'total' => (float) $v->total,
                'paid' => (float) $v->paid,
                'outstanding' => $v->outstanding(),
                'match_status' => $v->match_status,
            ])->all(),
            'timeline' => $this->timeline($po, $notes, $invoices, $actors),
            /*
             * والمقابضُ تُرسم على ما يقبله الخادم لا على ما نتمنّاه.
             *
             * زرُّ حذفٍ على أمرٍ استُلمت بضاعتُه يفتح حوارَ تأكيدٍ ثمّ يردّه
             * الخادم — والقاعدةُ نفسُها هنا وهناك تُقرأ من الصفوف ذاتها.
             */
            'can' => [
                'receive' => (bool) $user?->may(Permissions::RECEIPT_CREATE)
                    && ! in_array($po->status, [PurchaseOrders::RECEIVED, 'ملغي'], true),
                'delete' => $notes->isEmpty() && $invoices->isEmpty(),
            ],
        ]);
    }

    /**
     * الخطُّ الزمنيّ — أختامُ الوقت المكتوبة أصلًا، مرتَّبةً.
     *
     * وحدثٌ بلا ختمِ وقتٍ يُطرح ولا يُخمَّن له تاريخ: «قُدّم» ليست
     * «أُنشئ»، وورقةٌ قديمةٌ نُقلت بهجرةٍ قد لا تحمل ختمَها.
     *
     * @param  Collection<int, GoodsReceiptNote>  $notes
     * @param  Collection<int, SupplierInvoice>  $invoices
     * @param  Collection<int, string>  $actors
     * @return list<array<string, mixed>>
     */
    private function timeline(PurchaseOrder $po, $notes, $invoices, $actors): array
    {
        $events = [];

        $events[] = [
            'at' => $po->created_at,
            'kind' => $po->status === PurchaseOrders::DRAFT ? 'draft' : 'created',
            'title' => $po->status === PurchaseOrders::DRAFT
                ? __('حُفظ أمر الشراء مسودّة')
                : __('أُنشئ أمر الشراء'),
            'detail' => $po->supplier_name,
            'actor' => null,
        ];

        foreach ($notes as $note) {
            $events[] = [
                'at' => $note->created_at,
                'kind' => 'receipt',
                'title' => __('سُجّل استلام :n — بانتظار الاعتماد', ['n' => $note->number]),
                'detail' => $note->receiver,
                'actor' => $actors[$note->submitted_by] ?? null,
            ];

            if ($note->approved_at) {
                $events[] = [
                    'at' => $note->approved_at,
                    'kind' => 'approved',
                    'title' => __('اعتُمد الاستلام :n — دخلت البضاعة الرفّ', ['n' => $note->number]),
                    'detail' => null,
                    'actor' => $actors[$note->approved_by] ?? null,
                ];
            }

            if ($note->rejected_at) {
                $events[] = [
                    'at' => $note->rejected_at,
                    'kind' => 'rejected',
                    'title' => __('رُفض الاستلام :n', ['n' => $note->number]),
                    'detail' => $note->rejection_reason,
                    'actor' => $actors[$note->rejected_by] ?? null,
                ];
            }
        }

        foreach ($invoices as $invoice) {
            $events[] = [
                'at' => $invoice->created_at,
                'kind' => 'invoice',
                'title' => __('حُرّر سند المورّد :r', ['r' => $invoice->supplier_ref]),
                'detail' => null,
                'actor' => null,
            ];

            if ($invoice->approved_at) {
                $events[] = [
                    'at' => $invoice->approved_at,
                    'kind' => 'approved',
                    'title' => __('اعتُمد سند المورّد :r — قُيّدت الذمّة', ['r' => $invoice->supplier_ref]),
                    'detail' => null,
                    'actor' => $actors[$invoice->approved_by] ?? null,
                ];
            }

            /*
             * والإلغاءُ يُكتب في عمود الرفض نفسِه — فالحالةُ هي الفارقة.
             *
             * ولولا قراءتُها لَقال الخطُّ «رُفض» عن سندٍ اعتُمد ثمّ عُكس قيدُه،
             * وهما واقعتان مختلفتان في الدفتر.
             */
            if ($invoice->rejected_at) {
                $cancelled = $invoice->approval_status === SupplierInvoices::CANCELLED;

                $events[] = [
                    'at' => $invoice->rejected_at,
                    'kind' => $cancelled ? 'cancelled' : 'rejected',
                    'title' => $cancelled
                        ? __('أُلغي سند المورّد :r — عُكس قيدُه', ['r' => $invoice->supplier_ref])
                        : __('رُفض سند المورّد :r', ['r' => $invoice->supplier_ref]),
                    'detail' => $invoice->rejection_reason,
                    'actor' => $actors[$invoice->rejected_by] ?? null,
                ];
            }
        }

        return collect($events)
            ->filter(fn ($e) => $e['at'] !== null)
            ->sortBy(fn ($e) => $e['at']->getTimestamp())
            ->map(fn ($e) => [
                'at' => $e['at']->format('Y-m-d H:i'),
                'kind' => $e['kind'],
                'title' => $e['title'],
                'detail' => $e['detail'] ?: null,
                'actor' => $e['actor'],
            ])
            ->values()->all();
    }

    /**
     * إنشاءُ أمر شراء — مسودّةً أو مُرسَلًا.
     *
     * ═══ ولا أثرَ لهذا على شيء ═══
     *
     * أمرُ الشراء نيّةُ شراءٍ لا حدثٌ ماليّ ولا حركةُ مخزون: لا يزيد رصيدًا،
     * ولا يُنشئ ذمّةً على المتجر، ولا يكتب قيدًا. البضاعةُ تدخل الرفَّ
     * باعتماد الاستلام (`GoodsReceipts::approve`)، والذمّةُ تنشأ باعتماد سند
     * المورّد (`SupplierInvoices::approve`) — لا قبلهما.
     *
     * ═══ والإجماليُّ يُحسب هنا لا يُستقبَل ═══
     *
     * ما ترسله الشاشة من إجماليّاتٍ يُهمل جملةً: من يفتح أدوات المتصفّح
     * يستطيع إرسال إجماليٍّ صفرٍ لأمرٍ بألف. والصيغةُ في `PurchaseOrderTotals`
     * موضعًا واحدًا تقرؤه الشاشةُ والخادم.
     */
    /**
     * الورقةُ كما ستُطبع بما على الشاشة الآن — قبل أن تُحفظ.
     *
     * ═══ ولماذا لا تُرسم في الشاشة ═══
     *
     * القاعدةُ مكتوبةٌ في `DocumentRenderer`: «المعاينةُ تُرسم بالقالب الذي
     * يُطبع لا بنسخةٍ ثانية منه في الشاشة». وصندوقٌ يشبه أمرَ الشراء مرسومٌ
     * في JSX يفترق عنه عند أوّل تعديل — يُضاف سطرٌ إلى الورقة ولا يظهر في
     * الصورة، فيعتمد التاجر شكلًا لا يخرج من الطابعة ويرسل إلى مورّده ورقةً
     * غيرَ التي رآها.
     *
     * فهنا `pdf.document` نفسُه بقالب `purchase` من «قوالب الأوراق» — وهو
     * القالبُ الذي يطبع به `DocumentPrintController::purchase`.
     *
     * ═══ ولا شيءَ يُكتب ═══
     *
     * لا صفَّ أمر، ولا رقمَ يُقطع من التسلسل، ولا سطرَ في سجلّ النشاط، ولا
     * قيد — وأمرُ الشراء لا يكتب قيدًا أصلًا. من فتح الشاشة وكتب بندًا ثمّ
     * تركها لا يترك خلفه شيئًا.
     *
     * ═══ ولا حقلَ مطلوبًا ═══
     *
     * `store` تشترط مورّدًا وفرعًا وبندًا، وهذه لا تشترط: المعاينةُ ترافق
     * الكتابة من أوّل حرف. ومعاينةٌ لا تظهر حتى يكتمل النموذج لا يراها أحدٌ
     * إلّا بعد أن يفرغ من حاجته إليها.
     */
    public function preview(Request $request)
    {
        $bid = $this->bid();

        $data = $request->validate([
            'supplier_id' => ['nullable', 'integer'],
            'ordered_at' => ['nullable', 'date'],
            'expected_delivery_at' => ['nullable', 'date'],
            'supplier_reference' => ['nullable', 'string', 'max:100'],
            'supplier_discount' => ['nullable', 'numeric'],
            'shipping_cost' => ['nullable', 'numeric'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'payment_method' => ['nullable', 'string', Rule::in(PurchaseOrders::METHODS)],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['nullable', 'array'],
            'items.*.product_id' => ['nullable', 'integer'],
            'items.*.name' => ['nullable', 'string', 'max:255'],
            'items.*.purchase_unit' => ['nullable', 'string', 'max:40'],
            'items.*.units_per_purchase_unit' => ['nullable', 'numeric'],
            'items.*.cost' => ['nullable', 'numeric'],
            'items.*.quantity' => ['nullable', 'numeric'],
            /*
             * ولغةُ الورقة تُرسَل لتُعاين — ولا تُحفظ من هنا.
             *
             * من قلّبها لينظر كيف يقرؤها مورّدُه الأجنبيّ ثمّ عاد لا يجب أن
             * يجد أوراقَه القادمة إنجليزيّة. والحفظُ من «قوالب الأوراق».
             */
            'lang' => ['nullable', 'string', Rule::in(InvoiceBranding::LANGUAGES)],
        ]);

        /*
         * والمورّدُ من متجر الطالب أو لا مورّد.
         *
         * `first()` لا `firstOrFail()`: رقمٌ من متجرٍ آخر يُهمَل فتُرسم ورقةٌ
         * بلا اسم — ولا يُردّ الطلبُ بـ٤٠٤ في شاشةٍ تكتب. ولا يُقرأ صفُّ
         * مورّدٍ ليس من المتجر بحال.
         */
        $supplier = ! empty($data['supplier_id'])
            ? Supplier::where('business_id', $bid)->whereKey($data['supplier_id'])->first()
            : null;

        $lines = $this->lines($bid, array_map(
            fn (array $r) => $r + ['name' => '', 'cost' => 0, 'quantity' => 0],
            $data['items'] ?? [],
        ));

        $totals = PurchaseOrderTotals::compute(
            $lines,
            (float) ($data['supplier_discount'] ?? 0),
            (float) ($data['shipping_cost'] ?? 0),
            ($data['tax_rate'] ?? null) !== null && $data['tax_rate'] !== ''
                ? (float) $data['tax_rate']
                : PurchaseOrderTotals::taxRateFor($bid),
        );

        /*
         * وأمرٌ **غير محفوظ** يُرسَم به.
         *
         * `setRelation` تجعل `$po->items` تردّ ما بُني هنا بدل أن تسأل
         * القاعدةَ عن صفوفٍ لا وجود لها — والقالبُ يمرّ عليها كما يمرّ على
         * بنود أمرٍ محفوظ، فهو قالبٌ واحد لا اثنان.
         */
        $po = new PurchaseOrder([
            'business_id' => $bid,
            'number' => null,
            'supplier_id' => $supplier?->id,
            'supplier_name' => $supplier?->name,
            'supplier_reference' => $data['supplier_reference'] ?? null,
            'ordered_at' => $data['ordered_at'] ?? now()->toDateString(),
            'expected_delivery_at' => $data['expected_delivery_at'] ?? null,
            'payment_method' => $data['payment_method'] ?? null,
            'payment_terms_days' => $data['payment_terms_days'] ?? null,
            'items_subtotal' => $totals['items_subtotal'],
            'supplier_discount' => $totals['supplier_discount'],
            'shipping_cost' => $totals['shipping_cost'],
            'tax' => $totals['tax'],
            'tax_rate' => $totals['tax_rate'],
            'total' => $totals['total'],
            'notes' => $data['notes'] ?? null,
        ]);

        $po->setRelation('items', collect($lines)->map(fn (array $l) => new PurchaseOrderItem($l)));

        if ($supplier !== null) {
            $po->setRelation('supplier', $supplier);
        }

        return response()->json([
            'html' => InvoiceBranding::render(
                $bid,
                $data['lang'] ?? null,
                fn () => DocumentRenderer::generic($bid, 'purchase', DocumentPaper::forPurchase($po)),
            ),
        ]);
    }

    /**
     * المورّدُ الذي تُفتح عليه الشاشة — يُضبط أو يُرفع.
     *
     * ═══ ولمَ مقبضُه هنا ═══
     *
     * أكثرُ المحلّات تشتري من مورّدٍ واحد أكثرَ ممّا تشتري من غيره: مزرعةٌ
     * تُورّد الورد أسبوعيًّا. واختيارُه في كلّ مرّةٍ من قائمةٍ عملٌ يُعاد بلا
     * سبب. والمقبضُ حيث يُرى أثرُه لا في شاشةٍ يُبحث عنها.
     *
     * ولا يُحفظ رقمٌ لا صفَّ له: معرّفٌ من متجرٍ آخر يُردّ برسالةٍ على حقله،
     * لا يُكتب صامتًا ثمّ يُهمَل عند القراءة فيقول التنبيهُ «حُفظ» ولا يتغيّر
     * شيءٌ في الشاشة القادمة.
     *
     * ═══ وهو إعدادُ متجرٍ لا فعلَ أمرِ شراء ═══
     *
     * يُغيّر ما تُفتح عليه الشاشةُ لكلّ من يكتب أمرًا — فيُقاس بقسم
     * «الإعدادات» كما يُقاس تبديلُ قوالب الأوراق.
     */
    public function defaultSupplier(Request $request)
    {
        abort_unless((bool) auth()->user()?->allows('settings'), 403);

        $bid = $this->bid();

        $data = $request->validate([
            'supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')->where('business_id', $bid)],
        ], [
            'supplier_id.exists' => __('هذا المورّد ليس من موردي متجرك.'),
        ], ['supplier_id' => __('المورد الافتراضي')]);

        Setting::updateOrCreate(
            ['business_id' => $bid, 'key' => PurchaseOrders::DEFAULT_SUPPLIER],
            ['value' => (string) ($data['supplier_id'] ?? '')],
        );

        Activity::log('settings', blank($data['supplier_id'] ?? null)
            ? 'رفع المورّد الافتراضي لأوامر الشراء'
            : 'ضبط المورّد الافتراضي لأوامر الشراء');

        return back()->with('toast', [
            'msg' => blank($data['supplier_id'] ?? null)
                ? __('رُفع المورد الافتراضي')
                : __('حُفظ المورد الافتراضي'),
            'type' => 'success',
        ]);
    }

    public function store(Request $request)
    {
        $bid = $this->bid();

        /*
         * وتاريخُ الطلب يُملأ باليوم إن لم يُرسل.
         *
         * الشاشةُ ترسله دائمًا، وطلبٌ قديمٌ لا يعرفه — وإلزامُه كان يردّ كلَّ
         * تكاملٍ قائمٍ برسالةٍ عن حقلٍ لم يكن موجودًا أمس. ويُدمج قبل التحقّق
         * ليقرأه `after_or_equal` على تاريخ الوصول.
         */
        $request->merge(['ordered_at' => $request->input('ordered_at') ?: now()->toDateString()]);

        $data = $request->validate([
            'branch_id' => ['required', 'integer'],
            'supplier_id' => ['required', 'integer'],
            'ordered_at' => ['required', 'date'],
            'expected_delivery_at' => ['nullable', 'date', 'after_or_equal:ordered_at'],
            'supplier_reference' => ['nullable', 'string', 'max:100'],
            'supplier_discount' => ['nullable', 'numeric', 'min:0'],
            'shipping_cost' => ['nullable', 'numeric', 'min:0'],
            /*
             * ═══ ونسبةُ الضريبة على الأمر لا على المتجر ═══
             *
             * كانت تُقرأ من إعدادات المتجر جبرًا. وهي في **البيع** سياسةٌ لا
             * تُترك لمن يكتب الورقة — أمّا في **الشراء** فليست سياستَنا أصلًا:
             * هي ما يفرضه المورّد. ومورّدٌ غير مسجَّلٍ ضريبيًّا لا يفرض شيئًا،
             * ومورّدٌ خارج البلد كذلك.
             *
             * وأثرُه لم يكن في الورقة وحدها: `SupplierInvoices::match` تقابل
             * إجماليَّ السند بإجماليّ الأمر. فمتجرٌ ضريبتُه مُطفأة يشتري من
             * مورّدٍ يفرضها ⇒ السندُ أعلى من الأمر ⇒ **يُمنع** بمقدار الضريبة
             * بالضبط. والعكسُ يشتكي في كلّ أمر.
             *
             * وفارغةً تسقط إلى نسبة المتجر: من لا يعرف يترك ما كان.
             */
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
            /* وما لا يُطبع: عمودٌ آخر لا يبلغ ورقةَ المورّد — انظر `DocumentPaper::forPurchase` */
            'internal_notes' => ['nullable', 'string', 'max:1000'],
            /*
             * ═══ وطريقةُ السداد **مطلوبة** — ونيّةٌ لا حدث ═══
             *
             * من يكتب الأمر يعرف كيف اتّفق مع مورّده، ومن يسدّد بعد شهرٍ لا
             * يعرف. وكانت تُكتب في «ملاحظات» أو لا تُكتب، فتُسأل هاتفيًّا.
             *
             * ولا تكتب قيدًا ولا تُنقص صندوقًا ولا تُنشئ ذمّة: الذمّةُ تنشأ
             * باعتماد سند المورّد، والمالُ يخرج بالسداد على السند. وهذا
             * الملفُّ لا يمسّ أيًّا منهما — يحرسه
             * `APurchaseOrderIsAnIntentionNotAnEventTest`.
             */
            'payment_method' => ['required', 'string', Rule::in(PurchaseOrders::METHODS)],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'payment_reference' => ['nullable', 'string', 'max:60'],
            'draft' => ['nullable', 'boolean'],
            /*
             * ومفتاحٌ يرسله المتصفّح مرّةً واحدة لكلّ نموذج.
             *
             * ضغطتان على «إرسال» — أو ردٌّ يبطؤ فيُعاد الطلب — كانتا تكتبان
             * أمرين متطابقين لمورّدٍ واحد في الثانية نفسها. ولا يكشفهما إلّا
             * من يقرأ القائمة.
             */
            'form_token' => ['nullable', 'string', 'max:64'],
            'attachment' => ['nullable', 'file', 'max:10240', 'extensions:jpg,jpeg,png,pdf,webp,heic'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['nullable', 'integer'],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.purchase_unit' => ['nullable', 'string', 'max:40'],
            'items.*.units_per_purchase_unit' => ['nullable', 'numeric', 'min:0.001', 'max:100000'],
            'items.*.cost' => ['required', 'numeric', 'min:0'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ], [
            'supplier_id.required' => __('اختر المورد'),
            'payment_method.required' => __('اختر طريقة الدفع المتّفق عليها مع المورّد'),
            'branch_id.required' => __('اختر الفرع'),
            'items.required' => __('أضف صنفًا واحدًا على الأقل'),
            'items.min' => __('أضف صنفًا واحدًا على الأقل'),
            'items.*.quantity.min' => __('الكمية يجب أن تكون أكبر من صفر'),
            'items.*.cost.numeric' => __('تكلفة الوحدة غير صحيحة'),
            'expected_delivery_at.after_or_equal' => __('تاريخ الوصول لا يمكن أن يكون قبل تاريخ الطلب'),
            'attachment.extensions' => __('الصيغ المدعومة: JPG، PNG، PDF، WEBP، HEIC.'),
            'attachment.max' => __('أقصى حجم 10 ميجابايت.'),
        ], [
            'items.*.quantity' => __('الكمية'),
            'items.*.cost' => __('تكلفة الوحدة'),
            'attachment' => __('مرفق أمر الشراء'),
        ]);

        /*
         * والمعرّفاتُ تُقرأ من قاعدة المتجر لا تُصدَّق كما وصلت: رقمُ فرعٍ أو
         * مورّدٍ يُكتب في الطلب يدويًّا لا يفتح بابًا إلى متجر الجار.
         */
        $branch = Branch::where('business_id', $bid)->find($data['branch_id']);
        $supplier = Supplier::where('business_id', $bid)->find($data['supplier_id']);

        if (! $branch) {
            return back()->withInput()->withErrors(['branch_id' => __('الفرع المحدد غير صالح.')]);
        }

        if (! $supplier) {
            return back()->withInput()->withErrors(['supplier_id' => __('المورّد المحدد غير صالح.')]);
        }

        $items = $this->lines($bid, $data['items']);

        if ($items === []) {
            return back()->withInput()->withErrors(['items' => __('أضف صنفًا واحدًا على الأقل')]);
        }

        $totals = PurchaseOrderTotals::compute(
            $items,
            (float) ($data['supplier_discount'] ?? 0),
            (float) ($data['shipping_cost'] ?? 0),
            // والمكتوبةُ على الورقة تعلو، وفارغةً تسقط إلى نسبة المتجر
            ($data['tax_rate'] ?? null) !== null && $data['tax_rate'] !== ''
                ? (float) $data['tax_rate']
                : PurchaseOrderTotals::taxRateFor($bid),
        );

        // وخصمٌ أكبرُ من البضاعة يُردّ برسالة لا يُحصر صامتًا
        if ((float) ($data['supplier_discount'] ?? 0) - $totals['items_subtotal'] > 0.0005) {
            return back()->withInput()->withErrors([
                'supplier_discount' => __('خصم المورد أكبر من قيمة الأصناف'),
            ]);
        }

        $stored = null;

        if ($request->hasFile('attachment')) {
            $file = $request->file('attachment');
            // القرصُ الخاصّ: عرضُ سعرٍ فيه أسعارُ شرائك يُقرأ ببابٍ يسأل
            $stored = $file->store("purchase-orders/{$bid}", 'local');
            $data['attachment_name'] = $file->getClientOriginalName();
        }

        try {
            $po = DB::transaction(function () use ($bid, $branch, $supplier, $data, $items, $totals, $stored, $request) {
                $token = $data['form_token'] ?? null;

                /*
                 * ونموذجٌ أُرسل مرّتين يُردّ إلى أمره الأوّل.
                 *
                 * الحارسُ يقرأ تحت قفل: بلا القفل يمرّ الطلبان معًا فيجد كلٌّ
                 * منهما القاعدةَ فارغةً من المفتاح.
                 */
                if ($token) {
                    $seen = PurchaseOrder::where('business_id', $bid)
                        ->where('form_token', $token)->lockForUpdate()->first();

                    if ($seen) {
                        return $seen;
                    }
                }

                $po = PurchaseOrder::create([
                    'business_id' => $bid,
                    'branch_id' => $branch->id,
                    'number' => PurchaseOrders::nextNumber($bid),
                    'supplier_id' => $supplier->id,
                    'supplier_name' => $supplier->name,
                    'supplier_reference' => $data['supplier_reference'] ?? null,
                    'status' => $request->boolean('draft') ? PurchaseOrders::DRAFT : PurchaseOrders::SENT,
                    'items_subtotal' => $totals['items_subtotal'],
                    'supplier_discount' => $totals['supplier_discount'],
                    'shipping_cost' => $totals['shipping_cost'],
                    'tax' => $totals['tax'],
                    'tax_rate' => $totals['tax_rate'],
                    'total' => $totals['total'],
                    'notes' => $data['notes'] ?? null,
                    'internal_notes' => $data['internal_notes'] ?? null,
                    /*
                     * وخطّةُ السداد تُحفظ ولا تُنفَّذ.
                     *
                     * لا `Ledger::post` هنا ولا في شيءٍ يناديه هذا الباب —
                     * أمرُ الشراء نيّةٌ لا حدث. والسدادُ الفعليّ بابُه
                     * `SupplierInvoiceController::pay`، ولا يُفتح إلّا على
                     * سندٍ **معتمد**.
                     */
                    'payment_method' => $data['payment_method'],
                    'payment_terms_days' => $data['payment_terms_days'] ?? null,
                    'payment_reference' => $data['payment_reference'] ?? null,
                    'ordered_at' => $data['ordered_at'],
                    'expected_delivery_at' => $data['expected_delivery_at'] ?? null,
                    'attachment' => $stored,
                    'attachment_name' => $data['attachment_name'] ?? null,
                    'form_token' => $token,
                ]);

                foreach ($items as $line) {
                    $po->items()->create($line);
                }

                /*
                 * وما استُعمل عاد إلى القائمة.
                 *
                 * وحدةٌ رفعها التاجرُ من قائمته ثمّ كتبها في أمرٍ جديد يريدها:
                 * وبقاؤها مرفوعةً يجعله يكتبها في كلّ بندٍ من كلّ أمر ولا يعرف
                 * لماذا لا تظهر. والرفعُ رأيٌ في القائمة يُنقض باستعمالٍ جديد.
                 */
                PurchaseUnits::unhide($bid, array_column($items, 'purchase_unit'));

                return $po;
            });
        } catch (Throwable $e) {
            // ومرفقٌ رُفع قبل المعاملة لا يبقى على القرص بلا صفٍّ يشير إليه
            if ($stored) {
                Storage::disk('local')->delete($stored);
            }

            throw $e;
        }

        // وسطرُ السجلّ يُقرأ في شاشة «النشاط» — فالمبلغُ بعملة المتجر لا مثبَّتًا
        Activity::log('created', 'أنشأ أمر شراء '.$po->number.' لفرع '.$branch->name
            .' بقيمة '.Money::format((float) $po->total, Money::of((int) $po->business_id)), ['subject_id' => $po->id]);

        return redirect()->route('admin.purchases.orders', ['q' => $po->number])->with('toast', [
            'msg' => $po->status === PurchaseOrders::DRAFT
                ? __('حُفظ أمر الشراء :number مسودّة', ['number' => $po->number])
                : __('أُنشئ أمر الشراء :number', ['number' => $po->number]),
            'type' => 'success',
        ]);
    }

    /**
     * رفعُ وحدةِ شراءٍ من قائمة المتجر.
     *
     * ولا تُمحى من أمرٍ كُتبت فيه: أمرٌ اشترى «بالرول» يبقى يقول «رول» —
     * وإلا صار حذفُ خيارٍ من شاشةٍ يُعيد كتابة تاريخ الشراء. والقائمةُ تُقرأ
     * ممّا اشترى به المتجر، فالمرفوعُ يُطرح منها عند القراءة — انظر
     * `PurchaseUnits`.
     */
    public function hideUnit(Request $request)
    {
        $data = $request->validate([
            'unit' => ['required', 'string', 'max:40'],
        ]);

        PurchaseUnits::hide($this->bid(), $data['unit']);

        Activity::log('updated', 'رفع وحدة الشراء «'.trim($data['unit']).'» من قائمته');

        return back();
    }

    /**
     * بنودُ الأمر كما تُكتب في القاعدة — بأسعارها ووحداتها ولقطاتها.
     *
     * والمنتجُ يُقرأ من قاعدة المتجر: معرّفٌ من متجرٍ آخر يُطرح ويصير البند
     * يدويًّا باسمه المكتوب، فلا يرتبط أمرُ هذا المتجر بصنفٍ لا يملكه.
     *
     * @return list<array<string, mixed>>
     */
    private function lines(int $bid, array $rows): array
    {
        $ids = collect($rows)->pluck('product_id')->filter()->unique()->all();
        $owned = $ids === [] ? collect() : Product::where('business_id', $bid)->whereIn('id', $ids)->pluck('id', 'id');

        $out = [];

        foreach ($rows as $row) {
            $quantity = (int) $row['quantity'];
            $cost = round((float) $row['cost'], 3);

            if ($quantity < 1) {
                continue;
            }

            /*
             * ومحتوى الوحدة لقطةٌ على البند لا قراءةٌ من المنتج.
             *
             * من غيّر تعبئة صنفه بعد شهرٍ لا يُعيد كتابة أوامرَ مضت — ولا
             * يُعيد حساب ما دخل الرفَّ منها.
             */
            $per = (float) ($row['units_per_purchase_unit'] ?? 1);

            $out[] = [
                'product_id' => isset($row['product_id']) ? ($owned[$row['product_id']] ?? null) : null,
                'name' => $row['name'],
                // ‏والمسافاتُ مقلَّمةٌ سلفًا بـ`TrimStrings` — وقصُّها هنا فرعٌ لا يُقرأ
                'purchase_unit' => ($row['purchase_unit'] ?? null) ?: null,
                'units_per_purchase_unit' => $per > 0 ? round($per, 3) : 1,
                'cost' => $cost,
                'quantity' => $quantity,
                'line_total' => PurchaseOrderTotals::line(['cost' => $cost, 'quantity' => $quantity]),
            ];
        }

        return $out;
    }

    /**
     * تسجيلُ ما وصل — ورقةٌ تنتظر الاعتماد، ولا يتحرّك بها رفّ.
     *
     * وكان يزيد المخزونَ لحظتَه: من يكتب هو من يعتمد. انظر `GoodsReceipts`.
     */
    public function receive(Request $request, $id)
    {
        if (! auth()->user()?->may(Permissions::RECEIPT_CREATE)) {
            abort(403);
        }

        $bid = $this->bid();
        $po = PurchaseOrder::where('business_id', $bid)->with('items')->findOrFail($id);

        $data = $request->validate([
            'items' => ['nullable', 'array'],
            'items.*.id' => ['required', Rule::exists('purchase_order_items', 'id')->where('purchase_order_id', $po->id)],
            'items.*.quantity' => ['required', 'integer', 'min:0'],
            'received_at' => ['nullable', 'date'],
            'receiver' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'attachment' => ['nullable', 'file', 'max:10240', 'extensions:jpg,jpeg,png,pdf,webp,heic'],
        ], [
            'attachment.extensions' => __('الصيغ المدعومة: JPG، PNG، PDF، WEBP، HEIC.'),
            'attachment.max' => __('أقصى حجم 10 ميجابايت.'),
        ], [
            'items.*.quantity' => __('الكمية المستلمة'),
            'receiver' => __('المستلِم'),
            'attachment' => __('ورقة المورّد'),
        ]);

        /*
         * وما لم يُرسل يُستلم كاملًا — فزرّ «استلام الكل» يبقى طلبًا فارغًا
         * كما كان، ولا يُكسر ما يعمل اليوم.
         */
        $asked = collect($data['items'] ?? [])->keyBy('id');
        $pending = GoodsReceipts::pendingQuantities($po->id);
        $lines = [];

        foreach ($po->items as $item) {
            $lines[$item->id] = $asked->has($item->id)
                ? (int) $asked[$item->id]['quantity']
                : max(0, (int) $item->remaining - (int) ($pending[$item->id] ?? 0));
        }

        $stored = null;
        if ($request->hasFile('attachment')) {
            $file = $request->file('attachment');
            $data['attachment_name'] = $file->getClientOriginalName();
            $stored = $data['attachment'] = $file->store("receipts/{$bid}", 'local');
        }

        try {
            $note = GoodsReceipts::record($po, $lines, $data, auth()->user());
        } catch (ReceiveRefused $e) {
            if ($stored) {
                Storage::disk('local')->delete($stored);
            }

            return $e->tone === 'info'
                ? back()->with('toast', ['msg' => $e->getMessage(), 'type' => 'info'])
                : back()->withErrors(['receive' => $e->getMessage()]);
        }

        return back()->with('toast', [
            'msg' => __('سُجّل الاستلام :n — بانتظار الاعتماد', ['n' => $note->number]),
            'type' => 'success',
        ]);
    }

    /**
     * اعتمادُ الاستلام — وهنا وحدَه تدخل البضاعةُ الرفّ.
     *
     * والصلاحيةُ فعلٌ مستقلّ لا قسم: من يفتح المشتريات ليكتب ما وصله ليس
     * بالضرورة من يقول «هذا صحيح».
     */
    public function approveReceipt($id)
    {
        if (! auth()->user()?->may(Permissions::RECEIPT_APPROVE)) {
            abort(403);
        }

        $note = GoodsReceiptNote::where('business_id', $this->bid())->findOrFail($id);

        try {
            GoodsReceipts::approve($note, auth()->user());
        } catch (ReceiveRefused $e) {
            return $e->tone === 'info'
                ? back()->with('toast', ['msg' => $e->getMessage(), 'type' => 'info'])
                : back()->withErrors(['receive' => $e->getMessage()]);
        }

        return back()->with('toast', [
            'msg' => __('اعتُمد الاستلام :n — دخلت البضاعة المخزون', ['n' => $note->number]),
            'type' => 'success',
        ]);
    }

    /**
     * رفضُ الاستلام — بسببٍ مكتوب، ولا يتحرّك به شيء.
     *
     * وصلاحيتُه غيرُ صلاحية الاعتماد: الرفضُ لا يُدخل بضاعةً ولا يكتب قيدًا،
     * فمن يُوثَق به ليقول «هذه ناقصة» لا يلزم أن يُوثَق به ليُدخلها الرفّ.
     */
    public function rejectReceipt(Request $request, $id)
    {
        if (! auth()->user()?->may(Permissions::RECEIPT_REJECT)) {
            abort(403);
        }

        $data = $request->validate(['reason' => ['required', 'string', 'max:200']], [], [
            'reason' => __('سبب الرفض'),
        ]);

        $note = GoodsReceiptNote::where('business_id', $this->bid())->findOrFail($id);

        try {
            GoodsReceipts::reject($note, $data['reason'], auth()->user());
        } catch (ReceiveRefused $e) {
            return back()->with('toast', ['msg' => $e->getMessage(), 'type' => 'info']);
        }

        return back()->with('toast', ['msg' => __('رُفض الاستلام :n', ['n' => $note->number]), 'type' => 'warning']);
    }

    /** رفع/استبدال إيصال الدفع لأمر شراء قائم */
    public function uploadReceipt(Request $request, $id)
    {
        $po = PurchaseOrder::where('business_id', $this->bid())->findOrFail($id);
        $request->validate([
            'receipt' => ['required', 'file', 'max:10240', 'extensions:jpg,jpeg,png,pdf,webp,heic'],
        ], [
            'receipt.extensions' => __('الصيغ المدعومة: JPG، PNG، PDF، WEBP، HEIC.'),
            'receipt.max' => __('أقصى حجم 10 ميجابايت.'),
        ], ['receipt' => __('إيصال الدفع')]);

        // استبدال الإيصال القديم بدل تركه يتراكم على القرص
        if ($po->receipt) {
            Storage::disk('local')->delete($po->receipt);
        }
        $file = $request->file('receipt');
        $po->update([
            'receipt' => $file->store('purchase-receipts/'.$this->bid(), 'local'),
            'receipt_name' => $file->getClientOriginalName(),
        ]);
        Activity::log('updated', 'أرفق إيصال دفع لأمر الشراء '.$po->number, ['subject_id' => $po->id]);

        return back()->with('toast', ['msg' => __('تم رفع إيصال الدفع'), 'type' => 'success']);
    }

    /**
     * حذفُ أمر شراء — ما لم تكن له ذرّيّة.
     *
     * الحذف كان مطلقًا. وأمرٌ استُلمت بضاعتُه أو حُرّر عليه سند له وثائق تشير
     * إليه: إشعارُ الاستلام يُفرَّغ مرجعُه فيبقى ورقةً تقول «دخل عشرون» ولا
     * تقول من أين، والسند يبقى دَينًا بلا أمرٍ يبرّره. والبضاعة تبقى على
     * الرفّ — وهي وصلت فعلًا، فلا يجوز ردُّها — لكنّ سببَ وجودها يُمحى.
     *
     * فيبقى الحذف لما لم يقع منه شيء: أمرٌ كُتب خطأً ولم يصل ولم يُفوتَر.
     */
    public function destroy($id)
    {
        $po = PurchaseOrder::where('business_id', $this->bid())->findOrFail($id);

        if (GoodsReceiptNote::where('purchase_order_id', $po->id)->exists()) {
            return back()->with('toast', [
                'msg' => __('استُلمت بضاعةٌ على هذا الأمر — لا يُحذف بعد أن دخلت الرفّ'),
                'type' => 'warning',
            ]);
        }

        if ($po->invoices()->exists()) {
            return back()->with('toast', [
                'msg' => __('حُرّر سندُ مورّدٍ على هذا الأمر — لا يُحذف'),
                'type' => 'warning',
            ]);
        }

        $num = $po->number;
        if ($po->receipt) {
            Storage::disk('local')->delete($po->receipt);
        }
        $po->delete();
        Activity::log('deleted', 'حذف أمر الشراء: '.$num);

        /*
         * وإلى القائمة لا إلى حيث كان.
         *
         * `back()` كان يردّ الحاذفَ إلى الصفحة التي ضغط فيها — وصفحةُ الأمر
         * نفسِه واحدةٌ منها منذ اليوم، فيهبط في `404` على أمرٍ محاه هو. ومن
         * حذف من القائمة يعود إليها كما كان.
         */
        return redirect()->route('admin.purchases.orders')
            ->with('toast', ['msg' => __('تم حذف أمر الشراء'), 'type' => 'warning']);
    }
}
