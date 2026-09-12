<?php

namespace App\Support;

use App\Models\Plan;
use App\Models\Setting;

/**
 * ما يُسمَح للمساعد أن يقوله عن أبعاد — ولا شيء سواه.
 *
 * ═══ ولمَ لا تُكتب الأسعار في التعليمات ═══
 *
 * السعرُ يتغيّر في شاشة الباقات، ونصٌّ مكتوبٌ في مطالبةٍ لا يتغيّر معه. فلو
 * كُتب هنا «تسعة ريالات» لَبقي المساعدُ يقولها بعد أن صارت اثني عشر —
 * ويَعِد عميلًا بسعرٍ لا نبيع به. ووعدُ سعرٍ أخطرُ من عدم الردّ.
 *
 * فالأسعارُ تُقرأ من `plans` عند كلّ سؤال. وباقةٌ لا سعرَ لها تُقال «غير
 * معلن» ولا يُخترع لها رقم.
 *
 * ═══ وما ليس هنا لا يُقال ═══
 *
 * المساعدُ يُمنع صراحةً من وعدِ ميزةٍ ليست في هذه القائمة. والقائمةُ تُبنى
 * من `Plan::capabilities` و`PlanFeatures` — أي ممّا يُشغّله النظام فعلًا،
 * لا ممّا نتمنّاه.
 */
final class CrmKnowledge
{
    /**
     * الباقاتُ كما هي في القاعدة الآن.
     *
     * @return list<array<string, mixed>>
     */
    public static function plans(): array
    {
        return Plan::orderBy('monthly_price')->get()->map(fn (Plan $p) => [
            'name' => $p->name,
            /* والسعرُ نصًّا كما يُعرض — لا رقمًا يُعيد النموذج تنسيقَه */
            'monthly' => self::price($p->monthly_price),
            'yearly' => self::price($p->yearly_price),
            'max_branches' => self::limit($p->max_branches),
            'max_employees' => self::limit($p->max_employees),
            'max_products' => self::limit($p->max_products),
            'features' => array_values(array_filter((array) ($p->features ?? []))),
        ])->all();
    }

    /** مدّةُ التجربة كما يضبطها مدير المنصّة — لا كما نتذكّرها */
    public static function trialDays(): ?int
    {
        $value = Setting::whereNull('business_id')->where('key', 'trial_days')->value('value');

        return filled($value) ? (int) $value : null;
    }

    /**
     * ما يفعله النظام — عناوينُ أقسامه لا وعودُ تسويق.
     *
     * ومصدرُها `Permissions::SECTIONS`: ما يُعرض في اللوحة فعلًا. فقائمةٌ
     * تُكتب باليد تحمل بعد سنةٍ ميزةً حُذفت — ويَعِد بها المساعد.
     *
     * @return list<string>
     */
    public static function capabilities(): array
    {
        return array_values(array_unique(array_filter(Permissions::sectionLabels())));
    }

    /**
     * نصُّ المعرفة كما يُمرَّر إلى النموذج.
     *
     * ويُبنى في كلّ نداء: بناؤه مرّةً وتخزينُه يعني سعرًا قديمًا يُقال بعد
     * تعديله.
     */
    public static function text(): string
    {
        /*
         * ═══ والمعرفةُ تُبنى بالعربيّة دائمًا ═══
         *
         * أسماءُ الأقسام تُقرأ من `Permissions::sectionLabels()` — وهي مصدرٌ
         * مشترَكٌ يترجم نفسَه بلغة الواجهة. فموظّفٌ واجهتُه إنجليزيّة كان
         * يُرسل إلى النموذج معرفةً بأسماءَ إنجليزيّة، وموظّفٌ آخر عن العميل
         * نفسِه يُرسل عربيّة — فيأتي اقتراحان بأسلوبين.
         *
         * والحلُّ تثبيتُ اللغة هنا لا نسخُ القائمة: نسختان تفترقان يومًا
         * فيَعِد المساعدُ بقسمٍ حُذف. ولغةُ الردّ يقرّرها العميلُ لا نحن —
         * والقاعدةُ في المطالبة تقول ذلك.
         */
        $locale = app()->getLocale();
        app()->setLocale('ar');

        try {
            return self::build();
        } finally {
            app()->setLocale($locale);
        }
    }

    private static function build(): string
    {
        $lines = ['== معرفةٌ معتمَدة عن أبعاد — لا تُقل ما ليس فيها =='];

        $trial = self::trialDays();
        $lines[] = $trial !== null
            ? 'مدّة التجربة المجّانية: '.$trial.' يومًا.'
            : 'مدّة التجربة: غير محدّدة في النظام — لا تَعِد بمدّة.';

        $plans = self::plans();

        if ($plans === []) {
            /* ولا باقاتٍ في القاعدة: يُقال ذلك صراحةً فلا يُخترع سعر */
            $lines[] = 'لا باقات مسجّلة في النظام — لا تذكر سعرًا بحال، واعرض أن يتواصل معه أحد الفريق.';
        } else {
            $lines[] = 'الباقات وأسعارها الآن:';

            foreach ($plans as $plan) {
                $lines[] = '- '.$plan['name']
                    .' · '.'شهريًّا'.': '.$plan['monthly']
                    .' · '.'سنويًّا'.': '.$plan['yearly']
                    .' · '.'الفروع'.': '.$plan['max_branches']
                    .' · '.'الموظفون'.': '.$plan['max_employees']
                    .' · '.'المنتجات'.': '.$plan['max_products'];
            }
        }

        $lines[] = 'أقسام النظام: '.implode('، ', self::capabilities());

        return implode("\n", $lines);
    }

    /* ═══════════════════ أدواتٌ ═══════════════════ */

    /**
     * السعرُ مكتوبًا بعملة المنصّة — لا رقمًا عاريًا.
     *
     * ورقمٌ بلا عملةٍ يقرؤه النموذجُ ويكتبه للعميل «٩» — فيفهمها العميل
     * دولاراتٍ أو دراهم. والعملةُ جزءٌ من السعر لا زينةٌ عليه.
     *
     * وصفرٌ أو فراغٌ «غير معلن»: باقةٌ لم يُسعّرها أحدٌ بعد لا يُخترع لها رقم.
     */
    private static function price(mixed $value): string
    {
        if ($value === null || $value === '' || (float) $value <= 0) {
            return 'غير معلن';
        }

        return Money::format((float) $value, Demo::baseCurrency());
    }

    private static function limit(mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'غير محدّد';
        }

        // و‎-1 بلا حدّ كعادة النظام — انظر PlanLimits
        return (int) $value < 0 ? 'بلا حدّ' : (string) (int) $value;
    }
}
