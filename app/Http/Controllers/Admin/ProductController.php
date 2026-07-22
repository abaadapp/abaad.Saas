<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Support\Demo;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    private function bid(): int { return auth()->user()->business_id ?? Demo::bid(); }

    /** رمز منتج تلقائي فريد داخل النشاط: FLW-#### */
    private function generateSku(): string
    {
        do {
            $sku = 'FLW-' . str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT);
        } while (Product::where('business_id', $this->bid())->where('sku', $sku)->exists());

        return $sku;
    }

    /** باركود تلقائي فريد (يشبه EAN-13): 628 + ١٠ أرقام */
    private function generateBarcode(): string
    {
        do {
            $barcode = '628' . str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);
        } while (Product::where('business_id', $this->bid())->where('barcode', $barcode)->exists());

        return $barcode;
    }

    public function index(Request $request)
    {
        $q = Product::where('business_id', $this->bid())->with('category');

        if ($s = trim((string) $request->query('q'))) {
            $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('sku', 'like', "%{$s}%"));
        }
        if ($c = $request->query('category')) {
            $q->whereHas('category', fn ($w) => $w->where('name', $c));
        }
        if (($st = $request->query('status')) !== null && $st !== '') {
            $q->where('active', $st === 'active');
        }
        if ($stock = $request->query('stock')) {
            if ($stock === 'نفد المخزون') { $q->where('quantity', '<=', 0); }
            elseif ($stock === 'منخفض') { $q->whereColumn('quantity', '<', 'alert_qty')->where('quantity', '>', 0); }
            elseif ($stock === 'متوفر') { $q->whereColumn('quantity', '>=', 'alert_qty'); }
        }

        $products = $q->orderBy('id')->paginate(12)->withQueryString()->through(fn ($p) => [
            'id' => $p->id, 'name' => $p->name, 'cat' => $p->category?->name ?? '—',
            'price' => (float) $p->price, 'cost' => (float) $p->cost, 'qty' => $p->quantity,
            'sku' => $p->sku, 'barcode' => $p->barcode, 'image' => $p->image,
            'stock_status' => $p->stock_status, 'active' => (bool) $p->active,
            'alert' => $p->alert_qty, 'tax' => (float) $p->tax, 'discount' => (float) $p->discount,
        ]);

        return view('admin.products.index', ['products' => $products, 'filters' => $request->only('q', 'category', 'status', 'stock')]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'category_id' => ['nullable', 'integer'],
            'sku' => ['nullable', 'string', 'max:100'],
            'barcode' => ['nullable', 'string', 'max:100'],
            'price' => ['required', 'numeric', 'min:0'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'quantity' => ['nullable', 'integer', 'min:0'],
            'alert_qty' => ['nullable', 'integer', 'min:0'],
            'tax' => ['nullable', 'numeric', 'min:0'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'image' => ['nullable', 'image', 'max:4096'],
        ]);
        $data['business_id'] = $this->bid();
        // توليد رمز المنتج والباركود تلقائيًا إن تُركا فارغين
        $data['sku'] = ! empty($data['sku']) ? $data['sku'] : $this->generateSku();
        $data['barcode'] = ! empty($data['barcode']) ? $data['barcode'] : $this->generateBarcode();
        $data['active'] = $request->boolean('active', true);
        $data['image'] = $request->hasFile('image')
            ? $request->file('image')->store('products', 'public')
            : Demo::image('prod' . uniqid());
        Product::create($data);
        \App\Support\Activity::log('created', 'أضاف منتجًا: ' . $data['name']);

        return redirect()->route('admin.products.index')->with('toast', ['msg' => __('تم إضافة المنتج بنجاح'), 'type' => 'success']);
    }

    public function update(Request $request, $id)
    {
        $product = Product::where('business_id', $this->bid())->findOrFail($id);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'category_id' => ['nullable', 'integer'],
            'sku' => ['nullable', 'string', 'max:100'],
            'barcode' => ['nullable', 'string', 'max:100'],
            'price' => ['required', 'numeric', 'min:0'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'quantity' => ['nullable', 'integer', 'min:0'],
            'alert_qty' => ['nullable', 'integer', 'min:0'],
            'tax' => ['nullable', 'numeric', 'min:0'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'image' => ['nullable', 'image', 'max:4096'],
        ]);
        $data['active'] = $request->boolean('active', true);
        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image')->store('products', 'public');
        } else {
            unset($data['image']);
        }
        $product->update($data);
        \App\Support\Activity::log('updated', 'عدّل المنتج: ' . $product->name, ['subject_id' => $product->id]);

        return redirect()->route('admin.products.index')->with('toast', ['msg' => __('تم تحديث المنتج بنجاح'), 'type' => 'success']);
    }

    public function destroy($id)
    {
        $product = Product::where('business_id', $this->bid())->findOrFail($id);
        \App\Support\Activity::log('deleted', 'حذف المنتج: ' . $product->name, ['subject_id' => $product->id]);
        $product->delete();

        return redirect()->route('admin.products.index')->with('toast', ['msg' => __('تم حذف المنتج'), 'type' => 'success']);
    }
}
