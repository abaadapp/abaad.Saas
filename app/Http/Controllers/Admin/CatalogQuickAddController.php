<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Addon;
use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use App\Support\Boutiques;
use App\Support\Demo;
use App\Support\Permissions;
use App\Support\PosAddonsLayout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * قسمٌ أو إضافةٌ تُنشأ من حيث تُحتاج.
 *
 * الأقسام والإضافات لم يكن لهما بابُ إنشاءٍ في النظام إطلاقًا: تأتي من
 * تهيئة نوع النشاط (BusinessTypes) أو من استيراد ملفٍّ فيه أسماء أقسام.
 * فمن أراد قسمًا جديدًا لم يكن أمامه إلّا أن يستورد ملفًّا لأجله — أو أن
 * يبقى يصنّف ورده تحت قسمٍ لا يعنيه.
 *
 * والبابان هنا يردّان JSON لا صفحة: يُنادَيان من جانب حقلٍ في نموذجٍ نصفُه
 * مملوء، وإعادةُ تحميل الصفحة كانت ستمحو ما كُتب ولم يُحفظ بعد.
 *
 * ولا صلاحيةَ جديدة: تحت `/admin/products/` فيقيسهما حارس المسار بصلاحية
 * «المنتجات» — ومن يضيف منتجًا يضيف قسمه.
 */
class CatalogQuickAddController extends Controller
{
    private function bid(): int { return auth()->user()->business_id ?? Demo::bid(); }

    /**
     * قسمٌ جديد — والاسم فريدٌ في النشاط لا في النظام.
     *
     * قسمان بالاسم نفسه يجعلان المنتجات تتوزّع بينهما بلا قاعدة، ويصير
     * تقرير «المبيعات حسب القسم» يعرض «ورود» مرّتين برقمين.
     */
    public function storeCategory(Request $request): JsonResponse
    {
        $bid = $this->bid();

        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('categories', 'name')->where('business_id', $bid),
            ],
            'name_en' => ['nullable', 'string', 'max:100'],
        ], [
            'name.unique' => __('يوجد قسمٌ بهذا الاسم.'),
        ]);

        $category = Category::create(\App\Support\Lexicon::fill($data) + ['business_id' => $bid]);

        \App\Support\Activity::log('created', 'أضاف قسم «'.$category->name.'»', ['subject_id' => $category->id]);

        return response()->json([
            'ok' => true,
            'category' => [
                'id' => $category->id,
                'name' => $category->name,
                'name_en' => $category->name_en,
            ],
        ]);
    }

    /**
     * تسميةُ قسمٍ قائم — بالعربيّة والإنجليزيّة، والقسمُ هو هو.
     *
     * لم يكن للقسم بابُ تعديل: يُنشأ ثمّ لا يُمسّ. فمن كتب اسمه الإنجليزيّ
     * خطأً — أو لم يكتبه — لم يكن أمامه إلّا قسمٌ جديد، فتنقسم أصنافُه بين
     * اسمين. وهنا يُعاد اسمُه في صفّه: المعرّفُ كما هو، والأصنافُ مربوطةٌ به
     * كما كانت، والمرشِّحُ في الموقع بالمعرّف فلا يتبدّل ما تحته.
     *
     * والصفُّ من متجر من سجّل الدخول وإلّا فـ٤٠٤: «ليس لك» و«غير موجود»
     * جوابٌ واحد، فلا يُخمَّن رقمُ قسمِ غيره بالردّ.
     */
    public function updateCategory(Request $request, int $category): JsonResponse
    {
        $bid = $this->bid();
        $model = Category::where('business_id', $bid)->findOrFail($category);

        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('categories', 'name')->where('business_id', $bid)->ignore($model->id),
            ],
            'name_en' => ['nullable', 'string', 'max:100'],
        ], [
            'name.unique' => __('يوجد قسمٌ بهذا الاسم.'),
        ]);

        $model->update(\App\Support\Lexicon::fill([
            'name' => trim((string) $data['name']),
            'name_en' => trim((string) ($data['name_en'] ?? '')) ?: null,
        ]));

        \App\Support\Activity::log('updated', 'عدّل اسم قسم «'.$model->name.'»', ['subject_id' => $model->id]);

        return response()->json([
            'ok' => true,
            'category' => [
                'id' => $model->id,
                'name' => $model->name,
                'name_en' => $model->name_en,
            ],
        ]);
    }

    /**
     * حذفُ قسمٍ لا صنفَ فيه — من المحرّر حيث يراه فارغًا.
     *
     * كانت الأقسامُ تُنشأ ولا تُحذف: «خدمات» و«عروض» من تهيئة النشاط تبقى
     * في قائمته أبدًا وإن لم يضع فيها شيئًا.
     *
     * ═══ ولا يُحذف قسمٌ يشير إليه صنفٌ — أيُّ صنف ═══
     *
     * المطفأُ وغيرُ المنشور والمحذوفُ في السلّة كلُّها أصنافٌ قائمة: حذفُ
     * قسمها يُفرغ عمودَها (`nullOnDelete`) فيعود الصنفُ يومًا بلا قسم. ولا
     * يُقيَّد السؤالُ بالمتجر: صنفُ متجرٍ آخر مربوطٌ بهذا القسم خطأً لا يُمسّ
     * صفُّه من هنا. والصفُّ نفسُه من متجر من سجّل الدخول وإلّا فـ٤٠٤.
     */
    public function destroyCategory(int $category): JsonResponse
    {
        $model = Category::where('business_id', $this->bid())->findOrFail($category);

        if (Product::withTrashed()->where('category_id', $model->id)->exists()) {
            throw ValidationException::withMessages([
                'category' => __('لا تُحذف فئةٌ فيها منتجات — حتّى المطفأة أو غير المنشورة أو المحذوفة. انقلها إلى فئةٍ أخرى أوّلًا.'),
            ]);
        }

        $model->delete();

        \App\Support\Activity::log('deleted', 'حذف قسم «'.$model->name.'»', ['subject_id' => $model->id]);

        return response()->json(['ok' => true, 'id' => $model->id]);
    }

    /**
     * بطاقةُ القسم في «تسوّق حسب الفئة» — تُخفى أو تُكشف.
     *
     * والإخفاءُ للبطاقة وحدها: أصنافُ القسم تبقى معروضةً في المتجر، والقسمُ
     * يبقى يُختار في رفّه. ومن أراد إخفاءَ «الإضافات» من الرئيسيّة لم يكن
     * أمامه إلّا إخفاءُ أصنافها كلِّها — فتغيب عن الطلب أيضًا.
     *
     * والصفُّ من متجر من سجّل الدخول وإلّا فـ٤٠٤ — قبل التحقّق من المدخلات.
     */
    public function categoryCard(Request $request, int $category): JsonResponse
    {
        $model = Category::where('business_id', $this->bid())->findOrFail($category);

        $data = $request->validate(['card_hidden' => ['required', 'boolean']]);

        $model->update(['card_hidden' => (bool) $data['card_hidden']]);

        \App\Support\Activity::log(
            'updated',
            ($model->card_hidden ? 'أخفى' : 'أظهر').' بطاقة قسم «'.$model->name.'» في «تسوّق حسب الفئة»',
            ['subject_id' => $model->id],
        );

        return response()->json(['ok' => true, 'category' => self::card($model)]);
    }

    /**
     * صورةُ بطاقة القسم — تُرفع في مجلّد النشاط كسائر صور المتجر.
     *
     * والقاعدةُ قاعدةُ `MarketingController::uploadStoreImage`: صورةٌ حتّى
     * ٤ ميغابايت، و`image` لا تقبل SVG فلا يُرفع نصٌّ يُنفَّذ في صفحة الزبون.
     */
    public function categoryCardImage(Request $request, int $category): JsonResponse
    {
        $bid = $this->bid();
        $model = Category::where('business_id', $bid)->findOrFail($category);

        $request->validate([
            'image' => ['required', 'image', 'max:4096'],
        ], [
            'image.image' => __('الملفّ صورة — PNG أو JPG أو WEBP'),
            'image.max' => __('أقصى حجمٍ للصورة ٤ ميغابايت'),
        ]);

        // ‏تُقرأ من محتواها وتُدار وتُصغَّر كصور المحرّر — لا تُحفظ كما رُفعت
        $model->update(['card_image' => \App\Support\Store\StoreImageUpload::store($bid, $request->file('image'))['url']]);

        \App\Support\Activity::log('updated', 'وضع صورةً لبطاقة قسم «'.$model->name.'»', ['subject_id' => $model->id]);

        return response()->json(['ok' => true, 'category' => self::card($model)]);
    }

    /**
     * وإزالةُ الصورة تُعيد الخطوطَ الملوّنة — ولا تمسّ الملفّ.
     *
     * نسخةٌ على القرص أرخصُ من صورةٍ ضاعت، كما في `StoreImageField`.
     */
    public function clearCategoryCardImage(int $category): JsonResponse
    {
        $model = Category::where('business_id', $this->bid())->findOrFail($category);

        $model->update(['card_image' => null]);

        \App\Support\Activity::log('updated', 'أزال صورة بطاقة قسم «'.$model->name.'»', ['subject_id' => $model->id]);

        return response()->json(['ok' => true, 'category' => self::card($model)]);
    }

    /**
     * ترتيبُ الفئات في الموقع — كما رتّبها صاحبُها في المحرّر.
     *
     * تصل القائمةُ بترتيبها، وكلُّ معرّفٍ فيها من متجره وإلّا رُدّت كلُّها:
     * ترتيبٌ فيه فئةُ غيره لا يُكتب نصفُه. وما لم يصل منها — فئةٌ أُضيفت من
     * لسانٍ آخر بعد أن فُتح المحرّر — يُلحق بالذيل بترتيبه القائم، فلا
     * يتداخل رقمٌ قديمٌ برقمٍ جديد.
     */
    public function orderCategories(Request $request): JsonResponse
    {
        $bid = $this->bid();

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer', 'distinct', Rule::exists('categories', 'id')->where('business_id', $bid)],
        ], [
            'ids.*.exists' => __('في الترتيب فئةٌ ليست في متجرك — حدّث الصفحة.'),
        ]);

        $given = array_map('intval', $data['ids']);
        $rest = Category::where('business_id', $bid)->whereNotIn('id', $given)->inStoreOrder()->pluck('id')->all();
        $order = [...$given, ...$rest];

        DB::transaction(function () use ($bid, $order) {
            foreach ($order as $i => $id) {
                Category::where('business_id', $bid)->whereKey($id)->update(['sort_order' => $i + 1]);
            }
        });

        \App\Support\Activity::log('updated', 'رتّب فئات الموقع');

        return response()->json(['ok' => true, 'ids' => $order]);
    }

    /** البطاقةُ كما تقرؤها لوحةُ الفئات في المحرّر */
    private static function card(Category $model): array
    {
        return [
            'id' => $model->id,
            'card_hidden' => (bool) $model->card_hidden,
            'card_image' => filled($model->card_image) ? (string) $model->card_image : null,
        ];
    }

    /**
     * إضافةٌ جديدة — بسعرها، وبمداها، وبربطٍ اختياريّ ببضاعةٍ في الرفّ.
     *
     * تُنشأ فعّالةً: من يضيفها وهو يجهّز منتجًا يريدها الآن، ومطالبتُه
     * بتفعيلها في شاشةٍ أخرى مقبضٌ زائد بلا فائدة.
     */
    public function storeAddon(Request $request): JsonResponse
    {
        $bid = $this->bid();

        $data = $request->validate(self::noOwnership() + self::addonRules($bid), self::addonMessages());
        self::assertWholeBoutiquePieces($bid, $data);

        // والاسمُ الإنجليزيّ ما كتبه التاجر — لا يُملأ من المعجم: «شوكولاتة بلجيكية» اسمُ بضاعته
        $addon = Addon::create(collect($data)->only(['name', 'name_en', 'price'])->all() + [
            'business_id' => $bid,
            // فعّالةً ما لم يُطلب غيرُ ذلك — ومن صفحة الإضافات قد تُنشأ موقوفةً لتُجهَّز ثمّ تُفتح
            'active' => array_key_exists('active', $data) && $data['active'] !== null ? (bool) $data['active'] : true,
        ] + self::channelAttributes($data, null) + [
            // لا مالك: بابُ الإضافة الخاصّة بمنتجٍ مغلقٌ — انظر `noOwnership`
            'product_id' => null,
        ] + self::stockAttributes($data) + self::scopeAttributes($data, null));

        self::syncScopeProducts($addon, $bid, $data);

        \App\Support\Activity::log('created', 'أضاف إضافة «'.$addon->name.'»', ['subject_id' => $addon->id]);

        return response()->json(['ok' => true, 'addon' => self::addonPayload($addon->fresh())]);
    }

    /**
     * تعديل إضافةٍ قائمة — سعرها ومداها وما تأكله من الرفّ.
     *
     * ولم يكن للإضافة بابُ تعديلٍ إطلاقًا قبل هذا: تُنشأ ثمّ لا تُمسّ. فمن
     * أخطأ سعرها أو أراد ربطها بالمخزون لم يكن أمامه إلا أن ينشئ أخرى
     * بالاسم نفسه — فيرى الكاشير اسمين متطابقين ويختار عشوائيًّا.
     *
     * ولا تُمسّ فواتير مضت: اسمُها وسعرُها ولقطةُ ما أكلته منسوخةٌ في
     * `order_item_addons` لحظة البيع.
     */
    /**
     * كيف تُعرض إضافاتُ المتجر في نقطة البيع — لصاحب النشاط وحده.
     *
     * يُحفظ بطلبٍ مستقلّ لا مع نموذج المنتج: هو إعدادٌ للنشاط كلِّه، ولو
     * رُكّب على حفظة المنتج لَحُفظ منتجٌ نصفُ مكتوب لأجل تبديل عرض.
     *
     * والمالكُ بتعريف النظام الواحد (`Permissions::isOwner`)، والردُّ قبل
     * أيّ كتابة: الإخفاءُ في الشاشة لا يمنع طلبًا مباشرًا. ولا يُقرأ
     * `business_id` من الطلب — المتجرُ متجرُ من سجّل الدخول.
     */
    public function addonsDisplay(Request $request): JsonResponse
    {
        abort_unless(Permissions::isOwner(auth()->user()), 403);

        // والاسمُ العربيّ في النداء لا في الخريطة العامّة: `layout` هناك «تخطيط» في بانِي الموقع
        $data = $request->validate([
            'layout' => ['required', Rule::in(PosAddonsLayout::VALUES)],
        ], [], ['layout' => __('طريقة عرض الإضافات')]);

        Setting::updateOrCreate(
            ['business_id' => $this->bid(), 'key' => PosAddonsLayout::KEY],
            ['value' => $data['layout']],
        );

        \App\Support\Activity::log('updated', 'غيّر عرض الإضافات في نقطة البيع إلى «'.$data['layout'].'»');

        return response()->json(['ok' => true, 'layout' => $data['layout']]);
    }

    public function updateAddon(Request $request, int $addon): JsonResponse
    {
        $bid = $this->bid();

        $model = Addon::where('business_id', $bid)->findOrFail($addon);

        // الملكيّةُ تُقرأ من الصفّ لا من الطلب: لا يُحوِّلها حفظٌ ولا يُلغيها
        $owner = $model->product_id === null ? null : (int) $model->product_id;

        $data = $request->validate(
            self::ownershipStays($owner) + self::addonRules($bid, $model->id, $owner),
            self::addonMessages(),
        );
        self::assertWholeBoutiquePieces($bid, $data);

        // والاسمُ الإنجليزيّ كما أُرسل: ما خُزّن يبقى إن لم يُرسَل، ولا يُملأ من المعجم إن فرغ
        $model->update(collect($data)->only(['name', 'name_en', 'price'])->all() + [
            'active' => array_key_exists('active', $data) ? (bool) $data['active'] : (bool) $model->active,
        ] + self::channelAttributes($data, $model) + self::stockAttributes($data) + self::scopeAttributes($data, $owner));

        self::syncScopeProducts($model, $bid, $data);

        \App\Support\Activity::log('updated', 'عدّل إضافة «'.$model->name.'»', ['subject_id' => $model->id]);

        return response()->json(['ok' => true, 'addon' => self::addonPayload($model->fresh())]);
    }

    /**
     * أين تُباع الإضافة — نقطةُ البيع، والموقع، أو كلاهما، أو لا شيء.
     *
     * والجديدةُ بلا جوابٍ تُباع في الصندوق وحدَه: هو ما تفعله كلُّ إضافةٍ قائمة
     * (`an_addon_chooses_where_it_is_sold`)، والموقعُ يُفتح لها بقرارٍ لا بغياب.
     * والقائمةُ بلا جوابٍ تبقى على ما كانت: حفظُ سعرها لا يفتحها للموقع ولا
     * يغلقها عن الصندوق.
     *
     * @return array{pos_enabled: bool, web_enabled: bool}
     */
    private static function channelAttributes(array $data, ?Addon $current): array
    {
        return [
            'pos_enabled' => array_key_exists('pos_enabled', $data) && $data['pos_enabled'] !== null
                ? (bool) $data['pos_enabled']
                : (bool) ($current?->pos_enabled ?? true),
            'web_enabled' => array_key_exists('web_enabled', $data) && $data['web_enabled'] !== null
                ? (bool) $data['web_enabled']
                : (bool) ($current?->web_enabled ?? false),
        ];
    }

    /**
     * قواعد الإضافة — واحدةٌ للإنشاء والتعديل.
     *
     * والبضاعة والمنتجات كلُّها تُقيَّد بالنشاط في القاعدة نفسها: معرّفٌ من
     * متجرٍ آخر يُردّ هنا لا في الشاشة، ولا يُقرأ `business_id` من الطلب
     * إطلاقًا.
     *
     * @return array<string, mixed>
     */
    private static function addonRules(int $bid, ?int $ignore = null, ?int $owner = null): array
    {
        $ofBusiness = fn () => Rule::exists('products', 'id')->where('business_id', $bid)->whereNull('deleted_at');
        // ربطُ الإضافة القائمُ ببضاعتها — يُحفظ كما هو ولو كان صنفَ بوتيك
        $kept = $ignore ? Addon::where('business_id', $bid)->whereKey($ignore)->value('inventory_product_id') : null;

        return [
            'name' => [
                'required', 'string', 'max:100',
                // التفرّد يتبع المدى: «تغليف» لباقة الورد لا يمنع «تغليف»
                // لعلبة الشوكولاتة، ولا يمنع «تغليف» المتجر كلِّه
                Rule::unique('addons', 'name')->where('business_id', $bid)
                    ->where('product_id', $owner)
                    ->ignore($ignore),
            ],
            'name_en' => ['nullable', 'string', 'max:100'],
            'price' => ['required', 'numeric', 'min:0'],
            'active' => ['nullable', 'boolean'],
            // أين تُباع — انظر `channelAttributes`
            'pos_enabled' => ['nullable', 'boolean'],
            'web_enabled' => ['nullable', 'boolean'],
            // ولا قاعدةَ لـ`product_id` هنا: الوجهتان كلتاهما تمنعه، فقاعدةُ
            // «من هذا المتجر» لا تُقرأ أبدًا — انظر `noOwnership`
            /*
             * وصنفُ البوتيك لا يُربط بإضافةٍ جديدة بعد (`Boutiques::refuseNewLink`) —
             * والربطُ القائمُ يُحفظ كما هو.
             */
            'inventory_product_id' => ['nullable', $ofBusiness(), Boutiques::refuseNewLink($bid, $kept === null ? null : (int) $kept)],
            /*
             * الكمية المستهلَكة لا تُقبل صفرًا — وغيابُها واحدة.
             *
             * إضافةٌ مرتبطةٌ بصنفٍ وتأكل صفرًا وعدٌ لا يُنفَّذ: تُعرض على
             * أنّها تنقص من الرفّ ولا تنقص منه شيئًا. ومن أرادها بلا خصمٍ
             * فليتركها خدمةً بلا صنف.
             *
             * ولا تُطلب طلبًا: من ربط صنفًا ولم يذكر كميّةً يقصد واحدة — وهو
             * ما كان النظام يفعله قبل هذا العمود، فشاشةٌ قديمة تبقى تعمل.
             */
            'inventory_quantity' => ['nullable', 'numeric', 'gt:0', 'max:100000'],
            'scope' => ['nullable', Rule::in([Addon::SCOPE_ALL, Addon::SCOPE_SELECTED, 'product'])],
            'product_ids' => ['nullable', 'array', 'max:500'],
            'product_ids.*' => [$ofBusiness()],
        ];
    }

    /** @return array<string, string> */
    private static function addonMessages(): array
    {
        return [
            'name.unique' => __('توجد إضافةٌ بهذا الاسم.'),
            'inventory_quantity.gt' => __('الكمية المستهلكة تكون أكبر من صفر.'),
            'product_id.prohibited' => __('لا تُنشأ إضافةٌ خاصّةٌ بمنتجٍ واحد — تُنشأ للمتجر ثمّ يُضيَّق مداها.'),
            'scope.prohibited' => __('هذه الإضافة خاصّةٌ بمنتجها ولا يُغيَّر مداها.'),
            'product_ids.prohibited' => __('هذه الإضافة خاصّةٌ بمنتجها ولا تُعرض مع غيره.'),
        ];
    }

    /**
     * لا تُنشأ إضافةٌ مملوكةٌ لمنتج — والطلبُ الذي يحاول يُردّ لا يُفسَّر.
     *
     * كان «هذا المنتج فقط» مدًى ثالثًا، وزال بزوال قسم التركيب: هو وحدَه
     * كان يعرض تلك الإضافة ويعدّلها ويفكُّ ربطها. فلا يُفتح بابٌ يكتب صفًّا
     * لا تُديره شاشةٌ بعده.
     *
     * والردُّ صريحٌ لا صامت: طلبٌ يذكر منتجًا مالكًا لو أُهمل ذكرُه لصارت
     * الإضافةُ إضافةَ متجرٍ تظهر مع كلّ منتجاته — وهو عكسُ ما أراده صاحبُ
     * الطلب تمامًا.
     *
     * @return array<string, mixed>
     */
    private static function noOwnership(): array
    {
        return [
            'product_id' => ['prohibited'],
            'scope' => ['nullable', Rule::in([Addon::SCOPE_ALL, Addon::SCOPE_SELECTED])],
        ];
    }

    /**
     * ملكيّةُ إضافةٍ قائمةٍ لا تُنقل ولا تُرفع — بالخادم لا بالشاشة.
     *
     * والصفوفُ المكتوبةُ قبل زوال القسم تبقى تُعدَّل وتُعطَّل من «المعلومات
     * الأساسية» في شاشة مالكها. فلزم أن يكون الحاجزُ هنا: طلبُ الحفظ يصل
     * من متصفّحٍ قد تكون شاشتُه قديمة — أو مُلاعَبة. وإضافةٌ خاصّةٌ تُحوَّل
     * إلى عامّةٍ بحفظِ سعرٍ تظهر فجأةً مع كلّ منتجات المتجر، ولا أحد طلب
     * ذلك ولا أحد يراه.
     *
     * فالمملوكةُ يُمنع أن يُرسل لها مدًى أو منتجاتُ مدًى؛ والعامّةُ يُمنع أن
     * يُرسل لها مالك. والفراغُ ليس طلبًا: `null` و`[]` تمرّان.
     *
     * @return array<string, mixed>
     */
    private static function ownershipStays(?int $owner): array
    {
        if ($owner === null) {
            return self::noOwnership();
        }

        return [
            'product_id' => ['prohibited'],
            'scope' => ['prohibited'],
            'product_ids' => ['prohibited'],
        ];
    }

    /**
     * حقول المخزون — بلا صنفٍ لا كمية.
     *
     * وتُفرَّغ الكمية صراحةً حين يُرفع الربط: تركُها مكتوبةً تحت صنفٍ فارغ
     * يجعل من يعيد الربط لاحقًا يرث رقمًا لا يعرف من أين جاء.
     */
    private static function stockAttributes(array $data): array
    {
        $pid = $data['inventory_product_id'] ?? null;
        $pid = ($pid === null || $pid === '') ? null : (int) $pid;

        return [
            'inventory_product_id' => $pid,
            // والفراغ يبقى فراغًا لا واحدة: هو ما تُقرأ به كلّ إضافةٍ رُبطت
            // قبل هذا العمود، فيبقى للقديم والجديد قراءةٌ واحدة
            'inventory_quantity' => $pid && ($data['inventory_quantity'] ?? null) !== null
                ? (float) $data['inventory_quantity']
                : null,
        ];
    }

    /**
     * قطعةُ البوتيك تُؤخذ قطعًا كاملة — يُقال ذلك عند الحفظ لا عند كلّ بيعة.
     *
     * المستحقُّ ثمنُ القطعة × عددها (`BoutiqueDues::due`)، وكسرُ القطعة لا
     * مستحقَّ له يُعرف: فالبيعُ يردّه (`BoutiqueDues::refusal`). وكانت الإضافةُ
     * تُحفظ بكمّيّة ١٫٥ ثمّ تُردّ كلُّ بيعةٍ لها في الصندوق والموقع — والتاجرُ
     * لا يعرف أنّ العيب في إضافةٍ حفظها أمس.
     *
     * @throws ValidationException
     */
    private static function assertWholeBoutiquePieces(int $bid, array $data): void
    {
        $pid = $data['inventory_product_id'] ?? null;
        if ($pid === null || $pid === '') {
            return;
        }

        $owned = Product::where('business_id', $bid)->whereKey((int) $pid)->whereNotNull('boutique_id')->exists();
        $each = (float) ($data['inventory_quantity'] ?? 1);

        if ($owned && abs($each - round($each)) > 0.0001) {
            throw ValidationException::withMessages([
                'inventory_quantity' => __('قطعةُ البوتيك تُؤخذ قطعًا كاملة — اكتب عددًا صحيحًا.'),
            ]);
        }
    }

    /** المدى يُكتب فراغًا حين يكون «مع الجميع» — والفراغ هو مدى كلّ إضافةٍ قديمة */
    private static function scopeAttributes(array $data, ?int $owner): array
    {
        if ($owner !== null) {
            return ['scope' => null];
        }

        return ['scope' => ($data['scope'] ?? null) === Addon::SCOPE_SELECTED ? Addon::SCOPE_SELECTED : null];
    }

    /**
     * يكتب منتجات الإضافة ذات المدى المحدّد — ويمحوها لما سواها.
     *
     * وصفوف الإضافة هي مداها: «مع الجميع» لا صفوف لها، فتُعرض حيث لم
     * يُستثنَ شيء. انظر `ProductAddons::legacyList` لعلّة فصل هذه الصفوف
     * عن القائمة القديمة للمنتج.
     */
    private static function syncScopeProducts(Addon $addon, int $bid, array $data): void
    {
        // المملوكةُ لمنتجٍ لا صفوفَ مدًى لها تُكتب — وما كُتب قبل اليوم يبقى:
        // صفوفُ إنتاجٍ كتبها قسمٌ زال، وحفظُ سعرٍ لا يمحو بيانات
        if ($addon->product_id !== null) {
            return;
        }

        if ($addon->scope !== Addon::SCOPE_SELECTED) {
            \Illuminate\Support\Facades\DB::table('product_addons')->where('addon_id', $addon->id)->delete();

            return;
        }

        $ids = array_values(array_unique(array_map('intval', $data['product_ids'] ?? [])));

        \Illuminate\Support\Facades\DB::transaction(function () use ($addon, $bid, $ids) {
            // حفظان للإضافة نفسها يتعاقبان لا يتداخلان: الثاني ينتظر الأوّل ثمّ
            // يمحو ما كتبه — وإلّا بقيت القائمتان معًا. والقفلُ «بلا مفتاح»:
            // لا يحجز من يكتب صفًّا يشير إلى الإضافة (فحصُ المفتاح الأجنبيّ)
            \Illuminate\Support\Facades\DB::table('addons')->where('id', $addon->id)->lock('for no key update')->first();
            \Illuminate\Support\Facades\DB::table('product_addons')->where('addon_id', $addon->id)->delete();

            // وربطٌ وصل في اللحظة نفسها بالزوج نفسه لا يُسقط الحفظ — انظر `AddonController::link`
            foreach ($ids as $i => $productId) {
                \Illuminate\Support\Facades\DB::table('product_addons')->insertOrIgnore([
                    'business_id' => $bid,
                    'product_id' => $productId,
                    'addon_id' => $addon->id,
                    'sort_order' => $i,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    /**
     * من أين الإضافة — خدمةٌ من المتجر، أو صنفٌ من رفّه، أو قطعةٌ من رفّ بوتيك.
     *
     * والمستحقُّ المعروض قاعدةُ `BoutiqueDues::due` نفسُها: ثمنُ القطعة اليوم ×
     * عددها في كلّ إضافة. للشاشة وحدها — ما يُكتب يُحسب عند البيع ويُثبَّت
     * في لقطته، فتغييرُ ثمن القطعة غدًا لا يمسّ بيعةً مضت. ولا يُعرض الفرقُ
     * بين سعر الزبون والمستحقّ ربحًا: تكاليفُ المتجر الأخرى لا يعرفها هنا أحد.
     *
     * @return array{source: string, stock_name: ?string, boutique_id: ?int, boutique_name: ?string, boutique_due: ?float}
     */
    private static function sourceOf(Addon $addon): array
    {
        $item = $addon->inventory_product_id ? $addon->inventoryProduct : null;

        if (! $item) {
            return ['source' => 'shop', 'stock_name' => null, 'boutique_id' => null, 'boutique_name' => null, 'boutique_due' => null];
        }

        if ($item->boutique_id === null) {
            return ['source' => 'stock', 'stock_name' => $item->name, 'boutique_id' => null, 'boutique_name' => null, 'boutique_due' => null];
        }

        return [
            'source' => 'boutique',
            'stock_name' => $item->name,
            'boutique_id' => (int) $item->boutique_id,
            'boutique_name' => $item->boutique?->label(),
            'boutique_due' => round((float) $item->price * (float) \App\Support\AddonStock::each($addon), 3),
        ];
    }

    /**
     * ما تعرفه الشاشة عن الإضافة — بلا تكلفةٍ ولا شيءٍ يخصّ الزبون.
     *
     * @return array<string, mixed>
     */
    public static function addonPayload(Addon $addon): array
    {
        return [
            'value' => $addon->id,
            'label' => $addon->name,
            'name_en' => $addon->name_en,
            'price' => (float) $addon->price,
            'active' => (bool) $addon->active,
            'pos_enabled' => (bool) ($addon->pos_enabled ?? true),
            'web_enabled' => (bool) ($addon->web_enabled ?? false),
            'private' => $addon->product_id !== null,
            'product_id' => $addon->product_id,
            'scope' => $addon->scopeName(),
            'inventory_product_id' => $addon->inventory_product_id,
            'inventory_quantity' => $addon->inventory_product_id
                ? (float) \App\Support\AddonStock::each($addon)
                : null,
        ] + self::sourceOf($addon) + [
            'product_ids' => $addon->scopeName() === Addon::SCOPE_SELECTED
                ? \Illuminate\Support\Facades\DB::table('product_addons')->where('addon_id', $addon->id)
                    ->orderBy('sort_order')->pluck('product_id')->map(fn ($i) => (int) $i)->all()
                : [],
        ];
    }
}
