<?php

namespace App\Http\Controllers\Admin\Marketing;

use App\Http\Controllers\Admin\WhatsAppController;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Customer;
use App\Models\PointTransaction;
use App\Models\Product;
use App\Models\Review;
use App\Support\Activity;
use App\Support\Demo;
use App\Support\GoogleReviews;
use App\Support\Loyalty;
use App\Support\MarketingSettings;
use App\Support\Seo;
use App\Support\Storefront;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * أدوات التسويق — أربع شاشات إعداد، والتقييمات والكوبونات لهما متحكّماهما.
 *
 * كلّها تقرأ وتكتب من `MarketingSettings` وحدها: مفتاحٌ يُقرأ بحرفٍ ويُكتب
 * بآخر لا يُخطئ أحدًا — تُقرأ القيمة الافتراضية بهدوء وتبدو الشاشة سليمة،
 * والإعداد الذي حفظه التاجر لا أثر له.
 */
class MarketingController extends Controller
{
    private function bid(): int
    {
        return auth()->user()->business_id ?? Demo::bid();
    }

    /* --------------------------- الموقع الإلكتروني --------------------------- */

    /**
     * إعدادات المتجر تُحفظ — وقد صار لها قارئ.
     *
     * كانت هذه المفاتيح تُملأ وتُحفظ ولا يقرؤها شيء، فرُفعت: التاجر يرفع
     * «نشر الموقع» ويظنّ أنّه نشر متجرًا وينتظر طلبًا لا يأتي. وقيل يومها
     * إنّ ما حُفظ باقٍ في القاعدة، وإن بُنيت الواجهة وجدَ ما كُتب مكانَه.
     *
     * وقد بُنيت — انظر `App\Support\Storefront` و`resources/views/store`.
     * فكلُّ مفتاحٍ هنا له اليوم موضعٌ يُقرأ فيه على صفحةٍ يفتحها زبون.
     */
    public function saveWebsite(Request $request)
    {
        $data = $request->validate([
            /*
             * النطاق اسمٌ لا رابط.
             *
             * لصقُ «https://» أو مسارٍ بعده يبني روابط معطوبة في كل صفحة
             * (https://https://…)، ولا يظهر العطب إلا حين يفتحها زبون.
             */
            'site_domain' => ['nullable', 'string', 'max:255', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9\-\.]*\.[a-zA-Z]{2,}$/'],

            'site_published' => ['nullable', 'boolean'],
            'site_tagline' => ['nullable', 'string', 'max:255'],
            'site_about' => ['nullable', 'string', 'max:2000'],

            /*
             * الرقم أرقامٌ ومسافات — لا اسمُ حساب.
             *
             * `wa.me` لا يقبل إلا الأرقام، والتنظيف عند العرض. لكنّ نصًّا
             * لا رقم فيه أصلًا يُنظَّف إلى فراغ فيختفي زرّ الطلب بلا سبب
             * يراه التاجر — فيُردّ هنا حيث يقرأ الردّ.
             */
            'site_whatsapp' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\s\-()]{7,}$/'],
            'site_instagram' => ['nullable', 'string', 'max:100', 'regex:/^@?[A-Za-z0-9._]+$/'],

            'site_show_prices' => ['nullable', 'boolean'],
            'site_allow_orders' => ['nullable', 'boolean'],

            /*
             * اللون بستّ خاناتٍ لا غير.
             *
             * القيمة تُكتب في `<style>` على الصفحة، فنصٌّ فيه `}` يُنهي
             * القاعدة ويفتح ما بعدها. و`Storefront::theme` تردّ الفاسد إلى
             * الافتراضيّ عند العرض — والقيدان معًا: هذا يمنع الحفظ، وتلك
             * تحمي ممّا حُفظ قبله.
             */
            'site_theme' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'site_hero_title' => ['nullable', 'string', 'max:120'],
            'site_hero_note' => ['nullable', 'string', 'max:255'],
            'site_layout' => ['nullable', 'string', Rule::in(Storefront::LAYOUTS)],
            'site_show_about' => ['nullable', 'boolean'],
            'site_show_categories' => ['nullable', 'boolean'],
        ], [
            'site_domain.regex' => __('اكتب النطاق وحده بلا https:// ولا مسار — مثل: mystore.om'),
            'site_whatsapp.regex' => __('اكتب رقم واتساب بصيغة دولية — مثل: 96890000000'),
            'site_instagram.regex' => __('اكتب اسم حساب إنستغرام وحده بلا رابط'),
            'site_theme.regex' => __('اختر لونًا من اللوحة'),
        ]);

        MarketingSettings::save($this->bid(), 'website', $data);
        Activity::log('updated', 'حدّث إعدادات الموقع');

        return back()->with('toast', ['msg' => __('حُفظت إعدادات الموقع'), 'type' => 'success']);
    }

    /**
     * غلاف الصفحة الأولى — يُرفع وحده كما يُرفع الشعار.
     *
     * وملفٌّ لا يُرسل مع بقيّة الحقول عمدًا: النموذج يُحفظ عشر مرّاتٍ في
     * الجلسة الواحدة، ورفعُ صورةٍ بميغابايتين في كلّ حفظةٍ رحلةٌ لا داعي لها.
     */
    public function saveCover(Request $request)
    {
        $request->validate([
            'cover' => ['nullable', 'image', 'max:4096'],
        ], [
            'cover.image' => __('الغلاف صورة — PNG أو JPG أو WEBP'),
            'cover.max' => __('أقصى حجمٍ للغلاف ٤ ميغابايت'),
        ]);

        if ($request->hasFile('cover')) {
            $path = $request->file('cover')->store('covers', 'public');
        } elseif ($request->boolean('remove')) {
            $path = '';
        } else {
            return back();
        }

        MarketingSettings::save($this->bid(), 'website', ['site_cover' => $path]);
        Activity::log('updated', $path !== '' ? 'حدّث غلاف الموقع' : 'حذف غلاف الموقع');

        return back()->with('toast', [
            'msg' => $path !== '' ? __('حُفظ الغلاف') : __('حُذف الغلاف'),
            'type' => 'success',
        ]);
    }

    /**
     * ما يُعرض في المتجر — يُختار صنفًا صنفًا أو دفعةً واحدة.
     *
     * والدفعة ضرورةٌ لا رفاهية: تاجرٌ بخمسمئة صنف يفتح متجره أوّل مرّة فيجده
     * فارغًا، وأمامه خمسمئة ضغطة قبل أن يرى شيئًا — فيتركه فارغًا.
     */
    public function publishProducts(Request $request)
    {
        $data = $request->validate([
            'ids' => ['nullable', 'array'],
            'ids.*' => ['integer'],
            'published' => ['required', 'boolean'],
            'all' => ['nullable', 'boolean'],
        ]);

        $query = Product::where('business_id', $this->bid());

        if ($request->boolean('all')) {
            // «كلّ الأصناف» تعني النشِطة منها: ما أُطفئ في نقطة البيع لا يُعرض
            $query->where('active', true);
        } else {
            $query->whereIn('id', $data['ids'] ?? []);
        }

        /*
         * والعدد عدد ما تغيّر لا عدد ما شمله الاستعلام.
         *
         * «٥٠٠ صنفًا صار يظهر» لتاجرٍ ٤٩٠ منها ظاهرةٌ أصلًا رقمٌ لا يصف شيئًا،
         * ويجعل الرسالة نفسها تظهر عند كلّ ضغطةٍ بعدها بلا أن يتغيّر شيء.
         */
        $published = $request->boolean('published');
        $count = $query->where('published', ! $published)->update(['published' => $published]);

        Activity::log('updated', $published
            ? 'عرض '.$count.' صنفًا في المتجر'
            : 'أخفى '.$count.' صنفًا من المتجر');

        return back()->with('toast', [
            'msg' => $published
                ? __(':n صنفًا صار يظهر في متجرك', ['n' => $count])
                : __(':n صنفًا لم يعد يظهر في متجرك', ['n' => $count]),
            'type' => 'success',
        ]);
    }

    /* -------------------- الظهور في البحث وGoogle Analytics -------------------- */

    /**
     * شاشةٌ تُعطي ما يُلصق، ثمّ تفتح الموقع وتقول ما رأت.
     *
     * ولا حقلَ فيها لعنوان الصفحة ولا وصفها: الموقع خارج النظام، فما يُكتب
     * عندنا لا يصل صفحةً يقرؤها محرّك بحث.
     */
    public function seo(): Response
    {
        $bid = $this->bid();

        return Inertia::render('Admin/Marketing/Seo', [
            'link' => Seo::forBusiness($bid),
            'audit' => Seo::check($bid),
        ]);
    }

    public function saveSeo(Request $request)
    {
        $request->validate(['ga_measurement_id' => ['nullable', 'string', 'max:60']]);

        $input = trim((string) $request->input('ga_measurement_id'));

        /*
         * ما لا يُقرأ يُردّ قبل أن يُحفظ.
         *
         * ومعرّفٌ خاطئ لا يُخطئ أحدًا في الشاشة: يُحفظ، ويُبنى منه وسمٌ
         * يلصقه التاجر في موقعه، ثمّ ينتظر أرقامًا لا تأتي أبدًا. و`UA-`
         * توقّفت عن الجمع، و`GTM-` معرّفُ مدير الوسوم لا القياس.
         */
        if ($input !== '' && Seo::measurementId($input) === null) {
            return back()->withInput()->withErrors([
                'ga_measurement_id' => __('معرّف القياس يبدأ بـG- — انسخه من «المشرف ← تدفّقات البيانات» في Google Analytics.'),
            ]);
        }

        $bid = $this->bid();

        MarketingSettings::save($bid, 'seo', [
            'ga_measurement_id' => Seo::measurementId($input) ?? '',
        ]);

        // والفحصُ يسقط مع المعرّف: حالةُ ربطٍ محفوظةٌ لمعرّفٍ بُدّل خبرٌ عن غيره
        Seo::forget($bid);

        Activity::log('updated', $input === '' ? 'فكّ ربط Google Analytics' : 'ربط Google Analytics');

        return back()->with('toast', [
            'msg' => $input === '' ? __('أُلغي الربط') : __('حُفظ معرّف القياس'),
            'type' => 'success',
        ]);
    }

    /**
     * فحصٌ جديدٌ الآن — يتخطّى الذاكرة.
     *
     * ولولاه لَبقي التاجر نصفَ ساعةٍ يرى «لم أجد الوسم» بعد أن لصقه، فيظنّ
     * أنّ اللصق لم ينفع ويعيده.
     */
    public function refreshSeo()
    {
        $result = Seo::check($this->bid(), refresh: true);

        return back()->with('toast', $result['state'] === 'ok'
            ? ['msg' => __('اكتمل الفحص'), 'type' => 'success']
            : ['msg' => $result['error'] ?? __('تعذّر فحص الموقع'), 'type' => 'error']);
    }

    /* ----------------------------- برنامج الولاء ----------------------------- */

    public function loyalty(): Response
    {
        $bid = $this->bid();

        return Inertia::render('Admin/Marketing/Loyalty', [
            'settings' => MarketingSettings::group($bid, 'loyalty'),
            'summary' => [
                'members' => Customer::where('business_id', $bid)->where('points', '>', 0)->count(),
                'points' => (int) Customer::where('business_id', $bid)->sum('points'),
                'earned' => (int) PointTransaction::where('business_id', $bid)->where('type', 'earn')->sum('points'),
                // المستبدَل مخزَّنٌ سالبًا — والقيمة المطلقة أوضح في بطاقة
                'redeemed' => (int) abs(PointTransaction::where('business_id', $bid)->where('type', 'redeem')->sum('points')),
            ],
            'top' => Customer::where('business_id', $bid)->where('points', '>', 0)
                ->orderByDesc('points')->limit(10)
                ->get(['id', 'name', 'phone', 'points'])
                ->map(fn ($c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'phone' => $c->phone,
                    'points' => (int) $c->points,
                ])->all(),
            'recent' => PointTransaction::where('business_id', $bid)->with('customer')
                ->orderByDesc('id')->limit(20)->get()
                ->map(fn ($t) => [
                    'id' => $t->id,
                    'customer' => $t->customer?->name ?? '—',
                    'type' => $t->type,
                    'points' => (int) $t->points,
                    'balance_after' => (int) $t->balance_after,
                    'note' => $t->note,
                    'at' => optional($t->created_at)->format('Y-m-d'),
                ])->all(),
        ]);
    }

    public function saveLoyalty(Request $request)
    {
        $data = $request->validate([
            'loyalty_enabled' => ['nullable', 'boolean'],
            /*
             * نسبةُ اكتسابٍ لا تطبع مالًا.
             *
             * كان السقف ألفًا، والاستبدال مئةُ نقطةٍ للريال: فمنحُ ألف نقطةٍ
             * لكلّ ريالٍ يعيد إلى الزبون عشرة ريالاتٍ عن كلّ ريالٍ يدفعه.
             * وهذا ليس سخاءً يُترك لصاحبه — هو حلقةٌ لا تُغلق: يشتري بنقاطٍ
             * يكسب منها نقاطًا أكثر.
             */
            'loyalty_earn_rate' => ['required', 'numeric', 'min:0', 'max:'.Loyalty::maxEarnRate()],
            /*
             * سقف الاستبدال نسبةٌ من الفاتورة لا أكثر من مئة.
             *
             * تجاوزُها يجعل النقاط تُغطّي الفاتورة كلّها وزيادة، فيخرج البيع
             * بمبلغٍ سالب.
             */
            'loyalty_redeem_max_pct' => ['required', 'integer', 'min:0', 'max:100'],
            'loyalty_redeem_min' => ['required', 'integer', 'min:0'],
        ], [
            'loyalty_earn_rate.max' => __(
                'كلّ :unit نقطة تساوي وحدةَ عملةٍ عند الاستبدال، فمنحُ :rate نقطةً لكل وحدةٍ يعيد للزبون :pct٪ من فاتورته.',
                [
                    'unit' => Loyalty::POINTS_PER_UNIT,
                    'rate' => (string) $request->input('loyalty_earn_rate'),
                    'pct' => Loyalty::cashbackPercent((float) $request->input('loyalty_earn_rate')),
                ],
            ),
        ]);

        MarketingSettings::save($this->bid(), 'loyalty', $data);
        Activity::log('updated', 'حدّث برنامج الولاء');

        return back()->with('toast', ['msg' => __('حُفظ برنامج الولاء'), 'type' => 'success']);
    }

    /* ------------------------- الكوبونات والعروض ------------------------- */

    /**
     * صفحة ربط خرائط Google.
     *
     * وصارت صفحةً في النظام بعد أن كانت زرًّا يفتح `business.google.com` في
     * تبويبٍ خارجيّ: زرٌّ اسمُه «ربط» ولا يربط شيئًا — يُخرج التاجر من
     * لوحته ويتركه هناك، ولا يعود بمعرّفٍ ولا يُحفظ شيء.
     */
    public function google(): Response
    {
        $bid = $this->bid();

        $settings = MarketingSettings::group($bid, 'google');

        // المفتاح لا يُرسل إلى الشاشة — آخرُ أربعةِ أحرفٍ تكفي ليعرف أيَّه حفظ
        unset($settings['google_api_key']);

        return Inertia::render('Admin/Marketing/Google', [
            'settings' => $settings,
            'link' => GoogleReviews::forBusiness($bid),
            'keyHint' => GoogleReviews::keyHint($bid),
            'google' => GoogleReviews::pull($bid),
            /* عددُ ما في النظام من تقييمات — ليُقرأ الفرق بين الاثنين */
            'internal' => Review::where('business_id', $bid)->count(),
        ]);
    }

    /**
     * حفظُ مفتاح Places — أو محوُه.
     *
     * وحقلٌ فارغٌ لا يمحو: الشاشة لا تعرض المفتاح المحفوظ (لا يُرسل أصلًا)،
     * فحفظُ الصفحة لتبديل شيءٍ آخر يصل بحقلٍ فارغ — ولو عُدّ محوًا لَفقد
     * التاجر مفتاحه كلّما حفظ. فالمحو يُطلب بزرّه.
     */
    public function saveGoogleKey(Request $request)
    {
        $request->validate([
            'google_api_key' => ['nullable', 'string', 'max:255'],
        ]);

        $key = trim((string) $request->input('google_api_key'));

        if ($key === '') {
            return back()->withErrors(['google_api_key' => __('الصق المفتاح، أو اضغط «حذف المفتاح» لإزالته.')]);
        }

        GoogleReviews::storeKey($this->bid(), $key);
        // ولا يُكتب المفتاح في السجلّ — السجلّ يُقرأ في شاشة النشاط
        Activity::log('updated', 'حدّث مفتاح Google Places');

        return back()->with('toast', ['msg' => __('حُفظ المفتاح'), 'type' => 'success']);
    }

    public function forgetGoogleKey()
    {
        GoogleReviews::storeKey($this->bid(), null);
        Activity::log('updated', 'حذف مفتاح Google Places');

        return back()->with('toast', ['msg' => __('حُذف المفتاح'), 'type' => 'warning']);
    }

    /**
     * سحبٌ جديدٌ الآن — يتخطّى الذاكرة.
     *
     * ولولاه لَبقي التاجر ستَّ ساعاتٍ يرى ردًّا قديمًا بعد أن صحّح مفتاحه أو
     * ردّ على تقييم، فيظنّ أنّ إصلاحه لم ينفع ويعيده.
     */
    public function refreshGoogle()
    {
        $result = GoogleReviews::pull($this->bid(), refresh: true);

        return back()->with('toast', $result['state'] === 'ok'
            ? ['msg' => __('حُدِّثت التقييمات'), 'type' => 'success']
            : ['msg' => $result['error'] ?? __('لم تُسحب التقييمات'), 'type' => 'error']);
    }

    public function saveGoogle(Request $request)
    {
        $data = $request->validate([
            'google_maps_url' => ['nullable', 'string', 'max:500'],
            'google_review_on_receipt' => ['nullable', 'boolean'],
        ]);

        $input = trim((string) ($data['google_maps_url'] ?? ''));

        /*
         * الرابط يُقرأ قبل أن يُحفظ، ولا يُقبل ما لا يُقرأ.
         *
         * ومعرّفٌ خاطئ لا يُخطئ أحدًا في الشاشة: الحفظ ينجح، والرمز يُطبع،
         * ويمسحه الزبون فيفتح ملفَّ محلٍّ آخر — أو لا يفتح شيئًا. عطبٌ لا
         * يراه صاحبه أبدًا لأنّه لا يمسح إيصاله بنفسه.
         */
        if ($input !== '' && ! GoogleReviews::readable($input)) {
            return back()->withInput()->withErrors([
                'google_maps_url' => __('لم أستطع قراءة معرّف المكان من هذا الرابط. الصق «Place ID» نفسه، أو رابطًا يحمل place_id.'),
            ]);
        }

        $bid = $this->bid();

        MarketingSettings::save($bid, 'google', [
            'google_maps_url' => $input,
            'google_place_id' => GoogleReviews::placeId($input) ?? '',
            'google_review_on_receipt' => $request->boolean('google_review_on_receipt'),
        ]);

        /*
         * والمسحوبُ يسقط بعد الكتابة — بالمعرّف الجديد.
         *
         * ولا يخلط معرّفٌ بآخر: موضعُ الذاكرة يحمل المعرّف في اسمه، فلا يرث
         * محلٌّ تقييماتِ محلٍّ آخر أبدًا. وإنّما هو التقادم: من ربط الآن يقصد
         * أن يرى ما عند Google الآن، لا ما بقي في الذاكرة من قبل.
         */
        GoogleReviews::forget($bid);

        Activity::log('updated', $input === '' ? 'فكّ ربط خرائط Google' : 'ربط خرائط Google');

        return back()->with('toast', [
            'msg' => $input === '' ? __('أُلغي الربط') : __('حُفظ الربط'),
            'type' => 'success',
        ]);
    }

    public function coupons(): Response
    {
        return Inertia::render('Admin/Marketing/Coupons', [
            'stats' => Demo::couponStats(),
            'coupons' => Demo::coupons(),
            'segments' => Demo::marketingSegment(),
        ]);
    }

    /* -------------------------- إشعارات واتساب -------------------------- */

    public function whatsapp(): Response
    {
        $bid = $this->bid();

        $business = Business::findOrFail($bid);

        return Inertia::render('Admin/Marketing/Whatsapp', [
            'settings' => MarketingSettings::group($bid, 'whatsapp'),
            /*
             * حال الأتمتة كما تراها المنصّة — لا كما يظنّها التاجر.
             *
             * ولا يخرج منها رمزٌ ولا معرّف وصلة أبعاد: الوضع المشترك يقول
             * «يُرسل عبر أبعاد» ولا يقول بأيّ حسابٍ ولا بأيّ مفتاح.
             */
            'automation' => WhatsAppController::view($business),
        ]);
    }

    public function saveWhatsapp(Request $request)
    {
        /*
         * أربعةُ مقابضَ وحدها — انظر MarketingSettings::GROUPS.
         *
         * ولا رقمَ ولا نصَّ رسالةٍ ولا مفتاحَ تفعيلٍ ثانٍ: كانت تُقبل وتُحفظ
         * ولا يقرؤها مُرسِل الرسائل. والتفعيل من بطاقة الوصلة، والرقم رقمُها،
         * والنصّ قالبٌ معتمَدٌ عند ميتا.
         */
        $data = $request->validate([
            'wa_on_order' => ['nullable', 'boolean'],
            'wa_on_ready' => ['nullable', 'boolean'],
            'wa_on_out_for_delivery' => ['nullable', 'boolean'],
            'wa_on_delivered' => ['nullable', 'boolean'],
        ]);

        MarketingSettings::save($this->bid(), 'whatsapp', $data);
        Activity::log('updated', 'حدّث إشعارات واتساب');

        return back()->with('toast', ['msg' => __('حُفظت إعدادات واتساب'), 'type' => 'success']);
    }
}
