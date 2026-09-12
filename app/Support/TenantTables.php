<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * ما يملكه المتجر من جداول — قائمةٌ واحدة تقرأ منها النسخةُ والاستعادة.
 *
 * كانت النسخةُ الاحتياطية تحفظ **سبعةَ عشرَ** جدولًا، وفي القاعدة ستّةٌ
 * وخمسون جدولًا للمتجر وأحدَ عشرَ جدولَ أبناءٍ تحتها. والاستعادةُ تحذف
 * الآباء ثمّ تعيد ما في الملفّ — فكلُّ ما لم يُنسخ يسقط بالتتالي ولا يعود:
 *
 *   `branch_stocks` لا يُنسخ ولا يُحذف — فيبقى بأرقامٍ قديمة و`products.quantity`
 *   يأتي من الملفّ. **تباعدٌ مضمونٌ في كلّ صنف**، لا يظهر إلا في الجرد.
 *
 *   `pos_devices` يسقط بالتتالي من `branches` — **فكلُّ صندوقٍ يتعطّل ولا يعود**.
 *
 *   `supplier_invoices` مرتبطٌ بمورّده بـ`restrict` — **فالاستعادة تنهار في
 *   منتصفها** لكلّ تاجرٍ سجّل سندَ مورّد، بعد أن حذفت منتجاته.
 *
 * والرسالةُ تقول «تمّت الاستعادة بنجاح» في الحالات الثلاث.
 *
 * فالقائمةُ هنا واحدة، و**حارسٌ يسقط يوم يُضاف جدولٌ لا يُصنَّف** — لا يمرّ
 * جدولٌ جديد صامتًا كما مرّ ثمانيةٌ وثلاثون.
 */
class TenantTables
{
    /**
     * جداولُ المتجر مرتَّبةً: الأبُ قبل ابنه.
     *
     * الترتيبُ ليس ذوقًا: الإدراجُ يمشي به، والحذفُ يمشي بعكسه. وسطرٌ يُدرَج
     * قبل أبيه يُردّ بمفتاحٍ خارجيّ فتسقط الاستعادة كلُّها.
     *
     * وصحّتُه محروسةٌ بالمفاتيح الخارجية نفسها لا بالعين — انظر
     * `ARestoreReturnsTheShopTest`.
     */
    public const ORDER = [
        // أصولٌ لا أبَ لها
        'accounts', 'branches', 'users', 'categories', 'currencies', 'suppliers',
        'expense_types', 'job_titles', 'coupons', 'settings',
        'import_batches', 'point_transactions', 'activity_logs', 'branch_stocks',
        'whatsapp_connections', 'whatsapp_template_mappings', 'whatsapp_usage_periods',

        // ما يتفرّع عنها
        'branch_user', 'branch_google_places', 'google_business_accounts', 'google_business_reviews',
        'custom_alerts', 'products', 'product_variants', 'product_images',
        'addons', 'product_addons', 'recipe_items',
        'customers', 'customer_addresses',
        'pos_devices', 'pos_peripherals',
        'bank_accounts', 'bank_statement_lines',
        'journal_entries', 'journal_lines',
        'transactions', 'expenses',
        'fixed_assets', 'inventory_movements', 'stock_adjustments', 'stock_transfers',
        'purchase_orders', 'purchase_order_items', 'supplier_invoices',
        'goods_receipt_notes', 'goods_receipt_note_items',
        'orders', 'order_items', 'order_item_addons', 'order_edits',
        /*
         * فواتيرُ العملاء وذممُهم — بعد الطلبات لأنّها قد تُولد منها.
         *
         * والتخصيصُ بعد الدفعة والفاتورة معًا: صفٌّ يشير إلى ما لم يُكتب
         * بعدُ يُردّه المفتاحُ الأجنبيّ، فتسقط الاستعادةُ في منتصفها.
         */
        'customer_invoices', 'customer_invoice_items', 'customer_invoice_orders',
        /*
         * ومرفقاتُ الفاتورة صفوفُها لا ملفّاتُها.
         *
         * النسخُ يحمل القاعدة، والملفّاتُ على القرص خارجَه — كما هي حالُ
         * مرفقات المشتريات والمصروفات منذ اليوم الأوّل. فالصفُّ يُستعاد
         * ويشير إلى ملفٍّ قد لا يعود، وهو أصدقُ من ألّا يُستعاد فيختفي أنّ
         * ثمّة مستندًا كان.
         */
        'customer_invoice_attachments',
        'customer_payments', 'customer_payment_allocations',
        'customer_credit_notes',
        'delivery_notes', 'delivery_note_items',
        'reviews',
        'shifts', 'shift_movements',
        'payroll_runs', 'payroll_lines',

        /*
         * والموقعُ آخرًا: بينه وبين نسخِه حلقةٌ مفتاحيّة.
         *
         * `websites.published_version_id` يشير إلى نسخةٍ، و`website_versions.website_id`
         * يشير إلى الموقع. فلا يسبق أحدُهما الآخر — انظر `DEFERRED`.
         */
        'websites', 'website_versions', 'website_pages', 'website_sections', 'website_domains',
        'whatsapp_messages',
    ];

    /**
     * أعمدةٌ تُترك فارغةً عند الإدراج ثمّ تُكتب في جولةٍ ثانية.
     *
     * حلقةُ المفتاحين لا تُحلّ بترتيب: الموقعُ يشير إلى نسخته والنسخةُ إلى
     * موقعها. فيُدرَج الموقعُ بلا مؤشّرٍ، ثمّ نسخُه، ثمّ يُعاد المؤشّر.
     */
    public const DEFERRED = [
        'websites' => ['published_version_id'],
    ];

    /**
     * جداولُ الأبناء: لا `business_id` فيها، وتُقرأ من خلال أبيها.
     *
     * وبلا هذا تُنسخ سطورُ متجرٍ آخر أو لا تُنسخ سطورُ هذا المتجر أصلًا.
     */
    public const THROUGH = [
        'branch_user' => ['branches', 'branch_id'],
        /*
         * ربطُ الفرع بخرائط Google — يخصّ المتجر ويُنسخ معه.
         *
         * ومَن استعاد نسختَه ولم يعد الربطُ معها يجد فروعَه كلَّها «غير
         * مربوطة» — فيربطها من جديدٍ واحدًا واحدًا، وإيصالاتُه في ما بينهما
         * تُطبع بلا رمز.
         *
         * ولا سرَّ فيه: معرّفُ المكان عامٌّ عند Google، ولا مفتاحَ في الجدول.
         */
        'branch_google_places' => ['branches', 'branch_id'],
        /*
         * تقييماتُ ملفّ الأعمال — تخصّ الفرع، وتُنسخ معه.
         *
         * ولا سرَّ فيها: نصُّ التقييم واسمُ كاتبه كما يعرضهما Google للعامّة،
         * ولا رمزَ وصولٍ ولا بريدَ أحد.
         */
        'google_business_reviews' => ['branches', 'branch_id'],
        'customer_addresses' => ['customers', 'customer_id'],
        'delivery_note_items' => ['delivery_notes', 'delivery_note_id'],
        'goods_receipt_note_items' => ['goods_receipt_notes', 'goods_receipt_note_id'],
        'journal_lines' => ['journal_entries', 'journal_entry_id'],
        'order_item_addons' => ['order_items', 'order_item_id'],
        'order_items' => ['orders', 'order_id'],
        'customer_invoice_items' => ['customer_invoices', 'customer_invoice_id'],
        'customer_invoice_orders' => ['customer_invoices', 'customer_invoice_id'],
        'customer_payment_allocations' => ['customer_payments', 'customer_payment_id'],
        'payroll_lines' => ['payroll_runs', 'payroll_run_id'],
        'purchase_order_items' => ['purchase_orders', 'purchase_order_id'],
    ];

    /**
     * ما لا يخصّ المتجر — ولكلٍّ سببُه مكتوبًا.
     *
     * والسببُ شرطٌ لا زينة: جدولٌ يُستثنى بلا سبب يُقرأ بعد سنةٍ فلا يُعرف
     * أَخرجَ عمدًا أم نُسي.
     */
    public const NOT_MINE = [
        'subscriptions' => 'اشتراكُ المتجر في أبعاد — سجلُّ المنصّة، واستعادتُه تمديدُ اشتراكٍ بملفّ',
        'invoices' => 'فواتيرُ المنصّة على المتجر — مثلُها',
        'plans' => 'باقاتُ المنصّة — ليست ملكَ متجرٍ بعينه',
        'businesses' => 'صفُّ المتجر نفسه — يُحدَّث بحقولٍ آمنة لا يُحذف ويُدرَج',
        'password_recovery_challenges' => 'محاولاتُ استرجاعِ كلمة مرور — سرّيّةٌ ومؤقّتة، لا تُكتب في ملفٍّ يُنزَّل',
        'password_recovery_otps' => 'رموزُها — مثلُها',
        'dismissed_notifications' => 'ما أخفاه مستخدمٌ من تنبيهاته — تفضيلُ عرضٍ لا بيانات',
        'domain_requests' => 'طلبُ نطاقٍ يُقرّه المشغّل — استعادتُه تُعيد طلبًا مقبولًا بملفّ',
        /*
         * ورمزُ الرابط عمودٌ إلزاميّ لا يقبل الفراغ.
         *
         * فإمّا أن يخرج في ملفٍّ يُنزَّل — ومن ملَكه فتح المستندَ بلا حساب —
         * وإمّا أن يُنزع فيُردّ الإدراج. والرابطُ يُولَّد عند الطلب أصلًا،
         * والمعرّفاتُ تعود كما كانت فتبقى الروابطُ القديمة عاملة.
         */
        'document_links' => 'رموزُ مشاركةِ المستندات — مفاتيحُ فتحٍ بلا حساب، وتُولَّد عند الطلب',
        /*
         * ═══ محادثاتُ الدعم — ليست من نسخة التاجر ═══
         *
         * وهي بين طرفين لا طرف. وفيها `is_internal`: ملاحظاتُ فريق أبعاد عن
         * هذا التاجر بعينه — «يماطل في السداد»، «راجعوا شكواه السابقة».
         * فملفٌّ يُنزَّل على جهازه ويحمل ذلك ليس نسخةً احتياطيّة بل تسريب.
         *
         * والاستعادةُ أسوأ: صفوفٌ من ملفٍّ يرفعه التاجر تدخل صندوقَ وارد
         * المنصّة بحالاتٍ ومسؤولين وأرقامِ محادثاتٍ يختارها هو.
         *
         * وما يخصّه منها — ما كتبه وما رُدَّ عليه — يبقى في «المساعدة
         * والدعم» يقرؤه متى شاء. لا يضيع، ولا يُسلَّم في ملفّ.
         */
        'support_conversations' => 'محادثاتُ الدعم مع أبعاد — بين طرفين، وفيها ملاحظاتُ الفريق الداخليّة',
        'support_messages' => 'رسائلُها — وفيها الملاحظةُ الداخليّة التي لا تبلغ التاجر بحال',
        'support_attachments' => 'مرفقاتُها — تتبع رسائلها',
        'support_reads' => 'ما قرأه كلُّ طرفٍ منها — حالةُ عرضٍ لا بيانات',

        /*
         * ═══ دفترُ مبيعات أبعاد — ليس من نسخة التاجر بحال ═══
         *
         * وهو دفترُ **المنصّة** عن من تبيعه: مراحلُ بيعٍ، وقيمٌ متوقّعة،
         * وأسبابُ خسارة، وملاحظاتٌ داخليّةٌ يكتبها فريقُ المبيعات — «يماطل»،
         * «اعرض عليه خصمًا إن رفض». فملفٌّ يُنزَّل على جهاز التاجر ويحمل ذلك
         * ليس نسخةً احتياطيّة بل تسريب.
         *
         * والاستعادةُ أسوأ: صفوفٌ من ملفٍّ **يرفعه التاجر** تدخل دفترَ مبيعات
         * المنصّة بمراحلَ ومسؤولين يختارهم هو — وبصفوفٍ تربط متاجرَ غيره.
         *
         * ولا عمودَ `business_id` في هذه الجداول أصلًا: `converted_business_id`
         * رابطٌ إلى متجرٍ صار مشتركًا، لا ملكيّةُ متجرٍ لصفّ.
         */
        'crm_leads' => 'دفترُ مبيعات أبعاد — عملاءُ المنصّة المحتملون، لا بيانات متجر',
        'crm_notes' => 'ملاحظاتُ فريق المبيعات الداخليّة — لا تبلغ من كُتبت عنه بحال',
        'crm_tasks' => 'مهامُّ فريق المبيعات — عملُ المنصّة لا عملُ المتجر',
        'crm_stage_events' => 'تاريخُ مراحل البيع — سجلُّ المنصّة، ومنه تُحسب تقاريرها',
        /*
         * ورسائلُ واتساب في دفتر المبيعات: خيطُ **أبعاد** مع من يريد شراءها.
         *
         * وليست رسائلَ المتجر مع زبائنه — تلك في `whatsapp_messages` وهي
         * تُنسخ مع المتجر. وهذه تخرج من رقم مبيعاتنا، وفيها ما قاله عن
         * منافسيه وعن ميزانيّته.
         */
        'crm_messages' => 'رسائلُ واتساب مع العملاء المحتملين — خيطُ المنصّة لا خيطُ متجر',
    ];

    /**
     * أعمدةٌ لا تخرج في ملفٍّ يُنزَّل على جهاز التاجر.
     *
     * والرموزُ الخام وحدها: `pos_devices.token_hash` بصمةٌ لا مفتاح، وحذفُها
     * يُعطّل كلَّ صندوقٍ بعد الاستعادة — وهو العطبُ الذي نُصلحه لا نُكرّره.
     */
    public const SECRETS = [
        'users' => ['password', 'remember_token'],
        'whatsapp_connections' => ['access_token'],
        /*
         * ورمزا Google لا يخرجان في ملفٍّ يُنزَّل.
         *
         * من يملك رمزَ التجديد يقرأ تقييمات المتجر **ويردّ عليها باسمه** حتّى
         * يُلغيه صاحبُه بيده. وملفُّ نسخةٍ يُرسَل في بريدٍ أو يُنسى في مجلّد
         * تنزيلاتٍ ليس مكانَه.
         *
         * وليسا إلزاميَّين فلا يُولَّدان: المتجرُ يعود بعد الاستعادة «غيرَ
         * مربوط»، فيربط بضغطتين — وذلك أهونُ من إذنٍ يُنسخ مع الملفّ.
         */
        'google_business_accounts' => ['access_token', 'refresh_token'],
    ];

    /**
     * أعمدةٌ نُزعت وهي إلزاميّة — فتُولَّد عند الاستعادة.
     *
     * وعمودٌ إلزاميٌّ يُنزع ولا يُولَّد يُسقط الاستعادة كلَّها بـ`NOT NULL`،
     * فتصير الحمايةُ عطبًا. والحارسُ يسأل عن هذه القائمة بالضبط.
     *
     * و`users.password` تُولَّد عشوائيّةً في `restoreUsers`: الحسابُ القائم
     * يبقى بكلمته، والجديدُ يُلزَم بإعادة التعيين.
     */
    public const REGENERATED = [
        'users' => ['password'],
    ];

    /** كلُّ ما يُنسخ — بالترتيب */
    public static function all(): array
    {
        return self::ORDER;
    }

    /** الاستعلامُ الذي يقرأ سطورَ هذا المتجر من هذا الجدول */
    public static function scope(string $table, int $businessId): Builder
    {
        $q = DB::table($table);

        if (! isset(self::THROUGH[$table])) {
            return $q->where('business_id', $businessId);
        }

        [$parent, $key] = self::THROUGH[$table];

        return $q->whereIn($key, self::scope($parent, $businessId)->select('id'));
    }
}
