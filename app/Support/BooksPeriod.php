<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * تاريخُ التصحيح المحاسبيّ — حين يُصحَّح قيدٌ بعد شهره.
 *
 * ═══ العطبُ الذي أنشأه ═══
 *
 * حذفُ مصروفٍ يعكس قيدَه بيوم الحذف، وكلُّ عكسٍ في الدفتر كذلك. فمصروفٌ من
 * سبتمبر يُحذف في أكتوبر يبقى في سبتمبر ويُطرح من أكتوبر: سبتمبر منفوخٌ
 * بمصروفٍ لم يقع، وأكتوبر سالبٌ بمصروفٍ لم يُردّ — وقيس على الإنتاج: متجرٌ
 * حذف مصروفين مكرَّرين من سبتمبر يومَ ١ أكتوبر فقرأ «إجمالي المصروفات
 * −14.250» على لوحته. والاستعادةُ أسوأ: كانت تُعيد القيدَ بتاريخ المصروف
 * الأصليّ، فيتضاعف الشهرُ الأوّل ويبقى الثاني سالبًا.
 *
 * ═══ ولمَ لا يُعاد التصحيحُ إلى شهره دائمًا ═══
 *
 * لأنّ شهرًا مضى قد يكون أُقفل: قُدِّم إقرارُه، أو قرأ المحاسبُ أرقامَه
 * وبنى عليها. وتصحيحٌ يُكتب في شهرٍ مقفل يغيّر تقريرًا صدر — بلا ورقة.
 * ولم يكن في النظام إقفالُ فترات، فلا يُعرف أيُّ شهرٍ مقفل. ولا يُفترض أنّه
 * مفتوح: الافتراضُ هو ما يكتب في دفاتر التاجر ما لم يقرّره.
 *
 * ═══ فالقاعدة ═══
 *
 *   - تصحيحٌ في شهر القيد نفسِه: بيومه كما كان — لا يعبر حدًّا، ولا سؤال.
 *   - تصحيحٌ يعبر حدَّ شهر: لا يقع إلّا باختيارٍ صريحٍ وسببٍ مكتوب:
 *       · «في الفترة الحاليّة» — بيوم التصحيح، ويُوسم تصحيحَ فترةٍ سابقة.
 *       · «بتاريخ القيد الأصليّ» — لصاحب النشاط وحده، وفي فترةٍ مفتوحةٍ
 *         معلومة: بعد تاريخ إقفال الدفاتر الذي حدّده التاجر، أو الشهرِ
 *         الجاري وما بعده. وما لم يُحدَّد إقفالٌ فالشهرُ الماضي مجهولٌ
 *         لا مفتوح.
 *   - والمقفلُ لا يُكتب فيه شيءٌ من هنا أبدًا: تاريخُ إقفال الدفاتر، أو آخرُ
 *     إقرارٍ ضريبيٍّ قُدِّم — أيُّهما أبعد.
 *
 * والتاريخُ الفعليّ للتصحيح لا يضيع: القيدُ يحمل `created_at` و`created_by`
 * مهما كان تاريخُه المحاسبيّ، ووصفُه يقول التاريخين والسبب.
 */
class BooksPeriod
{
    /** آخرُ يومٍ أقفل التاجرُ دفاترَه — مفتاحُه في الإعدادات */
    public const SETTING = 'books_closed_through';

    /** مكانُ قيد التصحيح: يومُ وقوعه */
    public const CURRENT = 'current';

    /** أو تاريخُ القيد الذي يصحّحه */
    public const ORIGINAL = 'original';

    public const MODES = [self::CURRENT, self::ORIGINAL];

    /**
     * أطولُ سببٍ يُقبل — ليبقى كاملًا في وصف القيد وسطرِ النشاط.
     *
     * كلاهما عمودٌ بـ255 حرفًا، والسببُ يُكتب بعد اسم الفعل والتاريخين.
     * وكان 200: سببٌ طويلٌ يُقطع ذيلُه في القيد، ويُسقط سطرَ النشاط على
     * PostgreSQL بعد أن تمّ الحذفُ نفسُه — فيرى التاجرُ خطأً عن شيءٍ وقع.
     */
    public const REASON_MAX = 120;

    public const OPEN = 'open';

    public const CLOSED = 'closed';

    /** شهرٌ مضى ولم يقل التاجرُ أين أقفل دفاترَه — لا يُفترض مفتوحًا */
    public const UNKNOWN = 'unknown';

    /** تاريخُ إقفال الدفاتر كما حدّده التاجر — بلا الإقرار الضريبيّ */
    public static function booksClosedThrough(int $businessId): ?Carbon
    {
        $value = Setting::where('business_id', $businessId)->where('key', self::SETTING)->value('value');

        return $value ? Carbon::parse($value)->endOfDay() : null;
    }

    /** آخرُ يومٍ مقفل لأيّ سبب: الدفاتر أو الإقرار، أيُّهما أبعد */
    public static function closedThrough(int $businessId): ?Carbon
    {
        $dates = array_filter([self::booksClosedThrough($businessId), Vat::filedThrough($businessId)]);

        return $dates ? max($dates) : null;
    }

    /**
     * ما يحتاجه السؤالُ عن صفوفٍ كثيرة — يُقرأ مرّةً لا لكلّ صفّ.
     *
     * @return array{closed: ?Carbon, declared: bool, owner: bool}
     */
    public static function context(int $businessId, ?User $user): array
    {
        return [
            'closed' => self::closedThrough($businessId),
            'declared' => self::booksClosedThrough($businessId) !== null,
            'owner' => $user !== null && Permissions::isOwner($user),
        ];
    }

    /** هل تقع هذه الفترةُ مقفلةً أم مفتوحةً أم مجهولة؟ */
    public static function status(array $context, Carbon $date): string
    {
        if ($context['closed'] && $date->lte($context['closed'])) {
            return self::CLOSED;
        }

        // الشهرُ الجاري وما بعده فترةُ العمل نفسُها — تُرحَّل فيها مبيعاتُ اليوم
        if ($date->gte(now()->startOfMonth())) {
            return self::OPEN;
        }

        return $context['declared'] ? self::OPEN : self::UNKNOWN;
    }

    /**
     * التاريخُ نفسُه ما لم يكن مقفلًا — وإلّا فيومُ التصحيح.
     *
     * لتاريخٍ يختاره النظامُ لا التاجر (قيدٌ يُعاد بتاريخ القيد الذي يقابله
     * في الشهر نفسه): إقفالٌ وقع داخل الشهر الجاري لا يُكتب خلفه.
     */
    public static function openOr(int $businessId, Carbon $date): Carbon
    {
        $closed = self::closedThrough($businessId);

        return $closed && $date->lte($closed) ? now() : $date;
    }

    /** هل يعبر تصحيحُ قيدٍ بهذا التاريخ حدَّ شهر؟ */
    public static function crosses(Carbon $entryDate): bool
    {
        return ! $entryDate->isSameMonth(now());
    }

    /**
     * ما تعرضه الشاشة عن تصحيح قيدٍ بهذا التاريخ — ليُسأل قبل الضغط لا بعده.
     *
     * @return array{crosses: bool, entry_date: ?string, status: ?string, original_allowed: bool, closed_through: ?string}
     */
    public static function describe(array $context, ?Carbon $entryDate): array
    {
        if ($entryDate === null || ! self::crosses($entryDate)) {
            return ['crosses' => false, 'entry_date' => $entryDate?->toDateString(), 'status' => null,
                'original_allowed' => false, 'closed_through' => $context['closed']?->toDateString()];
        }

        $status = self::status($context, $entryDate);

        return [
            'crosses' => true,
            'entry_date' => $entryDate->toDateString(),
            'status' => $status,
            'original_allowed' => $status === self::OPEN && $context['owner'],
            'closed_through' => $context['closed']?->toDateString(),
        ];
    }

    /**
     * التاريخُ المحاسبيّ لتصحيح قيدٍ تاريخُه `$entryDate`.
     *
     * يردّ `null` حين لا يعبر التصحيحُ حدَّ شهر — فيبقى المستدعي على تاريخه
     * المعتاد. ويردّ تاريخًا صريحًا حين يعبر، بعد أن يتحقّق من الاختيار
     * والسبب والفترة والصلاحية.
     *
     * @throws ValidationException
     */
    public static function resolve(int $businessId, Carbon $entryDate, ?string $mode, ?string $reason, ?User $user): ?Carbon
    {
        if (! self::crosses($entryDate)) {
            return null;
        }

        $context = self::context($businessId, $user);
        $closed = $context['closed']?->toDateString();

        if (! in_array($mode, self::MODES, true)) {
            throw ValidationException::withMessages(['correction' => __(
                'قيدُ هذا المصروف بتاريخ :date، في شهرٍ غير الشهر الجاري. اختر أين يُسجَّل التصحيح، واكتب سببه.',
                ['date' => $entryDate->toDateString()],
            )]);
        }

        if (trim((string) $reason) === '') {
            throw ValidationException::withMessages(['correction_reason' => __('اكتب سبب تصحيح الفترة السابقة — يُحفظ مع القيد.')]);
        }

        if ($mode === self::CURRENT) {
            return now();
        }

        return match (self::status($context, $entryDate)) {
            self::CLOSED => throw ValidationException::withMessages(['correction' => __(
                'الدفاتر مقفلة حتى :closed، فلا يُكتب في فترة :date شيء. سجّل التصحيح في الفترة الحاليّة.',
                ['closed' => $closed, 'date' => $entryDate->toDateString()],
            )]),
            self::UNKNOWN => throw ValidationException::withMessages(['correction' => __(
                'لم يُحدَّد تاريخ إقفال الدفاتر في الإعدادات، فلا يُفترض أنّ شهرًا مضى مفتوح. سجّل التصحيح في الفترة الحاليّة، أو حدّد تاريخ الإقفال أوّلًا.',
            )]),
            default => $context['owner']
                ? $entryDate->copy()
                : throw ValidationException::withMessages(['correction' => __('التصحيح بتاريخ فترةٍ سابقة لصاحب النشاط وحده.')]),
        };
    }

    /**
     * سطرٌ في عمودٍ بـ255 حرفًا — يُقصَّر صدرُه، ويبقى سببُ التصحيح كاملًا.
     *
     * وصفُ القيد وسطرُ النشاط عمودان بـ255. والقطعُ من الآخر يُسقط السببَ
     * أوّلًا — وهو ما يُسأل عنه بعد سنة — حين يطول نوعُ المصروف أو مرجعُه.
     */
    public static function fit(string $head, ?string $tail = null, int $max = 255): string
    {
        $tail = $tail !== null && $tail !== '' ? ' — '.$tail : '';

        return mb_substr(mb_substr($head, 0, max(0, $max - mb_strlen($tail))).$tail, 0, $max);
    }

    /**
     * وصفُ التصحيح كما يُكتب على قيده — التاريخان والسبب.
     *
     * لا يُعاد تأريخُ قيدٍ قائم أبدًا: التصحيحُ قيدٌ جديد، وهذا ما يقوله عن نفسه.
     */
    public static function reason(string $action, Carbon $entryDate, ?Carbon $at, ?string $why): string
    {
        if ($at === null) {
            return $action;
        }

        return $action.' — '.__('تصحيح فترة سابقة (القيد :entry، التصحيح :at): :why', [
            'entry' => $entryDate->toDateString(),
            'at' => $at->toDateString(),
            'why' => trim((string) $why),
        ]);
    }
}
