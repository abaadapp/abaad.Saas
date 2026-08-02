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

    public function update(Request $request)
    {
        // الحفظ حرّ المفاتيح، لكن هذه تُعرض للناس: الموقع كزرّ قابل للنقر،
        // والبريد كوسيلة تواصل. تُصحَّح عند الإدخال لا تُكتشف معطوبة لاحقًا.
        $request->validate([
            'website' => ['nullable', 'string', 'max:255', 'regex:#^(https?://)?[^\s:/]+\.[^\s:/]+(/\S*)?$#i'],
            'shop_name' => ['sometimes', 'required', 'string', 'max:120'],
            'email' => ['sometimes', 'nullable', 'email', 'max:120'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
        ], [
            'website.regex' => __('أدخل عنوان موقع صحيح مثل example.com'),
        ]);

        $bid = auth()->user()->business_id ?? Demo::bid();

        $profile = [];
        foreach (self::PROFILE as $field => $column) {
            if ($request->has($field)) {
                $profile[$column] = $request->input($field);
            }
        }
        if ($profile) {
            \App\Models\Business::whereKey($bid)->update($profile);
        }

        foreach ($request->except(['_token', '_method', 'tab', ...array_keys(self::PROFILE)]) as $key => $value) {
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
