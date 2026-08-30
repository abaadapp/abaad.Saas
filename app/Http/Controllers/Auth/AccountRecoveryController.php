<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\RecoveryOtpMail;
use App\Models\PasswordRecoveryChallenge;
use App\Models\PasswordRecoveryOtp;
use App\Models\User;
use App\Support\Activity;
use App\Support\Mailer;
use App\Support\RecoveryEmail;
use App\Support\RecoveryOtp;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

/**
 * استعادة الحساب برمزٍ إلى بريدٍ **مختوم**.
 *
 * والختم هو كلّ الفرق. الطريق الساذج — «اكتب اسم دخولك، ثمّ اكتب أيّ بريد،
 * نرسل إليه رمزًا، غيّر كلمة المرور» — لا يُثبت شيئًا عن المتجر: يُثبت أنّ
 * صاحب الطلب يملك صندوق بريدٍ كتبه هو قبل ثانية. فمن عرف اسم دخول محلٍّ
 * أخذه.
 *
 * فلا يُقبل هنا عنوانٌ يكتبه الطالب أبدًا. الرمز يذهب إلى العنوان المختوم
 * المحفوظ سلفًا، أو لا يذهب.
 *
 * ------------------------------------------------------------------------
 *
 * وما لا بريد مختوم له لا يستعيد نفسه — ويُحال إلى إدارة أبعاد.
 *
 * ولمَ لا يُطلَب منه رقمُ هاتفه المسجَّل بدلًا من ذلك؟ لأنّ مطابقة رقمٍ
 * مخزَّن ليست إثباتًا: لا رسائل نصّية في هذا النظام، فالرقم لا يُنادى — وكلُّ
 * ما يُثبته أنّ الطالب يعرف رقمًا، وهو ما يعرفه كلّ من رأى فاتورةً من المحلّ
 * أو صفحته. أمنٌ يبدو أمنًا وليس به هو أسوأ من لا شيء: لا شيءٌ يُحيل إلى
 * إنسانٍ يتحقّق، والشكليّ يفتح الباب ويقول إنّه أُقفل.
 */
class AccountRecoveryController extends Controller
{
    /* ------------------------------ الشاشات ------------------------------ */

    private function abortWithoutMail(): void
    {
        abort_if(
            ! Mailer::configured(),
            404,
            __('استعادة كلمة المرور غير مفعَّلة على هذا النظام — تواصل مع مدير النظام.'),
        );
    }

    /**
     * الجواب الواحد.
     *
     * لا يُفرَّق بين «لا حساب بهذا الاسم» و«حسابٌ موقوف» و«حسابٌ بلا بريد
     * مختوم»: كلُّ فرقٍ في الردّ يجعل هذه الشاشة أداةَ جردٍ لحسابات المنصّة —
     * يُجرَّب الاسم فيُعرف أموجودٌ هو، ثمّ يُنتقل به إلى شاشة الدخول.
     *
     * والثمن دقيقةُ حَيرةٍ على صاحبٍ أخطأ في كتابة اسمه؛ والبديل خريطةُ
     * حساباتٍ لمن يستكشف.
     */
    private const GENERIC = 'إن كان هذا الحساب مسجّلًا ولديه بريد استعادة موثّق، فسيصلك رمز التحقق خلال دقائق. وإن لم يكن، تواصل مع إدارة أبعاد لتفعيل وسيلة الاستعادة.';

    /* ------------------------------ البداية ------------------------------ */

    /**
     * الخطوة الأولى: اسم الدخول وحده.
     *
     * ولا يُقبل معه بريدٌ ولا معرّف متجر — الخادم يستخرج الحساب بنفسه.
     */
    public function start(Request $request)
    {
        $this->abortWithoutMail();

        $data = $request->validate([
            'email' => ['required', 'string', 'max:150'],
        ]);

        $identifier = mb_strtolower(trim($data['email']));

        /*
         * الحدّ على الطلب لا على الحساب.
         *
         * ومفتاحه العنوانُ المكتوب مع رقم الشبكة معًا: لو كان الحساب وحده
         * لَاستطاع من يعرف اسم دخول محلٍّ أن يُقفل عليه بابَ الاستعادة بطلباتٍ
         * متتابعة — منعٌ للخدمة يُنفَّذ من شاشةٍ عامّة.
         */
        $this->throttle('recovery:start:'.$identifier.'|'.$request->ip(), 5, 900);

        $user = $this->findAccount($identifier);
        [$target, $purpose] = $this->targetFor($user);

        if ($user && $target) {
            $challenge = RecoveryOtp::openChallenge($user);
            $this->dispatchOtp($challenge, $purpose, $target);

            Activity::log('login_failed', 'بدأ استعادة كلمة المرور: '.$user->email, [
                'business_id' => $user->business_id,
                'icon' => 'key-round',
                'color' => 'warning',
            ]);

            return redirect()->route('recovery.verify', ['challenge' => $challenge->token]);
        }

        /*
         * لا حساب، أو حسابٌ موقوف، أو بلا بريد مختوم — جوابٌ واحد.
         *
         * ويُقيَّد الثاني والثالث ليراهما الدعم: تاجرٌ ينتظر رسالةً لا تُرسل
         * ولا أحد يعرف لماذا هو أسوأ ما في هذا الباب.
         */
        if ($user) {
            Activity::log('login_failed', 'طلب استعادة تعذّر: '.$user->email.' — '
                .($this->blocked($user) ? 'الحساب موقوف' : 'لا بريد استعادة موثّق'), [
                    'business_id' => $user->business_id,
                    'icon' => 'mail',
                    'color' => 'warning',
                ]);
        }

        return back()->with('status', __(self::GENERIC));
    }

    /* ------------------------------ التحقّق ------------------------------ */

    /** شاشة إدخال الرمز — لا حقل بريدٍ فيها البتّة */
    public function verify(Request $request, string $challenge)
    {
        $this->abortWithoutMail();

        $model = RecoveryOtp::resolve($challenge);

        if (! $model) {
            return redirect()->route('password.request')
                ->with('status', __('انتهت مهلة المحاولة. ابدأ من جديد.'));
        }

        $otp = $this->pendingOtp($model);

        return \Inertia\Inertia::render('Auth/VerifyRecoveryCode', [
            'challenge' => $model->token,
            // مُقنَّعًا ليطمئنّ صاحبه أنّ الرمز في صندوقه هو — وبلا كشفٍ لعنوانه
            'masked' => RecoveryEmail::mask($otp?->target_email),
            'cooldown' => (int) config('recovery.resend_cooldown', 60),
            'year' => (int) now()->format('Y'),
        ]);
    }

    /** يقارن الرمز — وينشئ الإذن إن طابق */
    public function check(Request $request)
    {
        $this->abortWithoutMail();

        $data = $request->validate([
            'challenge' => ['required', 'string'],
            'code' => ['required', 'string', 'max:12'],
        ]);

        $challenge = RecoveryOtp::resolve($data['challenge']);

        if (! $challenge) {
            throw ValidationException::withMessages(['code' => __('انتهت مهلة المحاولة. ابدأ من جديد.')]);
        }

        $this->throttle('recovery:check:'.$challenge->id.'|'.$request->ip(), 10, 900, 'code');

        $otp = $this->pendingOtp($challenge);

        if (! $otp || ! RecoveryOtp::check($otp, $data['code'])) {
            throw ValidationException::withMessages([
                'code' => __('الرمز غير صحيح أو منتهي الصلاحية.'),
            ]);
        }

        /*
         * رمزُ توثيق: يُختم العنوان الآن.
         *
         * والعنوان يُقرأ من صفّ الرمز لا من الطلب — لا حقل بريدٍ في هذا
         * المسار أصلًا، ولو كان لَخُتم عنوانٌ لم يصل إليه شيء.
         */
        if ($otp->purpose === PasswordRecoveryOtp::PURPOSE_EMAIL_VERIFICATION) {
            $challenge->user->forceFill([
                'recovery_email' => $otp->target_email,
                'recovery_email_verified_at' => now(),
            ])->save();

            Activity::log('settings', 'وثّق بريد الاستعادة عبر شاشة الاستعادة', [
                'business_id' => $challenge->business_id,
                'icon' => 'shield-check',
            ]);
        }

        /*
         * الإذن يُكتب في الخادم لا يُعطى للمتصفّح.
         *
         * ولو أُرسل إلى الشاشة `verified=true` لَكفى تعديلُه لتخطّي الرمز
         * كلّه. والمتصفّح يبقى يحمل رمز المحاولة المبهم نفسه، والخادم وحده
         * يعرف أنّه اجتاز.
         */
        $challenge->forceFill([
            'state' => PasswordRecoveryChallenge::AUTHORIZED,
            'verified_email_at' => now(),
            'authorized_at' => now(),
        ])->save();

        return redirect()->route('recovery.password', ['challenge' => $challenge->token]);
    }

    /** إعادة الإرسال — بمهلةٍ في الخادم لا بعدّادٍ في الشاشة */
    public function resend(Request $request)
    {
        $this->abortWithoutMail();

        $data = $request->validate(['challenge' => ['required', 'string']]);
        $challenge = RecoveryOtp::resolve($data['challenge']);

        if (! $challenge) {
            throw ValidationException::withMessages(['code' => __('انتهت مهلة المحاولة. ابدأ من جديد.')]);
        }

        $previous = $this->pendingOtp($challenge);
        $cooldown = (int) config('recovery.resend_cooldown', 60);

        /*
         * المهلة تُقاس من زمن آخر رمزٍ في القاعدة.
         *
         * والعدّاد في الشاشة زينةٌ لا حارس: من يُغلق الصفحة ويفتحها يبدأ
         * عدّادًا جديدًا — والقاعدة لا تنسى.
         */
        if ($previous && $previous->created_at->addSeconds($cooldown)->isFuture()) {
            throw ValidationException::withMessages([
                'code' => __('انتظر :seconds ثانية قبل طلب رمز جديد.', [
                    'seconds' => (int) ceil(now()->diffInSeconds($previous->created_at->addSeconds($cooldown), false)),
                ]),
            ]);
        }

        // وحدٌّ بالساعة فوق المهلة: المهلة تمنع التتابع، وهذا يمنع الإلحاح
        $this->throttle('recovery:resend:'.$challenge->id, 5, 3600, 'code');

        [$fallback, $fallbackPurpose] = $this->targetFor($challenge->user);
        $target = $previous?->target_email ?: $fallback;
        $purpose = $previous?->purpose ?: $fallbackPurpose;

        if (! $target) {
            throw ValidationException::withMessages(['code' => __('انتهت مهلة المحاولة. ابدأ من جديد.')]);
        }

        $this->dispatchOtp($challenge, $purpose, $target);

        return back()->with('status', __('أرسلنا رمزًا جديدًا. الرمز السابق لم يعد صالحًا.'));
    }

    /* ------------------------- كلمة المرور الجديدة ------------------------- */

    public function password(Request $request, string $challenge)
    {
        $this->abortWithoutMail();

        $model = RecoveryOtp::resolve($challenge);

        if (! $model || ! $model->canSetPassword()) {
            return redirect()->route('password.request')
                ->with('status', __('انتهت مهلة المحاولة. ابدأ من جديد.'));
        }

        return \Inertia\Inertia::render('Auth/ResetRecoveredPassword', [
            'challenge' => $model->token,
            'year' => (int) now()->format('Y'),
        ]);
    }

    /**
     * يحفظ كلمة المرور — ويُغلق كلّ ما بقي مفتوحًا.
     *
     * والإغلاق ليس تجميلًا: من نسي كلمته قد يكون فقد جهازه أو أخذها غيره.
     * فجلسةٌ قائمة على جهازٍ آخر تجعل الاستعادة إجراءً شكليًّا — الكلمة
     * تتغيّر ومن دخل بها لا يزال داخلًا.
     */
    public function store(Request $request)
    {
        $this->abortWithoutMail();

        $data = $request->validate([
            'challenge' => ['required', 'string'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ], [
            'password.confirmed' => __('كلمتا المرور غير متطابقتين.'),
            'password.min' => __('كلمة المرور ثمانية أحرف على الأقل.'),
        ]);

        $challenge = RecoveryOtp::resolve($data['challenge']);

        if (! $challenge || ! $challenge->canSetPassword()) {
            throw ValidationException::withMessages([
                'password' => __('انتهت مهلة المحاولة. ابدأ من جديد.'),
            ]);
        }

        $user = $challenge->user;

        DB::transaction(function () use ($challenge, $user, $data) {
            $user->forceFill([
                'password' => $data['password'],
                // بصمة «تذكّرني» تتبدّل فتسقط كوكيّات الأجهزة المحفوظة كلّها
                'remember_token' => Str::random(60),
            ])->save();

            self::closeSessions($user);

            // الإذن يُستهلك مرّةً واحدة — والمحاولة تُقفل بكلّ رموزها
            $challenge->forceFill([
                'state' => PasswordRecoveryChallenge::USED,
                'used_at' => now(),
            ])->save();

            PasswordRecoveryOtp::where('challenge_id', $challenge->id)
                ->whereNull('used_at')->update(['used_at' => now()]);
        });

        Activity::log('settings', 'أعاد تعيين كلمة المرور برمز التحقق', [
            'business_id' => $user->business_id,
            'icon' => 'key-round',
        ]);

        return redirect()->route('login')
            ->with('status', __('تم تغيير كلمة المرور. سجّل الدخول بها الآن.'));
    }

    /* ------------------------------ مشتركات ------------------------------ */

    /**
     * إغلاق جلسات المستخدم — حذفًا من الجدول لا وعدًا.
     *
     * وجهاز نقطة البيع لا يُمسّ: تفعيلُه كوكيّةٌ على الجهاز نفسه (انظر
     * `PosTerminal`) لا جلسةُ مستخدم. وإسقاطُه يعني كاشيرًا يقف أمام شاشة
     * إعدادٍ صباحًا لأنّ صاحب المحلّ نسي كلمته ليلًا.
     */
    public static function closeSessions(User $user): void
    {
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        }
    }

    /**
     * الحساب من اسم الدخول — والمحذوف لا يُبعث.
     *
     * `withoutTrashed` صريحةٌ هنا وإن كانت الافتراضيّة: هذا مسارٌ عامّ بلا
     * جلسة، ونطاقٌ عامّ يُنسى في تعديلٍ لاحق يفتح حسابًا حُذف.
     */
    private function findAccount(string $identifier): ?User
    {
        return User::withoutTrashed()
            ->with('business')
            ->whereRaw('lower(email) = ?', [$identifier])
            ->whereNotNull('password')
            ->first();
    }

    /** موقوفٌ أو معطَّل — والاشتراك المنتهي ليس منها */
    private function blocked(User $user): bool
    {
        $reason = Tenancy::blockReason($user);

        return $reason !== null && Tenancy::isHard($reason);
    }

    /**
     * إلى أين يُرسَل الرمز، وبأيّ غرض — أو لا شيء.
     *
     * حالتان لا ثالثة:
     *
     *  ١) بريدٌ **مختوم**: يُرسَل رمزُ تعيين كلمة مرور. هذا الطريق المعتاد.
     *
     *  ٢) بريدٌ مكتوبٌ غير مختوم **وضعه مدير المنصّة**: يُرسَل رمزُ توثيق.
     *     ومن اجتازه أثبت أنّه يقرأ الصندوق الذي أثبت صاحبَه إنسانٌ في أبعاد
     *     قبل أن يكتبه — فيُختم العنوان ويُؤذن له بتعيين كلمة مرور معًا.
     *     وهذه هي المرّة الواحدة التي يمرّ فيها المتجر القديم بإنسان.
     *
     * والعنوان في الحالتين محفوظٌ سلفًا — لا يكتبه الطالب أبدًا. ولو كتبه
     * لَأثبت ملكيّته لصندوقٍ أنشأه قبل ثانية، لا ملكيّته للمتجر.
     *
     * @return array{0: ?string, 1: string}
     */
    private function targetFor(?User $user): array
    {
        if (! $user || $this->blocked($user)) {
            return [null, PasswordRecoveryOtp::PURPOSE_PASSWORD_RESET];
        }

        if ($verified = RecoveryEmail::verifiedFor($user)) {
            return [$verified, PasswordRecoveryOtp::PURPOSE_PASSWORD_RESET];
        }

        $pending = RecoveryEmail::normalize($user->recovery_email);

        if ($pending !== null && ! RecoveryEmail::isInternal($pending)) {
            return [$pending, PasswordRecoveryOtp::PURPOSE_EMAIL_VERIFICATION];
        }

        return [null, PasswordRecoveryOtp::PURPOSE_PASSWORD_RESET];
    }

    /**
     * يُرسل الرمز — ولا يُسقط الطلب إن تعثّر البريد.
     *
     * خطأُ SMTP يحمل المضيف وأحيانًا اسم المستخدم؛ وعرضُه للطالب يُسرّب
     * إعداد الخادم ويقول له إنّ الحساب موجود. فيُبتلع ويُبلَّغ عنه، ويبقى
     * الجواب واحدًا.
     */
    private function dispatchOtp(PasswordRecoveryChallenge $challenge, string $purpose, string $target): void
    {
        $code = RecoveryOtp::issue($challenge, $purpose, $target);

        try {
            Mail::to($target)->send(new RecoveryOtpMail($code, $purpose));
        } catch (\Throwable $e) {
            // ولا يُكتب الرمز في البلاغ — السجلّ يُقرأ ويُنسخ ويُرسل
            report($e);
        }
    }

    /**
     * الرمز المعلَّق لهذه المحاولة — أيًّا كان غرضه.
     *
     * والغرض يُقرأ من الصفّ لا من الطلب: الشاشة لا تعرف أيّ رمزٍ في يدها،
     * والخادم يعرف. ولو قُرئ من الطلب لَأمكن تقديمُ رمز توثيقٍ على أنّه رمز
     * تعيين كلمة مرور.
     */
    private function pendingOtp(PasswordRecoveryChallenge $challenge): ?PasswordRecoveryOtp
    {
        return PasswordRecoveryOtp::where('challenge_id', $challenge->id)
            ->whereNull('used_at')
            ->whereIn('purpose', [
                PasswordRecoveryOtp::PURPOSE_PASSWORD_RESET,
                PasswordRecoveryOtp::PURPOSE_EMAIL_VERIFICATION,
            ])
            ->orderByDesc('id')
            ->first();
    }

    /** حدُّ محاولات — ورسالته لا تقول شيئًا عن الحساب */
    private function throttle(string $key, int $max, int $seconds, string $field = 'email'): void
    {
        if (RateLimiter::tooManyAttempts($key, $max)) {
            throw ValidationException::withMessages([
                $field => __('محاولات كثيرة. حاول بعد :seconds ثانية.', [
                    'seconds' => RateLimiter::availableIn($key),
                ]),
            ]);
        }

        RateLimiter::hit($key, $seconds);
    }
}
