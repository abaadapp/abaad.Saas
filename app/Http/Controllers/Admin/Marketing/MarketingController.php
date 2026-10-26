<?php

namespace App\Http\Controllers\Admin\Marketing;

use App\Http\Controllers\Admin\WhatsAppController;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Customer;
use App\Models\PointTransaction;
use App\Models\Product;
use App\Models\Setting;
use App\Rules\SafeLink;
use App\Support\Activity;
use App\Support\Demo;
use App\Support\Loyalty;
use App\Support\MarketingSettings;
use App\Support\Permissions;
use App\Support\Seo;
use App\Support\FlowerOrder;
use App\Support\Store\CheckoutFields;
use App\Support\Store\DeliverySchedule;
use App\Support\Store\NewArrivals;
use App\Support\Store\OrderWindow;
use App\Support\Store\StoreBranches;
use App\Support\Store\StoreTerms;
use App\Support\Store\PaidReceipt;
use App\Support\Store\ShelfOrder;
use App\Support\Store\RibbonPicks;
use App\Support\Store\RibbonUpsells;
use App\Support\Store\StoreContent;
use App\Support\Store\StoreCollections;
use App\Support\Store\StoreCustomPages;
use App\Support\Store\StoreFooter;
use App\Support\Store\StoreImageUpload;
use App\Support\Store\StoreMedia;
use App\Support\Store\StoreNav;
use App\Support\Store\StoreHeader;
use App\Support\Store\StoreVisualBar;
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
     * اسمُ حساب إنستغرام — من الاسم كما كُتب، أو من رابط الحساب كاملًا.
     *
     * يلصق صاحبُ المتجر الرابطَ من هاتفه أكثرَ ممّا يكتب الاسم، فيُؤخذ منه
     * الاسمُ وحده: حروفٌ إنجليزيّةٌ وأرقامٌ ونقطةٌ وشرطةٌ سفليّة كما يقبلها
     * إنستغرام نفسُه. وما سواها يُردّ برسالة — لا يُحفظ رابطٌ إلى غير حسابه.
     */
    private function instagramHandle(string $raw): string
    {
        $handle = trim($raw);
        $handle = (string) preg_replace('#^(?:https?://)?(?:www\.)?instagram\.com/#i', '', $handle);
        $handle = ltrim(explode('/', explode('?', $handle)[0])[0], '@');

        if (preg_match('/^[A-Za-z0-9._]{1,30}$/', $handle) !== 1) {
            throw ValidationException::withMessages([
                'store_instagram' => __('اكتب اسم حساب إنستغرام وحده — مثل ribbon.om — أو الصق رابط حسابك.'),
            ]);
        }

        return $handle;
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
            // والنبذةُ بالإنجليزيّة — بحدّ العربيّة، ولا تقع إحداهما على الأخرى (`StorePage::about`)
            'store_about_en' => ['nullable', 'string', 'max:400'],
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
            /*
             * وجدولُ الاستقبال ورسالةُ الإغلاق بلغتيها — والجدولُ نصُّ JSON
             * يُفحص بعد هذه القائمة (`OrderWindow::validated`).
             */
            OrderWindow::ON => ['sometimes', 'boolean'],
            OrderWindow::KEY => ['nullable', 'string', 'max:20000'],
            OrderWindow::MESSAGE_ON => ['sometimes', 'boolean'],
            OrderWindow::MESSAGE => ['nullable', 'string', 'max:'.OrderWindow::MESSAGE_MAX],
            OrderWindow::MESSAGE_EN => ['nullable', 'string', 'max:'.OrderWindow::MESSAGE_MAX],
            // والشروطُ بلغتيها — تُكتب نسخةً لا إعدادًا (`StoreTerms::revise`)
            StoreTerms::ON => ['sometimes', 'boolean'],
            StoreTerms::TEXT => ['nullable', 'string', 'max:'.StoreTerms::MAX],
            StoreTerms::TEXT_EN => ['nullable', 'string', 'max:'.StoreTerms::MAX],
            // وجدولُ أيّام التسليم وفتراتِه — يُفحص بعد القائمة (`DeliverySchedule::validated`)
            DeliverySchedule::ON => ['sometimes', 'boolean'],
            DeliverySchedule::KEY => ['nullable', 'string', 'max:20000'],
            // وما يقرؤه الزبونُ حين لا موعدَ توصيل — يكتبه صاحبُ المتجر بلغتيه
            DeliverySchedule::UNAVAILABLE => ['nullable', 'string', 'max:'.DeliverySchedule::UNAVAILABLE_MAX],
            DeliverySchedule::UNAVAILABLE_EN => ['nullable', 'string', 'max:'.DeliverySchedule::UNAVAILABLE_MAX],
            'store_whatsapp' => ['nullable', 'string', 'max:30'],
            /*
             * وحسابُ إنستغرام — كان حقلُه في المحرّر بلا قاعدةٍ هنا، فيُسقطه
             * `validate` ولا يُحفظ أبدًا. ويُقبل الاسمُ أو رابطُ الحساب كاملًا،
             * ويُحفظ الاسمُ وحده (`instagramHandle` أدناه).
             */
            'store_instagram' => ['nullable', 'string', 'max:100'],
            'store_pay_cod' => ['sometimes', 'boolean'],
            'store_pay_transfer' => ['sometimes', 'boolean'],
            /*
             * وفرعُ طلبات الموقع — من فروع هذا المتجر الحيّة وحدها، وفارغًا يعني
             * أوّلَها كما كان. واختيارُ الزبون فرعَ الاستلام مقبضٌ مطفأ. انظر
             * `Store\StoreBranches`.
             */
            StoreBranches::DEFAULT => ['nullable', 'integer', Rule::exists('branches', 'id')
                ->where('business_id', $business->id)->whereNull('deleted_at')],
            StoreBranches::CHOICE => ['sometimes', 'boolean'],
            // إيصالُ الدفع الإلكترونيّ للزبون — انظر `Store\PaidReceipt` وحارسَه تحت
            'store_paid_receipt' => ['sometimes', 'boolean'],
            /*
             * ونصوصُ صفحة الشكر — نصٌّ حرٌّ يُطبع مُهرَّبًا، لا وسمَ فيه يُرسم.
             * والحدودُ حدودُ موضعها: عنوانٌ سطر، ورسالةٌ فقرةٌ قصيرة، وزرٌّ
             * كلمات. انظر `Store\ThankYouPage`.
             */
            'store_thanks_title' => ['nullable', 'string', 'max:120'],
            'store_thanks_title_en' => ['nullable', 'string', 'max:120'],
            'store_thanks_message' => ['nullable', 'string', 'max:300'],
            'store_thanks_message_en' => ['nullable', 'string', 'max:300'],
            'store_thanks_receipt' => ['nullable', 'string', 'max:40'],
            'store_thanks_receipt_en' => ['nullable', 'string', 'max:40'],
            'store_thanks_continue' => ['nullable', 'string', 'max:40'],
            'store_thanks_continue_en' => ['nullable', 'string', 'max:40'],
            'store_bank' => ['nullable', 'string', 'max:400'],
            // التوصيل — يقرؤه إتمامُ الطلب في الواجهة الخاصّة وحده
            'store_delivery_fee' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'store_free_delivery_over' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'store_delivery_areas' => ['nullable', 'string', 'max:1000'],
            'store_delivery_slots' => ['nullable', 'string', 'max:400'],
            'store_hours' => ['nullable', 'string', 'max:120'],
            'store_hours_en' => ['nullable', 'string', 'max:120'],
            'store_delivery_note' => ['nullable', 'string', 'max:200'],
            // وبالإنجليزيّة — للصفحة الإنجليزيّة وحدها، بالحدّ نفسِه
            'store_delivery_note_en' => ['nullable', 'string', 'max:200'],
            /*
             * وتنبيهُ الصورة — سطرٌ لا فقرة.
             *
             * ومئتان حدُّه كحدّ أختِه: يُعرض تحت الصورة وفوق زرّ الطلب، وما
             * طال هناك لا يُقرأ — يُدفع الزرُّ خارج الشاشة على الجوّال.
             * والزائدُ يُردّ برسالةٍ ولا يُقصّ بصمت: من كتب ثلاثمئة وقُصّ له
             * عند المئتين يقرأ جملتَه مبتورةً في وجه زبونه.
             */
            'store_image_note' => ['nullable', 'string', 'max:200'],
            'store_image_note_en' => ['nullable', 'string', 'max:300'],
            // وتنبيهُ نطاق التوصيل — لكلّ لغةٍ نصُّها (`WebCheckout::settings`)
            'store_delivery_area_note' => ['nullable', 'string', 'max:300'],
            'store_delivery_area_note_en' => ['nullable', 'string', 'max:300'],
            /*
             * وكرتُ الهدية — صنفٌ يُباع في الموقع (انظر `Store\GiftCard`).
             *
             * ولا ثمنَ افتراضيَّ له: من رفع مفتاحَه سعّره، ومن تركه بلا ثمنٍ
             * لا يُعرض كرتُه. والحارسُ على الاثنين معًا بعد التحقّق أدناه —
             * فالقاعدةُ بين حقلين لا في حقل.
             */
            'store_gift_card' => ['sometimes', 'boolean'],
            /*
             * ولا مفتاحَ للإهداء هنا: يفتحه مديرُ المنصّة (`Store\GiftOrders::on`).
             * و`store_gift_checkout` بلا قاعدةٍ لا يبلغ الحفظ مهما أُرسل.
             */
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
            // وعنوانُ الواجهة ووصفُها لكلّ لغة — والفراغُ يُبقي نصَّ القالب (`StorePage::heroTitle`)
            'store_hero_title' => ['nullable', 'string', 'max:80'],
            'store_hero_title_en' => ['nullable', 'string', 'max:80'],
            'store_hero_sub' => ['nullable', 'string', 'max:200'],
            'store_hero_sub_en' => ['nullable', 'string', 'max:200'],
            'store_featured' => ['nullable', 'string', 'max:200'],
            // و«وصل حديثًا» اليدويّ — قواعدُه وإذنُه في `NewArrivals::validated` أدناه
            'store_new_arrivals_mode' => ['nullable', 'string', 'max:10'],
            'store_new_arrivals' => ['nullable', 'string', 'max:200'],
            // وترتيبُ المنتجات في الرفّ نصُّ JSON — إذنُه وقواعدُه في `ShelfOrder::validated` أدناه
            'store_shelf_order' => ['nullable', 'string', 'max:'.ShelfOrder::RAW_MAX],
            'store_sections' => ['nullable', 'string', 'max:200'],
            'store_block_on' => ['sometimes', 'boolean'],
            'store_block_title' => ['nullable', 'string', 'max:120'],
            'store_block_text' => ['nullable', 'string', 'max:1000'],
            'store_block_image' => ['nullable', 'string', 'max:2048', new SafeLink],
            'store_block_cta' => ['nullable', 'string', 'max:40'],
            // والقسمُ الحرُّ بالإنجليزيّة — بحدود العربيّة نفسِها، والصورةُ والوجهةُ واحدتان
            'store_block_title_en' => ['nullable', 'string', 'max:120'],
            'store_block_text_en' => ['nullable', 'string', 'max:1000'],
            'store_block_cta_en' => ['nullable', 'string', 'max:40'],
            /*
             * و«اختيارات RIBBON» — عنوانٌ لكلّ لغة. والقائمةُ وإذنُ القسم
             * في `RibbonPicks::validated` أدناه، و«أضف مع طلبك» في
             * `RibbonUpsells::validated` — لا قاعدةَ نصٍّ لهما هنا.
             */
            'store_picks_title' => ['nullable', 'string', 'max:'.RibbonPicks::TITLE_MAX],
            'store_picks_title_en' => ['nullable', 'string', 'max:'.RibbonPicks::TITLE_MAX],
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
            'store_tagline_en' => ['nullable', 'string', 'max:60'],

            /*
             * ورأسُ المتجر (انظر `Store\StoreHeader`): سطرٌ لكلّ لغة، ومحاذاةٌ
             * من ثلاثٍ مغلقة — لا قيمةُ CSS حرّة تبلغ الصفحة. والاختصاراتُ
             * تُقرأ بصرامةٍ أدناه.
             */
            'store_announcement_ar' => ['nullable', 'string', 'max:'.StoreHeader::ANNOUNCEMENT_MAX],
            'store_announcement_en' => ['nullable', 'string', 'max:'.StoreHeader::ANNOUNCEMENT_MAX],
            'store_announcement_align' => ['nullable', Rule::in(StoreHeader::ALIGNS)],
            'store_shop_nav_categories' => ['nullable', 'string', 'max:200'],
            // ومجموعاتُ المتجر نصُّ JSON — يُقرأ بصرامةٍ أدناه (`StoreCollections::validated`)
            'store_collections' => ['nullable', 'string', 'max:'.StoreCollections::RAW_MAX],
            // وشريطُها المصوّر نصُّ JSON — يُقرأ بصرامةٍ أدناه (`StoreVisualBar::validated`)
            'store_visual_bar' => ['nullable', 'string', 'max:'.StoreVisualBar::RAW_MAX],
            // وترتيبُ أزرار صفّ المتجر كلِّها — رموزٌ تُقرأ بصرامةٍ أدناه (`StoreHeader::validatedRow`)
            'store_shop_row' => ['nullable', 'string', 'max:2000'],
            // والقائمةُ الرئيسيّة بترتيب صاحبها وروابطه — `StoreNav::validatedMenu` أدناه
            'store_menu' => ['nullable', 'string', 'max:'.StoreNav::MENU_RAW_MAX],
            // والصفحاتُ المخصّصة — `StoreCustomPages::validated` أدناه
            'store_custom_pages' => ['nullable', 'string', 'max:'.StoreCustomPages::RAW_MAX],
            // وأعمدةُ التذييل وسطرُه — `StoreFooter::validated` أدناه
            'store_footer' => ['nullable', 'string', 'max:'.StoreFooter::RAW_MAX],
            /*
             * وطريقةُ عرض الصور — «كاملة» أو «ملء الإطار»، قيمتان مغلقتان تصيران
             * قاعدةَ CSS، فلا نصَّ حرٌّ يبلغ `style`. والفراغُ «ملء الإطار» كما كان.
             */
            'store_catalog_fit' => ['nullable', Rule::in(StoreMedia::FITS)],
            'store_hero_image_fit' => ['nullable', Rule::in(StoreMedia::FITS)],
            'store_banner_image_fit' => ['nullable', Rule::in(StoreMedia::FITS)],
            'store_block_image_fit' => ['nullable', Rule::in(StoreMedia::FITS)],

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
            'store_seo_title_en' => ['nullable', 'string', 'max:'.StoreSeo::TITLE_MAX],
            'store_seo_desc_en' => ['nullable', 'string', 'max:'.StoreSeo::DESC_MAX],
            'store_seo_index' => ['sometimes', 'boolean'],
        ]);

        /*
         * ═══ و«وصل حديثًا» اليدويّ — لمن في قائمته وحده ═══
         *
         * يُسأل قبل أن يُكتب شيء، ولو كان في الحمولة غيرُه: من ليس في
         * `storefront.ribbon_curated_new_arrivals_businesses` يُردّ بـ403،
         * والشاشةُ التي لا تعرض المقبضَ لا تحرس الباب. ومن فيها تُقرأ
         * قائمتُه بصرامة: أصنافُ متجره المعروضةُ وحدها، أربعةٌ لا أكثر.
         *
         * و`exists` لا `filled` كما في `store_fulfil` أعلاه: تفريغُ القائمة
         * سؤالٌ يُجاب، لا مفتاحٌ يُسقَط بصمت.
         */
        if ($request->exists('store_new_arrivals_mode') || $request->exists('store_new_arrivals')) {
            $data = array_merge($data, NewArrivals::validated($this->bid(), $request, StoreContent::draft($this->bid())));
        }

        /*
         * ═══ وترتيبُ المنتجات في الرفّ — لمن في قائمته وحده ═══
         *
         * من ليس في `storefront.ribbon_shelf_order_businesses` يُردّ بـ403
         * قبل أن يُكتب شيء. ومن فيها تُقرأ قوائمُه بصرامة: أصنافُ متجره وفئاتُه
         * وحدها، وقائمةُ الفئة لأصنافها. انظر `Store\ShelfOrder`.
         */
        if ($request->exists(ShelfOrder::KEY)) {
            $data[ShelfOrder::KEY] = ShelfOrder::validated($this->bid(), $request->input(ShelfOrder::KEY));
        }

        /*
         * ═══ و«اختيارات RIBBON» — لمن في قائمتها وحده ═══
         *
         * أيُّ مفاتيحها أُرسل يسأل الإذن (403) قبل أن يُكتب شيء، ولو كان
         * العنوانَ وحده. والقائمةُ تُقرأ بصرامة: أصنافُ متجره المعروضة،
         * ثمانيةٌ لا أكثر. انظر `Store\RibbonPicks`.
         */
        if (collect(RibbonPicks::KEYS)->contains(fn ($key) => $request->exists($key))) {
            $data = array_merge($data, RibbonPicks::validated($this->bid(), $request));
        }

        /*
         * ═══ و«أضف مع طلبك» بيده — لمن له القسم وحده ═══
         *
         * من ليس في `storefront.ribbon_product_upsells` يُردّ بـ403. والفراغُ
         * يُحفظ فارغًا: يعود القسمُ إلى «الاضافات» كما كان.
         */
        if ($request->exists(RibbonUpsells::KEY)) {
            $data[RibbonUpsells::KEY] = RibbonUpsells::validated($this->bid(), $request);
        }

        /*
         * ═══ وإيصالُ الدفع للزبون — لمن يضبط الموقع ═══
         *
         * مقبضُه في شاشة «المتجر والطلبات» وهي تحت `WEBSITE_CONFIGURE`، وهذا
         * البابُ تحت قسم «التسويق» وحده. فيُسأل هنا أيضًا: الشاشةُ التي لا
         * تُفتح لا تحرس بابًا يُرسَل إليه بيد. انظر `Store\PaidReceipt`.
         */
        if ($request->exists(PaidReceipt::KEY)) {
            abort_unless((bool) $request->user()?->may(Permissions::WEBSITE_CONFIGURE), 403);
        }

        /*
         * ═══ واختصاراتُ المتجر — فئاتُه وحده ═══
         *
         * معرّفاتٌ موجبةٌ فريدة، ستٌّ لا أكثر، وكلُّها من فئات متجره —
         * فلا يُحفظ معرّفُ فئةِ غيره ولو أرسله بيده. و`exists` لا `filled`:
         * تفريغُ القائمة سؤالٌ يُجاب، لا مفتاحٌ يُسقَط بصمت.
         */
        if ($request->exists(StoreHeader::SHORTCUTS)) {
            $data[StoreHeader::SHORTCUTS] = StoreHeader::validatedShortcuts($this->bid(), $request);
        }

        /*
         * ═══ ومجموعاتُ المتجر — لمن يلبس واجهةً خاصّة، وأصنافُه وصورُه وحده ═══
         *
         * لا تقرؤها صفحةُ المتجر البسيطة، فلا تُحفظ لمن لا واجهةَ له: الشاشةُ
         * التي لا تعرضها لا تحرس البابَ. وكلُّ صنفٍ من متجره المعروض، وكلُّ
         * صورةٍ من مجلّده، والرابطُ المختصرُ فريدٌ في قائمته — انظر
         * `Store\StoreCollections::validated`. و`exists` لا `filled`: تفريغُها
         * سؤالٌ يُجاب لا مفتاحٌ يُسقَط بصمت.
         */
        if ($request->exists(StoreCollections::KEY)) {
            abort_if($business->storefrontTheme() === null, 403);
            $data[StoreCollections::KEY] = StoreCollections::validated($this->bid(), $request->input(StoreCollections::KEY));
        }

        /*
         * ═══ والشريطُ المصوّر — وجهاتُه من متجره وحده ═══
         *
         * كلُّ بطاقةٍ تفتح مجموعةً أو فئةً أو صنفًا أو صفحةً منه، ولا رابطَ حرٌّ
         * يُكتب. والمجموعاتُ تُقاس على ما يُحفظ معها في الحفظة نفسِها، وإلّا على
         * المسوّدة — فبطاقةٌ لمجموعةٍ أُنشئت للتوّ تُقبل. انظر `Store\StoreVisualBar`.
         */
        /*
         * ═══ والصفحاتُ المخصّصة — صورُها من مجلّده، وزرُّها إلى وجهةٍ في متجره ═══
         *
         * والمجموعاتُ والصفحاتُ التي يُقاس عليها ما يُحفظ معها في الحفظة نفسِها،
         * وإلّا المسوّدة — فرابطٌ إلى صفحةٍ أُنشئت للتوّ يُقبل. انظر `Store\StoreCustomPages`.
         */
        $collectionsNow = fn () => StoreCollections::fromJson((string) (array_key_exists(StoreCollections::KEY, $data)
            ? $data[StoreCollections::KEY]
            : (StoreContent::draft($this->bid())[StoreCollections::KEY] ?? '')));

        if ($request->exists(StoreCustomPages::KEY)) {
            abort_if($business->storefrontTheme() === null, 403);
            $data[StoreCustomPages::KEY] = StoreCustomPages::validated($this->bid(), $request->input(StoreCustomPages::KEY), $collectionsNow());
        }

        $pagesNow = fn () => StoreCustomPages::fromJson((string) (array_key_exists(StoreCustomPages::KEY, $data)
            ? $data[StoreCustomPages::KEY]
            : (StoreContent::draft($this->bid())[StoreCustomPages::KEY] ?? '')));

        if ($request->exists(StoreVisualBar::KEY)) {
            abort_if($business->storefrontTheme() === null, 403);
            $data[StoreVisualBar::KEY] = StoreVisualBar::validated($this->bid(), $request->input(StoreVisualBar::KEY), $collectionsNow(), $pagesNow());
        }

        /*
         * ═══ والقائمةُ الرئيسيّة — الصفحاتُ الأربعُ فيها، وكلُّ رابطٍ إلى متجره ═══
         *
         * تُرتَّب الصفحاتُ ولا تُحذف، والروابطُ عشرةٌ إلى صفحةٍ أو مجموعةٍ أو
         * فئةٍ أو صنفٍ منه. انظر `Store\StoreNav::validatedMenu`.
         */
        if ($request->exists(StoreNav::MENU)) {
            abort_if($business->storefrontTheme() === null, 403);
            $data[StoreNav::MENU] = StoreNav::validatedMenu($this->bid(), $request->input(StoreNav::MENU), $collectionsNow(), $pagesNow());
        }

        // وترتيبُ صفّ المتجر — أزرارُه المعروفةُ وحدها، و«كل المنتجات» فيه دائمًا
        if ($request->exists(StoreHeader::ROW)) {
            abort_if($business->storefrontTheme() === null, 403);
            $data[StoreHeader::ROW] = StoreHeader::validatedRow($this->bid(), $request->input(StoreHeader::ROW), $collectionsNow());
        }

        /*
         * ═══ والتذييلُ — أعمدتُه وعناوينُها وما يظهر فيها ═══
         *
         * ومجموعاتُ عمود «تسوّق» من قائمته وحدها: ما يُحفظ معها، وإلّا المسوّدة.
         * انظر `Store\StoreFooter::validated`.
         */
        if ($request->exists(StoreFooter::KEY)) {
            abort_if($business->storefrontTheme() === null, 403);
            $data[StoreFooter::KEY] = StoreFooter::validated($request->input(StoreFooter::KEY), $collectionsNow());
        }

        if (filled($data['store_instagram'] ?? null)) {
            $data['store_instagram'] = $this->instagramHandle((string) $data['store_instagram']);
        }

        // وطرقُ عرض الصور تقرؤها الواجهةُ الخاصّة وحدها
        foreach (['store_catalog_fit', 'store_hero_image_fit', 'store_banner_image_fit', 'store_block_image_fit'] as $fitKey) {
            if ($request->exists($fitKey)) {
                abort_if($business->storefrontTheme() === null, 403);
            }
        }

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

        $this->orderDesk($request, $data);

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

        /*
         * ═══ وضبطُ الموقع فعلٌ باسمه — حين يتغيّر، لا حين يُرسَل ═══
         *
         * عنوانُ المتجر ونشرُه ولونُ صفحته ضبطٌ لا تشغيل (`Permissions::WEBSITE_CONFIGURE`):
         * من مُنح «التسويق» ليكتب ساعاتِ التوصيل ونصَّ الشكر لا يغيّر عنوانَ
         * متجره ولا يُطفئه. والشاشاتُ ترسل الحقولَ كلَّها في كلّ حفظ، فيُسأل
         * عمّا **تغيّر** منها لا عمّا وصل — فلا يُردّ موظّفٌ حفظ نصوصَه.
         */
        $site = MarketingSettings::group($this->bid(), 'website');
        $configures = $slug !== $business->site_slug
            || $published !== (($site['store_on'] ?? '') === '1')
            || ($request->exists('store_theme') && (string) $request->input('store_theme') !== (string) ($site['store_theme'] ?? ''));

        abort_if($configures && ! $request->user()?->may(Permissions::WEBSITE_CONFIGURE), 403);

        $business->forceFill(['site_slug' => $slug])->save();

        /*
         * والاسمُ المحجوز يصير عنوانًا في جدول العناوين.
         *
         * ولولا هذا السطر لَكان الاسمُ يُحفظ في عمودٍ ولا يُعرف صاحبُ العنوان
         * حين يصل: القارئُ يسأل جدولَ العناوين وحده (انظر `Domains::resolve`)،
         * ومن كتب اسمه بعد الهجرة لا صفَّ له فيه.
         */
        Domains::sync($business->refresh());

        foreach (['store_on', 'store_show_prices', 'store_allow_orders', 'store_pay_cod', 'store_pay_transfer', 'store_paid_receipt', 'store_gift_card', 'store_block_on', 'store_seo_index',
            OrderWindow::ON, OrderWindow::MESSAGE_ON, StoreTerms::ON, DeliverySchedule::ON, StoreBranches::CHOICE] as $flag) {
            if (array_key_exists($flag, $data)) {
                $data[$flag] = $request->boolean($flag) ? '1' : '0';
            }
        }

        /*
         * والشروطُ نسخةٌ تُكتب لا إعدادٌ يُستبدل — وقبل المفتاح: من رفعه مع نصٍّ
         * جديد يُشترط على زبونه النصُّ الجديد لا القديم. ونصٌّ لم يتغيّر لا
         * يكتب نسخة (`StoreTerms::revise`).
         */
        if ($request->exists(StoreTerms::TEXT) || $request->exists(StoreTerms::TEXT_EN)) {
            $current = StoreTerms::current($this->bid());
            StoreTerms::revise(
                $this->bid(),
                $request->exists(StoreTerms::TEXT) ? (string) $request->input(StoreTerms::TEXT) : $current?->body_ar,
                $request->exists(StoreTerms::TEXT_EN) ? (string) $request->input(StoreTerms::TEXT_EN) : $current?->body_en,
                auth()->id(),
            );
        }
        unset($data[StoreTerms::TEXT], $data[StoreTerms::TEXT_EN]);

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

    /**
     * استقبالُ الطلبات والشروطُ ومواعيدُ التسليم — تُفحص قبل أن يُكتب شيء.
     *
     * ═══ والسؤالُ عمّا سيكون لا عمّا وصل ═══
     *
     * الشاشةُ ترسل أقسامَها كلَّها، لكنّ بابًا آخر قد يرسل مفتاحًا وحدَه. فما لم
     * يصل يُقرأ ممّا هو محفوظ: من رفع الجدولةَ وحدَها يُسأل عن فتراتٍ محفوظة،
     * ومن رفع الشروطَ وحدَها يُسأل عن نصٍّ مكتوب. فلا يُفتح شيءٌ ناقص من أيّ باب.
     *
     * @param  array<string, mixed>  $data  المنقّى — يُكتب فيه الجدولان بصيغتهما الواحدة
     */
    private function orderDesk(Request $request, array &$data): void
    {
        $bid = $this->bid();
        $saved = MarketingSettings::group($bid, 'website');
        $on = fn (string $key) => $request->exists($key) ? $request->boolean($key) : ($saved[$key] ?? '0') === '1';

        /* ═══ جدولُ الاستقبال ═══ */
        if ($request->exists(OrderWindow::KEY)) {
            $data[OrderWindow::KEY] = OrderWindow::validated($request->input(OrderWindow::KEY));
        }

        // جدولٌ مفتوحٌ بلا فترةٍ متجرٌ مغلقٌ أبدًا — يُقال لصاحبه قبل أن يكتشفه زبون
        if ($on(OrderWindow::ON) && ! OrderWindow::hasWindows((string) ($data[OrderWindow::KEY] ?? $saved[OrderWindow::KEY] ?? ''))) {
            throw ValidationException::withMessages([
                OrderWindow::KEY => __('أضف فترة استقبالٍ واحدةً على الأقل، أو أطفئ الجدولة.'),
            ]);
        }

        /* ═══ رسالةُ الإغلاق بلغتيها ═══ */
        foreach ([OrderWindow::MESSAGE, OrderWindow::MESSAGE_EN] as $key) {
            if ($request->exists($key)) {
                $data[$key] = StoreTerms::clean($request->input($key));
                OrderWindow::assertMessage($key, $data[$key]);
            }
        }

        if ($on(OrderWindow::MESSAGE_ON)) {
            $text = fn (string $key) => trim((string) ($data[$key] ?? $saved[$key] ?? ''));

            // ولغةٌ بلا رسالة تقع على الأخرى أو على نصّ النظام — فتُطلب الاثنتان
            $missing = array_filter([
                OrderWindow::MESSAGE => $text(OrderWindow::MESSAGE) === '' ? __('اكتب رسالة الإغلاق بالعربية.') : null,
                OrderWindow::MESSAGE_EN => $text(OrderWindow::MESSAGE_EN) === '' ? __('اكتب رسالة الإغلاق بالإنجليزية.') : null,
            ]);

            if ($missing !== []) {
                throw ValidationException::withMessages($missing);
            }
        }

        /* ═══ الشروطُ والأحكام ═══ */
        if ($on(StoreTerms::ON)) {
            $current = StoreTerms::current($bid);
            $text = fn (string $key, ?string $saved) => StoreTerms::clean($request->exists($key) ? (string) $request->input($key) : $saved);

            // لا تُشترط موافقةٌ على شروطٍ لا يقرؤها زبونُ إحدى اللغتين
            $missing = array_filter([
                StoreTerms::TEXT => $text(StoreTerms::TEXT, $current?->body_ar) === '' ? __('اكتب الشروط والأحكام بالعربية قبل اشتراط الموافقة عليها.') : null,
                StoreTerms::TEXT_EN => $text(StoreTerms::TEXT_EN, $current?->body_en) === '' ? __('اكتب الشروط والأحكام بالإنجليزية قبل اشتراط الموافقة عليها.') : null,
            ]);

            if ($missing !== []) {
                throw ValidationException::withMessages($missing);
            }
        }

        /* ═══ جدولُ أيّام التسليم وفتراتِه ═══ */
        if ($request->exists(DeliverySchedule::KEY)) {
            $data[DeliverySchedule::KEY] = DeliverySchedule::validated($request->input(DeliverySchedule::KEY));
        }

        // ورسالةُ «لا مواعيد للتوصيل» بأسطرها كما كُتبت — وكلُّ لغةٍ وحدها، وفارغُها نصُّ النظام
        foreach ([DeliverySchedule::UNAVAILABLE, DeliverySchedule::UNAVAILABLE_EN] as $key) {
            if ($request->exists($key)) {
                $data[$key] = StoreTerms::clean($request->input($key));
            }
        }

        if ($on(DeliverySchedule::ON)) {
            DeliverySchedule::assertUsable(
                (string) ($data[DeliverySchedule::KEY] ?? $saved[DeliverySchedule::KEY] ?? ''),
                isset($data['store_fulfil'])
                    ? array_values(array_intersect(FlowerOrder::FULFILLMENT, explode(',', (string) $data['store_fulfil'])))
                    : CheckoutFields::fulfilments($bid),
            );
        }
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
        $max = (int) floor(StoreImageUpload::maxBytes() / 1024);
        $mb = rtrim(rtrim(number_format($max / 1024, 1, '.', ''), '0'), '.');

        /*
         * والنوعُ لا يُسأل هنا: قاعدةُ `image` تقرأ الامتداد ونوعَ MIME وتردّ HEIC
         * قبل أن يُقال لصاحبه كيف يُرسلها. فالملفُّ يُقبل ملفًّا، ويُقرأ نوعُه من
         * محتواه في `StoreImageUpload::store` — وهو أشدُّ من القاعدة لا أرخى.
         */
        $request->validate([
            'image' => ['required', 'file', 'max:'.$max],
        ], [
            'image.required' => __('اختر صورة لترفعها.'),
            'image.uploaded' => __('تعذّر رفع الصورة — أقصى حجمٍ :mb ميغابايت.', ['mb' => $mb]),
            'image.file' => __('تعذّر رفع الصورة — أقصى حجمٍ :mb ميغابايت.', ['mb' => $mb]),
            'image.max' => __('أقصى حجمٍ للصورة :mb ميغابايت.', ['mb' => $mb]),
        ]);

        // في مجلّد النشاط لا في مجلّدٍ عامّ — فنسخُ متجرٍ أو حذفُه يعرف ما يخصّه
        $saved = StoreImageUpload::store($this->bid(), $request->file('image'));

        // والمحرّرُ يرفع بطلبٍ وحده (`fetch`) لا بزيارة — فلا يقطع حفظًا جاريًا ولا يقطعه حفظ
        if ($request->expectsJson()) {
            return response()->json(['url' => $saved['url'], 'meta' => ['w' => $saved['w'], 'h' => $saved['h']]]);
        }

        return back()
            ->with('uploaded', $saved['url'])
            // وأبعادُها — يقرؤها المحرّرُ فيُري الصورةَ بنسبتها قبل أن تُحفظ
            ->with('uploaded_meta', ['w' => $saved['w'], 'h' => $saved['h']]);
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
