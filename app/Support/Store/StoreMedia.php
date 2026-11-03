<?php

namespace App\Support\Store;

use App\Support\ImageVariants;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;

/**
 * صورةٌ رفعها صاحبُ المتجر لمتجره — وما سواها لا يُقبل في المجموعات والشريط.
 *
 * ═══ ولمَ لا يكفي `SafeLink` هنا ═══
 *
 * `SafeLink` يمنع `javascript:` وأخواتها ويقبل كلَّ رابطٍ آمنِ الشكل. وصورُ
 * المجموعات وبطاقاتُ الشريط تُرسم في أعلى كلّ صفحةٍ يفتحها زبون، فرابطٌ
 * خارجيٌّ فيها بكسلُ تتبّعٍ يقرأ زوّارَ المتجر لغيره، ورابطٌ إلى مجلّد متجرٍ
 * آخر صورةٌ لم يرفعها صاحبُ هذا المتجر.
 *
 * فالمقبولُ ما يردّه بابُ الرفع نفسُه (`MarketingController::uploadStoreImage`):
 * ملفٌّ واحدٌ في `website/{معرّف المتجر}/` على القرص العامّ — باسمه الذي
 * أعطاه الرفع، بلا مجلّدٍ آخر ولا `..` ولا استعلام.
 */
final class StoreMedia
{
    /** اسمُ ملفٍّ كما يُعطيه الرفع — حروفٌ وأرقامٌ ونقطةُ الامتداد */
    private const FILE = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,190}$/';

    /**
     * طريقتا عرض الصورة في إطارها.
     *
     * «كاملة» (`contain`) تُري الصورةَ كلَّها بنسبتها، وما بقي من الإطار لونُ
     * الواجهة الهادئ — لا قصَّ ولا مطّ. و«ملء الإطار» (`cover`) تملؤه وتقصّ ما
     * زاد من طرفيها، وهي ما كانت عليه الصورُ كلُّها قبل أن يُسأل صاحبُها.
     */
    public const CONTAIN = 'contain';

    public const COVER = 'cover';

    public const FITS = [self::CONTAIN, self::COVER];

    /**
     * طريقةُ العرض كما تُرسم — والفراغُ «ملء الإطار».
     *
     * فصورةٌ رُفعت قبل أن يُسأل عن طريقتها تبقى كما رآها زبونُه حرفًا بحرف،
     * وما يُرفع من المحرّر بعدُ يُحفظ «كاملة» (`StoreImageField`). وما لا يُعرف
     * من القيم يُقرأ «ملء الإطار» — لا قيمةَ حرّةٌ تبلغ `style`.
     */
    public static function fit(?string $raw): string
    {
        return $raw === self::CONTAIN ? self::CONTAIN : self::COVER;
    }

    /**
     * أبعادُ صورةٍ رفعها هذا المتجر — من ملفّها على القرص، أو `null`.
     *
     * تُكتب في `width` و`height` فيحجز المتصفّحُ مكانَها قبل أن تصل، ولا تقفز
     * الصفحة. وتُقرأ من رأس الملفّ وحده (`getimagesize`) ولا تُفكّ الصورة.
     * وما ليس من مجلّده لا يُقرأ: لا يُفتح بهذا البابِ ملفٌّ خارج `website/{id}/`.
     *
     * @return array{w: int, h: int}|null
     */
    public static function size(int $businessId, string $url): ?array
    {
        if ($url === '' || ! self::owned($businessId, $url)) {
            return null;
        }

        $file = self::fileName($businessId, $url);
        $path = $file === null ? null : Storage::disk('public')->path('website/'.$businessId.'/'.$file);
        $info = $path !== null && is_file($path) ? @getimagesize($path) : false;

        return is_array($info) && $info[0] > 0 && $info[1] > 0
            ? ['w' => (int) $info[0], 'h' => (int) $info[1]]
            : null;
    }

    /** اسمُ الملفّ من رابطٍ يملكه المتجر — أو `null` */
    private static function fileName(int $businessId, string $url): ?string
    {
        foreach (self::prefixes($businessId) as $prefix) {
            if (str_starts_with($url, $prefix)) {
                return substr($url, strlen($prefix));
            }
        }

        return null;
    }

    /*
     * ═══ عرضُ الصورة المرسومُ في مكانها (`sizes`) ═══
     *
     * قواعدُ كلّ مكانٍ من CSS نفسه: [أقلُّ عرضٍ للشاشة، نسبةٌ من vw، ما يُضاف بالبكسل]
     * من الأوسع إلى الأضيق، والأخيرةُ لما دونها. وفوق ١٢٨٠ الإطارُ ثابتٌ فعرضُه ثابت
     * (نسبتُه صفر). وكلُّها في إطار `.rb-wrap`/`.rb-section`: ١٢٨٠ وحاشيتان ٢٤.
     * فتغييرُ CSS مكانٍ يغيّر قاعدتَه هنا معه (`ADisplayPictureAsksForItsDrawnWidthTest`).
     */

    /**
     * الواجهة (`home`): عمودان لا يقلّان عن ٣٤٠ وفاصلٌ `clamp(28px, 4vw, 56px)` — عمودٌ واحدٌ
     * تحت ٧٥٩. وإطارُها ٥:٤ بارتفاعٍ لا يقلّ عن ٢٤٠، فلا يضيق عن ٣٠٠ في أضيق الشاشات.
     */
    public const HERO = [[1280, 0, 591], [759, 48, -24], [348, 100, -48], [0, 0, 300]];

    /** الشريط (`sections/banner`): حاشيةٌ داخليّة `clamp(24px, 4vw, 56px)`، عمودان لا يقلّان عن ٣٠٠ وفاصل ٣٢ */
    public const BANNER = [[1280, 0, 549], [740, 46, -40], [601, 92, -48], [0, 100, -96]];

    /** القسمُ الحرّ (`sections/block`): عمودان لا يقلّان عن ٣٢٠ وفاصل ٤٠ */
    public const BLOCK = [[1280, 0, 596], [728, 50, -44], [0, 100, -48]];

    /** صفحة «عنّا» (`about`): عمودان لا يقلّان عن ٣٢٠ وفاصلٌ `clamp(28px, 4vw, 52px)` */
    public const ABOUT = [[1280, 0, 591], [717, 48, -24], [0, 100, -48]];

    /** بعرض الإطار كلِّه: رأسُ المجموعة، وصورةُ الصفحة، والقسمُ وصورتُه فوق نصّه */
    public const WIDE = [[1280, 0, 1232], [0, 100, -48]];

    /** معرضُ الصفحة (`page`): `auto-fill` بأعمدةٍ لا تقلّ عن ٢٦٠ وفاصل ١٦ — العددُ بعرض الشاشة لا بالصور */
    public const GALLERY = [[1280, 0, 296], [1136, 25, -24], [860, 33.34, -26.66], [584, 50, -32], [0, 100, -48]];

    /** قسمُ الصفحة بصورةٍ ونصّ (`page`): عمودان لا يقلّان عن ٣٢٠ وفاصلٌ `clamp(20px, 4vw, 48px)` */
    public const SECTION = [[1280, 0, 592], [1200, 50, -48], [717, 48, -24], [0, 100, -48]];

    /** عرضُ الصفحة (`page` ‏`.rb-cp-promo`): حاشيةٌ `clamp(24px, 4vw, 48px)`، عمودان لا يقلّان عن ٣٠٠ وفاصلٌ `clamp(20px, 4vw, 40px)` */
    public const PROMO = [[1280, 0, 548], [1200, 50, -92], [1000, 46, -44], [737, 44, -24], [601, 92, -48], [0, 100, -96]];

    /**
     * `sizes` من قواعد مكانٍ — مضروبةً في نسبة ما يُرسم من الصورة إلى عرض إطارها (`factor`).
     *
     * ويُقرَّب لصالح الوضوح: نسبةُ vw والعرضُ الثابت إلى أعلى، وما يُطرح إلى أسفل.
     *
     * @param  list<array{0: int, 1: float|int, 2: float|int}>  $rules
     */
    public static function sizes(array $rules, float $f = 1.0): string
    {
        $num = fn (float $n) => rtrim(rtrim(sprintf('%.2F', $n), '0'), '.');
        $out = [];

        foreach ($rules as [$from, $vw, $plus]) {
            // ‏وضجيجُ الكسور العشريّة يُطرح قبل التقريب: ٣٣٫٣٤ × ١٠٠ لا تصير ٣٣٣٥
            $add = round($plus * $f, 6);
            $vw = round($vw * $f * 100, 6);
            $value = $vw == 0 ? $num(ceil($add)).'px'
                : 'calc('.$num(ceil($vw) / 100).'vw '.($add < 0 ? '- '.$num(floor(round(-$add * 100, 6)) / 100) : '+ '.$num(ceil(round($add * 100, 6)) / 100)).'px)';
            $out[] = $from > 0 ? '(min-width: '.$from.'px) '.$value : $value;
        }

        return implode(', ', $out);
    }

    /**
     * كم يُرسم من الصورة نسبةً إلى عرض إطارها — من أبعادها الحقيقيّة.
     *
     * «ملء الإطار» يرسم الأعرضَ من الإطار بارتفاعه فيفيض عرضُه (أكثرُ من واحد)، و«كاملة»
     * ترسم الأطولَ منه أضيقَ منه (أقلُّ من واحد). وبلا أبعادٍ معروفة، أو بلا إطارٍ ثابت
     * النسبة (`$box` = `null`: الصورةُ بنسبتها)، تُرسم بعرض مكانها.
     *
     * @param  array{w: int, h: int}|null  $size
     */
    public static function factor(?array $size, string $fit, ?float $box): float
    {
        if ($size === null || $box === null || $size['h'] < 1) {
            return 1.0;
        }

        $ratio = $size['w'] / $size['h'] / $box;

        return $fit === self::CONTAIN ? min(1.0, $ratio) : max(1.0, $ratio);
    }

    /**
     * ` srcset="…" sizes="…"` لصورةٍ رفعها هذا المتجر في مكانها — أو لا شيء حين لا
     * يكون على القرص إلّا حجمٌ واحد (`ImageVariants::srcset`). والقيمتان مهرَّبتان هنا،
     * فيُكتب في الوسم كما هو.
     *
     * @param  list<array{0: int, 1: float|int, 2: float|int}>  $rules
     */
    public static function sized(int $businessId, ?string $url, array $rules, string $fit = self::COVER, ?float $box = null): HtmlString
    {
        $set = ImageVariants::srcset($url);

        if ($set === null) {
            return new HtmlString('');
        }

        $sizes = self::sizes($rules, self::factor(self::size($businessId, (string) $url), $fit, $box));

        return new HtmlString(' srcset="'.e($set).'" sizes="'.e($sizes).'"');
    }

    /**
     * `sizes` لصورة بطاقة القسم في رئيسيّة RIBBON — عرضُها المرسومُ في شبكتها.
     *
     * الشبكةُ في `sections/cats`: أعمدةٌ لا تقلّ عن ١٦٠ بفاصل ١٦، و`auto-fit`
     * يطوي ما زاد من الأعمدة عن البطاقات — فالعرضُ بعدد البطاقات لا بعرض
     * الشاشة وحده. وحولها `.rb-section` بعرض ١٢٨٠ وحاشيتين ٢٤، وحدُّ البطاقة
     * بكسلٌ من كلّ جانب، والإطارُ ٤:٣.
     *
     * وما يُرسم من الصورة ليس عرضَ الإطار دائمًا (`factor`) — والطوليّةُ في «ملء
     * الإطار» بعرضه نفسه، وعرضُها الحقيقيُّ في `srcset` (`ImageVariants::srcset`).
     *
     * @param  array{w: int, h: int}|null  $size
     */
    public static function cardSizes(int $cards, ?array $size, string $fit): string
    {
        [$min, $gap, $pad, $wide, $border] = [160, 16, 24, 1280, 2];
        $most = min(max(1, $cards), intdiv($wide - 2 * $pad + $gap, $min + $gap));

        // ‏عرضُ الصورة بـ k عمودًا: (الشاشة − الحاشيتين − الفواصل) ÷ k − الحدّ
        $rules = [[$wide, 0, ($wide - 2 * $pad - ($most - 1) * $gap) / $most - $border]];

        for ($k = $most; $k >= 1; $k--) {
            $rules[] = [$k === 1 ? 0 : ($min + $gap) * $k - $gap + 2 * $pad, 100 / $k, -((2 * $pad + ($k - 1) * $gap) / $k + $border)];
        }

        return self::sizes($rules, self::factor($size, $fit, 4 / 3));
    }

    /** أهذا الرابطُ صورةٌ رفعها هذا المتجر؟ — والفراغُ «لا صورة» فيُقبل */
    public static function owned(int $businessId, string $url): bool
    {
        if ($url === '') {
            return true;
        }

        foreach (self::prefixes($businessId) as $prefix) {
            if (str_starts_with($url, $prefix)) {
                $file = substr($url, strlen($prefix));

                return preg_match(self::FILE, $file) === 1 && ! str_contains($file, '..');
            }
        }

        return false;
    }

    /**
     * أوائلُ روابط مجلّده — المطلقُ كما يردّه الرفع، والنسبيُّ من الجذر.
     *
     * @return list<string>
     */
    private static function prefixes(int $businessId): array
    {
        $absolute = Storage::disk('public')->url('website/'.$businessId.'/');
        $path = (string) parse_url($absolute, PHP_URL_PATH);

        return array_values(array_unique(array_filter([$absolute, $path])));
    }
}
