<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Addon;
use App\Models\Category;
use App\Support\Demo;
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

        $category = Category::create($data + ['business_id' => $bid]);

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
     * إضافةٌ جديدة — بسعرها، وبربطٍ اختياريّ ببضاعةٍ في الرفّ.
     *
     * تُنشأ فعّالةً: من يضيفها وهو يجهّز منتجًا يريدها الآن، ومطالبتُه
     * بتفعيلها في شاشةٍ أخرى مقبضٌ زائد بلا فائدة.
     *
     * و`product_id` يقرّر مداها: مذكورًا فهي خاصّةٌ بذلك المنتج لا تُعرض مع
     * سواه، وغائبًا فهي إضافةُ متجرٍ كما كانت. والتفرّد يتبع المدى نفسه —
     * «تغليف» لباقة الورد لا يمنع «تغليف» لعلبة الشوكولاتة.
     */
    public function storeAddon(Request $request): JsonResponse
    {
        $bid = $this->bid();

        $owner = $request->input('product_id');
        $owner = ($owner === null || $owner === '') ? null : (int) $owner;

        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('addons', 'name')->where('business_id', $bid)->where('product_id', $owner),
            ],
            'name_en' => ['nullable', 'string', 'max:100'],
            'price' => ['required', 'numeric', 'min:0'],
            'product_id' => [
                'nullable',
                Rule::exists('products', 'id')->where('business_id', $bid)->whereNull('deleted_at'),
            ],
            // البضاعة من هذا النشاط أو لا شيء — والقاعدة في الخادم لا في الشاشة
            'inventory_product_id' => [
                'nullable',
                Rule::exists('products', 'id')->where('business_id', $bid)->whereNull('deleted_at'),
            ],
        ], [
            'name.unique' => __('توجد إضافةٌ بهذا الاسم.'),
        ]);

        $addon = Addon::create($data + ['business_id' => $bid, 'active' => true, 'product_id' => $owner]);

        \App\Support\Activity::log('created', 'أضاف إضافة «'.$addon->name.'»', ['subject_id' => $addon->id]);

        return response()->json([
            'ok' => true,
            'addon' => [
                'value' => $addon->id,
                'label' => $addon->name,
                'price' => (float) $addon->price,
                'active' => true,
                'private' => $addon->product_id !== null,
                'inventory_product_id' => $addon->inventory_product_id,
            ],
        ]);
    }
}
