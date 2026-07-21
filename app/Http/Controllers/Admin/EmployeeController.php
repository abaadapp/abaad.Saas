<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Demo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class EmployeeController extends Controller
{
    private function bid(): int { return auth()->user()->business_id ?? Demo::bid(); }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:50'],
            'job_title' => ['required', 'string', 'max:100'],
            'branch' => ['nullable', 'string', 'max:100'],
            'password' => ['nullable', 'string', 'min:4'],
        ]);

        // الوظيفة اسم ظاهر — الصلاحية تُشتق منها، فلا يُحفظ دور غير معروف يمنع الموظف من الدخول
        $title = $this->findJobTitle($data['job_title']);
        if (! $title) {
            return back()->withInput()->withErrors(['job_title' => 'الوظيفة المحددة غير موجودة.']);
        }

        User::create([
            'business_id' => $this->bid(),
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'role' => $title->role,
            'job_title' => $title->name,
            'branch' => $data['branch'] ?? 'الفرع الرئيسي',
            'status' => 'نشط',
            'avatar' => Demo::image('emp' . uniqid()),
            'password' => Hash::make($data['password'] ?? 'password'),
        ]);
        \App\Support\Activity::log('created', 'أضاف موظفًا: ' . $data['name']);

        return redirect()->route('admin.employees.index')->with('toast', ['msg' => 'تم إضافة الموظف بنجاح', 'type' => 'success']);
    }

    /** وظيفة تابعة للمستأجر الحالي (تحمل الصلاحية المكافئة) */
    private function findJobTitle(string $name): ?\App\Models\JobTitle
    {
        return \App\Models\JobTitle::where('business_id', $this->bid())->where('name', $name)->first();
    }

    /** موظف تابع للمستأجر الحالي (باستثناء مدير المنصة) */
    private function findEmployee($id): User
    {
        return User::where('business_id', $this->bid())->where('role', '!=', 'super_admin')->findOrFail($id);
    }

    public function edit($id)
    {
        return view('admin.employees.edit', ['employee' => $this->findEmployee($id)]);
    }

    public function update(Request $request, $id)
    {
        $employee = $this->findEmployee($id);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', \Illuminate\Validation\Rule::unique('users', 'email')->ignore($employee->id)],
            'phone' => ['nullable', 'string', 'max:50'],
            'job_title' => ['required', 'string', 'max:100'],
            'branch' => ['nullable', 'string', 'max:100'],
        ]);

        $title = $this->findJobTitle($data['job_title']);
        if (! $title) {
            return back()->withInput()->withErrors(['job_title' => 'الوظيفة المحددة غير موجودة.']);
        }
        $data['job_title'] = $title->name;
        $data['role'] = $title->role;

        $employee->update($data);
        \App\Support\Activity::log('updated', 'عدّل بيانات الموظف: ' . $employee->name, ['subject_id' => $employee->id]);

        return redirect()->route('admin.employees.show', $employee->id)->with('toast', ['msg' => 'تم تحديث بيانات الموظف', 'type' => 'success']);
    }

    /** حفظ الهدف الشهري ونسبة العمولة للموظف */
    public function updateGoal(Request $request, $id)
    {
        $employee = $this->findEmployee($id);
        $data = $request->validate([
            'monthly_target' => ['required', 'numeric', 'min:0'],
            'commission_rate' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);
        $employee->update($data);
        \App\Support\Activity::log('settings', 'حدّد هدف/عمولة الموظف: ' . $employee->name, ['subject_id' => $employee->id]);

        return back()->with('toast', ['msg' => 'تم حفظ هدف وعمولة الموظف', 'type' => 'success']);
    }

    public function toggleStatus($id)
    {
        $employee = $this->findEmployee($id);
        if ($employee->id === auth()->id()) {
            return back()->with('toast', ['msg' => 'لا يمكنك تعطيل حسابك الخاص', 'type' => 'error']);
        }
        $employee->status = $employee->status === 'نشط' ? 'معطل' : 'نشط';
        $employee->save();
        $on = $employee->status === 'نشط';
        \App\Support\Activity::log('status', ($on ? 'فعّل' : 'عطّل') . ' حساب الموظف: ' . $employee->name, ['subject_id' => $employee->id]);

        return back()->with('toast', ['msg' => $on ? 'تم تفعيل الحساب' : 'تم تعطيل الحساب', 'type' => $on ? 'success' : 'warning']);
    }

    public function resetPassword($id)
    {
        $employee = $this->findEmployee($id);
        if ($employee->id === auth()->id()) {
            return back()->with('toast', ['msg' => 'استخدم صفحة «الملف الشخصي» لتغيير كلمة مرورك', 'type' => 'warning']);
        }
        $temp = 'Ab' . random_int(1000, 9999);
        $employee->password = Hash::make($temp);
        $employee->save();
        \App\Support\Activity::log('updated', 'أعاد تعيين كلمة مرور الموظف: ' . $employee->name, ['subject_id' => $employee->id]);

        return back()->with('toast', ['msg' => 'كلمة المرور الجديدة: ' . $temp, 'type' => 'info']);
    }
}
