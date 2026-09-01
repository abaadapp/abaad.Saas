<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductImage;
use App\Support\Activity;
use App\Support\Demo;
use App\Support\ProductImages;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * صور المنتج — مسارٌ بذاته، لا حقلٌ في نموذج المنتج.
 *
 * وهذا هو الغرض لا تنظيمًا للشيفرة: نموذج المنتج يرسل السعر والتكلفة والكمية
 * والوصف والضريبة والخصم في كلّ حفظ، ويكتب الكمية **مطلقةً** فيُزيح رصيد
 * الفرع بفارقها. فمن أراد أن يبدّل صورةً فحفظ النموذج كلَّه، كتب فوق كلّ ما
 * تغيّر بينه وبين فتحه الشاشة — بيعةٌ وقعت في الأثناء تُمحى من الرصيد.
 *
 * فصارت الصور تُدار بطلباتٍ صغيرة: رفعٌ وترقيةٌ وحذف، لا يمسّ واحدٌ منها
 * عمودًا آخر في المنتج.
 */
class ProductImageController extends Controller
{
    private function bid(): int
    {
        return auth()->user()->business_id ?? Demo::bid();
    }

    /** المنتج من هذا المتجر وحده — والمعرّف يصل من العنوان فلا يُصدَّق */
    private function product(string $id): Product
    {
        return Product::where('business_id', $this->bid())->findOrFail($id);
    }

    /**
     * صورةٌ من معرض هذا المنتج بعينه.
     *
     * الشرطان معًا: `business_id` يمنع صورة الجار، و`product_id` يمنع نقل
     * صورةِ منتجٍ إلى منتجٍ آخر بتخمين معرّفها — وكلاهما داخل المتجر الواحد
     * فلا يكفي الأوّل وحده.
     */
    private function image(Product $product, string $imageId): ProductImage
    {
        return ProductImage::where('business_id', $this->bid())
            ->where('product_id', $product->id)
            ->findOrFail($imageId);
    }

    /**
     * رفع صورةٍ أو أكثر إلى المعرض.
     *
     * والسقف يُقاس على ما سيصير لا على ما هو: من عنده ستٌّ ورفع أربعًا يُردّ
     * قبل أن يُكتب شيء — لا أن تُقبل اثنتان وتُطرح اثنتان بلا أن يُقال أيّهما.
     */
    public function store(Request $request, string $id)
    {
        $product = $this->product($id);

        $request->validate([
            'images' => ['required', 'array', 'min:1'],
            'images.*' => ['image', 'max:4096'],
        ], [
            'images.required' => __('اختر صورةً واحدة على الأقل.'),
            'images.*.image' => __('الملفّ ليس صورة.'),
            'images.*.max' => __('حجم الصورة يتجاوز ٤ ميغابايت.'),
        ], ['images' => __('الصور')]);

        $files = $request->file('images');
        $have = ProductImages::count($product);

        if ($have + count($files) > ProductImages::MAX) {
            throw ValidationException::withMessages([
                'images' => __('لا يحمل المنتج أكثر من :max صور — عنده :have الآن.', [
                    'max' => ProductImages::MAX, 'have' => $have,
                ]),
            ]);
        }

        /*
         * ومنتجٌ بلا صورةٍ رئيسية تصير أُولى المرفوعات رئيسيتَه.
         *
         * وإلّا رفع التاجر صورةً فبقيت بطاقتُه تعرض بديل النظام، وصورتُه في
         * معرضٍ لا يفتحه أحد — فيظنّ أنّ الرفع لم ينجح ويعيده.
         */
        foreach ($files as $file) {
            if (! ProductImages::hasRealMain($product)) {
                $product->forceFill(['image' => $file->store('products', 'public')])->save();

                continue;
            }

            ProductImages::add($product, $file);
        }

        Activity::log('updated', 'أضاف صورًا للمنتج: '.$product->name, ['subject_id' => $product->id]);

        return back()->with('toast', [
            'msg' => trans_choice('{1}أُضيفت صورة|[2,*]أُضيفت :count صور', count($files), ['count' => count($files)]),
            'type' => 'success',
        ]);
    }

    /** يجعل صورةً من المعرض رئيسيةً — والقديمة تنزل مكانها لا تُفقد */
    public function promote(string $id, string $imageId)
    {
        $product = $this->product($id);

        ProductImages::promote($product, $this->image($product, $imageId));
        Activity::log('updated', 'غيّر الصورة الرئيسية للمنتج: '.$product->name, ['subject_id' => $product->id]);

        return back()->with('toast', ['msg' => __('صارت هذه هي الصورة الرئيسية'), 'type' => 'success']);
    }

    /** حذف صورةٍ من المعرض */
    public function destroy(string $id, string $imageId)
    {
        $product = $this->product($id);

        ProductImages::remove($this->image($product, $imageId));
        Activity::log('updated', 'حذف صورةً من المنتج: '.$product->name, ['subject_id' => $product->id]);

        return back()->with('toast', ['msg' => __('حُذفت الصورة'), 'type' => 'warning']);
    }

    /** حذف الصورة الرئيسية — وتصعد أوّلُ صورةٍ في المعرض مكانها */
    public function destroyMain(string $id)
    {
        $product = $this->product($id);

        $succeeded = ProductImages::removeMain($product);
        Activity::log('updated', 'حذف الصورة الرئيسية للمنتج: '.$product->name, ['subject_id' => $product->id]);

        return back()->with('toast', [
            'msg' => $succeeded
                ? __('حُذفت الصورة الرئيسية، وصعدت التالية مكانها')
                : __('حُذفت الصورة — لم تبقَ للمنتج صورة'),
            'type' => 'warning',
        ]);
    }
}
