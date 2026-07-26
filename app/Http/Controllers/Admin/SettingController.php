<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\Demo;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    public function update(Request $request)
    {
        $bid = auth()->user()->business_id ?? Demo::bid();
        foreach ($request->except(['_token', '_method', 'tab']) as $key => $value) {
            if (is_array($value)) {
                // مصفوفة متداخلة (مثل مناطق التوصيل) → JSON، ومصفوفة بسيطة → قيم مفصولة بفواصل
                $nested = collect($value)->contains(fn ($v) => is_array($v));
                $value = $nested ? json_encode(array_values($value), JSON_UNESCAPED_UNICODE) : implode(',', $value);
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
