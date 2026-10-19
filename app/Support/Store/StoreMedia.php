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
