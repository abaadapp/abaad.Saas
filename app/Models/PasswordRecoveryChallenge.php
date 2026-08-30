<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * محاولةُ استعادةٍ جارية — حالتُها عند الخادم لا عند المتصفّح.
 *
 * المتصفّح يحمل رمزًا مبهمًا لا يدلّ على صاحبه؛ والخادم وحده يعرف أيّ حسابٍ
 * وراءه. ولو حُملت الحالة في الطلب — «تحقّقتُ، وهذا معرّف متجري» — لَكفى
 * تعديلُ سطرٍ في أدوات المتصفّح لتغيير كلمة مرور متجرٍ آخر.
 */
class PasswordRecoveryChallenge extends Model
{
    protected $table = 'password_recovery_challenges';

    protected $guarded = [];

    protected $casts = [
        'verified_email_at' => 'datetime',
        'authorized_at' => 'datetime',
        'used_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /** الرمز لا يخرج في `toArray` — والباب هو ما تُعيده المتحكّمات صراحةً */
    protected $hidden = ['token'];

    public const OTP_SENT = 'otp_sent';

    public const EMAIL_VERIFIED = 'email_verified';

    public const AUTHORIZED = 'authorized';

    public const USED = 'used';

    public function user(): BelongsTo { return $this->belongsTo(User::class); }

    public function business(): BelongsTo { return $this->belongsTo(Business::class); }

    public function otps(): HasMany
    {
        return $this->hasMany(PasswordRecoveryOtp::class, 'challenge_id');
    }

    public function isLive(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }

    /**
     * هل يجوز الآن تعيين كلمةٍ جديدة؟
     *
     * ثلاثة شروطٍ معًا: اجتاز الرمز، وأُذن له، ولم تنتهِ مهلة الإذن. ومهلة
     * الإذن أقصر من عمر المحاولة عمدًا — إذنٌ مفتوح على جهازٍ تُرك في مقهى
     * أخطر من محاولةٍ لم تكتمل.
     */
    public function canSetPassword(): bool
    {
        if (! $this->isLive() || $this->authorized_at === null || $this->verified_email_at === null) {
            return false;
        }

        return $this->authorized_at->addSeconds((int) config('recovery.authorization_ttl', 900))->isFuture();
    }
}
