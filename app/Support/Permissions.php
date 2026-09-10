<?php

namespace App\Support;

use App\Models\User;

/**
 * صلاحيات الأقسام حسب الدور داخل لوحة النشاط.
 * '*' = كل الأقسام. dashboard و pos متاحة دائمًا لأي موظف يصل للوحة.
 */
class Permissions
{
    public const MAP = [
        'admin' => ['*'],
        'manager' => ['*'],
        // المحاسب يقرأ التقارير: هي عملُه لا زينةٌ فوقه
        'accountant' => ['dashboard', 'orders', 'customers', 'finance', 'expenses', 'employees', 'pos', 'reports'],
        // من يجهّز البضاعة يرى لوحة التجهيز — وهي عملُه لا زينةٌ فوقه
        'inventory' => ['dashboard', 'products', 'inventory', 'suppliers', 'purchases', 'pos', 'preparation'],
        'sales' => ['dashboard', 'orders', 'customers', 'products', 'pos', 'preparation'],
        'cashier' => ['dashboard', 'pos'],
        // والسائق يقرأ ما سيحمله: العنوان والموعد والمستلِم
        'delivery' => ['dashboard', 'orders', 'pos', 'preparation'],
    ];

    /**
     * أفعالٌ تُمنح بأسمائها — لا أقسامٌ تُفتح.
     *
     * القسمُ يقول «أيّ شاشةٍ تفتح»، والفعلُ يقول «كم من الضرر تستطيع». وهما
     * سؤالان مختلفان: من فتحتَ له نقطةَ البيع ليبيع لم تفتح له بالضرورة أن
     * يعيد كتابة فاتورةٍ صدرت.
     *
     * ويعيشان في العمود نفسه (`users.permissions`) ولا يختلطان: اسمُ الفعل
     * فيه نقطة واسمُ القسم لا نقطة فيه، فلا يُقرأ فعلٌ قسمًا ولا العكس —
     * انظر `entersPanel` و`isAction`.
     *
     * والدورةُ المالية تُفصَّل هنا فعلًا فعلًا: قراءةٌ، وكتابةٌ، واعتمادٌ،
     * ورفضٌ، وتجاوزٌ، وسداد. وجمعُها في مفتاحٍ واحدٍ كان يعني أنّ من يُمنح
     * كتابةَ السند يُمنح معها الإقرارَ بالذمّة وإخراجَ المال — وهي ثلاثةُ
     * قراراتٍ يفعلها في المتاجر ثلاثةُ أشخاص.
     *
     * وما لا يحرس شيئًا لا يُكتب: `view_payroll_payment_proofs` في المواصفة
     * لا مستندَ له بعد (دفعاتُ الرواتب المجمّعة لم تُبنَ)، ومقبضٌ لا يُدير
     * شيئًا أسوأ من غياب المقبض.
     */
    /** قراءةُ طابور إشعارات الاستلام — وفيه تكاليفُ الشراء */
    public const RECEIPT_VIEW = 'receipt.view';

    /** كتابةُ إشعار استلام — ورقةٌ تنتظر، ولا يتحرّك بها رفّ */
    public const RECEIPT_CREATE = 'receipt.create';

    /** اعتمادُ استلام البضاعة — وهو الفعل الذي يُدخلها الرفّ */
    public const RECEIPT_APPROVE = 'receipt.approve';

    /** رفضُ إشعار استلام — نصفُ المراجعة الآمن، يُمنح وحدَه */
    public const RECEIPT_REJECT = 'receipt.reject';

    /** قراءةُ سندات الموردين — وفيها ما على المتجر وبكم اشترى */
    public const INVOICE_VIEW = 'invoice.view';

    /** كتابةُ سند مورّد — إدخالُ الورقة لا الإقرارُ بالذمّة */
    public const INVOICE_CREATE = 'invoice.create';

    /** اعتمادُ سند المورّد — وهو الفعل الذي يُنشئ الذمّة */
    public const INVOICE_APPROVE = 'invoice.approve';

    /** رفضُ سند المورّد — نصفُ المراجعة الآمن، يُمنح وحدَه */
    public const INVOICE_REJECT = 'invoice.reject';

    /** تجاوزُ عدم مطابقة السند لأمره — بسببٍ يُكتب ويُنسب */
    public const INVOICE_OVERRIDE = 'invoice.override';

    /** سدادُ سند المورّد — مالٌ يخرج، وهو فعلٌ غيرُ الاعتماد */
    public const INVOICE_PAY = 'invoice.pay';

    /** قراءةُ مسيرة الرواتب — وفيها راتبُ كلّ موظّفٍ في المتجر */
    public const PAYROLL_VIEW = 'payroll.view';

    /** اعتمادُ المسيرة — وهو الفعل الذي يُنشئ الالتزام */
    public const PAYROLL_APPROVE = 'payroll.approve';

    /** صرفُ الرواتب — مالٌ يخرج، وهو فعلٌ غيرُ الاعتماد */
    public const PAYROLL_PAY = 'payroll.pay';

    /*
     * ═══ فواتيرُ العملاء: أفعالُ المال تُمنح بأسمائها ═══
     *
     * القسمُ («المبيعات») يفتح الشاشةَ ويكتب المسودّة — والمسودّةُ لا تُنشئ
     * ذمّةً ولا تكتب قيدًا، فلا تحتاج اسمًا. وما بعدها يحتاج: الإصدارُ يولد
     * الذمّة ويكتب القيد، والإلغاءُ يعكسه، والإشعارُ الدائن يُنقصه، والتحصيلُ
     * مالٌ يدخل الصندوق.
     *
     * وكان الأربعةُ يُملَكون بفتح «المبيعات» — فمن يُؤتمن على قراءة الطلبات
     * يُلغي فاتورةً صادرةً على وزارة.
     */

    /** إصدارُ فاتورة العميل — وهو الفعل الذي يُنشئ الذمّة ويكتب القيد */
    public const CUSTOMER_INVOICE_ISSUE = 'customerInvoice.issue';

    /** إلغاءُ فاتورةٍ صادرة — عكسُ قيدٍ وُقّع، لا محوُ ورقة */
    public const CUSTOMER_INVOICE_CANCEL = 'customerInvoice.cancel';

    /** إشعارٌ دائن — يُنقص دَينًا قائمًا على العميل */
    public const CUSTOMER_CREDIT_NOTE = 'customerCreditNote.create';

    /** تسجيلُ تحصيل — مالٌ يدخل، وهو فعلٌ غيرُ الإصدار */
    public const CUSTOMER_PAYMENT_CREATE = 'customerPayment.create';

    /** فتحُ مرفقات المستندات المالية — وفيها أثمنُ ما في المتجر: أسعارُ شرائه */
    public const ATTACHMENT_VIEW = 'attachment.view';

    public const ACTIONS = [
        'order.edit' => 'تصحيح فاتورة مكتملة',
        /*
         * ائتمانُ العميل: وضعُ حدّه وتجاوزُه — فعلٌ واحدٌ لا فعلان.
         *
         * التجاوزُ في البيع يُكتب بسببه ويُسجَّل. ووضعُ الحدّ كان مفتوحًا بقسم
         * «العملاء»، فمن لا يملك التجاوز يمحو الحدَّ (والفراغُ «بلا حدّ»)
         * ويبيع بلا سقف. ومفتاحان لثقةٍ واحدة يفترقان يومًا — انظر
         * `ReceivablesController::credit`.
         */
        'credit.override' => 'ضبط ائتمان العميل وتجاوز حدّه',
        /*
         * ومن يكتب الاستلام ليس بالضرورة من يعتمده.
         *
         * قسمُ «المشتريات» يُمنح لمن يطلب البضاعة ويستلمها؛ وإدخالُها الرفَّ
         * وترجيحُ متوسّط تكلفتها قرارٌ آخر — كميّةٌ تُكتب أكبر ممّا وصل
         * تُفسد تسعير كلّ بيعةٍ بعدها ولا يكشفها إلّا الجرد.
         */
        self::RECEIPT_VIEW => 'عرض إشعارات الاستلام',
        self::RECEIPT_CREATE => 'تسجيل استلام بضاعة',
        self::RECEIPT_APPROVE => 'اعتماد استلام البضاعة',
        /*
         * والرفضُ يُفصل عن الاعتماد: هو نصفُ المراجعة الآمن.
         *
         * ورقةٌ تُرفض لا تُحرّك رفًّا ولا تكتب قيدًا — فمن يُوثَق به ليقول
         * «هذه ناقصة» لا يلزم أن يُوثَق به ليقول «هذه صحيحة فأدخِلها».
         */
        self::RECEIPT_REJECT => 'رفض إشعار استلام',
        /*
         * وسندُ المورّد أربعةُ أفعالٍ لا فعلٌ واحد.
         *
         * من يُدخل الأوراق ليس بالضرورة من يقرّ بأنّ المتجر مدينٌ بها، ولا
         * من يخرج المال بها — وسندٌ يُرحَّل لحظةَ كتابته كان يجعل الثلاثة
         * فعلًا واحدًا.
         */
        self::INVOICE_VIEW => 'عرض سندات الموردين',
        self::INVOICE_CREATE => 'إدخال سند مورّد',
        self::INVOICE_APPROVE => 'اعتماد سند المورّد',
        self::INVOICE_REJECT => 'رفض سند المورّد',
        /*
         * والتجاوزُ منفصلٌ عن الاعتماد: من يعتمد السنداتِ المطابِقة يوميًّا
         * ليس بالضرورة من يقرّر الدفعَ عن بضاعةٍ لم تصل.
         */
        self::INVOICE_OVERRIDE => 'تجاوز عدم مطابقة سند المورّد',
        // والسدادُ آخرُها: مالٌ يخرج مقابل ذمّةٍ نشأت، لا مقابل ورقةٍ وصلت
        self::INVOICE_PAY => 'سداد سندات الموردين',
        /*
         * والرواتب: قراءتُها وحدها فعلٌ يُمنح.
         *
         * مسيرةُ الرواتب كانت تحت قسم «الرواتب والموظفين»، فمن مُنح القسمَ
         * ليضيف موظّفًا أو يصحّح مسمّاه كان يقرأ راتبَ كلّ من في المتجر —
         * وهو أكثرُ ما يُفسد بين الموظّفين إن تسرّب.
         */
        self::PAYROLL_VIEW => 'عرض مسيرة الرواتب',
        self::PAYROLL_APPROVE => 'اعتماد مسيرة الرواتب',
        self::PAYROLL_PAY => 'صرف الرواتب',
        /*
         * والمرفقاتُ بابٌ واحدٌ لكلّ المستندات المالية.
         *
         * ورقةُ المورّد فيها أسعارُ شرائك — أثمنُ ما في متجرك عند منافسك.
         */
        self::CUSTOMER_INVOICE_ISSUE => 'إصدار فاتورة عميل',
        self::CUSTOMER_INVOICE_CANCEL => 'إلغاء فاتورة عميل صادرة',
        self::CUSTOMER_CREDIT_NOTE => 'إصدار إشعار دائن',
        self::CUSTOMER_PAYMENT_CREATE => 'تسجيل تحصيل من عميل',
        self::ATTACHMENT_VIEW => 'فتح مرفقات المستندات المالية',
    ];

    /**
     * من يفعلها بدوره وحده — ومن سواه يُمنحها بالاسم.
     *
     * وليست `'*'`: صاحبُ النشاط ومديرُ الفرع يملكان كلَّ قسم، ولا يعني ذلك
     * أنّ كلّ فعلٍ يقع تحت `*` صامتًا يوم يُضاف. فما لا يُذكر هنا لا يُمنح.
     */
    public const ACTION_ROLES = [
        'order.edit' => ['admin', 'manager'],
        // والكاشير لا يتجاوز حدًّا وضعه صاحبُ المتجر: يطلبه ممّن وضعه
        'credit.override' => ['admin', 'manager'],
        /*
         * والقراءةُ والكتابةُ تُورَثان بالدور كما كانتا قبل التفصيل.
         *
         * فمن يفتح «المخزون» و«المشتريات» اليوم بدوره يبقى يفتحهما غدًا:
         * التفصيلُ يضيّق ما يُخرج المالَ ويُنشئ الذمّة، ولا يقطع عن أحدٍ
         * شاشةً يعمل عليها.
         */
        self::RECEIPT_VIEW => ['admin', 'manager', 'inventory'],
        self::RECEIPT_CREATE => ['admin', 'manager', 'inventory'],
        self::INVOICE_VIEW => ['admin', 'manager', 'inventory'],
        self::INVOICE_CREATE => ['admin', 'manager', 'inventory'],
        self::ATTACHMENT_VIEW => ['admin', 'manager', 'accountant', 'inventory'],
        // والاعتمادُ والرفضُ للمالك ومدير الفرع — ومن سواهما يُمنحهما بالاسم
        self::RECEIPT_APPROVE => ['admin', 'manager'],
        self::RECEIPT_REJECT => ['admin', 'manager'],
        self::INVOICE_APPROVE => ['admin', 'manager'],
        self::INVOICE_REJECT => ['admin', 'manager'],
        // والتجاوزُ للمالك وحده: دفعٌ عن بضاعةٍ لم تصل قرارُ من يملك المال
        self::INVOICE_OVERRIDE => ['admin'],
        /*
         * والسدادُ يضيق عمّا كان: كان أمينُ المخزن يسدّد للمورّد لأنّه يملك
         * «المشتريات». وإخراجُ المال ليس من عمله — يطلبه ممّن يملكه.
         */
        self::INVOICE_PAY => ['admin', 'manager'],
        /*
         * والرواتب: المحاسبُ يقرؤها لأنّها عملُه، ولا يعتمد ولا يصرف.
         * والاعتمادُ إقرارٌ بالتزام، والصرفُ إخراجُ مال — كلاهما لصاحبهما.
         */
        /*
         * وفواتيرُ العملاء: البائعُ يكتب المسودّة بقسمه، ولا يُصدر ولا يُلغي.
         *
         * والمحاسبُ يُصدر ويحصّل ويُصدر الإشعارَ الدائن — فذلك عملُه. والإلغاءُ
         * وحدَه للمالك ومدير الفرع: عكسُ قيدٍ وُقّع على وزارةٍ ليس تصحيحَ
         * خطأٍ مطبعيّ.
         */
        self::CUSTOMER_INVOICE_ISSUE => ['admin', 'manager', 'accountant'],
        self::CUSTOMER_PAYMENT_CREATE => ['admin', 'manager', 'accountant'],
        self::CUSTOMER_CREDIT_NOTE => ['admin', 'manager', 'accountant'],
        self::CUSTOMER_INVOICE_CANCEL => ['admin', 'manager'],
        self::PAYROLL_VIEW => ['admin', 'manager', 'accountant'],
        self::PAYROLL_APPROVE => ['admin', 'manager'],
        self::PAYROLL_PAY => ['admin', 'manager'],
    ];

    /**
     * ما كان القسمُ يبيحه قبل أن تُفصَّل أفعالُ الدورة المالية.
     *
     * مصدرٌ واحد تقرأ منه هجرةُ الترقية (فتمنح من خُصِّصت صلاحياتُه يدويًّا
     * ما كان يفعله أمسِ) ويقرأ منه الاختبارُ حين يبني موظّفًا بقائمةٍ يدوية.
     * وقائمتان تُكتبان باليد تفترقان يومًا، فيمرّ الاختبارُ ويُردّ الموظّف.
     *
     * والاعتمادُ والرفضُ والتجاوزُ ليست فيها: لم تكن تُملَك بقسمٍ يومًا.
     */
    public const LEGACY_SECTION_ACTIONS = [
        'inventory' => [self::RECEIPT_VIEW, self::ATTACHMENT_VIEW],
        'purchases' => [
            self::RECEIPT_CREATE,
            self::INVOICE_VIEW,
            self::INVOICE_CREATE,
            self::INVOICE_PAY,
            self::ATTACHMENT_VIEW,
        ],
        /*
         * و«المبيعات» كانت تبيح ثلاثةً: من فتحها أصدر وألغى وأصدر الإشعارَ
         * الدائن.
         *
         * والتحصيلُ ليس منها: بابُه `customerPayments` وهو محسوبٌ على
         * «المالية» في `ALIASES` — فمن مُنح «المبيعات» وحدها لم يكن يبلغه
         * أصلًا. ووضعُه هنا كان يمنح البائعَ فعلًا لم يملكه يومًا.
         */
        'orders' => [
            self::CUSTOMER_INVOICE_ISSUE,
            self::CUSTOMER_INVOICE_CANCEL,
            self::CUSTOMER_CREDIT_NOTE,
        ],

        // والتحصيلُ لمن كانت له «المالية» — وهي بابُه قبل التفصيل وبعده
        'finance' => [self::CUSTOMER_PAYMENT_CREATE],
        'employees' => [self::PAYROLL_VIEW, self::PAYROLL_APPROVE, self::PAYROLL_PAY],
        'expenses' => [self::ATTACHMENT_VIEW],
    ];

    /**
     * قائمةٌ يدوية مضافًا إليها ما كانت أقسامُها تبيحه — كما تفعل الهجرة.
     *
     * @param  list<string>  $list
     * @return list<string>
     */
    public static function withLegacyActions(array $list): array
    {
        foreach (self::LEGACY_SECTION_ACTIONS as $section => $actions) {
            if (in_array($section, $list, true)) {
                $list = array_merge($list, $actions);
            }
        }

        return array_values(array_unique($list));
    }

    /**
     * ما يفتحه هذا الموظّف ولا يفتحه الفاعل — وفارغةٌ تعني «يُمسّ حسابُه».
     *
     * ═══ ولماذا هنا لا في المتحكّم ═══
     *
     * سؤالان يُسألان عن الشيء نفسه: الحارسُ يسأله ليردّ، والشاشةُ تسأله
     * لترسم. ولو كُتب في موضعين لافترقا يومًا — فتُرسم أزرارُ «إعادة تعيين
     * كلمة المرور» و«تعطيل الحساب» لمن يردّه الخادمُ عنها، وهو أسوأ من
     * غيابها: الموظّف يظنّ العطبَ في النظام ويعيد المحاولة.
     *
     * والقاعدة: من لا يملك أن **يَمنح** الصلاحية لا يملك أن **يأخذها**
     * بكلمة مرور. انظر `EmployeeController::refuseTouchingSomeoneAboveMe`.
     *
     * @return list<string> مفاتيحُ ما يفوقه به — أقسامًا وأفعالًا
     */
    public static function beyond(?User $actor, User $employee): array
    {
        if (! $actor) {
            return ['*'];
        }

        // صاحبُ النشاط يمسّ الجميع، ونفسُه ليست فوقه
        if ($actor->role === 'admin' || $actor->id === $employee->id) {
            return [];
        }

        return [
            ...array_filter(
                self::SECTIONS,
                fn ($s) => $employee->allows($s) && ! $actor->allows($s),
            ),
            ...array_filter(
                self::actions(),
                fn ($a) => $employee->may($a) && ! $actor->may($a),
            ),
        ];
    }

    /** هل يمسّ هذا الفاعلُ حسابَ هذا الموظّف؟ */
    public static function mayTouch(?User $actor, User $employee): bool
    {
        return self::beyond($actor, $employee) === [];
    }

    /** هل هذا المفتاح فعلٌ لا قسم؟ — النقطة تفصلهما */
    public static function isAction(string $key): bool
    {
        return str_contains($key, '.');
    }

    /** @return list<string> */
    public static function actions(): array
    {
        return array_keys(self::ACTIONS);
    }

    /** أسماء الأفعال كما تُعرض للتاجر */
    public static function actionLabels(): array
    {
        return collect(self::ACTIONS)->map(fn ($label) => __($label))->all();
    }

    /** هل يفعلها هذا الدور بلا منحٍ باسمه؟ */
    public static function allowsAction(?string $role, string $action): bool
    {
        return in_array($role, self::ACTION_ROLES[$action] ?? [], true);
    }

    /** كل الأقسام التي تظهر في لوحة النشاط — مصدر واحد تقرأ منه الواجهة */
    public const SECTIONS = [
        'dashboard', 'customers', 'products', 'orders', 'marketing',
        'inventory', 'finance', 'expenses', 'settings',
        /*
         * الموقع الإلكتروني قسمٌ مستقلّ عن «الإعدادات».
         *
         * من مُنح الإعدادات ليضبط ضريبةً أو يضيف فرعًا كان يُمنح معها نشرَ
         * موقع المتجر على الإنترنت وتبديلَ ما يقرؤه كلّ زائر. وهما عملان لا
         * يفعلهما الشخص نفسه في أكثر المتاجر.
         */
        'website',
        /*
         * التطبيقات التكاملية قسمٌ مستقلّ عن «أدوات التسويق».
         *
         * ربطُ أداةٍ خارجية يعني تسليمَ مفتاحٍ يُنفِق على حساب المتجر، أو
         * فتحَ قناةٍ تخاطب زبائنه باسمه. وهذا قرارُ صاحبِ المتجر، لا قرارُ
         * من يكتب كوبونًا أو يردّ على تقييم — وكلاهما كان يُمنح «التسويق»
         * فيُمنح معه مفتاحُ Places ووصلةُ واتساب.
         */
        'integrations',
        'suppliers', 'purchases', 'employees', 'pos', 'reports',
        /*
         * التجهيز قسمٌ مستقلّ لا جزءٌ من «المبيعات».
         *
         * «المبيعات» تفتح الفواتير وإجماليّاتها ومجموعَ ما رُشّح — وهي أوسع
         * بكثير ممّا يحتاجه من يصنع الباقة. ومنحُها لعامل التجهيز يعني أنّ
         * كلّ من يقف عند الطاولة يقرأ مبيعات المحلّ.
         */
        'preparation',
    ];

    /**
     * الأدوار التي تدخل لوحة النشاط. الكاشير ليس منها — صلاحية `dashboard`
     * وحدها لا تكفي، لأن حارس المسار دورٌ لا صلاحية.
     *
     * مصدرٌ واحد: يقرأه middleware المسار، ويقرأه الخادم ليخبر الواجهة هل
     * تُظهر زر «العودة إلى اللوحة» أم لا. الفصل بينهما كان سيُظهر زرًّا
     * يقود إلى 403.
     */
    public const PANEL_ROLES = ['admin', 'manager', 'accountant', 'inventory', 'sales', 'delivery'];

    /**
     * لا قسم مفتوحًا بلا منح.
     *
     * كانت «لوحة التحكم» و«نقطة البيع» و«الفروع» تُفتح لكل من دخل مهما كانت
     * صلاحياته — فصاحب النشاط يرفع علامة عنها ولا يتغيّر شيء، وهو أسوأ ما
     * يكون: منعٌ ظاهرٌ في الشاشة لا وجود له في الواقع. صارت الثلاثة تُمنح
     * صراحةً كغيرها، فما لم يُعلَّم لا يُفتح.
     */
    public const ALWAYS_OPEN = [];

    /**
     * أقسام خارج لوحة النشاط: تُمنح ولا تفتح بابها.
     *
     * نقطة البيع شاشةٌ قائمة بذاتها، فمنحُها وحدها لا يجعل صاحبها يدخل
     * اللوحة — وإلا لصار كل كاشير في اللوحة بمجرّد أن يبيع.
     */
    public const OUTSIDE_PANEL = ['pos'];

    /**
     * هل يدخل هذا المستخدم لوحة النشاط؟
     *
     * الدور وحده لا يكفي منذ صارت الصلاحيات تُخصَّص يدويًّا: كاشيرٌ مُنح
     * صلاحية المخزون كان يُمنع عند الباب فلا تصل صلاحيته إلى الفحص أصلًا —
     * ميزةٌ تُحفَظ في القاعدة ولا تعمل. فمن مُنح قسمًا من أقسام اللوحة يدخل
     * ليصل إليه، ويبقى ما لم يُمنح محجوبًا عنه بحارس القسم.
     */
    public static function entersPanel(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        if ($user->isSuperAdmin() || in_array($user->role, self::PANEL_ROLES, true)) {
            return true;
        }

        /*
         * والفعلُ لا يفتح بابًا.
         *
         * `permissions` تحمل الأقسام والأفعال معًا، ومن مُنح «تصحيح فاتورة»
         * وحدَه لم يُمنح شاشةً واحدة — فلو عُدَّ منحُه دخولًا لوقف عند أوّل
         * قسمٍ بـ٤٠٣، أو رأى لوحةً فارغة لا شيء فيها له.
         */
        return collect($user->permissions ?? [])
            ->filter(fn ($s) => in_array($s, self::SECTIONS, true))
            ->reject(fn ($s) => in_array($s, self::OUTSIDE_PANEL, true))
            ->isNotEmpty();
    }

    /**
     * أوّل صفحةٍ في اللوحة يفتحها هذا المستخدم فعلًا — أو null.
     *
     * `entersPanel` تجيب «هل يدخل؟» ولا تقول «إلى أين». وزرّ «لوحة النشاط»
     * في نقطة البيع كان يعتمد عليها ثم يقود إلى `admin.dashboard` دائمًا،
     * فموظّفٌ مُنح المخزون وحده يرى الزرّ — لأنه يدخل اللوحة — ويصطدم بـ403
     * على قسمٍ لم يُمنحه. بابٌ يُعرض ولا يُفتح، وهو أسوأ من بابٍ لا يُعرض:
     * الموظّف يظنّ العطب في النظام ويعيد المحاولة، والتاجر يظنّ أن صلاحياته
     * لم تُحفظ.
     *
     * فيُسأل عن الوجهة لا عن الإذن، ومن لا وجهة له لا يرى الزرّ.
     */
    public static function panelEntry(?User $user): ?string
    {
        if ($user === null || ! self::entersPanel($user)) {
            return null;
        }

        if ($user->isSuperAdmin()) {
            return route('super-admin.dashboard');
        }

        return collect(self::SECTIONS)
            ->reject(fn ($s) => in_array($s, self::OUTSIDE_PANEL, true))
            ->filter(fn ($s) => $user->allows($s))
            ->map(fn ($s) => self::routeFor($s))
            ->first(fn ($r) => $r !== null);
    }

    /** القسم → مساره. مصدرٌ واحد يقرؤه التوجيه بعد الدخول وروابط التنبيهات */
    public const ROUTES = [
        'dashboard' => 'admin.dashboard', 'customers' => 'admin.customers.index',
        'products' => 'admin.products.index', 'orders' => 'admin.orders.index',
        'marketing' => 'admin.marketing.loyalty', 'inventory' => 'admin.inventory.index',
        'finance' => 'admin.finance.index', 'expenses' => 'admin.expenses.index',
        'website' => 'admin.website.index',
        'integrations' => 'admin.integrations.index',
        'settings' => 'admin.settings.index', 'suppliers' => 'admin.suppliers.index',
        'purchases' => 'admin.purchases.index', 'employees' => 'admin.employees.index',
        'pos' => 'pos.index',
        // «التقارير» كانت في SECTIONS ولا مسار لها هنا: من أوّلُ ما مُنح له
        // التقاريرُ يسقط على `ROUTES['reports']` غير الموجود، فيرتفع خطأ
        // مفتاحٍ ناقص داخل HandleInertiaRequests — أي ٥٠٠ على كل صفحةٍ يفتحها
        'reports' => 'admin.reports.index',
        'preparation' => 'admin.preparation.index',
    ];

    /**
     * مسار القسم إن كان له باب — وإلا null.
     *
     * القراءة المباشرة من ROUTES كانت تنفجر على قسمٍ نُسي فيها بدل أن تتخطّاه:
     * والفشل هنا يقع في مشاركة Inertia، فيصير خمسمئةً على كلّ صفحة لا بابًا
     * مفقودًا في قائمة.
     */
    private static function routeFor(string $section): ?string
    {
        return isset(self::ROUTES[$section]) ? route(self::ROUTES[$section]) : null;
    }

    /**
     * أوّل صفحة يراها المستخدم بعد الدخول.
     *
     * كانت تُختار بالدور وحده، فتُرسل كلّ من ليس مديرًا إلى نقطة البيع. ومنذ
     * صارت الصلاحيات تُخصَّص، صار ذلك يعني دخولًا ناجحًا ينتهي إلى 403: موظفٌ
     * مُنح المخزون ولم يُمنح نقطة البيع يُدفع إلى بابٍ مغلق في وجهه. فيُختار
     * القسم من صلاحياته: لوحته إن ملكها، ثمّ نقطة البيع، ثمّ أوّل ما مُنح.
     *
     * وبابان يجب أن يتّفقا هنا: صلاحية القسم، ودخول اللوحة. الكاشير يملك
     * صلاحية «لوحة التحكم» بحكم دوره ولا يدخل اللوحة — فإرساله إليها دخولٌ
     * ينتهي إلى 403. فلا يُقترح قسمٌ من اللوحة على من لا يدخلها.
     */
    public static function homeFor(User $user): string
    {
        if ($user->isSuperAdmin()) {
            return route('super-admin.dashboard');
        }

        $panel = self::entersPanel($user);

        if ($panel && $user->allows('dashboard')) {
            return route(self::ROUTES['dashboard']);
        }

        if ($user->allows('pos')) {
            return route(self::ROUTES['pos']);
        }

        // بلا صلاحية واحدة: تُرفض الآن عند الحفظ، لكن حسابًا قديمًا قد يسبقها
        return ($panel ? self::panelEntry($user) : null) ?? route('login');
    }

    /** أسماء الأقسام كما تُعرض للتاجر — الواجهة لا تخمّنها من المفتاح */
    public static function sectionLabels(): array
    {
        $labels = [
            'dashboard' => 'لوحة التحكم', 'customers' => 'العملاء', 'products' => 'المنتجات',
            'orders' => 'المبيعات', 'marketing' => 'أدوات التسويق', 'inventory' => 'المخزون',
            'finance' => 'المالية', 'expenses' => 'مصاريف شهرية', 'settings' => 'الإعدادات',
            'website' => 'الموقع الإلكتروني',
            'integrations' => 'التطبيقات التكاملية',
            'suppliers' => 'الموردين', 'purchases' => 'المشتريات',
            'employees' => 'الرواتب والموظفين', 'pos' => 'نقطة البيع',
            // كانت ساقطةً فتُعرض «reports» بحروفٍ لاتينية في قائمة صلاحيات عربية
            'reports' => 'التقارير',
            'preparation' => 'لوحة التجهيز',
        ];

        return collect(self::SECTIONS)
            ->mapWithKeys(fn ($s) => [$s => __($labels[$s] ?? $s)])
            ->all();
    }

    public static function sections(): array
    {
        return self::SECTIONS;
    }

    /**
     * ما يفتحه الدور — والمجهول لا يفتح شيئًا.
     *
     * كان السطر ينتهي بـ`?? ['*']`، فأيّ دورٍ لا تعرفه الخريطة يمرّ بكلّ
     * قسم: خطأٌ مطبعيّ في الحقل، أو صفٌّ قديم بدورٍ أُلغي، أو طلبٌ يُرسَل
     * إلى المسار مباشرةً بدورٍ مخترَع — كلّها كانت تنتهي إلى صلاحياتٍ كاملة.
     * والفشل في بابٍ يجب أن يُغلقه لا أن يفتحه على مصراعيه.
     *
     * وصاحبُ النشاط ومديرُ الفرع يبقيان على `*` صراحةً في الخريطة، فلا
     * يمسّ هذا التشديدُ أحدًا يعمل اليوم.
     */
    public static function abilities(?string $role): array
    {
        return self::MAP[$role] ?? [];
    }

    public static function allows(?string $role, string $section): bool
    {
        if ($role === 'super_admin') {
            return true;
        }
        $abilities = self::abilities($role);
        if (in_array('*', $abilities, true)) {
            return true;
        }
        if (in_array($section, self::ALWAYS_OPEN, true)) {
            return true;
        }

        return in_array($section, $abilities, true);
    }

    /**
     * مسارات هيكل اللوحة لا أقسامها: الجرس، والبحث، ومبدّلا اللغة والعملة.
     *
     * هذه أدوات الشريط العلوي التي يراها كلّ من دخل اللوحة أيًّا كان قسمه —
     * ليست بابًا إلى بيانات قسم، فلا يُشتقّ لها مفتاح صلاحية. والبحث يصفّي
     * نتائجه بنفسه حسب ما يملكه صاحبه (انظر SearchController).
     */
    public const SHELL = [
        'admin.search', 'admin.currency.switch', 'admin.language.update',
        'admin.notifications.feed', 'admin.notifications.dismiss', 'admin.notifications.clear',
        /*
         * و«المساعدة والدعم» من الهيكل لا من الأقسام.
         *
         * قسمٌ يُمنح يعني قسمًا يُمنع — وبابُ دعمٍ يُغلق أمام كاشيرٍ يرى
         * العطبَ بعينه يعني أن يمرّ البلاغُ بصاحب المتجر أو لا يمرّ. ومن
         * يدخل اللوحة يستطيع أن يسأل، كما يستطيع أن يبحث ويقرأ تنبيهاته.
         *
         * والحصرُ بمتجره قائمٌ على كلّ حال في `HelpController::mine` — فهذا
         * إعفاءٌ من سؤال «أيُّ قسمٍ؟» لا من سؤال «أيُّ متجر؟».
         */
        'admin.help.index', 'admin.help.store', 'admin.help.show',
        'admin.help.reply', 'admin.help.attachment',
    ];

    /**
     * مسارٌ اسمه لا يشتقّ قسمه — يُنسب صراحةً إلى القسم الذي يملكه.
     *
     * الاشتقاق من الاسم يعمل ما دام الاسم يطابق مفتاح الصلاحية. وحين يفترقان
     * يُنتج مفتاحًا لا وجود له في SECTIONS، فلا يملكه أحد: يُمنع منه كلّ من
     * خُصّصت صلاحياته يدويًّا مهما مُنح، ويُمنع منه كلُّ دورٍ إلا المالك
     * والمدير (لهما '*'). والنتيجة صفحةٌ في القائمة لا تُفتح، أو علامةٌ
     * يرفعها صاحب النشاط ولا تغيّر شيئًا.
     *
     * أوضحها «الفروع»: مساره admin.branches يشتقّ 'branches' والمفتاح
     * 'branch' — فمنحُها كان بلا أثر.
     */
    public const ALIASES = [
        // تبديل الفرع صار في قائمة الحساب بالهيدر، وصفحة الفروع في الإعدادات
        'branches' => 'settings',
        'branch' => 'settings',
        'addons' => 'products',
        'jobTitles' => 'employees',
        // مسيرة الرواتب وصرفها من قسم «الرواتب والموظفين» — لا مفتاح ثالث لها
        'payroll' => 'employees',
        'coupons' => 'marketing',
        'bank' => 'finance',
        'expenseTypes' => 'expenses',
        'goals' => 'dashboard',
        'alerts' => 'settings',
        'backup' => 'settings',
        // فواتيرُ العملاء مستندُ بيع: من يرى الطلبات يراها
        'customerInvoices' => 'orders',
        // والتحصيلُ مالٌ يدخل الصندوق: بابُه المالية
        'customerPayments' => 'finance',
        // تهيئةُ المتجر أوّلَ مرّة تكتب بيانات النشاط — فتتبع بابَها
        'setup' => 'settings',
        'activity' => 'settings',
        // أجهزة نقطة البيع إعدادٌ إداريّ: من يملك الإعدادات يفعّل ويُلغي
        'devices' => 'settings',
        // معاينةُ المتجر تتبع الشاشة التي يُضبط فيها — «الإعدادات»
        'store' => 'settings',
    ];

    /**
     * التصدير يتبع قسم ما يُصدَّر: admin.export.orders → orders.
     *
     * وما ليس هنا يسقط إلى «الإعدادات» — أضيقُ ما يُمنح. و`reports` كانت
     * ساقطةً فيه: زرّ «CSV» في ملخّص المبيعات يُرسم لكل من يفتح الصفحة،
     * ويُطالَب صاحبه عند الضغط بصلاحية الإعدادات. فالمحاسب — وهو أكثر من
     * يُصدّر — يفتح تقريرًا مأذونًا له ويُردّ بـ٤٠٣ عن ملفّه.
     */
    public const EXPORT_ALIASES = [
        'products' => 'products', 'orders' => 'orders', 'customers' => 'customers',
        'transactions' => 'finance', 'expenses' => 'expenses', 'inventory' => 'inventory',
        'reports' => 'reports',
        // و«الموردون» كانت ساقطةً مثلها: زرّ CSV في شاشة المورّدين يُرسم لمن
        // يملك القسم، ويُطالَب عند الضغط بصلاحية الإعدادات — وأمين المخزن
        // يملك المورّدين ولا يملك الإعدادات، فلا يُنزِّل ملفّ شاشته أبدًا.
        // وحارسٌ في ReportsTellTheirScopeTest يمنع سقوط الثامن.
        'suppliers' => 'suppliers',
    ];

    /** هل هذا المسار من هيكل اللوحة لا من أقسامها؟ */
    public static function isShell(?string $route): bool
    {
        return $route !== null && in_array($route, self::SHELL, true);
    }

    /** استخراج القسم من اسم المسار: admin.products.index → products */
    public static function sectionFromRoute(?string $route): string
    {
        if (! $route) {
            return 'dashboard';
        }
        // شاشة المدفوعات داخل نقطة البيع شاشة مالية لا شاشة بيع: تعرض
        // تحصيلات اليوم وطرق الدفع. الكاشير يبيع ولا يطّلع على حصيلة
        // الصندوق، فتتبع صلاحية «finance» لا صلاحية «pos» المفتوحة للجميع.
        if ($route === 'pos.payments') {
            return 'finance';
        }
        if (str_starts_with($route, 'pos.')) {
            return 'pos';
        }
        $parts = explode('.', $route);
        $section = $parts[1] ?? 'dashboard';

        if ($section === 'export') {
            // ما لا يُعرف ما يُصدَّر يتبع الإعدادات: أضيقُ ما يُمنح
            return self::EXPORT_ALIASES[$parts[2] ?? ''] ?? 'settings';
        }

        return self::ALIASES[$section] ?? $section;
    }
}
