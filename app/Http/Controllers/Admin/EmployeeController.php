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
            /*
             * البريد اختياري، والرمز يقوم مقامه.
             *
             * كان إلزاميًّا وفريدًا على مستوى المنصة، فأوّل متجرين يريدان
             * `cashier@` أو `info@` يصطدمان ولا يفهم الثاني السبب — ويخترع
             * لموظفه بريدًا وهميًّا لا يقرأه أحد. والبريد معرّفُ دخولٍ لا
             * بيانَ اتصال، فعالميّةُ تفرّده صحيحة ولا تُمسّ؛ الخطأ كان في
             * إلزامه من لا يدخل به أصلًا: الكاشير يدخل برمزه.
             */
            'email' => ['nullable', 'email', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:50'],
            'job_title' => ['required', 'string', 'max:100'],
            'branch' => ['nullable', 'string', 'max:100'],
            /*
             * فروع العمل: قائمة معرّفات، والفارغة تعني «كل فروع المتجر».
             *
             * كان `branch` نصًّا حرًّا لا يُقارن بشيء ولا يُفحص عند الدخول —
             * فكاشير الخوير يدخل على جهاز السيب ويبيع. وهذه القائمة هي مصدر
             * الإذن الآن (انظر User::worksAt).
             */
            'branches' => ['nullable', 'array'],
            'branches.*' => ['integer'],
            'password' => ['nullable', 'string', 'min:4'],
            /*
             * بلا بريدٍ لا بدّ من رمز: حسابٌ بلا واحدٍ منهما لا سبيل إليه.
             * يُحفظ بنجاح ثمّ يقف صاحبه أمام شاشة الدخول ولا يجد بابًا.
             */
            'pin' => ['required_without:email', 'nullable', 'digits:4'],
            /*
             * الصلاحيات إلزامية: قسمٌ واحد على الأقل. موظفٌ بلا صلاحية حسابٌ
             * يدخل ولا يجد شيئًا — يُحفظ بنجاح ثمّ يُكتشف عطله عند أوّل دخول.
             */
            'permissions' => ['required_with:manual_permissions', 'array', 'min:1'],
            'permissions.*' => ['string', \Illuminate\Validation\Rule::in(\App\Support\Permissions::sections())],
        ], [
            'permissions.required_with' => __('حدّد صلاحيات الموظف — قسمٌ واحد على الأقل.'),
            'permissions.min' => __('حدّد صلاحيات الموظف — قسمٌ واحد على الأقل.'),
            'pin.required_without' => __('بلا بريد إلكتروني يلزم رمز دخول — وإلا تعذّر على الموظف الدخول.'),
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

        \App\Support\PlanLimits::enforce(auth()->user()->business, 'employees');

        $employee = User::create([
            'business_id' => $this->bid(),
            'name' => $data['name'],
            // nullable: المفتاح يغيب عن $data حين لا يُرسل، ولا يأتي null
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'role' => $title->role,
            'job_title' => $title->name,
            'branch' => $data['branch'] ?? 'الفرع الرئيسي',
            'status' => 'نشط',
            'avatar' => Demo::image('emp' . uniqid()),
            'password' => Hash::make($data['password'] ?? 'password'),
            'pin' => ! empty($data['pin']) ? $data['pin'] : null,
            /*
             * الوظيفة تُقترح ولا تُلزم: صاحب النشاط يحدّد ما يفتحه الموظف منذ
             * لحظة إضافته. NULL تعني «اتبع الوظيفة» فتتغيّر صلاحياته معها،
             * والقائمة اليدوية تعني ما اختير هنا لا غير.
             */
            'permissions' => $request->boolean('manual_permissions')
                ? array_values(array_unique($data['permissions'] ?? []))
                : null,
        ]);
        $this->syncBranches($employee, $data['branches'] ?? []);
        \App\Support\Activity::log('created', 'أضاف موظفًا: ' . $data['name']);

        return redirect()->route('admin.employees.index')->with('toast', ['msg' => __('تم إضافة الموظف بنجاح'), 'type' => 'success']);
    }

    /**
     * هل رمز الدخول (٤ أرقام) مستخدم داخل هذا المتجر؟
     *
     * كان فريدًا على مستوى المنصة كلّها، وفي ذلك عطبان:
     *
     * ١) تسريبٌ صغير: متجرٌ يُخبَر أن رمزًا «مستخدم بالفعل» وهو لا يرى من
     *    يستعمله ولا يعرف بوجوده.
     *
     * ٢) وهو الأخطر: الرموز عشرة آلاف لا غير. مئة متجرٍ بعشرين موظفًا تشغل
     *    خُمس الفضاء، فتخمينٌ عشوائي واحد يصيب بنسبة واحدٍ من خمسة — ويُدخل
     *    متجرًا ما، أيًّا كان. والخطر ينمو مع كل متجرٍ يُضاف، أي أن نجاح
     *    المنصة نفسه هو ما يفتح الباب.
     *
     * وبالحصر داخل المتجر يعود لكل متجرٍ فضاؤه كاملًا، ولا يتغيّر بعدد
     * الجيران. ويقابله أن شاشة الرمز صارت تعرف متجرها (انظر PosDevice).
     */
    private function pinTaken(string $pin, ?int $exceptId = null): bool
    {
        return User::where('business_id', $this->bid())
            ->whereNotNull('pin')
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
                // الفارغة تعني «كل الفروع» — انظر User::worksAt
                'branches' => $employee->branches()->pluck('branches.id')->all(),
                'avatar' => $employee->avatar,
                'status' => $employee->status,
                'monthly_target' => $employee->monthly_target,
                'commission_rate' => $employee->commission_rate,
                // null تعني «اتبع الدور»؛ مصفوفة تعني قائمة يدوية
                'permissions' => $employee->permissions,
                'role_permissions' => collect(\App\Support\Permissions::sections())
                    ->filter(fn ($s) => \App\Support\Permissions::allows($employee->role, $s))
                    ->values()->all(),
            ],
            'sections' => \App\Support\Permissions::sectionLabels(),
            'branches' => Demo::branches(),
            'branchOptions' => \App\Models\Branch::where('business_id', Demo::bid())
                ->orderBy('id')->get(['id', 'name'])
                ->map(fn ($b) => ['value' => $b->id, 'label' => $b->name])->values()->all(),
            'jobTitles' => \App\Models\JobTitle::where('business_id', Demo::bid())->orderBy('name')->pluck('name')->all(),
        ]);
    }

    public function update(Request $request, $id)
    {
        $employee = $this->findEmployee($id);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', \Illuminate\Validation\Rule::unique('users', 'email')->ignore($employee->id)],
            'phone' => ['nullable', 'string', 'max:50'],
            'job_title' => ['required', 'string', 'max:100'],
            'branch' => ['nullable', 'string', 'max:100'],
            /*
             * فروع العمل: قائمة معرّفات، والفارغة تعني «كل فروع المتجر».
             *
             * كان `branch` نصًّا حرًّا لا يُقارن بشيء ولا يُفحص عند الدخول —
             * فكاشير الخوير يدخل على جهاز السيب ويبيع. وهذه القائمة هي مصدر
             * الإذن الآن (انظر User::worksAt).
             */
            'branches' => ['nullable', 'array'],
            'branches.*' => ['integer'],
            'pin' => ['nullable', 'digits:4'],
            // كان النموذج يعرض حقل كلمة مرور والتحقق لا يقبله: كل محاولة
            // تغيير كانت تُبتلع بصمت ويظنّ المدير أنه غيّرها.
            'password' => ['nullable', 'string', 'min:4'],
            'status' => ['nullable', 'boolean'],
            'monthly_target' => ['nullable', 'numeric', 'min:0'],
            'commission_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            /*
             * الصلاحيات إلزامية: قسمٌ واحد على الأقل. موظفٌ بلا صلاحية حسابٌ
             * يدخل ولا يجد شيئًا — يُحفظ بنجاح ثمّ يُكتشف عطله عند أوّل دخول.
             */
            'permissions' => ['required_with:manual_permissions', 'array', 'min:1'],
            'permissions.*' => ['string', \Illuminate\Validation\Rule::in(\App\Support\Permissions::sections())],
        ], [
            'permissions.required_with' => __('حدّد صلاحيات الموظف — قسمٌ واحد على الأقل.'),
            'permissions.min' => __('حدّد صلاحيات الموظف — قسمٌ واحد على الأقل.'),
        ]);

        $title = $this->findJobTitle($data['job_title']);
        if (! $title) {
            return back()->withInput()->withErrors(['job_title' => __('الوظيفة المحددة غير موجودة.')]);
        }
        $data['job_title'] = $title->name;
        $data['role'] = $title->role;

        /*
         * لا يبقى الحساب بلا بابٍ واحد.
         *
         * البريد صار اختياريًّا، والرمز يُترك فارغًا ليبقى كما هو — فمحوُ
         * البريد عن موظفٍ لا رمز له كان يُنتج حسابًا سليمًا في القاعدة لا
         * سبيل إلى الدخول به، ولا يظهر عطله إلا حين يقف صاحبه أمام الشاشة.
         */
        if (blank($data['email'] ?? null) && blank($data['pin'] ?? null) && ! filled($employee->pin)) {
            return back()->withInput()->withErrors([
                'pin' => __('بلا بريد إلكتروني يلزم رمز دخول — وإلا تعذّر على الموظف الدخول.'),
            ]);
        }

        // رمز الدخول السريع: يُحدَّث فقط إذا أُدخل، ويجب أن يكون فريدًا
        $pin = $data['pin'] ?? null;
        unset($data['pin']);
        if (! empty($pin)) {
            if ($this->pinTaken($pin, $employee->id)) {
                return back()->withInput()->withErrors(['pin' => __('رمز الدخول مستخدم بالفعل، اختر رمزًا آخر.')]);
            }
            $data['pin'] = $pin; // يُجزَّأ تلقائيًا عبر cast
        }

        // كلمة المرور تُغيَّر فقط إن أُدخلت — والفراغ يعني «أبقِها»
        $password = $data['password'] ?? null;
        unset($data['password']);
        if (! empty($password)) {
            $data['password'] = $password; // يُجزَّأ تلقائيًا عبر cast، كما الرمز
        }

        // الحالة: لا يُعطّل المستخدم حسابه بنفسه فيُقفل خارج النظام
        $wantsActive = $request->has('status') ? $request->boolean('status') : null;
        unset($data['status']);
        if ($wantsActive !== null) {
            if (! $wantsActive && $employee->id === auth()->id()) {
                return back()->withInput()->withErrors(['status' => __('لا يمكنك تعطيل حسابك الخاص')]);
            }
            $data['status'] = $wantsActive ? 'نشط' : 'معطل';
        }

        // الأرقام الفارغة تعني «بلا هدف/عمولة» لا صفرًا مفروضًا
        foreach (['monthly_target', 'commission_rate'] as $numeric) {
            if (array_key_exists($numeric, $data)) {
                $data[$numeric] = $data[$numeric] === null || $data[$numeric] === '' ? 0 : $data[$numeric];
            }
        }

        /*
         * الصلاحيات اليدوية.
         *
         * التمييز بين «لم يُرسَل» و«أُرسل فارغًا» جوهري هنا: النموذج يرسل
         * manual_permissions=0 حين يختار المدير «اتبع الدور»، فتعود القيمة
         * إلى null. ولو قرأناها بـ?? null لصار كل حفظٍ لموظفٍ من شاشة أخرى
         * يمحو صلاحياته المخصَّصة بلا أن يطلب أحدٌ ذلك.
         *
         * ولا يُخصّص أحدٌ صلاحيات نفسه: مديرٌ ينزع عن نفسه قسمًا بالخطأ يقفل
         * الباب على نفسه ولا يجد من يعيده.
         */
        if ($request->has('manual_permissions')) {
            if ($employee->id === auth()->id()) {
                return back()->withInput()->withErrors([
                    'permissions' => __('لا يمكنك تعديل صلاحيات حسابك الخاص.'),
                ]);
            }

            $employee->permissions = $request->boolean('manual_permissions')
                ? array_values(array_unique($data['permissions'] ?? []))
                : null;
        }
        unset($data['permissions']);

        /*
         * الفروع: «لم تُرسل» ليست «أُرسلت فارغة».
         *
         * الفارغة تعني «كل الفروع» وهي اختيارٌ مشروع، فلا بدّ من التمييز —
         * وإلا صار كل حفظٍ من شاشةٍ لا تعرض الفروع يمحو تقييد الموظف بها
         * ويفتح له المتجر كلّه بلا أن يطلب أحدٌ ذلك.
         */
        if ($request->has('branches')) {
            $this->syncBranches($employee, $data['branches'] ?? []);
        }
        unset($data['branches']);

        $employee->update($data);
        \App\Support\Activity::log('updated', 'عدّل بيانات الموظف: ' . $employee->name, ['subject_id' => $employee->id]);

        return redirect()->route('admin.employees.show', $employee->id)->with('toast', ['msg' => __('تم تحديث بيانات الموظف'), 'type' => 'success']);
    }

    /**
     * يضبط فروع الموظف.
     *
     * المعرّفات تُقيَّد بفروع متجره: قائمةٌ من الواجهة لا يُوثق بها، وفرعٌ من
     * متجر الجار كان سيُكتب في جدول الإذن ويصير للموظف حقٌّ خارج متجره.
     */
    private function syncBranches(User $employee, array $ids): void
    {
        $valid = \App\Models\Branch::where('business_id', $this->bid())
            ->whereIn('id', $ids)->pluck('id')->all();

        $employee->branches()->sync($valid);
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
