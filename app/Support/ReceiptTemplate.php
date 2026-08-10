<?php

namespace App\Support;

use App\Models\Setting;

/**
 * قالب الإيصال — إعدادات «قوالب» مقروءةً بشكلٍ يفهمه العرض.
 *
 * والترشيح في PHP لا في SQL عن قصد: `LIKE 'tpl\_%'` يعمل على MySQL وحده،
 * لأن الشرطة المائلة ليست رمز الهروب الافتراضي في SQLite ولا PostgreSQL —
 * فيعود الاستعلام فارغًا هناك، ويطبع الإيصال متجاهلًا كل ما ضبطه التاجر
 * بلا خطأ ولا أثر. وإعدادات النشاط عشرات لا آلاف، فالفرق لا يُقاس.
 */
class ReceiptTemplate
{
    /** @return array<string, string|bool> */
    public static function forBusiness(int $businessId): array
    {
        return Setting::where('business_id', $businessId)
            ->pluck('value', 'key')
            ->filter(fn ($v, $k) => str_starts_with($k, 'tpl_') || in_array($k, ['paper', 'vat_number'], true))
            // مفاتيح tpl_show_* أعلامٌ لا نصوص: «0» نصًّا صادقةٌ في PHP
            ->map(fn ($v, $k) => str_starts_with($k, 'tpl_show_') ? $v === '1' : $v)
            ->all();
    }

    /**
     * نصٌّ صالحٌ لخطّ الإيصال.
     *
     * خطّ الـPDF عربيّ ولاتينيّ ولا يحمل الرموز التعبيرية، فكل إيموجي يخرج
     * مربّعًا فارغًا (▯) على ورق الزبون. وكان التذييل الافتراضي نفسه يحمل 🌹
     * — أي أن كل إيصالٍ يُطبع بمربّعٍ فيه منذ اليوم الأول، ولا يظهر في أي
     * اختبار لأن الاختبارات تقرأ البايتات لا الحروف المرسومة.
     *
     * والحذف عند العرض لا عند الحفظ: التاجر يكتب ما شاء في إعداداته وتبقى
     * كلمته كما كتبها، ولا يُمحى من إيصاله إلا ما لا يُطبع أصلًا.
     */
    public static function printable(string $text): string
    {
        // نطاقات الرموز التعبيرية والصور الرمزية والأعلام وما يلحق بها
        $stripped = preg_replace(
            '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE00}-\x{FE0F}\x{2190}-\x{21FF}\x{2B00}-\x{2BFF}\x{200D}]/u',
            '',
            $text,
        ) ?? $text;

        // مسافةٌ مزدوجة تبقى مكان المحذوف، وسطرٌ صار فارغًا يُطوى
        return trim(preg_replace('/[ \t]{2,}/', ' ', $stripped) ?? $stripped);
    }

    /**
     * سطرُ تذييلٍ جاهزٌ للطباعة، بحروفه اللاتينية معزولةً عن اتجاه السطر.
     *
     * سطرٌ عربيّ فيه «@abaad» يُطبع «abaad@»: الرمز محايد الاتجاه فيتبع
     * السطر لا الكلمة، فينتقل إلى آخرها. ويصيب كل بريدٍ أو حسابٍ أو رقم
     * هاتفٍ بمقدّمة دولية يكتبه التاجر في تذييله — ولا يلاحظه هو، لأنه يعرف
     * ما كتب ويقرؤه صحيحًا في رأسه. من يقرؤه غلطًا هو الزبون.
     *
     * والعزل بـdir="ltr" لا بإعادة ترتيبٍ يدوية: mPDF يفهمه، والنصّ المخزَّن
     * يبقى كما كتبه صاحبه.
     */
    public static function printableHtml(string $line): string
    {
        $clean = self::printable($line);

        if ($clean === '') {
            return '';
        }

        /*
         * التقسيم قبل الهروب لا بعده.
         *
         * الهروب يحوّل «&» إلى «&amp;»، فيصير فيه حروفٌ لاتينية يلتقطها
         * التعبير ويلفّها — فيظهر «amp;» في الإيصال. فيُقسَّم النصّ الأصلي،
         * ويُهرَّب كل جزءٍ وحده.
         */
        $parts = preg_split(
            '/([A-Za-z0-9][A-Za-z0-9@._+\-\/:]*)/u',
            $clean,
            -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY,
        ) ?: [$clean];

        return collect($parts)
            ->map(fn (string $part) => preg_match('/^[A-Za-z0-9]/', $part)
                ? '<span dir="ltr">'.e($part).'</span>'
                : e($part))
            ->implode('');
    }
}
