<?php

namespace App\Models;

use App\Support\DocumentForms;
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
 * ═══ الإصدار منه ═══
 *
 * كلُّ قالبٍ محفوظٍ صالحٍ يُصدر منه مباشرةً (`issuable`) — لا اعتمادَ ولا نشرَ ولا
 * تفعيل. وما فيه خطأ (`problems`) لا يُصدر منه حتى يُصحَّح، ويُقال للمدير ما هو.
 *
 * وتعديلُ ما يصل إلى المستند (`content`: نصُّه ورسومُه وقاعدةُ مبلغه) إصدارٌ جديد
 * (`version`) يُحفظ في سجلّه (`versions`) ببصمته ومن حفظه ومتى، ويُصدر منه فورًا.
 * وما صدر قبله نسخةٌ في مستنده لا تتغيّر.
 *
 * و`active` و`approved_*` من زمن الاعتماد والنشر: باقيةٌ في الصفّ تاريخًا، لا
 * يقرؤها الإصدارُ ولا يكتبها الحفظ.
 */
class PlatformDocumentTemplate extends Model
{
    /** تاريخيّ: وسمٌ كتبته هجرةُ النماذج على العقد القائم قبل الاعتماد — لا يقرؤه الإصدار */
    public const LEGACY = 'legacy';

    /** المبلغُ هو رسومُ القالب — نصُّه يذكرها */
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

    /** تاريخيّ: أكان ما في القالب هو ما اعتُمد زمنَ الاعتماد؟ — يقرؤه سجلُّ الإصدارات ساعةَ أُنشئ */
    public function approved(): bool
    {
        return $this->approved_hash !== null && hash_equals($this->approved_hash, $this->contentHash());
    }

    /** يُصدر منه الآن: محفوظٌ وصالح — لا شرطَ غيرُه */
    public function issuable(): bool
    {
        return $this->problems() === [];
    }

    /**
     * ما يمنع الإصدارَ منه الآن — بعباراتٍ يقرؤها المدير فيصحّحها من شاشة القوالب.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $out = $this->form ? DocumentForms::problems(DocumentForms::normalize($this->form)) : [];
        if (! $this->form && array_filter($this->terms ?? [], fn ($t) => trim((string) ($t['b'] ?? '')) !== '') === []) {
            $out[] = __('القالب بلا بنود.');
        }
        if ($this->needsFee() && $this->default_amount === null) {
            $out[] = __('اكتب رسوم القالب — نصّه يذكرها.');
        }

        return $out;
    }

    /** نصٌّ يذكر رسومه، أو قاعدةُ مبلغٍ تحتاجها: بلا رقمٍ وعدٌ بمبلغٍ لا يُعرف */
    public function needsFee(): bool
    {
        return ($this->includes_amount && $this->amount_rule !== null)
            || array_intersect(['fee', 'fee_short'], DocumentForms::tokens($this->form ?: null)) !== [];
    }
}
