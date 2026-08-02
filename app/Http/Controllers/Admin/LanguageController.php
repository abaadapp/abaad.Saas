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

        /**
         * تفضيل شخصي لا إعداد متجر.
         *
         * كانت تُكتب في settings[business_id,'locale'] المشترك: فالكاشير
         * الذي يبدّل إلى الإنجليزية يسلب المالكَ عربيتَه، والمالك حين
         * يعيدها يسلب الكاشير إنجليزيته. الآن لكلٍّ لغته، وإعداد النشاط
         * يبقى افتراضًا لمن لم يختر بعد (يُضبط من تبويب اللغة في الإعدادات).
         */
        session(['locale' => $data['locale']]);
        auth()->user()->update(['locale' => $data['locale']]);
        app()->setLocale($data['locale']);
        Activity::log('updated', 'غيّر لغة النظام إلى: ' . $data['locale']);

        return back()->with('toast', [
            'msg' => $data['locale'] === 'ar' ? 'تم تغيير اللغة إلى العربية' : 'Language changed to English',
            'type' => 'success',
        ]);
    }
}
