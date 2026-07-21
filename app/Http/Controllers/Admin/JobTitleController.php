<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\JobTitle;
use App\Models\User;
use App\Support\Activity;
use App\Support\Demo;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class JobTitleController extends Controller
{
    private function bid(): int { return auth()->user()->business_id ?? Demo::bid(); }

    public function store(Request $request)
    {
        $bid = $this->bid();
        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('job_titles', 'name')->where(fn ($q) => $q->where('business_id', $bid)),
            ],
            // الصلاحية المكافئة إلزامية: بدونها يُحفظ دور غير معروف فيفقد الموظف الدخول للنظام
            'role' => ['required', 'string', Rule::in(array_keys(JobTitle::ROLES))],
            'description' => ['nullable', 'string', 'max:255'],
        ], [
            'name.unique' => __('هذه الوظيفة موجودة مسبقًا.'),
            'role.required' => __('حدّد الصلاحيات المكافئة للوظيفة.'),
        ], ['name' => __('اسم الوظيفة')]);

        JobTitle::create([
            'business_id' => $bid,
            'name' => $data['name'],
            'role' => $data['role'],
            'description' => $data['description'] ?? null,
        ]);
        Activity::log('created', 'أضاف وظيفة: ' . $data['name']);

        return back()->with('toast', ['msg' => __('تم إضافة الوظيفة'), 'type' => 'success']);
    }

    public function destroy($id)
    {
        $title = JobTitle::where('business_id', $this->bid())->findOrFail($id);

        // لا تُحذف وظيفة مستخدَمة — وإلا بقي موظفون بوظيفة لا وجود لها
        $used = User::where('business_id', $this->bid())->where('job_title', $title->name)->count();
        if ($used > 0) {
            return back()->with('toast', [
                'msg' => __('لا يمكن حذف «:name» لأنها مستخدمة لدى :count موظف. غيّر وظيفتهم أولًا.', ['name' => $title->name, 'count' => $used]),
                'type' => 'error',
            ]);
        }

        Activity::log('deleted', 'حذف الوظيفة: ' . $title->name, ['subject_id' => $title->id]);
        $title->delete();

        return back()->with('toast', ['msg' => __('تم حذف الوظيفة'), 'type' => 'warning']);
    }
}
