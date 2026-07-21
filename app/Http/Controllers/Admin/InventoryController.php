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

    public function store(Request $request)
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'branch_id' => ['required', 'integer'],
            'type' => ['required', 'string', 'max:50'],
            'quantity' => ['required', 'integer'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [
            'branch_id.required' => 'يجب تحديد الفرع قبل أي إضافة أو تعديل على المخزون.',
        ]);
        $product = Product::where('business_id', $this->bid())->findOrFail($data['product_id']);

        // الفرع يجب أن يخصّ نفس النشاط — وإلا رُفضت الحركة
        $branch = \App\Models\Branch::where('business_id', $this->bid())->find($data['branch_id']);
        if (! $branch) {
            return back()->withInput()->withErrors(['branch_id' => 'الفرع المحدد غير صالح.']);
        }

        // تعديل الكمية حسب نوع الحركة
        $delta = in_array($data['type'], ['إضافة كمية', 'مرتجع']) ? abs($data['quantity']) : -abs($data['quantity']);
        if ($data['type'] === 'تعديل يدوي') {
            $product->quantity = abs($data['quantity']);
        } else {
            $product->quantity = max(0, $product->quantity + $delta);
        }
        $product->save();

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

        return redirect()->route('admin.inventory.movements')->with('toast', ['msg' => 'تم تسجيل حركة المخزون', 'type' => 'success']);
    }
}
