<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Addon;
use App\Models\Category;
use App\Models\Setting;
use App\Support\Demo;
use App\Support\Permissions;
use App\Support\PosAddonsLayout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
     * إضافةٌ جديدة — بسعرها، وبمداها، وبربطٍ اختياريّ ببضاعةٍ في الرفّ.
     *
     * تُنشأ فعّالةً: من يضيفها وهو يجهّز منتجًا يريدها الآن، ومطالبتُه
     * بتفعيلها في شاشةٍ أخرى مقبضٌ زائد بلا فائدة.
     */
    public function storeAddon(Request $request): JsonResponse
    {
        $bid = $this->bid();

        $data = $request->validate(self::noOwnership() + self::addonRules($bid), self::addonMessages());

        $addon = Addon::create(\App\Support\Lexicon::fill(collect($data)->only(['name', 'name_en', 'price'])->all()) + [
            'business_id' => $bid,
            'active' => true,
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

        $model->update(\App\Support\Lexicon::fill(collect($data)->only(['name', 'name_en', 'price'])->all()) + [
            'active' => array_key_exists('active', $data) ? (bool) $data['active'] : (bool) $model->active,
        ] + self::stockAttributes($data) + self::scopeAttributes($data, $owner));

        self::syncScopeProducts($model, $bid, $data);

        \App\Support\Activity::log('updated', 'عدّل إضافة «'.$model->name.'»', ['subject_id' => $model->id]);

        return response()->json(['ok' => true, 'addon' => self::addonPayload($model->fresh())]);
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
            // ولا قاعدةَ لـ`product_id` هنا: الوجهتان كلتاهما تمنعه، فقاعدةُ
            // «من هذا المتجر» لا تُقرأ أبدًا — انظر `noOwnership`
            'inventory_product_id' => ['nullable', $ofBusiness()],
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
            \Illuminate\Support\Facades\DB::table('product_addons')->where('addon_id', $addon->id)->delete();

            foreach ($ids as $i => $productId) {
                \Illuminate\Support\Facades\DB::table('product_addons')->insert([
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
            'private' => $addon->product_id !== null,
            'product_id' => $addon->product_id,
            'scope' => $addon->scopeName(),
            'inventory_product_id' => $addon->inventory_product_id,
            'inventory_quantity' => $addon->inventory_product_id
                ? (float) \App\Support\AddonStock::each($addon)
                : null,
            'product_ids' => $addon->scopeName() === Addon::SCOPE_SELECTED
                ? \Illuminate\Support\Facades\DB::table('product_addons')->where('addon_id', $addon->id)
                    ->orderBy('sort_order')->pluck('product_id')->map(fn ($i) => (int) $i)->all()
                : [],
        ];
    }
}
