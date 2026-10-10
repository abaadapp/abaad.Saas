<?php

namespace App\Support;

use App\Http\Middleware\CheckPlanFeature;
use App\Models\Business;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * فهرس التقارير — مصدرٌ واحد يقرؤه فهرس التقارير في اللوحة.
 *
 * كل بندٍ هنا تقريرٌ موجود فعلًا في النظام، لا اقتراحٌ ولا وعد: إمّا صفحةٌ
 * تفتحه (`route`) وإمّا مفتاحُ بياناتٍ يعرضه العارض (`data` — انظر
 * ReportDataController). ولا يُضاف بندٌ ثالث لا هذا ولا ذاك: بطاقةٌ تُعرض
 * ولا تُفتح أسوأ من بطاقةٍ لا تُعرض — التاجر يظنّ الميزة موجودة ويبني عليها.
 *
 * وكلٌّ يحمل `section` صلاحيته، فما لا يملكه المستخدم لا يظهر له أصلًا. بلا
 * ذلك كان الفهرس يعرض عشرين بطاقة لمحاسبٍ لا يفتح منها إلا ثمانيًا، فيصطدم
 * باثنتي عشرة ٤٠٣ — والقائمة الجانبية تُخفي ما لا يُملك منذ البداية، فيفترق
 * البابان على الشيء نفسه.
 */
class Reports
{
    /*
     * أُعيد هذا الفهرس بعد حذفه في d34f32e.
     *
     * وعاد منقوصًا عمدًا: ستّة بنودٍ كانت تقصد شاشاتٍ حُذفت معه — الربحية،
     * وضريبة القيمة المضافة، وإقفال الورديات الإداريّ، وحركات المخزون،
     * والتحليلات المتقدّمة، والمبيعات حسب القسم. وإعادتُها بطاقاتٍ تقود إلى
     * ٤٠٤ ليست إعادةً للقسم بل إعادةٌ لمظهره. وقاعدة هذا الملفّ لم تتغيّر:
     * كل بندٍ إمّا صفحةٌ قائمة وإمّا مفتاح بيانات — ولا ثالث.
     */

    /** تصنيفات الفهرس بترتيب عرضها */
    public const CATEGORIES = [
        'financial' => 'التقارير المالية',
        'operational' => 'التقارير التشغيلية',
        'analytical' => 'تقارير التحليلات',
    ];

    /**
     * البنود. `icon` مفتاحٌ تترجمه الواجهة إلى أيقونة (خريطة صريحة في
     * Reports/Index.tsx — الاستيراد الشامل من lucide يضخّ المكتبة كاملة).
     */
    public const ALL = [
        /* ------------------------------ مالية ------------------------------ */
        [
            'key' => 'sales',
            'category' => 'financial',
            'section' => 'reports',
            'title' => 'ملخّص المبيعات',
            'desc' => 'المبيعات والأرباح والمصروفات والضريبة، ومنحنى السنة والأكثر مبيعًا.',
            'icon' => 'trending-up',
            'route' => 'admin.reports.sales',
        ],
        /*
         * صافي الربح — للنشاط كلِّه أو لفرعٍ بعينه.
         *
         * تحت «التقارير» كملخّص المبيعات: قراءةٌ لا كتابة، والصلاحيةُ صلاحيتُها.
         */
        [
            'key' => 'profit',
            'category' => 'financial',
            'section' => 'reports',
            'title' => 'صافي الربح',
            'desc' => 'الإيرادات والتكلفة والمصروفات وصافي الربح وهامش الربح للنشاط أو الفرع خلال الفترة المختارة.',
            'icon' => 'scale',
            'route' => 'admin.reports.profit',
        ],
        [
            'key' => 'finance',
            'category' => 'financial',
            'section' => 'finance',
            'title' => 'الحركة المالية',
            /*
             * الوصف يصف الوجهة لا الأمنية.
             *
             * كانت الوجهةُ شاشةَ الحسابات البنكية فوُصفت أرصدتُها. وصارت
             * `admin.reports.finance` نفسَها: المقبوضاتُ والمدفوعاتُ وصافي
             * الحركة وجدولُ الحركات بمبدّل فترة — فيصفها الوصفُ كما هي.
             */
            'desc' => 'المقبوضات والمدفوعات وصافي الحركة المالية خلال الفترة المختارة.',
            'icon' => 'wallet',
            'route' => 'admin.reports.finance',
        ],
        [
            'key' => 'vat',
            'category' => 'financial',
            'section' => 'reports',
            'title' => 'ضريبة القيمة المضافة',
            'desc' => 'ما حصّلتَه من ضريبة وما دفعتَه، والفرقُ المستحقّ — شهرًا بشهر.',
            'icon' => 'percent',
            'route' => 'admin.reports.vat',
        ],
        [
            'key' => 'expenses',
            'category' => 'financial',
            'section' => 'expenses',
            'title' => 'المصروفات',
            'desc' => 'المصروفات حسب النوع والتاريخ ومن سجّلها.',
            'icon' => 'arrow-down-circle',
            'route' => 'admin.reports.expenses',
        ],
        /*
         * التكاليفُ والخسائر — ما أنقص الربحَ كما قيّده دفترُ الأستاذ.
         *
         * تحت «المالية» لا «التقارير»: يفتح قيودَ الدفتر سطرًا سطرًا —
         * رواتبَ الموظفين بأسمائهم وإهلاكَ كلِّ أصل — وهو ما تحرسه شاشةُ
         * القيود. وغيرُ «المصروفات»: تلك صفوفُ جدول المصروفات كما سُجّلت.
         */
        [
            'key' => 'costs',
            'category' => 'financial',
            'section' => 'finance',
            'title' => 'التكاليف والخسائر',
            'desc' => 'ما أنقص الربح في المدّة كما قيّده دفتر الأستاذ: تكلفة المبيعات والموظفون والتشغيل والإهلاك وخسائر المخزون، مقارنةً بالمدّة السابقة.',
            'icon' => 'trending-down',
            'route' => 'admin.reports.costs',
        ],
        [
            'key' => 'payments',
            'category' => 'financial',
            'section' => 'finance',
            'title' => 'وسائل الدفع',
            'desc' => 'توزيع التحصيل على النقد والبطاقة وبقية الوسائل.',
            'icon' => 'credit-card',
            'route' => 'admin.reports.payments',
        ],
        [
            'key' => 'bank',
            'category' => 'financial',
            'section' => 'finance',
            'title' => 'كشف الحساب البنكي',
            'desc' => 'مطابقة حركات البنك بحركات النظام، وما لم يُطابَق منها.',
            'icon' => 'landmark',
            'route' => 'admin.reports.bank',
        ],

        /* ----------------------------- تشغيلية ----------------------------- */
        [
            'key' => 'orders',
            'category' => 'operational',
            'section' => 'orders',
            'title' => 'الطلبات',
            'desc' => 'كل طلبٍ بحالته وفرعه وقيمته ووسيلة دفعه.',
            'icon' => 'shopping-cart',
            'route' => 'admin.reports.orders',
        ],
        [
            'key' => 'products',
            'category' => 'operational',
            'section' => 'products',
            'title' => 'المنتجات',
            'desc' => 'المنتجات وأسعارها وأقسامها وكمياتها المتاحة.',
            'icon' => 'package',
            'route' => 'admin.reports.products',
        ],
        [
            'key' => 'inventory',
            'category' => 'operational',
            'section' => 'inventory',
            'title' => 'المخزون والكميات',
            'desc' => 'رصيد كل صنف وحدّه الأدنى وما بلغ حدّ إعادة الطلب.',
            'icon' => 'boxes',
            'route' => 'admin.reports.inventory',
        ],
        [
            'key' => 'stocktake',
            'category' => 'operational',
            'section' => 'inventory',
            'title' => 'عمليات جرد المخزون',
            'desc' => 'ما عُدّ وأين فارق الدفترُ الواقع: النقص والزيادة وقيمتهما لكل فرع.',
            'icon' => 'clipboard-list',
            'route' => 'admin.reports.stocktake',
        ],
        [
            'key' => 'purchases',
            'category' => 'operational',
            'section' => 'purchases',
            'title' => 'أوامر الشراء',
            'desc' => 'أوامر الشراء وقيمتها وحالة استلامها لكل مورّد.',
            'icon' => 'truck',
            'route' => 'admin.reports.purchases',
        ],
        [
            'key' => 'suppliers',
            'category' => 'operational',
            'section' => 'suppliers',
            'title' => 'المورّدون',
            'desc' => 'بيانات المورّدين وعدد أوامر الشراء لكل واحد.',
            'icon' => 'store',
            'route' => 'admin.reports.suppliers',
        ],
        [
            'key' => 'staff',
            'category' => 'operational',
            'section' => 'employees',
            'title' => 'أداء الموظفين',
            'desc' => 'مبيعات كل موظف في الفترة المختارة وفرعه وحالته.',
            'icon' => 'users',
            'route' => 'admin.reports.staff',
        ],
        [
            'key' => 'activity',
            'category' => 'operational',
            'section' => 'settings',
            'title' => 'سجل النشاط',
            'desc' => 'من فعل ماذا ومتى على النظام.',
            'icon' => 'history',
            'route' => 'admin.reports.activity',
        ],

        /* ----------------------------- تحليلات ----------------------------- */
        [
            'key' => 'customers',
            'category' => 'analytical',
            'section' => 'customers',
            'title' => 'العملاء الأكثر إنفاقًا',
            'desc' => 'من يشتري أكثر، وكم طلبًا وكم أنفق.',
            'icon' => 'star',
            'route' => 'admin.reports.customers',
        ],
        [
            'key' => 'waste',
            'category' => 'analytical',
            'section' => 'reports',
            'title' => 'تحليلات الهالك',
            'desc' => 'ما تلف وما فُقد: قيمته واتجاهه، وأيّ صنفٍ وفرعٍ يبتلعه.',
            'icon' => 'trash-2',
            'route' => 'admin.reports.waste',
        ],
        [
            'key' => 'seasons',
            'category' => 'analytical',
            'section' => 'reports',
            'title' => 'أداء المواسم',
            'desc' => 'مبيعات كل موسم وتكلفتها ومجمل ربحها — ممّا نُسب إليه في الصندوق.',
            'icon' => 'calendar-days',
            'route' => 'admin.reports.seasons',
        ],
        [
            'key' => 'addons',
            'category' => 'analytical',
            // قراءةٌ لمبيعاتٍ كأداء المواسم — فصلاحيتُها «التقارير» لا بابٌ جديد
            'section' => 'reports',
            'title' => 'الإضافات',
            'desc' => 'ما بيع من الإضافات: كم مرّةً وفي كم طلب، وقيمتُها وتكلفتُها وربحُها — جزءٌ من المبيعات لا فوقها.',
            'icon' => 'gift',
            'route' => 'admin.reports.addons',
        ],
        [
            'key' => 'marketing',
            'category' => 'analytical',
            'section' => 'marketing',
            'title' => 'الكوبونات والتسويق',
            'desc' => 'استخدام الكوبونات وقيمة الخصومات والإيراد المرتبط بها.',
            'icon' => 'ticket-percent',
            // إلى الشاشة نفسها لا إلى تحويلٍ إليها: بطاقةٌ تقود إلى ٣٠٢
            // تصل إلى وجهتها اليوم، وتصل إلى غيرها يوم يتبدّل التحويل
            'route' => 'admin.reports.marketing',
        ],
    ];

    /**
     * التقارير التي يفتحها هذا المستخدم فعلًا، بروابطها المحلولة.
     *
     * الرابط يُحلّ هنا لا في الواجهة: `route()` في المتصفّح يحتاج تسجيل المسار
     * في Ziggy، ومسارٌ واحدٌ ناقص يُسقط الصفحة كلّها بدل بطاقةٍ واحدة.
     */
    public static function forUser(?User $user): array
    {
        return self::visibleTo($user)
            /*
             * وما لا تفتحه الباقة لا تُعرض بطاقتُه.
             *
             * الفهرس بابٌ يقود إلى شاشات، وبطاقةٌ تقود إلى 403 تجعل صاحبها
             * يظنّ العطب في النظام. والقدرة تُقرأ من مصدر الحارس نفسه — انظر
             * `CheckPlanFeature::featureFor` — فلا تفترق بطاقةٌ عن بابها.
             */
            ->map(fn ($r) => [
                'key' => $r['key'],
                'category' => $r['category'],
                'title' => __($r['title']),
                'desc' => __($r['desc']),
                'icon' => $r['icon'],
                'href' => route($r['route']),
            ])
            ->values()
            ->all();
    }

    /**
     * البنود التي يفتحها هذا المستخدم فعلًا — مصفاةً بصلاحيته وبباقته.
     *
     * موضعٌ واحد للتصفية يقرؤه الفهرس وشريطُ التنقّل معًا: لو صفّى كلٌّ
     * بنفسه لَعرض أحدهما تقريرًا يُخفيه الآخر، وصار للشيء الواحد بابان
     * يختلفان — وأحدهما يقود إلى ٤٠٣.
     *
     * @return Collection<int, array>
     */
    private static function visibleTo(?User $user): Collection
    {
        return collect(self::ALL)
            ->filter(fn ($r) => $user?->allows($r['section']) ?? false)
            /*
             * وما لا تفتحه الباقة لا تُعرض بطاقتُه.
             *
             * الفهرس بابٌ يقود إلى شاشات، وبطاقةٌ تقود إلى 403 تجعل صاحبها
             * يظنّ العطب في النظام. والقدرة تُقرأ من مصدر الحارس نفسه — انظر
             * `CheckPlanFeature::featureFor` — فلا تفترق بطاقةٌ عن بابها.
             */
            ->filter(function ($r) use ($user) {
                $key = CheckPlanFeature::featureFor($r['route']);

                return $key === null || PlanFeatures::allows($user?->business, $key);
            });
    }

    /**
     * القسم الذي يُقاس به تقريرٌ بمساره — أو null فلا يُفتح.
     *
     * حارس المسار يشتقّ القسم من اسم المسار، فكلّ ما تحت `admin.reports.*`
     * يُقاس بصلاحية «التقارير» وحدها. وليست هذه تقاريرَ عن التقارير: فيها
     * مبيعاتُ كل موظف، وإنفاقُ كل عميل، ومقبوضاتُ الصندوق. فمن مُنح
     * «التقارير» وحدها يقرؤها كلّها بكتابة عنوانها، والفهرس نفسه لا يعرض
     * له منها بطاقةً واحدة — منعٌ في الشاشة لا وجود له عند الباب.
     *
     * فيُسأل هذا الفهرس عن قسم التقرير نفسه قبل أن تُبنى الصفحة.
     * والمسار المجهول يُردّ بـnull: يُغلق لا يُفتح.
     */
    public static function sectionForRoute(string $route): ?string
    {
        foreach (self::ALL as $report) {
            if ($report['route'] === $route) {
                return $report['section'];
            }
        }

        return null;
    }

    /**
     * ═══ فترةُ كلّ تقرير — المصدرُ الذي تقرؤه الشاشةُ وملفّاتُها ═══
     *
     * التقريرُ هنا يقرأ حركةً وقعت في زمن، فيُسأل عن فترة: `month` و`all`
     * افتراضيُّه حين لا يُقال شيء — ما كان يفتح عليه قبل هذا الملفّ.
     *
     * و`dates` لتقريرٍ يقارن مدّتَه بسابقتها فيحتاج حدّين دائمًا (التكاليف
     * والهالك): يقرأ من/إلى القديمة، وافتراضيُّه من أوّل الشهر حتى اليوم كما
     * كان، ولا «كل الفترات» له — مقارنةٌ بلا حدّين لا تُقال.
     *
     * وما ليس هنا لا فترةَ له، ولا يُرسم فوقه منتقٍ:
     *   - `inventory` — رصيدُ اللحظة. لا جردَ تاريخيًّا في النظام، ومنتقي
     *     «سبتمبر ٢٠٢٥» فوقه يعرض رصيد اليوم تحت اسم سبتمبر.
     *   - `seasons` — لكلّ موسمٍ مدّتُه، والتقريرُ يقرأ ما نُسب إليه.
     */
    public const PERIODS = [
        'sales' => 'month', 'profit' => 'month', 'finance' => 'month', 'vat' => 'month',
        'expenses' => 'month', 'payments' => 'month', 'bank' => 'month', 'orders' => 'month',
        'products' => 'month', 'stocktake' => 'month', 'purchases' => 'month', 'staff' => 'month',
        'activity' => 'month', 'customers' => 'month', 'addons' => 'month', 'marketing' => 'month',
        'suppliers' => 'all',
        'costs' => 'dates', 'waste' => 'dates',
    ];

    /**
     * فترةُ التقرير من الرابط — أو `null` لتقريرٍ لا فترةَ له.
     *
     * @param  array<string, mixed>  $query
     */
    public static function period(string $key, array $query): ?ReportingPeriod
    {
        $default = self::PERIODS[$key] ?? null;

        if ($default === null) {
            return null;
        }

        if ($default !== 'dates') {
            return ReportingPeriod::fromQuery($query, $default);
        }

        $default = ReportingPeriod::custom(now()->startOfMonth()->toDateString(), now()->toDateString());
        $period = ReportingPeriod::fromQuery($query, $default, ['dates']);

        /*
         * و`range=all` القديمة تسقط إلى الافتراضيّ كما كانت: هذان التقريران لم
         * يقرآ `range` قطّ، وروابطُ الفهرس تحملها. و«كل الفترات» المختارةُ
         * صراحةً (`period=all`) تُردّ — مقارنةٌ بلا حدّين لا تُقال.
         */
        if ($period->preset && $period->start === null) {
            return $default;
        }

        abort_if($period->start === null, 422, __('هذا التقرير يحتاج فترةً لها بداية ونهاية.'));

        return $period;
    }

    /**
     * ما تختاره الواجهةُ من الفترات لتقرير.
     *
     * @return array{presets: list<string>, previous_month: bool, month: bool, month_range: bool, year: bool, custom: bool, all: bool}
     */
    public static function periodCapabilities(string $key): array
    {
        $dates = (self::PERIODS[$key] ?? null) === 'dates';

        return [
            'presets' => $dates ? ['today', 'week', 'month', 'year'] : Demo::RANGES,
            'previous_month' => true,
            'month' => true,
            'month_range' => true,
            'year' => true,
            'custom' => true,
            'all' => ! $dates,
        ];
    }

    /** الفترةُ كما تقرؤها الواجهة — ومعها ما يُختار منه */
    public static function periodProp(string $key, ReportingPeriod $period): array
    {
        return $period->screen((int) Demo::bid(), self::periodCapabilities($key));
    }

    /**
     * ما تعرضه شاشة «ملخّص المبيعات» — مصدرٌ واحد تقرؤه الشاشة والتغذية
     * والملفّات الثلاثة (Excel وPDF وCSV).
     *
     * كانت الملفّات تجمع أرقامها بنفسها: مؤشّراتُها من `adminStats` — وهي
     * أرقام اليوم والشهر مهما كانت الفترة المطلوبة، ومحصورةٌ بالفرع الحالي
     * بينما ما تحتها ليس كذلك — وأفضلُ منتجاتها مرتّبةً بالكمية بينما
     * الشاشة ترتّبها بالإيراد. فيخرج الملفّ بترويسةٍ تقول «اليوم» وجدولٍ
     * يحمل الشهر، وبقائمةٍ لأفضل خمسةٍ غير التي رآها التاجر قبل ضغطه بثانية.
     *
     * وباجتماعها هنا لا يبقى للاختلاف موضع.
     */
    /**
     * ملخّصُ المبيعات — لفترةٍ، ولقناةٍ إن اختيرت.
     *
     * ═══ والقناةُ تُنقّى هنا لا عند كلّ قارئ ═══
     *
     * خمسةٌ ينادون هذه الدالّة: الشاشةُ وثلاثةُ ملفّاتٍ تخرج منها ولقمةُ
     * التحديث. ولو نقّى كلٌّ منهم المعامل بيده لَقبل أحدُهم قيمةً لا تصحّ،
     * فخرج ملفٌّ بغير ما على الشاشة.
     *
     * وما لا يصحّ يُقرأ «المتجر كلُّه» لا يُردّ خطأً: هذا تقريرٌ يُقرأ، وسطرُ
     * عنوانٍ معطوب لا يُبرّر شاشةً حمراء.
     */
    public static function salesReport(string|ReportingPeriod|null $range, ?string $channel = null, mixed $boutique = null): array
    {
        /*
         * والفترةُ زرٌّ سريع (`range`) أو `ReportingPeriod` قرأها المتحكّم من
         * الرابط — شهرٌ بعينه أو سنةٌ أو نطاق. وكلُّ ما تحت يقرأ حدّيها.
         */
        $period = $range instanceof ReportingPeriod ? $range : ReportingPeriod::preset($range);
        $channel = collect(SalesChannel::options())->pluck('value')->contains($channel) ? $channel : null;

        /*
         * ═══ والبوتيكُ يُنقّى هنا كذلك ═══
         *
         * `Boutiques::scope` تردّ `null` لكلّ ما لا يصحّ — بوتيكُ متجرٍ آخر،
         * أو متجرٌ لا بوتيكَ عنده — فيُقرأ «كل المبيعات» ولا يُكشف شيء.
         */
        $business = Business::find(Demo::bid());
        $scope = Boutiques::scope($business, $boutique);

        /*
         * والفرعُ يُقرأ هنا مرّةً ويُمرَّر — لا تقرؤه كلُّ دالّةٍ من الجلسة.
         *
         * لأنّ لتلك الدوالّ قرّاءً آخرين يريدون المتجر كلَّه: `reportSummary`
         * تُنادى من نظرة المالية، و`paymentBreakdown` من ملفّات التصدير.
         * فلو قرأت الفرعَ من نفسها لَتغيّر معنى تقاريرَ لم يطلب أحدٌ تغييرَها.
         *
         * و`null` تعني «كل الفروع» — وهي حصيلةُ النشاط كما كانت.
         *
         * والحصرُ آمنٌ من نفسه: `currentBranchId` تردّ `null` لفرعٍ ليس
         * لهذا المتجر، فجلسةٌ قديمة تحمل فرعَ جارٍ لا تُرشِّح ولا تُسمّي.
         */
        $branch = Demo::currentBranchId();

        /*
         * ═══ ونطاقُ البوتيك يُقرأ من البنود وحدها ═══
         *
         * فاتورةٌ واحدة تجمع صنفَ المحلّ وصنفَ بوتيكين، فمجموعُها ليس لأحدهم.
         * فما يُحسب هنا من بنود النطاق: إجماليُّها، والعمولةُ بنسبة كلّ بندٍ
         * ساعةَ بيعه، والكميّة، والمنحنى، والأكثرُ مبيعًا — انظر `Boutiques::soldLines`.
         *
         * وما يُكتب على الطلب كاملًا لا يُحسب: الضريبة، ووسائلُ الدفع،
         * وخصمُ الفاتورة، والمصروفات، وصافي الربح. لا يُنسب منها شيءٌ إلى
         * بندٍ بالظنّ ولا يُقسَم بنسبة — فتسقط من الحمولة (`null`) وتقول
         * الشاشةُ لماذا.
         *
         * وبلا نطاقٍ تبقى الحمولةُ كما كانت رقمًا برقم.
         */
        $scoped = $scope !== null;

        return [
            'summary' => $scoped ? null : Demo::reportSummary($period, $channel, $branch),
            'salesSeries' => Demo::salesTrend($period, $channel, $branch, $scope),
            'paymentDistribution' => $scoped ? null : Demo::paymentDistribution($period, $channel, $branch),
            'topSellingProducts' => Demo::topSellingProducts(5, $period, $branch, $channel, $scope),
            'boutiqueTotals' => $scoped
                ? Boutiques::soldTotals((int) Demo::bid(), $scope, $period->start, $branch, $channel, $period->end?->copy()->subSecond())
                : null,
            'boutique' => match (true) {
                $scope === null => null,
                $scope === Boutiques::OWN => ['kind' => Boutiques::OWN, 'id' => null, 'name' => null],
                default => ['kind' => 'boutique', 'id' => (int) $scope->id, 'name' => $scope->label()],
            },
            // وما يُطبع فوق الورقة: «كل المبيعات» أو «منتجات المتجر» أو «بوتيك: …»
            'boutiqueLabel' => Boutiques::scopeLabel($scope),
            // والخياراتُ فارغةٌ لمن لا بوتيكَ عنده — فلا يُرسم المُرشِّح ولا يُطبع سطرُه
            'boutiques' => Boutiques::options($business),
            // الزرُّ السريع — أو `null` لفترةٍ غيره، فلا يُضاء «الشهر» وهو سبتمبر الماضي
            'range' => $period->range(),
            'period' => self::periodProp('sales', $period),
            'channel' => $channel,
            'channels' => SalesChannel::options(),
            /*
             * وما تقيسه الورقةُ يُقال فيها: الشاشةُ فوقها مبدّلٌ يقول الفرع،
             * والملفُّ يغادرها فلا مبدّلَ فوقه. وتُقرأ من هنا لا تُحسب مرّتين.
             */
            'branchName' => Demo::scopeName(true),
            'branchId' => $branch,
        ];
    }

    /**
     * بطاقات الملخّص صفوفًا لورقة: اسمٌ وقيمة، بترتيب الشاشة نفسه.
     *
     * والقيمة رقمٌ خام لا نصٌّ منسَّق: الورقة تريدها رقمًا يُجمع في خليّة،
     * والـPDF يريدها منسَّقةً — فالتنسيق عند الكاتب لا هنا.
     */
    public static function summaryRows(array $summary): array
    {
        $rows = [
            ['إجمالي المبيعات', 'sales', true],
            // «منها» لا «قيمة الإضافات» وحدها: سطرٌ تحت الإجمالي بلا هذا الحرف
            // يجمعه قارئُ الورقة عليه فيعدّ الإضافات مرّتين
            ['منها إضافات', 'addons', true],
            // التكلفة تُقال صراحةً: بطاقةُ ربحٍ بلا سطر تكلفةٍ فوقها لا
            // يستطيع قارئها أن يتحقّق من الطرح ولا أن يعرف أنه وقع أصلًا
            ['تكلفة البضاعة المباعة', 'cogs', true],
            ['صافي الربح', 'profit', true],
            ['المصروفات التشغيلية', 'expenses', true],
            ['الضريبة المحصّلة', 'tax', true],
            ['المنتجات', 'products', false],
            ['تنبيهات المخزون', 'inventory_alerts', false],
            ['الموظفون', 'employees', false],
            ['العملاء', 'customers', false],
            ['وسائل الدفع', 'payment_methods', false],
        ];

        return collect($rows)->map(fn ($r) => [
            'label' => __($r[0]),
            'value' => $r[2] ? round((float) ($summary[$r[1]] ?? 0), 3) : (int) ($summary[$r[1]] ?? 0),
            'money' => $r[2],
        ])->all();
    }

    /**
     * صفوفُ المؤشّرات لورقةٍ من حمولة التقرير — بنطاقه.
     *
     * بلا نطاق بوتيك هي `summaryRows` كما كانت. وبنطاقٍ هي أرقامُ البنود
     * وحدها — ولا صفَّ لما لا يُنسب إلى بند (الضريبة والمصروفات والربح).
     */
    public static function rowsFor(array $report): array
    {
        $totals = $report['boutiqueTotals'] ?? null;

        if ($totals === null) {
            return self::summaryRows($report['summary']);
        }

        $rows = ($report['boutique']['kind'] ?? null) === Boutiques::OWN
            ? [
                ['مبيعات منتجات المتجر', 'gross', true],
                ['الكمية المباعة', 'quantity', false],
                ['طلبات فيها منتجات المتجر', 'orders', false],
            ]
            : [
                ['إجمالي مبيعات البوتيك', 'gross', true],
                ['عمولة المتجر', 'commission', true],
                ['المستحق للبوتيك', 'net', true],
                ['الكمية المباعة', 'quantity', false],
                ['طلبات فيها منتجات البوتيك', 'orders', false],
            ];

        return collect($rows)->map(fn ($r) => [
            'label' => __($r[0]),
            'value' => $r[2] ? round((float) ($totals[$r[1]] ?? 0), 3) : (int) ($totals[$r[1]] ?? 0),
            'money' => $r[2],
        ])->all();
    }

    /** أسماء التصنيفات مترجمةً — الواجهة لا تخمّنها من المفتاح */
    public static function categoryLabels(): array
    {
        return collect(self::CATEGORIES)->map(fn ($l) => __($l))->all();
    }
}
