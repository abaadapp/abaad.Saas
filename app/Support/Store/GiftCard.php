<?php

namespace App\Support\Store;

use App\Models\Order;
use App\Models\Product;
use App\Support\MarketingSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;

/**
 * كرتُ الهدية — صنفٌ يُباع، لا خانةُ نصٍّ مجّانية.
 *
 * ═══ ولمَ بندٌ في الفاتورة ═══
 *
 * كان النصُّ يُكتب بلا ثمن: يخرج الكرتُ من الدرج، ويُطبع، ويُربط بالباقة
 * — ولا يُقرأ في تقريرٍ أبدًا. فلا صاحبُ المحلّ يعرف كم كرتًا باع، ولا
 * الدفترُ يعرف أنّ خمسَ مئةِ بيسةٍ دخلت.
 *
 * فصار سطرًا في الطلب كأيّ صنف: يدخل المجموعَ والضريبةَ والإيراد، ويُعدّ
 * في تقرير المنتجات («كرت هدية — ٤٧ قطعة»). ولا مسارَ مالٍ ثانٍ يُبنى له.
 *
 * ═══ ولا يُخصم من رفّ ═══
 *
 * صنفُه `no_stock`: لا رصيدَ له يُفحص ولا حركةَ تُكتب. فكرتٌ يُباع مئةَ
 * مرّةٍ لا يُوقفه رفٌّ نفد، ولا يُطالَب صاحبُ المحلّ بجردِ كرتونةِ كروت.
 *
 * وهو مُطفأٌ وغيرُ منشور: لا يظهر في شبكة المتجر ليُضاف إلى السلّة وحده،
 * ولا في بحث الصندوق. بابُه الوحيد خانةُ الاختيار في إتمام الطلب.
 */
final class GiftCard
{
    /** اسمُ الصنف الذي يحمله في الفاتورة وفي التقارير */
    public const PRODUCT_NAME = 'كرت هدية';

    /**
     * ترتيبُ النصّ على الكرت — ثلاثةٌ لا أكثر.
     *
     * والأوّلُ هو الافتراضيّ: العربيّةُ تُكتب من اليمين، ومن لم يختر شيئًا
     * أراد ما اعتاده لا ما يخترعه النظام.
     */
    public const ALIGNS = ['right', 'center', 'left'];

    /** أقصى حجمٍ للملفّ المرفق — بالكيلوبايت */
    public const MAX_KB = 5120;

    /** ما يُقبل رفعه: صورةٌ يكتبها بخطّ يده، أو ملفُّ تصميمٍ جاهز */
    public const MIMES = ['jpg', 'jpeg', 'png', 'webp', 'heic', 'pdf'];

    /** مجلّدُ ما رُفع ولم يُربط بطلبٍ بعد */
    private const PENDING = 'gift-cards/pending';

    /** مجلّدُ ما صار لطلب */
    private const KEPT = 'gift-cards/kept';

    /** ما مضى على ملفٍّ معلَّقٍ فصار يُكنس — بالساعات */
    private const PENDING_HOURS = 24;

    /* ═══════════ أيُعرض أصلًا ═══════════ */

    /**
     * أيُعرض الكرتُ للبيع في هذا المحلّ؟
     *
     * ═══ مفتاحٌ وثمنٌ معًا، لا مفتاحٌ وحدَه ═══
     *
     * لا ثمنَ افتراضيَّ في النظام. فصاحبُ المحلّ هو من يُسعّر كرتَه، والشاشةُ
     * تمنع رفعَ المفتاح بلا ثمنٍ أكبرَ من صفر (`MarketingController::saveStore`).
     *
     * ويبقى السؤالُ هنا عن الاثنين لا عن المفتاح وحدَه: متجرٌ رفع مفتاحَه
     * قبل هذه القاعدة وترك الثمنَ فارغًا لا يُعرض كرتُه بثمنٍ يخترعه النظام
     * — يُطوى حتّى يُسعّره صاحبُه. وبابُ الرفع وبابُ الطلب يقرآن هذا الجواب،
     * فلا يُقبل مرفقٌ لكرتٍ لا يُباع.
     */
    public static function enabled(int $businessId): bool
    {
        $values = MarketingSettings::group($businessId, 'website');

        return (string) ($values['store_gift_card'] ?? '0') === '1'
            && self::readPrice($values) !== null;
    }

    /**
     * ثمنُه كما كتبه صاحبُ المحلّ — و`null` إن لم يكتب.
     *
     * ═══ ولا ثمنَ يخترعه النظام ═══
     *
     * كان الفراغُ يُقرأ «خمسَ مئةِ بيسة»، فيُباع في متجرٍ لم يُسعّر كرتَه
     * بثمنٍ لم يختره أحد — ويدخل فاتورةَ زبونٍ وإيرادَ دفتر. فصار الفراغُ
     * يُقرأ فراغًا: لا كرتَ يُعرض حتّى يُكتب ثمنُه.
     *
     * والصفرُ فراغٌ كذلك — لا «كرتٌ مجّانيّ»: بندٌ بصفرٍ في فاتورةٍ يقرؤه
     * الزبونُ سهوًا لا عطيّة، والقاعدةُ اليومَ «ثمنٌ صالحٌ أكبرُ من صفر».
     *
     * والتقريبُ إلى ثلاث خانات: خاناتُ الريال العُمانيّ، وهي ما تُعرض به
     * (`Money::format`) وما يُحسب به البند.
     */
    public static function price(int $businessId): ?float
    {
        return self::readPrice(MarketingSettings::group($businessId, 'website'));
    }

    /**
     * الثمنُ من قيمٍ مقروءةٍ سلفًا — فلا يُسأل الإعدادُ مرّتين في الطلب الواحد.
     *
     * @param  array<string, mixed>  $values
     */
    private static function readPrice(array $values): ?float
    {
        $raw = trim((string) ($values['store_gift_card_price'] ?? ''));

        if ($raw === '' || ! is_numeric($raw)) {
            return null;
        }

        $price = round((float) $raw, 3);

        return $price > 0 ? $price : null;
    }

    /** أطلب الزبونُ كرتًا؟ — وهو اختيارٌ صريح لا يُستنتج من كتابته نصًّا */
    public static function wanted(int $businessId, array $payload): bool
    {
        return self::enabled($businessId) && filter_var($payload['gift_card'] ?? false, FILTER_VALIDATE_BOOL);
    }

    /** المحاذاةُ من القائمة وحدَها — وما سواها يُردّ إلى الأولى */
    public static function align(?string $raw): string
    {
        return in_array($raw, self::ALIGNS, true) ? $raw : self::ALIGNS[0];
    }

    /* ═══════════ الصنفُ الذي يُباع ═══════════ */

    /**
     * صنفُ الكرت في هذا المحلّ — يُنشأ مرّةً ويُقرأ بعدها.
     *
     * والبحثُ بالاسم داخل المحلّ: لا إعدادَ يحمل معرّفَه — فإعدادٌ يحمل
     * معرّفًا لصنفٍ حُذف يُنشئ بندًا لصنفٍ لا وجود له، ويسقط التقرير الذي
     * يقرأ اسمَه.
     *
     * وثمنُه يُكتب في البطاقة كذلك ليُقرأ في شاشة الأصناف، والمعتمَدُ في
     * البيع ثمنُ الإعداد — موضعٌ واحد يحكم.
     *
     * والثمنُ يصل وسيطًا لا يُقرأ هنا: الصنفُ لا يُنشأ إلّا في سطرِ بيعٍ
     * قائم، وقد قُرئ ثمنُه هناك وتُحُقِّق أنّه موجود.
     */
    public static function product(int $businessId, float $price): Product
    {
        $product = Product::where('business_id', $businessId)
            ->where('name', self::PRODUCT_NAME)->first();

        if ($product === null) {
            $product = Product::create([
                'business_id' => $businessId,
                'name' => self::PRODUCT_NAME,
                'name_en' => 'Gift card',
                'price' => $price,
                'cost' => 0,
                'quantity' => 0,
                'alert_qty' => 0,
                /*
                 * مُطفأٌ وغيرُ منشور — وهو المقصود.
                 *
                 * لا يُعرض في شبكة المتجر فيُضاف إلى السلّة وحده بلا نصٍّ
                 * ولا مستلِم، ولا في بحث الصندوق فيُباع بلا كرتٍ يخرج.
                 * بابُه الوحيد خانةُ الاختيار في إتمام الطلب.
                 */
                'active' => false,
                'published' => false,
            ]);
        }

        return $product;
    }

    /**
     * سطرُ الكرت كما تقرؤه بقيّةُ المسار — سطرٌ كأيّ سطر.
     *
     * فالمجموعُ والضريبةُ والخصمُ والبندُ في الطلب كلُّها تُحسب بالقاعدة
     * القائمة، ولا يُستثنى الكرتُ في موضع. وما يُميّزه علامةٌ واحدة:
     * `no_stock` — انظر `SaleLines::demand`.
     *
     * @return array<string, mixed>
     */
    public static function line(int $businessId): array
    {
        $price = self::price($businessId);

        /*
         * ولا يُبنى سطرٌ بلا ثمن.
         *
         * البابُ الوحيد إلى هنا `wanted`، وهي تسأل `enabled` وهي تسأل عن
         * الثمن. فبلوغُ هذا السطر بلا ثمنٍ يعني أنّ بابًا جديدًا فُتح ولم
         * يسأل — ويُقال صراحةً لا يُسدّ بصفرٍ يدخل فاتورةَ زبون.
         */
        if ($price === null) {
            throw new LogicException('سطرُ كرت هدية بلا ثمن — '.self::class.'::wanted هي البابُ الوحيد.');
        }

        $product = self::product($businessId, $price);

        return [
            'product' => $product,
            'variant' => null,
            'name' => $product->name,
            'price' => $price,
            'qty' => 1,
            'cost' => 0.0,
            'addons' => [],
            'addons_total' => 0.0,
            'has_recipe' => false,
            'recipe' => null,
            'components' => [],
            'note' => null,
            // لا رفَّ له يُفحص ولا حركةَ تُكتب — بابُ العلامة في `SaleLines::demand`
            'no_stock' => true,
        ];
    }

    /* ═══════════ الملفُّ المرفق ═══════════ */

    /**
     * يُحفظ ما رفعه الزائر معلَّقًا، ويُعاد رمزٌ يُرسَل مع الطلب.
     *
     * ═══ ولمَ رمزٌ لا مسار ═══
     *
     * الرافعُ زائرٌ مجهول، وما يعود إليه يعود منه في الطلب التالي. فلو عاد
     * مسارًا لَأرسل مسارًا من عنده — «‎../../‎» وما بعدها — فيُربط بطلبه
     * ملفٌّ ليس له. والرمزُ اسمٌ عشوائيٌّ يُفحص بنمطٍ صارم ويُقرأ من مجلّدٍ
     * واحدٍ لا يخرج منه.
     *
     * @return array{token: string, name: string}
     */
    public static function hold(UploadedFile $file): array
    {
        self::sweep();

        $ext = strtolower($file->getClientOriginalExtension() ?: 'bin');
        $token = Str::random(40).'.'.$ext;

        $file->storeAs(self::PENDING, $token, 'local');

        return ['token' => $token, 'name' => self::cleanName($file->getClientOriginalName(), $ext)];
    }

    /**
     * يُثبَّت الملفُّ المعلَّق فيصير للطلب — أو `null` إن لم يكن ثَمّ ملفّ.
     *
     * ويُنقل لا يُنسخ: نسخةٌ تبقى معلّقةً تُكنس بعد يوم، فيفقد طلبٌ مرفقَه
     * بلا أن يمسّه أحد.
     *
     * @return array{path: string, name: string}|null
     */
    public static function keep(int $businessId, ?string $token, ?string $name): ?array
    {
        if (! is_string($token) || ! preg_match('/^[A-Za-z0-9]{40}\.[A-Za-z0-9]{1,8}$/', $token)) {
            return null;
        }

        $from = self::PENDING.'/'.$token;

        if (! Storage::disk('local')->exists($from)) {
            return null;
        }

        $to = self::KEPT.'/'.$businessId.'/'.$token;
        Storage::disk('local')->move($from, $to);

        $ext = pathinfo($token, PATHINFO_EXTENSION);

        return ['path' => $to, 'name' => self::cleanName((string) $name, $ext)];
    }

    /**
     * يُكنس ما رُفع ولم يُتمّ صاحبُه طلبَه.
     *
     * زائرٌ يرفع صورةً ثمّ يُغلق الصفحة يترك ملفًّا لا يقرؤه أحد. وبلا كنسٍ
     * يمتلئ القرصُ بما لا صاحبَ له — وهو مسارٌ مفتوحٌ لمن أراد ملأه عمدًا.
     */
    public static function sweep(): void
    {
        $disk = Storage::disk('local');
        $deadline = now()->subHours(self::PENDING_HOURS)->getTimestamp();

        foreach ($disk->files(self::PENDING) as $file) {
            if ($disk->lastModified($file) < $deadline) {
                $disk->delete($file);
            }
        }
    }

    /**
     * الاسمُ كما يُعرض — منزوعًا منه كلُّ ما يدلّ على مسار.
     *
     * يصل من زائرٍ مجهول ويُكتب في ترويسة تنزيل: اسمٌ فيه `/` أو سطرٌ جديد
     * يكسر الترويسة، واسمٌ طويلٌ يملأ عمودًا.
     */
    private static function cleanName(string $raw, string $ext): string
    {
        $name = trim(preg_replace('/[\x00-\x1F\/\\\\"]+/u', ' ', $raw) ?? '');
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');

        if ($name === '' || $name === '.') {
            return 'gift-card.'.$ext;
        }

        return mb_substr($name, 0, 120);
    }

    /* ═══════════ ما يُكتب على الطلب ═══════════ */

    /**
     * أعمدةُ الكرت على الطلب — والمحاذاةُ لا تُكتب لطلبٍ بلا كرت.
     *
     * محاذاةٌ محفوظةٌ بلا نصٍّ ولا كرتٍ تُقرأ في شاشة التجهيز كأنّ ثَمّ
     * كرتًا يُكتب.
     *
     * @param  array{path: string, name: string}|null  $file
     * @return array<string, mixed>
     */
    public static function columns(bool $wanted, ?string $align, ?array $file): array
    {
        if (! $wanted) {
            return ['card_align' => null, 'card_file' => null, 'card_file_name' => null];
        }

        return [
            'card_align' => self::align($align),
            'card_file' => $file['path'] ?? null,
            'card_file_name' => $file['name'] ?? null,
        ];
    }

    /** يُحذف مرفقُ طلبٍ زال — ولا يُترك على القرص بلا قارئ */
    public static function forget(Order $order): void
    {
        if (filled($order->card_file)) {
            Storage::disk('local')->delete($order->card_file);
        }
    }
}
