<?php

namespace App\Support;

use App\Models\Setting;
use Carbon\CarbonImmutable;

/**
 * حالُ فوترة Google للمنصّة — وموعدُ انتهاء التجربة.
 *
 * ═══ ولمَ هذا حارسٌ لا زينة ═══
 *
 * تجربةُ Google تسعون يومًا، ثمّ يتوقّف المشروع ما لم يُرفع الحساب بيد
 * صاحبه — Google لا تُحوّله من نفسها ولا تخصم من البطاقة قبل ضغطة.
 *
 * ويومَ تنتهي **تتوقّف الخرائط عن كلّ تجّار المنصّة دفعةً واحدة**: مفتاحٌ
 * واحدٌ يخدم الجميع، فعطبُه عطبٌ للجميع. ولا يقول شيءٌ لماذا — يرى التاجر
 * «رفضت Google المفتاح» فيظنّ العطبَ عندنا، ويراه مديرُ المنصّة بعد أن
 * يشكو أوّلُ تاجر.
 *
 * فالموعدُ يُكتب يومَ يُعرف، ويُنبَّه قبله بأسبوعين.
 *
 * ═══ وثلاثُ حالاتٍ لا اثنتان ═══
 *
 * «مدفوع» و«تجربةٌ تنتهي في…» لا تكفيان: الحالُ الثالثة هي **أنّ أحدًا لم
 * يُسجّل شيئًا** — وهي بعينها ما يُراد كشفُه. فلو جُمعت مع «مدفوع» في
 * غيابٍ واحدٍ لَصمَت النظامُ عن المفتاح الذي لا يعرف أحدٌ متى يموت.
 */
final class GoogleBilling
{
    public const STATE_KEY = 'google_billing_state';

    public const ENDS_KEY = 'google_trial_ends_at';

    /** لم يُسجَّل شيء — وهي الحالُ الافتراضيّة، وتُنبَّه */
    public const UNKNOWN = 'unknown';

    /** تجربةٌ لها موعدُ انتهاء */
    public const TRIAL = 'trial';

    /** حسابٌ مرفوعٌ إلى مدفوع — لا موعدَ ولا تنبيه */
    public const PAID = 'paid';

    public const STATES = [self::UNKNOWN, self::TRIAL, self::PAID];

    /**
     * قبل كم يومًا يبدأ التنبيه.
     *
     * أسبوعان: رفعُ الحساب يحتاج بطاقةً تعمل وقد يحتاج مراجعةَ بنك، ويومٌ
     * واحدٌ لا يكفي لمن يكتشف أنّ بطاقته انتهت صلاحيّتها.
     */
    public const WARN_DAYS = 14;

    public static function state(): string
    {
        $value = (string) self::read(self::STATE_KEY);

        return in_array($value, self::STATES, true) ? $value : self::UNKNOWN;
    }

    public static function endsAt(): ?CarbonImmutable
    {
        $value = trim((string) self::read(self::ENDS_KEY));

        if ($value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->startOfDay();
        } catch (\Throwable) {
            // تاريخٌ لا يُقرأ يُعدّ غيابًا — لا يُرمى استثناءٌ يكسر شاشة الإعدادات
            return null;
        }
    }

    /** كم يومًا بقي — سالبٌ لما مضى، و`null` بلا موعد */
    public static function daysLeft(): ?int
    {
        $ends = self::endsAt();

        return $ends === null ? null : (int) CarbonImmutable::now()->startOfDay()->diffInDays($ends, false);
    }

    /**
     * التنبيه — أو لا شيء.
     *
     * ولا يُنبَّه على منصّةٍ بلا مفتاح: لا شيء يُحمى، والتنبيهُ الذي لا يقابل
     * خطرًا يُقرأ مرّتين ثمّ يُتخطّى — ويمرّ معه الصادقُ يومًا.
     *
     * @return array{level:string, text:string}|null
     */
    public static function alert(): ?array
    {
        if (GoogleReviews::platformKey() === null) {
            return null;
        }

        $state = self::state();

        if ($state === self::PAID) {
            return null;
        }

        if ($state === self::UNKNOWN) {
            return [
                'level' => 'warning',
                'text' => __('مفتاح خرائط Google محفوظ ولم تُسجَّل حال فوترته — سجّلها هنا، فتجربةُ Google تنتهي بلا خبر وتتوقّف الخرائط عن كلّ التجّار.'),
            ];
        }

        $left = self::daysLeft();

        if ($left === null) {
            return [
                'level' => 'warning',
                'text' => __('حالُ الفوترة «تجربة» ولا موعدَ انتهاءٍ مسجَّل — اكتب الموعد ليُنبَّه قبله.'),
            ];
        }

        if ($left < 0) {
            return [
                'level' => 'danger',
                'text' => __('انتهت تجربة Google. إن لم يكن الحساب مرفوعًا إلى مدفوع فالخرائط متوقّفة عن كلّ تجّارك.'),
            ];
        }

        if ($left <= self::WARN_DAYS) {
            return [
                'level' => $left <= 3 ? 'danger' : 'warning',
                'text' => __('تنتهي تجربة Google بعد :n يومًا — ارفع الحساب إلى مدفوع، وإلّا توقّفت الخرائط عن كلّ تجّارك دفعةً واحدة.', ['n' => $left]),
            ];
        }

        return null;
    }

    /** ما تقرؤه الشاشة — بلا تنبيهٍ يُحسب فيها من جديد */
    public static function view(): array
    {
        return [
            'state' => self::state(),
            'ends_at' => self::endsAt()?->toDateString(),
            'days_left' => self::daysLeft(),
            'alert' => self::alert(),
        ];
    }

    /** يحفظ الحال — والموعدُ يُمحى مع كلّ حالٍ ليست تجربة، فلا يبقى تاريخٌ لا يعني شيئًا */
    public static function store(string $state, ?string $endsAt): void
    {
        $state = in_array($state, self::STATES, true) ? $state : self::UNKNOWN;

        self::write(self::STATE_KEY, $state);
        self::write(self::ENDS_KEY, $state === self::TRIAL ? (string) $endsAt : '');
    }

    private static function read(string $key): ?string
    {
        return Setting::whereNull('business_id')->where('key', $key)->value('value');
    }

    private static function write(string $key, string $value): void
    {
        Setting::updateOrCreate(['business_id' => null, 'key' => $key], ['value' => $value]);
    }
}
