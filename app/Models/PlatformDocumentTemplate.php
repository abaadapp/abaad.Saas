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
 * ═══ الاعتماد ═══
 *
 * لا يُصدر مستندٌ من نصٍّ لم يُعتمد. والاعتمادُ بصمةُ المحتوى ساعتَها
 * (`approved_hash`، انظر `content`): نصُّه ورسومُه وقاعدةُ مبلغه. فإن عُدِّل
 * شيءٌ منها بعده لم تعد البصمةُ تطابق، فلا يُصدر منه حتى يُعتمد إصدارُه الجديد
 * (`version`). وما صدر قبلها نسخةٌ في مستنده لا تتغيّر.
 *
 * والقالبُ القائم قبل سياسة الاعتماد (`approval_basis = legacy`) يبقى يعمل كما
 * كان — لم يراجعه أحدٌ بهذه الشاشة، فلا يُقال «معتمد»، ويحتاج اعتمادًا إن عُدِّل.
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

    /** يُصدر منه: مفعّلٌ ونصُّه هو المعتمد */
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
