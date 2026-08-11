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

    /**
     * نظرة عامة على المخزون — أرقام وتنبيهات وآخر الحركات في شاشة واحدة.
     *
     * كل رقم هنا محسوب من الجداول القائمة (products وpurchase_orders
     * وinventory_movements) بلا عمود جديد ولا جدول جديد. ما لا تسنده البنية
     * الحالية — المحجوز وقيد التوريد وجلسات الجرد والتحويلات — غائب عمدًا
     * بدل عرض صفر مضلّل يقرأه التاجر على أنه واقع.
     */
    public function overview()
    {
        $bid = $this->bid();
        $products = Product::where('business_id', $bid)->get();

        $out = $products->filter(fn ($p) => (int) $p->quantity <= 0);
        $low = $products->filter(
            fn ($p) => (int) $p->quantity > 0 && (int) $p->quantity < (int) $p->alert_qty,
        );

        // «المفتوح» = كل ما لم يُستلم ولم يُلغَ
        $openOrders = \App\Models\PurchaseOrder::where('business_id', $bid)
            ->whereNotIn('status', ['مستلم', 'ملغي'])
            ->count();

        return \Inertia\Inertia::render('Admin/Inventory/Overview', [
            'stats' => [
                'value' => round($products->sum(fn ($p) => (float) $p->cost * (int) $p->quantity), 3),
                'inStock' => $products->filter(fn ($p) => (int) $p->quantity > 0)->count(),
                'low' => $low->count(),
                'out' => $out->count(),
                'openOrders' => $openOrders,
            ],
            // كل تنبيه يحمل وجهته وفلترها، فالضغط عليه يصل إلى الصفّ المقصود
            'alerts' => array_values(array_filter([
                $out->isNotEmpty() ? [
                    'key' => 'out',
                    'label' => 'منتجات نفد مخزونها',
                    'count' => $out->count(),
                    'tone' => 'danger',
                    'href' => route('admin.inventory.index', ['stock' => 'نفد المخزون']),
                ] : null,
                $low->isNotEmpty() ? [
                    'key' => 'low',
                    'label' => 'منتجات تحت الحد الأدنى',
                    'count' => $low->count(),
                    'tone' => 'warning',
                    'href' => route('admin.inventory.index', ['stock' => 'منخفض']),
                ] : null,
                $openOrders > 0 ? [
                    'key' => 'orders',
                    'label' => 'أوامر شراء مفتوحة لم تُستلم',
                    'count' => $openOrders,
                    'tone' => 'info',
                    'href' => route('admin.purchases.index'),
                ] : null,
            ])),
            'recent' => array_slice(Demo::movements(), 0, 8),
        ]);
    }

    /** الأصناف التي تحتاج إعادة طلب (الكمية ≤ حد التنبيه) */
    public function reorder()
    {
        $items = collect(Demo::inventory())
            ->filter(fn ($i) => (int) $i['qty'] <= (int) $i['min'])
            ->sortBy('qty')
            ->values()
            ->all();

        return \Inertia\Inertia::render('Admin/Inventory/Reorder', ['items' => $items]);
    }

    /**
     * شاشة الجرد الفعلي — إدخال الكمية المعدودة ومقارنتها بدفتر الفرع.
     *
     * «الدفترية» كانت إجمالي الشركة، والجرد يعدّ فرعًا واحدًا. فمتجرٌ بفرعين
     * — عشرة في مسقط وخمسة في صلالة — يعدّ مسقط فيجدها عشرة كما يجب، فتقول
     * له الشاشة إن الفرق ناقص خمسة. فيذهب يبحث عن بضاعةٍ لم تُفقد.
     *
     * ورصيدُ كل فرعٍ يُرسَل كاملًا لا رصيدُ الفرع المختار وحده: تبديل الفرع
     * في الأعلى يجب أن يقلب الأرقام في مكانها، وطلبٌ جديد لكل تبديلٍ يمحو ما
     * أُدخل من أعدادٍ قبله.
     */
    public function stocktake()
    {
        $books = \App\Models\BranchStock::books($this->bid());

        $items = collect(Demo::inventory())
            ->map(fn (array $i) => $i + ['stock' => $books[$i['id']] ?? []])
            ->all();

        return \Inertia\Inertia::render('Admin/Inventory/Stocktake', [
            'items' => $items,
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

            /*
             * الفرق من دفتر الفرع لا من إجمالي الشركة — والإجمالي يتحرّك
             * بالفرق ولا يصير المعدود.
             *
             * كان يكتب المعدود في الإجمالي، فجردُ فرعٍ يمحو أرصدة بقيّة
             * الفروع: تعدّ مسقط فتضيع صلالة. وجردٌ كامل يمرّ على الفروع
             * واحدًا واحدًا كان ينتهي برصيد آخر فرعٍ في خانة الشركة كلّها.
             */
            $book = \App\Models\BranchStock::bookOf($this->bid(), $product->id, $branch->id);
            $delta = $counted - $book;
            if ($delta === 0) {
                continue;
            }
            \App\Models\BranchStock::ensureAllocated($this->bid(), $product->id, (int) $product->quantity);
            \App\Models\BranchStock::adjust($this->bid(), $branch->id, $product->id, $delta);
            $product->quantity = max(0, (int) $product->quantity + $delta);
            $product->save();

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
        \App\Models\BranchStock::ensureAllocated($this->bid(), $product->id, $old);
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
