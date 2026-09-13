<?php

namespace App\Support;

use App\Models\Setting;

/**
 * أوراقُ النظام وقوالبُها — سِجلٌّ واحد تُبنى منه الشاشة والورقة معًا.
 *
 * وكان القالبُ واحدًا يحكم ورقةَ البيع وحدها، وسائرُ الأوراق لا تُطبع أصلًا:
 * أمرُ شراءٍ يُرسل إلى مورّد، وسندُ استلامٍ يُوقَّع عند الباب، وشحنةٌ تخرج
 * بلا سندٍ يوقّعه مستلمها — كلُّها تُنشأ في النظام ولا تخرج منه على ورق.
 *
 * والسجلُّ هنا مصدرٌ واحد: منه تُبنى بطاقاتُ «قوالب» في الإعدادات، ومنه
 * تُشتقّ مفاتيحُ الحفظ، ومنه تُصادَق الحقول، وبه تُرسم الورقة. وقائمةٌ
 * تُكتب باليد في كلٍّ من هذه المواضع تنسى التاليَ دائمًا: يُضاف نوعٌ فيظهر
 * في الشاشة ولا يُقبل في الحفظ، أو يُحفظ ولا يقرؤه الرسم.
 *
 * ومفاتيحُ ورقة البيع تبقى كما هي — `tpl_show_logo` لا `tpl_sale_show_logo`
 * — لأنّ متاجرَ ضبطتها من قبل. وإعادةُ تسميتها تُعيد كلَّ واحدٍ منها إلى
 * الافتراضيّ بلا خطأ ولا أثر: تُطبع ورقةٌ غير التي اعتادها التاجر.
 */
class DocumentTemplates
{
    /**
     * الحقول التي تُعرض في كلّ ورقة — أسماؤها ومعناها في موضعٍ واحد.
     *
     * وتُكتب مرّةً لا مع كلّ نوع: خمسةُ أنواعٍ تعرض «اسم الفرع» بخمسة نصوصٍ
     * متفرّقة تعني تعديلًا في خمسةٍ كلّما تغيّرت الكلمة — وتبديلَ أربعةٍ
     * ونسيانَ الخامسة.
     */
    public const FIELDS = [
        'show_logo' => ['label' => 'شعار المتجر', 'hint' => 'يظهر فقط إن كان للنشاط شعار محفوظ'],
        'show_branch' => ['label' => 'اسم الفرع'],
        'show_employee' => ['label' => 'اسم الموظف'],
        'show_customer' => ['label' => 'اسم العميل', 'hint' => 'يبقى ظاهرًا في الفاتورة الضريبية دائمًا — بدونه لا يخصم المشتري ضريبته'],
        'show_supplier' => ['label' => 'اسم المورّد'],
        'show_datetime' => ['label' => 'التاريخ والوقت'],
        'show_items_count' => ['label' => 'عدد الأصناف'],
        'show_vat_no' => ['label' => 'الرقم الضريبي'],
        'show_qr' => ['label' => 'رمز الفوترة الإلكترونية (QR)', 'hint' => 'بصيغة ZATCA الخليجية. لا يظهر بلا رقم ضريبي. راجع جهاز الضرائب قبل الاعتماد عليه'],
        'show_prices' => ['label' => 'الأسعار والإجمالي', 'hint' => 'أطفئه في ورقةٍ يحملها سائق أو يوقّعها مستلم — فلا يرى ما لا يخصّه'],
        'show_notes' => ['label' => 'الملاحظات'],
        'show_signature' => ['label' => 'خانة التوقيع', 'hint' => 'سطرٌ يُوقَّع عليه عند التسليم — ورقةٌ بلا توقيع لا تُثبت شيئًا'],
    ];

    /**
     * الأوراق — وكلُّ ورقةٍ منها **تُطبع فعلًا** من شاشتها.
     *
     * ولا يُدرج هنا نوعٌ لا مطبعَ له: قالبٌ يُضبط لورقةٍ لا تخرج مقبضٌ لا
     * يُدير شيئًا. و`delivery` تُرسم من الطلب لا من جدول `delivery_notes`:
     * ذاك جدولٌ لا يكتب فيه شيءٌ في النظام كلّه.
     *
     * ورُفع `transfer` مع شاشته: النقل بين الفروع حُذف بطلب صاحب النظام،
     * فلا سندَ يُنشأ ليُطبع — وقالبٌ لورقةٍ لا تُنشأ مقبضٌ لا يُدير شيئًا.
     */
    public const TYPES = [
        'sale' => [
            'label' => 'فاتورة البيع',
            'desc' => 'الإيصال الحراري وفاتورة A4 والفاتورة الضريبية — قالبٌ واحد يحكم الثلاث',
            'section' => 'المبيعات',
            'legacy' => true,
            'paper' => true,
            'strip' => true,
            'fields' => [
                'show_logo' => false, 'show_branch' => true, 'show_employee' => true,
                'show_customer' => true, 'show_datetime' => true, 'show_items_count' => true,
                'show_vat_no' => false, 'show_qr' => true,
            ],
        ],
        /*
         * فاتورةُ العميل — الورقةُ التي تُرسَل إلى شركةٍ أو وزارة.
         *
         * ═══ ولماذا دخلت السجلَّ متأخّرةً ═══
         *
         * كانت خارجه: تُطبع بترويسةٍ لا يملك التاجر منها شيئًا، بينما
         * أخواتُها الأربع تُضبط من «قوالب الأوراق». فمن ضبط تذييلَ فاتورة
         * البيع توقّع أن تتبعه فاتورةُ العميل — ولم تكن تتبعه، ولا موضعَ
         * يقول له لماذا.
         *
         * ═══ وحقولُها ثلاثةٌ لا اثنا عشر ═══
         *
         * ولا يُعرض منها ما لا تديره الورقة: لا «اسم الفرع» ولا «اسم
         * الموظّف» — ليسا فيها أصلًا — ولا «الأسعار» ولا «اسم العميل»:
         * فاتورةٌ بلا مبلغٍ أو بلا جهةٍ ليست فاتورة، ومقبضٌ يُطفئ ما لا
         * يُطفأ أسوأ من غيابه.
         *
         * ═══ وتذييلُها فارغٌ افتراضًا ═══
         *
         * «شكرًا لزيارتكم — نتشرف بخدمتكم دائمًا» عبارةُ إيصالٍ يُسلَّم في
         * المحلّ، لا عبارةُ ورقةٍ تُطالب بها وزارةٌ بمبلغ. فالافتراضُ فراغ،
         * ومن أراد سطرَه كتبه.
         */
        'customer_invoice' => [
            'label' => 'فاتورة العميل',
            'desc' => 'الورقة التي تُرسَل إلى الشركات والجهات مقابل بضاعةٍ أو خدمة',
            'section' => 'المبيعات',
            'footer' => '',
            'fields' => [
                'show_logo' => true, 'show_vat_no' => true, 'show_notes' => true,
            ],
        ],
        'delivery' => [
            'label' => 'سند تسليم',
            'desc' => 'يمشي مع الشحنة ويُوقَّع عند الاستلام — أصنافٌ بلا أسعار',
            'section' => 'المبيعات',
            'fields' => [
                'show_logo' => true, 'show_branch' => true, 'show_employee' => true,
                'show_customer' => true, 'show_datetime' => true, 'show_items_count' => true,
                'show_prices' => false, 'show_notes' => true, 'show_signature' => true,
            ],
        ],
        /*
         * وتذييلُ أوراق المشتريات فارغٌ افتراضًا.
         *
         * «شكرًا لزيارتكم — نتشرف بخدمتكم دائمًا» عبارةُ إيصالٍ يُسلَّم
         * لزبونٍ في المحلّ. وأمرُ الشراء يمضي **إلى المورّد** يطلب منه
         * بضاعة، وسندُ الاستلام يُوقَّع عند باب المخزن — فلا زائرَ في
         * أيّهما يُشكر. وكانت تُطبع عليهما حتى ينتبه صاحبُهما ويمحوها.
         *
         * والفراغُ افتراضٌ لا محو: من كتب تذييلَه يبقى كما كتبه.
         */
        'purchase' => [
            'label' => 'أمر شراء',
            'desc' => 'يُرسل إلى المورّد بما يُطلب منه وكميّاته',
            'section' => 'المشتريات',
            'footer' => '',
            'fields' => [
                'show_logo' => true, 'show_supplier' => true, 'show_branch' => false,
                'show_datetime' => true, 'show_items_count' => true, 'show_prices' => true,
                'show_notes' => true, 'show_vat_no' => true, 'show_signature' => false,
            ],
        ],
        'grn' => [
            'label' => 'سند استلام بضاعة',
            'desc' => 'يُوقَّع عند باب المخزن حين تصل الشحنة',
            'section' => 'المشتريات',
            'footer' => '',
            'fields' => [
                'show_logo' => true, 'show_supplier' => true, 'show_branch' => true,
                'show_employee' => true, 'show_datetime' => true, 'show_items_count' => true,
                'show_prices' => false, 'show_notes' => true, 'show_signature' => true,
            ],
        ],
        /*
         * فاتورةُ المورّد — سندُ ما على المتجر لمورّده.
         *
         * ولها صفحتُها في النظام منذ أن صار الدَّينُ يُعتمد بتوقيع، ولم يكن
         * لها ورقة: يُراجَع سندٌ بمئتين على الشاشة ولا يخرج منه شيءٌ يُرفق
         * بحوالةٍ أو يُوقَّع عليه بالسداد.
         *
         * ولا تذييلَ افتراضيًّا: «شكرًا لزيارتكم» عبارةُ إيصالٍ لزبون.
         */
        'supplier_invoice' => [
            'label' => 'فاتورة المورّد',
            'desc' => 'سندُ ما على المتجر لمورّده — مرجعُه وتواريخه ومبالغه',
            'section' => 'المشتريات',
            'footer' => '',
            'fields' => [
                'show_logo' => true, 'show_supplier' => true, 'show_datetime' => true,
                'show_vat_no' => true, 'show_notes' => true,
            ],
        ],
        /*
         * إشعارٌ دائن — ورقةُ ما رُدّ من فاتورةٍ صدرت.
         *
         * ولا «اسم العميل» في مقابضه: إشعارٌ بلا جهةٍ لا يُنقص ذمّةَ أحد.
         * ولا «الأسعار»: إشعارٌ بلا مبلغ ليس إشعارًا.
         */
        'credit_note' => [
            'label' => 'إشعار دائن',
            'desc' => 'يُنقص ذمّةَ العميل عمّا رُدّ أو خُصم من فاتورةٍ صدرت',
            'section' => 'المبيعات',
            'footer' => '',
            'fields' => [
                'show_logo' => true, 'show_vat_no' => true, 'show_notes' => true,
            ],
        ],
        /*
         * سندُ قبض — إقرارُ المتجر بأنّه استلم مالًا.
         *
         * وخانةُ التوقيع فيه افتراضًا: هو ورقةٌ تُسلَّم لمن دفع، وسندُ قبضٍ
         * بلا توقيعٍ لا يُثبت أنّ أحدًا استلم شيئًا.
         */
        'customer_receipt' => [
            'label' => 'سند قبض',
            'desc' => 'إقرارٌ بقبض مبلغٍ من عميل — وما سُدِّد به من فواتير',
            'section' => 'المبيعات',
            'footer' => '',
            'fields' => [
                'show_logo' => true, 'show_datetime' => true, 'show_vat_no' => true,
                'show_notes' => true, 'show_signature' => true,
            ],
        ],
    ];

    /** أحجام الخطّ المتاحة — والورقة تُرسم بها لا بعددٍ حرّ يُخرج سطرًا لا يُقرأ */
    public const FONTS = ['صغير', 'عادي', 'كبير'];

    /**
     * مقاسات الورق — لورقة البيع وحدها، ومن سجلّ المقاسات لا مكتوبةً هنا.
     *
     * وقائمةٌ تُكتب باليد هنا وأخرى في `PaperSize` تفترقان عند أوّل مقاسٍ
     * يُضاف: يُعرض في الشاشة ولا يُقبل في الحفظ، أو يُحفظ ولا يعرفه المحرّك.
     */
    public static function papers(): array
    {
        return \App\Support\Document\PaperSize::sheets();
    }

    /**
     * عروضُ الشريط الحراريّ — مقاسُ الإيصال لا مقاسُ الفاتورة.
     *
     * وهما قائمتان لأنّهما سؤالان: «على أيّ ورقةٍ تُطبع فاتورتي؟» و«ما عرضُ
     * ورق طابعة الصندوق؟». وقائمةٌ واحدةٌ تخلطهما تجعل الجوابَ عن أحدهما
     * إلغاءً للآخر — وهو ما كان: من اختار «٨٠مم» لم تعد له فاتورة A4.
     */
    public static function strips(): array
    {
        return \App\Support\Document\PaperSize::strips();
    }

    /** التذييل حين لا يكتب التاجر شيئًا — واحدٌ للأوراق الثلاث لا اثنان */
    public const DEFAULT_FOOTER = "شكرًا لزيارتكم\nنتشرف بخدمتكم دائمًا";

    /** هل هذا نوعٌ معروف؟ */
    public static function exists(string $type): bool
    {
        return array_key_exists($type, self::TYPES);
    }

    /**
     * مفتاحُ الحفظ لحقلٍ في نوع — من هنا وحده.
     *
     * ومفاتيحُ ورقة البيع مسطَّحة بلا اسم النوع لأنّها سبقت هذا السجلّ،
     * وتغييرُها يُفقد كلَّ متجرٍ ضبطه القديم بلا أن يقول له أحد.
     */
    public static function key(string $type, string $field): string
    {
        $spec = self::TYPES[$type] ?? null;

        if ($spec === null) {
            return 'tpl_'.$type.'_'.$field;
        }

        if (($spec['legacy'] ?? false) === true) {
            return $field === 'paper' ? 'paper' : 'tpl_'.$field;
        }

        return 'tpl_'.$type.'_'.$field;
    }

    /** الافتراضيّ لكلّ حقلٍ في النوع — أعلامًا ونصوصًا */
    public static function defaults(string $type): array
    {
        $spec = self::TYPES[$type] ?? [];

        $out = $spec['fields'] ?? [];
        $out['header'] = '';
        /*
         * والتذييلُ الافتراضيُّ قد يخصّ النوعَ نفسَه.
         *
         * «شكرًا لزيارتكم» تصلح إيصالًا يُسلَّم في المحلّ ولا تصلح ورقةً
         * تُطالب بها وزارة. وكانت واحدةً للجميع، فالنوعُ الذي لا تناسبه
         * يُطبع بها حتى ينتبه صاحبُه ويمحوها — إن انتبه.
         */
        $out['footer'] = $spec['footer'] ?? self::DEFAULT_FOOTER;
        $out['font'] = 'عادي';

        /*
         * وورقةُ البيع A4 — والشريطُ مخرجٌ ثانٍ لا بديلٌ عنها.
         *
         * ═══ وكان ٨٠مم هو الافتراضيّ، فلم تكن ثمّة فاتورة ═══
         *
         * `paper` كان مفتاحًا واحدًا يحكم **ماذا يخرج** من باب الطباعة: من
         * اختار الشريطَ الحراريَّ لصندوقه فقد فاتورةَ A4 كلَّها — لا معاينةً
         * ولا طباعةً ولا ملفًّا. وهو الافتراضيّ، فأكثرُ المتاجر لم تكن تملك
         * فاتورةً تُرسلها إلى شركةٍ أصلًا، ولا سطرَ يقول لها لماذا.
         *
         * والوجهُ الآخر أنّ الفاتورةَ الضريبية كانت المخرجَ الوحيد على A4 —
         * وهي تشترط تسجيلًا ضريبيًّا. فغيرُ المسجَّل لا ورقةَ له.
         *
         * فصارا حقلين: `paper` مقاسُ الفاتورة (A4 · A5)، و`strip` عرضُ ورق
         * الطابعة الحراريّة (٨٠ · ٥٨). ولكلٍّ بابُه.
         */
        if (($spec['paper'] ?? false) === true) {
            $out['paper'] = \App\Support\Document\PaperSize::DEFAULT;
        }

        if (($spec['strip'] ?? false) === true) {
            $out['strip'] = \App\Support\Document\PaperSize::T80;
        }

        return $out;
    }

    /**
     * إعداداتُ ورقةٍ محلولةً — أعلامٌ منطقية ونصوصٌ جاهزة للرسم.
     *
     * وتُقرأ دفعةً واحدة لا مفتاحًا مفتاحًا: صفحةُ «قوالب» تعرض خمسة أنواع،
     * وقراءةُ كلّ حقلٍ باستعلامه تُخرج عشرات الاستعلامات لصفحةٍ واحدة.
     *
     * @param  array<string,mixed>|null  $override  قيمٌ لم تُحفظ بعد — للمعاينة الحيّة
     * @return array<string, mixed>
     */
    public static function settings(int $businessId, string $type, ?array $override = null): array
    {
        $defaults = self::defaults($type);

        $saved = Setting::where('business_id', $businessId)
            ->whereIn('key', array_map(fn ($f) => self::key($type, $f), array_keys($defaults)))
            ->pluck('value', 'key');

        /*
         * وعرضُ الطابعة القديم يُورَّث، لا يُمحى.
         *
         * متجرٌ ضبط `paper = 58mm` قال شيئًا واحدًا: «ورقُ صندوقي ٥٨». وبعد
         * أن انقسم الحقلان صار ذلك الصفُّ مرفوضًا في `paper` (ليس مقاسَ
         * ورقةٍ مقصوصة) — ولو تُرك لسقط إلى ٨٠ الافتراضيّ، فيخرج إيصالُه
         * مقصوصًا من الحافّة، ولا شيءَ يقول له إنّ إعدادَه تبدّل.
         *
         * فيُقرأ من موضعه القديم ما دام موضعُه الجديد فارغًا. وهجرةٌ في
         * القراءة لا في القاعدة: لا صفَّ يُكتب لمن لم يفتح شاشةَ القوالب،
         * وأوّلُ حفظٍ يثبّت الجواب في مفتاحه.
         */
        $stripKey = self::key($type, 'strip');

        if (array_key_exists('strip', $defaults) && ! $saved->has($stripKey)) {
            $legacy = (string) ($saved[self::key($type, 'paper')] ?? '');

            if (in_array($legacy, self::strips(), true)) {
                $saved[$stripKey] = $legacy;
            }
        }

        $out = [];

        foreach ($defaults as $field => $default) {
            $raw = $override !== null && array_key_exists($field, $override)
                ? $override[$field]
                : $saved[self::key($type, $field)] ?? null;

            $out[$field] = self::cast($field, $raw, $default);
        }

        return $out;
    }

    /**
     * قيمةٌ محفوظةً كما تُقرأ — والعلمُ منطقيٌّ لا نصّ.
     *
     * ‏«0» نصًّا صادقةٌ في PHP، فعلمٌ مُطفأ يُقرأ مُشغَّلًا ويُطبع ما أخفاه
     * صاحبُه.
     */
    private static function cast(string $field, mixed $raw, mixed $default): mixed
    {
        if (str_starts_with($field, 'show_')) {
            if (is_bool($raw)) {
                return $raw;
            }

            return $raw === null ? (bool) $default : $raw === '1' || $raw === 1 || $raw === true;
        }

        $value = $raw === null ? $default : (string) $raw;

        // النصّ الفارغ قصدٌ لا غياب: من محا تذييله لا يُعاد إليه الافتراضيّ
        if ($field === 'font' && ! in_array($value, self::FONTS, true)) {
            return $default;
        }

        if ($field === 'paper' && ! in_array($value, self::papers(), true)) {
            return $default;
        }

        if ($field === 'strip' && ! in_array($value, self::strips(), true)) {
            return $default;
        }

        return $value;
    }

    /**
     * قواعدُ المصادقة لنوعٍ — مشتقّةٌ من حقوله لا مكتوبةٌ بجانبها.
     *
     * @return array<string, mixed>
     */
    public static function rules(string $type): array
    {
        $rules = [];

        foreach (self::defaults($type) as $field => $default) {
            $rules[$field] = match (true) {
                str_starts_with($field, 'show_') => ['sometimes', 'boolean'],
                $field === 'font' => ['sometimes', 'in:'.implode(',', self::FONTS)],
                $field === 'paper' => ['sometimes', 'in:'.implode(',', self::papers())],
                $field === 'strip' => ['sometimes', 'in:'.implode(',', self::strips())],
                $field === 'header' => ['sometimes', 'nullable', 'string', 'max:120'],
                default => ['sometimes', 'nullable', 'string', 'max:500'],
            };
        }

        return $rules;
    }

    /** حفظُ ما صُودق عليه — بمفاتيح السجلّ لا بأسماء الحقول */
    public static function save(int $businessId, string $type, array $data): void
    {
        foreach ($data as $field => $value) {
            if (! array_key_exists($field, self::defaults($type))) {
                continue;
            }

            Setting::updateOrCreate(
                ['business_id' => $businessId, 'key' => self::key($type, $field)],
                ['value' => str_starts_with($field, 'show_') ? ($value ? '1' : '0') : (string) ($value ?? '')],
            );
        }
    }

    /**
     * بطاقاتُ الشاشة — كلُّ نوعٍ باسمه وحقوله وقيمه الحالية.
     *
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        $out = [];

        foreach (self::TYPES as $key => $spec) {
            $out[] = [
                'key' => $key,
                'label' => __($spec['label']),
                'desc' => __($spec['desc']),
                'section' => __($spec['section']),
            ];
        }

        return $out;
    }

    /**
     * وصفُ نوعٍ كاملًا للمحرّر: حقولُه بأسمائها، وقيمُه، وما يقبله.
     *
     * @return array<string, mixed>
     */
    public static function describe(int $businessId, string $type): array
    {
        $spec = self::TYPES[$type];

        $fields = [];
        foreach (array_keys($spec['fields']) as $field) {
            $fields[] = [
                'key' => $field,
                'label' => __(self::FIELDS[$field]['label']),
                'hint' => isset(self::FIELDS[$field]['hint']) ? __(self::FIELDS[$field]['hint']) : null,
            ];
        }

        return [
            'key' => $type,
            'label' => __($spec['label']),
            'desc' => __($spec['desc']),
            'section' => __($spec['section']),
            'hasPaper' => ($spec['paper'] ?? false) === true,
            'hasStrip' => ($spec['strip'] ?? false) === true,
            'fields' => $fields,
            'fonts' => self::FONTS,
            'papers' => self::papers(),
            'strips' => self::strips(),
            'values' => self::settings($businessId, $type),
        ];
    }
}
