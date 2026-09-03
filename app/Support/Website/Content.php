<?php

namespace App\Support\Website;

/**
 * ما يُكتب في القسم يُنظَّف قبل أن يُحفظ — لا قبل أن يُعرض.
 *
 * محتوى الأقسام يخرج إلى موقعٍ عامّ يقرؤه الناس ومحرّكات البحث، ويُقدَّم من
 * مستودعٍ آخر (`abaadapp/Website`) لا يعرف من أين جاء. فلو خرج نصٌّ فيه وسمُ
 * `<script>` لصار عطبًا أمنيًّا في موقع التاجر لا خطأً في شاشة.
 *
 * والتنظيف هنا لا هناك عمدًا: القارئ قد يتعدّد — موقعٌ اليوم، وتطبيقٌ غدًا،
 * وواجهةُ معاينةٍ الآن — وحارسٌ في كلّ قارئ يعني حارسًا يُنسى. وما يُحفظ
 * نظيفًا يخرج نظيفًا إلى كلّ قارئ.
 *
 * وثلاثةٌ يحرسها هذا الملفّ:
 *
 * ١) **المفتاح المجهول يسقط.** ما ليس في وصف القسم لا يُحفظ — فطلبٌ ملفَّق
 *    لا يزرع حقلًا لا يعرفه أحد.
 * ٢) **الرابط لا يكون `javascript:`.** زرٌّ في موقع التاجر وجهتُه سطرُ كود
 *    يُنفَّذ في متصفّح كلّ زائر. فالمسموح نسبيٌّ يبدأ بـ`/`، أو `https`
 *    و`http`، أو `tel:` و`mailto:` — وما سواها يسقط إلى فراغ.
 * ٣) **القيمة تُقصَر إلى حدّها.** عنوانٌ بألف حرف يكسر كلّ تصميم، ونصٌّ
 *    بمليون حرف يملأ القاعدة. والحدّ في وصف الحقل، فيُقرأ منه لا يُكتب هنا.
 */
class Content
{
    /** أطول قائمة في قسم — أربعون صورةً في معرضٍ واحد كثير */
    public const MAX_ITEMS = 40;

    /** بروتوكولات الروابط المسموحة */
    private const SCHEMES = ['https', 'http', 'mailto', 'tel'];

    /**
     * محتوى قسمٍ نظيفًا: ما يعرفه الوصف وحده، بأنواعه وحدوده.
     *
     * والناقص يُملأ بافتراضيّه لا يُترك غائبًا: قارئ الموقع يقرأ المفتاح ولا
     * يفحص وجوده، ومفتاحٌ غائبٌ يصير خطأً في العرض لا حقلًا فارغًا.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function clean(string $type, array $input, string $goal = 'store'): array
    {
        $out = [];

        foreach (Sections::CATALOGUE[$type]['fields'] ?? [] as $key => $field) {
            if (isset($field['goals']) && ! in_array($goal, $field['goals'], true)) {
                continue;
            }

            $out[$key] = array_key_exists($key, $input)
                ? self::field($field, $input[$key])
                : $field['default'];
        }

        return $out;
    }

    /** قيمةُ حقلٍ واحد بحسب نوعه */
    private static function field(array $field, mixed $value): mixed
    {
        return match ($field['type']) {
            'text' => self::text($value, $field['max'] ?? 255),
            'textarea' => self::text($value, $field['max'] ?? 2000),
            'link', 'image' => self::url($value),
            'number' => self::number($value, $field),
            'select' => self::choice($value, $field),
            'toggle' => (bool) filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'date' => self::date($value),
            'list' => self::list($value, $field['item'] ?? []),
            'products' => self::ids($value),
            'social' => self::social($value),
            default => $field['default'],
        };
    }

    /**
     * نصٌّ بلا وسوم ولا أسطرٍ زائدة.
     *
     * `strip_tags` لا `htmlspecialchars`: الترميز يجعل «شركة الورود & العطور»
     * تُقرأ `&amp;` في كلّ موضعٍ يعرضها بلا فكّ ترميز، ويتراكم الترميز على
     * نفسه في كلّ حفظ. والوسم يُحذف حذفًا فلا يبقى منه شيء يُرمَّز.
     */
    private static function text(mixed $value, int $max): string
    {
        $raw = (string) (is_scalar($value) ? $value : '');

        /*
         * و«script» يُحذف بمحتواه لا بوسمه وحده.
         *
         * `strip_tags` تُبقي ما بين الوسمين: `<script>alert(1)</script>` تصير
         * `alert(1)` نصًّا ظاهرًا في الموقع. وهو لا يُنفَّذ — لكنّه يظهر
         * لزائرٍ يقرأ سطر كودٍ في عنوان الصفحة، وذلك عطبٌ في الشكل لا يقلّ
         * سوءًا عن عطبٍ في الأمن.
         */
        $raw = preg_replace('#<(script|style|iframe)\b[^>]*>.*?</\1>#is', '', $raw) ?? $raw;

        $clean = trim(strip_tags($raw));
        // الفراغات المتتالية والأسطر الفارغة الثلاثة لا تعني شيئًا في العرض
        $clean = preg_replace("/\n{3,}/", "\n\n", $clean) ?? $clean;

        return mb_substr($clean, 0, max(1, $max));
    }

    /** رابطٌ نسبيٌّ أو ببروتوكولٍ مأمون — وما سواه فراغ */
    private static function url(mixed $value): string
    {
        $raw = trim((string) (is_scalar($value) ? $value : ''));

        if ($raw === '') {
            return '';
        }

        // النسبيّ: مسارٌ داخل الموقع نفسه. و`//host` ليس نسبيًّا رغم شكله
        if (str_starts_with($raw, '/') && ! str_starts_with($raw, '//')) {
            return mb_substr($raw, 0, 500);
        }

        $scheme = mb_strtolower((string) parse_url($raw, PHP_URL_SCHEME));

        return in_array($scheme, self::SCHEMES, true) ? mb_substr($raw, 0, 500) : '';
    }

    private static function number(mixed $value, array $field): int
    {
        $n = (int) (is_numeric($value) ? $value : ($field['default'] ?? 0));

        return max((int) ($field['min'] ?? 0), min((int) ($field['max'] ?? PHP_INT_MAX), $n));
    }

    /** خيارٌ من قائمته — والمجهول يعود إلى الافتراضيّ */
    private static function choice(mixed $value, array $field): string
    {
        $raw = (string) (is_scalar($value) ? $value : '');

        return array_key_exists($raw, $field['options'] ?? []) ? $raw : (string) $field['default'];
    }

    private static function date(mixed $value): string
    {
        $raw = trim((string) (is_scalar($value) ? $value : ''));

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1 ? $raw : '';
    }

    /**
     * قائمةُ عناصرَ لكلٍّ منها حقولُه — والعنصر الفارغ تمامًا يسقط.
     *
     * سطرٌ أضافه التاجر ثمّ تركه بلا ملء يصير في الموقع بطاقةً فارغة. وحذفُه
     * عند الحفظ يوافق ما يتوقّعه: لم أكتب فيه شيئًا فلا شيء فيه.
     *
     * @param  array<string, mixed>  $itemSpec
     */
    private static function list(mixed $value, array $itemSpec): array
    {
        if (! is_array($value) || $itemSpec === []) {
            return [];
        }

        $out = [];

        foreach (array_slice(array_values($value), 0, self::MAX_ITEMS) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $item = [];
            foreach ($itemSpec as $key => $field) {
                $item[$key] = array_key_exists($key, $row) ? self::field($field, $row[$key]) : $field['default'];
            }

            $filled = collect($item)->contains(fn ($v) => is_string($v) ? trim($v) !== '' : ! empty($v));
            if ($filled) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /** أرقام منتجاتٍ مختارة — أعدادٌ صحيحة موجبة بلا تكرار */
    private static function ids(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return collect($value)->map(fn ($v) => (int) $v)->filter(fn ($v) => $v > 0)
            ->unique()->take(self::MAX_ITEMS)->values()->all();
    }

    /**
     * حسابات التواصل: شبكةٌ معروفة واسمُ حساب.
     *
     * والشبكة من القائمة لا من المُدخَل: اسمٌ مجهول يعني أيقونةً لا وجود لها
     * ورابطًا لا يُبنى. والمكرّر يسقط — حسابا إنستغرام في تذييلٍ واحد خطأٌ
     * لا خيار.
     */
    private static function social(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        $seen = [];

        foreach (array_values($value) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $network = (string) ($row['network'] ?? '');
            $handle = self::text($row['value'] ?? '', 120);
            // «@name» يُكتب هكذا في كل مكان، والرابط يُبنى بلا العلامة
            $handle = ltrim($handle, '@');

            if (! isset(Sections::NETWORKS[$network]) || $handle === '' || isset($seen[$network])) {
                continue;
            }

            $seen[$network] = true;
            $out[] = ['network' => $network, 'value' => $handle];
        }

        return $out;
    }
}
