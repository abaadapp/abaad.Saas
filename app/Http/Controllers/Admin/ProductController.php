<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BranchStock;
use App\Models\ImportBatch;
use App\Models\Product;
use App\Support\Activity;
use App\Support\Demo;
use App\Support\Lexicon;
use App\Support\ListFilters;
use App\Support\Pagination;
use App\Support\PlanLimits;
use App\Support\Sort;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

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

    private function bid(): int
    {
        return auth()->user()->business_id ?? Demo::bid();
    }

    /**
     * القسم من أقسام هذا المتجر — لا من قائمةٍ مفتوحة.
     *
     * كان الحارس في الإجراء الجماعي وحده: `bulk` تردّ «قسم غير معروف»،
     * وإضافةُ منتجٍ واحد وتعديلُه يقبلان أيّ رقم. فقسمُ متجرٍ آخر يُكتب في
     * `category_id` كما وصل، ثمّ يُقرأ اسمُه بالضمّ فيظهر في قائمة المنتجات
     * وفي الملفّات وفي تقرير «المبيعات حسب القسم» — اسمٌ من متجر الجار على
     * شاشة صاحبه. ولا خطأ يُرفع، لأنّ الصفّ صحيحٌ في نفسه.
     *
     * وموضعٌ واحد للقاعدة كي لا يفترق البابان ثانيةً.
     */
    private function categoryRule(): array
    {
        return ['nullable', 'integer', Rule::exists('categories', 'id')->where('business_id', $this->bid())];
    }

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
        $products = Product::where('business_id', $this->bid())
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
            $sku = 'FLW-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT);
        } while (Product::where('business_id', $this->bid())->where('sku', $sku)->exists());

        return $sku;
    }

    /** باركود تلقائي فريد (يشبه EAN-13): 628 + ١٠ أرقام */
    private function generateBarcode(): string
    {
        do {
            $barcode = '628'.str_pad((string) random_int(0, 9999999999), 10, '0', STR_PAD_LEFT);
        } while (Product::where('business_id', $this->bid())->where('barcode', $barcode)->exists());

        return $barcode;
    }

    public function index(Request $request)
    {
        $q = Product::where('business_id', $this->bid())->with('category');

        // القاعدة نفسها التي يقرأ بها الملفّ — انظر App\Support\ListFilters
        ListFilters::products($q, $request);

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
        Sort::apply($q, $request, self::SORTS, fn ($w) => $w->orderByDesc('id'));

        $products = $q->paginate(12)->withQueryString()->through(fn ($p) => [
            'id' => $p->id, 'name' => $p->name, 'cat' => $p->category?->name ?? '—',
            'price' => (float) $p->price, 'cost' => (float) $p->cost, 'qty' => $p->quantity,
            'sku' => $p->sku, 'barcode' => $p->barcode, 'image' => $p->image,
            'stock_status' => $p->stock_status, 'active' => (bool) $p->active,
            'alert' => $p->alert_qty, 'tax' => (float) $p->tax, 'discount' => (float) $p->discount,
        ]);

        return Inertia::render('Admin/Products/Index', [
            'products' => $products->items(),
            // الترقيم يبقى خادميًا: DataTable في وضعه الخادمي يقرأ هذه الحقول
            'pagination' => Pagination::meta($products),
            'categories' => Demo::categories(),
            'filters' => $request->only('q', 'category', 'status', 'stock')
                + Sort::params($request, self::SORTS),
            'sorts' => Sort::keys(self::SORTS),
            // لاستيراد ملف: الكميات المستوردة يجب أن تُودَع في فرع محدّد،
            // وإلا اختلّ التوازن «مجموع الفروع = كمية المنتج»
            'branches' => Branch::where('business_id', $this->bid())
                ->orderBy('id')->get(['id', 'name']),
            'currentBranchId' => Demo::activeBranchId(),
            // زرّ التراجع لا يظهر إلا حين يكون له ما يتراجع عنه
            'lastImport' => ImportBatch::lastUndoable($this->bid())?->only(
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
            'category_id' => $this->categoryRule(),
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
            ['category_id.exists' => __('قسم غير معروف')] + ProductCompositionController::draftMessages());

        // مسوّدةُ التركيب ليست عمودًا في products؛ تُنحّى قبل الإنشاء وتُكتب بعده
        $composition = $data['composition'] ?? [];
        unset($data['composition']);

        $data['business_id'] = $this->bid();
        PlanLimits::enforce(auth()->user()->business, 'products');
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
        /*
         * «يُعرض على الإنترنت» غير «نشِط».
         *
         * والافتراضيّ معروض كافتراض العمود: متاجرُ مفتوحةٌ اليوم تعرض كلّ ما
         * فيها، وصنفٌ جديد يختفي عنها بلا سبب يراه صاحبه أسوأ من ظهوره.
         */
        $data['published'] = $request->boolean('published', true);
        // اسمٌ إنجليزيّ من المعجم إن لم يُكتب بيد — انظر Lexicon
        $data = Lexicon::fill($data);
        $data['image'] = $request->hasFile('image')
            ? $request->file('image')->store('products', 'public')
            : Demo::image('prod'.uniqid());
        $product = Product::create($data);
        // إسناد الكمية الافتتاحية إلى الفرع الحالي/الأول ليبقى مجموع الفروع = كمية المنتج
        BranchStock::adjust($this->bid(), $this->defaultBranchId(), $product->id, (int) ($data['quantity'] ?? 0));
        Activity::log('created', 'أضاف منتجًا: '.$data['name']);

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
            'category_id' => $this->categoryRule(),
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
            /*
             * والصورة ليست من هذا النموذج — بابها مسارُها.
             *
             * كانت تُرفع مع السعر والكمية والوصف في طلبٍ واحد، وهذا النموذج
             * يكتب الكمية **مطلقةً** ويُزيح رصيد الفرع بفارقها. فمن فتح
             * الشاشة ثمّ باع صنفًا على الصندوق ثمّ بدّل الصورة، أعاد الكمية
             * إلى ما كانت عليه قبل البيعة — بضاعةٌ تعود إلى الرفّ لأنّ أحدًا
             * غيّر صورة.
             *
             * فصارت الصور تُدار بطلباتٍ صغيرة لا تمسّ عمودًا آخر: انظر
             * `ProductImageController`. والإنشاء يبقى على حاله — لا معرّض
             * قبل أن يوجد المنتج.
             */
        ], ['category_id.exists' => __('قسم غير معروف')]);
        $data['name_en'] = $data['name_en'] ?? null;
        $data['active'] = $request->boolean('active', true);
        // ولا يُطفأ العرض بحفظةٍ لم تُرسله: نموذجٌ جزئيّ لا يعني «أخفِه»
        if ($request->has('published')) {
            $data['published'] = $request->boolean('published');
        }
        // اسمٌ إنجليزيّ من المعجم إن لم يُكتب بيد — انظر Lexicon
        $data = Lexicon::fill($data);
        // القيم الرقمية الفارغة → افتراضياتها (الأعمدة NOT NULL)
        $data['cost'] = $data['cost'] ?? 0;
        $data['quantity'] = $data['quantity'] ?? 0;
        $data['alert_qty'] = $data['alert_qty'] ?? 10;
        // الضريبة الفارغة تبقى فارغة: «اتبع نسبة المتجر» لا «صفر»
        $data['tax'] = ($data['tax'] ?? '') === '' ? null : $data['tax'];
        $data['discount'] = $data['discount'] ?? 0;
        // والقفل نفسه هنا: النموذج يكتب كميةً مطلقة، وفارقُها يذهب إلى الفرع
        \DB::transaction(function () use ($product, $data) {
            $locked = Product::where('business_id', $this->bid())->lockForUpdate()->findOrFail($product->id);
            $oldQty = (int) $locked->quantity;
            BranchStock::ensureAllocated($this->bid(), $locked->id, $oldQty);
            $locked->update($data);
            // مزامنة رصيد الفرع بفارق الكمية إن عُدّلت يدويًا من نموذج المنتج
            BranchStock::adjust($this->bid(), $this->defaultBranchId(), $locked->id, (int) $locked->quantity - $oldQty);
            $product->setRawAttributes($locked->getAttributes());
        });
        Activity::log('updated', 'عدّل المنتج: '.$product->name, ['subject_id' => $product->id]);

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
        PlanLimits::enforce(auth()->user()->business, 'products');

        $copy = $source->replicate(['sku', 'barcode', 'quantity', 'created_at', 'updated_at']);
        $copy->name = $source->name.' — '.__('نسخة');
        $copy->sku = $this->generateSku();
        $copy->barcode = $this->generateBarcode();
        $copy->quantity = 0;

        /*
         * وتركيبُه معه: المقاسات والوصفة والإضافات.
         *
         * كان يُنسخ الصفّ وحده. فنسخةُ باقةٍ مركّبة تبدو في كل شاشةٍ نسخةً
         * طبق الأصل — الاسم والسعر والتكلفة والقسم — وتسلك سلوكًا آخر
         * تمامًا: تُباع فلا تُنقص من الرفّ ساقًا واحدة، لأنّ وصفتها فارغة.
         * ولا شيء يقول ذلك: التاجر نسخ «بوكيه الحب» ليصنع «بوكيه الحب
         * الكبير»، فصار عنده صنفٌ يبيع ورودًا لا يخصمها أحد، ويُقرأ ربحه
         * كاملًا لأنّ مكوّناته لم تُحتسب قطّ.
         *
         * والوصفة قد تتعلّق بمقاسٍ بعينه، فتُنسخ المقاسات أوّلًا ويُترجَم
         * مرجعُها — وإلّا أشارت وصفةُ النسخة إلى مقاسٍ في الأصل.
         */
        \DB::transaction(function () use ($source, $copy) {
            $copy->save();

            $variantMap = [];
            foreach ($source->variants()->get() as $variant) {
                $newVariant = $variant->replicate(['created_at', 'updated_at']);
                $newVariant->product_id = $copy->id;
                $newVariant->save();
                $variantMap[$variant->id] = $newVariant->id;
            }

            foreach ($source->recipeItems()->get() as $item) {
                $newItem = $item->replicate(['created_at', 'updated_at']);
                $newItem->product_id = $copy->id;
                $newItem->variant_id = $item->variant_id ? ($variantMap[$item->variant_id] ?? null) : null;
                $newItem->save();
            }

            // والجدول الوسيط يحمل `business_id` — فيُكتب صراحةً كما في syncAddons
            $links = \DB::table('product_addons')->where('product_id', $source->id)
                ->orderBy('sort_order')->get(['addon_id', 'sort_order', 'business_id']);

            foreach ($links as $link) {
                \DB::table('product_addons')->insert([
                    'business_id' => $link->business_id,
                    'product_id' => $copy->id,
                    'addon_id' => $link->addon_id,
                    'sort_order' => $link->sort_order,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        Activity::log('created', 'نسخ المنتج: '.$source->name, ['subject_id' => $copy->id]);

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

        /*
         * القراءة والكتابة تحت قفلٍ واحد.
         *
         * الفارق يُحسب من كميةٍ قُرئت قبل الحفظ. وتعديلان سريعان على الصنف
         * نفسه — أو تعديلٌ وبيعةٌ — يقرآن الرقم نفسه، فيصير رصيد الفرع فرقًا
         * عن إجماليٍّ لم يعد صحيحًا: «مجموع الفروع = كمية المنتج» ينكسر بلا
         * أثرٍ في أيّ شاشة.
         */
        \DB::transaction(function () use ($product, $data) {
            $locked = Product::where('business_id', $this->bid())->lockForUpdate()->findOrFail($product->id);

            if (array_key_exists('price', $data) && $data['price'] !== null) {
                $locked->price = $data['price'];
            }

            if (array_key_exists('quantity', $data) && $data['quantity'] !== null) {
                // الفارق يذهب إلى الفرع الحالي كما في نموذج المنتج، فيبقى
                // «مجموع الفروع = كمية المنتج»
                $old = (int) $locked->quantity;
                BranchStock::ensureAllocated($this->bid(), $locked->id, $old);
                $locked->quantity = (int) $data['quantity'];
                BranchStock::adjust($this->bid(), $this->defaultBranchId(), $locked->id, (int) $data['quantity'] - $old);
            }

            $locked->save();
            $product->setRawAttributes($locked->getAttributes());
        });
        Activity::log('updated', 'عدّل سريعًا: '.$product->name, ['subject_id' => $product->id]);

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
            'category_id' => $this->categoryRule(),
            // ±٩٠٪ سقفٌ يمنع الغلطة المطبعية: «٥٠٠» بدل «٥» تمسح تسعيرة متجر
            'percent' => ['nullable', 'numeric', 'min:-90', 'max:900'],
        ], ['category_id.exists' => __('قسم غير معروف')]);

        $query = Product::where('business_id', $this->bid())->whereIn('id', $data['ids']);
        $count = 0;

        switch ($data['action']) {
            case 'activate':
            case 'deactivate':
                $count = $query->update(['active' => $data['action'] === 'activate']);
                break;

            case 'category':
                // القسم مفحوصٌ في التحقّق أعلاه — انظر categoryRule
                $count = $query->update(['category_id' => $data['category_id'] ?? null]);
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
                    /*
                     * ويُقيَّد لكلّ صنفٍ باسمه ونوعه — كما يفعل الحذف المفرد.
                     *
                     * شاشة المحذوفات تقرأ «من حذف» من سجلّ النشاط بالنوع
                     * والمعرّف معًا. والإجراء الجماعي كان يكتب سطرًا واحدًا
                     * بلا موضوع، فيظهر ما حُذف بالعشرات بلا فاعل — وأوّل سؤالٍ
                     * لصاحب متجرٍ فيه موظّفون هو مَن، لا ماذا.
                     */
                    Activity::log('deleted', 'حذف المنتج: '.$p->name, [
                        'subject_id' => $p->id, 'subject_type' => 'product',
                    ]);
                    $p->delete();   // إلى السلة لا إعدامًا — تُستعاد من المحذوفات
                    $count++;
                }
                break;
        }

        Activity::log('updated', "إجراء جماعي ({$data['action']}) على {$count} منتجًا");

        return back()->with('toast', ['msg' => __('طُبّق على :n منتجًا', ['n' => $count]), 'type' => 'success']);
    }

    public function destroy($id)
    {
        $product = Product::where('business_id', $this->bid())->findOrFail($id);
        Activity::log('deleted', 'حذف المنتج: '.$product->name, ['subject_id' => $product->id, 'subject_type' => 'product']);
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
