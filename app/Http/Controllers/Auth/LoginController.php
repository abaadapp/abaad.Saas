<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Activity;
use App\Support\Mailer;
use App\Support\Permissions;
use App\Support\PosTerminal;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

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
         * حدّان لا واحد: خمسٌ في الدقيقة، وعشرون في الساعة.
         *
         * وهو الباب الوحيد الآن، فلا يُترك بلا حدّ: من يجرّب كلمات المرور
         * يجرّب ما شاء إلى الأبد، وخلف هذا الباب حساب مدير المنصة لا درج
         * صندوقٍ واحد.
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
            Activity::log('login_failed', 'محاولة دخول فاشلة — '.$credentials['email']);

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
        // الجهاز ويعرف فرعَه بعدها — انظر PosTerminal
        PosTerminal::rememberBusiness(Auth::user()->business_id);

        return redirect()->intended($this->homeFor(Auth::user()));
    }

    /**
     * يُنهي الجلسة ويرفض الدخول إن كان الحساب أو متجره أو اشتراكه موقوفًا.
     *
     * كان الباب مفتوحًا: `Auth::attempt` لا تقرأ حالة الحساب — فموظفٌ نشطٌ
     * في متجرٍ معطَّل انتهى اشتراكه منذ أشهر كان يدخل ويبيع.
     *
     * والمنع عند الباب لا يكفي وحده: حارسُ الطلب (CheckTenantStatus) يقطع
     * جلسةً فُتحت قبل الإيقاف. وهذا يمنع فتح واحدةٍ جديدة.
     */
    private function refuseBlocked(?User $user, string $field): void
    {
        $reason = Tenancy::blockReason($user);
        if (! $reason) {
            return;
        }

        /*
         * منتهي الاشتراك يدخل: حارسُ الطلب يسوقه إلى صفحة التجديد ولا يدعه
         * يتجاوزها. وردُّه هنا برسالةٍ في حقل البريد كان يجعله يعيد كتابة
         * كلمة المرور ظنًّا أنه أخطأها.
         */
        if (! Tenancy::isHard($reason)) {
            return;
        }

        Auth::logout();

        throw ValidationException::withMessages([
            $field => Tenancy::message($reason),
        ]);
    }

    /**
     * شاشة الدخول — أول ما يراه المستخدم: بريد وكلمة مرور، لا غير.
     *
     * كان لها بابٌ ثانٍ: أربعة أرقامٍ يدخل بها الكاشير بلا بريدٍ ولا كلمة
     * مرور، ويُفتح تبويبه افتراضيًّا على كل جهازٍ سبق أن دخل منه أحد. فرُفع
     * الباب كلّه: فضاءُ الرموز عشرة آلاف لا غير، وما يُفتح بأربعة أرقام
     * ليس حسابًا. من كان يدخل برمزه يدخل الآن ببريده وكلمة مروره.
     *
     * وهوية الجهاز تبقى: هي مصدر الفرع في نقطة البيع، واسم المتجر فوق
     * البطاقة يقول للواقف أمامها أين هو — انظر PosTerminal.
     */
    public function showLogin(): Response
    {
        $device = PosTerminal::current();

        return Inertia::render('Auth/Login', [
            /*
             * كتلةٌ واحدة لا حقول متفرّقة: وجودها هو الإذن بعرض اسم المتجر
             * وبابِ نسيانه، فلا تعرض الواجهة اسمًا بلا متجرٍ خلفه.
             */
            'device' => PosTerminal::remembered() ? [
                'business' => PosTerminal::businessName(),
                'branch' => $device?->branch?->name,
                'device' => $device?->name,
                /*
                 * جهازٌ مفعَّل أم متجرٌ متذكَّر وحسب؟
                 *
                 * الفرق ثمن النسيان: كوكي المتجر تُكتب من جديد عند أي دخولٍ
                 * بالبريد، أما الجهاز المفعَّل فيحمل فرعَه — ونسيانه يحتاج
                 * مديرًا يعيد تفعيله من «فتح نقطة البيع». والواجهة تُحذّر
                 * بحسب ذلك بدل أن تُسوّي بينهما.
                 */
                'activated' => $device !== null,
            ] : null,
            'year' => (int) now()->format('Y'),
            // بابٌ لا يفتح يُخفى: بلا بريدٍ مضبوط تقول شاشة الاستعادة
            // «أرسلنا الرابط» ولا تُرسل، فينتظر المستخدم رسالةً لن تأتي
            'canRecover' => Mailer::configured(),
        ]);
    }

    /**
     * ينسى هذا المتصفّح متجرَه — المخرج من شاشةٍ مقفلة على متجرٍ واحد.
     *
     * صارت شاشة الدخول تتذكّر المتجر وتعرض اسمه، فلزمها بابُ خروج: جهازٌ
     * بيع، أو نُقل إلى محلٍّ آخر، أو رُبط يوم التركيب بالمتجر الخطأ. وبلا هذا
     * لا حيلة إلا مسح كوكي المتصفّح يدويًّا — وهو ما لا يعرفه صاحب المحل.
     *
     * ولا حارس عليه عمدًا: لا يمسّ إلا كوكي الطالب نفسه، ولا يُلغي تفعيل
     * الجهاز في السجلّ. من يستطيع مسح كوكيّاته من المتصفّح يستطيع هذا.
     * والإلغاء الحقيقي (إبطال الجهاز في القاعدة) يبقى في الإعدادات خلف
     * صلاحيته.
     */
    public function forgetDevice(Request $request)
    {
        PosTerminal::forget();

        return redirect()->route('login')->with('toast', [
            'msg' => __('نُسي هذا الجهاز. سجّل الدخول بالبريد لربطه من جديد.'),
            'type' => 'info',
        ]);
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
        PosTerminal::rememberBusiness($user->business_id);

        return redirect($this->homeFor($user));
    }

    /**
     * تسجيل الخروج — إلى شاشة الدخول دائمًا.
     *
     * كان الخروج بسبب الخمول (`?to=pin`) يعيد الموظف إلى لوحة الأرقام. ولمّا
     * رُفع الدخول بالرمز صار البابُ واحدًا، فلا وجهة إلا هو.
     */
    public function logout(Request $request)
    {
        $idle = $request->query('to') === 'pin';

        Activity::log('logout', $idle ? 'خروج تلقائي بسبب الخمول' : 'سجّل الخروج من النظام', ['self' => true]);
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function markLogin(User $user): void
    {
        $user->forceFill(['last_login_at' => now()])->save();
        Activity::log('login', 'سجّل الدخول إلى النظام', ['self' => true]);
    }

    /** الصفحة الرئيسية حسب ما يملكه المستخدم فعلًا لا حسب دوره */
    private function homeFor(User $user): string
    {
        return Permissions::homeFor($user);
    }
}
