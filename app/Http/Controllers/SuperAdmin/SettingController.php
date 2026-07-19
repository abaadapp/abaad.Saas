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
}
