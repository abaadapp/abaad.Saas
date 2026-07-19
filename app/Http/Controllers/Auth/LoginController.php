<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    /** حسابات الدخول التجريبي السريع */
    private array $demoAccounts = [
        'super-admin' => 'super@abadpos.com',
        'admin' => 'admin@abadpos.com',
        'pos' => 'cashier@abadpos.com',
    ];

    /** محاولة تسجيل الدخول العادية */
    public function attempt(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        $remember = $request->boolean('remember');

        if (! Auth::attempt($credentials, $remember)) {
            throw ValidationException::withMessages([
                'email' => 'بيانات الدخول غير صحيحة.',
            ]);
        }

        $request->session()->regenerate();
        $this->markLogin(Auth::user());

        return redirect()->intended($this->homeFor(Auth::user()));
    }

    /** دخول تجريبي سريع بدور محدّد */
    public function demo(Request $request, string $role)
    {
        $email = $this->demoAccounts[$role] ?? null;
        $user = $email ? User::where('email', $email)->first() : null;

        if (! $user) {
            return redirect()->route('login')->withErrors(['email' => 'الحساب التجريبي غير متوفر.']);
        }

        Auth::login($user);
        $request->session()->regenerate();
        $this->markLogin($user);

        return redirect($this->homeFor($user));
    }

    /** تسجيل الخروج */
    public function logout(Request $request)
    {
        \App\Support\Activity::log('logout', 'سجّل الخروج من النظام');
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function markLogin(User $user): void
    {
        $user->forceFill(['last_login_at' => now()])->save();
        \App\Support\Activity::log('login', 'سجّل الدخول إلى النظام');
    }

    /** الصفحة الرئيسية حسب الدور */
    private function homeFor(User $user): string
    {
        return match (true) {
            $user->isSuperAdmin() => route('super-admin.dashboard'),
            $user->isAdmin() => route('admin.dashboard'),
            default => route('pos.index'),
        };
    }
}
