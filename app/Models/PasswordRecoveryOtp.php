<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * رمزُ التحقّق — بصمتُه لا هو.
 *
 * ستّة أرقامٍ تُخزَّن نصًّا تعني أنّ من قرأ صفًّا واحدًا في القاعدة — نسخةً
 * احتياطية، أو سجلَّ استعلامات، أو شاشةَ زميل — غيّر كلمة مرور صاحبه.
 * والبصمة باتجاهٍ واحد: تُقارَن ولا تُستخرَج.
 *
 * والغرض مكتوبٌ في الصفّ: رمزٌ أُرسل لتوثيق بريدٍ لا يصلح لتعيين كلمة مرور.
 */
class PasswordRecoveryOtp extends Model
{
    protected $table = 'password_recovery_otps';

    protected $guarded = [];

    protected $casts = [
        'expires_at' => 'datetime',
        'verified_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    /** لا تخرج البصمة في `toArray` ولو مُرّر النموذج سهوًا */
    protected $hidden = ['otp_hash'];

    /** تعيين كلمة مرورٍ لحسابٍ له بريد استعادةٍ مختوم */
    public const PURPOSE_PASSWORD_RESET = 'password_reset';

    /** ختمُ بريد استعادةٍ جديد — لا يصلح لتعيين كلمة مرور */
    public const PURPOSE_EMAIL_VERIFICATION = 'recovery_email_verification';

    public function challenge(): BelongsTo
    {
        return $this->belongsTo(PasswordRecoveryChallenge::class, 'challenge_id');
    }

    /** صالحٌ للمقارنة: لم يُستعمل، ولم ينتهِ، ولم تُستنفد محاولاته */
    public function isUsable(): bool
    {
        return $this->used_at === null
            && $this->expires_at->isFuture()
            && $this->attempts < $this->max_attempts;
    }
}
