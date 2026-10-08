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
        /*
         * التكاليفُ والخسائر: حسابٌ في فئته — بأرقام الشاشة نفسِها.
         * و«التغيّر %» فارغٌ حين لا سابقَ موجب: نسبةٌ من صفر لا تُكتب.
         */
        'costs' => [
            ['category_label', 'الفئة', 'text'], ['code', 'الرمز', 'text'], ['account', 'الحساب', 'text'],
            ['current', 'هذه المدّة', 'money'], ['share', 'من الإجمالي %', 'number'],
            ['previous', 'المدّة السابقة', 'money'], ['delta', 'الفرق', 'money'], ['change_pct', 'التغيّر %', 'text'],
        ],
        'profit' => [
            ['period', 'الفترة', 'text'], ['sales', 'إجمالي المبيعات', 'money'], ['tax', 'الضريبة المحصلة', 'money'],
            ['net_revenue', 'صافي الإيرادات', 'money'], ['cogs', 'تكلفة البضاعة المباعة', 'money'],
            ['gross_profit', 'مجمل الربح', 'money'], ['expenses', 'المصروفات التشغيلية', 'money'],
            ['net_profit', 'صافي الربح', 'money'], ['margin', 'هامش صافي الربح %', 'number'],
        ],
        'bank' => [
            ['at', 'التاريخ', 'text'], ['description', 'البيان', 'text'], ['reference', 'المرجع', 'text'],
            ['status', 'المطابقة', 'text'], ['amount', 'المبلغ', 'money'],
        ],
        'orders' => [
            ['at', 'التاريخ', 'text'], ['number', 'رقم الطلب', 'text'], ['customer', 'العميل', 'text'],
            ['branch', 'الفرع', 'text'], ['method', 'وسيلة الدفع', 'text'], ['status', 'الحالة', 'text'],
            ['fulfillment', 'نوع التنفيذ', 'text'], ['total', 'الإجمالي', 'money'],
        ],
        'addons' => [
            ['name', 'الإضافة', 'text'], ['link', 'الربط', 'text'],
            ['orders', 'عدد الطلبات', 'number'], ['uses', 'مرات الاستخدام', 'number'],
            ['quantity', 'الكمية', 'number'], ['revenue', 'قيمة المبيعات', 'money'],
            ['cost', 'التكلفة', 'money'], ['profit', 'الربح', 'money'], ['average', 'متوسّط سعر الوحدة', 'money'],
        ],
        'products' => [
            ['name', 'المنتج', 'text'], ['category', 'القسم', 'text'], ['price', 'السعر', 'money'],
            ['quantity', 'الرصيد', 'number'], ['units', 'المُباع', 'number'],
            ['revenue', 'الإيراد', 'money'], ['profit', 'الربح التقديري', 'money'],
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
        'seasons' => [
            ['name', 'الموسم', 'text'], ['dates', 'المدّة', 'text'], ['statusLabel', 'الحالة', 'text'],
            ['sales', 'إجمالي المبيعات', 'money'], ['cogs', 'تكلفة البضاعة المباعة', 'money'],
            ['gross_profit', 'مجمل الربح', 'money'], ['margin', 'الهامش %', 'number'],
            ['orders', 'عدد الطلبات', 'number'], ['units', 'الكمية المباعة', 'number'],
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
     * بطاقاتُ المؤشّرات فوق كلّ جدول — بترتيب الشاشة وبألفاظها.
     *
     * ═══ العطبُ الذي فُتحت لأجله ═══
     *
     * كان الملفّ يختار بطاقاتِه **بترتيب ظهور المفاتيح** في `ReportData`:
     * أوّلُ أربعةٍ غيرِ مصفوفة، ويسمّيها من قاموسٍ ثانٍ في المتحكّم. وهما
     * تخمينان، فافترق الملفّ عن شاشته في أربعة تقارير:
     *
     *  • **الجرد** — الشاشة تُنهي بـ«صافي الفرق» والملفُّ بـ«قيمة الزيادة».
     *  • **الموظفون** — الشاشة تُظهر «الأعلى مبيعًا» والملفُّ «عدد الموظفين».
     *  • **الهالك** — الشاشة تبدأ بالقيمة والملفُّ بالعدد.
     *  • **الضريبة** — وهذه أسوأُها.
     *
     * ═══ وإقرارُ الضريبة كان يخرج بالإنجليزية ═══
     *
     * مفاتيحُ ملخّص الضريبة لم تكن في ذلك القاموس أصلًا، و`__()` تردّ
     * المفتاحَ حين لا ترجمة. فكانت الورقةُ التي تُرفع إلى جهاز الضرائب
     * تحمل أربعَ بطاقاتٍ مكتوبة: **taxable — output — purchases — input**،
     * وتحتها بسطرين الجدولُ نفسُه يسمّيها «المبيعات الخاضعة» و«ضريبة
     * المخرجات»… ملفٌّ يناقض نفسَه في صفحةٍ واحدة.
     *
     * وأثقلُ منه أنّ **«الصافي المستحقّ» لم يكن يظهر**: هو الخامس في
     * الترتيب، والتخمينُ يقصّ عند الرابع. فالرقمُ الوحيد الذي يُدفع في
     * إقرارٍ ضريبيّ كان غائبًا عن الورقة.
     *
     * ═══ فصار التصريحُ هنا ═══
     *
     * إلى جانب أعمدة الجدول، في الملفّ الذي يقرؤه الملفّ. والصيغةُ:
     * `[المفتاح، الاسم، النوع]`، ويلحقه اختياريًّا `[مفتاحٌ ثانٍ، نوعُه،
     * الفاصل]` حيث تجمع الشاشةُ رقمين في بطاقةٍ واحدة («٣ / ٧»، «نقدي ·
     * ٢٥٠٫٠٠٠»).
     *
     * @var array<string, list<array{0: string, 1: string, 2: string, 3?: array{0: string, 1: string, 2: string}}>>
     */
    public const CARDS = [
        // والإجماليُّ مجموعُ الأربع بعده — بترتيب الشاشة
        'costs' => [
            ['total', 'إجمالي التكاليف والخسائر', 'money'],
            ['cost_of_sales', 'تكلفة المبيعات', 'money'],
            ['operating', 'مصروفات التشغيل', 'money'],
            ['losses', 'الخسائر', 'money'],
            ['other', 'مصروفات وخسائر أخرى', 'money'],
        ],
        'vat' => [
            ['taxable', 'المبيعات الخاضعة', 'money'],
            ['output', 'ضريبة المخرجات', 'money'],
            ['input', 'ضريبة المدخلات', 'money'],
            /*
             * والصافي يُكتب بإشارته لا بقيمته المطلقة.
             *
             * الشاشةُ تقلب الاسم إلى «رصيد مستردّ» حين يكون سالبًا لأنّ
             * أمامها مساحةٌ تفعل ذلك. والورقةُ تُرسَل ولا يُسأل كاتبُها،
             * فرقمٌ سالبٌ تحت «الصافي المستحقّ» يُقرأ كما هو: لك لا عليك.
             */
            ['due', 'الصافي المستحقّ', 'money'],
        ],
        'payments' => [
            ['total', 'إجمالي التحصيل', 'money'],
            ['count', 'عدد العمليات', 'number'],
            ['active', 'الوسائل النشطة', 'number'],
            ['topName', 'الأعلى تحصيلًا', 'text', ['topTotal', 'money', ' · ']],
        ],
        'staff' => [
            ['total', 'إجمالي المبيعات', 'money'],
            ['sellers', 'من باع في الفترة', 'number', ['staff', 'number', ' / ']],
            ['average', 'متوسّط البائع', 'money'],
            ['topName', 'الأعلى مبيعًا', 'text', ['topSales', 'money', ' · ']],
        ],
        'customers' => [
            ['total', 'إجمالي الإنفاق', 'money'],
            ['customers', 'عملاء اشتروا', 'number'],
            ['orders', 'عدد الطلبات', 'number'],
            ['average', 'متوسّط قيمة الطلب', 'money'],
        ],
        'finance' => [
            ['income', 'المقبوضات', 'money'],
            ['outgo', 'المدفوعات', 'money'],
            ['net', 'صافي الحركة', 'money'],
            ['count', 'عدد الحركات', 'number'],
        ],
        'profit' => [
            ['net_profit', 'صافي الربح', 'money'],
            ['margin', 'هامش صافي الربح', 'percent'],
            ['net_revenue', 'صافي الإيرادات', 'money'],
            ['gross_profit', 'مجمل الربح', 'money'],
            ['sales', 'إجمالي المبيعات', 'money'],
            ['tax', 'الضريبة المحصلة', 'money'],
            ['cogs', 'تكلفة البضاعة المباعة', 'money'],
            ['expenses', 'المصروفات التشغيلية', 'money'],
            /*
             * وللفرع وحده، وحين يكون: ما لم يُطرح منه.
             *
             * ورقةُ فرعٍ تكتب صافيَ ربحه بلا هذا السطر تُقرأ على أنّها تحمل
             * إيجارَ المتجر كلِّه — وهي لا تحمله.
             */
            ['unallocated', 'المصروفات العامة غير الموزعة (لم تُخصم من الفرع)', 'money'],
            // والنطاقُ آخرًا: الشاشةُ تكتبه في رأسها لا في بطاقة، والملفُّ يبدأ بما تبدأ به
            ['scope_name', 'نطاق التقرير', 'text'],
        ],
        'expenses' => [
            ['total', 'إجمالي المصروفات المسجلة', 'money'],
            ['paid', 'المدفوع', 'money'],
            ['unpaid', 'غير المدفوع', 'money'],
            ['count', 'عدد المصروفات', 'number'],
        ],
        'bank' => [
            ['lines', 'أسطر الكشف', 'number'],
            ['matched', 'المطابَق', 'number'],
            ['unmatched', 'غير المطابَق', 'number'],
            ['total', 'إجمالي الحركة', 'money'],
        ],
        'orders' => [
            ['count', 'عدد الطلبات', 'number'],
            ['total', 'إجمالي المبيعات', 'money'],
            ['average', 'متوسّط قيمة الطلب', 'money'],
            ['cancelled', 'الملغاة', 'number'],
            /*
             * وما في الإجمالي — لا ما يُضاف إليه.
             *
             * رسومُ التوصيل وثمنُ الإضافات داخلان في `orders.total` منذ البيع.
             * فيُسمّيان هنا «ضمن الإجمالي» صراحةً: ورقةٌ تكتب «رسوم التوصيل»
             * تحت «إجمالي المبيعات» يجمعها قارئُها عليه فيعدّها مرّتين.
             */
            ['delivery', 'طلبات التوصيل (غير الملغاة)', 'number'],
            ['pickup', 'طلبات الاستلام (غير الملغاة)', 'number'],
            ['delivery_fees', 'رسوم التوصيل — ضمن الإجمالي', 'money'],
            ['addons', 'قيمة الإضافات — ضمن الإجمالي', 'money'],
        ],
        'addons' => [
            ['uses', 'مرات استخدام الإضافات', 'number'],
            ['orders', 'طلبات فيها إضافات', 'number'],
            ['revenue', 'قيمة الإضافات — ضمن إجمالي المبيعات', 'money'],
            ['profit', 'ربح الإضافات', 'money'],
        ],
        'products' => [
            ['products', 'عدد المنتجات', 'number'],
            ['sold', 'باعت منها', 'number', ['products', 'number', ' / ']],
            ['revenue', 'الإيراد', 'money'],
            ['profit', 'الربح التقديري', 'money'],
        ],
        'inventory' => [
            ['items', 'الأصناف', 'number'],
            ['quantity', 'إجمالي الكمية', 'number'],
            ['value', 'قيمة المخزون', 'money'],
            ['below', 'تحت الحدّ', 'number'],
            // والنطاقُ آخرًا كما في «صافي الربح»: الشاشةُ تكتبه في رأسها، والملفُّ يحمله
            ['scope_name', 'نطاق التقرير', 'text'],
        ],
        'stocktake' => [
            ['operations', 'عمليات الجرد', 'number'],
            ['items', 'أصناف اختلفت', 'number'],
            ['shortage', 'قيمة النقص', 'money'],
            ['net', 'صافي الفرق', 'money'],
        ],
        'purchases' => [
            ['count', 'عدد الأوامر', 'number'],
            ['total', 'قيمة المشتريات', 'money'],
            ['received', 'المستلَمة', 'number'],
            ['pending', 'المعلّقة', 'number'],
        ],
        'suppliers' => [
            ['suppliers', 'عدد المورّدين', 'number'],
            ['active', 'اشترينا منهم', 'number', ['suppliers', 'number', ' / ']],
            ['orders', 'عدد الأوامر', 'number'],
            ['total', 'إجمالي المشتريات', 'money'],
        ],
        'activity' => [
            ['count', 'عدد الأحداث', 'number'],
            ['users', 'المستخدمون', 'number'],
            ['topAction', 'أكثر إجراء', 'text', ['topCount', 'number', ' · ']],
        ],
        'seasons' => [
            ['seasons', 'عدد المواسم', 'number'],
            ['sold', 'مواسم لها مبيعات', 'number', ['seasons', 'number', ' / ']],
            ['sales', 'إجمالي المبيعات', 'money'],
            ['gross_profit', 'مجمل الربح', 'money'],
        ],
        'marketing' => [
            ['coupons', 'عدد الكوبونات', 'number'],
            ['used', 'استُخدم منها', 'number', ['coupons', 'number', ' / ']],
            ['uses', 'مرات الاستخدام', 'number'],
            ['discount', 'قيمة الخصومات', 'money'],
        ],
        /*
         * والهالك ثلاثٌ لا أربع: بطاقةُ «المدّة السابقة» الرابعةُ على الشاشة
         * مقارنةٌ لا حصيلة، ولا مفتاحَ لها في الملخّص. وكتابةُ رقمٍ رابعٍ
         * لملء الفراغ تُدخل في الورقة ما لم يُحسب لها.
         */
        'waste' => [
            ['value', 'قيمة الهالك', 'money'],
            ['quantity', 'الكمية الهالكة', 'number'],
            ['count', 'عدد التسجيلات', 'number'],
        ],
    ];

    /**
     * بطاقاتُ تقريرٍ مملوءةً من ملخّصه — اسمًا وقيمةً مكتوبةً للقراءة.
     *
     * والتنسيق هنا لا في القالب: القالبُ لا يعرف أيُّ مفتاحٍ مبلغٌ وأيُّه
     * عدد، فكان يطبع عدد الطلبات بثلاث خاناتٍ عشرية.
     *
     * @return list<array{label: string, value: string}>
     */
    /**
     * بطاقاتٌ تُطبع حين يكون لها ما تقوله — وفارغُها لا يصير شرطة.
     *
     * «غيرُ الموزّعة» تخصّ ورقةَ فرعٍ عليه عامٌّ لم يُوزَّع. وللنشاط كلِّه هي
     * من مصروفاته أصلًا: شرطةٌ مكانها تُقرأ «لا شيء» وهي ليست كذلك.
     */
    private const WHEN_SET = [
        'profit' => ['unallocated'],
    ];

    public static function cards(string $report, array $summary): array
    {
        $out = [];

        foreach (self::CARDS[$report] ?? [] as $card) {
            [$key, $label, $kind] = $card;

            if (! array_key_exists($key, $summary)) {
                continue;
            }

            if ($summary[$key] === null && in_array($key, self::WHEN_SET[$report] ?? [], true)) {
                continue;
            }

            $value = self::readable($summary[$key], $kind);

            /*
             * والثاني لا يُكتب إن لم يكن للأوّل قيمة.
             *
             * «الأعلى تحصيلًا» في متجرٍ لم يبع بعدُ تصير «— · 0.000 ر.ع»:
             * شرطةٌ تقول «لا شيء» ورقمٌ بجوارها يقول غيرَ ذلك. والشاشةُ
             * تكتب شرطةً وحدَها — انظر `Payments.tsx`.
             */
            if ($value !== '—' && isset($card[3]) && array_key_exists($card[3][0], $summary)) {
                $value .= $card[3][2].self::readable($summary[$card[3][0]], $card[3][1]);
            }

            $out[] = ['label' => __($label), 'value' => $value];
        }

        return $out;
    }

    /** قيمةٌ مكتوبةٌ بنوعها — والفارغُ شرطةٌ كما في خلايا الجدول */
    private static function readable(mixed $value, string $kind): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return match ($kind) {
            'money' => Demo::money((float) $value),
            'number' => number_format((float) $value, 0),
            // والهامشُ بخانةٍ عشريّة — «١٦» تُخفي الفرق بين ١٥٫٦ و١٦٫٤
            'percent' => number_format((float) $value, 1).'%',
            default => (string) $value,
        };
    }

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
