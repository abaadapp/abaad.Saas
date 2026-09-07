<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\GoodsReceiptNote;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Support\Activity;
use App\Support\Demo;
use App\Support\GoodsReceipts;
use App\Support\Permissions;
use App\Support\ReceiveRefused;
use App\Support\Search;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

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

        return Inertia::render('Admin/Purchases/Index', [
            'stats' => [
                ['label' => __('إجمالي الأوامر'), 'value' => (string) $s['total'], 'icon' => 'clipboard-list', 'color' => 'primary'],
                ['label' => __('قيد التنفيذ'), 'value' => (string) $s['pending'], 'icon' => 'clock', 'color' => 'warning'],
                ['label' => __('مستلمة'), 'value' => (string) $s['received'], 'icon' => 'package-check', 'color' => 'success'],
                ['label' => __('قيمة قيد الاستلام'), 'value' => Demo::money($s['value']), 'icon' => 'wallet', 'color' => 'info'],
            ],
            // رابط الإيصال يُبنى هنا: المسار وحده لا يكفي المتصفح لفتحه
            'orders' => array_map(function ($o) {
                $o['receipt'] = $o['receipt']
                    ? Storage::disk('public')->url($o['receipt'])
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
        $branch = Branch::where('business_id', $bid)->find($data['branch_id']);
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
            'number' => 'PO-'.random_int(10000, 99999),
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
        Activity::log('created', 'أنشأ أمر شراء '.$po->number.' لفرع '.$branch->name.' بقيمة '.number_format($total, 3).' ر.ع', ['subject_id' => $po->id]);

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
    /**
     * تسجيلُ ما وصل — ورقةٌ تنتظر الاعتماد، ولا يتحرّك بها رفّ.
     *
     * وكان يزيد المخزونَ لحظتَه: من يكتب هو من يعتمد. انظر `GoodsReceipts`.
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

    /** رفضُ الاستلام — بسببٍ مكتوب، ولا يتحرّك به شيء */
    public function rejectReceipt(Request $request, $id)
    {
        if (! auth()->user()?->may(Permissions::RECEIPT_APPROVE)) {
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
            Storage::disk('public')->delete($po->receipt);
        }
        $file = $request->file('receipt');
        $po->update([
            'receipt' => $file->store('purchase-receipts/'.$this->bid(), 'public'),
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
            Storage::disk('public')->delete($po->receipt);
        }
        $po->delete();
        Activity::log('deleted', 'حذف أمر الشراء: '.$num);

        return back()->with('toast', ['msg' => __('تم حذف أمر الشراء'), 'type' => 'warning']);
    }
}
