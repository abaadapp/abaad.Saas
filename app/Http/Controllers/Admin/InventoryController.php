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

        /*
         * نفاذٌ في فرعٍ وبضاعةٌ في غيره — الحالة التي لم يكن أحد يراها.
         *
         * الأرقام أعلاه كلّها إجماليّ الشركة، والبيع يقرأ رصيد الفرع. فصنفٌ
         * رصيده صفرٌ في صلالة وخمسون في مسقط لا يظهر «نافدًا» ولا «منخفضًا»
         * ولا في قائمة إعادة الطلب — والكاشير في صلالة لا يستطيع بيعه.
         * وعلاجُه ليس شراءً بل تحويلًا، فوجهة التنبيه شاشة التحويل.
         */
        $books = \App\Models\BranchStock::books($bid);
        $branchCount = \App\Models\Branch::where('business_id', $bid)->count();
        $starved = 0;
        if ($branchCount > 1) {
            foreach ($products as $p) {
                if ((int) $p->quantity <= 0) {
                    continue; // نافدٌ في الشركة كلّها — تنبيهه أعلاه لا هنا
                }
                $rows = $books[$p->id] ?? [];
                if (count($rows) > 0 && min($rows) <= 0) {
                    $starved++;
                }
            }
        }

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
                $starved > 0 ? [
                    'key' => 'starved',
                    'label' => 'أصناف نفدت في فرع وهي متوفّرة في غيره',
                    'count' => $starved,
                    'tone' => 'warning',
                    'href' => route('admin.inventory.transfer'),
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

    /**
     * الأصناف التي تحتاج إعادة طلب — بالقاعدة نفسها التي تلوّن الحالة.
     *
     * كانت هنا `≤` وفي `Product::statusFor` `<`، فصنفٌ كميّته تساوي الحدّ
     * بالضبط يُقرأ «متوفر» في جدول المخزون ويقف في قائمة «يحتاج طلبًا».
     * حدّان لمعنًى واحد، والتاجر يصدّق أيّهما رأى أوّلًا.
     */
    public function reorder()
    {
        $items = collect(Demo::inventory())
            ->filter(fn ($i) => Product::statusFor((int) $i['qty'], (int) $i['min']) !== 'متوفر')
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
        $shortage = 0.0;
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
            if ($delta < 0) {
                $shortage += abs($delta) * (float) $product->cost;
            }
        }

        /*
         * الفاقد يُقيَّد مصروفًا — وإلا اختفت الخسارة بدل أن تُقاس.
         *
         * كان الجرد يصحّح الرقم ولا يمسّ شيئًا آخر: تجد خمسين قطعةً ناقصة
         * فتُطرح من المخزون، ولا تظهر في الربح ولا في المصروفات. فيقرأ التاجر
         * أرباحًا لم يجنها، ولا يرى كم يكلّفه الفاقد شهريًّا — وهو الرقم الذي
         * يدفعه إلى تغيير شيء.
         *
         * والزيادة لا تُقيَّد إيرادًا: بضاعةٌ ظهرت في العدّ غالبًا خطأُ تسجيلٍ
         * سابق لا ربحٌ جديد، وقيدُها دخلًا يضخّم الأرباح بلا بيعة.
         */
        if ($shortage > 0) {
            \App\Models\Expense::create([
                'business_id' => $this->bid(),
                // لا عمود فرعٍ في المصروفات — فالفرع في الوصف ليُقرأ في التقرير
                'reference' => 'SHR-'.now()->format('YmdHis'),
                'type' => 'فاقد جرد',
                'description' => __('فاقد جرد — :branch (:n صنفًا)', ['branch' => $branch->name, 'n' => $adjusted]),
                'amount' => round($shortage, 3),
                'method' => 'قيد داخلي',
                'employee_name' => auth()->user()->name,
                'spent_at' => now(),
            ]);
        }

        \App\Support\Activity::log('updated', 'جرد فعلي: سوّى ' . $adjusted . ' صنفًا — فرع: ' . $branch->name);

        return redirect()->route('admin.inventory.stocktake')
            ->with('toast', ['msg' => __('تمت تسوية :n صنفًا بعد الجرد', ['n' => $adjusted]), 'type' => 'success']);
    }

    /**
     * تحويل بضاعةٍ بين فرعين — حركةٌ واحدة لا حركتان.
     *
     * كان السبيل الوحيد «صرف» من فرعٍ ثم «إضافة» في آخر. فمن نسي الثانية
     * اختفت بضاعته من النظام، ومن أتمّهما قرأ السجلُّ فعلَه تلفًا ثم مكسبًا
     * — فتظهر في التقارير خسارةٌ ثم ربحٌ لم يقعا. والتحويل لا يغيّر إجمالي
     * الشركة بشيء: ينتقل الرقم من عمودٍ إلى عمود.
     */
    public function transfer()
    {
        return \Inertia\Inertia::render('Admin/Inventory/Transfer', [
            'items' => Demo::inventory(),
            'branches' => Demo::branches(),
            'books' => \App\Models\BranchStock::books($this->bid()),
        ]);
    }

    public function applyTransfer(Request $request)
    {
        $data = $request->validate([
            'from_branch_id' => ['required', 'integer', 'different:to_branch_id'],
            'to_branch_id' => ['required', 'integer'],
            'product_id' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:1'],
        ], [
            'from_branch_id.different' => __('لا يُحوَّل الصنف إلى الفرع نفسه.'),
        ]);

        $branches = \App\Models\Branch::where('business_id', $this->bid())
            ->whereIn('id', [$data['from_branch_id'], $data['to_branch_id']])
            ->pluck('name', 'id');

        if ($branches->count() !== 2) {
            return back()->withInput()->withErrors(['from_branch_id' => __('الفرع المحدد غير صالح.')]);
        }

        $product = Product::where('business_id', $this->bid())->findOrFail($data['product_id']);
        $qty = (int) $data['quantity'];

        \App\Models\BranchStock::ensureAllocated($this->bid(), $product->id, (int) $product->quantity);
        $available = \App\Models\BranchStock::bookOf($this->bid(), $product->id, (int) $data['from_branch_id']);

        // لا يُحوَّل ما ليس هناك — وإلا صار الفرع المُرسِل سالبًا
        if ($qty > $available) {
            return back()->withInput()->withErrors([
                'quantity' => __('رصيد :branch من هذا الصنف :n فقط.', [
                    'branch' => $branches[$data['from_branch_id']], 'n' => $available,
                ]),
            ]);
        }

        \App\Models\BranchStock::adjust($this->bid(), (int) $data['from_branch_id'], $product->id, -$qty);
        \App\Models\BranchStock::adjust($this->bid(), (int) $data['to_branch_id'], $product->id, $qty);
        // الإجمالي لا يُمَسّ: البضاعة لم تدخل ولم تخرج، انتقلت

        $note = $branches[$data['from_branch_id']].' ← '.$branches[$data['to_branch_id']];
        foreach ([[$data['from_branch_id'], -$qty], [$data['to_branch_id'], $qty]] as [$branchId, $signed]) {
            InventoryMovement::create([
                'business_id' => $this->bid(),
                'branch_id' => $branchId,
                'product_id' => $product->id,
                'product_name' => $product->name,
                'sku' => $product->sku,
                // نوعٌ خاصّ به: التقارير تميّز الانتقال من الفقد والمكسب
                'type' => 'تحويل بين الفروع',
                'quantity' => ($signed >= 0 ? '+' : '').$signed,
                'employee_name' => auth()->user()->name,
                'note' => $note,
            ]);
        }

        \App\Support\Activity::log('updated', 'حوّل '.$qty.' من '.$product->name.' — '.$note, [
            'subject_id' => $product->id,
        ]);

        return back()->with('toast', [
            'msg' => __('تم تحويل :n من :name — :route', ['n' => $qty, 'name' => $product->name, 'route' => $note]),
            'type' => 'success',
        ]);
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

        /*
         * كل حركةٍ تقع على فرع، فالحساب كلّه من رصيد ذلك الفرع.
         *
         * «تعديل يدوي» كان يكتب الرقم المُدخَل في إجمالي الشركة ثم يدفع الفرق
         * كلّه إلى الفرع — وهو عطب الجرد نفسه بالحرف: متجرٌ بفرعين عشرةً
         * وخمسة، يُعدَّل مسقط إلى عشرة فيصير الإجمالي عشرة ورصيد مسقط خمسة.
         * والآن يُقاس الفرق من رصيد الفرع، ويتحرّك الإجمالي بالفرق نفسه.
         */
        $old = (int) $product->quantity;
        \App\Models\BranchStock::ensureAllocated($this->bid(), $product->id, $old);
        $book = \App\Models\BranchStock::bookOf($this->bid(), $product->id, $branch->id);

        $delta = match ($data['type']) {
            'تعديل يدوي' => abs($data['quantity']) - $book,
            'إضافة كمية', 'مرتجع' => abs($data['quantity']),
            default => -abs($data['quantity']),
        };

        /*
         * ولا يُصرف من فرعٍ أكثر ممّا فيه.
         *
         * الإجمالي كان محميًّا بـmax(0,…) ورصيدُ الفرع مكشوفًا، فصرفُ عشرين من
         * فرعٍ فيه عشرة يتركه سالبًا بخمسة — رقمٌ لا وجود له في الواقع، تقرؤه
         * التقارير وتطرحه من قيمة المخزون.
         */
        if ($delta < 0 && $book + $delta < 0) {
            return back()->withInput()->withErrors([
                'quantity' => __('رصيد :branch من هذا الصنف :n فقط — لا يمكن صرف أكثر منه.', [
                    'branch' => $branch->name, 'n' => $book,
                ]),
            ]);
        }

        if ($delta === 0) {
            return back()->with('toast', ['msg' => __('لا فرق — لم تتغيّر الكمية'), 'type' => 'info']);
        }

        \App\Models\BranchStock::adjust($this->bid(), $branch->id, $product->id, $delta);
        $product->quantity = max(0, $old + $delta);
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

        return redirect()->route('admin.inventory.movements')->with('toast', ['msg' => __('تم تسجيل حركة المخزون'), 'type' => 'success']);
    }
}
