<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * مستندٌ أُرسل إلى عميلٍ ليوقّعه — نسخةٌ من قالبه ساعةَ الإصدار.
 *
 * حالاتُه ثلاث: أُرسل (الرابطُ يعمل)، وُقِّع (لا يتغيّر بعدها)، أُلغي. والمنتهي
 * ليس حالةً تُكتب: هو مُرسَلٌ تجاوز `expires_at`، فلا يُوقَّع.
 *
 * والرابطُ وحده مفتاحُه للتوقيع — لا رمزَ ولا حساب. فيعرض ما فيه ما دام للتوقيع.
 * وبعده تُعرض نسخةُ الموقَّع أيّامًا على الجهاز الذي وقّع منه وحده: مفتاحٌ في
 * كوكي متصفّحه (`COPY_COOKIE`) وبصمتُه هنا. فرابطٌ مُرِّر بعد التوقيع، أو بقي في
 * محادثةٍ يقرؤها غيرُه، لا يفتح رقمَه المدنيّ ولا توقيعه. والملغى والمنتهي وما
 * مضت أيّامُ نسخته يقول حالَه فقط.
 *
 * وتوقيعُ العقد شيءٌ وتمديدُ الاشتراك شيءٌ آخر: العقدُ لا يمسّ `subscriptions`
 * ولا الفواتير — التمديدُ يقع عند سداد فاتورة التجديد (`Billing::markPaid`).
 */
class PlatformDocument extends Model
{
    /** أيّامُ بقاء النسخة الموقَّعة على رابطها — وبعدها تُطلب من مدير المنصّة */
    public const COPY_DAYS = 30;

    /** كوكي نسخة الموقِّع — على مسار رابطه وحده */
    public const COPY_COOKIE = 'abaad_signed_copy';

    public const SENT = 'sent';

    public const SIGNED = 'signed';

    public const CANCELLED = 'cancelled';

    public const LABELS = [
        self::SENT => 'بانتظار التوقيع',
        self::SIGNED => 'موقّع',
        self::CANCELLED => 'ملغى',
        'expired' => 'انتهت صلاحية الرابط',
    ];

    /** طرقُ مشاركة الرابط التي يسجّلها المدير — فعلُه هو، لا دليلُ وصول */
    public const SHARED = [
        'copy' => 'نُسخ الرابط',
        'whatsapp' => 'فُتح واتساب',
    ];

    protected $guarded = [];

    protected $hidden = ['token'];

    protected $casts = [
        'terms' => 'array',
        'plan' => 'array',
        'client' => 'array',
        'provider' => 'array',
        'form' => 'array',
        'team' => 'array',
        'answers' => 'array',
        'amount' => 'decimal:3',
        'term_starts_on' => 'date',
        'term_ends_on' => 'date',
        'expires_at' => 'datetime',
        'viewed_at' => 'datetime',
        'signed_at' => 'datetime',
        'agreed_at' => 'datetime',
        'shared_at' => 'datetime',
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

    /** العقدُ الذي يجدّده هذا */
    public function renews(): BelongsTo
    {
        return $this->belongsTo(self::class, 'renews_document_id');
    }

    /** عقودُ التجديد التي صدرت عنه */
    public function renewals(): HasMany
    {
        return $this->hasMany(self::class, 'renews_document_id');
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

    /** آخرُ يومٍ تُعرض فيه النسخةُ الموقَّعة على رابطها */
    public function copyUntil(): ?Carbon
    {
        return $this->signed_at?->copy()->addDays(self::COPY_DAYS);
    }

    /** هل هذا المتصفّحُ هو الذي وقّع؟ — مفتاحُه يطابق البصمة، في أيّام النسخة */
    public function holdsCopy(?string $key): bool
    {
        return $this->copyOpen() && $this->copy_key_hash !== null && $key !== null && $key !== ''
            && hash_equals($this->copy_key_hash, hash('sha256', $key));
    }

    /** هل يعرض الرابطُ بيانَ العميل؟ — للتوقيع، أو لمن وقّع على جهازه؛ وإلّا قال حالَه وحدها */
    public function readable(?string $copyKey): bool
    {
        return $this->signable() || $this->holdsCopy($copyKey);
    }
}
