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

        return redirect()->route('admin.inventory.index')->with('toast', ['msg' => __('تم تسجيل حركة المخزون'), 'type' => 'success']);
    }
}
