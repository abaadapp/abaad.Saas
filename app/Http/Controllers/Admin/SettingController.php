<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\Demo;
use Illuminate\Http\Request;

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
     * @var array<string, array<int, mixed>>
     */
    private const KEYS = [
        // بيانات النشاط — البريد وسيلةُ تواصلٍ تُعرض للناس فتُصحَّح عند الإدخال
        'shop_name' => ['sometimes', 'required', 'string', 'max:120'],
        'email' => ['sometimes', 'nullable', 'email', 'max:120'],
        'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
        'address' => ['sometimes', 'nullable', 'string', 'max:500'],

        // الضريبة
        'vat_enabled' => ['sometimes', 'boolean'],
        'vat_rate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
        'vat_number' => ['sometimes', 'nullable', 'string', 'max:30'],
        'tax_mode' => ['sometimes', 'nullable', 'in:inclusive,exclusive'],

        // العملة وعرضها
        'currency' => ['sometimes', 'nullable', 'string', 'size:3', 'alpha'],
        'decimals' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:4'],
        'symbol_pos' => ['sometimes', 'nullable', 'in:before,after'],

        // وسائل الدفع في نقطة البيع
        'pay_cash' => ['sometimes', 'boolean'],
        'pay_card' => ['sometimes', 'boolean'],
        'pay_transfer' => ['sometimes', 'boolean'],

        /*
         * البادئة تدخل شرط LIKE عند توليد الرقم — و«%» فيها تجعل كل فاتورةٍ
         * مطابقةً فيقفز العدّاد. تُنقّى في PosController أيضًا، والمنع هنا أوضح.
         */
        'inv_prefix' => ['sometimes', 'nullable', 'string', 'max:12', 'not_regex:/[%_\\\\]/'],
        'inv_start' => ['sometimes', 'nullable', 'integer', 'min:1'],
        'paper' => ['sometimes', 'in:58mm,80mm,A4'],

        // التنبيهات
        'notify_new_order' => ['sometimes', 'boolean'],
        'notify_smart_alerts' => ['sometimes', 'boolean'],
        'notify_daily_summary' => ['sometimes', 'boolean'],

        /*
         * لا ولاءَ ولا ورديةً هنا — وغيابُهما مقصود.
         *
         * الولاء كان يُحفظ من مسارين إلى المفاتيح نفسها: هذه الشاشة، وشاشةُ
         * «برنامج ولاء» في التسويق التي تُظهر معها الأعضاء والنقاط. فبقي
         * مالكٌ واحد — `MarketingController::saveLoyalty` — لأنّ المفتاح الذي
         * يكتبه اثنان يقول أحدهما غير ما يقول الآخر.
         *
         * والوردية أُزيلت شاشتها بطلب صاحب النظام، فأُخرج مفتاحاها من القائمة
         * المغلقة: بابُ حفظٍ يقبل ما لا مقبض له يُملأ من خارج الشاشة وحدها.
         * و`Shifts` يبقى قارئًا لهما — `DEFAULT_MAX_HOURS` سقفُه، و
         * `require_open_shift` أُطفئ في ترحيلٍ مرافق فلا يحبس صندوقًا.
         */

        // قوالب الفواتير — تحكم الأوراق الثلاث
        'tpl_header' => ['sometimes', 'nullable', 'string', 'max:120'],
        'tpl_footer' => ['sometimes', 'nullable', 'string', 'max:500'],
        'tpl_font' => ['sometimes', 'in:صغير,عادي,كبير'],
        'tpl_show_logo' => ['sometimes', 'boolean'],
        'tpl_show_branch' => ['sometimes', 'boolean'],
        'tpl_show_employee' => ['sometimes', 'boolean'],
        'tpl_show_customer' => ['sometimes', 'boolean'],
        'tpl_show_datetime' => ['sometimes', 'boolean'],
        'tpl_show_items_count' => ['sometimes', 'boolean'],
        'tpl_show_vat_no' => ['sometimes', 'boolean'],
        'tpl_show_qr' => ['sometimes', 'boolean'],
    ];

    public function update(Request $request)
    {
        $data = $request->validate(self::KEYS, [
            'inv_prefix.not_regex' => __('لا تصلح الرموز % و _ و \\ في بادئة رقم الفاتورة'),
            'currency.size' => __('رمز العملة ثلاثة أحرف مثل OMR'),
            'shift_max_hours.max' => __('سقف الوردية اثنتان وسبعون ساعة على الأكثر'),
            'vat_rate.max' => __('نسبة الضريبة مئة بالمئة على الأكثر'),
        ], [
            'shop_name' => __('اسم المتجر'),
            'vat_rate' => __('نسبة الضريبة'),
            'shift_max_hours' => __('سقف ساعات الوردية'),
            'loyalty_earn_rate' => __('نقاط الولاء لكل ريال'),
        ]);

        $bid = auth()->user()->business_id ?? Demo::bid();

        $profile = [];
        foreach (self::PROFILE as $field => $column) {
            if (array_key_exists($field, $data)) {
                $profile[$column] = $data[$field];
            }
        }
        if ($profile) {
            \App\Models\Business::whereKey($bid)->update($profile);
        }

        foreach (\Illuminate\Support\Arr::except($data, array_keys(self::PROFILE)) as $key => $value) {
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

        \App\Support\Activity::log('settings', 'حدّث إعدادات النشاط');

        return back()->with('toast', ['msg' => __('تم حفظ الإعدادات بنجاح'), 'type' => 'success']);
    }
}
