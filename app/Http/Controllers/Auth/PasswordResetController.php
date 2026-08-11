<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\PasswordResetMail;
use App\Models\User;
use App\Support\Activity;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * استعادة كلمة المرور — الباب الذي لا يمرّ بك.
 *
 * لم يكن في النظام استعادةٌ إطلاقًا: تاجرٌ نسي كلمته يتّصل بالدعم فيفتح
 * لوحة المنصة ويصنع له واحدة. وهذا يعمل عند عشرين تاجرًا ويستحيل عند
 * مئتين — ثم إنه يجعل كلمةَ مرور كل تاجر مارّةً بيد إنسان.
 *
 * والرابط يُسلَّم إلى بريدٍ يصل فعلًا (انظر User::contactEmail): حساب
 * التاجر على نطاقٍ داخلي لا صندوق خلفه.
 */
class PasswordResetController extends Controller
{
    /** شاشة «نسيت كلمة المرور» */
    public function request()
    {
        $this->abortWithoutMail();

        return \Inertia\Inertia::render('Auth/ForgotPassword', [
            'year' => (int) now()->format('Y'),
        ]);
    }

    /**
     * بلا بريدٍ مضبوط، هذا المسار لا يفعل شيئًا سوى الوعد.
     *
     * إخفاء الرابط من شاشة الدخول لا يكفي: من يعرف العنوان — أو حفظه
     * متصفّحه، أو وصله من رسالةٍ قديمة — يصل إلى نموذجٍ يقول «أرسلنا» ولا
     * يُرسل. فيُقفل الباب من الخادم لا من الواجهة، ويُقال له لماذا.
     */
    private function abortWithoutMail(): void
    {
        abort_if(
            ! \App\Support\Mailer::configured(),
            404,
            __('استعادة كلمة المرور غير مفعَّلة على هذا النظام — تواصل مع مدير النظام.'),
        );
    }

    /**
     * يُرسل الرابط — ويردّ الجواب نفسه في كل حال.
     *
     * التمييز بين «أرسلنا» و«لا حساب بهذا البريد» يجعل هذه الشاشة أداةَ
     * جردٍ لحسابات المنصة: يجرّب المرء العناوين حتى يعرف أيّها موجود، ثم
     * ينتقل بها إلى شاشة الدخول. والجوابُ الواحد يكلّف صاحب الحساب الحقيقي
     * دقيقةَ انتظار، ويكلّف من يستكشف كلَّ شيء.
     */
    public function send(Request $request)
    {
        $this->abortWithoutMail();

        $data = $request->validate(['email' => ['required', 'email']]);
        $email = mb_strtolower($data['email']);

        // حدٌّ على الطلب نفسه: كل رسالةٍ تُرسل تكلّف، ومن يكرّر الطلب يُغرق
        // صندوق غيره ويحرق سمعة نطاق الإرسال
        $key = 'password-reset:'.$email.'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 3)) {
            throw ValidationException::withMessages([
                'email' => __('محاولات كثيرة. حاول بعد :seconds ثانية.', [
                    'seconds' => RateLimiter::availableIn($key),
                ]),
            ]);
        }
        RateLimiter::hit($key, 900);

        $user = User::with('business')->whereRaw('lower(email) = ?', [$email])->first();

        // حسابٌ موقوف أو متجرٌ منتهٍ لا يُفتح بابه من هنا: الاستعادة تعيد
        // كلمة المرور لا الصلاحية، ورابطٌ ينتهي إلى شاشة رفضٍ أسوأ من صمت
        // اشتراكٌ منتهٍ لا يمنع استعادة كلمة المرور: هي ما يحتاجه ليدخل ويجدّد
        $reason = $user ? Tenancy::blockReason($user) : null;
        if ($user && ($reason === null || ! Tenancy::isHard($reason))) {
            $to = $user->contactEmail();

            if ($to) {
                Mail::to($to)->send(new PasswordResetMail(
                    $user->name,
                    $user->email,
                    route('password.reset', [
                        'token' => Password::broker()->createToken($user),
                        'email' => $user->email,
                    ]),
                ));
            } else {
                /*
                 * لا عنوان تسليم: متجرٌ سُجِّل بلا بريد تواصل.
                 *
                 * لا يُقال هذا لطالب الاستعادة (فيصير الفرقُ بين الجوابين
                 * دلالةً على وجود الحساب)، لكنه يُقيَّد ليراه الدعم — وإلا
                 * بقي التاجر ينتظر رسالةً لا تُرسل ولا يعلم أحدٌ لماذا.
                 */
                Activity::log('login_failed', 'طلب استعادة كلمة مرور تعذّر تسليمه — لا بريد تواصل للمتجر: '.$user->email, [
                    'business_id' => $user->business_id,
                    'icon' => 'mail',
                    'color' => 'warning',
                ]);
            }
        }

        return back()->with('status', __('إن كان هذا البريد مسجّلًا فسيصلك رابط إعادة التعيين خلال دقائق.'));
    }

    /** شاشة كلمة المرور الجديدة — تُفتح من رابط الرسالة */
    public function reset(Request $request, string $token): \Inertia\Response
    {
        return \Inertia\Inertia::render('Auth/ResetPassword', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
            'year' => (int) now()->format('Y'),
        ]);
    }

    /** يحفظ كلمة المرور الجديدة ويُبطل الرابط */
    public function store(Request $request)
    {
        $data = $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ], [
            'password.confirmed' => __('كلمتا المرور غير متطابقتين.'),
            'password.min' => __('كلمة المرور ثمانية أحرف على الأقل.'),
        ]);

        $status = Password::reset($data, function (User $user, string $password) {
            /*
             * تغيير كلمة المرور يقطع الجلسات القائمة.
             *
             * من نسي كلمته قد يكون فقد جهازه أو أخذها غيره — فإبقاء جلسةٍ
             * مفتوحةً على جهازٍ آخر يجعل الاستعادة إجراءً شكليًّا: الكلمة
             * تتغيّر ومن دخل بها لا يزال داخلًا.
             */
            $user->forceFill([
                'password' => $password,
                'remember_token' => \Illuminate\Support\Str::random(60),
            ])->save();

            Activity::log('settings', 'أعاد تعيين كلمة المرور عبر رابط الاستعادة', [
                'business_id' => $user->business_id,
                'icon' => 'key-round',
            ]);
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => __('رابط إعادة التعيين غير صالح أو منتهي الصلاحية. اطلب رابطًا جديدًا.'),
            ]);
        }

        return redirect()->route('login')->with('status', __('تم تغيير كلمة المرور. سجّل الدخول بها الآن.'));
    }
}
