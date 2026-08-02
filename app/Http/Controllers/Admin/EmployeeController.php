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
            'pin' => ['nullable', 'digits:4'],
        ]);

        // الوظيفة اسم ظاهر — الصلاحية تُشتق منها، فلا يُحفظ دور غير معروف يمنع الموظف من الدخول
        $title = $this->findJobTitle($data['job_title']);
        if (! $title) {
            return back()->withInput()->withErrors(['job_title' => __('الوظيفة المحددة غير موجودة.')]);
        }

        // رمز الدخول السريع: يجب أن يكون فريدًا على مستوى النظام
        if (! empty($data['pin']) && $this->pinTaken($data['pin'])) {
            return back()->withInput()->withErrors(['pin' => __('رمز الدخول مستخدم بالفعل، اختر رمزًا آخر.')]);
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
            'pin' => ! empty($data['pin']) ? $data['pin'] : null,
        ]);
        \App\Support\Activity::log('created', 'أضاف موظفًا: ' . $data['name']);

        return redirect()->route('admin.employees.index')->with('toast', ['msg' => __('تم إضافة الموظف بنجاح'), 'type' => 'success']);
    }

    /** هل رمز الدخول (٤ أرقام) مستخدم من مستخدم آخر؟ (فريد على مستوى النظام) */
    private function pinTaken(string $pin, ?int $exceptId = null): bool
    {
        return User::whereNotNull('pin')
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->get()
            ->contains(fn ($u) => Hash::check($pin, $u->getRawOriginal('pin')));
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
        $employee = $this->findEmployee($id);

        return \Inertia\Inertia::render('Admin/Employees/Edit', [
            'employee' => [
                'id' => $employee->id,
                'name' => $employee->name,
                'job_title' => $employee->job_title,
                'branch' => $employee->branch,
                'phone' => $employee->phone,
                'email' => $employee->email,
                /**
                 * الرمز لا يُرسل أبدًا — وهو مخزَّن مشفّرًا أصلًا فلا يُقرأ.
                 *
                 * كان يُرسل `$employee->pin` فيصل الهاش إلى المتصفح ويُملأ به
                 * الحقل، فيسقط التحقق («يجب أن يتكون من 4 أرقام») عند كل حفظ:
                 * تعديل أي موظف له رمز دخول كان مستحيلًا. نرسل علمًا فقط:
                 * هل له رمز؟ ليقول النموذج «اتركه فارغًا للإبقاء عليه».
                 */
                'pin' => '',
                'has_pin' => filled($employee->pin),
            ],
            'branches' => Demo::branches(),
            'jobTitles' => \App\Models\JobTitle::where('business_id', Demo::bid())->orderBy('name')->pluck('name')->all(),
        ]);
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
            'pin' => ['nullable', 'digits:4'],
        ]);

        $title = $this->findJobTitle($data['job_title']);
        if (! $title) {
            return back()->withInput()->withErrors(['job_title' => __('الوظيفة المحددة غير موجودة.')]);
        }
        $data['job_title'] = $title->name;
        $data['role'] = $title->role;

        // رمز الدخول السريع: يُحدَّث فقط إذا أُدخل، ويجب أن يكون فريدًا
        $pin = $data['pin'] ?? null;
        unset($data['pin']);
        if (! empty($pin)) {
            if ($this->pinTaken($pin, $employee->id)) {
                return back()->withInput()->withErrors(['pin' => __('رمز الدخول مستخدم بالفعل، اختر رمزًا آخر.')]);
            }
            $data['pin'] = $pin; // يُجزَّأ تلقائيًا عبر cast
        }

        $employee->update($data);
        \App\Support\Activity::log('updated', 'عدّل بيانات الموظف: ' . $employee->name, ['subject_id' => $employee->id]);

        return redirect()->route('admin.employees.show', $employee->id)->with('toast', ['msg' => __('تم تحديث بيانات الموظف'), 'type' => 'success']);
    }

    public function toggleStatus($id)
    {
        $employee = $this->findEmployee($id);
        if ($employee->id === auth()->id()) {
            return back()->with('toast', ['msg' => __('لا يمكنك تعطيل حسابك الخاص'), 'type' => 'error']);
        }
        $employee->status = $employee->status === 'نشط' ? 'معطل' : 'نشط';
        $employee->save();
        $on = $employee->status === 'نشط';
        \App\Support\Activity::log('status', ($on ? 'فعّل' : 'عطّل') . ' حساب الموظف: ' . $employee->name, ['subject_id' => $employee->id]);

        return back()->with('toast', ['msg' => $on ? __('تم تفعيل الحساب') : __('تم تعطيل الحساب'), 'type' => $on ? 'success' : 'warning']);
    }

    public function resetPassword($id)
    {
        $employee = $this->findEmployee($id);
        if ($employee->id === auth()->id()) {
            return back()->with('toast', ['msg' => __('استخدم صفحة «الملف الشخصي» لتغيير كلمة مرورك'), 'type' => 'warning']);
        }
        $temp = 'Ab' . random_int(1000, 9999);
        $employee->password = Hash::make($temp);
        $employee->save();
        \App\Support\Activity::log('updated', 'أعاد تعيين كلمة مرور الموظف: ' . $employee->name, ['subject_id' => $employee->id]);

        return back()->with('toast', ['msg' => __('كلمة المرور الجديدة: :password', ['password' => $temp]), 'type' => 'info']);
    }
}
