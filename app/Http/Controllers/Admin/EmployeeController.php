<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\JobTitle;
use App\Models\User;
use App\Support\Activity;
use App\Support\Demo;
use App\Support\MerchantAccount;
use App\Support\Permissions;
use App\Support\PlanFeatures;
use App\Support\PlanLimits;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class EmployeeController extends Controller
{
    private function bid(): int
    {
        return auth()->user()->business_id ?? Demo::bid();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            /*
             * اسمُ الدخول وحده يُكتب، والنطاق يُلحق على الخادم.
             *
             * والبريد إلزاميّ: هو الباب الوحيد. كان اختياريًّا لأن الكاشير
             * يدخل بأربعة أرقام؛ ولمّا رُفع الدخول بالرمز صار الحسابُ بلا
             * بريدٍ حسابًا بلا باب — يُحفظ بنجاح ثمّ يقف صاحبه أمام الشاشة
             * ولا يجد ما يدخل به.
             *
             * وكان يُكتب كاملًا بيد المدير، فتخرج عناوين على أشكال: `.com`
             * مكان `.om`، ومسافةٌ في الآخر، وحرفٌ عربيّ سقط من لوحةٍ لم
             * تُبدَّل. ثمّ لا يدخل الموظف ولا يعرف أحدٌ لماذا.
             *
             * وتفرّده على مستوى المنصة يبقى: هو معرّف الدخول لا بيان اتصال.
             */
            'login_username' => array_merge(['required'], MerchantAccount::usernameRules()),
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
             * الراتب وبدلاته — مصدرُ مسيرة الشهر.
             *
             * بلا حقلٍ يُدخلان منه كانت المسيرة تُفتح على أصفار: يفتحها
             * التاجر فيجد كلّ موظّفيه بلا راتب ولا يعرف من أين يُدخلها.
             */
            'basic_salary' => ['nullable', 'numeric', 'min:0'],
            'allowances' => ['nullable', 'numeric', 'min:0'],
            /*
             * الصلاحيات إلزامية: قسمٌ واحد على الأقل. موظفٌ بلا صلاحية حسابٌ
             * يدخل ولا يجد شيئًا — يُحفظ بنجاح ثمّ يُكتشف عطله عند أوّل دخول.
             */
            'permissions' => ['required_with:manual_permissions', 'array', 'min:1'],
            'permissions.*' => ['string', Rule::in(Permissions::sections())],
        ], [
            'permissions.required_with' => __('حدّد صلاحيات الموظف — قسمٌ واحد على الأقل.'),
            'permissions.min' => __('حدّد صلاحيات الموظف — قسمٌ واحد على الأقل.'),
            'login_username.required' => __('اسم المستخدم مطلوب — وبه يدخل الموظف إلى النظام.'),
        ] + MerchantAccount::messages());

        // العنوان الكامل يُبنى هنا، والتفرّد يُفحص عليه لا على الاسم
        $email = MerchantAccount::email($data['login_username']);

        if (MerchantAccount::taken($data['login_username'])) {
            return back()->withInput()->withErrors([
                'login_username' => __('هذا الاسم مستخدَم — اختر غيره.'),
            ]);
        }

        // الوظيفة اسم ظاهر — الصلاحية تُشتق منها، فلا يُحفظ دور غير معروف يمنع الموظف من الدخول
        $title = $this->findJobTitle($data['job_title']);
        if (! $title) {
            return back()->withInput()->withErrors(['job_title' => __('الوظيفة المحددة غير موجودة.')]);
        }

        $manual = $request->boolean('manual_permissions')
            ? array_values(array_unique($data['permissions'] ?? []))
            : null;
        $this->refuseManualPermissionsBeyondPlan($title, $manual);
        $this->refuseGrantingMoreThanIHave($title, $manual);

        PlanLimits::enforce(auth()->user()->business, 'employees');

        $employee = User::create([
            'business_id' => $this->bid(),
            'name' => $data['name'],
            'email' => $email,
            'phone' => $data['phone'] ?? null,
            'role' => $title->role,
            'job_title' => $title->name,
            'branch' => $data['branch'] ?? 'الفرع الرئيسي',
            'status' => 'نشط',
            'avatar' => Demo::image('emp'.uniqid()),
            'password' => Hash::make($data['password'] ?? 'password'),
            // العمودان لا يقبلان NULL، والحقل الفارغ يعني صفرًا لا فراغًا
            'basic_salary' => $data['basic_salary'] ?? 0,
            'allowances' => $data['allowances'] ?? 0,
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
        Activity::log('created', 'أضاف موظفًا: '.$data['name']);

        return redirect()->route('admin.employees.index')->with('toast', ['msg' => __('تم إضافة الموظف بنجاح'), 'type' => 'success']);
    }

    /**
     * لا يُعطي أحدٌ ما لا يملك.
     *
     * قسم «الموظفون» يُمنح للمحاسب — وهو بابُ رواتبَ وأرقام هواتف في الأصل.
     * وكان يفتح على أوسع من ذلك بكثير:
     *
     *   يُنشئ المحاسب موظّفًا بوظيفةٍ دورُها «مدير فرع» — ومدير الفرع يملك
     *   كلّ الأقسام — ويضع له كلمة مرورٍ يعرفها، ثمّ يدخل بها. أو يفتح صفّه
     *   هو ويبدّل وظيفته إلى تلك، فيصير عنده الإعدادات والمالية والنسخ
     *   الاحتياطية. ولا شيء في المسار يمنعه: الدور يُشتقّ من الوظيفة، وحارسُ
     *   الصلاحيات لا يعمل إلّا حين تُرسَل قائمةٌ يدوية.
     *
     * فالقاعدة: ما يُمنح لا يتجاوز ما يملكه المانح. وصاحب النشاط خارجها —
     * هو مالكُ كلّ شيء أصلًا، ومن يقيّده يقفل المحلّ على صاحبه.
     *
     * @param  array<int, string>|null  $manual  قائمةٌ يدوية إن أُرسلت
     */
    /**
     * الصلاحيات المخصّصة قدرةُ باقةٍ — والمقياس الانحرافُ عن الدور لا إرسالُ قائمة.
     *
     * النموذج يرسل `manual_permissions` دائمًا ومعه القائمة، حتّى حين يتركها
     * المدير كما جاءت من الدور. فقياسُ القدرة بمجرّد وصول القائمة كان يقفل
     * إضافةَ الموظّفين كلَّها على الباقة الأساسية — وهو ما لم يعده أحد.
     *
     * فالمقياس أن تختلف عمّا يمنحه الدور: من عيّن «كاشيرًا» فأخذ ما للكاشير
     * لم يخصّص شيئًا، ومن نزع عنه قسمًا أو زاده قسمًا فقد خصّص. وهو المعنى
     * المكتوب في صفحة التسعير حرفًا بحرف.
     *
     * و`null` — أي «اتبع الدور» — لا تُفحص أصلًا: الرجوع عن التخصيص يجب أن
     * يبقى مفتوحًا لمن نزلت باقتُه، وإلّا حُبس على ما خصّصه قبلها.
     */
    private function refuseManualPermissionsBeyondPlan(JobTitle $title, ?array $manual, ?User $employee = null): void
    {
        if ($manual === null) {
            return;
        }

        $byRole = array_values(array_filter(
            Permissions::sections(),
            fn ($s) => Permissions::allows($title->role, $s),
        ));

        $wanted = array_values(array_unique($manual));
        sort($byRole);
        sort($wanted);

        if ($byRole === $wanted) {
            return;
        }

        /*
         * وما هو قائمٌ يبقى — الحدُّ يمنع إحداث تخصيصٍ لا الإبقاء عليه.
         *
         * موظّفٌ مُنح صلاحياتٍ مخصّصة يوم كانت الباقة تسمح، أو قبل أن يوجد
         * هذا الحدّ أصلًا. والنموذج يرسل صلاحياته كما هي في كلّ حفظ، فكان
         * الانحرافُ يُقاس ويُردّ الطلب — **حتى لو لم يُغيَّر إلا رقم الهاتف**.
         * فلا يستطيع المالك تعديل موظّفه إطلاقًا: لا اسمَه ولا تعطيلَه.
         *
         * ولو أردنا نزعَها لكان النزعُ صريحًا في مكانه، لا أثرًا جانبيًّا
         * لتغيير حقلٍ آخر.
         */
        if ($employee !== null) {
            $current = array_values(array_unique($employee->permissions ?? []));
            sort($current);

            if ($current === $wanted) {
                return;
            }
        }

        if (PlanFeatures::allows(auth()->user()?->business, 'custom_permissions')) {
            return;
        }

        /*
         * والرفضُ يُقال في النموذج لا يُصفع به.
         *
         * `abort(403)` على حفظِ نموذجٍ يُخرج صفحة خطأٍ كاملة: يفقد المالك ما
         * كتبه، ولا يعرف أيُّ حقلٍ سبّبها ولا أنّ السبب باقتُه أصلًا.
         */
        throw ValidationException::withMessages([
            'permissions' => PlanFeatures::refusal(auth()->user()?->business, 'custom_permissions'),
        ]);
    }

    private function refuseGrantingMoreThanIHave(JobTitle $title, ?array $manual): void
    {
        $actor = auth()->user();

        if (! $actor || $actor->role === 'admin') {
            return;
        }

        $granted = $manual !== null
            ? $manual
            : array_values(array_filter(Permissions::sections(), fn ($s) => Permissions::allows($title->role, $s)));

        $beyond = array_values(array_filter($granted, fn ($s) => ! $actor->allows($s)));

        if ($beyond) {
            abort(403, __('لا تملك صلاحية منح: :sections', [
                'sections' => implode('، ', array_map(
                    fn ($s) => Permissions::sectionLabels()[$s] ?? $s,
                    $beyond,
                )),
            ]));
        }
    }

    /**
     * ولا يرفع أحدٌ نفسه — لا رتبةً ولا راتبًا.
     *
     * تعديلُ صلاحيات النفس ممنوعٌ أصلًا، لكنّ الدور يأتي من الوظيفة لا من
     * حقل الصلاحيات: فبدَلُ الوظيفة وحده كان يرفع صاحبه. والراتب مثله —
     * مسيرةُ الشهر تقرأ العمود، فمن رفع راتبه رفع ما يُصرف له.
     */
    private function refuseRaisingMyself(User $employee, JobTitle $title, Request $request): ?string
    {
        if ($employee->id !== auth()->id() || auth()->user()?->role === 'admin') {
            return null;
        }

        if ($title->role !== $employee->role) {
            return __('لا يمكنك تغيير وظيفتك بنفسك.');
        }

        foreach (['basic_salary' => $employee->basic_salary, 'allowances' => $employee->allowances,
            'commission_rate' => $employee->commission_rate] as $field => $current) {
            if ($request->has($field) && (float) $request->input($field) > (float) $current) {
                return __('لا يمكنك رفع راتبك أو بدلاتك بنفسك.');
            }
        }

        return null;
    }

    /** وظيفة تابعة للمستأجر الحالي (تحمل الصلاحية المكافئة) */
    private function findJobTitle(string $name): ?JobTitle
    {
        return JobTitle::where('business_id', $this->bid())->where('name', $name)->first();
    }

    /** موظف تابع للمستأجر الحالي (باستثناء مدير المنصة) */
    private function findEmployee($id): User
    {
        return User::where('business_id', $this->bid())->where('role', '!=', 'super_admin')->findOrFail($id);
    }

    /**
     * حساب صاحب النشاط لا يُمَسّ إلا بيد صاحب نشاطٍ مثله.
     *
     * قسم «الموظفون» يُمنح للمدير وللمحاسب — وهو في الأصل باب رواتبَ وأدوارٍ
     * وأرقام هواتف. لكن أبوابه الأربعة كانت تفتح على صفّ صاحب النشاط نفسه:
     * تُغيَّر كلمة مروره من شاشة التعديل، وتُعاد بزرّ «إعادة تعيين» الذي
     * يعرض الكلمة الجديدة على الشاشة، ويُوقَف حسابه من الشاشة أو من المفتاح.
     *
     * والنتيجة استيلاءٌ كامل على المحلّ: يُوقف المحاسبُ صاحبَه فتُنهى جلسته
     * ويُردّ إلى شاشة الدخول، أو يأخذ كلمة مروره فيدخل باسمه. ولا يستعيدها
     * صاحب المحل إلا بالبريد — وهو ما يُعطَّل عند أول مزوّدٍ لا يُضبط.
     *
     * فيُشترط أن يكون الماسّ صاحبَ نشاطٍ هو الآخر. والقراءة تبقى مفتوحة:
     * المحاسب يرى الصفّ في القائمة كما كان، ولا يكتب فيه.
     */
    private function refuseTouchingTheOwner(User $employee): void
    {
        if ($employee->role === 'admin' && auth()->user()?->role !== 'admin') {
            abort(403, __('حساب صاحب النشاط لا يُعدَّل إلا بيده.'));
        }
    }

    public function edit($id)
    {
        $employee = $this->findEmployee($id);

        return Inertia::render('Admin/Employees/Edit', [
            'employee' => [
                'id' => $employee->id,
                'name' => $employee->name,
                'job_title' => $employee->job_title,
                'branch' => $employee->branch,
                'phone' => $employee->phone,
                'email' => $employee->email,
                // الاسم وحده يُعرض في الحقل، والنطاق مُلحق ثابت في الشاشة
                'username' => MerchantAccount::username((string) $employee->email),
                // خارج النطاق: حسابٌ قديم، ولا يُنقل إلا بطلبٍ صريح
                'on_domain' => MerchantAccount::onDomain($employee->email),
                // الفارغة تعني «كل الفروع» — انظر User::worksAt
                'branches' => $employee->branches()->pluck('branches.id')->all(),
                'avatar' => $employee->avatar,
                'status' => $employee->status,
                'monthly_target' => $employee->monthly_target,
                'commission_rate' => $employee->commission_rate,
                // منهما تُملأ مسيرة الشهر — وحقلٌ لا يُرسل يُقرأ فارغًا فيُمسح عند الحفظ
                'basic_salary' => $employee->basic_salary,
                'allowances' => $employee->allowances,
                // null تعني «اتبع الدور»؛ مصفوفة تعني قائمة يدوية
                'permissions' => $employee->permissions,
                'role_permissions' => collect(Permissions::sections())
                    ->filter(fn ($s) => Permissions::allows($employee->role, $s))
                    ->values()->all(),
            ],
            'sections' => Permissions::sectionLabels(),
            'branches' => Demo::branches(),
            'branchOptions' => Branch::where('business_id', Demo::bid())
                ->orderBy('id')->get(['id', 'name'])
                ->map(fn ($b) => ['value' => $b->id, 'label' => $b->name])->values()->all(),
            'jobTitles' => JobTitle::where('business_id', Demo::bid())->orderBy('name')->pluck('name')->all(),
        ]);
    }

    public function update(Request $request, $id)
    {
        $employee = $this->findEmployee($id);
        $this->refuseTouchingTheOwner($employee);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            /*
             * الاسم اختياريّ هنا وحده — والفارغ يعني «أبقِ عنوانه».
             *
             * حساباتٌ قديمة أُنشئت قبل توحيد النطاق تحمل عناوين خارجه. ولو
             * فُرض الاسم على كلّ حفظ لَنُقلت في صمت: يُصحَّح رقم هاتفٍ فيتبدّل
             * بريدُ الدخول، ويقف صاحبه غدًا أمام الشاشة بعنوانٍ لا يعرفه.
             * فالنقل يقع بطلبٍ صريح من الشاشة لا كأثرٍ جانبيّ لحفظ.
             */
            'login_username' => array_merge(['nullable'], MerchantAccount::usernameRules()),
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
            // كان النموذج يعرض حقل كلمة مرور والتحقق لا يقبله: كل محاولة
            // تغيير كانت تُبتلع بصمت ويظنّ المدير أنه غيّرها.
            'password' => ['nullable', 'string', 'min:4'],
            'status' => ['nullable', 'boolean'],
            'monthly_target' => ['nullable', 'numeric', 'min:0'],
            'commission_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            /*
             * الراتب وبدلاته — مصدرُ مسيرة الشهر.
             *
             * بلا حقلٍ يُدخلان منه كانت المسيرة تُفتح على أصفار: يفتحها
             * التاجر فيجد كلّ موظّفيه بلا راتب ولا يعرف من أين يُدخلها.
             */
            'basic_salary' => ['nullable', 'numeric', 'min:0'],
            'allowances' => ['nullable', 'numeric', 'min:0'],
            /*
             * الصلاحيات إلزامية: قسمٌ واحد على الأقل. موظفٌ بلا صلاحية حسابٌ
             * يدخل ولا يجد شيئًا — يُحفظ بنجاح ثمّ يُكتشف عطله عند أوّل دخول.
             */
            'permissions' => ['required_with:manual_permissions', 'array', 'min:1'],
            'permissions.*' => ['string', Rule::in(Permissions::sections())],
        ], [
            'permissions.required_with' => __('حدّد صلاحيات الموظف — قسمٌ واحد على الأقل.'),
            'permissions.min' => __('حدّد صلاحيات الموظف — قسمٌ واحد على الأقل.'),
        ] + MerchantAccount::messages());

        if (filled($data['login_username'] ?? null)) {
            if (MerchantAccount::taken($data['login_username'], $employee->id)) {
                return back()->withInput()->withErrors([
                    'login_username' => __('هذا الاسم مستخدَم — اختر غيره.'),
                ]);
            }

            $data['email'] = MerchantAccount::email($data['login_username']);
        }

        unset($data['login_username']);

        $title = $this->findJobTitle($data['job_title']);
        if (! $title) {
            return back()->withInput()->withErrors(['job_title' => __('الوظيفة المحددة غير موجودة.')]);
        }
        $manual = $request->has('manual_permissions') && $request->boolean('manual_permissions')
            ? array_values(array_unique($data['permissions'] ?? []))
            : null;
        $this->refuseManualPermissionsBeyondPlan($title, $manual, $employee);
        $this->refuseGrantingMoreThanIHave($title, $manual);

        if ($refusal = $this->refuseRaisingMyself($employee, $title, $request)) {
            return back()->withInput()->withErrors(['job_title' => $refusal]);
        }

        $data['job_title'] = $title->name;
        $data['role'] = $title->role;

        // كلمة المرور تُغيَّر فقط إن أُدخلت — والفراغ يعني «أبقِها»
        $password = $data['password'] ?? null;
        unset($data['password']);
        if (! empty($password)) {
            $data['password'] = $password; // يُجزَّأ تلقائيًا عبر cast
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
        foreach (['monthly_target', 'commission_rate', 'basic_salary', 'allowances'] as $numeric) {
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
        Activity::log('updated', 'عدّل بيانات الموظف: '.$employee->name, ['subject_id' => $employee->id]);

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
        $valid = Branch::where('business_id', $this->bid())
            ->whereIn('id', $ids)->pluck('id')->all();

        $employee->branches()->sync($valid);
    }

    public function toggleStatus($id)
    {
        $employee = $this->findEmployee($id);
        $this->refuseTouchingTheOwner($employee);
        if ($employee->id === auth()->id()) {
            return back()->with('toast', ['msg' => __('لا يمكنك تعطيل حسابك الخاص'), 'type' => 'error']);
        }
        $employee->status = $employee->status === 'نشط' ? 'معطل' : 'نشط';
        $employee->save();
        $on = $employee->status === 'نشط';
        Activity::log('status', ($on ? 'فعّل' : 'عطّل').' حساب الموظف: '.$employee->name, ['subject_id' => $employee->id]);

        return back()->with('toast', ['msg' => $on ? __('تم تفعيل الحساب') : __('تم تعطيل الحساب'), 'type' => $on ? 'success' : 'warning']);
    }

    public function resetPassword($id)
    {
        $employee = $this->findEmployee($id);
        $this->refuseTouchingTheOwner($employee);
        if ($employee->id === auth()->id()) {
            return back()->with('toast', ['msg' => __('استخدم صفحة «الملف الشخصي» لتغيير كلمة مرورك'), 'type' => 'warning']);
        }
        $temp = MerchantAccount::temporaryPassword();
        $employee->password = Hash::make($temp);
        $employee->save();
        Activity::log('updated', 'أعاد تعيين كلمة مرور الموظف: '.$employee->name, ['subject_id' => $employee->id]);

        /*
         * تُعرض في الشاشة لتُنسخ، ولا تُكتب في سجلّ النشاط ولا في القاعدة:
         * المحفوظ تجزئتُها وحدها، وهذه آخر مرّة تُقرأ فيها.
         */
        return back()
            ->with('issued_password', $temp)
            ->with('toast', ['msg' => __('وُلِّدت كلمة مرور جديدة — انسخها قبل إغلاق النافذة'), 'type' => 'info']);
    }
}
