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
    private const SORTS = [
        'name' => 'name',
        'email' => 'email',
        'role' => 'role',
        'status' => 'status',
        'last_login' => 'last_login_at',
    ];

    public function index(Request $request)
    {
        $q = User::with('business');

        if ($s = trim((string) $request->query('q'))) {
            $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%")->orWhere('phone', 'like', "%{$s}%"));
        }
        if ($r = $request->query('role')) { $q->where('role', $r); }
        if ($st = $request->query('status')) { $q->where('status', $st); }

        \App\Support\Sort::apply($q, $request, self::SORTS, fn ($w) => $w->orderByDesc('id'));

        $users = $q->paginate(10)->withQueryString()->through(fn ($u) => [
            'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'phone' => $u->phone,
            'business' => $u->business?->name ?? __('المنصة'), 'role' => $u->roleLabel(),
            'status' => $u->status, 'last_login' => optional($u->last_login_at)->format('Y-m-d H:i') ?? '—',
            'avatar' => $u->avatar ?? Demo::image('user' . $u->id, 100, 100),
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

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'unique:users,email',
                // النطاق يُفرض على مدراء المنصة وحدهم؛ التاجر يدخل ببريده هو
                Rule::when($request->input('role') === 'super_admin', [new PlatformEmailDomain]),
            ],
            'phone' => ['nullable', 'string', 'max:50'],
            'role' => ['required', 'string', 'max:50'],
            'business_id' => ['nullable', 'integer', 'exists:businesses,id'],
            'password' => ['nullable', 'string', 'min:4'],
        ], [
            'email.unique' => __('هذا البريد مستعمل في حساب آخر — اختر غيره.'),
        ]);
        User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'role' => $data['role'],
            'business_id' => $data['business_id'] ?? null,
            'status' => 'نشط',
            'avatar' => Demo::image('user' . uniqid()),
            'password' => \Illuminate\Support\Facades\Hash::make($data['password'] ?? 'password'),
        ]);
        \App\Support\Activity::log('created', 'أضاف مستخدمًا للمنصة: ' . $data['name']);

        return back()->with('toast', ['msg' => __('تم إضافة المستخدم بنجاح'), 'type' => 'success']);
    }

    public function update(Request $request, $id)
    {
        $user = User::findOrFail($id);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                Rule::unique('users', 'email')->ignore($user->id),
                Rule::when($request->input('role') === 'super_admin', [new PlatformEmailDomain]),
            ],
            'phone' => ['nullable', 'string', 'max:50'],
            'role' => ['required', 'string', 'max:50'],
            /*
             * كلمة المرور لم يكن لها حقلٌ هنا ولا سطرٌ في التحقّق.
             *
             * فيفتح المشغّل «تعديل بيانات المستخدم» ليصلح حساب من فقد كلمته،
             * فلا يجد إلا الاسم والبريد والدور — ولا مخرج إلا فتح القاعدة.
             * والفارغ يعني «لا تغيّرها»، وإلا صار كل تعديلٍ لدورٍ أو هاتفٍ
             * يطالب بكلمةٍ جديدة فتُخترع واحدة ويخرج صاحبها من حسابه.
             */
            'password' => ['nullable', 'string', 'min:8'],
        ], [
            'email.unique' => __('هذا البريد مستعمل في حساب آخر — اختر غيره.'),
        ]);

        $password = $data['password'] ?? null;
        unset($data['password']);

        $user->update($data);

        if (filled($password)) {
            $user->password = $password; // cast hashed
            $user->save();
            \App\Support\Activity::log('updated', 'غيّر كلمة مرور المستخدم: ' . $user->name, ['subject_id' => $user->id]);
        }
        \App\Support\Activity::log('updated', 'عدّل بيانات المستخدم: ' . $user->name, ['subject_id' => $user->id]);

        return back()->with('toast', ['msg' => __('تم تحديث بيانات المستخدم'), 'type' => 'success']);
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
