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
            Setting::updateOrCreate(
                ['business_id' => null, 'key' => $key],
                ['value' => $value]
            );
        }

        \App\Support\Activity::log('settings', 'حدّث إعدادات المنصة');

        return back()->with('toast', ['msg' => 'تم حفظ إعدادات المنصة بنجاح', 'type' => 'success']);
    }

    public function testEmail(Request $request)
    {
        $to = $request->input('to', auth()->user()->email);
        try {
            \Illuminate\Support\Facades\Mail::raw('هذه رسالة اختبار من نظام Abad POS. إذا وصلتك فإن إعدادات البريد تعمل بنجاح.', function ($m) use ($to) {
                $m->to($to)->subject('اختبار البريد — Abad POS');
            });

            return back()->with('toast', ['msg' => 'تم إرسال بريد تجريبي إلى ' . $to, 'type' => 'success']);
        } catch (\Throwable $e) {
            report($e);

            return back()->with('toast', ['msg' => 'تعذّر إرسال البريد التجريبي', 'type' => 'error']);
        }
    }
}
