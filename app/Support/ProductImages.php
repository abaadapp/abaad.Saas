<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductImage;
use App\Support\Store\StoreImageUpload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
     *
     * ═══ وخمسٌ لا ثمانٍ ═══
     *
     * صارت الصورُ تُرفع من شاشة الإضافة دفعةً واحدة، وكلُّ صورةٍ تُحفظ على
     * القرص وفي كلّ نسخةٍ احتياطيّة. وخمسٌ تكفي الباقةَ من جهاتها.
     *
     * والسقفُ للإضافة وحدها: صنفٌ قديمٌ بستٍّ أو ثمانٍ تبقى صورُه كلُّها —
     * تُعرض وتُحذف وتُبدَّل رئيسيتُها — ولا يُضاف إليه شيءٌ حتى ينزل عددُها
     * دون السقف (`assertRoom`). ولا يُمحى شيءٌ آليًّا: صورةٌ رفعها تاجرٌ
     * لا يحذفها غيرُه.
     */
    public const MAX = 5;

    /**
     * أطولُ ضلعٍ تُحفظ به صورةُ منتجٍ جديدة.
     *
     * أعرضُ ما تُعرض به صفحةُ الصنف في واجهة المتجر نحو ثمانمئة بكسل، وضعفُه
     * لشاشات الهواتف بكثافتها المضاعفة. وصورةُ هاتفٍ بأربعة آلاف بكسل لا
     * يُرى منها أكثرُ من ذلك — يُدفع ثمنُها في القرص وفي شبكة الزبون.
     */
    public const MAX_SIDE = 1600;

    /** جودةُ WebP — لا يُرى فرقُها في صورة بضاعة، والملفُّ نحو ربعِ أصله */
    private const QUALITY = 80;

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
        return self::limitsFrom(self::iniKb('upload_max_filesize'), self::iniKb('post_max_size'));
    }

    /**
     * الحسابُ نفسُه بأرقامٍ تُعطى — ليُختبر بحدود خادمٍ غير الذي يجري عليه.
     *
     * والطلبُ هو الحدُّ الأضيق دائمًا: ملفٌّ لا يتّسع له الطلبُ لا يصل وإن
     * سمح به `upload_max_filesize`. وكان الحسابُ يأخذ الأوسعَ منهما
     * (`max(perFile, post)`)، فخادمٌ حدُّ ملفّه أربعةٌ وحدُّ طلبه اثنان يَعِد
     * بأربعة — ويُلقى الطلبُ كلُّه بلا رسالة.
     *
     * @return array{perFile: int, batch: int} بالكيلوبايت
     */
    public static function limitsFrom(int $uploadKb, int $postKb): array
    {
        $perFile = $uploadKb > 0 ? min(self::MAX_KB, $uploadKb) : self::MAX_KB;

        if ($postKb <= 0) {
            return ['perFile' => $perFile, 'batch' => $perFile * self::MAX];
        }

        /*
         * وهامشٌ يُطرح من حدّ الطلب: الطلبُ يحمل معه رمزَ الحماية وحدودَ
         * multipart وأسماءَ الحقول. ودفعةٌ تساوي الحدَّ بالضبط تتجاوزه بها.
         */
        $batch = max(1, $postKb - 512);

        return ['perFile' => min($perFile, $batch), 'batch' => $batch];
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
     * أيتّسع المنتجُ لهذه الزيادة؟ — وإلّا رُدّ الطلبُ كلُّه قبل أن يُكتب شيء.
     *
     * والسقف يُقاس على ما سيصير لا على ما هو: من عنده ثلاثٌ ورفع ثلاثًا يُردّ
     * — لا أن تُقبل اثنتان وتُطرح واحدة بلا أن يُقال أيّها.
     *
     * وصنفٌ قديمٌ فوق السقف يُردّ عن كلّ إضافة، وصورُه باقية: الرفضُ للجديد
     * لا للقائم.
     */
    public static function assertRoom(Product $product, int $adding): void
    {
        $have = self::count($product);

        if ($have + $adding > self::MAX) {
            throw ValidationException::withMessages([
                'images' => __('لا يحمل المنتج أكثر من :max صور — عنده :have الآن.', [
                    'max' => self::MAX, 'have' => $have,
                ]),
            ]);
        }
    }

    /**
     * يضيف صورةً إضافية ويردّ صفّها.
     *
     * والموضع يُحسب من آخر ما في المعرض لا من عدده: صورةٌ حُذفت من الوسط
     * تترك فجوةً في العدّ، فيُعاد استعمال موضعٍ مشغول وتتبادل الصورتان
     * أماكنهما في كلّ فتحة.
     *
     * والملفّ يُمحى إن لم يُكتب صفُّه — فلا يبقى على القرص ما لا يشير إليه شيء.
     */
    public static function add(Product $product, UploadedFile $file): ProductImage
    {
        $path = self::putAll([$file])[0];

        try {
            $next = (int) ProductImage::where('product_id', $product->id)->max('sort_order');

            return ProductImage::create([
                'business_id' => $product->business_id,
                'product_id' => $product->id,
                'path' => $path,
                'sort_order' => $next + 1,
            ]);
        } catch (\Throwable $e) {
            self::release($path);

            throw $e;
        }
    }

    /**
     * يعلّق ملفّاتٍ حُفظت على القرص بالمنتج — بترتيبها.
     *
     * ومنتجٌ بلا صورةٍ رئيسية تصير أُولاها رئيسيتَه، والباقي في المعرض بعد
     * آخر ما فيه. وإلّا رفع التاجر صورةً فبقيت بطاقتُه بلا صورة، وصورتُه في
     * معرضٍ لا يفتحه أحد — فيظنّ أنّ الرفع لم ينجح ويعيده.
     *
     * صفوفٌ وحدها: الملفّاتُ حُفظت قبلُ (`putAll`)، ومن يناديه يلفّه في
     * معاملةٍ ويمحو الملفّات إن سقطت — فلا صفَّ بلا ملفّ، ولا ملفَّ بلا صفّ.
     *
     * @param  list<string>  $paths
     */
    public static function attach(Product $product, array $paths): void
    {
        $next = (int) ProductImage::where('product_id', $product->id)->max('sort_order');

        foreach ($paths as $path) {
            if (! self::hasRealMain($product)) {
                $product->forceFill(['image' => $path])->save();

                continue;
            }

            ProductImage::create([
                'business_id' => $product->business_id,
                'product_id' => $product->id,
                'path' => $path,
                'sort_order' => ++$next,
            ]);
        }
    }

    /**
     * يحفظ دفعةً كاملةً على القرص أو لا شيء — ويردّ مساراتها بترتيبها.
     *
     * ملفٌّ ثالثٌ يتعذّر حفظُه (قرصٌ امتلأ، أو مجلّدٌ لا يُكتب فيه) يمحو
     * الاثنين قبله ويُردّ الطلب برسالةٍ تقول أيُّ صورة. وإلّا بقيت على القرص
     * صورتان لا يشير إليهما صفّ — أو أُنشئ المنتج بصورٍ ناقصة لا يعرف صاحبُه
     * أيّها سقط.
     *
     * ═══ والصورةُ نفسُها لا تُحفظ مرّتين في المتجر ═══
     *
     * صورةٌ مطابقةٌ تمامًا لصورة منتجٍ في المتجر نفسه — أو هي نفسُها بكسلًا
     * بكسلًا بصيغةٍ أخرى (`ImageFingerprints`) — يُعاد مسارُ الموجودة ولا يُكتب
     * ملفٌّ ثانٍ. وتُقال في `$found` بموضعها في الدفعة ونوعِ تطابقها، فتقولها
     * الشاشة. والملفُّ الموجود لا يُمحى بسقوط الدفعة: لا يمحو التنظيفُ إلّا ما
     * كتبه هو.
     *
     * و`$businessId` يحصر المطابقةَ في المتجر — وبلا متجرٍ لا مطابقة (`null`).
     * و`$originals` بصمةُ أصل كلّ صورةٍ قبل أن يصغّرها المتصفّح، بموضعها.
     *
     * @param  list<UploadedFile>  $files
     * @param  array<int, mixed>  $originals
     * @param-out list<array{at: int, name: string, kind: string, path: string}>  $found
     * @return list<string>
     */
    public static function putAll(array $files, ?int $businessId = null, array $originals = [], ?array &$found = null): array
    {
        $paths = [];
        $fresh = [];
        $found = [];
        /** بصماتُ هذه الدفعة — صورةٌ اختيرت مرّتين معًا لا صفَّ لها بعدُ يُطابَق */
        $batch = [];

        foreach (array_values($files) as $at => $file) {
            $match = null;
            $print = $businessId === null ? null : ImageFingerprints::of((string) $file->getRealPath(), $originals[$at] ?? null);
            $twin = $print === null ? null : collect($batch)->first(fn ($b) => $b['sha'] === $print['sha'] && $print['sha'] !== null);

            try {
                $path = $twin !== null ? $twin['path'] : self::put($file, $businessId, $originals[$at] ?? null, $match, $print);
            } catch (\Throwable $e) {
                report($e);
                $path = null;
            }

            if ($path === null) {
                self::forgetFiles($fresh);
                // ‏وما حُجز لهذه الدفعة يُرفع حجزُه — لا يُكتب له صفّ
                ImageFingerprints::settle(array_column($found, 'path'));

                throw ValidationException::withMessages([
                    'images' => __('تعذّر حفظ الصورة «:name» — لم يُحفظ شيء، فحاول مرّةً أخرى.', [
                        'name' => $file->getClientOriginalName(),
                    ]),
                ]);
            }

            $match ??= $twin === null ? null : ['path' => $path, 'kind' => ImageFingerprints::EXACT];

            if ($match !== null) {
                $found[] = ['at' => $at, 'name' => $file->getClientOriginalName(), 'kind' => $match['kind'], 'path' => $path];
            } else {
                $fresh[] = $path;
            }

            if ($print !== null && $twin === null) {
                $batch[] = ['sha' => $print['sha'], 'path' => $path];
            }

            $paths[] = $path;
        }

        /*
         * ومصغّراتُها للقوائم بعد أن حُفظت كلُّها — لا ينتظر التاجرُ جولةً على
         * الصور القديمة. وبعدُ لا قبلُ: نسخةٌ تتعذّر لا تُسقط دفعةً حُفظت،
         * ودفعةٌ سقطت لا تترك نسخًا (`forgetFile` يمحوها مع أصلها). والموجودةُ
         * نسخُها معها منذ رُفعت.
         */
        foreach ($fresh as $path) {
            ImageVariants::makeQuietly($path);
        }

        return $paths;
    }

    /**
     * ما يُقال للتاجر عن صورٍ وُجدت في متجره — أو `null` إن لم يوجد شيء.
     *
     * @param  list<array{at: int, name: string, kind: string, path: string}>  $found
     */
    public static function foundNotice(int $businessId, array $found, ?int $productId = null): ?string
    {
        if ($found === []) {
            return null;
        }

        $first = $found[0];
        $owner = self::ownerOf($businessId, $first['path'], $productId);

        $line = match (true) {
            $owner === null => __('صورةٌ اخترتَها رُفعت إلى متجرك من قبل — استُعملت نسختُها المحفوظة ولم تُحفظ نسخةٌ ثانية.'),
            $first['kind'] === ImageFingerprints::PIXELS => __('الصورة «:name» هي صورةُ «:product» نفسُها بصيغةٍ أخرى — استُعملت نسختُها المحفوظة ولم تُحفظ نسخةٌ ثانية.', ['name' => $first['name'], 'product' => $owner]),
            default => __('الصورة «:name» رُفعت من قبل إلى «:product» — استُعملت نسختُها المحفوظة ولم تُحفظ نسخةٌ ثانية.', ['name' => $first['name'], 'product' => $owner]),
        };

        return count($found) > 1
            ? $line.' '.__('ومعها :n صور أخرى موجودة.', ['n' => count($found) - 1])
            : $line;
    }

    /** اسمُ منتجٍ في المتجر يعرض هذه الصورة — ويُقدَّم غيرُ المنتج الذي يُرفع له */
    private static function ownerOf(int $businessId, string $path, ?int $productId): ?string
    {
        $ids = DB::table('products')->where('business_id', $businessId)->where('image', $path)->pluck('id')
            ->merge(DB::table('product_images')->where('business_id', $businessId)->where('path', $path)->pluck('product_id'))
            ->map(fn ($id) => (int) $id)->unique()->sortBy(fn ($id) => $id === $productId ? 1 : 0)->values();

        $id = $ids->first();

        return $id === null ? null : DB::table('products')->where('business_id', $businessId)->where('id', $id)->value('name');
    }

    /**
     * يحفظ صورةً مرفوعةً ويردّ مسارها — WebP مُصغَّرةً إن أمكن، وكما رُفعت إن لم يمكن.
     *
     * والرفعُ وحده يمرّ من هنا: لا يُعاد ضغطُ صورةٍ قائمة ولا يُكتب فوقها —
     * ملفٌّ يُكتب فوقه خطأً لا يُستعاد. والقديمةُ تُصنع لها نسخٌ إلى جوارها
     * دفعاتٍ صغيرة (`images:optimize`)، والأصلُ باقٍ.
     *
     * و`null` حين لا يُكتب الملفّ أصلًا — والقرصُ يردّ `false` لا استثناءً.
     *
     * ═══ والموجودةُ في المتجر تُعاد ولا تُكتب ═══
     *
     * مع `$businessId` تُسأل بصمتُها أوّلًا — قبل أن تُفكّ (`ImageFingerprints`):
     * مطابقةٌ تامّة تُعيد مسارَ الموجودة. وما لم يُطابَق يُفكّ ليُضغط، وتُسأل
     * بكسلاتُه قبل أن يُكتب منها شيء — فإن كانت هي هي أُعيدت الموجودة ولم
     * يُضغط شيء. و`$match` يقول ما وُجد، و`null` لصورةٍ كُتبت.
     *
     * @param  array{sha: ?string, orig: ?string, px: ?string, w: int, h: int, bytes: int}|null  $print
     * @param-out array{path: string, kind: string}|null  $match
     */
    public static function put(UploadedFile $file, ?int $businessId = null, ?string $original = null, ?array &$match = null, ?array $print = null): ?string
    {
        $match = null;
        $print = $businessId === null ? null : ($print ?? ImageFingerprints::of((string) $file->getRealPath(), $original));

        /*
         * والموجودةُ تُحجز قبل أن يُعتمد عليها (`ImageFingerprints::claim`): حذفُها من
         * منتجها في تبويبٍ آخر قبل أن تُكتب صفوفُ هذا الرفع لا يمحو ملفَّها. وما لم
         * يُحجز (مُحي للتوّ، أو القفلُ مشغول) يُحفظ له ملفُّه كأنّه جديد.
         */
        if ($print !== null && ($match = ImageFingerprints::find($businessId, ImageFingerprints::PRODUCTS, $print)) !== null) {
            if (ImageFingerprints::claim($match['path'])) {
                return $match['path'];
            }

            $match = null;
        }

        $known = $print === null ? null : function (string $pixels) use (&$print, &$match, $businessId) {
            $print['px'] = $pixels;
            $match = ImageFingerprints::find($businessId, ImageFingerprints::PRODUCTS, ['px' => $pixels]);

            if ($match !== null && ! ImageFingerprints::claim($match['path'])) {
                $match = null;
            }

            return $match !== null;
        };

        // ‏ودورٌ طال: يُحفظ الأصلُ كما رُفع ولا يُفكّ بالتوازي — ونسخُه تنتظر (`makeQuietly`)
        $webp = ImageVariants::exclusive(fn () => self::webp($file, $known), fn () => null);

        if ($match !== null) {
            return $match['path'];
        }

        $path = null;

        if ($webp !== null) {
            $path = self::FOLDER.'/'.Str::random(40).'.webp';

            if (! Storage::disk(self::DISK)->put($path, $webp)) {
                $path = null;
            }
        }

        $path ??= $file->store(self::FOLDER, self::DISK);
        $path = is_string($path) && $path !== '' ? $path : null;

        // ‏وما حُفظ يُكتب كما هو: نسخةُ عرضٍ فاقدة (WebP)، أو الملفُّ كما رُفع
        if ($path !== null && $print !== null) {
            ImageFingerprints::remember($businessId, $path, $print, $webp !== null ? 'converted' : 'as_uploaded');
        }

        return $path;
    }

    /**
     * الصورةُ WebP مُدارةً ومُصغَّرة — أو `null` فيُحفظ الأصلُ كما رُفع.
     *
     * ═══ ومتى يُحفظ الأصل ═══
     *
     *  • المفتاحُ مطفأ (`images.product_webp`)، أو الخادمُ بلا WebP.
     *  • GIF: قد تتحرّك، وGD يفكّ إطارها الأوّل وحده — فتصير صورةً ساكنة.
     *  • ما لا يُفكّ، أو لا تتّسع الذاكرةُ لفكّه (`StoreImageUpload::roomFor`).
     *  • ما كُتب ولم يُقرأ صورةً بأبعادها — لا يحلّ ملفٌّ تالفٌ محلّ الأصل.
     *  • ما لم يصغر: صورةٌ خفيفةٌ أصلًا لا تُستبدل بأثقل منها.
     *
     * والامتدادُ يتبع المحتوى: `.webp` لما حُوّل فعلًا، وامتدادُ الأصل لما لم
     * يُحوَّل — لا ملفّ JPEG باسم WebP.
     *
     * ═══ والاتّجاه ═══
     *
     * الهاتفُ يحفظ الصورةَ مائلةً ويكتب في رأسها «أدِرها» (EXIF)، وإعادةُ
     * الكتابة تمحو الرأس — فتُدار قبلها وإلّا ظهرت الباقةُ على جنبها. ويُقرأ
     * الرأسُ بلا امتداد `exif` (`StoreImageUpload::orientation`): قد لا يكون
     * في الخادم، فلا يسقط الرفعُ لغيابه.
     *
     * و`$known` يُسأل ببصمة البكسلات بعد الإدارة وقبل التصغير: إن قال «موجودة»
     * رُدّ `null` ولم يُضغط شيء — ومن ناداه يعرف لماذا.
     *
     * @param  (\Closure(string): bool)|null  $known
     */
    private static function webp(UploadedFile $file, ?\Closure $known = null): ?string
    {
        if (! config('images.product_webp', true) || ! function_exists('imagewebp')) {
            return null;
        }

        $source = (string) $file->getRealPath();
        $info = $source === '' ? false : @getimagesize($source);

        if (! is_array($info) || $info[0] < 1 || $info[1] < 1) {
            return null;
        }

        [$w, $h, $type] = $info;

        $decode = match ($type) {
            IMAGETYPE_JPEG => 'imagecreatefromjpeg',
            IMAGETYPE_PNG => 'imagecreatefrompng',
            IMAGETYPE_WEBP => 'imagecreatefromwebp',
            default => null,
        };

        if ($decode === null || ! function_exists($decode)) {
            return null;
        }

        // ‏أبعادٌ فوق السقف تُحفظ كما رُفعت — ويُكتب لماذا لم تُضغط
        if ($w * $h > StoreImageUpload::MAX_PIXELS) {
            ImageMemory::note('product_upload', 'too_large', $w, $h);

            return null;
        }

        if (! StoreImageUpload::roomFor($w * $h)) {
            return null;
        }

        // ‏وذاكرةُ الخادم لا حدُّ PHP: ما لا يتّسع له الآن يُحفظ كما رُفع، والرفعُ ينجح
        if (ImageMemory::shortFor($w * $h) !== null) {
            ImageMemory::note('product_upload', 'low_memory', $w, $h);

            return null;
        }

        try {
            $image = @$decode($source);

            if (! $image instanceof \GdImage) {
                return null;
            }

            if ($type === IMAGETYPE_JPEG) {
                $image = StoreImageUpload::oriented($image, StoreImageUpload::orientation($source));
            }

            // ‏والصورةُ نفسُها بصيغةٍ أخرى تُعرف ببكسلاتها كلِّها — قبل أن تُصغَّر
            if ($known !== null && ($pixels = ImageFingerprints::pixels($image)) !== null && $known($pixels)) {
                return null;
            }

            $image = StoreImageUpload::fitted($image, self::MAX_SIDE);
            $w = imagesx($image);
            $h = imagesy($image);

            ob_start();

            try {
                $written = imagewebp($image, null, self::QUALITY);
            } finally {
                $bytes = (string) ob_get_clean();
            }
        } catch (\Throwable) {
            return null;
        }

        $check = $written && $bytes !== '' ? @getimagesizefromstring($bytes) : false;

        if (! is_array($check) || $check[2] !== IMAGETYPE_WEBP || $check[0] !== $w || $check[1] !== $h) {
            return null;
        }

        $size = (int) @filesize($source);

        return $size > 0 && strlen($bytes) < $size ? $bytes : null;
    }

    /**
     * يرتّب صورَ المعرض — ما بعد الرئيسية — بالترتيب الذي يُعطى.
     *
     * والقائمةُ يجب أن تكون صورَ المنتج نفسَها كلَّها، لا أقلّ ولا أكثر:
     * شاشةٌ فُتحت قبل حذفٍ أو رفعٍ من تبويبٍ آخر ترسل قائمةً قديمة، وترتيبُها
     * على ما بقي يُخفي صورةً عن ترتيبٍ لم يرَه التاجر. فتُردّ بسببٍ يُقال.
     * ومعرّفُ صورةٍ لمنتجٍ آخر — في متجره أو غيره — لا يطابق أبدًا.
     *
     * @param  list<int>  $ids
     */
    public static function reorder(Product $product, array $ids): void
    {
        DB::transaction(function () use ($product, $ids) {
            $locked = Product::where('business_id', $product->business_id)->lockForUpdate()->findOrFail($product->id);
            $have = ProductImage::where('product_id', $locked->id)->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
            $sent = collect($ids)->map(fn ($id) => (int) $id)->sort()->values()->all();

            if ($have !== $sent) {
                throw ValidationException::withMessages([
                    'images' => __('تغيّرت صور المنتج منذ فُتحت الصفحة — أعد فتحها ثمّ رتّبها.'),
                ]);
            }

            foreach (array_values($ids) as $slot => $id) {
                ProductImage::where('product_id', $locked->id)->whereKey($id)->update(['sort_order' => $slot + 1]);
            }
        });
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
            self::release($path);
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

            self::release($path);

            return $heir !== null;
        });
    }

    /**
     * نسخةٌ خاصّةٌ من صورة المنتج الرئيسية — لِما يُنسخ منه منتجٌ جديد.
     *
     * ولا يُنسخ المسار كما هو: منتجان يشيران إلى ملفٍّ واحد، فمحوُ أحدهما
     * نهائيًّا يمحو الملفّ ويترك الآخر — وهو حيٌّ معروض — بصورةٍ مكسورة.
     *
     * وملفٌّ مفقودٌ على القرص لا يُنسخ ولا يُنسَخ مسارُه: نسخةٌ بمسارٍ إلى
     * لا شيء تُعرض مربّعًا مكسورًا، والفراغُ يُعرض حالتَه الفارغة.
     */
    public static function copyMainFile(Product $source): ?string
    {
        $path = $source->getRawOriginal('image');

        if (blank($path)) {
            return null;
        }

        // ‏رابطٌ خارجيّ ليس ملفًّا عندنا: يُنسخ نصُّه ولا يمحوه محوُ منتج
        if (str_starts_with((string) $path, 'http')) {
            return (string) $path;
        }

        if (! Storage::disk(self::DISK)->exists($path)) {
            return null;
        }

        $extension = pathinfo((string) $path, PATHINFO_EXTENSION);
        $copy = self::FOLDER.'/'.Str::random(40).($extension ? '.'.$extension : '');

        if (! Storage::disk(self::DISK)->copy($path, $copy)) {
            return null;
        }

        ImageVariants::makeQuietly($copy);

        return $copy;
    }

    /**
     * مساراتُ صور المنتج على القرص — الرئيسيةُ والمعرض، بلا روابط خارجيّة.
     *
     * @return list<string>
     */
    public static function pathsOf(Product $product): array
    {
        return array_values(array_filter([
            $product->getRawOriginal('image'),
            ...ProductImage::where('product_id', $product->id)->pluck('path')->all(),
        ], fn ($path) => filled($path) && ! str_starts_with((string) $path, 'http')));
    }

    /**
     * ملفّاتُ المنتج التي تُمحى معه — الرئيسية والمعرض.
     *
     * لمن يمحو المنتج نهائيًّا: الصفوف يأخذها قيدُ المفتاح، والملفّات لا
     * يأخذها شيء. ومتجرٌ يمحو موسمه كلَّه يترك عشرات الميغابايت لصورٍ لا
     * صفَّ يشير إليها — عطبٌ صامت لا يظهر إلا بعدّ ما على القرص.
     *
     * ═══ وملفٌّ يشير إليه منتجٌ آخر لا يُمحى ═══
     *
     * «نسخُ منتج» كان ينسخ مسار الصورة كما هو، فيتقاسم المنتجان ملفًّا
     * واحدًا. ثمّ يُحذف أحدهما وتمرّ عليه سلّةُ المحذوفات بعد تسعين يومًا
     * فيُمحى الملفّ — فيفقد **الآخر، وهو حيٌّ معروضٌ في المتجر**، صورتَه.
     * ولا يُعرف السبب: لا أحد لمس ذلك المنتج، والصفحةُ تعرض مربّعًا مكسورًا
     * لا حالةً فارغة، لأنّ عموده ما زال يحمل مسارًا.
     *
     * والنسخُ صار يملك ملفَّه (انظر `ProductController::duplicate`) — وهذا
     * لما نُسخ قبل ذلك.
     *
     * @return list<string>
     */
    public static function files(Product $product): array
    {
        $mine = array_values(array_filter([
            $product->getRawOriginal('image'),
            ...ProductImage::where('product_id', $product->id)->pluck('path')->all(),
        ], fn ($path) => filled($path) && ! str_starts_with((string) $path, 'http')));

        if ($mine === []) {
            return [];
        }

        /*
         * ما يشير إليه غيرُه — منتجًا كان أو صورةَ معرضٍ لمنتجٍ آخر — يبقى.
         *
         * والقراءةُ من مُنشئ الاستعلام لا من النموذج: `pluck('image')` على
         * `Product` تمرّ بالقارئ فتردّ «‎/storage/…‎» لا المسار الخام، فلا
         * يطابق شيئًا — وتُمحى الملفّات المشتركة كما لو لم يكن هناك حارس.
         * والمحذوفُ مشمول: صفٌّ في السلّة ما زال يشير إلى ملفّه.
         */
        $shared = DB::table('products')
            ->where('business_id', $product->business_id)
            ->where('id', '!=', $product->id)
            ->whereIn('image', $mine)
            ->pluck('image')
            ->merge(
                DB::table('product_images')
                    ->where('business_id', $product->business_id)
                    ->where('product_id', '!=', $product->id)
                    ->whereIn('path', $mine)
                    ->pluck('path')
            )
            ->all();

        return array_values(array_diff($mine, $shared));
    }

    /**
     * يمحو ملفًّا من القرص — والرابط الخارجيّ ليس ملفًّا فلا يُمسّ.
     *
     * لمن عرف أنّ لا شيء غيرَه يشير إليه (`files` لمحو منتجٍ نهائيًّا) — و`$still`
     * يُعيد السؤالَ تحت القفل، فصفٌّ كُتب بين السؤالين يُبقي الملف. وما سواه يمرّ
     * بـ`release`. وملفٌّ يحجزه رفعٌ جارٍ لا يُمحى أبدًا (`ImageFingerprints::claim`).
     */
    public static function forgetFile(?string $path, ?callable $still = null): void
    {
        if (blank($path) || str_starts_with($path, 'http')) {
            return;
        }

        ImageFingerprints::unlessClaimed($path, function () use ($path) {
            Storage::disk(self::DISK)->delete($path);
            ImageVariants::forget($path);
        }, $still);
    }

    /**
     * يمحو الملفَّ إن لم يبقَ ما يعرضه — وإلّا تركه.
     *
     * صورةٌ واحدةٌ قد يعرضها منتجان في المتجر (رُفعت لهما فحُفظت مرّةً واحدة،
     * أو نُسخ منتجٌ قبل أن يملك النسخُ ملفَّه). فحذفُها من أحدهما يحذف صفَّه
     * وحده، ويبقى الملفُّ للآخر — وإلّا فقد منتجٌ حيٌّ صورتَه ولم يلمسه أحد.
     *
     * ويُسأل بعد أن كُتب ما يُحذف (الصفُّ محذوف أو العمودُ مبدَّل) — في المعاملة
     * نفسها، فلا يُعدّ الصفُّ الذي يُحذف مرجعًا.
     */
    public static function release(?string $path): void
    {
        if (blank($path) || str_starts_with((string) $path, 'http') || self::inUse((string) $path)) {
            return;
        }

        // ‏ويُسأل ثانيةً تحت القفل: رفعٌ أعاد استعمالَها قد كتب صفَّه بين السؤالين
        self::forgetFile($path, fn () => ! self::inUse((string) $path));
    }

    /**
     * أيعرض هذه الصورةَ شيءٌ حيّ؟ — منتجٌ (ولو في السلّة)، أو صورةُ معرض، أو
     * بطاقةُ فئة، أو إعدادٌ يحمل مسارها. في كلّ المتاجر: الحذفُ لا يُخطئ بالحصر.
     */
    public static function inUse(string $path): bool
    {
        $text = 'CAST('.DB::getQueryGrammar()->wrap('value').' AS TEXT)';

        return DB::table('products')->where('image', $path)->exists()
            || DB::table('product_images')->where('path', $path)->exists()
            || DB::table('categories')->whereIn('card_image', [$path, '/storage/'.$path])->exists()
            || DB::table('settings')->whereRaw($text.' LIKE ?', ['%'.basename($path).'%'])->exists();
    }

    /**
     * يمحو ملفّاتٍ حُفظت لطلبٍ لم يكتمل — ما لا يعرضه غيرُه.
     *
     * والمحوُ لا يُسقط ما بعده: ملفٌّ لا يُمحى يُترك ويُكمَل غيرُه — ويبقى
     * الخطأُ الأصليُّ هو ما يُرفع لا خطأُ التنظيف. وصورةٌ أُعيد استعمالُها من
     * منتجٍ آخر لا تُمحى بسقوط طلبٍ لم يكتبها (`release`).
     *
     * @param  list<string>  $paths
     */
    public static function forgetFiles(array $paths): void
    {
        foreach (array_unique($paths) as $path) {
            try {
                self::release($path);
            } catch (\Throwable $e) {
                report($e);
            }
        }
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
