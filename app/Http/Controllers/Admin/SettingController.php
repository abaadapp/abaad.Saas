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
            // البادئة تدخل شرط LIKE عند توليد الرقم — و«%» فيها تجعل كل فاتورةٍ
            // مطابقةً فيقفز العدّاد. تُنقّى في PosController أيضًا، والمنع هنا أوضح.
            'inv_prefix' => ['sometimes', 'nullable', 'string', 'max:12', 'not_regex:/[%_\\\\]/'],
            'inv_start' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3', 'alpha'],
            'decimals' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:4'],
            'symbol_pos' => ['sometimes', 'nullable', 'in:before,after'],
        ], [
            'inv_prefix.not_regex' => __('لا تصلح الرموز % و _ و \\ في بادئة رقم الفاتورة'),
            'currency.size' => __('رمز العملة ثلاثة أحرف مثل OMR'),
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
