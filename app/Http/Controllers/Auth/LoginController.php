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

        $this->refuseBlocked(Auth::user(), 'email');

        $request->session()->regenerate();
        $this->markLogin(Auth::user());

        // يوم التركيب يدخل صاحب المتجر ببريده على جهاز الصندوق، فيتذكّره
        // الجهاز ويعمل الكاشير بالرمز وحده بعدها — انظر PosDevice
        \App\Support\PosDevice::remember(Auth::user()->business_id);

        return redirect()->intended($this->homeFor(Auth::user()));
    }

    /**
     * يُنهي الجلسة ويرفض الدخول إن كان الحساب أو متجره أو اشتراكه موقوفًا.
     *
     * كان الباب مفتوحًا كلّه: `Auth::attempt` لا تقرأ حالة الحساب، ودخولُ
     * الرمز يقرأ حالة الموظف وحده — فموظفٌ نشطٌ في متجرٍ معطَّل انتهى
     * اشتراكه منذ أشهر كان يدخل ويبيع.
     *
     * والمنع عند الباب لا يكفي وحده: حارسُ الطلب (CheckTenantStatus) يقطع
     * جلسةً فُتحت قبل الإيقاف. وهذا يمنع فتح واحدةٍ جديدة.
     */
    private function refuseBlocked(?User $user, string $field): void
    {
        $reason = \App\Support\Tenancy::blockReason($user);
        if (! $reason) {
            return;
        }

        Auth::logout();

        throw ValidationException::withMessages([
            $field => \App\Support\Tenancy::message($reason),
        ]);
    }

    /** شاشة الدخول بالبريد وكلمة المرور — أول ما يراه المستخدم */
    public function showLogin(): \Inertia\Response
    {
        return \Inertia\Inertia::render('Auth/Login', [
            // رمز الموظف مسار حقيقي لا زخرفة؛ يُمرَّر جاهزًا فلا تبنيه الواجهة
            'pinUrl' => route('pin.form'),
            'year' => (int) now()->format('Y'),
        ]);
    }

    /** شاشة الدخول بالرمز (لوحة أرقام) — بالإنجليزية دائمًا */
    public function pinForm(): \Inertia\Response
    {
        app()->setLocale('en');

        return \Inertia\Inertia::render('Auth/Pin', [
            /*
             * اسم المتجر الذي يقرأ هذا الجهازُ رموزَه.
             *
             * صار الرمز فريدًا داخل المتجر، فمن الواجب أن يرى الكاشير أين
             * يدخل: جهازٌ رُبط بالمتجر الخطأ يوم التركيب يبقى صامتًا حتى
             * يقف موظفٌ أمام شاشةٍ ترفض رمزه الصحيح ولا يفهم لماذا.
             */
            'deviceBusiness' => \App\Support\PosDevice::name(),
        ]);
    }

    /** دخول الموظف برمز من ٤ أرقام — بلا بريد أو كلمة مرور */
    public function pinAttempt(Request $request)
    {
        app()->setLocale('en');

        $data = $request->validate([
            'pin' => ['required', 'digits:4'],
        ], [
            'pin.required' => __('أدخل رمز الدخول.'),
            'pin.digits' => __('رمز الدخول يجب أن يكون 4 أرقام.'),
        ]);

        /*
         * الرمز يُقرأ داخل متجر هذا الجهاز وحده.
         *
         * صار «1234» ممكنًا في متجرين، فالبحث في المستخدمين كلّهم كان
         * سيُدخل صاحبَ الرمز أيًّا كان متجره — وهو أسوأ من العطب الذي
         * أصلحناه: دخولٌ إلى متجرٍ غير متجرك.
         */
        $businessId = \App\Support\PosDevice::businessId();
        if (! $businessId) {
            throw ValidationException::withMessages([
                'pin' => __('هذا الجهاز غير مرتبط بمتجر. ادخل مرّةً بالبريد وكلمة المرور أولًا.'),
            ]);
        }

        /*
         * حدّان لا واحد: ٥ في الدقيقة، و٣٠ في الساعة.
         *
         * الحدّ الدقيقيّ وحده يُبطئ ولا يمنع — من يصبر يجرّب سبعة آلاف رمز
         * في اليوم، وهو أكثر من فضاء الرموز كلّه. والحدّ الساعيّ يجعل مسحَ
         * الفضاء يستغرق سنة.
         */
        $key = 'pin-login:' . $request->ip();
        $slowKey = 'pin-login-hour:' . $request->ip();

        foreach ([[$key, 5], [$slowKey, 30]] as [$k, $max]) {
            if (RateLimiter::tooManyAttempts($k, $max)) {
                throw ValidationException::withMessages([
                    'pin' => __('محاولات كثيرة. حاول بعد :seconds ثانية.', [
                        'seconds' => RateLimiter::availableIn($k),
                    ]),
                ]);
            }
        }

        // البحث عن مستخدم يطابق رمزه المُجزّأ داخل متجر الجهاز
        $user = User::where('business_id', $businessId)->whereNotNull('pin')->get()
            ->first(fn ($u) => Hash::check($data['pin'], $u->getRawOriginal('pin')));

        if (! $user) {
            RateLimiter::hit($key, 60);
            RateLimiter::hit($slowKey, 3600);
            throw ValidationException::withMessages([
                'pin' => __('رمز الدخول غير صحيح.'),
            ]);
        }

        $this->refuseBlocked($user, 'pin');

        RateLimiter::clear($key);
        RateLimiter::clear($slowKey);
        Auth::login($user);
        $request->session()->regenerate();
        $this->markLogin($user);

        return redirect($this->homeFor($user));
    }

    /** دخول تجريبي سريع بدور محدّد — محليًا فقط */
    public function demo(Request $request, string $role)
    {
        // حارس ثانٍ إلى جانب حارس التسجيل في routes/web.php:
        // لو أُعيد تسجيل المسار يومًا بغير قصد، يبقى الباب مقفلًا.
        abort_unless(config('app.demo_login'), 404);

        $email = $this->demoAccounts[$role] ?? null;
        $user = $email ? User::where('email', $email)->first() : null;

        if (! $user) {
            return redirect()->route('login')->withErrors(['email' => __('الحساب التجريبي غير متوفر.')]);
        }

        $this->refuseBlocked($user, 'email');

        Auth::login($user);
        $request->session()->regenerate();
        $this->markLogin($user);
        \App\Support\PosDevice::remember($user->business_id);

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

    /** الصفحة الرئيسية حسب ما يملكه المستخدم فعلًا لا حسب دوره */
    private function homeFor(User $user): string
    {
        return \App\Support\Permissions::homeFor($user);
    }
}
