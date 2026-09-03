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

    /**
     * أقصى حجمٍ للصورة الواحدة بالكيلوبايت.
     *
     * ورقمٌ واحد يقرؤه المُدقّق والشاشة معًا: لو قاست الشاشةُ بحدٍّ والخادمُ
     * بآخر، لَرُفع أربعةُ ميغابايت كاملةً على شبكةِ هاتفٍ ثمّ رُدّت — أو
     * لَمنعت الشاشةُ ما كان الخادم ليقبله.
     */
    public const MAX_KB = 4096;

    /**
     * حدودُ الرفع كما يسمح بها هذا الخادمُ فعلًا — لا كما نتمنّى.
     *
     * وهذا هو الفرق بين اختبارٍ أخضر وشاشةٍ تعمل: `UploadedFile::fake()` لا
     * يمرّ بحدود PHP أصلًا، فالمُدقّق يَعِد بأربعة ميغابايت والاختبار يصدّقه،
     * وعلى خادمٍ حدُّه ٢ ميغا **يطرح PHP الملفَّ قبل أن يصل لارافيل** فلا
     * رسالةَ رفضٍ ولا صورة.
     *
     * وتجاوزُ `post_max_size` أسوأ: يُلقى الطلبُ كلُّه بما فيه رمزُ الحماية،
     * فيرى التاجر «انتهت صلاحية الصفحة» ولا يعرف أنّ صوره كانت ثقيلة.
     *
     * فتُقرأ الحدود من `ini` ويُؤخذ الأضيقُ منها ومن وعدنا. والشاشةُ تقرأ
     * الرقم نفسه، فتردّ قبل الإرسال بدل أن تُرسل إلى بابٍ مغلق.
     *
     * @return array{perFile: int, batch: int} بالكيلوبايت
     */
    public static function uploadLimits(): array
    {
        $allowed = self::iniKb('upload_max_filesize');
        $perFile = $allowed > 0 ? min(self::MAX_KB, $allowed) : self::MAX_KB;

        $post = self::iniKb('post_max_size');

        /*
         * وهامشٌ يُطرح من حدّ الطلب: الطلبُ يحمل معه رمزَ الحماية وحدودَ
         * multipart وأسماءَ الحقول. ودفعةٌ تساوي الحدَّ بالضبط تتجاوزه بها.
         */
        $batch = $post > 0 ? max($perFile, $post - 512) : $perFile * self::MAX;

        return ['perFile' => $perFile, 'batch' => $batch];
    }

    /** يقرأ قيمة `ini` المختصرة (2M، 512K، 1G) كيلوبايتاتٍ — و«بلا حدّ» صفرٌ */
    private static function iniKb(string $key): int
    {
        $raw = trim((string) ini_get($key));

        if ($raw === '' || $raw === '-1' || $raw === '0') {
            return 0;
        }

        $number = (int) $raw;

        return match (strtolower(substr($raw, -1))) {
            'g' => $number * 1024 * 1024,
            'm' => $number * 1024,
            'k' => $number,
            default => intdiv($number, 1024),
        };
    }

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

    /**
     * كلُّ ملفّات المنتج على القرص — الرئيسية والمعرض معًا.
     *
     * لمن يمحو المنتج نهائيًّا: الصفوف يأخذها قيدُ المفتاح، والملفّات لا
     * يأخذها شيء. ومتجرٌ يمحو موسمه كلَّه يترك عشرات الميغابايت لصورٍ لا
     * صفَّ يشير إليها — عطبٌ صامت لا يظهر إلا بعدّ ما على القرص.
     *
     * @return list<string>
     */
    public static function files(Product $product): array
    {
        return array_values(array_filter([
            $product->getRawOriginal('image'),
            ...ProductImage::where('product_id', $product->id)->pluck('path')->all(),
        ], fn ($path) => filled($path) && ! str_starts_with((string) $path, 'http')));
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
     * وبديلُ النظام يُعلَّم `placeholder`: هو صورةٌ تُعرض ولا يملكها أحد —
     * لا تُحذف ولا تُحسب في السقف. ولولا العَلَم لَعدّته الشاشةُ صورةً،
     * فقالت «١ / ٨» لمنتجٍ لا صورةَ له، وعرضت زرَّ حذفٍ لملفٍّ لا وجود له.
     *
     * @return list<array{id: int|null, url: string, main: bool, placeholder: bool}>
     */
    public static function gallery(Product $product): array
    {
        $out = [[
            // الرئيسية بلا معرّف: ليست صفًّا في الجدول — وهو ما يميّزها للشاشة
            'id' => null,
            'url' => $product->image,
            'main' => true,
            'placeholder' => ! self::hasRealMain($product),
        ]];

        foreach ($product->images as $image) {
            $out[] = ['id' => $image->id, 'url' => $image->url, 'main' => false, 'placeholder' => false];
        }

        return $out;
    }
}
