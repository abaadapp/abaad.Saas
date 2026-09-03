<?php

namespace App\Support;

/**
 * أعمدةُ كلّ تقرير كما تُصدَّر — مصدرٌ واحد للملفّات الثلاثة.
 *
 * ولولا موضعٌ واحد لَكتب كلُّ صيغةٍ أعمدتَها بنفسها: تخرج ورقةُ إكسل بسبعة
 * أعمدة وملفُّ CSV بخمسة عن التقرير الواحد، ويُقارَن الملفّان فلا يتّفقان —
 * ولا أحد يعرف أيُّهما الصحيح.
 *
 * و`kind` يقول كيف تُكتب الخليّة لا كيف تبدو: المبلغ رقمٌ يُجمع في خليّة لا
 * نصٌّ منسَّق، وإلّا خرجت الورقة بأعمدةٍ لا تُجمع — وهو أوّل ما يفعله من
 * يفتحها.
 */
class ReportColumns
{
    /**
     * @var array<string, list<array{0: string, 1: string, 2: string}>>
     *                                                                  [مفتاح الحقل، العنوان، النوع: money|number|text]
     */
    public const MAP = [
        'finance' => [
            ['at', 'التاريخ', 'text'], ['reference', 'السند', 'text'], ['description', 'البيان', 'text'],
            ['method', 'الوسيلة', 'text'], ['type', 'النوع', 'text'], ['amount', 'المبلغ', 'money'],
        ],
        'expenses' => [
            ['at', 'التاريخ', 'text'], ['type', 'النوع', 'text'], ['description', 'البيان', 'text'],
            ['method', 'الوسيلة', 'text'], ['status', 'الحالة', 'text'], ['amount', 'المبلغ', 'money'],
        ],
        'bank' => [
            ['at', 'التاريخ', 'text'], ['description', 'البيان', 'text'], ['reference', 'المرجع', 'text'],
            ['status', 'المطابقة', 'text'], ['amount', 'المبلغ', 'money'],
        ],
        'orders' => [
            ['at', 'التاريخ', 'text'], ['number', 'رقم الطلب', 'text'], ['customer', 'العميل', 'text'],
            ['branch', 'الفرع', 'text'], ['method', 'وسيلة الدفع', 'text'], ['status', 'الحالة', 'text'],
            ['total', 'الإجمالي', 'money'],
        ],
        'products' => [
            ['name', 'المنتج', 'text'], ['category', 'القسم', 'text'], ['price', 'السعر', 'money'],
            ['quantity', 'الرصيد', 'number'], ['units', 'المُباع', 'number'],
            ['revenue', 'الإيراد', 'money'], ['profit', 'الربح', 'money'],
        ],
        'inventory' => [
            ['name', 'الصنف', 'text'], ['sku', 'الرمز', 'text'], ['category', 'القسم', 'text'],
            ['quantity', 'الرصيد', 'number'], ['alert', 'الحدّ الأدنى', 'number'],
            ['cost', 'التكلفة', 'money'], ['value', 'القيمة', 'money'],
        ],
        'stocktake' => [
            ['at', 'الوقت', 'text'], ['number', 'السند', 'text'], ['product', 'الصنف', 'text'],
            ['branch', 'الفرع', 'text'], ['reason', 'النتيجة', 'text'],
            ['delta', 'الفرق', 'number'], ['cost', 'التكلفة', 'money'], ['value', 'القيمة', 'money'],
        ],
        'purchases' => [
            ['at', 'التاريخ', 'text'], ['number', 'رقم الأمر', 'text'], ['supplier', 'المورّد', 'text'],
            ['status', 'الحالة', 'text'], ['received', 'الاستلام', 'text'], ['total', 'القيمة', 'money'],
        ],
        'suppliers' => [
            ['name', 'المورّد', 'text'], ['contact', 'مسؤول التواصل', 'text'], ['phone', 'الهاتف', 'text'],
            ['orders', 'عدد الأوامر', 'number'], ['total', 'إجمالي المشتريات', 'money'],
        ],
        'activity' => [
            ['at', 'الوقت', 'text'], ['user', 'المستخدم', 'text'],
            ['action', 'الإجراء', 'text'], ['description', 'التفصيل', 'text'],
        ],
        'marketing' => [
            ['code', 'الرمز', 'text'], ['type', 'النوع', 'text'], ['value', 'القيمة', 'number'],
            ['uses', 'مرات الاستخدام', 'number'], ['discount', 'الخصم', 'money'], ['revenue', 'الإيراد', 'money'],
        ],
        'vat' => [
            ['month', 'الشهر', 'text'], ['taxable', 'المبيعات الخاضعة', 'money'],
            ['output', 'ضريبة المخرجات', 'money'], ['purchases', 'المشتريات', 'money'],
            ['input', 'ضريبة المدخلات', 'money'], ['delivery', 'رسوم التوصيل (غير خاضعة)', 'money'],
            ['due', 'الصافي المستحقّ', 'money'],
        ],
        'payments' => [
            ['name', 'الوسيلة', 'text'], ['total', 'الإجمالي', 'money'],
            ['count', 'عدد العمليات', 'number'], ['percent', 'النسبة', 'number'],
        ],
        'staff' => [
            ['name', 'الموظف', 'text'], ['role', 'الوظيفة', 'text'], ['branch', 'الفرع', 'text'],
            ['orders', 'الطلبات', 'number'], ['sales', 'المبيعات', 'money'], ['status', 'الحالة', 'text'],
        ],
        'customers' => [
            ['name', 'العميل', 'text'], ['orders', 'عدد الطلبات', 'number'], ['total', 'إجمالي الإنفاق', 'money'],
        ],
    ];

    /**
     * التقارير التي ليست جدولًا واحدًا — لكلٍّ منها أقسامٌ تُصدَّر معًا.
     *
     * وتحليلاتُ الهالك أوّلُها: شاشتُها ستُّ قراءاتٍ على الصفوف نفسها — بالصنف
     * وبالقسم وبالفرع وبالسبب وعبر الزمن ومقابل الاستهلاك. وتصديرُ واحدةٍ
     * منها وترْكُ خمسٍ يُخرج ملفًّا يقول أقلَّ ممّا على الشاشة، ومن يقارنه بها
     * يظنّ أنّ شيئًا سقط.
     *
     * @var array<string, array<string, list<array{0: string, 1: string, 2: string}>>>
     */
    public const SECTIONS = [
        'waste' => [
            'بالصنف' => [
                ['label', 'الصنف', 'text'], ['quantity', 'الكمية', 'number'], ['value', 'القيمة', 'money'],
            ],
            'بالقسم' => [
                ['label', 'القسم', 'text'], ['quantity', 'الكمية', 'number'], ['value', 'القيمة', 'money'],
            ],
            'بالفرع' => [
                ['label', 'الفرع', 'text'], ['quantity', 'الكمية', 'number'], ['value', 'القيمة', 'money'],
            ],
            'بالسبب' => [
                ['label', 'السبب', 'text'], ['quantity', 'الكمية', 'number'], ['value', 'القيمة', 'money'],
            ],
            'عبر الزمن' => [
                ['label', 'الشهر', 'text'], ['quantity', 'الكمية', 'number'], ['value', 'القيمة', 'money'],
            ],
            'مقابل الاستهلاك' => [
                ['label', 'الصنف', 'text'], ['consumed', 'المستهلَك', 'number'],
                ['waste', 'الهالك', 'number'], ['rate', 'النسبة %', 'number'], ['value', 'القيمة', 'money'],
            ],
        ],
    ];

    /** هل هذا التقرير أقسامٌ لا جدولًا واحدًا؟ */
    public static function sectioned(string $report): bool
    {
        return array_key_exists($report, self::SECTIONS);
    }

    /** أسماءُ أقسام تقريرٍ متعدّد — بترتيب الشاشة */
    public static function sectionsOf(string $report): array
    {
        return array_keys(self::SECTIONS[$report] ?? []);
    }

    /** @return list<array{key: string, label: string, kind: string}> */
    public static function sectionColumns(string $report, string $section): array
    {
        return array_map(
            fn ($c) => ['key' => $c[0], 'label' => __($c[1]), 'kind' => $c[2]],
            self::SECTIONS[$report][$section] ?? [],
        );
    }

    /** صفٌّ واحد بترتيب أعمدة قسمٍ بعينه */
    public static function sectionCells(string $report, string $section, array $row): array
    {
        return self::write(self::sectionColumns($report, $section), $row);
    }

    /** هل يُصدَّر هذا التقرير من الباب العام؟ */
    public static function has(string $report): bool
    {
        return array_key_exists($report, self::MAP);
    }

    /** @return list<array{key: string, label: string, kind: string}> */
    public static function for(string $report): array
    {
        return array_map(
            fn ($c) => ['key' => $c[0], 'label' => __($c[1]), 'kind' => $c[2]],
            self::MAP[$report] ?? [],
        );
    }

    /** أسماء الأعمدة وحدها — لرأس الجدول */
    public static function headings(string $report): array
    {
        return array_column(self::for($report), 'label');
    }

    /**
     * صفٌّ واحد بترتيب الأعمدة.
     *
     * والفارغ يُكتب شرطةً لا فراغًا: خليّةٌ خاوية في ورقةٍ تُقرأ خطأً في
     * القراءة، ولا يُعرف أوقع فيها شيءٌ أم لا.
     */
    public static function cells(string $report, array $row): array
    {
        return self::write(self::for($report), $row);
    }

    /** كتابةُ صفٍّ بأعمدةٍ معطاة — موضعٌ واحد للجدول الواحد وللأقسام */
    private static function write(array $columns, array $row): array
    {
        return array_map(function ($c) use ($row) {
            $value = $row[$c['key']] ?? null;

            if ($c['kind'] === 'money' || $c['kind'] === 'number') {
                return $value === null ? 0 : $value;
            }

            return $value === null || $value === '' ? '—' : (string) $value;
        }, $columns);
    }
}
