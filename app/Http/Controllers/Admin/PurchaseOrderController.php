<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Support\Demo;
use Illuminate\Http\Request;

class PurchaseOrderController extends Controller
{
    private function bid(): int { return auth()->user()->business_id ?? Demo::bid(); }

    /** أوامر الشراء — ما طُلب، وما استُلم منه */
    public function index(): \Inertia\Response
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

    /** استلام أمر الشراء: يرفع كميات المنتجات ويسجّل حركة مخزون */
    public function receive($id)
    {
        $bid = $this->bid();
        $po = PurchaseOrder::where('business_id', $bid)->with('items')->findOrFail($id);
        if ($po->status === 'مستلم') {
            return back()->with('toast', ['msg' => __('أمر الشراء مستلم مسبقًا'), 'type' => 'info']);
        }

        foreach ($po->items as $item) {
            $remaining = $item->remaining;
            if ($remaining <= 0) {
                continue;
            }
            if ($item->product_id) {
                $product = Product::where('business_id', $bid)->find($item->product_id);
                if ($product) {
                    \App\Models\BranchStock::ensureAllocated($bid, $product->id, (int) $product->quantity);
                    $onHand = (int) $product->quantity;
                    $product->increment('quantity', $remaining);
                    \App\Models\BranchStock::adjust($bid, $po->branch_id, $product->id, (int) $remaining);

                    /*
                     * متوسّطٌ مرجّح لا آخر سعر.
                     *
                     * كانت التكلفة تُكتب فوق القديمة: مئةُ قطعةٍ اشتُريت بأربعة
                     * ثم عشرٌ بستّة تجعل المئة والعشر كلَّها بستّة — فتقفز قيمة
                     * المخزون بمئتين لم تُدفع، وينقص الربح المحسوب على كل
                     * بيعةٍ قادمة. والمتوسّط يوزّع الفرق على ما اشتُري فعلًا.
                     *
                     * ورصيدٌ صفرٌ أو سالب يعني بدايةً جديدة، فتُؤخذ تكلفة
                     * الشراء كما هي — لا معنى لمتوسّطٍ على لا شيء.
                     */
                    $newCost = $onHand > 0
                        ? (($onHand * (float) $product->cost) + ($remaining * (float) $item->cost))
                            / ($onHand + $remaining)
                        : (float) $item->cost;
                    $product->update(['cost' => round($newCost, 3)]);
                    InventoryMovement::create([
                        'business_id' => $bid,
                        'branch_id' => $po->branch_id,
                        'product_id' => $product->id,
                        'product_name' => $product->name,
                        'sku' => $product->sku,
                        'type' => 'إضافة كمية',
                        'quantity' => '+' . $remaining,
                        'employee_name' => auth()->user()->name,
                    ]);
                }
            }
            $item->update(['received_quantity' => $item->quantity]);
        }
        $po->update(['status' => 'مستلم', 'received_at' => now()]);
        \App\Support\Activity::log('updated', 'استلم أمر الشراء ' . $po->number, ['subject_id' => $po->id]);

        return back()->with('toast', ['msg' => __('تم استلام أمر الشراء وتحديث المخزون'), 'type' => 'success']);
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

    public function destroy($id)
    {
        $po = PurchaseOrder::where('business_id', $this->bid())->findOrFail($id);
        $num = $po->number;
        if ($po->receipt) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($po->receipt);
        }
        $po->delete();
        \App\Support\Activity::log('deleted', 'حذف أمر الشراء: ' . $num);

        return back()->with('toast', ['msg' => __('تم حذف أمر الشراء'), 'type' => 'warning']);
    }
}
