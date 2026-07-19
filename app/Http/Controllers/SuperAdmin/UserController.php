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
}
