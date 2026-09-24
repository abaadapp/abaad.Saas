<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Support\MarketingSettings;
use App\Support\Seo;
use App\Support\Store\StoreContent;
use App\Support\Storefront;
use App\Support\Website\Domains;
use App\Support\Website\Published;
use Illuminate\Http\Request;
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
    public function show(string $slug, ?string $path = null): Response
    {
        $clean = Storefront::slug($slug);
        $business = $clean ? Storefront::open($clean) : null;

        abort_if($business === null, 404);

        // والواجهةُ الخاصّة — RIBBON — تتقدّم على البانِي والبسيطة معًا
        if (Storefront::serves($business) === Storefront::SERVES_THEME) {
            return app(RibbonController::class)->page($business, $path, $this->base($slug));
        }

        /*
         * والأسبقيّةُ تُقرأ من مصدرها لا تُكتب هنا — انظر `Storefront::serves`.
         *
         * شاشةُ الإعدادات تسأل السؤالَ نفسه («أيّ صفحةٍ تُخدم؟») وكانت تجيبه
         * بمفتاح الصفحة البسيطة وحده، فتقول «غير منشور» عن متجرٍ مفتوح.
         * وفحصان لسؤالٍ واحد يفترقان يوم يُبدَّل أحدهما.
         */
        $site = Storefront::serves($business) === Storefront::SERVES_BUILT
            ? Published::forBusiness((int) $business->id)
            : ['state' => Published::NOT_PUBLISHED];

        if ($site['state'] !== Published::NOT_PUBLISHED) {
            /*
             * وقاعدةُ الروابط تُقال للرسم.
             *
             * روابطُ القائمة في المستند مكتوبةٌ من الجذر (`/shop`) — وهي
             * كذلك على النطاق الفرعيّ وعلى نطاق التاجر. أمّا على المسار
             * البديل (`/s/{slug}`) فجذرُ المضيف ليس جذرَ المتجر: `/shop`
             * يخرج إلى `app.abaadapp.om/shop`، و«الرئيسية» تُخرج الزبون
             * إلى صفحة دخول أبعاد. فتُمرَّر القاعدةُ ويُبنى عليها كلُّ
             * رابطٍ داخليّ — انظر `Published::rebase`.
             */
            return $this->built($site, $business, $path, $this->base($slug));
        }

        // وصفحةُ المتجر البسيطة صفحةٌ واحدة — ولا مسارَ داخليًّا لها
        abort_if($path !== null, 404);

        // ولمن لم يبنِ موقعًا: صفحةُ المتجر البسيطة إن نشرها
        abort_if(! Storefront::published($business), 404);

        return response()
            ->view('store.show', Storefront::page($business) + [
                // والوسمُ نفسُه في صفحة المتجر البسيطة — الطريقان عنوانٌ واحد
                'analytics' => Seo::tagFor((int) $business->id),
            ])
            /*
             * ═══ ولا تُخزَّن أصلًا — لا هنا ولا في وسيط ═══
             *
             * كانت تأذن بخزنها دقيقتين. ومعناها أنّ التاجر يُصلح سعرًا
             * ويفتح موقعه ليرى، فيرى القديمَ **دقيقتين** — فيظنّ أنّ حفظَه
             * لم يقع فيحفظ ثانيةً، أو يبلّغ عن عطبٍ لا وجود له. وأوّلُ ما
             * يفعله من غيّر شيئًا أن يفتح ويتأكّد.
             *
             * وزبونُه مثلُه: يقرأ ثمنًا رُفع أو صنفًا نفد.
             *
             * ولا يُخسر بها شيء: الصفحةُ تُبنى من استعلاماتٍ قليلة، والمتجرُ
             * ذو الواجهة الخاصّة يُخدَم `no-store` منذ كُتب — فقاعدةٌ واحدةٌ
             * للبابين خيرٌ من اثنتين يُنسى فرقُهما.
             *
             * و`private` تبقى صريحة: محتواها يخصّ متجرًا بعينه، ووسيطٌ
             * يخزّنها بمفتاح المسار وحده قد يردّها لمتجرٍ آخر.
             */
            ->header('Cache-Control', 'no-store, private');
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
    public function byHost(string $host, ?string $path = null): Response
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

        if (Storefront::serves($business) === Storefront::SERVES_THEME) {
            return app(RibbonController::class)->page($business, $path, '');
        }

        $site = Published::forBusiness($businessId);

        abort_if($site['state'] === Published::NOT_PUBLISHED, 404);

        // ونطاقُ التاجر جذرُه جذرُ متجره — فلا قاعدةَ تُضاف
        return $this->built($site, $business, $path, '');
    }

    /* ═══════════ السلّةُ وإتمامُ الطلب — للواجهة الخاصّة وحدها ═══════════ */

    /** تسعيرُ السلّة من الخادم — بابٌ للقراءة لا يكتب شيئًا */
    public function quote(Request $request, string $slug)
    {
        return app(RibbonController::class)->quote($this->themed($slug), $request);
    }

    /** إتمامُ الطلب — طلبٌ حقيقيٌّ في أبعاد (انظر `Store\WebCheckout`) */
    public function place(Request $request, string $slug)
    {
        return app(RibbonController::class)->place($this->themed($slug), $request, $this->base($slug));
    }

    /** رفعُ ملفّ كرت الهدية — قبل الطلب لا معه (انظر `RibbonController::giftCard`) */
    public function giftCard(Request $request, string $slug)
    {
        return app(RibbonController::class)->giftCard($this->themed($slug), $request);
    }

    public function giftCardByHost(Request $request, string $host)
    {
        return app(RibbonController::class)->giftCard($this->themedHost($host), $request);
    }

    public function quoteByHost(Request $request, string $host)
    {
        return app(RibbonController::class)->quote($this->themedHost($host), $request);
    }

    public function placeByHost(Request $request, string $host)
    {
        return app(RibbonController::class)->place($this->themedHost($host), $request, '');
    }

    /** المتجرُ من عنوانه — ولا يُخدم بابُ السلّة إلّا لمن واجهتُه خاصّة */
    private function themed(string $slug): Business
    {
        $clean = Storefront::slug($slug);
        $business = $clean ? Storefront::open($clean) : null;
        abort_if($business === null || Storefront::serves($business) !== Storefront::SERVES_THEME, 404);

        return $business;
    }

    private function themedHost(string $host): Business
    {
        abort_if(! config('storefront.custom_domains'), 404);
        $businessId = Domains::resolve($host);
        $business = $businessId ? Business::find($businessId) : null;
        abort_if($business === null || Storefront::serves($business) !== Storefront::SERVES_THEME, 404);

        return $business;
    }

    /**
     * قاعدةُ روابط المتجر على هذا الطلب — و'' حين يكون الجذرُ جذرَه.
     *
     * والقياسُ على المضيف لا على العَلَم: من فتح `/s/متجري` يبقى فيه ولو
     * كانت النطاقاتُ الفرعية مُشغَّلة — والعكس. وما يُبنى يتبع البابَ الذي
     * دخل منه الزائر، لا البابَ الذي نتمنّاه له.
     */
    private function base(string $slug): string
    {
        return str_starts_with((string) request()->route()?->getName(), 'store.show')
            ? '/s/'.$slug
            : '';
    }

    /**
     * موقعٌ بُني في بانِي المواقع — يُرسم بطبقة الرسم نفسها التي في المعاينة.
     *
     * ولا يُنسخ الرسمُ إلى Blade: طبقة الرسم سبعةَ عشرَ نوعَ قسمٍ في نحو
     * ألفَي سطر، ونسخةٌ ثانية منها بلغةٍ أخرى تفترق عند أوّل إصلاح — فيرى
     * التاجر في معاينته غير ما يرى زبونُه. وهو العطبُ الذي وُضع له
     * `RendererParityTest` أصلًا.
     */
    private function built(array $site, Business $business, ?string $path = null, string $base = ''): Response
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

        $doc = $site['site'];
        $page = Published::pageAt($doc, $path);

        /*
         * ومسارٌ لا صفحةَ له ٤٠٤ — لا الرئيسيةُ مكانَه.
         *
         * صفحةٌ تُردّ بـ٢٠٠ عن عنوانٍ لا وجود له تُفهرَس مرّتين تحت عنوانين،
         * ويبقى الزائرُ الذي تبع رابطًا قديمًا يظنّ أنّه وصل.
         */
        abort_if($page === null, 404);

        // وروابطُه الداخليّة تُبنى على قاعدة هذا الطلب — انظر `Published::rebase`
        $doc = Published::rebase($doc, $base);

        return response()
            ->view('site.show', [
                'doc' => $doc,
                'page' => $page['slug'] ?? '/',
                'head' => Published::head($doc, $page),
                // ونصُّ هذه الصفحة وحدها: لكلّ عنوانٍ محتواه لا محتوى الموقع كلِّه
                'outline' => Published::outline($doc, $page),
                'canonical' => Published::canonicalFor(
                    Storefront::canonical($business->site_slug, (int) $business->id),
                    $page,
                ),
                /*
                 * ووسمُ القياس يخرج من هنا — لا يُطلب من التاجر لصقُه.
                 *
                 * صفحتُه صفحتُنا: `<head>` نكتبه، فلا بابَ له إليه. وكانت
                 * شاشةُ «الظهور في البحث» تحفظ معرّفه وتعطيه وسمًا يلصقه في
                 * موقعٍ لا يملكه — فلا يخرج الوسمُ في صفحةٍ واحدة قطّ،
                 * وينتظر أرقامًا لا تأتي. انظر `Seo::hostedUrl`.
                 */
                'analytics' => Seo::tagFor((int) $business->id),
            ])
            // ولا تُخزَّن — كما في الطريق الآخر أعلاه، وللسبب نفسِه
            ->header('Cache-Control', 'no-store, private');
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
    public function preview(Request $request): Response
    {
        $business = Business::findOrFail(
            auth()->user()->business_id ?? \App\Support\Demo::bid()
        );

        /*
         * والمعاينةُ تُصيَّر على **المسوّدة** لا على المنشور.
         *
         * طبقتان: ما حُفظ في المسوّدة ولم يُنشر بعد، وفوقه ما لم يُحفظ بعدُ
         * في الشاشة. فيرى صاحبُ المتجر ما سيصير إليه موقعُه لو نشر الآن.
         *
         * ومتجرٌ لم يُفتح له النشرُ تردّ مسوّدتُه المنشورَ نفسَه — فلا يتبدّل
         * شيءٌ لمن لم يُرحَّل.
         */
        return MarketingSettings::withOverlay(
            (int) $business->id, 'website',
            array_merge(StoreContent::draft((int) $business->id), $this->draft($request)),
            fn () => $this->render($business),
        );
    }

    /** الصفحةُ كما يراها الزبون — وهي نفسُها في المعاينة وفي الموقع */
    private function render(Business $business): Response
    {
        /*
         * ومن لبس واجهةً خاصّة يُعايِنها هي — لا الصفحةَ البسيطة التي لن
         * يراها زبونُه. وروابطُها الداخليّة على عنوانه العامّ: المعاينةُ
         * صفحةٌ واحدة، وما بعدها الموقعُ نفسُه.
         */
        if ($business->storefrontTheme() !== null) {
            return app(RibbonController::class)
                ->page($business, null, $business->site_slug ? '/s/'.$business->site_slug : '')
                ->header('Cache-Control', 'no-store')
                ->header('X-Robots-Tag', 'noindex, nofollow');
        }

        return response()
            ->view('store.show', Storefront::page($business) + ['preview' => true])
            // ومعاينةٌ لا تُخزَّن ولا تُفهرَس: هي حالُ لحظتها، ولصاحبها وحده
            ->header('Cache-Control', 'no-store')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    /**
     * ما لم يُحفظ بعد يُعرض كما سيُعرض — والمنشورُ لا يمسّه شيء.
     *
     * ═══ العطبُ الذي وُضع لأجله ═══
     *
     * المعاينةُ كانت تُصيّر المحفوظ: يكتب صاحبُ المحلّ عنوانًا في المحرّر،
     * فلا يراه حتّى يضغط «حفظ» — والحفظُ يبلغ زبونَه في اللحظة نفسِها. فلا
     * معاينةَ قبل التطبيق أصلًا: التطبيقُ كان شرطَ المعاينة.
     *
     * وثلاثةُ قيودٍ تحرس البابَ بعد أن فُتح:
     *
     * ١) المفاتيحُ مصفّاةٌ بقائمة `MarketingSettings::GROUPS['website']` —
     *    وهي القائمةُ نفسُها التي يُصفّي بها بابُ الحفظ. فما لا يُحفظ لا
     *    يُعايَن.
     * ٢) والنشاطُ من الجلسة لا من الطلب — كما يقول تعليقُ `preview` فوق:
     *    لا معرّفَ في الرابط، فلا يُعاين تاجرٌ متجرَ جاره.
     * ٣) والتراكبُ يُنزَع بانتهاء التصيير لا بانتهاء الطلب — انظر
     *    `MarketingSettings::withOverlay`: ثابتٌ يعيش أطولَ من طلبه يُخرج
     *    مسوّدةَ التاجر لزبونه.
     *
     * ولا يُقرأ إلّا على `POST`: الرابطُ المحفوظ في متصفّحٍ أو المُشارَك في
     * رسالةٍ يجب أن يفتح المتجرَ كما هو محفوظ — لا كما كان أحدُهم يجرّب.
     *
     * @return array<string, mixed>
     */
    private function draft(Request $request): array
    {
        if (! $request->isMethod('post')) {
            return [];
        }

        $draft = $request->input('draft');

        if (is_string($draft)) {
            $draft = json_decode($draft, true);
        }

        return is_array($draft) ? $draft : [];
    }
}
