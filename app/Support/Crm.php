<?php

namespace App\Support;

/**
 * قواعدُ دفتر مبيعات أبعاد — في موضعٍ واحد.
 *
 * المراحلُ والحالاتُ والمصادرُ وأسبابُ الخسارة تُقرأ من هنا: الشاشةُ ترسمها،
 * والتحقّقُ يقيس عليها، والتقريرُ يجمع بها. وثلاثُ نسخٍ منها تفترق يومًا
 * فتُقبل مرحلةٌ لا يرسمها المسار — ثمّ يضيع صفٌّ بين عمودين.
 */
final class Crm
{
    /* ═══════════════════ المراحل ═══════════════════ */

    /**
     * مسارُ البيع — تسعُ مراحلَ بترتيبها.
     *
     * والترتيبُ بيانٌ لا زينة: منه يُرسم المسار، وبه يُعرف التقدّمُ من
     * التراجع في السجلّ.
     */
    public const NEW = 'new';

    public const CONTACTED = 'contacted';

    public const INTERESTED = 'interested';

    public const QUALIFIED = 'qualified';

    public const TRIAL = 'trial';

    public const QUOTATION = 'quotation';

    public const DECISION = 'decision';

    public const WON = 'won';

    public const LOST = 'lost';

    /** @var list<string> */
    public const STAGES = [
        self::NEW, self::CONTACTED, self::INTERESTED, self::QUALIFIED,
        self::TRIAL, self::QUOTATION, self::DECISION, self::WON, self::LOST,
    ];

    /**
     * ما يُرسم في لوحة المسار — دون الخاتمتين.
     *
     * «مشترك» و«مفقود» نهايتان لا عمودان يُسحب إليهما ويُسحب منهما: الأولى
     * لا تُبلغ إلّا بتحويلٍ حقيقيّ يصنع متجرًا، والثانية لا تُبلغ إلّا بسببٍ
     * مكتوب. وعمودٌ في اللوحة يُخفي هذين الشرطين.
     *
     * @return list<string>
     */
    public static function boardStages(): array
    {
        return [
            self::NEW, self::CONTACTED, self::INTERESTED,
            self::QUALIFIED, self::TRIAL, self::QUOTATION, self::DECISION,
        ];
    }

    /** المراحلُ الحيّة — ما لم يُحسم بعد */
    public static function openStages(): array
    {
        return self::boardStages();
    }

    public static function stageLabel(string $stage): string
    {
        return __(match ($stage) {
            self::NEW => 'جديد',
            self::CONTACTED => 'تم التواصل',
            self::INTERESTED => 'مهتم',
            self::QUALIFIED => 'مؤهل',
            self::TRIAL => 'تجربة',
            self::QUOTATION => 'عرض سعر',
            self::DECISION => 'قرار',
            self::WON => 'تم الاشتراك',
            self::LOST => 'مفقود',
            default => $stage,
        });
    }

    /** لونُ الشارة — من ألوان النظام لا من لوحةٍ ثانية */
    public static function stageTone(string $stage): string
    {
        return match ($stage) {
            self::NEW => 'info',
            self::CONTACTED, self::INTERESTED => 'primary',
            self::QUALIFIED, self::TRIAL => 'warning',
            self::QUOTATION, self::DECISION => 'warning',
            self::WON => 'success',
            self::LOST => 'danger',
            default => 'gray',
        };
    }

    /* ═══════════════════ الحال ═══════════════════ */

    public const ACTIVE = 'active';

    public const CONVERTED = 'converted';

    public const LOST_STATUS = 'lost';

    /** @var list<string> */
    public const STATUSES = [self::ACTIVE, self::CONVERTED, self::LOST_STATUS];

    public static function statusLabel(string $status): string
    {
        return __(match ($status) {
            self::ACTIVE => 'نشط',
            self::CONVERTED => 'تم الاشتراك',
            self::LOST_STATUS => 'مفقود',
            default => $status,
        });
    }

    /**
     * الحالُ الذي تفرضه المرحلة — ولا يُكتب باليد.
     *
     * حقلان يقولان الشيء نفسه يفترقان يومًا: صفٌّ مرحلتُه «مشترك» وحالُه
     * «نشط» يُعدّ مرّتين — مرّةً في المشتركين ومرّةً في قائمة المتابعة.
     * فالحالُ يُشتقّ هنا ولا يُقبل من الطلب.
     */
    public static function statusFor(string $stage): string
    {
        return match ($stage) {
            self::WON => self::CONVERTED,
            self::LOST => self::LOST_STATUS,
            default => self::ACTIVE,
        };
    }

    /* ═══════════════════ المصادر ═══════════════════ */

    public const SOURCE_WHATSAPP = 'whatsapp';

    public const SOURCE_MANUAL = 'manual';

    /** @var list<string> */
    public const SOURCES = [
        self::SOURCE_WHATSAPP, 'phone', self::SOURCE_MANUAL,
        'website', 'referral', 'instagram',
    ];

    public static function sourceLabel(string $source): string
    {
        return __(match ($source) {
            self::SOURCE_WHATSAPP => 'واتساب',
            'phone' => 'اتصال هاتفي',
            self::SOURCE_MANUAL => 'إدخال يدوي',
            'website' => 'الموقع الإلكتروني',
            'referral' => 'ترشيح',
            'instagram' => 'إنستغرام',
            default => $source,
        });
    }

    /* ═══════════════════ أسبابُ الخسارة ═══════════════════ */

    /** @var list<string> */
    public const LOST_REASONS = [
        'price', 'competitor', 'missing_feature', 'not_ready', 'no_response', 'other',
    ];

    public static function lostReasonLabel(string $reason): string
    {
        return __(match ($reason) {
            'price' => 'السعر',
            'competitor' => 'اختار منافسًا',
            'missing_feature' => 'ميزة مطلوبة غير متوفرة',
            'not_ready' => 'ليس الوقت المناسب',
            'no_response' => 'لم يرد',
            'other' => 'أخرى',
            default => $reason,
        });
    }

    /* ═══════════════════ المهام ═══════════════════ */

    /** @var list<string> */
    public const TASK_STATUSES = ['open', 'done', 'cancelled'];

    /** @var list<string> */
    public const TASK_PRIORITIES = ['low', 'normal', 'high'];

    public static function taskStatusLabel(string $status): string
    {
        return __(match ($status) {
            'open' => 'مفتوحة',
            'done' => 'منجزة',
            'cancelled' => 'ملغاة',
            default => $status,
        });
    }

    public static function taskPriorityLabel(string $priority): string
    {
        return __(match ($priority) {
            'low' => 'منخفضة',
            'normal' => 'عادية',
            'high' => 'عالية',
            default => $priority,
        });
    }

    /* ═══════════════════ خياراتُ الشاشة ═══════════════════ */

    /**
     * قائمةٌ منسدلة — قيمةٌ وتسمية.
     *
     * @param  list<string>  $values
     * @return list<array{value:string,label:string}>
     */
    public static function options(array $values, callable $label): array
    {
        return array_map(fn (string $v) => ['value' => $v, 'label' => $label($v)], $values);
    }
}
