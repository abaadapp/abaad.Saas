<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductImage;
use App\Support\Activity;
use App\Support\Demo;
use App\Support\ProductImages;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
     * والسقف يُقاس على ما سيصير لا على ما هو: من عنده ثلاثٌ ورفع ثلاثًا يُردّ
     * قبل أن يُكتب شيء — لا أن تُقبل اثنتان وتُطرح واحدة بلا أن يُقال أيّها.
     */
    public function store(Request $request, string $id)
    {
        $product = $this->product($id);

        $limits = ProductImages::uploadLimits();

        $request->validate([
            'images' => ['required', 'array', 'min:1'],
            'images.*' => ['image', 'max:'.$limits['perFile']],
        ], [
            'images.required' => __('اختر صورةً واحدة على الأقل.'),
            'images.*.image' => __('الملفّ ليس صورة.'),
            'images.*.max' => __('حجم الصورة يتجاوز :n ميغابايت.', ['n' => round($limits['perFile'] / 1024, 1)]),
        ], ['images' => __('الصور')]);

        $files = array_values($request->file('images'));

        // والسقفُ قبل القرص: صنفٌ ممتلئ — أو قديمٌ فوق السقف — لا يُكتب له ملفٌّ ثمّ يُمحى
        ProductImages::assertRoom($product, count($files));

        /*
         * الملفّاتُ تُحفظ وتُضغط أوّلًا، خارج المعاملة.
         *
         * ضغطُ خمس صورٍ يأخذ ثوانيَ، وقفلُ صفّ المنتج طوالها يُوقف الصندوق
         * عن بيعه (البيعُ يقفل الصفّ نفسه ليخصم كميّته). فالقفلُ يُؤخذ بعدها
         * لكتابة الصفوف وحدها، ويُعاد فيه عدُّ السقف: رفعان من تبويبين معًا
         * يمرّ كلٌّ منهما بالعدّ الأوّل وحده.
         *
         * ومنتجٌ بلا صورةٍ رئيسية تصير أُولى المرفوعات رئيسيتَه (`attach`).
         */
        $paths = ProductImages::putAll($files);

        try {
            DB::transaction(function () use ($product, $paths) {
                $locked = Product::where('business_id', $this->bid())->lockForUpdate()->findOrFail($product->id);

                ProductImages::assertRoom($locked, count($paths));
                ProductImages::attach($locked, $paths);
            });
        } catch (\Throwable $e) {
            // ولا يبقى على القرص ملفٌّ لم يُكتب صفُّه
            ProductImages::forgetFiles($paths);

            throw $e;
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
