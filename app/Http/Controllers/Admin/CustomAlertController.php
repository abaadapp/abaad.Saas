<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CustomAlert;
use App\Support\Demo;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** تنبيهات يعرّفها صاحب النشاط — انظر App\Models\CustomAlert */
class CustomAlertController extends Controller
{
    private function bid(): int
    {
        return auth()->user()->business_id ?? Demo::bid();
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        CustomAlert::create($data + [
            'business_id' => $this->bid(),
            'created_by' => auth()->id(),
            'active' => true,
        ]);

        return back()->with('toast', ['msg' => __('تم إضافة التنبيه'), 'type' => 'success']);
    }

    public function update(Request $request, $id)
    {
        $alert = CustomAlert::where('business_id', $this->bid())->findOrFail($id);

        // التفعيل/التعطيل وحده لا يحتاج إعادة إدخال القاعدة كاملة
        if ($request->has('active') && ! $request->has('message')) {
            $alert->update(['active' => $request->boolean('active')]);

            return back();
        }

        $alert->update($this->validated($request));

        return back()->with('toast', ['msg' => __('تم تحديث التنبيه'), 'type' => 'success']);
    }

    public function destroy($id)
    {
        $alert = CustomAlert::where('business_id', $this->bid())->findOrFail($id);
        $alert->delete();

        return back()->with('toast', ['msg' => __('تم حذف التنبيه'), 'type' => 'warning']);
    }

    /**
     * القاعدة تتطلّب مقياسًا وحدًّا، والتذكير يتطلّب موعدًا — والتحقق يفرض
     * ذلك بدل قبول صفٍّ ناقص لا يظهر أبدًا ولا يُخبر صاحبه لماذا.
     */
    private function validated(Request $request): array
    {
        $rules = [
            'type' => ['required', Rule::in(['rule', 'reminder'])],
            'message' => ['required', 'string', 'max:255'],
            'section' => ['required', Rule::in(\App\Support\Permissions::sections())],
            'color' => ['nullable', Rule::in(['warning', 'danger', 'info', 'success'])],
        ];

        if ($request->input('type') === 'rule') {
            $rules += [
                'metric' => ['required', Rule::in(array_keys(CustomAlert::METRICS))],
                'operator' => ['required', Rule::in(CustomAlert::OPERATORS)],
                'threshold' => ['required', 'numeric'],
            ];
        } else {
            $rules['due_at'] = ['required', 'date'];
        }

        $data = $request->validate($rules);

        // حقول النوع الآخر تُصفَّر، فلا يبقى في الصف شرطٌ لتذكير ولا موعدٌ لقاعدة
        return $data + ($request->input('type') === 'rule'
            ? ['due_at' => null]
            : ['metric' => null, 'operator' => null, 'threshold' => null]);
    }
}
