<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    public function update(Request $request)
    {
        foreach ($request->except(['_token', '_method', 'tab']) as $key => $value) {
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
}
