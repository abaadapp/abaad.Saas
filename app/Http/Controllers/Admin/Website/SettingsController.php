<?php

namespace App\Http\Controllers\Admin\Website;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\PaymentGateway;
use App\Models\Product;
use App\Models\WebsiteSection;
use App\Support\MarketingSettings;
use App\Support\Seo;
use App\Support\Store\CheckoutFields;
use App\Support\Store\RibbonTexts;
use App\Support\Store\StoreNav;
use App\Support\Store\StoreSeo;
use App\Support\Store\StorePage;
use App\Support\Store\StoreReadiness;
use App\Support\Store\WebCheckout;
use App\Support\Website\Blueprints;
use App\Support\Website\Commerce;
use App\Support\Website\MerchantData;
use App\Support\Website\Readiness;
use App\Support\Website\Templates;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * المتجر والسيو والصيانة — الإعدادات التي بقيت بعد أن صار الباقي بنيةً.
 *
 * وأكثرها ليس جديدًا: `store_show_prices` و`store_allow_orders` يُحفظان
 * منذ نسخٍ ولا يقرؤهما شيء. وقد صار لهما الآن قارئ — العارض يقرأ اللقطة،
 * واللقطة تحملهما. فما نُقل إلى هنا لم يُخترع، وإنّما وُصل بما يعنيه.
 *
 * ولا يُعرض إعدادٌ لا يعني شيئًا لهذا الموقع: من اختار «تعريفيّ» لا يرى
 * إعداداتِ السلّة ولا عرضَ الأسعار — ليست مطفأةً عنده، هي غيرُ موجودة.
 *
 * والدفع ليس هنا: طرقُه في «الضرائب والعملة والدفع» ومصدرُه واحد. وما يملكه
 * الموقع أن يعرض ما فُعّل هناك، لا أن يفتح مصدرًا ثانيًا يعارضه.
 */
class SettingsController extends Controller
{
    use Concerns;

    /**
     * «الإعدادات ‹ الموقع الإلكتروني ‹ عام» — حالُ الموقع وما يُفعل به.
     *
     * ═══ ولماذا انتقلت من الشريط الجانبيّ ═══
     *
     * كانت لوحةَ القسم: أوّلُ ما يفتحه من يضغط «الموقع الإلكتروني». وفيها
     * النشرُ والصيانةُ والنسخُ السابقة وأبوابُ التصميم والصفحات — وكلُّها
     * يُفعل مرّةً ثمّ يُترك. والموظّفُ الذي يفتح القسم كلَّ صباح لا يريد شيئًا
     * منها؛ يريد أن يعرف أنّ الموقع يعمل وأنّ منتجاته ظاهرة.
     *
     * فصار الشريطُ يفتح لوحةَ التشغيل (`HubController`)، وصارت هذه أوّلَ
     * أقسام إعدادات الموقع — تليها التصميمُ والصفحاتُ والمتجرُ والنطاقُ
     * والظهورُ في البحث، كلُّها بمساراتها التي لم تتبدّل.
     *
     * ═══ واسمُها `general` لا `site` ═══
     *
     * `Concerns::site()` موجودةٌ في هذا الصنف بالوراثة وتردّ **موقعَ النشاط**.
     * وفعلٌ عامٌّ باسمها يحجبها، فتصير `siteOrFail()` تنادي الشاشةَ التي
     * تناديها — ذهابًا وإيابًا حتّى تنفد الذاكرة. ولا يقوله المترجم: التوقيعان
     * مختلفان والوراثةُ من سمة، فالحجبُ مسموح.
     */
    public function general(): Response
    {
        /*
         * ومن لبس واجهةً خاصّة يرى الشاشةَ نفسَها بشكلها نفسِه.
         *
         * وكانت `siteOrFail` تردّه إلى لوحة التشغيل، فيبقى ضبطُ متجره كلُّه
         * في بطاقةٍ داخل «الإعدادات» بينما لجاره ستُّ شاشاتٍ بشريط تبويبات.
         * والشكلُ ليس زينة: من فتح لوحتين مختلفتين للشيء نفسه لا يعرف أين
         * يبحث عن مقبض.
         */
        if (($theme = $this->theme()) !== null) {
            return $this->themedGeneral($theme);
        }

        $site = $this->siteOrFail();
        $pages = $site->pages()->withCount('sections')->get();

        return Inertia::render('Admin/Website/Site', $this->shell($site) + [
            'pages' => $pages->map(fn ($p) => [
                'id' => $p->id,
                'title' => $p->title,
                'slug' => $p->slug,
                'status' => $p->status,
                'is_home' => $p->is_home,
                'sections' => $p->sections_count,
            ])->all(),
            'summary' => [
                'pages' => $pages->count(),
                'sections' => WebsiteSection::where('website_id', $site->id)->whereNotNull('page_id')->count(),
                'hidden' => WebsiteSection::where('website_id', $site->id)->where('visible', false)->count(),
                'versions' => $site->versions()->count(),
            ],
            'domain' => $this->domainState(),
            // وجاهزيةُ المتجر حقائقُ تُقاس — انظر `Readiness`
            'readiness' => Readiness::check($site),
            'template_label' => __(Templates::CATALOGUE[$site->template]['label'] ?? ''),
            'versions' => $site->versions()->with('creator:id,name')->limit(5)->get()
                ->map(fn ($v) => [
                    'id' => $v->id,
                    'number' => $v->number,
                    'at' => optional($v->published_at)->format('Y-m-d H:i'),
                    'by' => $v->creator?->name,
                    'note' => $v->note,
                    'current' => $v->id === $site->published_version_id,
                ])->all(),
        ]);
    }

    /**
     * «عام» لصاحب الواجهة الخاصّة — حالُ متجره وأبوابُه.
     *
     * ═══ وما يختلف عن أختِها يختلف عن حقّ ═══
     *
     * لا «نشر التغييرات» ولا «النسخ السابقة» ولا «وضع الصيانة»: الواجهةُ
     * تقرأ الإعدادات مباشرةً فما يُحفظ يصل زبونَه في اللحظة نفسِها، ولا
     * مسوّدةَ لها تُنشر ولا لقطةَ تُستعاد. وزرٌّ يَعِد بتأجيلٍ لا يقع كذبةٌ
     * تُقرأ مرّةً ثمّ يُترك الزرّ.
     *
     * والأبوابُ خمسةٌ كأبواب جاره — وكلُّها تقود إلى شاشةٍ قائمة.
     */
    private function themedGeneral(string $theme): Response
    {
        $bid = $this->bid();
        $business = Business::findOrFail($bid);
        $site = MarketingSettings::group($bid, 'website');

        $active = Product::where('business_id', $bid)->where('active', true);

        return Inertia::render('Admin/Website/ThemeSite', $this->themeShell($theme) + [
            /*
             * ودليلُ التجهيز أوّلُ ما يُقرأ — وهو موضعُه.
             *
             * كان في بطاقةٍ داخل «الإعدادات»، وهذه شاشةُ «حالِ موقعك»:
             * من يفتحها يفتحها ليعرف أين هو.
             */
            'readiness' => StoreReadiness::steps($business),
            'counts' => [
                'sections' => count(StorePage::order($bid)),
                'allSections' => count(StorePage::SECTIONS),
                // والمعروضُ لا الفعّال: تاجرٌ أخفى أصنافه كلَّها يقرأ رقمًا يطمئنه
                'shown' => (clone $active)->where('published', true)->count(),
                'active' => $active->count(),
                'payments' => count(WebCheckout::payments($bid)),
                /*
                 * وعددُ الصفحات ما يُفتح منها — لا ما أذِن به.
                 *
                 * «٤ من ٤» على متجرٍ لا نبذةَ فيه ولا هاتف رقمٌ يطمئنه على
                 * صفحتين يردّهما عنوانُهما «غير موجود». انظر `StoreNav::has`.
                 */
                'pages' => count(array_filter(
                    StoreNav::ALL,
                    fn ($p) => StoreNav::has($bid, $p),
                )),
                'allPages' => count(StoreNav::ALL),
                'seo' => trim((string) ($site['store_seo_title'] ?? '')) !== ''
                    || trim((string) ($site['store_seo_desc'] ?? '')) !== '',
            ],
        ]);
    }

    /**
     * «المتجر والطلبات» لصاحب الواجهة الخاصّة — قبضُه وتسليمُه وما يسأل عنه.
     *
     * وكان هذا كلُّه في بطاقة «الإعدادات ‹ الموقع»، وهي بطاقةٌ واحدةٌ فيها
     * ثلاثون مقبضًا: العنوانُ والنشرُ والدفعُ والتوصيلُ وحقولُ الطلب وكرتُ
     * الهدية والصفحةُ — بلا فاصلٍ بين ما يُضبط مرّةً وما يُراجَع كلَّ موسم.
     */
    private function themedStore(string $theme): Response
    {
        $bid = $this->bid();

        $gateway = PaymentGateway::where('business_id', $bid)
            ->where('provider', PaymentGateway::PAYMOB)->first();

        return Inertia::render('Admin/Website/ThemeShop', $this->themeShell($theme) + [
            'settings' => MarketingSettings::group($bid, 'website'),
            /*
             * وحقولُ الطلب تصل محسوبةً لا فارغة.
             *
             * `CheckoutFields` تقرأ الفراغَ «ما كان» — فشاشةٌ تعرض فراغًا
             * تقول لصاحبها إنّ حقلًا مطفأٌ وهو يُسأل عنه في متجره.
             */
            'fieldStates' => CheckoutFields::all($bid),
            'fulfilments' => CheckoutFields::fulfilments($bid),
            /*
             * وبوّابةُ الدفع — حالُها لا مفاتيحُها.
             *
             * السرّان لا يخرجان من الخادم أبدًا: خصائصُ Inertia تُقرأ في
             * مصدر الصفحة بضغطةٍ واحدة.
             */
            'gateway' => [
                'active' => (bool) ($gateway?->active ?? false),
                'public_key' => (string) ($gateway?->public_key ?? ''),
                'card_integration_id' => (string) ($gateway?->card_integration_id ?? ''),
                'has_secret' => filled($gateway?->secret_key),
                'has_hmac' => filled($gateway?->hmac_secret),
                'ready' => (bool) ($gateway?->ready() ?? false),
            ],
        ]);
    }

    /** المتجر: ما يراه الزائر وما يستطيع فعله */
    public function store(): Response
    {
        // والواجهةُ الخاصّة تبيع فعلًا — فتبويبُها يضبط قبضَها وتسليمَها
        if (($theme = $this->theme()) !== null) {
            return $this->themedStore($theme);
        }

        $site = $this->siteOrFail();
        $bid = $this->bid();
        $marketing = MarketingSettings::group($bid, 'website');

        return Inertia::render('Admin/Website/Store', $this->shell($site) + [
            'settings' => [
                'show_prices' => ($marketing['store_show_prices'] ?? '1') === '1',
                'allow_orders' => ($marketing['store_allow_orders'] ?? '0') === '1',
            ],
            'sells' => $site->sells(),
            'hasCatalogue' => Blueprints::hasCatalogue($site->goal()),
            /*
             * ونوعُ الموقع يُبدَّل من هنا — وكان يُوعَد به ولا يوجد.
             *
             * الشاشةُ تقول في موضعين «تبدّل ذلك من إعدادات الموقع»، وشاشةُ
             * إعدادات الموقع لم تكن موجودة: `saveSite` مسارٌ حيٌّ لا يناديه
             * شيء في الواجهة. فمن بنى متجرًا وأراده كتالوجًا يقرأ الوعد ولا
             * يجد البابَ الموعود.
             *
             * وموضعُه هنا لا في المحرّر: المحرّر يرسم الموقع، وهذا يقرّر ما
             * هو. ولأنّه يُبدّل ما يعرضه الموقع — تسقط أقسامُ المنتجات من
             * موقعٍ صار تعريفيًّا — فهو قرارٌ يُتّخذ في شاشة إعدادات لا في
             * أثناء تحريك لون.
             */
            'goal' => $site->goal(),
            'goals' => Blueprints::goalOptions(),
            'name' => $site->name,
            /*
             * ═══ طرقُ الدفع كما هي **على الموقع** — لا كما هي على المنضدة ═══
             *
             * كانت تُقرأ من `PaymentMethods::state` فتقول «البطاقة مفعّلة».
             * وذاك جوابٌ عن سؤالٍ آخر: تلك تصف ما يأخذه الكاشير من يد الزبون
             * في المحلّ. وبينه وبين «البطاقة تُقبض على الإنترنت» بوّابةُ دفعٍ
             * مربوطةٌ ومتحقَّق منها — ولا بوّابةَ في أبعاد بعد.
             *
             * فكانت الشاشة تَعِد صاحبَ المتجر بما لا يقع: ينشر موقعه ويوزّع
             * رابطه ثمّ يعلم من زبونه أنّ لا شيء يُدفع. انظر `Commerce`.
             */
            'payments' => Commerce::payments($bid),
            // وما يفعله زرُّ الطلب فعلًا: محادثةُ واتساب، أو لا شيء
            'channel' => Commerce::channel($bid),
            // وجاهزيةُ المتجر قبل النشر لا بعده — حقائقُ تُقاس لا أمنيات
            'readiness' => Readiness::check($site),
            'counts' => [
                'products' => \App\Models\Product::where('business_id', $bid)->where('active', true)->count(),
                'categories' => \App\Models\Category::where('business_id', $bid)->count(),
            ],
        ]);
    }

    public function saveStore(Request $request)
    {
        $site = $this->siteOrFail();

        $data = $request->validate([
            'show_prices' => ['required', 'boolean'],
            'allow_orders' => ['required', 'boolean'],
        ]);

        // ولا طلبَ في موقعٍ لا يبيع: الوجهة تحكم لا المفتاح
        if (! $site->sells()) {
            $data['allow_orders'] = false;
        }

        /*
         * وسعرٌ مخفيٌّ لا يُطلب معه.
         *
         * «اطلب» على منتجٍ بلا سعر يعني زبونًا يضع في سلّته ما لا يعرف ثمنه،
         * ثمّ يصل إلى الدفع فيفاجأ. وهذا كان تحذيرًا في الشاشة القديمة —
         * وصار قاعدةً في الخادم: الشاشة قد تُتخطّى.
         */
        if (! $data['show_prices']) {
            $data['allow_orders'] = false;
        }

        MarketingSettings::save($this->bid(), 'website', [
            'store_show_prices' => $data['show_prices'] ? '1' : '0',
            'store_allow_orders' => $data['allow_orders'] ? '1' : '0',
        ]);

        $site->touchDraft();

        return back()->with('toast', ['msg' => __('حُفظت إعدادات المتجر'), 'type' => 'success']);
    }

    /**
     * السيو — بلغةِ من يبيع لا بلغةِ من يبرمج.
     *
     * «عنوان موقعك في غوغل» لا «meta title»، و«صورة المشاركة» لا «og:image».
     * والكلمات المفتاحية ليست محور الشاشة: `seo_keywords` تبقى محفوظةً لمن
     * ضبطها ولا تُعرض أوّلًا — لا يقرؤها محرّك بحثٍ منذ سنين.
     */
    public function seo(): Response
    {
        if (($theme = $this->theme()) !== null) {
            return $this->themedSeo($theme);
        }

        $site = $this->siteOrFail();
        $seo = $site->seo ?? [];

        return Inertia::render('Admin/Website/Seo', $this->shell($site) + [
            'seo' => [
                'title' => (string) ($seo['title'] ?? ''),
                'description' => (string) ($seo['description'] ?? ''),
                'image' => (string) ($seo['image'] ?? ''),
                'index' => (bool) ($seo['index'] ?? true),
            ],
            'pages' => $site->pages()->get()->map(fn ($p) => [
                'id' => $p->id,
                'title' => $p->title,
                'slug' => $p->slug,
                'status' => $p->status,
                'seo' => $p->seo ?? ['title' => '', 'description' => '', 'image' => ''],
            ])->all(),
            'domain' => $this->domainState(),
        ]);
    }

    /**
     * «الظهور في البحث» لصاحب الواجهة الخاصّة — عنوانٌ ووصفٌ وإذنُ فهرسة.
     *
     * وكان عنوانُ متجره اسمَ نشاطه ووصفُه أوّلَ سطورِ نبذته، يُكتبان في
     * `<head>` ولا يملك إليهما بابًا — وهما ما يُضغط أو لا يُضغط في نتيجة
     * غوغل. ولجاره شاشةٌ يكتبهما فيها منذ أوّل يوم.
     *
     * ولا «صورةُ المشاركة» هنا: تلك تُقرأ من شعار نشاطه (`og:image` في
     * القالب)، وحقلٌ ثانٍ لها يعني صورتين تفترقان.
     */
    private function themedSeo(string $theme): Response
    {
        $bid = $this->bid();
        $site = MarketingSettings::group($bid, 'website');
        $business = Business::findOrFail($bid);

        return Inertia::render('Admin/Website/ThemeSeo', $this->themeShell($theme) + [
            'seo' => [
                'title' => (string) ($site['store_seo_title'] ?? ''),
                'desc' => (string) ($site['store_seo_desc'] ?? ''),
                'index' => ($site['store_seo_index'] ?? '1') === '1',
            ],
            /*
             * وما يُعرض في غوغل حين لا يكتب شيئًا — يُحسب هنا لا في الشاشة.
             *
             * فالشاشةُ تعرض المعاينةَ بما سيُكتب فعلًا، ولو حسبَته بنفسها
             * لَقالت غيرَ ما يقوله `StoreSeo` يومَ يتبدّل أحدُهما.
             */
            'fallback' => StoreSeo::head($business, StoreNav::HOME, RibbonTexts::for('ar'), MerchantData::identity($bid)),
            'limits' => ['title' => Seo::TITLE_MAX, 'desc' => Seo::DESC_MAX],
        ]);
    }

    public function saveSeo(Request $request)
    {
        $site = $this->siteOrFail();

        $data = $request->validate([
            // الحدّان ليسا اعتباطًا: ما زاد عنهما يُقصّ في نتائج البحث
            'title' => ['nullable', 'string', 'max:70'],
            'description' => ['nullable', 'string', 'max:170'],
            'image' => ['nullable', 'string', 'max:500'],
            'index' => ['required', 'boolean'],
        ]);

        $site->update(['seo' => [
            'title' => (string) ($data['title'] ?? ''),
            'description' => (string) ($data['description'] ?? ''),
            'image' => (string) ($data['image'] ?? ''),
            'index' => (bool) $data['index'],
        ]]);
        $site->touchDraft();

        /*
         * ولا تُكتب نسخةٌ ثانية في `settings`.
         *
         * كانت هنا كتابةٌ إلى مجموعة `seo` تقول إنّها «تُطعم الشاشة القديمة».
         * والمجموعة مرفوعةٌ من `MarketingSettings::GROUPS` — فـ`save` كانت
         * تُسقط المفاتيح الثلاثة بلا خبر، وشاشةُ السيو القديمة التي تقرؤها
         * لا وجود لها في النظام. سيو الموقع يسكن `websites.seo` وحده،
         * ومنه تُقرأ الشاشة أعلاه وتُبنى وسومُ الصفحة المنشورة.
         */

        return back()->with('toast', ['msg' => __('حُفظت إعدادات الظهور في البحث'), 'type' => 'success']);
    }

    /** إعدادات الموقع نفسه: اسمُه ووجهتُه */
    public function saveSite(Request $request)
    {
        $site = $this->siteOrFail();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'goal' => ['required', Rule::in(array_keys(Blueprints::GOALS))],
        ]);

        /*
         * وتبديل الوجهة لا يهدم ما بُني.
         *
         * من يبدّل «متجر» إلى «تعريفيّ» تبقى صفحاتُه وأقسامُه كما هي، ويسقط
         * ما لا يصلح للوجهة الجديدة من العرض وحده. وحذفُ الأقسام عند التبديل
         * كان سيُضيّع عملَ يومٍ بضغطةٍ في قائمةٍ منسدلة.
         */
        $site->update($data);
        $site->touchDraft();

        return back()->with('toast', ['msg' => __('حُفظت إعدادات الموقع'), 'type' => 'success']);
    }

}
