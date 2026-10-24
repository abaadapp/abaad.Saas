<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * مستندٌ أُرسل إلى عميلٍ ليوقّعه — نسخةٌ من قالبه ساعةَ الإصدار.
 *
 * حالاتُه ثلاث: أُرسل (الرابطُ يعمل)، وُقِّع (لا يتغيّر بعدها)، أُلغي. والمنتهي
 * ليس حالةً تُكتب: هو مُرسَلٌ تجاوز `expires_at`، فيُقرأ ولا يُوقَّع.
 */
class PlatformDocument extends Model
{
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
        'amount' => 'decimal:3',
        'expires_at' => 'datetime',
        'viewed_at' => 'datetime',
        'signed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

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
}
