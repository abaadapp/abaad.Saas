<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Support\Demo;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    private function bid(): int { return auth()->user()->business_id ?? Demo::bid(); }

    /**
     * تغذية كميات لجداول اللوحة.
     *
     * كانت بطاقة «منتجات منخفضة المخزون» تتحدّث كل 15 ثانية بينما جدول
     * المنتجات تحتها مجمَّد على لقطة لحظة الفتح — فيقرأ التاجر «6 منتجات
     * منخفضة» وجدولًا يقول إن كل شيء متوفر.
     *
     * بإجمالي الشركة لا برصيد فرع، لأن هذا ما تعرضه هذه الجداول أصلًا.
     * تغذيةٌ تقيس غير ما عُرض تجعل الرقم يقفز بلا سبب ظاهر.
     */
    public function stockFeed()
    {
        $products = \App\Models\Product::where('business_id', $this->bid())
            ->orderBy('id')->get(['id', 'quantity', 'alert_qty'])
            ->map(fn ($p) => [
                'id' => $p->id,
                'qty' => (int) $p->quantity,
                'stock_status' => $p->stock_status,
            ])->values();

        return response()->json([
            'products' => $products,
            'updated_at' => now()->format('H:i:s'),
        ]);
    }

    /** مصدر واحد لتحديد الفرع — كان مكرّرًا هنا وفي PosController بصياغتين */
    private function defaultBranchId(): ?int
    {
        return Demo::activeBranchId();
    }

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

        return \Inertia\Inertia::render('Admin/Products/Index', [
            'products' => $products->items(),
            // الترقيم يبقى خادميًا: DataTable في وضعه الخادمي يقرأ هذه الحقول
            'pagination' => \App\Support\Pagination::meta($products),
            'categories' => Demo::categories(),
            'filters' => $request->only('q', 'category', 'status', 'stock'),
            // لاستيراد ملف: الكميات المستوردة يجب أن تُودَع في فرع محدّد،
            // وإلا اختلّ التوازن «مجموع الفروع = كمية المنتج»
            'branches' => \App\Models\Branch::where('business_id', $this->bid())
                ->orderBy('id')->get(['id', 'name']),
            'currentBranchId' => Demo::activeBranchId(),
            // زرّ التراجع لا يظهر إلا حين يكون له ما يتراجع عنه
            'lastImport' => \App\Models\ImportBatch::lastUndoable($this->bid())?->only(
                ['file', 'added', 'updated', 'created_at'],
            ),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
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
        \App\Support\PlanLimits::enforce(auth()->user()->business, 'products');
        // القيم الرقمية الفارغة → افتراضياتها (الأعمدة NOT NULL؛ الفراغ يُحوَّل إلى null فيفشل)
        $data['cost'] = $data['cost'] ?? 0;
        $data['quantity'] = $data['quantity'] ?? 0;
        $data['alert_qty'] = $data['alert_qty'] ?? 10;
        $data['tax'] = $data['tax'] ?? 0;
        $data['discount'] = $data['discount'] ?? 0;
        // توليد رمز المنتج والباركود تلقائيًا إن تُركا فارغين
        $data['sku'] = ! empty($data['sku']) ? $data['sku'] : $this->generateSku();
        $data['barcode'] = ! empty($data['barcode']) ? $data['barcode'] : $this->generateBarcode();
        $data['active'] = $request->boolean('active', true);
        $data['image'] = $request->hasFile('image')
            ? $request->file('image')->store('products', 'public')
            : Demo::image('prod' . uniqid());
        $product = Product::create($data);
        // إسناد الكمية الافتتاحية إلى الفرع الحالي/الأول ليبقى مجموع الفروع = كمية المنتج
        \App\Models\BranchStock::adjust($this->bid(), $this->defaultBranchId(), $product->id, (int) ($data['quantity'] ?? 0));
        \App\Support\Activity::log('created', 'أضاف منتجًا: ' . $data['name']);

        /*
         * الإضافة تنتهي فيُخرَج منها، خلافًا للتعديل الذي يبقى في مكانه.
         *
         * والقائمة هي الشاهد: المنتج الجديد يظهر فيها، فالإشعار يقول «تمّ»
         * والعين ترى الصفّ. أمّا البقاء في نموذجٍ فارغ فيترك الإشعار وحده
         * دليلًا، ولا يُرى المنتج إلا بخطوةٍ أخرى.
         */
        return redirect()->route('admin.products.index')->with('toast', ['msg' => __('تم إضافة المنتج بنجاح'), 'type' => 'success']);
    }

    public function update(Request $request, $id)
    {
        $product = Product::where('business_id', $this->bid())->findOrFail($id);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
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
        $data['name_en'] = $data['name_en'] ?? null;
        $data['active'] = $request->boolean('active', true);
        // القيم الرقمية الفارغة → افتراضياتها (الأعمدة NOT NULL)
        $data['cost'] = $data['cost'] ?? 0;
        $data['quantity'] = $data['quantity'] ?? 0;
        $data['alert_qty'] = $data['alert_qty'] ?? 10;
        $data['tax'] = $data['tax'] ?? 0;
        $data['discount'] = $data['discount'] ?? 0;
        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image')->store('products', 'public');
        } else {
            unset($data['image']);
        }
        $oldQty = (int) $product->quantity;
        \App\Models\BranchStock::ensureAllocated($this->bid(), $product->id, $oldQty);
        $product->update($data);
        // مزامنة رصيد الفرع بفارق الكمية إن عُدّلت يدويًا من نموذج المنتج
        \App\Models\BranchStock::adjust($this->bid(), $this->defaultBranchId(), $product->id, (int) $product->quantity - $oldQty);
        \App\Support\Activity::log('updated', 'عدّل المنتج: ' . $product->name, ['subject_id' => $product->id]);

        // ويبقى في صفحة التعديل كذلك: من يصحّح سعرًا يريد أن يرى أنه ثبت،
        // وغالبًا يتبعه بتعديل الكمية أو الصورة في القسم المجاور
        return redirect()->route('admin.products.edit', $product->id)->with('toast', ['msg' => __('تم تحديث المنتج بنجاح'), 'type' => 'success']);
    }

    public function destroy($id)
    {
        $product = Product::where('business_id', $this->bid())->findOrFail($id);
        \App\Support\Activity::log('deleted', 'حذف المنتج: ' . $product->name, ['subject_id' => $product->id]);
        $product->delete();

        /*
         * «تراجع» في الإشعار نفسه، لا في شاشةٍ يبحث عنها.
         *
         * سلّة المحذوفات تحرس البيانات لكنها مدفونة في الإعدادات: من حذف
         * منتجًا بالخطأ لا يعرف أنها موجودة، فيتّصل بالدعم. والزرّ هنا يردّ
         * الخطأ في اللحظة التي وقع فيها.
         */
        return redirect()->route('admin.products.index')->with('toast', [
            'msg' => __('تم حذف المنتج'),
            'type' => 'success',
            'undo' => ['url' => route('admin.products.restore', $product->id), 'label' => $product->name],
        ]);
    }
}
