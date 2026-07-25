<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Support\Demo;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
    private function bid(): int { return auth()->user()->business_id ?? Demo::bid(); }

    /** الأصناف التي تحتاج إعادة طلب (الكمية ≤ حد التنبيه) */
    public function reorder()
    {
        $items = collect(Demo::inventory())
            ->filter(fn ($i) => (int) $i['qty'] <= (int) $i['min'])
            ->sortBy('qty')
            ->values()
            ->all();

        return view('admin.inventory.reorder', ['items' => $items]);
    }

    /** شاشة الجرد الفعلي — إدخال الكمية المعدودة ومقارنتها بالدفترية */
    public function stocktake()
    {
        return view('admin.inventory.stocktake', [
            'items' => Demo::inventory(),
            'branches' => Demo::branches(),
            'currentBranch' => Demo::currentBranchId(),
        ]);
    }

    /** تطبيق الجرد: تعيين الكمية المعدودة وتسجيل حركة تسوية لكل فرق */
    public function applyStocktake(Request $request)
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer'],
            'counts' => ['required', 'array'],
            'counts.*' => ['nullable', 'integer', 'min:0'],
        ], [
            'branch_id.required' => __('يجب تحديد الفرع قبل تطبيق الجرد.'),
        ]);

        $branch = \App\Models\Branch::where('business_id', $this->bid())->find($data['branch_id']);
        if (! $branch) {
            return back()->withInput()->withErrors(['branch_id' => __('الفرع المحدد غير صالح.')]);
        }

        $adjusted = 0;
        foreach ($data['counts'] as $productId => $counted) {
            if ($counted === null || $counted === '') {
                continue;
            }
            $product = Product::where('business_id', $this->bid())->find((int) $productId);
            if (! $product) {
                continue;
            }
            $counted = (int) $counted;
            $delta = $counted - (int) $product->quantity;
            if ($delta === 0) {
                continue;
            }
            $product->quantity = $counted;
            $product->save();
            \App\Models\BranchStock::adjust($this->bid(), $branch->id, $product->id, $delta);

            InventoryMovement::create([
                'business_id' => $this->bid(),
                'branch_id' => $branch->id,
                'product_id' => $product->id,
                'product_name' => $product->name,
                'sku' => $product->sku,
                'type' => 'تسوية جرد',
                'quantity' => ($delta >= 0 ? '+' : '') . $delta,
                'employee_name' => auth()->user()->name,
            ]);
            $adjusted++;
        }

        \App\Support\Activity::log('updated', 'جرد فعلي: سوّى ' . $adjusted . ' صنفًا — فرع: ' . $branch->name);

        return redirect()->route('admin.inventory.stocktake')
            ->with('toast', ['msg' => __('تمت تسوية :n صنفًا بعد الجرد', ['n' => $adjusted]), 'type' => 'success']);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'branch_id' => ['required', 'integer'],
            'type' => ['required', 'string', 'max:50'],
            'quantity' => ['required', 'integer'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [
            'branch_id.required' => __('يجب تحديد الفرع قبل أي إضافة أو تعديل على المخزون.'),
        ]);
        $product = Product::where('business_id', $this->bid())->findOrFail($data['product_id']);

        // الفرع يجب أن يخصّ نفس النشاط — وإلا رُفضت الحركة
        $branch = \App\Models\Branch::where('business_id', $this->bid())->find($data['branch_id']);
        if (! $branch) {
            return back()->withInput()->withErrors(['branch_id' => __('الفرع المحدد غير صالح.')]);
        }

        // تعديل الكمية حسب نوع الحركة
        $old = (int) $product->quantity;
        $delta = in_array($data['type'], ['إضافة كمية', 'مرتجع']) ? abs($data['quantity']) : -abs($data['quantity']);
        if ($data['type'] === 'تعديل يدوي') {
            $product->quantity = abs($data['quantity']);
        } else {
            $product->quantity = max(0, $product->quantity + $delta);
        }
        $product->save();
        // مزامنة رصيد الفرع بالفرق الفعلي (يبقى مجموع الفروع = كمية المنتج)
        \App\Models\BranchStock::adjust($this->bid(), $branch->id, $product->id, (int) $product->quantity - $old);

        InventoryMovement::create([
            'business_id' => $this->bid(),
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'sku' => $product->sku,
            'type' => $data['type'],
            'quantity' => ($delta >= 0 ? '+' : '') . $delta,
            'employee_name' => auth()->user()->name,
        ]);
        \App\Support\Activity::log('updated', 'حركة مخزون (' . $data['type'] . ') على: ' . $product->name . ' — فرع: ' . $branch->name, ['subject_id' => $product->id]);

        return redirect()->route('admin.inventory.movements')->with('toast', ['msg' => __('تم تسجيل حركة المخزون'), 'type' => 'success']);
    }
}
