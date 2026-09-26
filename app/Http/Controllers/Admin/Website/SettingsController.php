<?php

namespace App\Http\Controllers\Admin\Website;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\PaymentGateway;
use App\Models\Product;
use App\Models\WebsiteSection;
use App\Support\MarketingSettings;
use App\Support\Seo;
use App\Support\Store\RibbonTexts;
use App\Support\Store\StoreNav;
use App\Support\Store\StoreSeo;
use App\Support\Website\Blueprints;
use App\Support\Website\Commerce;
use App\Support\Website\MerchantData;
use App\Support\Website\Readiness;
use App\Support\Website\Templates;
use Illuminate\Http\RedirectResponse;
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
    public function general(): Response|RedirectResponse
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
     * ضبطُ متجر الواجهة الخاصّة — صفحةٌ واحدة لا ستُّ شاشات.
     *
     * ═══ ما كان ═══
     *
     * ستُّ شاشاتٍ بشريط تبويبات، وفوقها شاشةُ «عام» التي هي **قائمةٌ ثانية**:
     * سبعُ بطاقاتٍ تقود إلى التبويبات نفسِها. فمن أراد تغيير رسم التوصيل مرّ
     * بقائمتين وحمّل الصفحةَ مرّتين قبل أن يبلغ حقلًا.
     *
     * وكان التوزيعُ غيرَ عادل: «الدومين» ثلاثةُ مقابض، و«الظهور في البحث»
     * ثلاثة، و«الصفحات» ثلاثة — و«المتجر والطلبات» اثنان وثلاثون ومعها
     * زرّا حفظٍ متجاوران لا يحفظ أحدُهما ما يحفظه الآخر.
     *
     * ═══ ولمَ صحّ الجمع ═══
     *
     * الشاشاتُ الأربعُ كانت تكتب في الباب نفسِه: `marketing.store.save`.
     * فالتفريقُ كان في الشاشة لا في الحفظ — وجمعُها يجعل زرَّ الحفظ واحدًا
     * كما هو الحفظُ في الخادم واحد.
     *
     * ولم تُجمع «التصميم» معها: تلك محرّرُ أقسامٍ بمعاينةٍ حيّة لا نموذجُ
     * حقول، وحشرُها في صفحةِ ضبطٍ يُفقدها المعاينةَ التي هي نصفُ عملها.
     *
     * وقائمةُ الجاهزية انتقلت إلى لوحة التشغيل: «أين متجري الآن» سؤالُ
     * لوحةٍ لا سؤالُ نموذج — انظر `HubController`.
     */
    private function themedGeneral(string $theme): Response
    {
        $bid = $this->bid();
        $business = Business::findOrFail($bid);
        $values = MarketingSettings::group($bid, 'website');

        $gateway = PaymentGateway::where('business_id', $bid)
            ->where('provider', PaymentGateway::PAYMOB)->first();

        $rows = StoreNav::rows($bid);
        $allowed = StoreNav::allowed($bid);

        $ways = array_values(array_filter([
            ($values['store_pay_cod'] ?? '1') === '1' ? __('نقد') : null,
            ($values['store_pay_transfer'] ?? '0') === '1' ? __('تحويل') : null,
            $gateway?->ready() ? __('بطاقة') : null,
        ]));

        return Inertia::render('Admin/Website/ThemeSettings', $this->themeShell($theme) + [
            'publishing' => $this->themePublishState($bid),
            'domain' => $this->domainState(),
            'storeOn' => ($values['store_on'] ?? '0') === '1',
            /*
             * وعددُ المعروض يُقال عند مفتاح النشر لا في شاشةٍ أخرى: صفحةٌ
             * فارغةٌ تُفقد الزبونَ ثقتَه ولا يعود إليها بعد أن رآها خالية.
             */
            'productCount' => Product::where('business_id', $bid)
                ->where('active', true)->where('published', true)->count(),

            /*
             * وسطرُ كلّ بطاقةٍ يُحسب هنا لا في الشاشة.
             *
             * البطاقةُ التي تقول «٤ صفحات» ثمّ يفتحها فيجد ثلاثًا تكذب عليه
             * مرّةً واحدةً فلا يصدّقها بعدها. والعدُّ من `StoreNav` نفسِها
             * التي ترسم القائمةَ في متجره.
             */
            'cards' => [
                'pages' => ['shown' => count($allowed) + 2, 'all' => count($rows)],
                'ways' => $ways,
                'seoIndexed' => ($values['store_seo_index'] ?? '1') === '1',
                'gatewayReady' => (bool) $gateway?->ready(),
            ],
        ]);
    }

    /**
     * «المتجر والطلبات» لصاحب الواجهة الخاصّة — أثقلُ تبويباته.
     *
     * وفيه ما قِيس يومًا فوُجد ثقيلًا: اثنان وثلاثون مقبضًا. والعطبُ يومَها
     * لم يكن العددَ بل **زرّي حفظٍ متجاورين** لا يحفظ أحدُهما ما يحفظه
     * الآخر — فمن ضبط التوصيل وضغط زرَّ الحقول خسر ما كتب.
     *
     * فزرُّ الحفظ هنا واحد، والمجموعاتُ ثلاثٌ تحته. ويبقى لبوّابة البطاقة
     * زرُّها وحدَها عن حقّ: أسرارُها تُكتب في `payment_gateways` لا في
     * إعدادات المتجر، وخلطُ سرٍّ مشفَّرٍ في حمولةِ ضبطٍ عامّة بابُ تسريب.
     */
    private function themedStore(string $theme): Response
    {
        $bid = $this->bid();
        $business = Business::findOrFail($bid);

        $gateway = PaymentGateway::where('business_id', $bid)
            ->where('provider', PaymentGateway::PAYMOB)->first();

        return Inertia::render('Admin/Website/ThemeStore', $this->themeShell($theme) + $this->themeSeed($business) + [
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

    /** «الظهور في البحث» لصاحب الواجهة الخاصّة */
    private function themedSeo(string $theme): Response
    {
        $bid = $this->bid();
        $business = Business::findOrFail($bid);

        return Inertia::render('Admin/Website/ThemeSeo', $this->themeShell($theme) + $this->themeSeed($business) + [
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

    public function store(): Response|RedirectResponse
    {
        // ولصاحب الواجهة الخاصّة شاشتُه على المسار نفسِه — من صنعته
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
    public function seo(): Response|RedirectResponse
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
