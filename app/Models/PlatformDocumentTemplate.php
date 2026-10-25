<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * قالبُ مستندٍ لخدمةٍ من خدمات أبعاد — يكتبه مدير المنصّة.
 *
 * بنودُه `[{h, b}]`: عنوانُ البند ونصُّه. و`includes_plan` يضع في المستند
 * الباقةَ ودورتَها وسعرَها من باقات النظام، و`includes_amount` مبلغًا مقطوعًا
 * لخدمةٍ لا باقةَ لها (تخصيص موقع، تطبيق، أجهزة).
 *
 * و`form` نموذجٌ كاملٌ بحقوله وقوائمه وجداوله (انظر `DocumentForms`) — اتفاقياتُ
 * المالك كما صمّمها. وقالبُ النموذج لا بنودَ له: نصُّه في أقسامه.
 *
 * ═══ النشر ═══
 *
 * يُصدر المستندُ من الإصدار المنشور: مفعّلٌ (`active`) وبصمةُ محتواه هي ما نُشر
 * (`approved_hash`، انظر `content`): نصُّه ورسومُه وقاعدةُ مبلغه. فإن عُدِّل شيءٌ
 * منها بعده صار إصدارًا جديدًا (`version`) غيرَ منشور. وما صدر قبله نسخةٌ في
 * مستنده لا تتغيّر.
 *
 * والنشرُ قرارُ مدير المنصّة بتأكيدٍ واحد — من نافذة الإصدار وهو يُصدر منه، أو من
 * شاشة القوالب — على بصمة ما رآه، ويُسجَّل باسمه ووقته في القالب وفي سجلّ
 * إصداراته (`versions`). ولا يُنشر قالبٌ بنشر تحديثٍ برمجيّ.
 *
 * والقالبُ القائم قبل سياسة الاعتماد (`approval_basis = legacy`) يبقى منشورًا كما
 * كان، ويحتاج نشرًا إن عُدِّل.
 */
class PlatformDocumentTemplate extends Model
{
    /** اعتمده مدير المنصّة من شاشة القوالب */
    public const BY_OWNER = 'owner';

    /** كان يُصدر منه قبل سياسة الاعتماد — يبقى كما كان حتى يُعدَّل */
    public const LEGACY = 'legacy';

    /** المبلغُ هو رسومُ القالب كما اعتُمدت — نصُّه يذكرها */
    public const AMOUNT_FIXED = 'fixed';

    /** المبلغُ يبدأ من رسوم القالب — «يبدأ من 60 ر.ع» */
    public const AMOUNT_MIN = 'min';

    protected $guarded = [];

    protected $casts = [
        'terms' => 'array',
        'form' => 'array',
        'default_amount' => 'decimal:3',
        'includes_plan' => 'boolean',
        'includes_amount' => 'boolean',
        'active' => 'boolean',
        'approved_at' => 'datetime',
        'version' => 'integer',
    ];

    public function documents(): HasMany
    {
        return $this->hasMany(PlatformDocument::class, 'template_id');
    }

    /** سجلُّ إصداراته — الأحدثُ أوّلًا */
    public function versions(): HasMany
    {
        return $this->hasMany(PlatformDocumentTemplateVersion::class, 'template_id')->orderByDesc('version');
    }

    /**
     * يكتب الإصدارَ الحاليّ في سجلّه — مرّةً لكلّ رقم، فلا يُكتب فوق إصدارٍ حُفظ.
     */
    public function recordVersion(?User $by): PlatformDocumentTemplateVersion
    {
        return PlatformDocumentTemplateVersion::firstOrCreate(
            ['template_id' => $this->id, 'version' => $this->version],
            ['content' => $this->content(), 'content_hash' => $this->contentHash(), 'saved_by' => $by?->id, 'saved_by_name' => $by?->name],
        );
    }

    /** ما يُعتمد — كلُّ ما يصل إلى المستند من القالب، بلا اسمه الداخليّ ولا تفعيله */
    public function content(): array
    {
        return [
            'service' => $this->service,
            'title' => $this->title,
            'title_en' => $this->title_en,
            'terms' => array_values($this->terms ?? []),
            'form' => $this->form ?: null,
            'includes_plan' => (bool) $this->includes_plan,
            'includes_amount' => (bool) $this->includes_amount,
            'default_amount' => $this->default_amount === null ? null : number_format((float) $this->default_amount, 3, '.', ''),
            'default_amount_label' => $this->default_amount_label,
            'amount_rule' => $this->amount_rule,
        ];
    }

    public function contentHash(): string
    {
        return hash('sha256', json_encode($this->content(), JSON_UNESCAPED_UNICODE));
    }

    /** هل ما في القالب الآن هو ما اعتُمد؟ */
    public function approved(): bool
    {
        return $this->approved_hash !== null && hash_equals($this->approved_hash, $this->contentHash());
    }

    /** منشور: مفعّلٌ ونصُّه هو ما نُشر — يُصدر منه بلا تأكيد */
    public function issuable(): bool
    {
        return $this->active && $this->approved();
    }

    /** draft: مسوّدة · approved: معتمد · legacy: قائمٌ قبل الاعتماد · changed: عُدِّل بعد اعتماده */
    public function approvalState(): string
    {
        if ($this->approved()) {
            return $this->approval_basis === self::LEGACY ? 'legacy' : 'approved';
        }

        return $this->approved_hash !== null ? 'changed' : 'draft';
    }
}
