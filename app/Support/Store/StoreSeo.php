<?php

namespace App\Support\Store;

use App\Models\Business;
use App\Models\StoreSite;
use App\Support\MarketingSettings;
use App\Support\Storefront;
use Illuminate\Support\Facades\DB;
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
     * ما تكتبه شاشةُ «الظهور في البحث» — نصوصُها بلغتيها وإذنُ الفهرسة.
     *
     * @var list<string>
     */
    public const KEYS = ['store_seo_title', 'store_seo_desc', 'store_seo_title_en', 'store_seo_desc_en', 'store_seo_index'];

    /** متى حفظ صاحبُه من الشاشة آخرَ مرّة — انظر `save` */
    public const SAVED_AT = 'store_seo_saved_at';

    /**
     * يحفظ «الظهور في البحث» — ويسري في اللحظة نفسِها، بلا نشر.
     *
     * ═══ ولمَ لا يمرّ بالمسوّدة ═══
     *
     * كان النصُّ يُكتب في المسوّدة وينتظر «نشر»، وإذنُ الفهرسة يسري فورًا.
     * فشاشةٌ بزرّ حفظٍ واحد يسري نصفُها ويتأجّل نصفُها، وصاحبُها يقرأ «حُفظ»
     * ثمّ يبحث عن متجره فيجد العنوانَ القديم. وأسوأُ منه أنّ نشرَ مسوّدةٍ
     * قديمة — أو استرجاعَ نشرةٍ سابقة — كان يُعيد عنوانًا كتب صاحبُه فوقه.
     *
     * فالخمسةُ تُكتب حيّةً معًا في معاملةٍ واحدة: لا يُحفظ نصفُها ويُترك
     * نصفُها إن تعثّر الحفظ. ولا تمسّ المسوّدةَ إلّا لتُسقط منها ما بقي من
     * قبلُ (`StoreContent::RETIRED`) — قرّر صاحبُه الآن، فلا يُعرض عليه ثانيةً.
     * ولا يُنشر بها تصميمٌ ولا صفحةٌ ولا منتج.
     *
     * @param  array<string, mixed>  $values  ما أُرسل من `KEYS` وحدها
     */
    public static function save(int $businessId, array $values): void
    {
        $clean = array_intersect_key($values, array_flip(self::KEYS));

        if ($clean === []) {
            return;
        }

        if (array_key_exists('store_seo_index', $clean)) {
            $clean['store_seo_index'] = filter_var($clean['store_seo_index'], FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
        }

        DB::transaction(function () use ($businessId, $clean) {
            MarketingSettings::save($businessId, 'website', $clean + [self::SAVED_AT => now()->toIso8601String()]);

            $site = StoreSite::where('business_id', $businessId)->lockForUpdate()->first();

            if ($site !== null && StoreContent::retired((array) $site->draft) !== []) {
                // ولا تتقدّم المراجعة: ما يُنشر لم يتبدّل، ومن فتح المحرّرَ لا يُقال له إنّ زميلًا بدّله
                $site->update(['draft' => array_diff_key((array) $site->draft, array_flip(StoreContent::RETIRED))]);
            }
        });

        MarketingSettings::forget($businessId);
    }

    /**
     * ما حُفظ في المسوّدة قبل أن يصير البحثُ يسري بالحفظ — ولم يُنشر.
     *
     * وهو ما يُعرض على صاحبه في الشاشة ليستعمله أو يتركه: لم يصل زبونًا
     * قطّ، فلا يُطبَّق عنه بترقية، ولا يُرمى وقد كتبه بيده. وما يساوي
     * المنشورَ ليس معلّقًا — لا يُعرض.
     *
     * @return array<string, string>
     */
    public static function pending(int $businessId): array
    {
        $draft = StoreSite::where('business_id', $businessId)->value('draft');
        $retired = StoreContent::retired(is_array($draft) ? $draft : (array) json_decode((string) $draft, true));
        $live = MarketingSettings::group($businessId, 'website');

        return array_filter(
            $retired,
            fn (string $value, string $key) => $value !== (string) ($live[$key] ?? ''),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * صفحاتٌ لا تُفهرَس — مهما أذِن صاحبُ المتجر بالفهرسة.
     *
     * ═══ والعطبُ الذي وُضع لأجله ═══
     *
     * صفحةُ التأكيد `‎/done/{id}?t=…‎` تعرض رقمَ طلبِ زبونٍ وأصنافَه
     * ومبلغَه وحسابَ التحويل. ورابطُها لا يُخمَّن — لكنّ محرّك بحثٍ يزحف
     * إليه من مشاركةٍ عابرة في منتدًى أو رسالةٍ عامّة يجعله مفهرسًا للجميع،
     * فيصير طلبُ زبونٍ واحدٍ نتيجةَ بحثٍ عن اسم المحلّ.
     *
     * وهي حجّةُ `public/paper.blade.php` و`verify` و`review` أنفسُها،
     * مكتوبةٌ هناك منذ كُتبت: «رابطُها لا يُخمَّن، لكنّ محرّك بحثٍ يزحف
     * إليه من مشاركةٍ عابرة يجعله مفهرسًا للجميع».
     *
     * والسلّةُ والإتمامُ والعودةُ من البوّابة معها: ثلاثُ صفحاتٍ لا نصَّ
     * فيها يُبحَث، تُفهرَس بعنوانٍ واحدٍ فتقسم ثقلَ المتجر على أربع.
     *
     * وإذنُ الفهرسة في الشاشة يبقى سؤالَ الصفحات العامّة وحدَها — وهذان
     * سؤالان لا سؤال.
     */
    public const PRIVATE_PAGES = ['cart', 'checkout', 'done', 'paying'];

    /**
     * رابطٌ مطلقٌ لا نسبيّ.
     *
     * `og:image` يقرؤه واتساب من خادمٍ آخر لا من متصفّح الزائر، فلا مضيفَ
     * يُكمل به `‎/storage/…‎`. ورابطٌ نسبيٌّ هناك لا صورةَ له — ولا خطأ:
     * تُعرض البطاقةُ بلا صورةٍ ولا يُعرف لماذا.
     */
    private static function absolute(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        return str_starts_with($url, 'http://') || str_starts_with($url, 'https://')
            ? $url
            : url($url);
    }

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
        $root = Storefront::canonical($business->site_slug, (int) $business->id);

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
     * ═══ ولكلّ لغةٍ ما كُتب لها ═══
     *
     * الصفحةُ الإنجليزيّة تقرأ `store_seo_title_en` و`store_seo_desc_en`،
     * والعربيّةُ مفتاحَيها. وفارغُ اللغة يُحسب كما كان (اسمُ النشاط، ثمّ
     * النبذة) — لا يُكتب في وسمٍ إنجليزيّ ما كتبه صاحبُه للعربيّة.
     *
     * ═══ وللصفحة الإنجليزيّة عنوانُها الأصليّ ═══
     *
     * كانت الإنجليزيّةُ (`‎?lang=en‎`) تُعلن أصلَها الصفحةَ العربيّة، وتُعلن
     * في `hreflang` أنّها ترجمتُها. وغوغل يقرأ الأولى فيطوي الإنجليزيّة في
     * العربيّة نسخةً مكرّرة — فلا يظهر ما كتبه صاحبُ المتجر لها في نتيجة
     * بحثٍ قطّ، ولا يجدها من يبحث بالإنجليزيّة. فلكلّ لغةٍ أصلُها نفسُها،
     * والترجمتان تُذكران معًا في `alternates`.
     *
     * @param  array<string, string>  $t  نصوصُ الواجهة بلغة الزائر
     * @param  array<string, string>  $identity
     * @return array{brand: string, title: string, description: string, index: bool, canonical: ?string, alternates: ?array{ar: string, en: string}, image: ?string}
     */
    public static function head(Business $business, string $page, array $t, array $identity, string $base = '', string $path = '/', string $lang = 'ar'): array
    {
        $bid = (int) $business->id;
        $site = MarketingSettings::group($bid, 'website');
        $suffix = $lang === 'en' ? '_en' : '';

        $name = (string) $business->name;
        $written = trim((string) ($site['store_seo_title'.$suffix] ?? ''));
        $label = $page === StoreNav::HOME ? '' : (string) ($t[StoreNav::LABELS[$page] ?? ''] ?? '');

        /*
         * و`$brand` لا `$base`: ذاك اسمُ قاعدة العنوان في هذه الدالّة،
         * وسمّيتُه مرّةً باسمين فطمس أحدُهما الآخر — فمضى إلى `canonical`
         * اسمُ المتجر في موضع قاعدة الرابط، وخرج العنوانُ الأصليُّ مضاعفًا.
         */
        $brand = $written !== '' ? $written : $name;

        $desc = trim((string) ($site['store_seo_desc'.$suffix] ?? ''));

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
            'index' => ($site['store_seo_index'] ?? '1') === '1'
                && ! in_array($page, self::PRIVATE_PAGES, true),
            // وكلُّ ما يقرؤه غوغل في موضعٍ واحد — العنوانُ الأصليّ منه، ولكلّ لغةٍ أصلُها
            'canonical' => ($canonical = self::canonical($business, $base, $path)) === null
                ? null
                : ($lang === 'en' ? $canonical.'?lang=en' : $canonical),
            'alternates' => $canonical === null ? null : ['ar' => $canonical, 'en' => $canonical.'?lang=en'],
            /*
             * وصورةُ المشاركة: ما يظهر حين يُلصَق الرابطُ في واتساب.
             *
             * وصورةُ الواجهة قبل الشعار: الشعارُ مربّعٌ صغيرٌ على أبيض يظهر
             * قصاصةً في بطاقة المحادثة، وصورةُ الواجهة بضاعةٌ تُشتهى. ومن
             * لا واجهةَ له يبقى شعارُه خيرًا من فراغ.
             */
            'image' => self::absolute(StorePage::heroImage($bid) ?: ((string) $business->logo ?: null)),
        ];
    }
}
