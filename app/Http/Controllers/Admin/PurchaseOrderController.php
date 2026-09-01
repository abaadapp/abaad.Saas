<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GoodsReceiptNote;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Support\Demo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PurchaseOrderController extends Controller
{
    private function bid(): int { return auth()->user()->business_id ?? Demo::bid(); }

    /** أوامر الشراء — ما طُلب، وما استُلم منه */
    public function index(Request $request): \Inertia\Response
    {
        $s = Demo::purchaseOrderStats();

        return \Inertia\Inertia::render('Admin/Purchases/Index', [
            'stats' => [
                ['label' => __('إجمالي الأوامر'), 'value' => (string) $s['total'], 'icon' => 'clipboard-list', 'color' => 'primary'],
                ['label' => __('قيد التنفيذ'), 'value' => (string) $s['pending'], 'icon' => 'clock', 'color' => 'warning'],
                ['label' => __('مستلمة'), 'value' => (string) $s['received'], 'icon' => 'package-check', 'color' => 'success'],
                ['label' => __('قيمة قيد الاستلام'), 'value' => Demo::money($s['value']), 'icon' => 'wallet', 'color' => 'info'],
            ],
            // رابط الإيصال يُبنى هنا: المسار وحده لا يكفي المتصفح لفتحه
            'orders' => array_map(function ($o) {
                $o['receipt'] = $o['receipt']
                    ? \Illuminate\Support\Facades\Storage::disk('public')->url($o['receipt'])
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
            'q' => trim((string) $request->query('q')) ?: null,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'receipt' => ['nullable', 'file', 'max:10240', 'extensions:jpg,jpeg,png,pdf,webp,heic'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['nullable', 'integer'],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.cost' => ['required', 'numeric', 'min:0'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ], [
            'branch_id.required' => __('يجب تحديد الفرع الذي ستُستلم فيه البضاعة.'),
            'receipt.extensions' => __('الصيغ المدعومة لإيصال الدفع: JPG، PNG، PDF، WEBP، HEIC.'),
            'receipt.max' => __('أقصى حجم لإيصال الدفع 10 ميجابايت.'),
        ]);

        $bid = $this->bid();

        // الفرع يجب أن يخصّ نفس النشاط
        $branch = \App\Models\Branch::where('business_id', $bid)->find($data['branch_id']);
        if (! $branch) {
            return back()->withInput()->withErrors(['branch_id' => __('الفرع المحدد غير صالح.')]);
        }

        // إيصال الدفع (اختياري)
        $receipt = $receiptName = null;
        if ($request->hasFile('receipt')) {
            $file = $request->file('receipt');
            $receiptName = $file->getClientOriginalName();
            $receipt = $file->store("purchase-receipts/{$bid}", 'public');
        }

        $supplier = ! empty($data['supplier_id']) ? Supplier::where('business_id', $bid)->find($data['supplier_id']) : null;
        $total = collect($data['items'])->sum(fn ($i) => $i['cost'] * $i['quantity']);

        $po = PurchaseOrder::create([
            'business_id' => $bid,
            'branch_id' => $branch->id,
            'number' => 'PO-' . random_int(10000, 99999),
            'supplier_id' => $supplier?->id,
            'supplier_name' => $supplier?->name,
            'status' => 'مُرسل',
            'total' => $total,
            'notes' => $data['notes'] ?? null,
            'receipt' => $receipt,
            'receipt_name' => $receiptName,
            'ordered_at' => now(),
        ]);
        foreach ($data['items'] as $i) {
            $po->items()->create([
                'product_id' => $i['product_id'] ?? null,
                'name' => $i['name'],
                'cost' => $i['cost'],
                'quantity' => $i['quantity'],
            ]);
        }
        \App\Support\Activity::log('created', 'أنشأ أمر شراء ' . $po->number . ' لفرع ' . $branch->name . ' بقيمة ' . number_format($total, 3) . ' ر.ع', ['subject_id' => $po->id]);

        return redirect()->route('admin.purchases.orders')->with('toast', ['msg' => __('تم إنشاء أمر الشراء :number', ['number' => $po->number]), 'type' => 'success']);
    }

    /**
     * استلام أمر شراء — كلَّه أو بعضه، وبورقةٍ تشهد على ما دخل.
     *
     * كان الاستلام كلًّا أو لا شيء: الزرّ يرسل طلبًا فارغًا والكود يكتب
     * `received_quantity = quantity` لكل بند. والعمودان `received_quantity`
     * و`remaining` موجودان في القاعدة والنموذج منذ البداية — أي أنّ الاستلام
     * الجزئيّ مُهيَّأٌ له ولم يُوصَل.
     *
     * ومورّدٌ يشحن ثمانين من مئة كان يُسجَّل مئةً: فيزيد المخزون عشرين لم
     * تصل، ويُحسب متوسّط التكلفة على مئةٍ دُفع ثمن ثمانين منها — فيفسد
     * الرقمان معًا، ويظهر الفرق بعد أشهرٍ في جردٍ لا يُعرف من أين جاء.
     *
     * وكلّ دفعةٍ تُنشئ إشعار استلامٍ خاصًّا بها (GRN): الحركة في سجلّ المخزون
     * سطرٌ يقول «دخل عشرون»، والإشعار مستندٌ يقول متى ومن استلم ومن أيّ أمرٍ
     * وبأيّ تكلفة — وهو ما يُقابَل بفاتورة المورّد.
     */
    public function receive(Request $request, $id)
    {
        $bid = $this->bid();
        $po = PurchaseOrder::where('business_id', $bid)->with('items')->findOrFail($id);

        $data = $request->validate([
            'items' => ['nullable', 'array'],
            'items.*.id' => ['required', Rule::exists('purchase_order_items', 'id')->where('purchase_order_id', $po->id)],
            'items.*.quantity' => ['required', 'integer', 'min:0'],
            'received_at' => ['nullable', 'date'],
            'receiver' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [], [
            'items.*.quantity' => __('الكمية المستلمة'),
            'receiver' => __('المستلِم'),
        ]);

        try {
            $note = DB::transaction(function () use ($bid, $po, $data) {
                /*
                 * المتبقّي يُقرأ تحت قفل — لا قبل المعاملة.
                 *
                 * كان يُقرأ من نسخةٍ حُمّلت مع الصفحة، ثمّ يُزاد المخزون على
                 * أساسها. فضغطتان على «استلام الكل» — أو موظّفان يفتحان أمر
                 * الشراء نفسه — تقرآن «المتبقّي مئة» كلتاهما فتُدخلان مئتين:
                 * بضاعةٌ لم تصل تُضاف إلى الرفّ، و`received_quantity` يتجاوز
                 * المطلوب، وإشعارا استلامٍ لدفعةٍ واحدة. ومتوسّطُ التكلفة
                 * يُرجَّح بكمّيةٍ وهميّة فيُفسد تكلفة كلّ بيعةٍ قادمة.
                 *
                 * ولا يكشفه إلّا الجرد، بعد أن يكون قد دخل في تسعير شهر.
                 */
                $po = PurchaseOrder::where('business_id', $bid)->lockForUpdate()->findOrFail($po->id);
                $items = $po->items()->lockForUpdate()->get();

                if ($po->status === 'مستلم') {
                    throw new \App\Support\ReceiveRefused(__('أمر الشراء مستلم مسبقًا'), 'info');
                }

                /*
                 * ما لم يُرسل يُستلم كاملًا — فزرّ «استلام الكل» يبقى طلبًا فارغًا
                 * كما كان، ولا يُكسر ما يعمل اليوم.
                 */
                $asked = collect($data['items'] ?? [])->keyBy('id');
                $lines = [];
                $over = [];

                foreach ($items as $item) {
                    $qty = $asked->has($item->id) ? (int) $asked[$item->id]['quantity'] : $item->remaining;

                    /*
                     * الزائد يُردّ ولا يُقصّ صامتًا.
                     *
                     * حصرُه في `remaining` بلا قول يُدخل الكمية الصحيحة ويترك من
                     * كتب الرقم يظنّ أنّ ما كتبه سُجّل — وهو أسوأ من الرفض: لا يعرف
                     * أنّه أخطأ، ولا أنّ الورقة تخالف ما في يده.
                     */
                    if ($qty > $item->remaining) {
                        $over[] = $item->name.' ('.__('المتبقّي').' '.$item->remaining.')';
                    }
                    if ($qty > 0) {
                        $lines[$item->id] = $qty;
                    }
                }

                if ($over) {
                    throw new \App\Support\ReceiveRefused(
                        __('الكمية المستلمة أكبر من المتبقّي: :items', ['items' => implode('، ', $over)]),
                    );
                }

                if (! $lines) {
                    throw new \App\Support\ReceiveRefused(__('لا كميةَ لاستلامها — اكتب ما وصلك من كل صنف.'));
                }

                $po->setRelation('items', $items);

                $noteItems = [];

                foreach ($po->items as $item) {
                    $qty = $lines[$item->id] ?? 0;
                    if ($qty <= 0) {
                        continue;
                    }

                    if ($item->product_id) {
                        $product = Product::where('business_id', $bid)->find($item->product_id);
                        if ($product) {
                            \App\Models\BranchStock::ensureAllocated($bid, $product->id, (int) $product->quantity);
                            $onHand = (int) $product->quantity;
                            $product->increment('quantity', $qty);
                            \App\Models\BranchStock::adjust($bid, $po->branch_id, $product->id, $qty);

                            /*
                             * متوسّطٌ مرجّح لا آخر سعر.
                             *
                             * كانت التكلفة تُكتب فوق القديمة: مئةُ قطعةٍ اشتُريت بأربعة
                             * ثم عشرٌ بستّة تجعل المئة والعشر كلَّها بستّة — فتقفز قيمة
                             * المخزون بمئتين لم تُدفع، وينقص الربح المحسوب على كل
                             * بيعةٍ قادمة. والمتوسّط يوزّع الفرق على ما اشتُري فعلًا.
                             *
                             * والمرجَّح بما وصل لا بما طُلب: دفعةٌ من ثمانين لا
                             * تُثقَّل بوزن مئة.
                             *
                             * ورصيدٌ صفرٌ أو سالب يعني بدايةً جديدة، فتُؤخذ تكلفة
                             * الشراء كما هي — لا معنى لمتوسّطٍ على لا شيء.
                             */
                            $newCost = $onHand > 0
                                ? (($onHand * (float) $product->cost) + ($qty * (float) $item->cost)) / ($onHand + $qty)
                                : (float) $item->cost;
                            $product->update(['cost' => round($newCost, 3)]);

                            InventoryMovement::create([
                                'business_id' => $bid,
                                'branch_id' => $po->branch_id,
                                'product_id' => $product->id,
                                'product_name' => $product->name,
                                'sku' => $product->sku,
                                'type' => 'إضافة كمية',
                                'quantity' => '+'.$qty,
                                'employee_name' => auth()->user()->name,
                            ]);
                        }
                    }

                    $noteItems[] = [
                        'product_id' => $item->product_id,
                        'name' => $item->name,
                        'quantity' => $qty,
                        'cost' => (float) $item->cost,
                    ];

                    $item->increment('received_quantity', $qty);
                }

                $note = GoodsReceiptNote::create([
                    'business_id' => $bid,
                    'branch_id' => $po->branch_id,
                    'supplier_id' => $po->supplier_id,
                    'purchase_order_id' => $po->id,
                    'number' => GoodsReceiptNote::nextNumber($bid),
                    'received_at' => $data['received_at'] ?? now()->toDateString(),
                    'receiver' => $data['receiver'] ?? auth()->user()->name,
                    'notes' => $data['notes'] ?? null,
                ]);

                foreach ($noteItems as $line) {
                    $note->items()->create($line);
                }

                /*
                 * الحالة تُقرأ من البنود بعد تحديثها لا تُفترض.
                 *
                 * `$po->items` محمَّلةٌ قبل الزيادة، فـ`remaining` عليها قديم —
                 * ولو قيست به لبقي أمرٌ اكتمل استلامُه «مستلمًا جزئيًّا» إلى الأبد.
                 */
                $outstanding = $po->items()->whereColumn('received_quantity', '<', 'quantity')->exists();

                $po->update([
                    'status' => $outstanding ? 'مستلم جزئيًا' : 'مستلم',
                    // تاريخُ الاكتمال لا تاريخُ أوّل دفعة: لكلّ دفعةٍ تاريخُها في إشعارها
                    'received_at' => $outstanding ? null : now(),
                ]);

                return $note;
            });
        } catch (\App\Support\ReceiveRefused $e) {
            return $e->tone === 'info'
                ? back()->with('toast', ['msg' => $e->getMessage(), 'type' => 'info'])
                : back()->withErrors(['receive' => $e->getMessage()]);
        }

        $po->refresh();

        \App\Support\Activity::log('updated', 'استلم من أمر الشراء '.$po->number.' بإشعار '.$note->number, ['subject_id' => $po->id]);

        return back()->with('toast', [
            'msg' => $po->status === 'مستلم'
                ? __('اكتمل استلام أمر الشراء — إشعار :n', ['n' => $note->number])
                : __('سُجّل استلامٌ جزئيّ — إشعار :n', ['n' => $note->number]),
            'type' => 'success',
        ]);
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
            \Illuminate\Support\Facades\Storage::disk('public')->delete($po->receipt);
        }
        $file = $request->file('receipt');
        $po->update([
            'receipt' => $file->store('purchase-receipts/' . $this->bid(), 'public'),
            'receipt_name' => $file->getClientOriginalName(),
        ]);
        \App\Support\Activity::log('updated', 'أرفق إيصال دفع لأمر الشراء ' . $po->number, ['subject_id' => $po->id]);

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

        if (\App\Models\GoodsReceiptNote::where('purchase_order_id', $po->id)->exists()) {
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
            \Illuminate\Support\Facades\Storage::disk('public')->delete($po->receipt);
        }
        $po->delete();
        \App\Support\Activity::log('deleted', 'حذف أمر الشراء: ' . $num);

        return back()->with('toast', ['msg' => __('تم حذف أمر الشراء'), 'type' => 'warning']);
    }
}
