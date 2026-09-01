<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * صورُ المنتج — البابُ الوحيد الذي تُبدَّل منه.
 *
 * القاعدة كلّها جملةٌ واحدة: **الرئيسية في `products.image`، وما سواها في
 * `product_images`**. فلا صورةَ في موضعين، ولا عَلَمَ «رئيسية» يناقض عمودًا.
 *
 * وكلُّ تبديلٍ هنا معاملةٌ واحدة: الترقية تنزع من الجدول وتضع في العمود
 * وتُعيد القديم مكانه — وثلاثُ كتاباتٍ متتابعة بلا معاملة تترك المنتج بصورةٍ
 * رئيسيةٍ مكرّرة في معرضه، أو بلا رئيسيةٍ أصلًا، إن انقطع الطلب بينها.
 *
 * والملفّ يُمحى من القرص مع صفّه: متجرٌ يبدّل صور بضاعته كلّ موسم يترك
 * أضعافَ ما يعرضه على القرص، ولا شيء يشير إليه.
 */
class ProductImages
{
    /**
     * أقصى ما يحمله منتجٌ من صور — الرئيسية وما معها.
     *
     * سقفٌ لا لأنّ القاعدة تضيق، بل لأنّ الشاشة تُحمَّل كلَّها: عشرون صورةً
     * على بطاقةٍ في نقطة البيع تُبطئ الصندوق، ولا أحد يتصفّح عشرين صورةً
     * لباقة ورد.
     */
    public const MAX = 8;

    /** المجلّد على قرص `public` — واحدٌ للرئيسية والإضافية */
    private const DISK = 'public';

    private const FOLDER = 'products';

    /** كم صورةً يحمل هذا المنتج الآن — الرئيسية المرفوعة وما معها */
    public static function count(Product $product): int
    {
        return (self::hasRealMain($product) ? 1 : 0)
            + ProductImage::where('product_id', $product->id)->count();
    }

    /**
     * هل للمنتج صورةٌ رئيسيةٌ حقيقية؟
     *
     * القيمة الخام لا المقروءة: `getImageAttribute` يردّ رابط `picsum`
     * لمنتجٍ بلا صورة، فلا يُفرَّق بالمقروء بين صورةٍ رفعها التاجر وبديلٍ
     * يصنعه النظام. ولو قيس بالمقروء لَبدا كلُّ منتجٍ مصوَّرًا.
     */
    public static function hasRealMain(Product $product): bool
    {
        return filled($product->getRawOriginal('image'));
    }

    /**
     * يضيف صورةً إضافية ويردّ صفّها.
     *
     * والموضع يُحسب من آخر ما في المعرض لا من عدده: صورةٌ حُذفت من الوسط
     * تترك فجوةً في العدّ، فيُعاد استعمال موضعٍ مشغول وتتبادل الصورتان
     * أماكنهما في كلّ فتحة.
     */
    public static function add(Product $product, UploadedFile $file): ProductImage
    {
        $next = (int) ProductImage::where('product_id', $product->id)->max('sort_order');

        return ProductImage::create([
            'business_id' => $product->business_id,
            'product_id' => $product->id,
            'path' => $file->store(self::FOLDER, self::DISK),
            'sort_order' => $next + 1,
        ]);
    }

    /**
     * يجعل صورةً من المعرض رئيسيةً — والرئيسيةُ القديمة تنزل مكانها.
     *
     * تبديلٌ لا استبدال: من رفع خمس صورٍ ثمّ بدّل الرئيسية لا يقصد أن يفقد
     * واحدةً منها. فيبقى العدد كما هو وتتبادل الصورتان موضعيهما.
     *
     * ويُستثنى بديلُ النظام: منتجٌ لا صورةَ له يحمل رابط `picsum` في عموده،
     * وإنزالُه إلى المعرض يملؤه بصورٍ لم يرفعها أحد — فيُطرح ولا يُحفظ.
     */
    public static function promote(Product $product, ProductImage $image): void
    {
        DB::transaction(function () use ($product, $image) {
            $previousMain = $product->getRawOriginal('image');
            $slot = $image->sort_order;

            $product->forceFill(['image' => $image->path])->save();

            if (filled($previousMain) && ! str_starts_with((string) $previousMain, 'http')) {
                $image->forceFill(['path' => $previousMain, 'sort_order' => $slot])->save();
            } else {
                $image->delete();
            }
        });
    }

    /**
     * يحذف صورةً من المعرض — صفَّها وملفَّها معًا.
     */
    public static function remove(ProductImage $image): void
    {
        DB::transaction(function () use ($image) {
            $path = $image->path;
            $image->delete();
            self::forgetFile($path);
        });
    }

    /**
     * يحذف الصورة الرئيسية — وتصعد أوّلُ صورةٍ في المعرض مكانها.
     *
     * ومنتجٌ يُترك بلا رئيسيةٍ ومعرضُه ممتلئ يُعرض بلا صورةٍ في شاشة البيع
     * بينما صورُه محفوظة — عطبٌ يراه التاجر ولا يفهم سببه. فالخلافة تلقائية،
     * وإفراغُه إلى بديل النظام لا يقع إلّا حين لا يبقى شيء.
     *
     * @return bool هل خلَفها شيء؟
     */
    public static function removeMain(Product $product): bool
    {
        return DB::transaction(function () use ($product) {
            $path = $product->getRawOriginal('image');
            $heir = ProductImage::where('product_id', $product->id)
                ->orderBy('sort_order')->orderBy('id')->first();

            $product->forceFill(['image' => $heir?->path])->save();
            $heir?->delete();

            self::forgetFile($path);

            return $heir !== null;
        });
    }

    /** يمحو ملفًّا من القرص — والرابط الخارجيّ ليس ملفًّا فلا يُمسّ */
    public static function forgetFile(?string $path): void
    {
        if (blank($path) || str_starts_with($path, 'http')) {
            return;
        }

        Storage::disk(self::DISK)->delete($path);
    }

    /**
     * صورُ المنتج كما تُعرض: الرئيسية أوّلًا ثمّ المعرض.
     *
     * وباب واحد تقرأ منه الشاشات كلّها — البطاقة والمعرض والتعديل — فلا
     * يفترق ترتيبٌ عن ترتيب، ولا تُعرض الرئيسية ثانيةً في آخر الصفّ.
     *
     * @return list<array{id: int|null, url: string, main: bool}>
     */
    public static function gallery(Product $product): array
    {
        $out = [[
            // الرئيسية بلا معرّف: ليست صفًّا في الجدول — وهو ما يميّزها للشاشة
            'id' => null,
            'url' => $product->image,
            'main' => true,
        ]];

        foreach ($product->images as $image) {
            $out[] = ['id' => $image->id, 'url' => $image->url, 'main' => false];
        }

        return $out;
    }
}
