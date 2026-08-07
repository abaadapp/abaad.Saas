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

        /*
         * الحدّ نفسه الذي يحرس رمز الكاشير — ومن باب أخطر.
         *
         * كان دخول الرمز محروسًا بحدَّين وقفل، ودخول البريد بلا حدّ إطلاقًا:
         * فمن يجرّب كلمات المرور على شاشة الرمز يُقفل بعد خمس، ومن يجرّبها
         * على هذه الشاشة يجرّب ما شاء إلى الأبد. وخلف هذا الباب حساب مدير
         * المنصة، لا درج صندوقٍ واحد.
         *
         * والمفتاح بريدٌ وعنوان معًا: العنوان وحده يوقف مكتبًا كاملًا خلف
         * موجّهٍ واحد لأن موظفًا أخطأ، والبريد وحده يجعل من يملك آلاف
         * العناوين يقصف حسابًا بعينه بلا حساب.
         */
        $key = 'login:'.mb_strtolower($credentials['email']).'|'.$request->ip();
        $slowKey = 'login-hour:'.mb_strtolower($credentials['email']).'|'.$request->ip();

        foreach ([[$key, 5], [$slowKey, 20]] as [$k, $max]) {
            if (RateLimiter::tooManyAttempts($k, $max)) {
                throw ValidationException::withMessages([
                    'email' => __('محاولات كثيرة. حاول بعد :seconds ثانية.', [
                        'seconds' => RateLimiter::availableIn($k),
                    ]),
                ]);
            }
        }

        if (! Auth::attempt($credentials, $remember)) {
            RateLimiter::hit($key, 60);
            RateLimiter::hit($slowKey, 3600);

            // يُسجَّل الفشل بلا كلمة المرور — والبريد يبقى ليُعرف الحسابُ المستهدف
            \App\Support\Activity::log('login_failed', 'محاولة دخول فاشلة — '.$credentials['email']);

            throw ValidationException::withMessages([
                'email' => __('بيانات الدخول غير صحيحة.'),
            ]);
        }

        RateLimiter::clear($key);
        RateLimiter::clear($slowKey);

        $this->refuseBlocked(Auth::user(), 'email');

        $request->session()->regenerate();
        $this->markLogin(Auth::user());

        // يوم التركيب يدخل صاحب المتجر ببريده على جهاز الصندوق، فيتذكّره
        // الجهاز ويعمل الكاشير بالرمز وحده بعدها — انظر PosTerminal
        \App\Support\PosTerminal::rememberBusiness(Auth::user()->business_id);

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

        $device = \App\Support\PosTerminal::current();

        return \Inertia\Inertia::render('Auth/Pin', [
            /*
             * ما يقف عليه الموظف: متجره وفرعه وصندوقه.
             *
             * صار الرمز فريدًا داخل المتجر ومقيَّدًا بفرع الجهاز، فمن الواجب
             * أن يرى الكاشير أين يدخل: جهازٌ فُعّل على الفرع الخطأ يوم التركيب
             * يبقى صامتًا حتى يقف موظفٌ أمام شاشةٍ ترفض رمزه الصحيح ولا يفهم
             * لماذا.
             */
            'deviceBusiness' => \App\Support\PosTerminal::businessName(),
            'deviceBranch' => $device?->branch?->name,
            'deviceName' => $device?->name,
        ]);
    }

    /**
     * دخول الموظف برمز من ٤ أرقام — بلا بريد أو كلمة مرور.
     *
     * المصدر الموثوق للمتجر والفرع هو الجهاز على الخادم، لا شيء يصل من
     * المتصفّح. الموظف يعرف رمزه وحده.
     */
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
        $device = \App\Support\PosTerminal::current();
        $businessId = \App\Support\PosTerminal::businessId();

        if (! $businessId) {
            throw ValidationException::withMessages([
                'pin' => __('هذا الجهاز غير مفعَّل. اطلب من المدير تفعيله من «فتح نقطة البيع».'),
            ]);
        }

        /*
         * حدّان لا واحد: ٥ في الدقيقة، و٣٠ في الساعة.
         *
         * الحدّ الدقيقيّ وحده يُبطئ ولا يمنع — من يصبر يجرّب سبعة آلاف رمز
         * في اليوم، وهو أكثر من فضاء الرموز كلّه. والحدّ الساعيّ يجعل مسحَ
         * الفضاء يستغرق سنة.
         *
         * والمفتاح يضمّ الجهاز إلى العنوان: محلٌّ بثلاثة صناديق خلف موجّهٍ
         * واحد كان صندوقٌ فيه يستهلك حصّة إخوته، فيقف البيع في المحل كلّه
         * لأن كاشيرًا واحدًا أخطأ خمس مرّات.
         */
        $who = $device ? 'dev'.$device->id : 'ip'.$request->ip();
        $key = 'pin-login:'.$who;
        $slowKey = 'pin-login-hour:'.$who;

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

        /*
         * الرفض واحدٌ لكل الأسباب: رمزٌ خاطئ، أو موظف موقوف، أو ممنوع من
         * هذا الفرع.
         *
         * التمييز بينها يقول لمن يجرّب الأرقام «هذا الرمز صحيح لكن…» —
         * فيعرف أنه أصاب رمزًا ويكمل على فرعٍ آخر. والرسالة الواحدة تكلّف
         * الموظفَ الصادق سؤالًا لمديره، وتكلّف المخمّن كل شيء.
         */
        $branchId = $device?->branch_id;
        $allowed = $user
            && (! $device || $user->worksAt($branchId))
            && \App\Support\Tenancy::blockReason($user) === null;

        if (! $allowed) {
            RateLimiter::hit($key, 60);
            RateLimiter::hit($slowKey, 3600);

            // يُسجَّل الفشل بلا الرمز نفسه ولا اسم من طابقه
            \App\Support\Activity::log('login_failed', 'محاولة دخول برمز فاشلة'
                .($device ? ' — جهاز: '.$device->name : ''), [
                    'business_id' => $businessId,
                ]);

            throw ValidationException::withMessages([
                'pin' => __('رمز غير صحيح أو غير مسموح في هذا الفرع.'),
            ]);
        }

        RateLimiter::clear($key);
        RateLimiter::clear($slowKey);
        Auth::login($user);
        $request->session()->regenerate();
        $this->markLogin($user);

        /*
         * فرع الجهاز يُفرض على الجلسة، ولا يُقرأ من مبدّل الفروع.
         *
         * كان الكاشير يرث الفرع الذي اختاره المدير في تبويبٍ آخر — أو «كل
         * الفروع» فيسقط على أوّل فرعٍ في القائمة. فتُسجَّل مبيعات الخوير على
         * السيب، ولا يُكتشف إلا عند جرد آخر الشهر.
         */
        if ($device) {
            session(['current_branch' => $device->branch_id]);
            \App\Support\PosTerminal::touch($device);
        }

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
        \App\Support\PosTerminal::rememberBusiness($user->business_id);

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
