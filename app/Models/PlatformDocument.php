<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * مستندٌ أُرسل إلى عميلٍ ليوقّعه — نسخةٌ من قالبه ساعةَ الإصدار.
 *
 * حالاتُه ثلاث: أُرسل (الرابطُ يعمل)، وُقِّع (لا يتغيّر بعدها)، أُلغي. والمنتهي
 * ليس حالةً تُكتب: هو مُرسَلٌ تجاوز `expires_at`، فلا يُوقَّع.
 *
 * والرابطُ يعرض ما فيه ما دام للتوقيع، ثمّ نسخةَ الموقَّع أيّامًا ينزّلها فيها
 * العميل. والملغى والمنتهي وما مضت أيّامُ نسخته يقول حالَه فقط: رابطٌ أُرسل
 * إلى رقمٍ خاطئ أو بقي في محادثةٍ لا يبقى بابًا إلى رقم العميل المدنيّ وتوقيعه.
 */
class PlatformDocument extends Model
{
    /** أيّامُ بقاء النسخة الموقَّعة على رابطها — وبعدها تُطلب من مدير المنصّة */
    public const COPY_DAYS = 30;

    public const SENT = 'sent';

    public const SIGNED = 'signed';

    public const CANCELLED = 'cancelled';

    public const LABELS = [
        self::SENT => 'بانتظار التوقيع',
        self::SIGNED => 'موقّع',
        self::CANCELLED => 'ملغى',
        'expired' => 'انتهت صلاحية الرابط',
    ];

    protected $guarded = [];

    protected $hidden = ['token'];

    protected $casts = [
        'terms' => 'array',
        'plan' => 'array',
        'client' => 'array',
        'provider' => 'array',
        'amount' => 'decimal:3',
        'expires_at' => 'datetime',
        'viewed_at' => 'datetime',
        'signed_at' => 'datetime',
        'phone_verified_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    /** رموزُ التحقّق التي أُرسلت إلى هاتفه المعتمد — انظر `SigningCodes` */
    public function codes(): HasMany
    {
        return $this->hasMany(PlatformDocumentCode::class, 'document_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(PlatformDocumentTemplate::class, 'template_id');
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class, 'client_business_id');
    }

    public function expired(): bool
    {
        return $this->status === self::SENT && $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** الحالةُ كما تُعرض — والمنتهي يُقرأ من التاريخ */
    public function state(): string
    {
        return $this->expired() ? 'expired' : $this->status;
    }

    public function signable(): bool
    {
        return $this->status === self::SENT && ! $this->expired();
    }

    /** الموقَّعُ في أيّام نسخته — يُعرض على رابطه وتُنزَّل ورقتُه */
    public function copyOpen(): bool
    {
        return $this->status === self::SIGNED && $this->signed_at !== null
            && $this->signed_at->copy()->addDays(self::COPY_DAYS)->isFuture();
    }

    /** هل يعرض الرابطُ بيانَ العميل؟ — وإلّا قال حالَه وحدها */
    public function readable(): bool
    {
        return $this->signable() || $this->copyOpen();
    }
}
