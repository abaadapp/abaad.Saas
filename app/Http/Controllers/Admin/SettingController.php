<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Currency;
use App\Models\Setting;
use App\Support\Activity;
use App\Support\Demo;
use App\Support\InvoiceBranding;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;

class SettingController extends Controller
{
    /**
     * حقول «بيانات النشاط» تسكن جدول businesses لا جدول الإعدادات.
     *
     * النموذج كان يقرأها من businesses ويكتبها كصفوف settings، فتختفي عند
     * إعادة التحميل بينما يقول التنبيه «تم الحفظ بنجاح» — أسوأ من عطل ظاهر
     * لأن التاجر يظنّ بياناته محفوظة. المفتاح هنا اسم الحقل في النموذج
     * والقيمة اسم العمود في الجدول.
     */
    private const PROFILE = [
        'shop_name' => 'name',
        'shop_name_en' => 'name_en',
        'phone' => 'phone',
        'email' => 'email',
        'address' => 'address',
    ];

    /**
     * ما تكتبه هذه الشاشة — بالاسم وبقاعدةٍ لكلٍّ منه.
     *
     * كان الحفظ حرَّ المفاتيح: يُؤخذ كلُّ ما في الطلب ويُكتب صفًّا في جدول
     * الإعدادات. فالمقبض الذي يُسمّى بحرفٍ زائد يُحفظ في مفتاحٍ لا يقرؤه
     * أحد — يتحرّك في الشاشة، ويقول التنبيه «تم الحفظ»، ولا يتغيّر شيء في
     * الطباعة ولا في البيع. وهذا الصنف من العطب لا يُكتشف بالتجربة لأن كل
     * ما يُرى منه سليم؛ إنما يُكتشف حين يشتكي التاجر بعد شهر.
     *
     * وتُقرأ القائمة من موضعٍ واحد فتُقارَن بنموذج الشاشة: ما لا اسم له هنا
     * لا يُحفظ، وما لا قاعدة له لا يمرّ.
     *
     * وما تحت `PROFILE` يسكن جدول businesses لا هذا الجدول.
     *
     * ومع كلّ مفتاحٍ اسمُه بالعربية وقسمُه في الشاشة — لا قاعدتُه وحدها.
     * الاسمُ كي لا تقول الرسالةُ العربية «حقل vat_number»، والقسمُ كي يُقال
     * لمن رُدَّ حفظُه أين يُصلِح: النموذج واحدٌ يرسل حقولَه كلَّها من أيّ
     * قسم، فالخطأ قد يقع على حقلٍ لا يراه (انظر `refusal`).
     *
     * @var array<string, array{section: string, label: string, rules: array<int, mixed>}>
     */
    private const KEYS = [
        // بيانات النشاط — البريد وسيلةُ تواصلٍ تُعرض للناس فتُصحَّح عند الإدخال
        'shop_name' => ['section' => 'business', 'label' => 'اسم المتجر',
            'rules' => ['sometimes', 'required', 'string', 'max:120']],
        /*
         * والاسمُ الإنجليزيُّ اختياريّ ولا يُشتقّ.
         *
         * يُطبع على ورقٍ لغتُه إنجليزيّة (انظر `Paper::brand`)، ويُقرأ في
         * شهادة السجلّ التجاريّ حرفًا حرفًا. وترجمةُ اسمٍ قانونيّ تلقائيًّا
         * تضع على فاتورةٍ ضريبيّة اسمًا لا تعرفه الجهة.
         */
        'shop_name_en' => ['section' => 'business', 'label' => 'اسم المتجر بالإنجليزية',
            'rules' => ['sometimes', 'nullable', 'string', 'max:120']],
        /*
         * ورقمُ السجلّ التجاريّ في «بيانات النشاط» لا مع الرقم الضريبيّ.
         *
         * القُربُ في الورقة لا يعني القُربَ في الشاشة: الرقمان يقعان سطرين
         * متجاورين في كتلة الهويّة (انظر `partials/identity`)، لكنّ الضريبيَّ
         * يسكن قسمَ «الضرائب» لأنّه معلّقٌ بمقبض التسجيل ونسبتِه — والسجلُّ
         * لا يعلّقه شيء: كلُّ نشاطٍ مرخَّصٍ يحمله مسجَّلًا كان أو غيرَ
         * مسجَّل.
         *
         * ووصفُ هذا القسم يقول ما يكفي: «ما يُطبع في رأس فواتيرك
         * وإيصالاتك». ومن يبحث عن سجلّه التجاريّ يفتح بيانات نشاطه لا
         * إعداداتِ ضريبته.
         */
        'cr_number' => ['section' => 'business', 'label' => 'رقم السجل التجاري',
            'rules' => ['sometimes', 'nullable', 'string', 'max:30']],
        'email' => ['section' => 'business', 'label' => 'البريد الإلكتروني',
            'rules' => ['sometimes', 'nullable', 'email', 'max:120']],
        'phone' => ['section' => 'business', 'label' => 'رقم الهاتف',
            'rules' => ['sometimes', 'nullable', 'string', 'max:40']],
        'address' => ['section' => 'business', 'label' => 'العنوان',
            'rules' => ['sometimes', 'nullable', 'string', 'max:500']],

        // الضريبة
        'vat_enabled' => ['section' => 'finance', 'label' => 'تفعيل ضريبة القيمة المضافة',
            'rules' => ['sometimes', 'boolean']],
        'vat_rate' => ['section' => 'finance', 'label' => 'نسبة الضريبة',
            'rules' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100']],
        'vat_number' => ['section' => 'finance', 'label' => 'الرقم الضريبي',
            'rules' => ['sometimes', 'nullable', 'string', 'max:30']],
        /*
         * آخرُ يومٍ قُدِّم إقرارُه — وما قبله لا تُلغى فواتيرُه.
         *
         * ويُقبل تاريخًا لا فترة: «الربع الأوّل» يعني شهورًا مختلفة عند من
         * تبدأ سنتُه المالية في يوليو. والفراغُ يعني «لم أقدّم بعد».
         *
         * ولا يُقبل تاريخُ الغد وما بعده: قفلُ فترةٍ لم تنتهِ يمنع إلغاءَ
         * فاتورةِ اليوم — وهي أولى ما يُلغى.
         */
        'vat_filed_through' => ['section' => 'finance', 'label' => 'آخر إقرار قُدِّم',
            'rules' => ['sometimes', 'nullable', 'date', 'before_or_equal:today']],
        'tax_mode' => ['section' => 'finance', 'label' => 'طريقة الاحتساب',
            'rules' => ['sometimes', 'nullable', 'in:inclusive,exclusive']],

        // العملة وعرضها
        'currency' => ['section' => 'finance', 'label' => 'العملة',
            'rules' => ['sometimes', 'nullable', 'string', 'size:3', 'alpha']],
        'decimals' => ['section' => 'finance', 'label' => 'عدد الخانات العشرية',
            'rules' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:4']],
        'symbol_pos' => ['section' => 'finance', 'label' => 'موضع الرمز',
            'rules' => ['sometimes', 'nullable', 'in:before,after']],

        // وسائل الدفع في نقطة البيع
        'pay_cash' => ['section' => 'finance', 'label' => 'الدفع نقدًا',
            'rules' => ['sometimes', 'boolean']],
        'pay_card' => ['section' => 'finance', 'label' => 'الدفع بالبطاقة',
            'rules' => ['sometimes', 'boolean']],
        'pay_transfer' => ['section' => 'finance', 'label' => 'الدفع بالتحويل',
            'rules' => ['sometimes', 'boolean']],

        /*
         * الطلباتُ المخصَّصة — مقبضٌ يُطفئ بابًا لا يُخفي زرًّا.
         *
         * الإطفاءُ يمنع إنشاءَ طلبٍ جديد في الخادم، ولا يمسّ طلبًا بيع ولا
         * قالبًا كُتب ولا تقريرًا صدر — انظر `CustomArrangement::enabled`.
         */
        'custom_orders_enabled' => ['section' => 'custom-orders', 'label' => 'تفعيل الطلبات المخصصة',
            'rules' => ['sometimes', 'boolean']],

        /*
         * البادئة تدخل شرط LIKE عند توليد الرقم — و«%» فيها تجعل كل فاتورةٍ
         * مطابقةً فيقفز العدّاد. تُنقّى في PosController أيضًا، والمنع هنا أوضح.
         */
        'inv_prefix' => ['section' => 'templates', 'label' => 'بادئة رقم الفاتورة',
            'rules' => ['sometimes', 'nullable', 'string', 'max:12', 'not_regex:/[%_\\\\]/']],
        'inv_start' => ['section' => 'templates', 'label' => 'رقم البداية',
            'rules' => ['sometimes', 'nullable', 'integer', 'min:1']],

        /*
         * أيرى الموظّفُ أداءه في «حسابي»؟ — قرارُ صاحب المتجر.
         *
         * صفحةُ «حسابي» تعرض للموظّف راتبَه ومسيراته وما باعه هو. والراتب
         * حقُّه يقرؤه بلا إذن: رقمٌ في عقده، وسؤالُ صاحب المحلّ عنه شفاهةً
         * آخر الشهر ليس ميزةً في نظام.
         *
         * والأداء غيرُه: أرقامُ بيعٍ تُقاس عليها، ومحلٌّ يعرضها لكاشيره
         * يفتح بابًا لا يريده كلُّ صاحب محلّ — مقارنةً بين زملاء، أو
         * مساءلةً عن يومٍ ضعيف لم يكن سببُه الموظّف. فيُطفأ افتراضًا
         * ويُشعله من يريده.
         *
         * ولا يُوسَّع إلى الراتب: مفتاحٌ يُخفي راتبَ صاحبه يجعل الصفحةَ
         * فارغةً بلا سبب.
         */
        'staff_sees_performance' => ['section' => 'permissions', 'label' => 'إظهار أدائه للموظّف',
            'rules' => ['sometimes', 'boolean']],

        // التنبيهات
        'notify_new_order' => ['section' => 'notifications', 'label' => 'بريدٌ عند كل طلب جديد',
            'rules' => ['sometimes', 'boolean']],
        'notify_smart_alerts' => ['section' => 'notifications', 'label' => 'التنبيهات الذكية',
            'rules' => ['sometimes', 'boolean']],
        'notify_daily_summary' => ['section' => 'notifications', 'label' => 'ملخّص الأداء اليومي',
            'rules' => ['sometimes', 'boolean']],
        /*
         * ومفتاحٌ كان يُقرأ ولا يُكتب.
         *
         * `Demo::buildNotifications` تسأل عن `notify_dormant_customers` منذ
         * زمن — ولا مدخلَ له هنا ولا مقبضَ في الشاشة. فالقراءةُ تَعِد بخيارٍ
         * لا يملكه أحد: متجرٌ له ثلاثمئة زبونٍ راكد يمتلئ جرسُه بهم كلَّ يوم
         * ولا سبيل إلى إسكاتهم.
         */
        'notify_dormant_customers' => ['section' => 'notifications', 'label' => 'تنبيه العملاء الراكدين',
            'rules' => ['sometimes', 'boolean']],

        /*
         * لا ولاءَ ولا ورديةً هنا — وغيابُهما مقصود.
         *
         * الولاء كان يُحفظ من مسارين إلى المفاتيح نفسها: هذه الشاشة، وشاشةُ
         * «برنامج ولاء» في التسويق التي تُظهر معها الأعضاء والنقاط. فبقي
         * مالكٌ واحد — `MarketingController::saveLoyalty` — لأنّ المفتاح الذي
         * يكتبه اثنان يقول أحدهما غير ما يقول الآخر.
         *
         * والوردية رُفعت من نقطة البيع كلّها بطلب صاحب النظام، فلم يبقَ
         * لمفتاحيها — `shift_max_hours` و`require_open_shift` — قارئٌ واحد.
         * وصفّاهما قد يبقيان في `settings` من قبلُ ولا يقرؤهما شيء.
         */

        /*
         * ولا مفاتيحَ قوالبَ هنا: `tpl_*` و`paper` انتقلت إلى محرّرها.
         *
         * وكانت شاشةُ الإعدادات ترسلها مع كلّ حفظٍ من أيّ تبويب، فمن عدّل
         * ورقته في محرّرها ثمّ حفظ «بيانات النشاط» أعاد القيمَ التي قرأتها
         * الشاشة قبل تعديله — يُنسَخ القديم فوق الجديد بلا خطأ ولا رسالة،
         * ولا يُكتشف إلّا على ورقٍ أمام زبون. انظر `DocumentTemplates`.
         *
         * والصفوفُ المحفوظة باقيةٌ كما هي: الأسماء لم تتغيّر، وإنّما تغيّر
         * البابُ الذي يكتبها.
         */
    ];

    /**
     * أسماءُ الأقسام كما تعرضها بطاقاتُ الإعدادات.
     *
     * تُقال للتاجر حين يُردّ حفظُه: «الحقل في قسم كذا». ونسختُها الأولى في
     * `SettingsNav.tsx` — والحارسُ يقارن الاثنين، فاسمٌ يُبدَّل هناك ولا
     * يُبدَّل هنا يسقط الاختبار قبل أن يقرأ تاجرٌ اسمَ قسمٍ لا وجود له.
     *
     * @var array<string, string>
     */
    private const SECTIONS = [
        'business' => 'بيانات النشاط',
        'finance' => 'الضرائب والعملة والدفع',
        'templates' => 'قوالب الأوراق',
        'permissions' => 'صلاحيات الموظفين',
        'notifications' => 'الإشعارات',
        'custom-orders' => 'الطلبات المخصصة',
    ];

    /**
     * حقولُ كلّ قسمٍ باسمها — تقرؤها الشاشة كي ترسل ما تعدّله وحده.
     *
     * ═══ ولمَ تحتاجها ═══
     *
     * النموذج في الشاشة واحد، فكان كلُّ حفظٍ من أيّ قسمٍ يرسل الحقول كلَّها
     * كما قرأها عند فتح الصفحة. ومن فتح الإعدادات في نافذتين — أو تركها
     * مفتوحةً وعدّل من هاتفه — يحفظ اسم متجره فيُعيد معه نسبةَ الضريبة
     * وبادئةَ الفاتورة إلى ما كانت: يُنسَخ القديم فوق الجديد بلا خطأ ولا
     * رسالة. وهو العطبُ نفسه الذي رُفعت لأجله مفاتيحُ القوالب من هذا
     * النموذج، وبقي في الواحدٍ والعشرين الباقية.
     *
     * والقواعدُ كلُّها `sometimes` أصلًا: الحفظُ الجزئيّ مقصودٌ من أوّل يوم.
     *
     * @return array<string, array<int, string>>
     */
    public static function fieldsBySection(): array
    {
        $out = [];

        foreach (self::KEYS as $key => $meta) {
            $out[$meta['section']][] = $key;
        }

        return $out;
    }

    /** @return array<string, array<int, mixed>> */
    private static function rules(): array
    {
        return array_map(fn (array $k) => $k['rules'], self::KEYS);
    }

    /** أسماءُ الحقول بالعربية — بلا هذه تقول الرسالة «حقل currency» */
    private static function labels(): array
    {
        return array_map(fn (array $k) => __($k['label']), self::KEYS);
    }

    /**
     * ما يُقال حين يُردّ الحفظ — ولمَ لا يكفي وسمُ الحقل وحده.
     *
     * ═══ العطب ═══
     *
     * الشاشة نموذجٌ واحد يُرسل حقولَه كلَّها من أيّ قسم: من يحفظ اسم متجره
     * يُرسل معه العملةَ ونسبةَ الضريبة وبادئةَ الفاتورة كما قرأها عند فتح
     * الصفحة. فإن كان في القاعدة صفٌّ قديمٌ لا تقبله القاعدةُ اليوم — عملةٌ
     * مكتوبة «ريال عماني» من أيّام الحفظ الحرّ مثلًا — رُدَّ الطلبُ كلُّه،
     * ووُسم الخطأُ على `currency`، وهو حقلٌ في قسم «المالية» لا يراه من يقف
     * في «بيانات النشاط».
     *
     * فيضغط «حفظ التغييرات» فلا يقع شيء: لا سطرَ أحمر، ولا تنبيهَ، ولا حفظ.
     * ويعيد الضغط. ولا شيءَ في الشاشة يقول له أين المشكلة — زرٌّ لا يفعل
     * شيئًا ولا يقول لماذا، وهو أسوأ من زرٍّ لا يوجد.
     *
     * فتُقال الثلاثة: أنّ شيئًا لم يُحفظ، وما الخطأ، وفي أيّ قسمٍ يُصلَح.
     */
    private function refusal(\Illuminate\Contracts\Validation\Validator $validator): string
    {
        $key = (string) array_key_first($validator->errors()->messages());
        $section = self::KEYS[$key]['section'] ?? null;

        return __('لم يُحفظ شيء — :why والحقلُ في قسم «:section».', [
            'why' => $validator->errors()->first($key),
            'section' => __(self::SECTIONS[$section] ?? ''),
        ]);
    }

    public function update(Request $request)
    {
        $validator = Validator::make($request->all(), self::rules(), [
            'inv_prefix.not_regex' => __('لا تصلح الرموز % و _ و \\ في بادئة رقم الفاتورة'),
            'currency.size' => __('رمز العملة ثلاثة أحرف مثل OMR'),
            'vat_rate.max' => __('نسبة الضريبة مئة بالمئة على الأكثر'),
            'vat_filed_through.before_or_equal' => __('لا يُقفل إقرارُ فترةٍ لم تنتهِ بعد — اختر تاريخًا مضى.'),
        ], self::labels());

        if ($validator->fails()) {
            return back()
                ->withErrors($validator)
                ->withInput()
                ->with('toast', ['msg' => $this->refusal($validator), 'type' => 'danger']);
        }

        $data = $validator->validated();

        $bid = auth()->user()->business_id ?? Demo::bid();

        $profile = [];
        foreach (self::PROFILE as $field => $column) {
            if (array_key_exists($field, $data)) {
                $profile[$column] = $data[$field];
            }
        }
        if ($profile) {
            Business::whereKey($bid)->update($profile);
        }

        foreach (Arr::except($data, array_keys(self::PROFILE)) as $key => $value) {
            /*
             * المنطقيّ يُخزَّن '1'/'0' صراحةً لا true/false.
             *
             * القراءة تقارن بالنصّ، وكتابةُ `false` تُترك لسائق القاعدة:
             * يكتبها بعضُهم '0' وبعضُهم سلسلةً فارغة — فمقبضٌ مطفأٌ على
             * قاعدةٍ يُقرأ مطفأً وعلى أخرى يُقرأ مفعّلًا. ولا يظهر ذلك إلا
             * بعد النقل.
             */
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }

            Setting::updateOrCreate(
                ['business_id' => $bid, 'key' => $key],
                ['value' => $value]
            );
        }

        if (array_key_exists('currency', $data)) {
            $this->alignBaseCurrency($bid, (string) $data['currency']);
        }

        Activity::log('settings', 'حدّث إعدادات النشاط');

        return back()->with('toast', ['msg' => __('تم حفظ الإعدادات بنجاح'), 'type' => 'success']);
    }

    /**
     * صفُّ العملة الأساسية يتبع المقبض — وإلّا كان المقبضُ زينة.
     *
     * ═══ العطب ═══
     *
     * قراءةُ العملة تسأل جدولَ `currencies` أوّلًا (انظر `Demo::baseCurrency`)
     * ولا تهبط إلى إعداد «العملة» إلّا حين لا صفَّ هناك. وللمتاجر المزروعة
     * — وكلِّ متجرٍ استُعيد من نسخة — صفٌّ أساسيّ موجود. فيبدّل صاحبُه
     * العملةَ في الإعدادات من ر.ع إلى د.إ، ويقرأ «تم حفظ الإعدادات بنجاح»،
     * وتُعيد الشاشةُ عرضَ «AED» لأنّها تقرأ الصفَّ المحفوظ في `settings` —
     * **وكلُّ مبلغٍ في النظام يبقى مكتوبًا بالريال**.
     *
     * وهذا أسوأ من مقبضٍ لا يعمل: الشاشةُ تشهد له أنّه عمل.
     *
     * فالكتابةُ تُبقي الاثنين على كلمةٍ واحدة. والسعرُ لا يُمسّ: صفُّ الأساس
     * سعرُه واحدٌ بحكم كونه الأساس، وما عداه يُنسب إليه.
     */
    private function alignBaseCurrency(int $bid, string $code): void
    {
        $code = strtoupper(trim($code));

        if (! preg_match('/^[A-Z]{3}$/', $code)) {
            return;
        }

        $base = Currency::where('business_id', $bid)->where('is_base', true)->first();

        if ($base === null || $base->code === $code) {
            return;
        }

        $base->update([
            'code' => $code,
            'symbol' => Demo::SYMBOLS[$code] ?? $code,
            'name' => Demo::SYMBOLS[$code] ?? $code,
        ]);

        // والذاكرة الساكنة قد تكون امتلأت بالعملة القديمة في هذا الطلب نفسه
        Demo::flushCurrency();
    }

    /**
     * شعار النشاط — يرفعه صاحبه لا مديرُ المنصّة وحده.
     *
     * كان العمود موجودًا والرفعُ في لوحة المنصّة فقط، بينما في إعدادات
     * التاجر مقبضٌ اسمه «شعار المتجر» وصفُه «يظهر فقط إن كان للنشاط شعار
     * محفوظ» — مقبضٌ يشترط ما لا سبيل لصاحبه إليه. فمن أراد شعاره على
     * فاتورته اتّصل بالدعم ليرفعه عنه.
     *
     * ومسارٌ مستقلّ لا حقلٌ في نموذج الإعدادات: الملفّ يحتاج
     * `multipart/form-data`، وجعلُ النموذج كلّه كذلك يرسل كل مقبضٍ نصًّا
     * فتنكسر مصادقة `boolean`.
     */
    public function logo(Request $request)
    {
        $request->validate([
            'logo' => ['nullable', 'image', 'max:2048'],
        ], [
            'logo.image' => __('الشعار صورة — PNG أو JPG أو WEBP'),
            'logo.max' => __('أقصى حجمٍ للشعار ٢ ميغابايت'),
        ]);

        $business = Business::findOrFail(Demo::bid());

        /*
         * والكتابةُ من مالكٍ واحد — `InvoiceBranding::storeLogo`.
         *
         * البابُ الثاني في «تخصيص التصميم» بشاشة الفاتورة، وقاعدةٌ تُكتب
         * في البابين تفترق: يُخزَّن هنا بمسارٍ ويُقرأ هناك بغيره.
         */
        if (! InvoiceBranding::storeLogo(
            $business,
            $request->file('logo'),
            $request->boolean('remove'),
        )) {
            return back();
        }
        Activity::log('settings', $business->logo ? 'حدّث شعار المتجر' : 'حذف شعار المتجر');

        return back()->with('toast', [
            'msg' => $business->logo ? __('حُفظ الشعار') : __('حُذف الشعار'),
            'type' => 'success',
        ]);
    }
}
