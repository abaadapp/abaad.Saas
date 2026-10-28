<?php

namespace App\Support;

/**
 * ما يفعله الموظّف داخل القسم — لا ما يفتحه منه.
 *
 * ═══ ما الذي تغيّر ═══
 *
 * كان القسمُ مفتاحًا واحدًا: من فُتح له «العملاء» يقرأ العملاء ويضيفهم
 * ويعدّلهم ويحذفهم ويصدّرهم. فلا يستطيع صاحبُ النشاط أن يقول «يرى ولا يحذف».
 *
 * فصار القسمُ «عرضًا»: يفتح شاشاته ويقرأ ما فيها. وكلُّ ما يكتب أو يحذف أو
 * يُخرج البيانات صار فعلًا باسمه هنا — ويحرسه `CheckAbility` من `ROUTES`
 * على الخادم، لا الزرُّ في الشاشة.
 *
 * ═══ ولماذا لا يخسر أحدٌ شيئًا ═══
 *
 * كلُّ فعلٍ هنا كان يُملك أمسِ بقسمٍ واحد بعينه — `SPLIT` تقول أيّها. ومنه:
 *
 *   - من يتبع دورَه: `ROLES` لكلّ فعلٍ هي الأدوارُ التي تملك قسمَه في
 *     `Permissions::MAP` بالضبط. فالبائعُ الذي كان يضيف عميلًا يضيفه.
 *   - ومن خُصّصت قائمتُه: هجرةُ «كلّ قسمٍ يسمّي ما يُفعل فيه» تضيف إلى
 *     قائمته أفعالَ كلِّ قسمٍ فيها — ما كان يفعله، لا أكثر.
 *
 * والحارسُ (`EveryOperationIsGrantedByItsOwnNameTest`) يتحقّق من الاثنين:
 * أنّ كلّ مسارٍ في `ROUTES` يقع في قسم فعله نفسِه، وأنّ أدوار كلّ فعلٍ هي
 * أدوارُ قسمه. فلا يصير فعلٌ أضيقَ من القسم الذي انفصل عنه.
 *
 * ═══ وما لا يُفصل هنا ═══
 *
 *   - عملُ القسم نفسُه: البيعُ في نقطة البيع، ونقلُ الطلب في لوحة التجهيز.
 *     فصلُهما يوقف الصندوق ولا يحمي شيئًا.
 *   - المعايناتُ والتفضيلات: حسابُ الإجمالي قبل الحفظ، والعميلُ الافتراضيّ.
 *   - الطباعة: الشاشةُ تعرض المستند نفسَه، ومنعُ ورقته لا يمنع شيئًا ويكسر
 *     إيصال الصندوق.
 *   - وما يحرسه فعلٌ سابق أو «صاحب النشاط وحده»: لا يُضاف عليه شرطٌ ثانٍ.
 *
 * والمفتاحُ يحمل نقطةً دائمًا — `Permissions::isAction` تفصل بها الفعلَ عن
 * القسم، فلا يلتبس `customer.create` بقسم «customers».
 */
final class SectionActions
{
    /**
     * الفعلُ → القسمُ الذي كان يبيحه قبل أن ينفصل.
     *
     * يُضاف إليها ولا يُغيَّر ما فيها: الهجرةُ قرأتها يوم نُشرت، وفعلٌ يُضاف
     * غدًا يحتاج هجرةً تمنحه وحدَه.
     */
    public const SPLIT = [
        'goal.manage' => 'dashboard',

        'customer.create' => 'customers',
        'customer.edit' => 'customers',
        'customer.delete' => 'customers',
        'customer.points' => 'customers',
        'customer.export' => 'customers',

        'order.details' => 'orders',
        'order.message' => 'orders',
        'order.export' => 'orders',

        'product.create' => 'products',
        'product.edit' => 'products',
        'product.delete' => 'products',
        'category.manage' => 'products',
        'addon.manage' => 'products',
        'boutique.manage' => 'products',
        'boutique.settle' => 'products',
        'product.export' => 'products',

        'stock.adjust' => 'inventory',
        'stock.count' => 'inventory',
        'stock.export' => 'inventory',

        'purchase.create' => 'purchases',
        'purchase.delete' => 'purchases',

        'supplier.create' => 'suppliers',
        'supplier.edit' => 'suppliers',
        'supplier.delete' => 'suppliers',
        'supplier.export' => 'suppliers',

        'journal.create' => 'finance',
        'journal.reverse' => 'finance',
        'chart.manage' => 'finance',
        'bank.manage' => 'finance',
        'bank.reconcile' => 'finance',
        'asset.manage' => 'finance',
        'paymob.settle' => 'finance',
        'finance.export' => 'finance',

        'expense.create' => 'expenses',
        'expense.edit' => 'expenses',
        'expense.pay' => 'expenses',
        'expense.delete' => 'expenses',
        'expenseType.manage' => 'expenses',
        'expense.export' => 'expenses',

        'employee.create' => 'employees',
        'employee.edit' => 'employees',
        'jobTitle.manage' => 'employees',
        'payroll.create' => 'employees',

        'report.export' => 'reports',

        'coupon.manage' => 'marketing',
        'loyalty.manage' => 'marketing',
        'review.manage' => 'marketing',
        'marketing.edit' => 'marketing',

        'integration.manage' => 'integrations',
        'payment.configure' => 'integrations',

        'settings.edit' => 'settings',
        'books.close' => 'settings',
        'branch.manage' => 'settings',
        'device.manage' => 'settings',
        'backup.manage' => 'settings',
        'subscription.manage' => 'settings',
    ];

    /** أسماءُ الأفعال كما تُعرض للتاجر */
    public const LABELS = [
        'goal.manage' => 'تعديل أهداف المبيعات',

        'customer.create' => 'إضافة عميل واستيراد العملاء',
        'customer.edit' => 'تعديل بيانات العميل وعناوينه وملاحظاته',
        'customer.delete' => 'حذف عميل واستعادته',
        'customer.points' => 'صرف نقاط ولاء العميل يدويًا',
        'customer.export' => 'تصدير العملاء',

        'order.details' => 'تعديل بيانات التسليم والمستلم في الطلب',
        'order.message' => 'مراسلة العميل من الطلب والفاتورة',
        'order.export' => 'تصدير المبيعات',

        'product.create' => 'إضافة منتج ونسخه واستيراد المنتجات',
        'product.edit' => 'تعديل المنتجات وصورها ومقاساتها ووصفاتها ومواسمها',
        'product.delete' => 'حذف منتج واستعادته',
        'category.manage' => 'إدارة التصنيفات',
        'addon.manage' => 'إدارة الإضافات وربطها بالمنتجات',
        'boutique.manage' => 'إدارة البوتيكات ومكوّناتها',
        'boutique.settle' => 'سداد مستحقات البوتيك وتسوية مرتجعاته',
        'product.export' => 'تصدير المنتجات',

        'stock.adjust' => 'تسوية المخزون وإدخال حركاته يدويًا',
        'stock.count' => 'اعتماد الجرد وتطبيق فروقاته',
        'stock.export' => 'تصدير المخزون',

        'purchase.create' => 'إنشاء أمر شراء وإرفاق إيصاله',
        'purchase.delete' => 'حذف أمر شراء',

        'supplier.create' => 'إضافة مورّد واستيراد الموردين',
        'supplier.edit' => 'تعديل بيانات المورّد',
        'supplier.delete' => 'حذف مورّد',
        'supplier.export' => 'تصدير الموردين',

        'journal.create' => 'إضافة قيد يدوي أو حركة صندوق وبنك',
        'journal.reverse' => 'عكس قيد يدوي مرحّل',
        'chart.manage' => 'إدارة شجرة الحسابات',
        'bank.manage' => 'إدارة الحسابات البنكية',
        'bank.reconcile' => 'استيراد كشف البنك ومطابقته',
        'asset.manage' => 'إدارة الأصول الثابتة وإهلاكها واستبعادها',
        'paymob.settle' => 'تسويات Paymob: إنشاؤها وترحيلها وإلغاؤها',
        'finance.export' => 'تصدير الحركات المالية',

        'expense.create' => 'إضافة مصروف',
        'expense.edit' => 'تعديل نطاق المصروف',
        'expense.pay' => 'تسجيل سداد مصروف',
        'expense.delete' => 'حذف مصروف واستعادته',
        'expenseType.manage' => 'إدارة أنواع المصروفات',
        'expense.export' => 'تصدير المصروفات',

        'employee.create' => 'إضافة موظف',
        'employee.edit' => 'تعديل موظف وصلاحياته وإيقافه وإعادة كلمة مروره',
        'jobTitle.manage' => 'إدارة الوظائف',
        'payroll.create' => 'إعداد مسيرة الرواتب وتعديل بنودها',

        'report.export' => 'تصدير التقارير',

        'coupon.manage' => 'إدارة الكوبونات',
        'loyalty.manage' => 'ضبط برنامج الولاء',
        'review.manage' => 'إدارة التقييمات والرد عليها',
        'marketing.edit' => 'ضبط أدوات التسويق: SEO وواتساب وصور المتجر',

        'integration.manage' => 'ربط التطبيقات التكاملية وضبطها',
        'payment.configure' => 'ضبط وسائل الدفع (Paymob)',

        'settings.edit' => 'تعديل إعدادات النشاط وقوالب الأوراق والتنبيهات',
        'books.close' => 'إقفال الفترات المحاسبية وتاريخ آخر إقرار',
        'branch.manage' => 'إدارة الفروع',
        'device.manage' => 'إدارة الأجهزة والطابعات',
        'backup.manage' => 'إنشاء النسخ الاحتياطية وضبط تكرارها',
        'subscription.manage' => 'طلب إضافات الاشتراك وتجديدها',
    ];

    /** ما لا يكفيه الاسم — سطرٌ تحته في شاشة الصلاحيات */
    public const HINTS = [
        'customer.delete' => 'الحذف يخفي العميل ويُستعاد. والمحو النهائي يحتاج معه قسم «الإعدادات».',
        'customer.points' => 'خصم نقاط من رصيد العميل خارج البيع. والنقاط في البيع نفسه تتبع نقطة البيع.',
        'order.message' => 'إرسال الطلب أو الفاتورة أو إشعار الحالة أو طلب التقييم أو تذكير السداد إلى العميل.',
        'boutique.settle' => 'دفع مستحقات البوتيك عن مكوّنات الباقات، وتسوية مرتجعاته وردّ مبالغه.',
        'stock.adjust' => 'زيادة الكمية أو إنقاصها خارج البيع والشراء بسببٍ يُكتب: تلف أو فقد أو تصحيح عدّ.',
        'stock.count' => 'تطبيق نتيجة الجرد على الرفوف: تُسجَّل الفروقات حركاتٍ في المخزون.',
        'journal.create' => 'قيدٌ يدويّ في الدفتر، أو حركة قبضٍ وصرفٍ في الصندوق والبنك.',
        'journal.reverse' => 'عكس قيدٍ يدويّ رُحّل: يُكتب قيدٌ معاكس بتاريخه، ولا يُحذف القيد الأصلي. قيودُ المستندات تُلغى من مستندها.',
        'paymob.settle' => 'ربط دفعات Paymob بما وصل البنك فعلًا وترحيلها، أو إلغاء تسويةٍ مرحّلة.',
        'employee.edit' => 'ولا يمنح أحدٌ غيرَه ما لا يملكه هو، ولا يعدّل صلاحيات حسابه.',
        'payment.configure' => 'مفاتيح Paymob وأرقام التكامل. لا يسجّل تحصيلًا ولا يرى المدفوعات.',
        'books.close' => 'تقديم تاريخ «الدفاتر مقفلة حتى» أو «آخر إقرار قُدِّم»: لا يُكتب بعده قيدٌ في الفترة المقفلة. ويُنفَّذ من الإعدادات، فيلزمه معها تعديل الإعدادات.',
        'backup.manage' => 'أخذ نسخة وضبط تكرارها. والاستعادة لصاحب النشاط وحده، والتنزيل بصلاحية «تنزيل نسخة احتياطية».',
    ];

    /**
     * من يفعلها بدوره — أدوارُ قسمها في `Permissions::MAP` بالضبط.
     *
     * تُكتب بالأسماء لا تُحسب: هكذا تُقرأ الصلاحيةُ في مكانٍ واحد كما تُقرأ
     * `ACTION_ROLES`. والحارسُ يقارنها بـMAP، فلا تفترق عنه.
     */
    private const DASHBOARD = ['admin', 'manager', 'accountant', 'inventory', 'sales', 'cashier', 'delivery'];

    private const CUSTOMERS = ['admin', 'manager', 'accountant', 'sales'];

    private const ORDERS = ['admin', 'manager', 'accountant', 'sales', 'delivery'];

    private const PRODUCTS = ['admin', 'manager', 'inventory', 'sales'];

    private const INVENTORY = ['admin', 'manager', 'inventory'];

    private const STOCK_BOOKS = ['admin', 'manager', 'accountant', 'inventory'];

    private const MONEY = ['admin', 'manager', 'accountant'];

    private const OWNERS = ['admin', 'manager'];

    public const ROLES = [
        'goal.manage' => self::DASHBOARD,

        'customer.create' => self::CUSTOMERS,
        'customer.edit' => self::CUSTOMERS,
        'customer.delete' => self::CUSTOMERS,
        'customer.points' => self::CUSTOMERS,
        'customer.export' => self::CUSTOMERS,

        'order.details' => self::ORDERS,
        'order.message' => self::ORDERS,
        'order.export' => self::ORDERS,

        'product.create' => self::PRODUCTS,
        'product.edit' => self::PRODUCTS,
        'product.delete' => self::PRODUCTS,
        'category.manage' => self::PRODUCTS,
        'addon.manage' => self::PRODUCTS,
        'boutique.manage' => self::PRODUCTS,
        'boutique.settle' => self::PRODUCTS,
        'product.export' => self::PRODUCTS,

        'stock.adjust' => self::INVENTORY,
        'stock.count' => self::INVENTORY,
        'stock.export' => self::INVENTORY,

        // المشتريات والموردون: يقرؤهما المحاسب منذ أُصلح سهوُه — انظر `Permissions::MAP`
        'purchase.create' => self::STOCK_BOOKS,
        'purchase.delete' => self::STOCK_BOOKS,

        'supplier.create' => self::STOCK_BOOKS,
        'supplier.edit' => self::STOCK_BOOKS,
        'supplier.delete' => self::STOCK_BOOKS,
        'supplier.export' => self::STOCK_BOOKS,

        'journal.create' => self::MONEY,
        'journal.reverse' => self::MONEY,
        'chart.manage' => self::MONEY,
        'bank.manage' => self::MONEY,
        'bank.reconcile' => self::MONEY,
        'asset.manage' => self::MONEY,
        'paymob.settle' => self::MONEY,
        'finance.export' => self::MONEY,

        'expense.create' => self::MONEY,
        'expense.edit' => self::MONEY,
        'expense.pay' => self::MONEY,
        'expense.delete' => self::MONEY,
        'expenseType.manage' => self::MONEY,
        'expense.export' => self::MONEY,

        'employee.create' => self::MONEY,
        'employee.edit' => self::MONEY,
        'jobTitle.manage' => self::MONEY,
        'payroll.create' => self::MONEY,

        'report.export' => self::MONEY,

        'coupon.manage' => self::OWNERS,
        'loyalty.manage' => self::OWNERS,
        'review.manage' => self::OWNERS,
        'marketing.edit' => self::OWNERS,

        'integration.manage' => self::OWNERS,
        'payment.configure' => self::OWNERS,

        'settings.edit' => self::OWNERS,
        'books.close' => self::OWNERS,
        'branch.manage' => self::OWNERS,
        'device.manage' => self::OWNERS,
        'backup.manage' => self::OWNERS,
        'subscription.manage' => self::OWNERS,
    ];

    /**
     * المسارُ → الفعلُ الذي يحرسه فوق قسمه.
     *
     * يقرؤها `CheckAbility` بعد أن يسأل عن القسم: من لا يملك القسم لا يصل،
     * ومن يملكه ولا يملك الفعل يُردّ بـ٤٠٣. وشاشةُ النموذج (create/edit)
     * تُحرس بفعل حفظها: من لا يحفظ لا يُفتح له نموذجٌ لا يُرسَل.
     */
    public const ROUTES = [
        'admin.goals.update' => 'goal.manage',

        'admin.customers.store' => 'customer.create',
        'admin.customers.import.upload' => 'customer.create',
        'admin.customers.import.preview' => 'customer.create',
        'admin.customers.import.confirm' => 'customer.create',
        'admin.customers.import.cancel' => 'customer.create',
        'admin.customers.update' => 'customer.edit',
        'admin.customers.addresses.save' => 'customer.edit',
        'admin.customers.addresses.default' => 'customer.edit',
        'admin.customers.addresses.delete' => 'customer.edit',
        'admin.customers.note' => 'customer.edit',
        'admin.customers.language' => 'customer.edit',
        'admin.customers.destroy' => 'customer.delete',
        'admin.customers.restore' => 'customer.delete',
        'admin.customers.purge' => 'customer.delete',
        'admin.customers.redeem' => 'customer.points',
        'admin.export.customers' => 'customer.export',
        'admin.customers.export.pdf' => 'customer.export',
        'admin.customers.export.xlsx' => 'customer.export',
        'admin.customers.xlsx' => 'customer.export',

        'admin.orders.details.update' => 'order.details',
        'admin.orders.send' => 'order.message',
        'admin.orders.statusNotice' => 'order.message',
        'admin.orders.reviewInvite' => 'order.message',
        'admin.orders.reviewRequest' => 'order.message',
        'admin.orders.contactRecipient' => 'order.message',
        'admin.customerInvoices.remind' => 'order.message',
        'admin.export.orders' => 'order.export',
        'admin.orders.exportPdf' => 'order.export',
        'admin.orders.xlsx' => 'order.export',
        'admin.customerInvoices.sales.csv' => 'order.export',
        'admin.customerInvoices.sales.xlsx' => 'order.export',

        'admin.products.create' => 'product.create',
        'admin.products.store' => 'product.create',
        'admin.products.duplicate' => 'product.create',
        'admin.products.import.upload' => 'product.create',
        'admin.products.import.preview' => 'product.create',
        'admin.products.import.remap' => 'product.create',
        'admin.products.import.confirm' => 'product.create',
        'admin.products.import.cancel' => 'product.create',
        'admin.products.import.undo' => 'product.create',
        'admin.products.edit' => 'product.edit',
        'admin.products.update' => 'product.edit',
        'admin.products.quick' => 'product.edit',
        'admin.products.toggle' => 'product.edit',
        'admin.products.bulk' => 'product.edit',
        'admin.products.images.store' => 'product.edit',
        'admin.products.images.promote' => 'product.edit',
        'admin.products.images.order' => 'product.edit',
        'admin.products.images.destroy' => 'product.edit',
        'admin.products.images.destroyMain' => 'product.edit',
        'admin.products.variants.store' => 'product.edit',
        'admin.products.variants.update' => 'product.edit',
        'admin.products.variants.destroy' => 'product.edit',
        'admin.products.recipe.store' => 'product.edit',
        'admin.products.recipe.update' => 'product.edit',
        'admin.products.recipe.destroy' => 'product.edit',
        'admin.seasons.store' => 'product.edit',
        'admin.seasons.update' => 'product.edit',
        'admin.seasons.cycle' => 'product.edit',
        'admin.seasons.attach' => 'product.edit',
        'admin.seasons.detach' => 'product.edit',
        'admin.seasons.destroy' => 'product.edit',
        'admin.seasons.reminders.store' => 'product.edit',
        'admin.seasons.reminders.update' => 'product.edit',
        'admin.seasons.reminders.read' => 'product.edit',
        'admin.seasons.reminders.destroy' => 'product.edit',
        'admin.products.destroy' => 'product.delete',
        'admin.products.restore' => 'product.delete',
        'admin.products.purge' => 'product.delete',
        'admin.products.categories.store' => 'category.manage',
        'admin.products.categories.update' => 'category.manage',
        'admin.products.categories.card' => 'category.manage',
        'admin.products.categories.cardImage' => 'category.manage',
        'admin.products.categories.cardImage.clear' => 'category.manage',
        'admin.products.categories.order' => 'category.manage',
        'admin.products.categories.destroy' => 'category.manage',
        'admin.products.addons.store' => 'addon.manage',
        'admin.products.addons.update' => 'addon.manage',
        'admin.products.addons.link' => 'addon.manage',
        'admin.products.addons.unlink' => 'addon.manage',
        'admin.products.addons.sync' => 'addon.manage',
        'admin.products.addons.destroy' => 'addon.manage',
        'admin.addons.active' => 'addon.manage',
        'admin.boutiques.store' => 'boutique.manage',
        'admin.boutiques.update' => 'boutique.manage',
        'admin.boutiques.attach' => 'boutique.manage',
        'admin.boutiques.destroy' => 'boutique.manage',
        'admin.products.boutique-parts.store' => 'boutique.manage',
        'admin.products.boutique-parts.destroy' => 'boutique.manage',
        'admin.boutiques.components.pay' => 'boutique.settle',
        'admin.boutiques.refund' => 'boutique.settle',
        'admin.boutiques.settle' => 'boutique.settle',
        'admin.boutiques.returns.offset' => 'boutique.settle',
        'admin.boutiques.returns.receipt' => 'boutique.settle',
        'admin.boutiques.returns.review' => 'boutique.settle',
        'admin.export.products' => 'product.export',
        'admin.products.export.pdf' => 'product.export',
        'admin.products.export.xlsx' => 'product.export',
        'admin.products.exportPdf' => 'product.export',
        'admin.products.xlsx' => 'product.export',

        'admin.inventory.store' => 'stock.adjust',
        'admin.inventory.adjustments.store' => 'stock.adjust',
        'admin.inventory.stocktake.apply' => 'stock.count',
        'admin.export.inventory' => 'stock.export',
        'admin.inventory.exportPdf' => 'stock.export',
        'admin.inventory.xlsx' => 'stock.export',

        'admin.purchases.create' => 'purchase.create',
        'admin.purchases.store' => 'purchase.create',
        'admin.purchases.receipt' => 'purchase.create',
        'admin.purchases.destroy' => 'purchase.delete',

        'admin.suppliers.store' => 'supplier.create',
        'admin.suppliers.import.upload' => 'supplier.create',
        'admin.suppliers.import.preview' => 'supplier.create',
        'admin.suppliers.import.confirm' => 'supplier.create',
        'admin.suppliers.import.cancel' => 'supplier.create',
        'admin.suppliers.update' => 'supplier.edit',
        'admin.suppliers.destroy' => 'supplier.delete',
        'admin.export.suppliers' => 'supplier.export',
        'admin.suppliers.export.pdf' => 'supplier.export',
        'admin.suppliers.export.xlsx' => 'supplier.export',
        'admin.suppliers.xlsx' => 'supplier.export',

        'admin.finance.store' => 'journal.create',
        'admin.finance.journal.store' => 'journal.create',
        'admin.finance.journal.reverse' => 'journal.reverse',
        'admin.finance.chart.store' => 'chart.manage',
        'admin.finance.chart.update' => 'chart.manage',
        'admin.finance.chart.toggle' => 'chart.manage',
        'admin.finance.chart.destroy' => 'chart.manage',
        'admin.finance.banks.store' => 'bank.manage',
        'admin.finance.banks.update' => 'bank.manage',
        'admin.finance.banks.primary' => 'bank.manage',
        'admin.finance.banks.destroy' => 'bank.manage',
        'admin.bank.import' => 'bank.reconcile',
        'admin.bank.rematch' => 'bank.reconcile',
        'admin.bank.clear' => 'bank.reconcile',
        'admin.finance.assets.store' => 'asset.manage',
        'admin.finance.assets.depreciate' => 'asset.manage',
        'admin.finance.assets.dispose' => 'asset.manage',
        'admin.finance.assets.destroy' => 'asset.manage',
        'admin.finance.assets.categories.destroy' => 'asset.manage',
        'admin.finance.paymobSettlements.store' => 'paymob.settle',
        'admin.finance.paymobSettlements.attribute' => 'paymob.settle',
        'admin.finance.paymobSettlements.cancel' => 'paymob.settle',
        'admin.finance.paymobSettlements.retry' => 'paymob.settle',
        'admin.finance.paymobSettlements.drafts.store' => 'paymob.settle',
        'admin.finance.paymobSettlements.items.store' => 'paymob.settle',
        'admin.finance.paymobSettlements.items.remove' => 'paymob.settle',
        'admin.finance.paymobSettlements.waiting' => 'paymob.settle',
        'admin.finance.paymobSettlements.dropUnsure' => 'paymob.settle',
        'admin.finance.paymobSettlements.post' => 'paymob.settle',
        'admin.finance.paymobSettlementItems.remove' => 'paymob.settle',
        'admin.export.transactions' => 'finance.export',
        'admin.finance.xlsx' => 'finance.export',
        'admin.finance.pdf' => 'finance.export',

        'admin.expenses.store' => 'expense.create',
        'admin.expenses.scope' => 'expense.edit',
        'admin.expenses.paid' => 'expense.pay',
        'admin.expenses.destroy' => 'expense.delete',
        'admin.expenses.restore' => 'expense.delete',
        'admin.expenses.purge' => 'expense.delete',
        'admin.expenseTypes.store' => 'expenseType.manage',
        'admin.expenseTypes.update' => 'expenseType.manage',
        'admin.expenseTypes.destroy' => 'expenseType.manage',
        'admin.export.expenses' => 'expense.export',
        'admin.expenses.exportPdf' => 'expense.export',
        'admin.expenses.xlsx' => 'expense.export',

        'admin.employees.create' => 'employee.create',
        'admin.employees.store' => 'employee.create',
        'admin.employees.edit' => 'employee.edit',
        'admin.employees.update' => 'employee.edit',
        'admin.employees.toggle' => 'employee.edit',
        'admin.employees.resetPassword' => 'employee.edit',
        'admin.jobTitles.store' => 'jobTitle.manage',
        'admin.jobTitles.update' => 'jobTitle.manage',
        'admin.jobTitles.destroy' => 'jobTitle.manage',
        'admin.payroll.store' => 'payroll.create',
        'admin.payroll.destroy' => 'payroll.create',
        'admin.payroll.lines.update' => 'payroll.create',
        'admin.payroll.lines.destroy' => 'payroll.create',

        'admin.export.reports' => 'report.export',
        'admin.reports.export.csv' => 'report.export',
        'admin.reports.export.pdf' => 'report.export',
        'admin.reports.export.xlsx' => 'report.export',
        'admin.reports.xlsx' => 'report.export',

        'admin.coupons.store' => 'coupon.manage',
        'admin.coupons.toggle' => 'coupon.manage',
        'admin.coupons.limits' => 'coupon.manage',
        'admin.coupons.destroy' => 'coupon.manage',
        'admin.marketing.loyalty.save' => 'loyalty.manage',
        'admin.marketing.reviews.store' => 'review.manage',
        'admin.marketing.reviews.reply' => 'review.manage',
        'admin.marketing.reviews.status' => 'review.manage',
        'admin.marketing.reviews.destroy' => 'review.manage',
        'admin.marketing.seo.save' => 'marketing.edit',
        'admin.marketing.seo.refresh' => 'marketing.edit',
        'admin.marketing.store.image' => 'marketing.edit',
        'admin.marketing.store.products' => 'marketing.edit',
        'admin.marketing.whatsapp.save' => 'marketing.edit',

        'admin.integrations.connect' => 'integration.manage',
        'admin.integrations.google.save' => 'integration.manage',
        'admin.integrations.google.enabled' => 'integration.manage',
        'admin.integrations.google.key' => 'integration.manage',
        'admin.integrations.google.key.test' => 'integration.manage',
        'admin.integrations.google.key.forget' => 'integration.manage',
        'admin.integrations.google.refresh' => 'integration.manage',
        'admin.integrations.google.search' => 'integration.manage',
        'admin.integrations.google.branch.link' => 'integration.manage',
        'admin.integrations.google.branch.refresh' => 'integration.manage',
        'admin.integrations.google.branch.unlink' => 'integration.manage',
        'admin.integrations.googleBusiness.connect' => 'integration.manage',
        'admin.integrations.googleBusiness.callback' => 'integration.manage',
        'admin.integrations.googleBusiness.disconnect' => 'integration.manage',
        'admin.integrations.googleBusiness.locations' => 'integration.manage',
        'admin.integrations.googleBusiness.alerts' => 'integration.manage',
        'admin.integrations.googleBusiness.reply' => 'integration.manage',
        'admin.integrations.googleBusiness.branch.link' => 'integration.manage',
        'admin.integrations.googleBusiness.branch.sync' => 'integration.manage',
        'admin.integrations.whatsapp.connect' => 'integration.manage',
        'admin.integrations.whatsapp.disconnect' => 'integration.manage',
        'admin.integrations.whatsapp.embedded.callback' => 'integration.manage',
        'admin.integrations.whatsapp.mode' => 'integration.manage',
        'admin.integrations.whatsapp.autoReply' => 'integration.manage',
        'admin.integrations.whatsapp.test' => 'integration.manage',
        'admin.integrations.whatsapp.packs.request' => 'integration.manage',
        'admin.integrations.paymob.save' => 'payment.configure',

        'admin.settings.update' => 'settings.edit',
        'admin.settings.logo' => 'settings.edit',
        'admin.settings.documents.brand' => 'settings.edit',
        'admin.settings.documents.cover' => 'settings.edit',
        'admin.settings.templates.edit' => 'settings.edit',
        'admin.settings.templates.update' => 'settings.edit',
        'admin.setup.confirm' => 'settings.edit',
        'admin.alerts.store' => 'settings.edit',
        'admin.alerts.update' => 'settings.edit',
        'admin.alerts.destroy' => 'settings.edit',
        'admin.customOrders.store' => 'settings.edit',
        'admin.customOrders.update' => 'settings.edit',
        'admin.customOrders.reorder' => 'settings.edit',
        'admin.customOrders.destroy' => 'settings.edit',
        'admin.branches.store' => 'branch.manage',
        'admin.branches.update' => 'branch.manage',
        'admin.branches.destroy' => 'branch.manage',
        'admin.branches.restore' => 'branch.manage',
        'admin.devices.update' => 'device.manage',
        'admin.devices.revoke' => 'device.manage',
        'admin.devices.destroy' => 'device.manage',
        'admin.devices.peripherals.store' => 'device.manage',
        'admin.devices.peripherals.update' => 'device.manage',
        'admin.devices.peripherals.destroy' => 'device.manage',
        'admin.backup.create' => 'backup.manage',
        'admin.backup.frequency' => 'backup.manage',
        'admin.subscription.addons.request' => 'subscription.manage',
        'admin.subscription.addons.renews' => 'subscription.manage',
    ];

    /**
     * شاشةُ الصلاحيات: كلُّ قسمٍ وتحته ما يُفعل فيه.
     *
     * `view` هو القسمُ نفسُه (يفتح الشاشات)، و`actions` بترتيب عرضها —
     * الأفعالُ القديمة والجديدة معًا. وكلُّ قسمٍ وكلُّ فعلٍ في مجموعةٍ واحدة
     * (الحارس يعدّها). و«فواتير العملاء» تُعرض وحدها وإن فتحها قسم «المبيعات».
     */
    public const GROUPS = [
        ['key' => 'dashboard', 'view' => 'dashboard', 'actions' => ['goal.manage']],
        ['key' => 'customers', 'view' => 'customers', 'actions' => [
            'customer.create', 'customer.edit', 'customer.delete', 'customer.points', 'customer.export',
            'credit.override', CustomerFlags::OVERRIDE,
        ]],
        ['key' => 'orders', 'view' => 'orders', 'actions' => [
            'order.edit', 'order.details', 'order.message', Permissions::PREPARATION_CANCEL,
            Permissions::ORDER_WEBSITE_NOTIFY, 'order.export',
        ]],
        ['key' => 'customerInvoices', 'view' => null, 'label' => 'فواتير العملاء', 'opens_with' => 'orders', 'actions' => [
            Permissions::CUSTOMER_INVOICE_ISSUE,
            Permissions::CUSTOMER_INVOICE_EDIT_DRAFT,
            Permissions::CUSTOMER_INVOICE_EDIT_ISSUED,
            Permissions::CUSTOMER_INVOICE_EDIT_PAID,
            Permissions::CUSTOMER_INVOICE_CORRECT_PERIOD,
            Permissions::FINANCIAL_CORRECTION_APPROVE,
            Permissions::CUSTOMER_INVOICE_CANCEL,
            Permissions::CUSTOMER_CREDIT_NOTE,
            Permissions::CUSTOMER_INVOICE_HISTORY,
        ]],
        ['key' => 'pos', 'view' => 'pos', 'actions' => [Permissions::POS_MOVEMENT]],
        ['key' => 'preparation', 'view' => 'preparation', 'actions' => [Permissions::CUSTOMER_INVOICE_PREPARATION_EDIT]],
        ['key' => 'products', 'view' => 'products', 'actions' => [
            'product.create', 'product.edit', 'product.delete', 'category.manage', 'addon.manage',
            'boutique.manage', 'boutique.settle', 'product.export',
        ]],
        ['key' => 'inventory', 'view' => 'inventory', 'actions' => [
            'stock.adjust', 'stock.count', Permissions::RECEIPT_VIEW,
            Permissions::TRANSFER_VIEW, Permissions::TRANSFER_VIEW_ALL, Permissions::TRANSFER_CREATE,
            Permissions::TRANSFER_APPROVE, Permissions::TRANSFER_DISPATCH, Permissions::TRANSFER_RECEIVE,
            Permissions::TRANSFER_RESOLVE, Permissions::SUPPLY_CREATE, 'stock.export',
        ]],
        ['key' => 'purchases', 'view' => 'purchases', 'actions' => [
            'purchase.create', 'purchase.delete',
            Permissions::RECEIPT_CREATE, Permissions::RECEIPT_APPROVE, Permissions::RECEIPT_REJECT,
            Permissions::INVOICE_VIEW, Permissions::INVOICE_CREATE, Permissions::INVOICE_APPROVE,
            Permissions::INVOICE_REJECT, Permissions::INVOICE_OVERRIDE, Permissions::INVOICE_PAY,
        ]],
        ['key' => 'suppliers', 'view' => 'suppliers', 'actions' => [
            'supplier.create', 'supplier.edit', 'supplier.delete', 'supplier.export',
        ]],
        ['key' => 'finance', 'view' => 'finance', 'actions' => [
            Permissions::CUSTOMER_PAYMENT_CREATE, Permissions::CUSTOMER_PAYMENT_CANCEL,
            Permissions::PAYMENT_LINK_CREATE,
            'journal.create', 'journal.reverse', 'chart.manage', 'bank.manage', 'bank.reconcile',
            'asset.manage', 'paymob.settle', Permissions::ATTACHMENT_VIEW, 'finance.export',
        ]],
        ['key' => 'expenses', 'view' => 'expenses', 'actions' => [
            'expense.create', 'expense.edit', 'expense.pay', 'expense.delete', 'expenseType.manage', 'expense.export',
        ]],
        ['key' => 'employees', 'view' => 'employees', 'actions' => [
            'employee.create', 'employee.edit', 'jobTitle.manage',
            Permissions::PAYROLL_VIEW, 'payroll.create', Permissions::PAYROLL_APPROVE, Permissions::PAYROLL_PAY,
        ]],
        ['key' => 'reports', 'view' => 'reports', 'actions' => ['report.export']],
        ['key' => 'marketing', 'view' => 'marketing', 'actions' => [
            'coupon.manage', 'loyalty.manage', 'review.manage', 'marketing.edit',
        ]],
        ['key' => 'website', 'view' => 'website', 'actions' => [Permissions::WEBSITE_CONFIGURE]],
        ['key' => 'integrations', 'view' => 'integrations', 'actions' => ['integration.manage', 'payment.configure']],
        ['key' => 'settings', 'view' => 'settings', 'actions' => [
            'settings.edit', 'books.close', 'branch.manage', 'device.manage', 'backup.manage',
            'subscription.manage', Permissions::BUSINESS_EXPORT,
        ]],
    ];

    /**
     * القائمةُ كما كانت تعمل قبل الفصل: كلُّ قسمٍ فيها ومعه أفعالُه.
     *
     * تقرؤها الهجرةُ لتُبقي كلَّ موظّفٍ على ما كان يفعله، ويقرؤها اختبارٌ
     * يصف موظّفًا قائمًا «مُنح القسم» — فلا تفترق نسختان.
     *
     * @param  list<string>  $list
     * @return list<string>
     */
    public static function withActionsOf(array $list): array
    {
        foreach (self::SPLIT as $action => $section) {
            if (in_array($section, $list, true)) {
                $list[] = $action;
            }
        }

        return array_values(array_unique($list));
    }

    /** الفعلُ الذي يحرس هذا المسار فوق قسمه — أو لا شيء */
    public static function forRoute(?string $route): ?string
    {
        return $route === null ? null : (self::ROUTES[$route] ?? null);
    }

    /**
     * المجموعاتُ كما تقرؤها شاشة الصلاحيات — بأسمائها المترجمة.
     *
     * @return list<array{key: string, label: string, view: ?string, opens_with: ?string, actions: list<string>}>
     */
    public static function groups(): array
    {
        $sections = Permissions::sectionLabels();

        return array_map(fn (array $g) => [
            'key' => $g['key'],
            'label' => isset($g['label']) ? __($g['label']) : ($sections[$g['view']] ?? $g['key']),
            'view' => $g['view'],
            'opens_with' => $g['opens_with'] ?? null,
            'actions' => $g['actions'],
        ], self::GROUPS);
    }
}
