<?php

namespace App\Support\Store;

use Illuminate\Support\Facades\Storage;

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
        static $seen = [];

        if ($url === '' || ! self::owned($businessId, $url)) {
            return null;
        }

        $key = $businessId.'|'.$url;

        if (array_key_exists($key, $seen)) {
            return $seen[$key];
        }

        $file = self::fileName($businessId, $url);
        $path = $file === null ? null : Storage::disk('public')->path('website/'.$businessId.'/'.$file);
        $info = $path !== null && is_file($path) ? @getimagesize($path) : false;

        return $seen[$key] = is_array($info) && $info[0] > 0 && $info[1] > 0
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
