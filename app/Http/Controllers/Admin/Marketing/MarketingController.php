<?php

namespace App\Http\Controllers\Admin\Marketing;

use App\Http\Controllers\Admin\WhatsAppController;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Customer;
use App\Models\PaymentGateway;
use App\Models\PointTransaction;
use App\Models\Product;
use App\Models\Setting;
use App\Rules\SafeLink;
use App\Support\Activity;
use App\Support\Demo;
use App\Support\Loyalty;
use App\Support\MarketingSettings;
use App\Support\Seo;
use App\Support\FlowerOrder;
use App\Support\Store\CheckoutFields;
use App\Support\Store\StoreContent;
use App\Support\Store\ThemePublisher;
use App\Support\Store\StoreSeo;
use App\Support\Storefront;
use App\Support\Website\Domains;
use App\Support\WhatsAppEvent;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * أدوات التسويق — شاشاتُ إعدادٍ، والتقييماتُ والكوبونات لهما متحكّماهما.
 *
 * كلّها تقرأ وتكتب من `MarketingSettings` وحدها: مفتاحٌ يُقرأ بحرفٍ ويُكتب
 * بآخر لا يُخطئ أحدًا — تُقرأ القيمة الافتراضية بهدوء وتبدو الشاشة سليمة،
 * والإعداد الذي حفظه التاجر لا أثر له.
 *
 * وربطُ الأدوات الخارجية ليس هنا: خرائطُ Google ووصلةُ واتساب انتقلتا إلى
 * `Admin\IntegrationsController` — التسويقُ يملك ما يُفعَل بالأداة، لا وصلَها.
 * وبقي هنا من واتساب مقابضُ الأحداث وحدها: أيُّ رسالةٍ تخرج ومتى.
 */
class MarketingController extends Controller
{
    private function bid(): int
    {
        return auth()->user()->business_id ?? Demo::bid();
    }

    /* --------------------------- الموقع الإلكتروني --------------------------- */

    /**
     * النطاق يُحفظ — وما كان معه رُفع.
     *
     * كانت الشاشة تحفظ ثمانية مفاتيح: جملةً تعريفية، ونبذةً، وواتساب
     * وإنستغرام، و«نشر الموقع» و«عرض الأسعار» و«قبول الطلبات». تُملأ وتُحفظ
     * ولا يقرؤها شيء — لا واجهةَ متجرٍ في النظام تعرضها لأحد. فالتاجر يرفع
     * «نشر الموقع» ويظنّ أنّه نشر متجرًا، وينتظر طلبًا لا يأتي.
     *
     * وبقي النطاق وحده لأنّه وحده يُقرأ: يصير زرًّا في الشريط يفتح موقع
     * التاجر خارج النظام — انظر `Demo::websiteUrl`. وما حُفظ من المرفوع باقٍ
     * في القاعدة لم يُمحَ: إن بُنيت الواجهة يومًا وجدَ ما كُتب مكانَه.
     */
    public function saveWebsite(Request $request)
    {
        $data = $request->validate([
            'site_on' => ['sometimes', 'boolean'],
            /*
             * النطاق اسمٌ لا رابط.
             *
             * لصقُ «https://» أو مسارٍ بعده يبني روابط معطوبة في كل صفحة
             * (https://https://…)، ولا يظهر العطب إلا حين يفتحها زبون.
             */
            'site_domain' => ['nullable', 'string', 'max:255', 'regex:/^[a-zA-Z0-9][a-zA-Z0-9\-\.]*\.[a-zA-Z]{2,}$/'],
        ], [
            'site_domain.regex' => __('اكتب النطاق وحده بلا https:// ولا مسار — مثل: mystore.om'),
        ]);

        /*
         * ═══ والنطاق يمرّ بطبقته لا يُكتب هنا ═══
         *
         * كان يُفحص تفرّدُه بشرطٍ مكتوبٍ في هذا المتحكّم ثمّ يُحفظ صفًّا في
         * جدول المفاتيح. فلا تفرّدَ في القاعدة — طلبان متزامنان يمرّان
         * كلاهما — ولا حالَ للربط: التاجر يكتب نطاقه ويرى «حُفظ» ثمّ يفتحه
         * فلا يعمل، ولا شيء يقول له أين وقف.
         *
         * و`Domains::attach` تملك الاثنين: الصفَّ ذا الحال، والمرآةَ في
         * `site_domain` التي يقرؤها زرُّ الشريط والسيو. وكاتبٌ واحدٌ للاثنين
         * لا يفترق عن نفسه.
         */
        if (array_key_exists('site_domain', $data)) {
            $result = Domains::attach(Business::findOrFail($this->bid()), $data['site_domain']);

            if (! ($result['ok'] ?? false)) {
                return back()->withInput()->withErrors(['site_domain' => $result['error']]);
            }

            // والمرآةُ كتبتها الطبقة — فلا تُكتب هنا ثانيةً بقيمةٍ غير مطبَّعة
            unset($data['site_domain']);
        }

        MarketingSettings::save($this->bid(), 'website', $data);
        Seo::forget($this->bid());
        Activity::log('updated', 'حدّث إعدادات الموقع الإلكتروني');

        return back()->with('toast', ['msg' => __('حُفظت إعدادات الموقع'), 'type' => 'success']);
    }

    /**
     * اختيارُ الطريق إلى عنوانٍ على الإنترنت — سؤالٌ يُطرح مرّةً.
     *
     * ولا يمسّ هذا الحفظُ عنوانًا محجوزًا ولا نطاقًا مكتوبًا: التبديلُ رأيٌ
     * في أيّ بطاقةٍ تُعرض، لا محوٌ لما ضُبط. ومن جرّب «عندي نطاق» ثمّ عاد
     * إلى نطاق أبعاد يجب أن يجد عنوانه كما تركه — وإلّا صار السؤالُ فخًّا
     * يمحو عملَ صاحبه.
     */
    public function saveDomainPath(Request $request)
    {
        $data = $request->validate([
            'site_path' => ['required', Rule::in(Storefront::PATHS)],
        ]);

        MarketingSettings::save($this->bid(), 'website', $data);
        Activity::log('updated', 'اختار طريق نطاقه: '.$data['site_path']);

        return back()->with('toast', ['msg' => __('حُفظ اختيارك'), 'type' => 'success']);
    }

    /**
     * إنشاء متجر التاجر على الإنترنت — في نموذجٍ واحد.
     *
     * والعنوان يُحفظ في عمودٍ لا في مفتاح إعداد: التفرّد يُفرَض في القاعدة
     * (انظر هجرة `a_shop_gets_an_address`). والتحقّق هنا يسبقه ليقول للتاجر
     * «هذا الاسم محجوز» بدل أن يُردّ بخطأ قاعدةٍ لا يفهمه.
     */
    /**
     * ما يُعرض في المتجر — يُختار صنفًا صنفًا أو دفعةً واحدة.
     *
     * والدفعة ضرورةٌ لا رفاهية: تاجرٌ بخمسمئة صنفٍ يريد إخفاء موادّه الخام
     * كلَّها لا يفعلها بخمسمئة ضغطة، فيترك متجره كما هو ويظهر فيه ما لا يريد.
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
            // «الكلّ» تعني الفعّالة: ما أُطفئ في نقطة البيع لا يُعرض أصلًا
            $query->where('active', true);
        } else {
            $query->whereIn('id', $data['ids'] ?? []);
        }

        /*
         * والعدد عدد ما تغيّر لا عدد ما شمله الاستعلام: «٥٠٠ صنفًا صار يظهر»
         * لتاجرٍ ٤٩٠ منها ظاهرةٌ أصلًا رقمٌ لا يصف شيئًا.
         */
        $published = $request->boolean('published');
        $count = $query->where('published', ! $published)->update(['published' => $published]);

        Activity::log('updated', $published
            ? 'عرض '.$count.' صنفًا في متجره'
            : 'أخفى '.$count.' صنفًا من متجره');

        return back()->with('toast', [
            'msg' => $published
                ? __(':n صنفًا صار يظهر في متجرك', ['n' => $count])
                : __(':n صنفًا لم يعد يظهر في متجرك', ['n' => $count]),
            'type' => 'success',
        ]);
    }

    /**
     * مفاتيحُ بوّابة الدفع — تُكتب ولا تُقرأ.
     *
     * ═══ وبابٌ على حدة ═══
     *
     * لا تُحفظ مع سائر الإعدادات في `settings`: ذاك جدولٌ نصّيٌّ مكشوف،
     * وهذان سرّان يُقبض بهما المال. فلهما جدولُهما وعمودان مشفَّران —
     * قاعدةُ `WhatsAppConnection` نفسُها في هذا المستودع.
     *
     * والفراغُ يعني «لا تبدّله»: الشاشةُ لا تعرض السرَّ فلا يُعاد إرسالُه،
     * وحفظُ اسمٍ أو رقمٍ بجانبه كان يمحوه لو قُرئ الفراغُ محوًا.
     */
    public function savePaymentGateway(Request $request)
    {
        $data = $request->validate([
            'active' => ['sometimes', 'boolean'],
            'public_key' => ['nullable', 'string', 'max:255'],
            'card_integration_id' => ['nullable', 'string', 'max:32'],
            'secret_key' => ['nullable', 'string', 'max:255'],
            'hmac_secret' => ['nullable', 'string', 'max:255'],
        ], [], [
            'public_key' => __('المفتاح العامّ'),
            'card_integration_id' => __('رقم تكامل البطاقة'),
            'secret_key' => __('المفتاح السرّي'),
            'hmac_secret' => __('سرّ التوقيع'),
        ]);

        $gateway = PaymentGateway::firstOrNew([
            'business_id' => $this->bid(),
            'provider' => PaymentGateway::PAYMOB,
        ]);

        $gateway->public_key = trim((string) ($data['public_key'] ?? ''));
        $gateway->card_integration_id = trim((string) ($data['card_integration_id'] ?? ''));

        // والسرُّ لا يُمسّ إلّا إن كُتب من جديد
        foreach (['secret_key', 'hmac_secret'] as $secret) {
            if (filled($data[$secret] ?? null)) {
                $gateway->{$secret} = trim((string) $data[$secret]);
            }
        }

        $gateway->active = $request->boolean('active');
        $gateway->save();

        /*
         * ولا تُرفع بوّابةٌ ناقصة.
         *
         * زبونٌ يختار «بطاقة» على بوّابةٍ بلا سرِّ توقيعٍ يدفع ولا يُصدَّق
         * إشعارُه — فيخرج مالُه ولا يصله طلب. والشاشةُ تمنعه، ومن أرسل
         * الحمولةَ بيده لا.
         */
        if ($gateway->active && ! $gateway->ready()) {
            $gateway->forceFill(['active' => false])->save();

            return back()->withErrors([
                'active' => __('أكمل المفاتيح الأربعة قبل تشغيل الدفع بالبطاقة — بوّابةٌ ناقصة تأخذ المال ولا تُنشئ طلبًا.'),
            ]);
        }

        Activity::log('updated', 'حدّث بوّابة الدفع');

        return back()->with('toast', ['msg' => __('حُفظت بوّابة الدفع'), 'type' => 'success']);
    }

    public function saveStore(Request $request)
    {
        // ثلاثُ حالاتٍ لا أكثر — والفراغُ رابعٌ يعني «ما كان»
        $state = ['nullable', 'in:'.implode(',', CheckoutFields::STATES)];

        $business = Business::findOrFail($this->bid());

        $data = $request->validate([
            'site_slug' => ['nullable', 'string', 'max:63'],
            'store_on' => ['sometimes', 'boolean'],
            'store_theme' => ['sometimes', Rule::in(array_keys(Storefront::THEMES))],
            'store_headline' => ['nullable', 'string', 'max:80'],
            'store_about' => ['nullable', 'string', 'max:400'],
            'store_show_prices' => ['sometimes', 'boolean'],
            /*
             * ═══ ومفتاحُ قبول الطلبات — وكان يُرسَل ولا يُكتب ═══
             *
             * معرَّفٌ في `MarketingSettings::GROUPS` منذ نسخ، وتقرؤه
             * `WebCheckout::settings` فيحكم ظهورَ السلّة في الواجهة. وكاتبُه
             * الوحيد كان شاشةَ متجر البانِي (`Website\SettingsController`) —
             * وهي شاشةٌ يُردّ عنها صاحبُ الواجهة الخاصّة.
             *
             * فصار في شاشته مقبضٌ يُقلَّب ويُحفظ ويُردّ «حُفظ متجرك» ولا
             * يتبدّل شيء: `validated()` تُسقط ما ليس في هذه القائمة بهدوء.
             */
            'store_allow_orders' => ['sometimes', 'boolean'],
            'store_whatsapp' => ['nullable', 'string', 'max:30'],
            'store_pay_cod' => ['sometimes', 'boolean'],
            'store_pay_transfer' => ['sometimes', 'boolean'],
            'store_bank' => ['nullable', 'string', 'max:400'],
            // التوصيل — يقرؤه إتمامُ الطلب في الواجهة الخاصّة وحده
            'store_delivery_fee' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'store_free_delivery_over' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'store_delivery_areas' => ['nullable', 'string', 'max:1000'],
            'store_delivery_slots' => ['nullable', 'string', 'max:400'],
            'store_hours' => ['nullable', 'string', 'max:120'],
            'store_delivery_note' => ['nullable', 'string', 'max:200'],
            /*
             * وتنبيهُ الصورة — سطرٌ لا فقرة.
             *
             * ومئتان حدُّه كحدّ أختِه: يُعرض تحت الصورة وفوق زرّ الطلب، وما
             * طال هناك لا يُقرأ — يُدفع الزرُّ خارج الشاشة على الجوّال.
             * والزائدُ يُردّ برسالةٍ ولا يُقصّ بصمت: من كتب ثلاثمئة وقُصّ له
             * عند المئتين يقرأ جملتَه مبتورةً في وجه زبونه.
             */
            'store_image_note' => ['nullable', 'string', 'max:200'],
            /*
             * وكرتُ الهدية — صنفٌ يُباع في الموقع (انظر `Store\GiftCard`).
             *
             * ولا ثمنَ افتراضيَّ له: من رفع مفتاحَه سعّره، ومن تركه بلا ثمنٍ
             * لا يُعرض كرتُه. والحارسُ على الاثنين معًا بعد التحقّق أدناه —
             * فالقاعدةُ بين حقلين لا في حقل.
             */
            'store_gift_card' => ['sometimes', 'boolean'],
            'store_gift_card_price' => ['nullable', 'numeric', 'min:0', 'max:1000'],

            /*
             * وحقولُ إتمام الطلب — ما يُعرض منها وما يُشترط.
             *
             * والفراغُ مقبولٌ ويعني «ما كان» (انظر `Store\CheckoutFields`)،
             * فشاشةٌ حُفظت قبل أن تُضاف هذه المفاتيح لا تُبدّل متجرًا.
             */
            'store_field_area' => $state,
            'store_field_address' => $state,
            'store_field_date' => $state,
            'store_field_slot' => $state,
            'store_field_recipient' => $state,
            'store_field_promo' => $state,
            'store_fulfil' => ['nullable', 'string', 'max:40'],
            'store_max_days' => ['nullable', 'integer', 'min:0', 'max:365'],

            /*
             * وصفحةُ المتجر — ما فيها وترتيبُه (انظر `Store\StorePage`).
             *
             * والصورتان رابطان لا ملفّان: تُرفعان ببابٍ على حدة ويُحفظ ما
             * يعود منه — فالنموذجُ يبقى JSON ولا يصير `multipart` من أجل
             * حقلين. وهي قاعدةُ `GiftCard::hold` نفسُها.
             */
            'store_hero_image' => ['nullable', 'string', 'max:2048', new SafeLink],
            'store_featured' => ['nullable', 'string', 'max:200'],
            'store_sections' => ['nullable', 'string', 'max:200'],
            'store_block_on' => ['sometimes', 'boolean'],
            'store_block_title' => ['nullable', 'string', 'max:120'],
            'store_block_text' => ['nullable', 'string', 'max:1000'],
            'store_block_image' => ['nullable', 'string', 'max:2048', new SafeLink],
            'store_block_cta' => ['nullable', 'string', 'max:40'],
            /*
             * ووجهةُ الزرّ تُحرس بـ`SafeLink` — لا بـ`string` وحدها.
             *
             * الحقلُ نصٌّ حرٌّ يكتبه صاحبُ المحلّ، ويخرج في `href` على صفحةٍ
             * عامّة. ووجهةٌ تبدأ بـ`javascript:` سطرُ كودٍ يُنفَّذ في متصفّح
             * كلّ زائر — ومتاجرُ أبعاد على نطاقٍ واحد، فما يُحقن في صفحةِ
             * متجرٍ يقرأ ما يخصّ النطاق نفسَه. والصورتان مثلُها: رابطُهما
             * يعود من بابِ الرفع، ومن أرسل الحمولةَ بيده لا يمرّ بالباب.
             */
            'store_block_href' => ['nullable', 'string', 'max:2048', new SafeLink],
            'store_banner_image' => ['nullable', 'string', 'max:2048', new SafeLink],
            'store_tagline' => ['nullable', 'string', 'max:60'],

            /*
             * وصفحاتُ المتجر — ما أذِن به من «من نحن» و«تواصل معنا».
             *
             * والقيمُ محصورةٌ في `StoreNav::OPTIONAL`: القائمةُ تُقرأ في
             * `allowed` وتُرشَّح هناك، فاسمٌ غريبٌ يسقط. والحدُّ هنا يمنع
             * سطرًا طويلًا يُخزَّن بلا معنى.
             */
            'store_pages' => ['nullable', 'string', 'max:120'],
            'store_about_image' => ['nullable', 'string', 'max:2048', new SafeLink],

            /*
             * وما يقرؤه غوغل — والحدّان أوسعُ ممّا تنصح به الشاشة.
             *
             * ستّون حرفًا للعنوان ومئةٌ وستّون للوصف حدُّ ما يُعرض في نتيجة
             * البحث لا حدُّ ما يصحّ حفظُه. والشاشةُ تقول له أين يُقصّ، ولا
             * يُردّ حفظُه برسالةٍ لأنّه تجاوزه بحرفين.
             */
            'store_seo_title' => ['nullable', 'string', 'max:'.StoreSeo::TITLE_MAX],
            'store_seo_desc' => ['nullable', 'string', 'max:'.StoreSeo::DESC_MAX],
            'store_seo_index' => ['sometimes', 'boolean'],
        ]);

        /*
         * ═══ ومتجرٌ لا يُسلّم شيئًا لا يُحفظ ═══
         *
         * إطفاءُ التوصيل والاستلام معًا يترك زبونًا يملأ النموذجَ ثمّ يُردّ
         * بخطأٍ عن حقلٍ لا يراه. والشاشةُ تمنعه، ومن أرسل الحمولةَ بيده لا.
         */
        /*
         * والسؤالُ «أأرسلت الشاشةُ المفتاح؟» يُطرح على الطلب لا على المنقّى.
         *
         * `ConvertEmptyStringsToNull` تقلب الفراغَ إلى `null`، وقائمةُ
         * `validated()` تُسقط المفتاحَ حينئذٍ — فيمرّ إطفاءُ الطريقتين معًا
         * من هذا الحارس بلا أن يُقرأ. و`exists` تقول «أُرسل» لا «مُلئ».
         */
        if ($request->exists('store_fulfil')) {
            $picked = array_values(array_intersect(
                FlowerOrder::FULFILLMENT,
                array_map('trim', explode(',', (string) $request->input('store_fulfil'))),
            ));

            if ($picked === []) {
                return back()->withErrors(['store_fulfil' => __('اختر طريقة استلامٍ واحدةً على الأقل.')]);
            }

            $data['store_fulfil'] = implode(',', $picked);

            /*
             * ولا عنوانَ ولا منطقةَ في متجرٍ يوصّل يعني سائقًا بلا وجهة.
             *
             * وأحدُهما يكفي: من يوصّل داخل مناطقَ معدودةٍ ويتّصل ليسأل عن
             * البيت يكتفي بالمنطقة — والاثنان معًا مُطفأان لا يُكتفى بهما.
             */
            $shown = fn (string $f) => ($data['store_field_'.$f] ?? '') !== CheckoutFields::OFF;

            if (in_array(FlowerOrder::DELIVERY, $picked, true) && ! $shown('area') && ! $shown('address')) {
                return back()->withErrors([
                    'store_field_address' => __('متجرٌ يوصّل يسأل عن المنطقة أو العنوان — لا يُخفيان معًا.'),
                ]);
            }
        }

        /*
         * ═══ ولا يُرفع مفتاحُ كرت الهدية بلا ثمن ═══
         *
         * لا ثمنَ افتراضيَّ في النظام: صاحبُ المحلّ هو من يُسعّر كرتَه. فمفتاحٌ
         * مرفوعٌ بلا ثمنٍ صالحٍ كان يعني كرتًا يُباع بخمسِ مئةِ بيسةٍ لم
         * يخترها أحد — تدخل فاتورةَ زبونٍ وإيرادَ دفتر.
         *
         * والقاعدةُ بين حقلين، فمحلُّها هنا لا في جدول القواعد: من رفع
         * المفتاح في هذه الحفظة، أو كان مرفوعًا من قبلُ وهو يُعدّل ثمنَه.
         *
         * و`exists` لا `filled`: حفظةٌ لا تحمل الحقلَ أصلًا تقرأ المحفوظ —
         * فشاشةٌ تُرسل جزءًا لا تُطفئ كرتًا مُسعّرًا ولا تُجيز مفتاحًا بلا ثمن.
         * وهي قاعدةُ `store_on` نفسُها أدناه.
         */
        $cardOn = $request->exists('store_gift_card')
            ? $request->boolean('store_gift_card')
            : (MarketingSettings::group($this->bid(), 'website')['store_gift_card'] ?? '') === '1';

        if ($cardOn) {
            $cardPrice = $request->exists('store_gift_card_price')
                ? trim((string) $request->input('store_gift_card_price'))
                : trim((string) (MarketingSettings::group($this->bid(), 'website')['store_gift_card_price'] ?? ''));

            if ($cardPrice === '' || ! is_numeric($cardPrice) || round((float) $cardPrice, 3) <= 0) {
                throw ValidationException::withMessages([
                    'store_gift_card_price' => __('اكتب سعر كرت الهدية قبل تفعيله — سعرًا أكبر من صفر.'),
                ]);
            }
        }

        /*
         * ═══ والعنوانُ لا يُمسّ إلّا إن أُرسل ═══
         *
         * `input` تردّ `null` على مفتاحٍ غائبٍ كما تردّها على حقلٍ فُرّغ —
         * فحفظٌ لا يحمل العنوان كان يمحوه، ويصير المتجرُ «منشورًا» في شاشته
         * و404 في كلّ رابط. وهي الحالةُ التي يرفض الحارسُ أدناه إنشاءها
         * بالكتابة، فتُنشأ بالسكوت.
         *
         * و`exists` تقول «أُرسل» لا «مُلئ» — فيبقى تفريغُ الحقل محوًا كما
         * قصده صاحبُه. وهي قاعدةُ `store_fulfil` نفسُها أعلاه.
         */
        $slug = $request->exists('site_slug')
            ? Storefront::slug($request->input('site_slug'))
            : $business->site_slug;

        if (filled($request->input('site_slug')) && $slug === null) {
            throw ValidationException::withMessages([
                'site_slug' => __('العنوان حروفٌ إنجليزية صغيرة وأرقام وشرطة، من :min إلى :max حرفًا، وليس اسمًا محجوزًا.', [
                    'min' => Storefront::MIN, 'max' => Storefront::MAX,
                ]),
            ]);
        }

        if ($slug !== null && Business::where('site_slug', $slug)->whereKeyNot($business->id)->exists()) {
            throw ValidationException::withMessages([
                'site_slug' => __('هذا العنوان محجوز لمتجرٍ آخر — اختر غيره.'),
            ]);
        }

        /*
         * ولا يُنشر متجرٌ بلا عنوان.
         *
         * النشرُ بلا عنوانٍ حالةٌ لا معنى لها: المفتاح مرفوع والصفحة لا تُفتح
         * من أيّ رابط. والرفضُ هنا بكلمةٍ أوضح من تركه يُحفظ ثمّ يسأل صاحبُه
         * لماذا لا يعمل موقعه.
         */
        /*
         * والمنشورُ من لم يُرسل مفتاحَه يبقى منشورًا — فيُقرأ المحفوظ.
         *
         * ولولاه لَمُحي عنوانُ متجرٍ منشورٍ بحفظٍ لا يحمل `store_on`، ومرّ
         * من هذا الحارس لأنّ السؤال وقع على الحمولة لا على الحال.
         */
        $published = $request->exists('store_on')
            ? $request->boolean('store_on')
            : (MarketingSettings::group($this->bid(), 'website')['store_on'] ?? '') === '1';

        if ($published && $slug === null) {
            throw ValidationException::withMessages([
                'site_slug' => __('اكتب عنوان متجرك قبل نشره — بلا عنوانٍ لا يُفتح من أيّ رابط.'),
            ]);
        }

        $business->forceFill(['site_slug' => $slug])->save();

        /*
         * والاسمُ المحجوز يصير عنوانًا في جدول العناوين.
         *
         * ولولا هذا السطر لَكان الاسمُ يُحفظ في عمودٍ ولا يُعرف صاحبُ العنوان
         * حين يصل: القارئُ يسأل جدولَ العناوين وحده (انظر `Domains::resolve`)،
         * ومن كتب اسمه بعد الهجرة لا صفَّ له فيه.
         */
        Domains::sync($business->refresh());

        foreach (['store_on', 'store_show_prices', 'store_allow_orders', 'store_pay_cod', 'store_pay_transfer', 'store_gift_card', 'store_block_on', 'store_seo_index'] as $flag) {
            if (array_key_exists($flag, $data)) {
                $data[$flag] = $request->boolean($flag) ? '1' : '0';
            }
        }

        /*
         * ═══ ما يُنشر يذهب إلى المسوّدة، وما يسري فورًا يُكتب حيًّا ═══
         *
         * والقسمةُ في `StoreContent::split` لا هنا: هي العقدُ الذي يقول أيُّ
         * مفتاحٍ يُجمَّد — ومفتاحٌ يُضاف غدًا يُقرَّر مرّةً في موضعٍ واحد.
         *
         * ومتجرٌ لم يُفتح له النشرُ بعدُ (لا صفَّ له في `store_sites`) يعمل
         * كما كان **بالضبط**: كلُّ ما وصل يُكتب حيًّا. فالنظامُ لا يُفرَض
         * دفعةً واحدة، والتراجعُ حذفُ صفٍّ لا ترحيلٌ عكسيّ.
         */
        if (StoreContent::usesDrafts($this->bid())) {
            [$versioned, $liveNow] = StoreContent::split($data);

            ThemePublisher::saveDraft($this->bid(), $versioned, auth()->id());
            MarketingSettings::save($this->bid(), 'website', $liveNow);
            Activity::log('updated', 'حفظ مسودة متجره الإلكتروني');

            return back()->with('toast', [
                'msg' => __('حُفظت مسودتك — انشرها لتظهر لزبائنك'), 'type' => 'success',
            ]);
        }

        MarketingSettings::save($this->bid(), 'website', $data);
        Activity::log('updated', 'حدّث متجره الإلكتروني');

        return back()->with('toast', ['msg' => __('حُفظ متجرك الإلكتروني'), 'type' => 'success']);
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

    /**
     * صورةُ المتجر — الواجهةُ أو صورةُ القسم الذي يكتبه بنفسه.
     *
     * ═══ ولمَ بابٌ هنا لا `Website\MediaController` ═══
     *
     * ذاك يبدأ بـ`siteOrFail()`، وهي تردّ من لبس واجهةً خاصّة صراحةً: «ومن
     * لبس واجهةً خاصّة لا شاشاتِ بانٍ له». فصاحبُ RIBBON لا يبلغه أصلًا —
     * وهو بالضبط من يحتاجه هنا.
     *
     * وما يعود رابطٌ يُحفظ في الإعداد، فيبقى نموذجُ المتجر JSON ولا يصير
     * `multipart` من أجل حقلين.
     */
    public function uploadStoreImage(Request $request)
    {
        $request->validate([
            'image' => ['required', 'image', 'max:4096'],
        ], [
            'image.image' => __('الملفّ صورة — PNG أو JPG أو WEBP'),
            'image.max' => __('أقصى حجمٍ للصورة ٤ ميغابايت'),
        ]);

        // في مجلّد النشاط لا في مجلّدٍ عامّ — فنسخُ متجرٍ أو حذفُه يعرف ما يخصّه
        $path = $request->file('image')->store('website/'.$this->bid(), 'public');

        return back()->with('uploaded', \Illuminate\Support\Facades\Storage::url($path));
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
            : ['msg' => $result['error'] ?? __('تعذّر فحص الموقع'), 'type' => 'danger']);
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
     * الكوبونات — ولا دفترُ هواتفَ معها.
     *
     * كانت الصفحة تحمل `segments`: اسمَ كلّ عميلٍ ورقمَ هاتفه ومجموعَ إنفاقه
     * وتاريخَ آخر طلبٍ له. ولا سطرَ في الشاشة يقرؤها — لا قائمةٌ ولا زرّ.
     *
     * وثمنُها ثلاثة: مسحُ جدول العملاء كلِّه واستعلامُ تجميعٍ فوقه في كلّ
     * فتحة؛ وحمولةٌ تكبر مع كلّ زبونٍ جديد؛ وأخطرُها أنّها **تُقرأ من مصدر
     * الصفحة**. و«التسويق» قسمٌ غير «العملاء» في الصلاحيات: موظّفٌ مُنح
     * الكوبونات ولم يُمنح العملاء كان يقرأ أرقامهم كلَّها من شاشةٍ لا تعرض
     * منها حرفًا.
     */
    public function coupons(): Response
    {
        return Inertia::render('Admin/Marketing/Coupons', [
            'stats' => Demo::couponStats(),
            'coupons' => Demo::coupons(),
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
         * مقابضُ الأحداث وحدها — ولا رقمَ ولا نصَّ رسالةٍ ولا مفتاحَ تفعيلٍ
         * ثانٍ: كانت تُقبل وتُحفظ ولا يقرؤها مُرسِل الرسائل. والتفعيل من
         * بطاقة الوصلة، والرقم رقمُها، والنصّ قالبٌ معتمَدٌ عند ميتا.
         *
         * ═══ والقائمةُ تُشتقّ من الأحداث لا تُكتب بجانبها ═══
         *
         * كانت أربعةَ أسطرٍ مكتوبةٍ بيد، والأحداثُ ستّة. فحدثان أُضيفا
         * (تذكيرُ الفاتورة قبل الاستحقاق وبعده) رسمت لهما الشاشةُ مقبضين —
         * لأنّها تقرأ `WhatsAppEvent::ALL` — ويسقطان هنا بلا خبر: التاجر
         * يُشعل التذكير ويحفظ ويرى «حُفظت إعداداتك»، ولا صفَّ يُكتب.
         *
         * وقائمةٌ تُكتب باليد بجانب قائمةٍ تُقرأ تنسى التاليَ دائمًا.
         */
        $data = $request->validate(
            array_fill_keys(
                array_values(WhatsAppEvent::SETTING_KEYS),
                ['nullable', 'boolean'],
            ),
        );

        MarketingSettings::save($this->bid(), 'whatsapp', $data);
        Activity::log('updated', 'حدّث إشعارات واتساب');

        return back()->with('toast', ['msg' => __('حُفظت إعدادات واتساب'), 'type' => 'success']);
    }
}
