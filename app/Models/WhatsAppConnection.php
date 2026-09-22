<?php

namespace App\Models;

use App\Support\WhatsAppMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * وصلةُ رقمٍ على واتساب — لأبعاد أو لمحلّ.
 *
 * والرمز مشفَّرٌ في العمود لا مقروءًا: من نسخ قاعدة البيانات لا ينسخ معها
 * مفتاحَ الإرسال. و`$hidden` فوق ذلك حتى لا يتسرّب في `toArray` — وهي
 * الطريق التي تسلكها خصائص Inertia كلّها إلى المتصفّح.
 */
class WhatsAppConnection extends Model
{
    protected $table = 'whatsapp_connections';

    protected $guarded = [];

    protected $casts = [
        'access_token' => 'encrypted',
        'token_expires_at' => 'datetime',
        'connected_at' => 'datetime',
        'disconnected_at' => 'datetime',
        'last_webhook_at' => 'datetime',
        'last_error_at' => 'datetime',
        'coexistence' => 'boolean',
        'metadata' => 'array',
    ];

    /**
     * ما لا يخرج من هذا النموذج أبدًا.
     *
     * الحارس الحقيقي أن لا يُمرَّر النموذج إلى الشاشة أصلًا (انظر
     * `WhatsAppConnections::publicView`)، وهذا حارسٌ ثانٍ: من كتب
     * `->toArray()` سهوًا لا يُسرّب المفتاح.
     */
    protected $hidden = ['access_token'];

    public const ACTIVE = 'active';

    public const INACTIVE = 'inactive';

    public const EXPIRED = 'expired';

    public const REVOKED = 'revoked';

    public const ERROR = 'error';

    /**
     * ═══ حالتان جديدتان، وكلتاهما تصف واقعًا لم يكن له اسم ═══
     *
     * `PENDING`: التسجيل المدمج تمّ، والرمزُ عندنا، وحسابُ الأعمال معروف —
     * ولا رقمَ فيه بعد. وهذا يقع فعلًا في مسار تطبيق واتساب للأعمال: ميتا
     * تُعيد `waba_id` وحده ثمّ يظهر الرقمُ بعد دقائق. وقبل هذه الحالة كان
     * الخيارُ بين كذبتين: «متّصل» ولا رقمَ يُرسل منه، أو «فشل» وقد نجح.
     *
     * `REAUTH`: الرمزُ حيٌّ بعدُ لكنّه يوشك — أو سُحبت منه صلاحيةٌ يحتاجها.
     * وهي غيرُ `EXPIRED`: تلك انقطاعٌ وقع، وهذه تحذيرٌ قبل أن يقع. ومن
     * يُسوّي بينهما يُخيف التاجر أسبوعين أو يفاجئه في اليوم الأخير.
     */
    public const PENDING = 'pending';

    public const REAUTH = 'reauthorization_required';

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by_user_id');
    }

    public function scopePlatform($query)
    {
        return $query->where('owner_type', WhatsAppMode::OWNER_PLATFORM);
    }

    public function scopeForBusiness($query, int $businessId)
    {
        return $query->where('owner_type', WhatsAppMode::OWNER_BUSINESS)
            ->where('business_id', $businessId);
    }

    /**
     * صالحةٌ للإرسال: نشطة، ولها معرّف رقم، ورمزها لم ينتهِ.
     *
     * انتهاء الرمز يُفحص هنا لا عند ميتا: النداء بمفتاحٍ منتهٍ يُردّ بخطأ
     * بعد ثوانٍ، والحصّة تكون قد حُجزت.
     */
    public function isUsable(): bool
    {
        /*
         * و«يحتاج إعادة تفويض» يُرسل.
         *
         * هي تحذيرٌ قبل الانقطاع لا انقطاع: الرمزُ صالحٌ اليوم. ومن أوقف
         * الإرسال عندها أوقف رسائلَ أسبوعين لأنّ رمزًا سينتهي بعدهما.
         */
        if (! in_array($this->status, [self::ACTIVE, self::REAUTH], true)
            || blank($this->phone_number_id) || blank($this->access_token)) {
            return false;
        }

        return $this->token_expires_at === null || $this->token_expires_at->isFuture();
    }
}
