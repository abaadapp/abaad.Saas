<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Middleware\SetLocale;
use App\Models\Setting;
use App\Support\Activity;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LanguageController extends Controller
{
    /**
     * تبديل اللغة قبل تسجيل الدخول.
     *
     * منفصل عن update لأن ذاك يكتب في إعدادات النشاط ويسجّل النشاط باسم
     * المستخدم — وكلاهما يفترض جلسة مصادَقة. الزائر ليس له نشاط يُحفظ فيه
     * تفضيله، فتكفيه الجلسة: SetLocale يقرأها أولًا قبل الإعدادات.
     */
    public function guest(Request $request)
    {
        $data = $request->validate([
            'locale' => ['required', Rule::in(SetLocale::SUPPORTED)],
        ]);

        session(['locale' => $data['locale']]);
        app()->setLocale($data['locale']);

        return back();
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'locale' => ['required', Rule::in(SetLocale::SUPPORTED)],
        ]);

        // تُحفظ في الجلسة (فورية) وفي الإعدادات (تبقى بعد الخروج).
        // مدير المنصة بلا business_id فتُحفظ لغته في إعدادات المنصة (business_id = null)
        // كما تُحفظ بقية إعداداتها؛ الرجوع إلى أول نشاط كان يكتب تفضيله في إعدادات تاجر.
        session(['locale' => $data['locale']]);
        Setting::updateOrCreate(
            ['business_id' => auth()->user()->business_id, 'key' => 'locale'],
            ['value' => $data['locale']]
        );
        app()->setLocale($data['locale']);
        Activity::log('updated', 'غيّر لغة النظام إلى: ' . $data['locale']);

        return back()->with('toast', [
            'msg' => $data['locale'] === 'ar' ? 'تم تغيير اللغة إلى العربية' : 'Language changed to English',
            'type' => 'success',
        ]);
    }
}
