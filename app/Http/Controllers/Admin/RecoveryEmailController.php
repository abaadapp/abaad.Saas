<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\RecoveryOtpMail;
use App\Models\PasswordRecoveryChallenge;
use App\Models\PasswordRecoveryOtp;
use App\Models\User;
use App\Support\Activity;
use App\Support\Mailer;
use App\Support\RecoveryEmail;
use App\Support\RecoveryOtp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * بريد الاستعادة من داخل الحساب — يضبطه صاحبه وهو داخل.
 *
 * وهذا هو الطريق الصحيح: يُضبط والحساب مفتوح، قبل أن يُحتاج إليه. ومن ضبطه
 * اليوم لا يحتاج إلى أحدٍ يوم ينسى كلمته.
 *
 * ------------------------------------------------------------------------
 *
 * وجلسةٌ مفتوحة وحدها لا تكفي.
 *
 * جهازٌ تُرك مفتوحًا دقيقتين يكفي لكتابة بريدٍ غريب وحفظه — ثمّ يملك صاحبُه
 * الحسابَ إلى الأبد، بلا كلمة مرورٍ ولا شيء. فيُطلَب:
 *
 *   ١) كلمة المرور الحالية — تُثبت أنّ من يكتب هو صاحب الحساب لا من مرّ به.
 *   ٢) ورمزٌ إلى العنوان الجديد — يُثبت أنّه يقرؤه.
 *
 * والاثنان معًا: الأولى تُثبت الحساب، والثاني يُثبت الصندوق. وواحدةٌ منهما
 * وحدها تترك بابًا.
 */
class RecoveryEmailController extends Controller
{
    private function owner(): User
    {
        return auth()->user();
    }

    /**
     * يبدأ ضبط بريدٍ — أو تغييره.
     *
     * والتغيير أشدُّ من الإضافة الأولى لأنّه ينزع وسيلةً قائمة: لكنّ الشرطين
     * أعلاه يكفيان للحالين، فلا يُشترط رمزٌ على العنوان القديم — عنوانٌ قديم
     * لم يعد صاحبه يقرؤه هو أوّل سبب لتغييره، فاشتراطُه يُقفل الباب على من
     * جاء يُصلحه.
     */
    public function start(Request $request)
    {
        abort_if(! Mailer::configured(), 404, __('البريد غير مفعَّل على هذا النظام.'));

        $user = $this->owner();

        $data = $request->validate([
            'recovery_email' => RecoveryEmail::rules(),
            'current_password' => ['required', 'string'],
        ], RecoveryEmail::messages() + [
            'current_password.required' => __('اكتب كلمة المرور الحالية.'),
        ]);

        $this->throttle('recovery-email:start:'.$user->id, 5, 900);

        // كلمة المرور تُقارَن هنا لا في قاعدة تحقّق: الرسالة واحدة لا تفرّق
        if (! Hash::check($data['current_password'], (string) $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => __('كلمة المرور الحالية غير صحيحة.'),
            ]);
        }

        $email = RecoveryEmail::normalize($data['recovery_email']);

        if (RecoveryEmail::isInternal($email)) {
            throw ValidationException::withMessages([
                'recovery_email' => __('هذا عنوان دخولٍ داخليّ لا صندوق بريد — اكتب بريدًا تصله الرسائل فعلًا.'),
            ]);
        }

        $challenge = RecoveryOtp::openChallenge($user);
        $code = RecoveryOtp::issue($challenge, PasswordRecoveryOtp::PURPOSE_EMAIL_VERIFICATION, $email);

        try {
            Mail::to($email)->send(new RecoveryOtpMail($code, PasswordRecoveryOtp::PURPOSE_EMAIL_VERIFICATION));
        } catch (\Throwable $e) {
            report($e);

            return back()->withErrors([
                'recovery_email' => __('تعذّر إرسال الرمز الآن. حاول بعد قليل.'),
            ]);
        }

        Activity::log('settings', 'طلب توثيق بريد استعادة جديد', [
            'business_id' => $user->business_id,
            'icon' => 'mail',
        ]);

        return back()->with('recovery_challenge', $challenge->token)
            ->with('toast', ['msg' => __('أرسلنا رمز التحقق إلى البريد الجديد'), 'type' => 'success']);
    }

    /**
     * يختم العنوان بعد اجتياز الرمز.
     *
     * والعنوان يُقرأ من صفّ الرمز لا من الطلب: لو قُبل من الطلب لَكفى أن
     * يُطلب رمزٌ إلى بريدٍ يملكه الطالب ثمّ يُرسَل مع الرمز عنوانٌ آخر —
     * فيُختم عنوانٌ لم يصل إليه شيء.
     */
    public function confirm(Request $request)
    {
        $user = $this->owner();

        $data = $request->validate([
            'code' => ['required', 'string', 'max:12'],
        ]);

        /*
         * المحاولة تُستخرج من الجلسة لا من الطلب.
         *
         * ولا يُرسل معرّفها إلى الشاشة أصلًا: هذا يُغلق بابين معًا — لا
         * يستطيع أحدٌ إكمال محاولة غيره ولو عرف رمزها، ويستطيع صاحبُ الحساب
         * أن يُكمل محاولةً بدأها مدير المنصّة له دون أن يُمرَّر إليه شيء.
         */
        $challenge = PasswordRecoveryChallenge::where('user_id', $user->id)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('id')
            ->first();

        if (! $challenge) {
            throw ValidationException::withMessages([
                'code' => __('انتهت مهلة المحاولة. ابدأ من جديد.'),
            ]);
        }

        $this->throttle('recovery-email:confirm:'.$user->id, 10, 900, 'code');

        $otp = RecoveryOtp::current($challenge, PasswordRecoveryOtp::PURPOSE_EMAIL_VERIFICATION);

        if (! $otp || ! RecoveryOtp::check($otp, $data['code'])) {
            throw ValidationException::withMessages([
                'code' => __('الرمز غير صحيح أو منتهي الصلاحية.'),
            ]);
        }

        $previous = $user->recovery_email;

        $user->forceFill([
            'recovery_email' => $otp->target_email,
            'recovery_email_verified_at' => now(),
        ])->save();

        $challenge->forceFill([
            'state' => PasswordRecoveryChallenge::USED,
            'verified_email_at' => now(),
            'used_at' => now(),
        ])->save();

        /*
         * ويُخبَر العنوان القديم بما جرى.
         *
         * من غُيّر بريد استعادته دون علمه لا يعرف إلا حين ينسى كلمته — وقد
         * فات الأمر. وهذه الرسالة هي فرصته الوحيدة لينتبه اليوم. وفشلُها لا
         * يُسقط العملية: أُنجزت وخُتمت.
         */
        if (filled($previous) && $previous !== $otp->target_email && ! RecoveryEmail::isInternal($previous)) {
            try {
                Mail::to($previous)->send(new \App\Mail\RecoveryEmailChangedMail());
            } catch (\Throwable $e) {
                report($e);
            }
        }

        Activity::log('settings', filled($previous) ? 'غيّر بريد الاستعادة ووثّقه' : 'وثّق بريد الاستعادة', [
            'business_id' => $user->business_id,
            'icon' => 'shield-check',
        ]);

        return back()->with('toast', ['msg' => __('تم توثيق بريد الاستعادة'), 'type' => 'success']);
    }

    /** ما يُعرض في الإعدادات — بلا رمزٍ ولا بصمة */
    public static function view(User $user): array
    {
        return [
            'email' => $user->recovery_email,
            'verified' => $user->recovery_email_verified_at !== null,
            'verified_at' => optional($user->recovery_email_verified_at)->format('Y-m-d H:i'),
            'mail_ready' => Mailer::configured(),
        ];
    }

    private function throttle(string $key, int $max, int $seconds, string $field = 'recovery_email'): void
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
