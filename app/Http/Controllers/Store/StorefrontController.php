<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Support\Storefront;
use App\Support\Website\Domains;
use App\Support\Website\Published;
use Illuminate\Http\Response;

/**
 * الصفحة التي يفتحها زبونُ التاجر.
 *
 * وهي **خارج كلّ حرّاس النظام**: لا جلسة، ولا تسجيل دخول، ولا حارس مستأجر —
 * لأنّ من يفتحها زبونٌ لا حساب له. فالمتجر يُعرف من عنوانه، والحارس الوحيد
 * هنا شرطان: أن يكون المتجر **نشطًا** وأن يكون صاحبُه قد **نشره**. وما لم
 * يُنشر فهو ٤٠٤ لا صفحةٌ فارغة: صفحةٌ فارغة تقول للزائر إنّ المحلّ مغلق،
 * و٤٠٤ تقول إنّه لا عنوان هنا — وهي الحقيقة.
 *
 * ═══ وبابٌ واحد لموقعين ═══
 *
 * في النظام طريقان إلى «موقع التاجر»، بُنيا في وقتين:
 *
 *  1. **بانِي المواقع** — أقسامٌ وصفحاتٌ يركّبها التاجر ويُجمّدها نسخةً
 *     تُنشر (`websites` و`website_versions`).
 *  2. **صفحةُ المتجر البسيطة** — شبكةُ منتجاتٍ بمفتاح `store_on`، والطلبُ
 *     فيها يقع في واتساب.
 *
 * والبانِي يتقدّم: هو ما بناه التاجر بيده وضغط «انشر» عليه. ولو تقدّمت
 * البسيطةُ لَبنى موقعَه ونشره ثمّ فتح عنوانه فوجد شبكةَ صورٍ لم يصنعها.
 *
 * ولا عنوانَ ثانٍ للجديد: عنوانٌ لكلّ طريق يعني أنّ التاجر يوزّع رابطًا ثمّ
 * يبدّل طريقَه فيموت ما وزّعه. فالعنوان واحد، والذي يُعرض عليه هو الأحدث
 * ممّا نشره صاحبُه.
 */
class StorefrontController extends Controller
{
    public function show(string $slug): Response
    {
        $clean = Storefront::slug($slug);
        $business = $clean ? Storefront::open($clean) : null;

        abort_if($business === null, 404);

        $site = Published::forBusiness((int) $business->id);

        if ($site['state'] !== Published::NOT_PUBLISHED) {
            return $this->built($site, $business);
        }

        // ولمن لم يبنِ موقعًا: صفحةُ المتجر البسيطة إن نشرها
        abort_if(! Storefront::published($business), 404);

        return response()
            ->view('store.show', Storefront::page($business))
            /*
             * ولا تُخزَّن في وسيطٍ مشترك.
             *
             * الصفحة عامّة، لكنّ محتواها يخصّ متجرًا بعينه — وخادمٌ وسيطٌ
             * يخزّنها بمفتاح المسار وحده قد يردّها لمتجرٍ آخر. والخصوصية
             * تُقال صراحةً لا تُترك للافتراض.
             */
            ->header('Cache-Control', 'public, max-age=120');
    }

    /**
     * ونطاقُ التاجر نفسه — `myshop.om` يفتح موقعه.
     *
     * ولا يُقرأ منه شيءٌ إلّا ما يقوله جدولُ العناوين: مضيفٌ لا صفَّ **نشطًا**
     * له لا يُخدَم. ولو خُدم لَأمكن أن يوجّه أحدٌ نطاقًا إلينا فيُعرض عليه
     * موقعُ متجرٍ لا يملكه — أو أن يُخدَم على نطاقٍ رُبط ولم يُتحقَّق منه بعد،
     * فيبطل معنى التحقّق كلُّه.
     *
     * والبانِي وحده هنا: صفحةُ المتجر البسيطة عنوانُها `‎/s/{slug}‎` وما
     * زالت تعمل عليه. ونطاقٌ خاصٌّ يُربط اليوم يُربط بموقعٍ بُني.
     */
    public function byHost(string $host): Response
    {
        /*
         * والعلَمُ يُقرأ هنا لا عند تسجيل المسار.
         *
         * خدمةُ الصفحة على نطاق التاجر تلزمها كتلةُ nginx تلتقط المضيف
         * المجهول وشهادةٌ تُصدَر له. وقبلهما يصل الزائرُ إلى تحذير أمانٍ
         * يحمل اسم متجر التاجر — وهو أسوأ من عنوانٍ لا يفتح.
         */
        abort_if(! config('storefront.custom_domains'), 404);

        $businessId = Domains::resolve($host);

        abort_if($businessId === null, 404);

        $business = Business::find($businessId);

        abort_if($business === null || ! Storefront::serving($business), 404);

        $site = Published::forBusiness($businessId);

        abort_if($site['state'] === Published::NOT_PUBLISHED, 404);

        return $this->built($site, $business);
    }

    /**
     * موقعٌ بُني في بانِي المواقع — يُرسم بطبقة الرسم نفسها التي في المعاينة.
     *
     * ولا يُنسخ الرسمُ إلى Blade: طبقة الرسم سبعةَ عشرَ نوعَ قسمٍ في نحو
     * ألفَي سطر، ونسخةٌ ثانية منها بلغةٍ أخرى تفترق عند أوّل إصلاح — فيرى
     * التاجر في معاينته غير ما يرى زبونُه. وهو العطبُ الذي وُضع له
     * `RendererParityTest` أصلًا.
     */
    private function built(array $site, Business $business): Response
    {
        if ($site['state'] === Published::MAINTENANCE) {
            /*
             * والصيانةُ تردّ ٥٠٣ لا ٢٠٠.
             *
             * محرّكُ البحث يقرأ ٢٠٠ على أنّها الصفحة، فيحفظ «نعود قريبًا»
             * مكانَ المتجر ويعرضها للناس بعد أن يعود. و٥٠٣ تقول «تعذّر
             * الآن» فيعود ويسأل.
             */
            return response()
                ->view('site.maintenance', ['doc' => $site])
                ->setStatusCode(503)
                ->header('Cache-Control', 'no-store')
                ->header('Retry-After', '3600');
        }

        return response()
            ->view('site.show', [
                'doc' => $site['site'],
                'head' => Published::head($site['site']),
                'outline' => Published::outline($site['site']),
                'canonical' => Storefront::canonical($business->site_slug, (int) $business->id),
            ])
            ->header('Cache-Control', 'public, max-age=120');
    }

    /**
     * المتجر كما يراه صاحبُه قبل أن يراه أحد.
     *
     * وكان يضبطه أعمى: يكتب عنوانه ويختار لونه ويُخفي أصنافًا، ولا يرى شيئًا
     * حتى ينشره — فيُنشر ليرى، ثمّ يُطفئ ليُصلح، وبين الاثنين رابطٌ حيٌّ فُتح
     * لمن وصله. أو لا يُنشر أبدًا لأنّه لا يعرف ما سيخرج.
     *
     * وهو القالبُ نفسه بالحمولة نفسها — لا رسمًا يشبهه: رسمٌ يشبهه يفترق عنه
     * عند أوّل حقلٍ يُضاف في أحدهما، فيرى التاجر غير ما يرى زبونُه.
     *
     * والمسار خلف الحارس ولا يقبل معرّفًا: المتجر يُقرأ من جلسة صاحبه وحدها،
     * فلا يُعايَن متجرُ غيره بتبديل رقمٍ في الرابط.
     */
    public function preview(): Response
    {
        $business = Business::findOrFail(
            auth()->user()->business_id ?? \App\Support\Demo::bid()
        );

        return response()
            ->view('store.show', Storefront::page($business) + ['preview' => true])
            // ومعاينةٌ لا تُخزَّن ولا تُفهرَس: هي حالُ لحظتها، ولصاحبها وحده
            ->header('Cache-Control', 'no-store')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
