<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Demo;
use App\Rules\PlatformEmailDomain;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    /** ما يُرتَّب في قائمة المستخدمين */
    /** قيمة المرشّح التي تعني «المحذوفون» — ليست حالةً في العمود */
    private const TRASHED = 'محذوف';

    private const SORTS = [
        'name' => 'name',
        'email' => 'email',
        'role' => 'role',
        'status' => 'status',
        'last_login' => 'last_login_at',
    ];

    public function index(Request $request)
    {
        /*
         * موظّفو التجّار وحدهم.
         *
         * متجرٌ تجريبيّ فيه ثمانية موظّفين، فلولا هذا لتصدّروا القائمة كأنّهم
         * مستخدمون حقيقيّون — ومدير المنصّة نفسه بلا `business_id` فيبقى.
         */
        $q = User::with('business')->whereDoesntHave('business', fn ($w) => $w->where('is_demo', true));

        // «محذوف» حالةٌ ثالثة: بابُ الاسترداد لا يُفتح إلا من هنا
        if ($request->query('status') === self::TRASHED) { $q->onlyTrashed(); }

        if ($s = trim((string) $request->query('q'))) {
            // المعامل يُسأل ولا يُكتب — انظر `Search`
            $op = \App\Support\Search::like();
            $q->where(fn ($w) => $w->where('name', $op, "%{$s}%")->orWhere('email', $op, "%{$s}%")->orWhere('phone', $op, "%{$s}%"));
        }
        if ($r = $request->query('role')) { $q->where('role', $r); }
        if (($st = $request->query('status')) && $st !== self::TRASHED) { $q->where('status', $st); }

        \App\Support\Sort::apply($q, $request, self::SORTS, fn ($w) => $w->orderByDesc('id'));

        $users = $q->paginate(10)->withQueryString()->through(fn ($u) => [
            'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'phone' => $u->phone,
            'business' => $u->business?->name ?? __('المنصة'), 'role' => $u->roleLabel(),
            'status' => $u->status, 'last_login' => optional($u->last_login_at)->format('Y-m-d H:i') ?? '—',
            'avatar' => $u->avatar ?? Demo::image('user' . $u->id, 100, 100),
            'deleted' => $u->trashed(),
            /*
             * ما يمنعه من الدخول فعلًا — لا ما تقوله شارته.
             *
             * حسابٌ بلا بريد لا يستطيع تسجيل الدخول أصلًا، وكانت شارته خضراء
             * «نشط» كغيره: الشاشة تطمئنك على حسابٍ معطوب. والدور الذي يعمل
             * داخل نشاطٍ بلا نشاطٍ مربوط يدخل إلى نظامٍ فارغ لا بيانات فيه.
             */
            'blocked' => $u->trashed() ? null : self::blockedReason($u),
        ]);

        return \Inertia\Inertia::render('Platform/Users/Index', [
            'users' => $users->items(),
            'pagination' => \App\Support\Pagination::meta($users),
            'filters' => $request->only('q', 'role', 'status')
                + \App\Support\Sort::params($request, self::SORTS),
            'sorts' => \App\Support\Sort::keys(self::SORTS),
            'roles' => PageController::roles(),
            'businesses' => \App\Models\Business::orderBy('name')->get()
                ->map(fn ($b) => ['label' => $b->name, 'value' => $b->id])->all(),
        ]);
    }

    /**
     * ما يمنع هذا الحساب من العمل — أو null إن كان سليمًا.
     *
     * تُقرأ في القائمة فتُوسم الصفوف المعطوبة، وتُقرأ عند الحفظ فلا يُصنع
     * معطوبٌ جديد.
     */
    private static function blockedReason(User $user): ?string
    {
        /*
         * البريد وحده هو الباب.
         *
         * كان الفحص يقيس بابين: بريدًا ورمزًا من أربعة أرقام يدخل به الكاشير.
         * ورُفع الرمز، فمن لا بريد له لا يدخل — وشاشة الموظفين صارت تُلزم
         * البريد فلا يُصنع حسابٌ جديدٌ بلا باب. ويبقى هذا الفحص لمن سبق.
         */
        if (blank($user->email)) {
            return __('بلا بريد — لا باب يدخل منه');
        }

        if ($user->role !== 'super_admin' && ! $user->business_id) {
            return __('بلا نشاط مربوط — يدخل إلى نظام فارغ');
        }

        return null;
    }

    /**
     * قواعد الحساب — واحدةٌ للإنشاء والتعديل.
     *
     * والدور يُتحقَّق من قائمةٍ مغلقة: كان `string|max:50` يقبل أيّ نصّ،
     * وخريطةُ الصلاحيات كانت تعطي المجهولَ كلَّ شيء. حرفٌ زائد في الطلب
     * كان يصنع حسابًا مفتوحًا على كل قسم.
     *
     * والنشاط لازمٌ لغير مدير المنصّة: دورٌ يعمل داخل متجرٍ بلا متجر يدخل
     * إلى نظامٍ فارغ، ولم يكن في الشاشة حقلٌ يُصلح ربطه بعد الإنشاء.
     */
    private function rules(Request $request, ?User $user = null): array
    {
        $isSuper = $request->input('role') === 'super_admin';

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                $user ? Rule::unique('users', 'email')->ignore($user->id) : Rule::unique('users', 'email'),
                // النطاق يُفرض على مدراء المنصة وحدهم؛ التاجر يدخل ببريده هو
                Rule::when($isSuper, [new PlatformEmailDomain]),
            ],
            'phone' => ['nullable', 'string', 'max:50'],
            'role' => ['required', Rule::in(\App\Support\Roles::keys())],
            'business_id' => [
                $isSuper ? 'nullable' : 'required',
                'integer',
                'exists:businesses,id',
            ],
        ];
    }

    /**
     * رسائل عربية للقواعد المشتركة.
     *
     * و«مستعمل في حساب آخر» تُضلّل حين يكون صاحبُ البريد محذوفًا: الحساب لا
     * يظهر في أيّ قائمة، فيبحث المشغّل عنه في الصفحات ولا يجده ويظنّ العطبَ
     * في النظام. فيُسمّى السبب: استعِده أو غيّر بريده.
     */
    private function messages(Request $request): array
    {
        $trashedHolder = User::onlyTrashed()->where('email', $request->input('email'))->exists();

        return [
            'email.unique' => $trashedHolder
                ? __('هذا البريد يخصّ مستخدمًا محذوفًا — استعِده من مرشّح «المحذوفون» أو اختر بريدًا غيره.')
                : __('هذا البريد مستعمل في حساب آخر — اختر غيره.'),
            'role.in' => __('هذا الدور غير معروف في النظام.'),
            'business_id.required' => __('اختر النشاط التجاري — بلا نشاط يدخل الحساب إلى نظام فارغ.'),
            'password.min' => __('كلمة المرور ثمانية أحرف على الأقل.'),
        ];
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules($request) + [
            /*
             * كلمة المرور مطلوبةٌ وثمانية أحرف.
             *
             * كانت اختيارية بأربعة أحرف، وإن تُركت فارغة صار الحساب بكلمة
             * `password` حرفيًّا — وهذه هي النافذة نفسها التي يُصنع بها
             * *مدير منصّة*. أضعفُ حارسٍ على أخطر باب. والتعديل كان يطلب
             * ثمانية، فاختلف البابان في الشدّة والأخطر هو الأهون.
             */
            'password' => ['required', 'string', 'min:8'],
        ], $this->messages($request));

        User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'role' => $data['role'],
            'business_id' => $data['role'] === 'super_admin' ? null : $data['business_id'],
            'status' => 'نشط',
            'avatar' => Demo::image('user' . uniqid()),
            'password' => \Illuminate\Support\Facades\Hash::make($data['password']),
        ]);
        \App\Support\Activity::log('created', 'أضاف مستخدمًا للمنصة: ' . $data['name']);

        return back()->with('toast', ['msg' => __('تم إضافة المستخدم بنجاح'), 'type' => 'success']);
    }

    public function update(Request $request, $id)
    {
        $user = User::findOrFail($id);
        $data = $request->validate($this->rules($request, $user) + [
            /*
             * كلمة المرور لم يكن لها حقلٌ هنا ولا سطرٌ في التحقّق.
             *
             * فيفتح المشغّل «تعديل بيانات المستخدم» ليصلح حساب من فقد كلمته،
             * فلا يجد إلا الاسم والبريد والدور — ولا مخرج إلا فتح القاعدة.
             * والفارغ يعني «لا تغيّرها»، وإلا صار كل تعديلٍ لدورٍ أو هاتفٍ
             * يطالب بكلمةٍ جديدة فتُخترع واحدة ويخرج صاحبها من حسابه.
             */
            'password' => ['nullable', 'string', 'min:8'],
        ], $this->messages($request));

        $password = $data['password'] ?? null;
        unset($data['password']);

        // مدير المنصّة لا نشاط له — وإن جاء رقمٌ في الطلب طُرح
        $data['business_id'] = $data['role'] === 'super_admin' ? null : $data['business_id'];

        $user->update($data);

        if (filled($password)) {
            $user->password = $password; // cast hashed
            $user->save();
            \App\Support\Activity::log('updated', 'غيّر كلمة مرور المستخدم: ' . $user->name, ['subject_id' => $user->id]);
        }
        \App\Support\Activity::log('updated', 'عدّل بيانات المستخدم: ' . $user->name, ['subject_id' => $user->id]);

        return back()->with('toast', ['msg' => __('تم تحديث بيانات المستخدم'), 'type' => 'success']);
    }

    /**
     * الحذف — وكان «التعطيل» كلَّ ما في اليد.
     *
     * حسابٌ أُدخل خطأً يبقى في كل عدٍّ وكل بحثٍ وكل صفحة، ولا يزيله إلا فتح
     * القاعدة. وهو ناعم: يُستعاد من مرشّح «المحذوفون» في الشاشة نفسها.
     *
     * ولا يحذف المرءُ نفسه — يخرج من حسابه ولا يعود إليه ليستعيده.
     */
    public function destroy($id)
    {
        $user = User::findOrFail($id);

        if ($user->id === auth()->id()) {
            return back()->with('toast', ['msg' => __('لا يمكنك حذف حسابك الخاص'), 'type' => 'error']);
        }

        /*
         * ولا يُحذف آخرُ مدير منصّة.
         *
         * حذفُه يُغلق لوحة المنصّة على الجميع، ولا حساب يبقى ليستعيده —
         * ضغطةٌ لا رجعة عنها إلا من قاعدة البيانات.
         */
        if ($user->role === 'super_admin' && User::where('role', 'super_admin')->count() <= 1) {
            return back()->with('toast', ['msg' => __('لا يمكن حذف آخر مدير منصة'), 'type' => 'error']);
        }

        $user->delete();
        \App\Support\Activity::log('deleted', 'حذف المستخدم: ' . $user->name, [
            'subject_id' => $user->id, 'subject_type' => 'user',
        ]);

        return back()->with('toast', ['msg' => __('تم حذف المستخدم — يمكن استعادته'), 'type' => 'success']);
    }

    public function restore($id)
    {
        $user = User::onlyTrashed()->findOrFail($id);
        $user->restore();
        \App\Support\Activity::log('restored', 'استعاد المستخدم: ' . $user->name, ['subject_id' => $user->id]);

        return back()->with('toast', ['msg' => __('تمت استعادة المستخدم'), 'type' => 'success']);
    }

    public function toggleStatus($id)
    {
        $user = User::findOrFail($id);
        if ($user->id === auth()->id()) {
            return back()->with('toast', ['msg' => __('لا يمكنك تعطيل حسابك الخاص'), 'type' => 'error']);
        }
        $user->status = $user->status === 'نشط' ? 'موقوف' : 'نشط';
        $user->save();
        $on = $user->status === 'نشط';
        \App\Support\Activity::log('status', ($on ? 'فعّل' : 'أوقف') . ' حساب المستخدم: ' . $user->name, ['subject_id' => $user->id]);

        return back()->with('toast', ['msg' => $on ? __('تم تفعيل الحساب') : __('تم إيقاف الحساب'), 'type' => $on ? 'success' : 'warning']);
    }
}
