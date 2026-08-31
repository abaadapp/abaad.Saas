<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Support\Demo;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    /**
     * ما يُرتَّب في قائمة المنتجات — مفتاح العمود في الواجهة → عمود القاعدة.
     *
     * والقسم ليس منها: اسمه في جدولٍ آخر، وترتيبه يلزمه ضمٌّ يُثقل استعلامًا
     * يُقرأ في كل فتحة. والهامش كذلك — يُحسب في الواجهة من السعر والتكلفة،
     * وحسابُه في القاعدة يُكرّر معادلته في مكانين تفترقان.
     */
    private const SORTS = [
        'name' => 'name',
        'price' => 'price',
        'cost' => 'cost',
        'qty' => 'quantity',
        'active' => 'active',
    ];

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

        // القاعدة نفسها التي يقرأ بها الملفّ — انظر App\Support\ListFilters
        \App\Support\ListFilters::products($q, $request);

        /*
         * الأحدث أوّلًا — كما في كلّ قائمةٍ أخرى في اللوحة.
         *
         * كانت تصعد بالمعرّف وحدها من بين قوائم اللوحة كلّها، والصفحة اثنا
         * عشر صنفًا: فمتجرٌ فيه مئةٌ وعشرون صنفًا يضع الصنف المضاف حديثًا في
         * الصفحة العاشرة، والتاجر يُعاد بعد الحفظ إلى الأولى. فيرى الاثني عشر
         * نفسها ويحسب أن شيئًا لم يُحفظ — فيضيفه ثانيةً وثالثة.
         *
         * ولا يظهر هذا في متجرٍ جديد: صنفٌ واحد في صفحةٍ واحدة يُرى صاعدًا
         * ونازلًا. يظهر بعد الصنف الثالث عشر، أي بعد أن يصير المتجر متجرًا.
         */
        \App\Support\Sort::apply($q, $request, self::SORTS, fn ($w) => $w->orderByDesc('id'));


        $products = $q->paginate(12)->withQueryString()->through(fn ($p) => [
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
            'filters' => $request->only('q', 'category', 'status', 'stock')
                + \App\Support\Sort::params($request, self::SORTS),
            'sorts' => \App\Support\Sort::keys(self::SORTS),
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
            /*
             * الرمز والباركود فريدان داخل المتجر.
             *
             * صنفان بباركودٍ واحد يجعلان الماسح يختار أحدهما — فيُخصم من
             * صنفٍ ويبقى الآخر على الرفّ، ويظهر الفرق في الجرد بلا سبب.
             * والقيد في التحقّق لا في القاعدة: متاجر قائمة قد تحمل تكرارًا
             * اليوم، وقيدٌ في القاعدة يُسقط الهجرة على الإنتاج بدل أن يمنع
             * الخطأ القادم.
             */
            'sku' => ['nullable', 'string', 'max:100', Rule::unique('products', 'sku')->where('business_id', $this->bid())->whereNull('deleted_at')],
            'barcode' => ['nullable', 'string', 'max:100', Rule::unique('products', 'barcode')->where('business_id', $this->bid())->whereNull('deleted_at')],
            'price' => ['required', 'numeric', 'min:0'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'quantity' => ['nullable', 'integer', 'min:0'],
            'alert_qty' => ['nullable', 'integer', 'min:0'],
            // نسبتان لا مبلغان: خصمٌ فوق المئة يجعل سطر الفاتورة سالبًا،
            // وضريبةٌ ٩٠٠٪ تُخرج فاتورةً بعشرة أضعاف ثمنها. كانا يُقبلان
            'tax' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'discount' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'image' => ['nullable', 'image', 'max:4096'],
            // التركيب يُملأ مع المنتج لا بعده — انظر ProductCompositionController::draftRules
        ] + ProductCompositionController::draftRules($this->bid()),
            ProductCompositionController::draftMessages());

        // مسوّدةُ التركيب ليست عمودًا في products؛ تُنحّى قبل الإنشاء وتُكتب بعده
        $composition = $data['composition'] ?? [];
        unset($data['composition']);

        $data['business_id'] = $this->bid();
        \App\Support\PlanLimits::enforce(auth()->user()->business, 'products');
        // القيم الرقمية الفارغة → افتراضياتها (الأعمدة NOT NULL؛ الفراغ يُحوَّل إلى null فيفشل)
        $data['cost'] = $data['cost'] ?? 0;
        $data['quantity'] = $data['quantity'] ?? 0;
        $data['alert_qty'] = $data['alert_qty'] ?? 10;
        // الضريبة الفارغة تبقى فارغة: «اتبع نسبة المتجر» لا «صفر»
        $data['tax'] = ($data['tax'] ?? '') === '' ? null : $data['tax'];
        $data['discount'] = $data['discount'] ?? 0;
        // توليد رمز المنتج والباركود تلقائيًا إن تُركا فارغين
        $data['sku'] = ! empty($data['sku']) ? $data['sku'] : $this->generateSku();
        $data['barcode'] = ! empty($data['barcode']) ? $data['barcode'] : $this->generateBarcode();
        $data['active'] = $request->boolean('active', true);
        // اسمٌ إنجليزيّ من المعجم إن لم يُكتب بيد — انظر Lexicon
        $data = \App\Support\Lexicon::fill($data);
        $data['image'] = $request->hasFile('image')
            ? $request->file('image')->store('products', 'public')
            : Demo::image('prod' . uniqid());
        $product = Product::create($data);
        // إسناد الكمية الافتتاحية إلى الفرع الحالي/الأول ليبقى مجموع الفروع = كمية المنتج
        \App\Models\BranchStock::adjust($this->bid(), $this->defaultBranchId(), $product->id, (int) ($data['quantity'] ?? 0));
        \App\Support\Activity::log('created', 'أضاف منتجًا: ' . $data['name']);

        // المقاسات والوصفة والإضافات التي كُتبت في نفس الشاشة — بعد أن صار
        // للمنتج معرّفٌ تُعلَّق به
        if ($composition) {
            ProductCompositionController::applyDraft($product, $composition);
        }

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
            /*
             * الرمز والباركود فريدان داخل المتجر.
             *
             * صنفان بباركودٍ واحد يجعلان الماسح يختار أحدهما — فيُخصم من
             * صنفٍ ويبقى الآخر على الرفّ، ويظهر الفرق في الجرد بلا سبب.
             * والقيد في التحقّق لا في القاعدة: متاجر قائمة قد تحمل تكرارًا
             * اليوم، وقيدٌ في القاعدة يُسقط الهجرة على الإنتاج بدل أن يمنع
             * الخطأ القادم.
             */
            'sku' => ['nullable', 'string', 'max:100', Rule::unique('products', 'sku')->where('business_id', $this->bid())->whereNull('deleted_at')->ignore($product->id)],
            'barcode' => ['nullable', 'string', 'max:100', Rule::unique('products', 'barcode')->where('business_id', $this->bid())->whereNull('deleted_at')->ignore($product->id)],
            'price' => ['required', 'numeric', 'min:0'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'quantity' => ['nullable', 'integer', 'min:0'],
            'alert_qty' => ['nullable', 'integer', 'min:0'],
            // نسبتان لا مبلغان: خصمٌ فوق المئة يجعل سطر الفاتورة سالبًا،
            // وضريبةٌ ٩٠٠٪ تُخرج فاتورةً بعشرة أضعاف ثمنها. كانا يُقبلان
            'tax' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'discount' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'image' => ['nullable', 'image', 'max:4096'],
        ]);
        $data['name_en'] = $data['name_en'] ?? null;
        $data['active'] = $request->boolean('active', true);
        // اسمٌ إنجليزيّ من المعجم إن لم يُكتب بيد — انظر Lexicon
        $data = \App\Support\Lexicon::fill($data);
        // القيم الرقمية الفارغة → افتراضياتها (الأعمدة NOT NULL)
        $data['cost'] = $data['cost'] ?? 0;
        $data['quantity'] = $data['quantity'] ?? 0;
        $data['alert_qty'] = $data['alert_qty'] ?? 10;
        // الضريبة الفارغة تبقى فارغة: «اتبع نسبة المتجر» لا «صفر»
        $data['tax'] = ($data['tax'] ?? '') === '' ? null : $data['tax'];
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

    /**
     * نسخ منتج.
     *
     * المتجر فيه قميصٌ بأربعة مقاسات: كانت العشرة حقول تُدخَل أربع مرّات.
     * والنسخة تُنشأ بكميةٍ صفر لا بكمية أصلها — نسخُ الرصيد يخلق بضاعةً لا
     * وجود لها على الرفّ، وهو أسوأ من حقلٍ يُملأ باليد.
     */
    public function duplicate($id)
    {
        $source = Product::where('business_id', $this->bid())->findOrFail($id);
        \App\Support\PlanLimits::enforce(auth()->user()->business, 'products');

        $copy = $source->replicate(['sku', 'barcode', 'quantity', 'created_at', 'updated_at']);
        $copy->name = $source->name.' — '.__('نسخة');
        $copy->sku = $this->generateSku();
        $copy->barcode = $this->generateBarcode();
        $copy->quantity = 0;
        $copy->save();

        \App\Support\Activity::log('created', 'نسخ المنتج: '.$source->name, ['subject_id' => $copy->id]);

        return redirect()->route('admin.products.edit', $copy->id)
            ->with('toast', ['msg' => __('نُسخ المنتج — عدّل اسمه وكميته'), 'type' => 'success']);
    }

    /**
     * تعديل السعر أو الكمية من الصفّ مباشرة.
     *
     * جردُ عشرين صنفًا كان أربعين نقرة: فتح، تعديل، حفظ، رجوع — لكلٍّ منها.
     */
    public function quickUpdate(Request $request, $id)
    {
        $product = Product::where('business_id', $this->bid())->findOrFail($id);

        $data = $request->validate([
            'price' => ['nullable', 'numeric', 'min:0'],
            'quantity' => ['nullable', 'integer', 'min:0'],
        ]);

        if (array_key_exists('price', $data) && $data['price'] !== null) {
            $product->price = $data['price'];
        }

        if (array_key_exists('quantity', $data) && $data['quantity'] !== null) {
            // الفارق يذهب إلى الفرع الحالي كما في نموذج المنتج، فيبقى
            // «مجموع الفروع = كمية المنتج»
            $old = (int) $product->quantity;
            \App\Models\BranchStock::ensureAllocated($this->bid(), $product->id, $old);
            $product->quantity = (int) $data['quantity'];
            \App\Models\BranchStock::adjust($this->bid(), $this->defaultBranchId(), $product->id, (int) $data['quantity'] - $old);
        }

        $product->save();
        \App\Support\Activity::log('updated', 'عدّل سريعًا: '.$product->name, ['subject_id' => $product->id]);

        return back()->with('toast', ['msg' => __('حُفظ'), 'type' => 'success']);
    }

    /**
     * إجراء على المحدَّد: تفعيل، تعطيل، نقل إلى قسم، تغيير الأسعار بنسبة، حذف.
     *
     * رفعُ أسعار قسمٍ خمسةً بالمئة كان يعني فتح كل صنفٍ على حدة.
     */
    public function bulk(Request $request)
    {
        $data = $request->validate([
            'action' => ['required', 'in:activate,deactivate,category,price,delete'],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
            'category_id' => ['nullable', 'integer'],
            // ±٩٠٪ سقفٌ يمنع الغلطة المطبعية: «٥٠٠» بدل «٥» تمسح تسعيرة متجر
            'percent' => ['nullable', 'numeric', 'min:-90', 'max:900'],
        ]);

        $query = Product::where('business_id', $this->bid())->whereIn('id', $data['ids']);
        $count = 0;

        switch ($data['action']) {
            case 'activate':
            case 'deactivate':
                $count = $query->update(['active' => $data['action'] === 'activate']);
                break;

            case 'category':
                $categoryId = $data['category_id'] ?? null;
                if ($categoryId && ! \App\Models\Category::where('business_id', $this->bid())->whereKey($categoryId)->exists()) {
                    return back()->with('toast', ['msg' => __('قسم غير معروف'), 'type' => 'error']);
                }
                $count = $query->update(['category_id' => $categoryId]);
                break;

            case 'price':
                $percent = (float) ($data['percent'] ?? 0);
                foreach ($query->get() as $p) {
                    // القيمة تُحسب في PHP لا في SQL: التقريب إلى ثلاث خانات
                    // يجب أن يطابق ما يعرضه الجدول، وضربُ decimal في المحرّك
                    // يترك ٩٫٩٩٩٩٩٩٩ في العمود
                    $p->update(['price' => max(0, round((float) $p->price * (1 + $percent / 100), 3))]);
                    $count++;
                }
                break;

            case 'delete':
                foreach ($query->get() as $p) {
                    $p->delete();   // إلى السلة لا إعدامًا — تُستعاد من المحذوفات
                    $count++;
                }
                break;
        }

        \App\Support\Activity::log('updated', "إجراء جماعي ({$data['action']}) على {$count} منتجًا");

        return back()->with('toast', ['msg' => __('طُبّق على :n منتجًا', ['n' => $count]), 'type' => 'success']);
    }

    public function destroy($id)
    {
        $product = Product::where('business_id', $this->bid())->findOrFail($id);
        \App\Support\Activity::log('deleted', 'حذف المنتج: ' . $product->name, ['subject_id' => $product->id, 'subject_type' => 'product']);
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
