<?php

namespace App\Support\Store;

use App\Models\Business;
use App\Support\MarketingSettings;
use Illuminate\Support\Str;

/**
 * ما يقرؤه غوغل من متجر الواجهة الخاصّة — عنوانٌ ووصفٌ وإذنُ فهرسة.
 *
 * ═══ ولمَ صار لصاحبه بابٌ إليه ═══
 *
 * كان عنوانُ الصفحة اسمَ النشاط ووصفُها أوّلَ مئةٍ وخمسين حرفًا من نبذته،
 * يُكتبان في `<head>` ولا يملك صاحبُ المتجر إليهما بابًا. وسائرُ متاجر
 * أبعاد لها شاشةُ «الظهور في البحث» تكتبهما بيدها منذ البداية.
 *
 * والعنوانُ في نتيجة البحث هو ما يُضغط أو لا يُضغط — وسطرٌ يقول «ريبون»
 * وحدَه لا يقول لمن يبحث عن «ورد توصيل مسقط» شيئًا.
 *
 * ═══ والفراغُ يعني «ما كان» ═══
 *
 * قاعدةُ `StorePage` نفسُها. فترقيةٌ تُنزل المفتاحين فارغين على متجرٍ
 * مفهرَسٍ منذ شهور لا تُبدّل عنوانَه في نتائج غوغل.
 */
final class StoreSeo
{
    /**
     * ولا يُقصّ ما كتبه: الحدُّ إرشادٌ في الشاشة، والقصُّ هنا يُخفي عنه
     * ما حفظه. وغوغل يقصّ في نتيجته ولا يُعاقب على الطول.
     */
    public const TITLE_MAX = 70;

    public const DESC_MAX = 170;

    /**
     * عنوانُ هذه الصفحة الأصليُّ — جذرُ المتجر وعليه مسارُها.
     *
     * ═══ والعطبُ الذي وُضع لأجله ═══
     *
     * كان يُكتب في القالب هكذا:
     *
     *     {{ $canonical }}{{ $base === '' ? request()->getPathInfo() : '' }}
     *
     * فالمسارُ يُضمّ حين يُخدَم المتجر على عنوانه وحده، ويُسقَط حين يُخدَم
     * على الطريق البديل (`‎/s/متجري‎`) — لأنّ الجذرَ هناك يحمل الطريقَ
     * أصلًا، فضمُّ المسار كاملًا يُخرج `‎…/s/متجري/s/متجري/shop‎`.
     *
     * والأثرُ أنّ **كلَّ صفحةٍ على الطريق البديل تقول لغوغل إنّها نسخةٌ من
     * الرئيسية**: `‎/shop‎` و`‎/about‎` و`‎/contact‎` وصفحاتُ الأصناف كلُّها
     * تُشير إلى الجذر. فلا تُفهرس واحدةٌ منها مهما كُتب لها في «الظهور في
     * البحث» — يكتب صاحبُ المتجر عنوانًا ووصفًا لصفحاتٍ أعلنّا نحن أنّها
     * لا تستحقّ الفهرسة.
     *
     * والصوابُ أن يُضمّ المسارُ **منسوبًا إلى جذر المتجر** لا إلى جذر
     * المضيف. وهي قاعدةُ `Website\Published::canonicalFor` نفسُها — يفعلها
     * البانِي صحيحةً منذ كُتب، وانفردت هذه الواجهةُ بصيغةٍ ثانية فأخطأت.
     *
     * ═══ وما لا يُضمّ ═══
     *
     * سلسلةُ الاستعلام: `‎?cat=3‎` رفٌّ مُرشَّحٌ من `‎/shop‎` لا صفحةٌ أخرى،
     * و`‎?q=ورد‎` بحثٌ لا يُفهرَس. وأصلُهما `‎/shop‎` — وهذا معنى `canonical`.
     * و`‎?lang=en‎` كذلك، ولها `hreflang` تقول إنّها ترجمةٌ لا نسخة.
     */
    public static function canonical(Business $business, string $base, string $path): ?string
    {
        $root = \App\Support\Storefront::canonical($business->site_slug, (int) $business->id);

        if ($root === null) {
            return null;
        }

        // المسارُ منسوبًا إلى جذر المتجر — والجذرُ يحمل القاعدةَ إن كانت
        $relative = $base !== '' && str_starts_with($path, $base)
            ? substr($path, strlen($base))
            : $path;

        // والرئيسيةُ تبقى على الجذر عاريًا: «…/متجري/» و«…/متجري» عنوانان لصفحةٍ واحدة
        return rtrim($root, '/').rtrim($relative, '/');
    }

    /**
     * ترويسةُ الصفحة — عنوانٌ ووصفٌ وإذنُ فهرسة.
     *
     * والعنوانُ يُبنى على ثلاث مراتب: ما كتبه، ثمّ اسمُ النشاط مع اسم
     * الصفحة، ثمّ اسمُ النشاط وحدَه للرئيسية. واسمُ الصفحة يُضمّ إليه لا
     * يُبدّله: نتيجتان في غوغل بعنوانٍ واحد لا تُميَّزان.
     *
     * @param  array<string, string>  $t  نصوصُ الواجهة بلغة الزائر
     * @param  array<string, string>  $identity
     * @return array{brand: string, title: string, description: string, index: bool, canonical: ?string}
     */
    public static function head(Business $business, string $page, array $t, array $identity, string $base = '', string $path = '/'): array
    {
        $bid = (int) $business->id;
        $site = MarketingSettings::group($bid, 'website');

        $name = (string) $business->name;
        $written = trim((string) ($site['store_seo_title'] ?? ''));
        $label = $page === StoreNav::HOME ? '' : (string) ($t[StoreNav::LABELS[$page] ?? ''] ?? '');

        /*
         * و`$brand` لا `$base`: ذاك اسمُ قاعدة العنوان في هذه الدالّة،
         * وسمّيتُه مرّةً باسمين فطمس أحدُهما الآخر — فمضى إلى `canonical`
         * اسمُ المتجر في موضع قاعدة الرابط، وخرج العنوانُ الأصليُّ مضاعفًا.
         */
        $brand = $written !== '' ? $written : $name;

        $desc = trim((string) ($site['store_seo_desc'] ?? ''));

        if ($desc === '') {
            $desc = $identity['about'] !== '' ? $identity['about'] : $name;
        }

        return [
            /*
             * و`brand` ما يُذيَّل به عنوانُ كلّ صفحةٍ تكتب عنوانَها بنفسها
             * — صفحةُ الصنف والسلّة والإتمام. ولولاه لَبقي اسمُ النشاط
             * مكتوبًا هناك وما كتبه صاحبُه لا يبلغ إلّا صفحتين.
             */
            'brand' => $brand,
            'title' => $label === '' ? $brand : $label.' — '.$brand,
            'description' => Str::limit($desc, 155),
            /*
             * و«لا تُفهرسني» يُكتب `noindex` ولا يُحذف شيء.
             *
             * من بنى متجره على عنوانٍ حقيقيّ وهو يجرّب لا يريده في نتائج
             * البحث بعد. وإخفاؤه بإطفاء النشر يُغلقه على زبائنه أيضًا —
             * وهذان سؤالان لا سؤال.
             */
            'index' => ($site['store_seo_index'] ?? '1') === '1',
            // وكلُّ ما يقرؤه غوغل في موضعٍ واحد — العنوانُ الأصليّ منه
            'canonical' => self::canonical($business, $base, $path),
        ];
    }
}
