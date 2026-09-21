<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * تنبيهاتُ العملاء وملاحظاتُهم وأعيادُ ميلادهم — سؤالٌ واحد وجوابٌ واحد.
 *
 * ═══ ما هنا ═══
 *
 * مقابضُ «الإعدادات ← العملاء» بمفاتيحها وافتراضاتها، وما يُقال للكاشير عن
 * زبونٍ اختاره (`context`)، وحارسُ البيع على المحظور (`assertSellable`).
 * يقرؤها الصندوقُ وشاشةُ الطلب ومتحكّمُ الإعدادات — فلا تُكتب القاعدةُ مرّتين.
 *
 * ═══ الغيابُ إذنٌ ═══
 *
 * كلُّ مقبضٍ مفتوحٌ حتى يُطفأ — كوسائل الدفع. ومتجرٌ لم يفتح الإعدادات
 * يرى التنبيهاتِ وأعيادَ الميلاد من أوّل يوم.
 *
 * ═══ والإطفاءُ لا يمحو ═══
 *
 * إطفاءُ التنبيهات يُخفيها من الصندوق والشاشات ولا يمسّ صفوفَ العملاء:
 * من أعاد المقبضَ وجد تنبيهاتِه كما تركها.
 */
final class CustomerFlags
{
    public const ALERTS = 'customer_alerts_enabled';

    public const NOTES_IN_POS = 'show_customer_notes_in_pos';

    public const BLOCKING = 'customer_sale_blocking_enabled';

    public const MANAGER_OVERRIDE = 'customer_block_manager_override';

    public const BIRTHDAYS = 'customer_birthdays_enabled';

    public const BIRTHDAY_POS = 'customer_birthday_pos_reminder';

    public const BIRTHDAY_DAYS = 'customer_birthday_reminder_days';

    /** المقابضُ المنطقيّة — كلُّها مفتوحةٌ افتراضًا */
    public const TOGGLES = [self::ALERTS, self::NOTES_IN_POS, self::BLOCKING, self::MANAGER_OVERRIDE, self::BIRTHDAYS, self::BIRTHDAY_POS];

    public const DEFAULT_REMINDER_DAYS = 7;

    public const MAX_REMINDER_DAYS = 30;

    public const WARNING = 'warning';

    public const BLOCK = 'block';

    public const ALERT_TYPES = [self::WARNING, self::BLOCK];

    /** فعلُ تجاوز الحظر — يُمنح بالاسم أو يُورَّث للمدير، انظر Permissions */
    public const OVERRIDE = 'customer.block_override';

    /** @return array<string, string> مفتاح ← قيمة لهذا المتجر */
    public static function settings(int $businessId): array
    {
        return Setting::where('business_id', $businessId)
            ->whereIn('key', [...self::TOGGLES, self::BIRTHDAY_DAYS])
            ->pluck('value', 'key')->all();
    }

    public static function on(array $settings, string $key): bool
    {
        return ($settings[$key] ?? '1') !== '0';
    }

    public static function reminderDays(array $settings): int
    {
        $n = (int) ($settings[self::BIRTHDAY_DAYS] ?? self::DEFAULT_REMINDER_DAYS);

        return max(0, min(self::MAX_REMINDER_DAYS, $n));
    }

    /**
     * كم يومًا إلى عيد ميلاده القادم — `null` لمن لا ميلادَ له.
     *
     * يُحسب على التقويم لا بمقارنة الشهر واليوم: ميلادُ ٢ يناير في ٢٨ ديسمبر
     * «بعد خمسة أيام» لا «قبل أحد عشر شهرًا». و٢٩ فبراير في سنةٍ لا تحمله
     * يُحتفل به في ٢٨ فبراير — قبل أن ينقضي الشهر، لا في مارس.
     */
    public static function daysToBirthday(Customer $customer, ?CarbonInterface $today = null): ?int
    {
        if ($customer->birth_day === null || $customer->birth_month === null) {
            return null;
        }

        $today = ($today ?? Carbon::today())->copy()->startOfDay();
        $next = self::occurrence((int) $customer->birth_month, (int) $customer->birth_day, (int) $today->year);

        if ($next->lt($today)) {
            $next = self::occurrence((int) $customer->birth_month, (int) $customer->birth_day, (int) $today->year + 1);
        }

        return (int) $today->diffInDays($next);
    }

    private static function occurrence(int $month, int $day, int $year): Carbon
    {
        $lastDay = Carbon::create($year, $month, 1)->daysInMonth;

        return Carbon::create($year, $month, min($day, $lastDay))->startOfDay();
    }

    /**
     * ما يُقال للكاشير عن هذا الزبون — وما لا يُقال.
     *
     * يُبنى في الخادم بالإعدادات لا في الشاشة: ما أُطفئ لا يصل أصلًا، فلا
     * تُرسل ملاحظةٌ داخليّة إلى جهازٍ قيل إنّه لا يراها.
     *
     * @return array{alert: array{type: string, reason: string}|null, birthday_in: int|null, note: string|null}
     */
    public static function context(Customer $customer, array $settings, ?CarbonInterface $today = null): array
    {
        $alert = null;
        if (self::on($settings, self::ALERTS) && in_array($customer->alert_type, self::ALERT_TYPES, true)) {
            $type = $customer->alert_type;
            // حظرٌ والمقبضُ مُطفأ: يُقال تحذيرًا — العلمُ به لا يضرّ، والمنعُ قرارٌ آخر
            if ($type === self::BLOCK && ! self::on($settings, self::BLOCKING)) {
                $type = self::WARNING;
            }
            $alert = ['type' => $type, 'reason' => (string) ($customer->alert_reason ?? '')];
        }

        $birthdayIn = null;
        if (self::on($settings, self::BIRTHDAYS) && self::on($settings, self::BIRTHDAY_POS)) {
            $days = self::daysToBirthday($customer, $today);
            if ($days !== null && $days <= self::reminderDays($settings)) {
                $birthdayIn = $days;
            }
        }

        return [
            'alert' => $alert,
            'birthday_in' => $birthdayIn,
            'note' => self::on($settings, self::NOTES_IN_POS) && filled($customer->notes) ? (string) $customer->notes : null,
        ];
    }

    /** هل البيعُ لهذا الزبون موقوف — بالإعدادات لا بالصفّ وحده */
    public static function blocked(Customer $customer, array $settings): bool
    {
        return $customer->alert_type === self::BLOCK
            && self::on($settings, self::ALERTS)
            && self::on($settings, self::BLOCKING);
    }

    /**
     * لا تُباع بيعةٌ لزبونٍ موقوف — إلّا بتجاوزٍ مقصودٍ ممّن يملكه.
     *
     * التجاوزُ ثلاثةُ شروطٍ معًا: مقبضُ الإعدادات مفتوح، والفاعلُ يملك الفعل،
     * وسببٌ مكتوب. وإغلاقُ نافذةٍ ليس تجاوزًا. ويُقيَّد في السجلّ بالعميل
     * والفاعل والسبب.
     */
    public static function assertSellable(?Customer $customer, array $settings, ?User $actor, ?string $overrideReason): void
    {
        if ($customer === null || ! self::blocked($customer, $settings)) {
            return;
        }

        $mayOverride = self::on($settings, self::MANAGER_OVERRIDE) && $actor !== null && $actor->may(self::OVERRIDE);

        if ($mayOverride && trim((string) $overrideReason) !== '') {
            Activity::log('updated', 'تجاوز حظرَ البيع على «'.$customer->name.'» — '.trim((string) $overrideReason), [
                'subject_id' => $customer->id, 'subject_type' => 'customer',
            ]);

            return;
        }

        throw ValidationException::withMessages([
            'customer_block' => $mayOverride
                ? __('البيع لهذا العميل موقوف — اكتب سببَ التجاوز للمتابعة.')
                : __('البيع لهذا العميل موقوف.'),
        ]);
    }
}
