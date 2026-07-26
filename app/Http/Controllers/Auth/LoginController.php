<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
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
                'email' => __('بيانات الدخول غير صحيحة.'),
            ]);
        }

        $request->session()->regenerate();
        $this->markLogin(Auth::user());

        return redirect()->intended($this->homeFor(Auth::user()));
    }

    /** شاشة الدخول بالرمز (لوحة أرقام) — بالإنجليزية دائمًا */
    public function pinForm()
    {
        app()->setLocale('en');

        return view('auth.pin');
    }

    /** دخول الموظف برمز من ٤ أرقام — بلا بريد أو كلمة مرور */
    public function pinAttempt(Request $request)
    {
        app()->setLocale('en');

        $data = $request->validate([
            'pin' => ['required', 'digits:4'],
        ], [
            'pin.required' => __('أدخل رمز الدخول.'),
            'pin.digits' => __('رمز الدخول يجب أن يكون ٤ أرقام.'),
        ]);

        // تحديد المعدل: ٥ محاولات لكل عنوان IP في الدقيقة
        $key = 'pin-login:' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            throw ValidationException::withMessages([
                'pin' => __('محاولات كثيرة. حاول بعد :seconds ثانية.', ['seconds' => $seconds]),
            ]);
        }

        // البحث عن مستخدم يطابق رمزه المُجزّأ (بين من لديهم رمز فقط)
        $user = User::whereNotNull('pin')->get()
            ->first(fn ($u) => Hash::check($data['pin'], $u->getRawOriginal('pin')));

        if (! $user) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages([
                'pin' => __('رمز الدخول غير صحيح.'),
            ]);
        }

        if ($user->status !== 'نشط') {
            throw ValidationException::withMessages([
                'pin' => __('هذا الحساب معطّل. راجع صاحب النشاط.'),
            ]);
        }

        RateLimiter::clear($key);
        Auth::login($user);
        $request->session()->regenerate();
        $this->markLogin($user);

        return redirect($this->homeFor($user));
    }

    /** دخول تجريبي سريع بدور محدّد */
    public function demo(Request $request, string $role)
    {
        $email = $this->demoAccounts[$role] ?? null;
        $user = $email ? User::where('email', $email)->first() : null;

        if (! $user) {
            return redirect()->route('login')->withErrors(['email' => __('الحساب التجريبي غير متوفر.')]);
        }

        Auth::login($user);
        $request->session()->regenerate();
        $this->markLogin($user);

        return redirect($this->homeFor($user));
    }

    /** تسجيل الخروج */
    public function logout(Request $request)
    {
        // خروج بسبب الخمول → يعود الموظف لشاشة الرمز مباشرةً
        $toPin = $request->query('to') === 'pin';

        \App\Support\Activity::log('logout', $toPin ? 'خروج تلقائي بسبب الخمول' : 'سجّل الخروج من النظام');
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route($toPin ? 'pin.form' : 'login');
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
