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

    public function update(Request $request)
    {
        $data = $request->validate(self::KEYS, [
            'inv_prefix.not_regex' => __('لا تصلح الرموز % و _ و \\ في بادئة رقم الفاتورة'),
            'currency.size' => __('رمز العملة ثلاثة أحرف مثل OMR'),
            'vat_rate.max' => __('نسبة الضريبة مئة بالمئة على الأكثر'),
        ], [
            'shop_name' => __('اسم المتجر'),
            'vat_rate' => __('نسبة الضريبة'),
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

        $business = \App\Models\Business::findOrFail(Demo::bid());

        if ($request->hasFile('logo')) {
            $business->logo = $request->file('logo')->store('logos', 'public');
        } elseif ($request->boolean('remove')) {
            $business->logo = null;
        } else {
            return back();
        }

        $business->save();
        \App\Support\Activity::log('settings', $business->logo ? 'حدّث شعار المتجر' : 'حذف شعار المتجر');

        return back()->with('toast', [
            'msg' => $business->logo ? __('حُفظ الشعار') : __('حُذف الشعار'),
            'type' => 'success',
        ]);
    }
}
