<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Demo;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $q = User::with('business');

        if ($s = trim((string) $request->query('q'))) {
            $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%")->orWhere('phone', 'like', "%{$s}%"));
        }
        if ($r = $request->query('role')) { $q->where('role', $r); }
        if ($st = $request->query('status')) { $q->where('status', $st); }

        $users = $q->orderByDesc('id')->paginate(10)->withQueryString()->through(fn ($u) => [
            'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'phone' => $u->phone,
            'business' => $u->business?->name ?? 'المنصة', 'role' => $u->roleLabel(),
            'status' => $u->status, 'last_login' => optional($u->last_login_at)->format('Y-m-d H:i') ?? '—',
            'avatar' => $u->avatar ?? Demo::image('user' . $u->id, 100, 100),
        ]);

        return view('super-admin.users.index', ['users' => $users, 'filters' => $request->only('q', 'role', 'status')]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:50'],
            'role' => ['required', 'string', 'max:50'],
            'business_id' => ['nullable', 'integer', 'exists:businesses,id'],
            'password' => ['nullable', 'string', 'min:4'],
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

        return back()->with('toast', ['msg' => 'تم إضافة المستخدم بنجاح', 'type' => 'success']);
    }

    public function update(Request $request, $id)
    {
        $user = User::findOrFail($id);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', \Illuminate\Validation\Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:50'],
            'role' => ['required', 'string', 'max:50'],
        ]);
        $user->update($data);
        \App\Support\Activity::log('updated', 'عدّل بيانات المستخدم: ' . $user->name, ['subject_id' => $user->id]);

        return back()->with('toast', ['msg' => 'تم تحديث بيانات المستخدم', 'type' => 'success']);
    }

    public function toggleStatus($id)
    {
        $user = User::findOrFail($id);
        if ($user->id === auth()->id()) {
            return back()->with('toast', ['msg' => 'لا يمكنك تعطيل حسابك الخاص', 'type' => 'error']);
        }
        $user->status = $user->status === 'نشط' ? 'موقوف' : 'نشط';
        $user->save();
        $on = $user->status === 'نشط';
        \App\Support\Activity::log('status', ($on ? 'فعّل' : 'أوقف') . ' حساب المستخدم: ' . $user->name, ['subject_id' => $user->id]);

        return back()->with('toast', ['msg' => $on ? 'تم تفعيل الحساب' : 'تم إيقاف الحساب', 'type' => $on ? 'success' : 'warning']);
    }
}
