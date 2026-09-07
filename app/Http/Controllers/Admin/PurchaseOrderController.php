<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\GoodsReceiptNote;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Support\Activity;
use App\Support\Demo;
use App\Support\GoodsReceipts;
use App\Support\Permissions;
use App\Support\PurchaseOrders;
use App\Support\PurchaseOrderTotals;
use App\Support\ReceiveRefused;
use App\Support\Search;
use Illuminate\Http\Request;
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
            'notes' => ['nullable', 'string', 'max:1000'],
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
            PurchaseOrderTotals::taxRateFor($bid),
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
                    'ordered_at' => $data['ordered_at'],
                    'expected_delivery_at' => $data['expected_delivery_at'] ?? null,
                    'attachment' => $stored,
                    'attachment_name' => $data['attachment_name'] ?? null,
                    'form_token' => $token,
                ]);

                foreach ($items as $line) {
                    $po->items()->create($line);
                }

                return $po;
            });
        } catch (Throwable $e) {
            // ومرفقٌ رُفع قبل المعاملة لا يبقى على القرص بلا صفٍّ يشير إليه
            if ($stored) {
                Storage::disk('local')->delete($stored);
            }

            throw $e;
        }

        Activity::log('created', 'أنشأ أمر شراء '.$po->number.' لفرع '.$branch->name
            .' بقيمة '.number_format((float) $po->total, 3).' ر.ع', ['subject_id' => $po->id]);

        return redirect()->route('admin.purchases.orders', ['q' => $po->number])->with('toast', [
            'msg' => $po->status === PurchaseOrders::DRAFT
                ? __('حُفظ أمر الشراء :number مسودّة', ['number' => $po->number])
                : __('أُنشئ أمر الشراء :number', ['number' => $po->number]),
            'type' => 'success',
        ]);
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

        return back()->with('toast', ['msg' => __('تم حذف أمر الشراء'), 'type' => 'warning']);
    }
}
