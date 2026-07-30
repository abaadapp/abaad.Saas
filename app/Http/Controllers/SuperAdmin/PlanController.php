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
        ]);
        Plan::create([
            'name' => $data['name'],
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
        ]);
        $plan->update([
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
