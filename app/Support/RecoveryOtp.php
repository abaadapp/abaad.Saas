<?php

namespace App\Support;

use App\Models\PasswordRecoveryChallenge;
use App\Models\PasswordRecoveryOtp;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * الرمز: توليدُه، وبصمتُه، ومقارنتُه — موضعٌ واحد.
 *
 * ولو وُزّع لَاختلف: مسارٌ يولّد بستّة أرقامٍ ومسارٌ بأربعة، ومسارٌ ينسى
 * إبطال ما قبله. وكلّ خللٍ منها لا يظهر إلا حين يُستغَلّ.
 */
class RecoveryOtp
{
    /**
     * ستّة أرقامٍ من مولّدٍ آمن.
     *
     * `random_int` لا `rand` ولا `mt_rand`: الأخيران متوقّعان — من رأى بضعة
     * مخرجاتٍ استنتج البذرة ثمّ تنبّأ بالتالي. وهي أرقامُ استعادة حساب، لا
     * أرقامُ لعبة.
     */
    public static function generate(): string
    {
        return (string) random_int(100000, 999999);
    }

    /**
     * يُنشئ رمزًا للمحاولة ويُبطل ما قبله.
     *
     * الإبطال شرطٌ لا تحسين: لو بقي القديم صالحًا لَصار «أعد الإرسال» طريقةً
     * لمضاعفة الاحتمالات — عشرةُ رموزٍ صالحةٍ معًا تعني عشرة أضعاف فرصة
     * التخمين.
     *
     * @return string الرمز الخام — يُرسَل ولا يُخزَّن ولا يُعاد إلى شاشة
     */
    public static function issue(PasswordRecoveryChallenge $challenge, string $purpose, string $targetEmail): string
    {
        // ما سبق من رموز هذه المحاولة يُبطَل — أحدثُ رمزٍ وحده يعمل
        PasswordRecoveryOtp::where('challenge_id', $challenge->id)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        $code = self::generate();

        PasswordRecoveryOtp::create([
            'challenge_id' => $challenge->id,
            'purpose' => $purpose,
            'target_email' => $targetEmail,
            // bcrypt لا sha256: بطيئةٌ عمدًا، فتخمينُ مليون احتمالٍ على بصمةٍ مسروقة يستحيل
            'otp_hash' => Hash::make($code),
            'attempts' => 0,
            'max_attempts' => (int) config('recovery.otp_max_attempts', 5),
            'expires_at' => now()->addSeconds((int) config('recovery.otp_ttl', 600)),
        ]);

        return $code;
    }

    /**
     * أحدث رمزٍ صالحٍ لهذه المحاولة وهذا الغرض.
     *
     * والغرض جزءٌ من الشرط: رمزٌ أُرسل لتوثيق بريدٍ لا يُقبل لتعيين كلمة
     * مرور. ولولاه لَصار كلُّ رمزٍ مفتاحًا لكلّ باب.
     */
    public static function current(PasswordRecoveryChallenge $challenge, string $purpose): ?PasswordRecoveryOtp
    {
        return PasswordRecoveryOtp::where('challenge_id', $challenge->id)
            ->where('purpose', $purpose)
            ->whereNull('used_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * مقارنة — وكلّ خطأٍ يُعدّ.
     *
     * والعدّ يُكتب **قبل** الحكم لا بعده: لو كُتب بعده لَاستطاع من يقطع
     * الاتصال بعد كلّ محاولةٍ أن يجرّب بلا حدّ.
     *
     * @return bool صحيحٌ إن طابق
     */
    public static function check(PasswordRecoveryOtp $otp, string $code): bool
    {
        if (! $otp->isUsable()) {
            return false;
        }

        $otp->increment('attempts');

        if (! Hash::check(trim($code), $otp->otp_hash)) {
            // بلغ الحدّ: يُبطَل الرمز كلّه ولا تُترك بقيّةُ محاولاتٍ لغده
            if ($otp->fresh()->attempts >= $otp->max_attempts) {
                $otp->forceFill(['used_at' => now()])->save();
            }

            return false;
        }

        $otp->forceFill(['verified_at' => now(), 'used_at' => now()])->save();

        return true;
    }

    /** رمزُ محاولةٍ مبهم — لا يدلّ على صاحبه ولا يُخمَّن */
    public static function challengeToken(): string
    {
        return Str::random(48);
    }

    /**
     * محاولةٌ جديدة — والقديمة لهذا الحساب تُبطَل.
     *
     * محاولتان مفتوحتان لحسابٍ واحد تعنيان بابين؛ ومن بدأ الاستعادة مرّتين
     * يقصد الثانية.
     */
    public static function openChallenge(User $user): PasswordRecoveryChallenge
    {
        PasswordRecoveryChallenge::where('user_id', $user->id)
            ->whereNull('used_at')
            ->update(['used_at' => now(), 'state' => PasswordRecoveryChallenge::USED]);

        return PasswordRecoveryChallenge::create([
            'token' => self::challengeToken(),
            'user_id' => $user->id,
            'business_id' => $user->business_id,
            'state' => PasswordRecoveryChallenge::OTP_SENT,
            'expires_at' => now()->addSeconds((int) config('recovery.challenge_ttl', 1800)),
            'ip_address' => request()?->ip(),
        ]);
    }

    /**
     * المحاولة التي يشير إليها هذا الرمز — أو لا شيء.
     *
     * ولا يُقرأ من الطلب معرّف مستخدمٍ ولا معرّف متجر: الرمز وحده، والخادم
     * يستخرج منه صاحبه. وهذا هو الحدّ الذي يمنع محاولةَ متجرٍ أن تُغيّر كلمة
     * مرور متجرٍ آخر.
     */
    public static function resolve(?string $token): ?PasswordRecoveryChallenge
    {
        if (blank($token)) {
            return null;
        }

        $challenge = PasswordRecoveryChallenge::with('user')->where('token', $token)->first();

        return $challenge && $challenge->isLive() ? $challenge : null;
    }
}
