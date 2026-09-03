<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    /**
     * ما تُغيّره هذه الشاشة — بالاسم وبقاعدةٍ لكلٍّ منه.
     *
     * كان المتحكّم يأخذ كل مفتاحٍ في الطلب ويكتبه في جدول الإعدادات العامّ
     * بلا قائمةٍ ولا تحقّق. فحرفٌ زائد في اسم حقلٍ يصنع مفتاحًا ميتًا لا
     * يقرؤه شيء ولا يشكو منه أحد، و«١٤ يوم» في مدّة التجربة تصير صفرًا
     * صامتًا — فيُضاف تاجرٌ بتجربةٍ منتهيةٍ قبل أن تبدأ.
     *
     * @var array<string, array<int, string>>
     */
    private const KEYS = [
        'app_name' => ['nullable', 'string', 'max:100'],
        'locale' => ['nullable', 'in:ar,en'],
        'maintenance_mode' => ['nullable', 'boolean'],

        'company' => ['nullable', 'string', 'max:150'],
        'official_email' => ['nullable', 'email', 'max:150'],
        'phone' => ['nullable', 'string', 'max:50'],
        'website' => ['nullable', 'string', 'max:200'],

        'trial_days' => ['nullable', 'integer', 'min:0', 'max:365'],
        'grace_days' => ['nullable', 'integer', 'min:0', 'max:365'],
        'default_plan' => ['nullable', 'string', 'max:100'],
        'auto_suspend' => ['nullable', 'boolean'],

        'vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        'tax_mode' => ['nullable', 'in:inclusive,exclusive'],

        'from_address' => ['nullable', 'email', 'max:150'],
        'from_name' => ['nullable', 'string', 'max:100'],

        /*
         * واتساب — ثلاثة مقابض تحكم المنصّة كلّها.
         *
         * والحدّ الافتراضي رقمٌ واحد يُقرأ لكلّ متجرٍ لا تخصيص له: تعديله
         * هنا يسري على مئةٍ دفعةً واحدة، ولو نُسخ في صفوفهم لَما سرى على
         * أحد.
         */
        'whatsapp_enabled' => ['nullable', 'boolean'],
        'whatsapp_shared_enabled' => ['nullable', 'boolean'],
        'whatsapp_shared_default_monthly_limit' => ['nullable', 'integer', 'min:-1', 'max:1000000'],
    ];

    public function update(Request $request)
    {
        $data = $request->validate(self::KEYS, [], [
            'trial_days' => __('مدة الفترة التجريبية'),
            'grace_days' => __('مهلة السماح'),
            'vat_rate' => __('نسبة الضريبة'),
            'official_email' => __('البريد الرسمي'),
            'from_address' => __('بريد المُرسِل'),
        ]);

        /*
         * والباقة الافتراضية باسمٍ لا يطابق باقةً قائمة تُرفض هنا لا تُهمَل
         * لاحقًا: كانت تُحفظ ثم تُقرأ عند إضافة متجر فلا تُطابق شيئًا، فيُضاف
         * المتجر بلا باقة — بلا سعرٍ ولا فاتورة ولا سقف، ولا رسالةَ خطأ.
         */
        if (filled($data['default_plan'] ?? null)
            && ! \App\Models\Plan::where('name', trim($data['default_plan']))->exists()) {
            return back()->withErrors([
                'default_plan' => __('لا باقة بهذا الاسم — اكتبه كما هو في شاشة الباقات.'),
            ]);
        }

        foreach ($data as $key => $value) {
            if (is_array($value)) { $value = implode(',', $value); }
            // المفاتيح المنطقية تُخزَّن '1'/'0' لا true/false: القراءة تقارن بالنص،
            // و false يُكتب سلسلة فارغة فتُقرأ لاحقًا على أنها «مفعّل».
            if (is_bool($value)) { $value = $value ? '1' : '0'; }
            Setting::updateOrCreate(
                ['business_id' => null, 'key' => $key],
                ['value' => $value]
            );
        }

        \App\Support\Activity::log('settings', 'حدّث إعدادات المنصة');

        return back()->with('toast', ['msg' => __('تم حفظ إعدادات المنصة بنجاح'), 'type' => 'success']);
    }

    /**
     * تجربة البريد — تصدُق أو تسكت.
     *
     * كانت تنجح دائمًا: المرسِل `log` يكتب الرسالة في ملفّ ولا يخرجها، ثم
     * يقول التوست «تم الإرسال». فيطمئنّ المشغّل ولا يصل تنبيهُ اشتراكٍ ولا
     * رابطُ استعادة كلمة سرّ لأحد. والفشل الصريح هنا هو الفائدة كلّها.
     */
    public function testEmail(Request $request)
    {
        $status = \App\Support\PlatformConfig::mailStatus();
        if (! $status['delivers']) {
            return back()->with('toast', [
                'msg' => __('البريد غير مفعّل على الخادم (المرسِل: :mailer) — لن تخرج أي رسالة.', ['mailer' => $status['mailer']]),
                'type' => 'error',
            ]);
        }

        $to = $request->input('to', auth()->user()->email);
        try {
            \Illuminate\Support\Facades\Mail::raw(__('هذه رسالة اختبار من نظام Abad POS. إذا وصلتك فإن إعدادات البريد تعمل بنجاح.'), function ($m) use ($to) {
                $m->to($to)->subject(__('اختبار البريد — Abad POS'));
            });

            return back()->with('toast', ['msg' => __('تم إرسال بريد تجريبي إلى :email', ['email' => $to]), 'type' => 'success']);
        } catch (\Throwable $e) {
            report($e);

            return back()->with('toast', ['msg' => __('تعذّر إرسال البريد التجريبي'), 'type' => 'error']);
        }
    }

    /**
     * مفتاح خرائط Google للمنصّة — يُحفظ وحده، معمًّى، ولا يعود إلى الشاشة.
     *
     * ولا يمرّ في `KEYS` مع بقيّة الإعدادات: تلك تُكتب خامًا وتُقرأ في
     * `platformSettings` فتصل المتصفّح. وهذا مفتاحٌ تُحتسب عليه فاتورةُ
     * نداءات كلّ متاجر المنصّة — ظهورُه في حمولةِ صفحةٍ يعني أنّ من فتح
     * أدوات المتصفّح أخذه.
     *
     * وهو ما يجعل «ربط مع أبعاد» صادقًا في خرائط Google: بلا مفتاحٍ هنا،
     * كلُّ تاجرٍ عليه أن يفتح حسابًا في Google Cloud بنفسه.
     */
    public function googleKey(Request $request)
    {
        $request->validate(['google_places_key' => ['nullable', 'string', 'max:255']]);

        $key = trim((string) $request->input('google_places_key'));

        // وحقلٌ فارغٌ لا يمحو: المحو يُطلب بزرّه — انظر Marketing\MarketingController::saveGoogleKey
        if ($key === '') {
            return back()->withErrors([
                'google_places_key' => __('الصق المفتاح، أو اضغط «حذف المفتاح» لإزالته.'),
            ]);
        }

        \App\Support\GoogleReviews::storePlatformKey($key);
        // ولا يُكتب المفتاح في السجلّ
        \App\Support\Activity::log('updated', 'حدّث مفتاح خرائط Google للمنصّة');

        return back()->with('toast', ['msg' => __('حُفظ المفتاح'), 'type' => 'success']);
    }

    public function forgetGoogleKey()
    {
        \App\Support\GoogleReviews::storePlatformKey(null);
        \App\Support\Activity::log('updated', 'حذف مفتاح خرائط Google للمنصّة');

        return back()->with('toast', ['msg' => __('حُذف المفتاح'), 'type' => 'warning']);
    }
}
