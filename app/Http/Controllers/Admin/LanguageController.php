<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Middleware\SetLocale;
use App\Models\Setting;
use App\Support\Activity;
use App\Support\Demo;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LanguageController extends Controller
{
    public function update(Request $request)
    {
        $data = $request->validate([
            'locale' => ['required', Rule::in(SetLocale::SUPPORTED)],
        ]);

        // تُحفظ في الجلسة (فورية) وفي إعدادات النشاط (تبقى بعد الخروج)
        session(['locale' => $data['locale']]);
        Setting::updateOrCreate(
            ['business_id' => auth()->user()->business_id ?? Demo::bid(), 'key' => 'locale'],
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
