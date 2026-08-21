<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Http\Request;

class PlanController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'monthly_price' => ['required', 'numeric', 'min:0'],
            'yearly_price' => ['required', 'numeric', 'min:0'],
            'color' => ['nullable', 'string', 'max:30'],
            'features' => ['nullable', 'string'],
            'is_popular' => ['nullable', 'boolean'],
            /*
             * سقوف الباقة — لم يكن لها حقلٌ في هذه الشاشة أصلًا.
             *
             * `PlanLimits` تفرضها عند الإنشاء، لكنّ الباقة المصنوعة من هنا
             * تُولد بأعمدةٍ فارغة، و`cap()` تقرأ الفارغ «لا سقف». فتُباع باقةٌ
             * مكتوبٌ في مزاياها «فرع واحد» ولا شيء يمنع فتح عشرة. والسقوف
             * الوحيدة العاملة اليوم هي التي زُرعت مع النظام.
             *
             * والفارغ يبقى مسموحًا ويعني «بلا حدّ» صراحةً — لا سهوًا.
             */
            'max_branches' => ['nullable', 'integer', 'min:1'],
            'max_employees' => ['nullable', 'integer', 'min:1'],
            'max_products' => ['nullable', 'integer', 'min:1'],
        ]);
        Plan::create([
            'name' => $data['name'],
            'max_branches' => $data['max_branches'] ?? null,
            'max_employees' => $data['max_employees'] ?? null,
            'max_products' => $data['max_products'] ?? null,
            'monthly_price' => $data['monthly_price'],
            'yearly_price' => $data['yearly_price'],
            'color' => $data['color'] ?? 'primary',
            'is_popular' => $request->boolean('is_popular'),
            'features' => array_values(array_filter(array_map('trim', explode("\n", (string) ($data['features'] ?? ''))))),
        ]);
        \App\Support\Activity::log('created', 'أضاف باقة جديدة: ' . $data['name']);

        return back()->with('toast', ['msg' => __('تمت إضافة الباقة بنجاح'), 'type' => 'success']);
    }

    /**
     * تعديل باقة قائمة.
     *
     * لم يكن لها مسار: نافذة «تعديل الباقة» في القالب كانت بقيم ثابتة وبلا
     * action، وزرّ الحفظ يعرض toast نجاح دون أن يكتب شيئًا.
     */
    public function update(Request $request, $id)
    {
        $plan = Plan::findOrFail($id);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'monthly_price' => ['required', 'numeric', 'min:0'],
            'yearly_price' => ['required', 'numeric', 'min:0'],
            'color' => ['nullable', 'string', 'max:30'],
            'features' => ['nullable', 'string'],
            'is_popular' => ['nullable', 'boolean'],
            // السقوف كما في الإنشاء — انظر التعليق هناك
            'max_branches' => ['nullable', 'integer', 'min:1'],
            'max_employees' => ['nullable', 'integer', 'min:1'],
            'max_products' => ['nullable', 'integer', 'min:1'],
        ]);
        /*
         * وما لم يُذكر في الطلب لا يُمسّ.
         *
         * `?? null` كان يعني أن نموذجًا لا يرسل السقوف يمحوها: تُعدَّل باقةٌ
         * لتغيير سعرها فتفقد حدودها كلَّها بلا أن يظهر ذلك في شاشة.
         */
        $limits = collect(['max_branches', 'max_employees', 'max_products'])
            ->filter(fn ($k) => array_key_exists($k, $data))
            ->mapWithKeys(fn ($k) => [$k => $data[$k]])
            ->all();

        $plan->update($limits + [
            'name' => $data['name'],
            'monthly_price' => $data['monthly_price'],
            'yearly_price' => $data['yearly_price'],
            'color' => $data['color'] ?? $plan->color,
            'is_popular' => $request->boolean('is_popular'),
            'features' => array_values(array_filter(array_map('trim', explode("\n", (string) ($data['features'] ?? ''))))),
        ]);
        \App\Support\Activity::log('updated', 'عدّل الباقة: ' . $plan->name, ['subject_id' => $plan->id]);

        return back()->with('toast', ['msg' => __('تم حفظ تعديلات الباقة بنجاح'), 'type' => 'success']);
    }
}
