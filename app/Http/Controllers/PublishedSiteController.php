<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Models\Setting;
use App\Models\Website;
use App\Support\DomainOptions;
use App\Support\Website\Preview;
use Illuminate\Http\JsonResponse;

/**
 * ما يقرؤه العارض الخارجيّ — النسخة المنشورة وحدها.
 *
 * أبعاد تبني الموقع ولا تعرضه: العرض في مستودعٍ مستقلّ (`abaadapp/Website`)
 * كي لا يصير في النظام كتالوجان ولا خادما ويب. والوصل بينهما مستندٌ واحد:
 * هذا المسار يردّ اللقطة المنشورة ومعها ما تقرؤه أقسامُها من المنتجات
 * والتصنيفات والتقييمات.
 *
 * وثلاثةٌ تُحرَس هنا، وكلٌّ منها بابٌ لو تُرك:
 *
 * ١) **المنشور وحده يخرج.** المسوّدة عملُ التاجر الذي لم يرضَ عنه بعد؛
 *    خروجُها يُبطل معنى «انشر» كلَّه.
 * ٢) **النطاق يُطابَق بحرفه.** لا يُقرأ موقعٌ بمعرّفه: معرّفٌ متسلسل يجعل
 *    عدّادًا بسيطًا يمرّ على مواقع المتاجر كلّها.
 * ٣) **الصيانة تُقال ولا يُكشف ما وراءها.** الموقع في الصيانة يردّ رسالته
 *    ولا يردّ صفحاته.
 *
 * وما يخرج علنيٌّ بطبعه: هو ما سيُعرض على موقع التاجر لكلّ زائر. فلا سرَّ
 * فيه — ولذلك لا مفتاح عليه، والحدُّ على الطلبات يكفي لمنع الاستنزاف.
 */
class PublishedSiteController extends Controller
{
    public function __invoke(string $host): JsonResponse
    {
        $host = mb_strtolower(trim($host));

        /*
         * النطاق → المتجر، من إعداداته لا من جدول المواقع.
         *
         * `site_domain` هو مصدر النطاق الوحيد في النظام منذ توحيده — يقرؤه
         * زرّ «الموقع» وشاشة الدومين والفاتورة. وعمودٌ ثانٍ في `websites`
         * كان سيفترق عنه عند أوّل تعديل.
         */
        $businessId = Setting::whereNotNull('business_id')
            ->where('key', 'site_domain')
            ->whereRaw('LOWER(value) = ?', [$host])
            ->value('business_id') ?: self::bySubdomain($host);

        if (! $businessId) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $website = Website::where('business_id', $businessId)->with('publishedVersion')->first();

        if (! $website || ! $website->publishedVersion) {
            return response()->json(['error' => 'not_published'], 404);
        }

        if ($website->maintenance) {
            /*
             * وصفحةُ الصيانة تُرسم بهويّة المتجر لا بهويّة العارض.
             *
             * زائرٌ يرى صفحةً بيضاء عليها «الموقع تحت الصيانة» لا يعرف أنّه
             * وصل إلى المكان الصحيح. فالشعارُ واللونُ ووسيلةُ التواصل تُرسل
             * معها — يعرف بها أنّه في متجر من قصده، ويصل إليه إن استعجل.
             */
            return response()->json([
                'maintenance' => true,
                'name' => $website->name,
                'message' => $website->maintenance_message ?: 'نعود قريبًا',
                'tokens' => $website->tokens(),
                'brand' => Preview::brand($businessId, ['name' => $website->name]),
                'locale' => 'ar',
                'dir' => 'rtl',
            ], 503);
        }

        $version = $website->publishedVersion;

        return response()->json([
            'published_at' => optional($version->published_at)->toIso8601String(),
            'version' => $version->number,
            // اللقطة المجمّدة، ومعها الكتالوج كما هو اليوم — انظر Preview
            'site' => Preview::resolve($version->payload, $businessId),
        ])->header('Cache-Control', 'public, max-age=60');
    }

    /**
     * ومن لا نطاق له: الاسمُ الذي حجزه.
     *
     * ═══ وهو `site_slug` لا مفتاحٌ آخر ═══
     *
     * كان يُبحث عنه في إعدادٍ اسمه `site_subdomain` — **مفتاحٌ لا يكتبه شيءٌ
     * في النظام كلِّه**: لا شاشةَ تحفظه ولا متحكّمَ يمرّره، ولا صفَّ له في
     * قاعدة الإنتاج. فالفرعُ كلُّه لم يكن يقع مرّةً واحدة.
     *
     * والاسمُ المحجوز فعلًا في `businesses.site_slug`: هو الذي يُفحص تفرّدُه
     * عند الحفظ، وهو الذي يُعرض للتاجر عنوانًا في لوحته، وهو الذي يُبنى عليه
     * مسارُ متجر أبعاد. فمن حجز اسمه وبنى موقعه ونشره ثمّ فتح عنوانه وجد
     * «غير موجود» — ولوحتُه تقول إنّ العنوان له.
     *
     * فصار الاسمُ واحدًا: ما يحجزه التاجر هو ما يفتح موقعه.
     *
     * ولا يُقرأ موقعٌ بمعرّفه: عدّادٌ بسيط يمرّ على مواقع المتاجر كلّها. والاسم
     * المحجوز نصٌّ اختاره صاحبُه — لا يُخمَّن بالعدّ.
     *
     * ويبقى ما ليس من شأن هذا الملفّ: **أيُّ خادمٍ يخدم `*.abaadapp.om`**.
     * هذا المسار يقول من صاحبُ العنوان ويردّ لقطته؛ وتوجيهُ DNS والشهادةُ
     * قرارُ استضافة. انظر `Storefront/docs/DEPLOY.md`.
     */
    private static function bySubdomain(string $host): ?int
    {
        $suffix = '.'.mb_strtolower(DomainOptions::suffix());

        if (! str_ends_with($host, $suffix)) {
            return null;
        }

        $label = mb_substr($host, 0, -mb_strlen($suffix));

        // اسمٌ من جزءٍ واحد لا غير: `a.b.abaadapp.om` ليس نطاقًا فرعيًّا محجوزًا
        if ($label === '' || str_contains($label, '.')) {
            return null;
        }

        return Business::whereRaw('LOWER(site_slug) = ?', [$label])->value('id');
    }
}
