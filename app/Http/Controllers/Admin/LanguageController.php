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
