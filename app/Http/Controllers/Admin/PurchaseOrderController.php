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

    public function store(Request $request)
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['nullable', 'integer'],
            'items.*.name' => ['required', 'string', 'max:255'],
            'items.*.cost' => ['required', 'numeric', 'min:0'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ], [
            'branch_id.required' => 'يجب تحديد الفرع الذي ستُستلم فيه البضاعة.',
        ]);

        $bid = $this->bid();

        // الفرع يجب أن يخصّ نفس النشاط
        $branch = \App\Models\Branch::where('business_id', $bid)->find($data['branch_id']);
        if (! $branch) {
            return back()->withInput()->withErrors(['branch_id' => 'الفرع المحدد غير صالح.']);
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

        return redirect()->route('admin.purchases.index')->with('toast', ['msg' => 'تم إنشاء أمر الشراء ' . $po->number, 'type' => 'success']);
    }

    /** استلام أمر الشراء: يرفع كميات المنتجات ويسجّل حركة مخزون */
    public function receive($id)
    {
        $bid = $this->bid();
        $po = PurchaseOrder::where('business_id', $bid)->with('items')->findOrFail($id);
        if ($po->status === 'مستلم') {
            return back()->with('toast', ['msg' => 'أمر الشراء مستلم مسبقًا', 'type' => 'info']);
        }

        foreach ($po->items as $item) {
            $remaining = $item->remaining;
            if ($remaining <= 0) {
                continue;
            }
            if ($item->product_id) {
                $product = Product::where('business_id', $bid)->find($item->product_id);
                if ($product) {
                    $product->increment('quantity', $remaining);
                    // تحديث تكلفة المنتج بآخر تكلفة شراء
                    $product->update(['cost' => $item->cost]);
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

        return back()->with('toast', ['msg' => 'تم استلام أمر الشراء وتحديث المخزون', 'type' => 'success']);
    }

    public function destroy($id)
    {
        $po = PurchaseOrder::where('business_id', $this->bid())->findOrFail($id);
        $num = $po->number;
        $po->delete();
        \App\Support\Activity::log('deleted', 'حذف أمر الشراء: ' . $num);

        return back()->with('toast', ['msg' => 'تم حذف أمر الشراء', 'type' => 'warning']);
    }
}
