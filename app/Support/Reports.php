<?php

namespace App\Support;

use App\Models\User;

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
        [
            'key' => 'profitability',
            'category' => 'financial',
            'section' => 'profitability',
            'title' => 'الربحية',
            'desc' => 'هامش الربح لكل منتج وقسم بعد خصم التكلفة.',
            'icon' => 'piggy-bank',
            'route' => 'admin.profitability.index',
        ],
        [
            'key' => 'vat',
            'category' => 'financial',
            'section' => 'vat',
            'title' => 'ضريبة القيمة المضافة',
            'desc' => 'الضريبة المحصّلة والمدفوعة في كل فترة، جاهزةً للإقرار.',
            'icon' => 'percent',
            'route' => 'admin.vat.index',
        ],
        [
            'key' => 'finance',
            'category' => 'financial',
            'section' => 'finance',
            'title' => 'الحركة المالية',
            'desc' => 'المقبوضات والمدفوعات وصافي الحركة في المدة المختارة.',
            'icon' => 'wallet',
            'route' => 'admin.finance.index',
        ],
        [
            'key' => 'expenses',
            'category' => 'financial',
            'section' => 'expenses',
            'title' => 'المصروفات',
            'desc' => 'المصروفات حسب النوع والتاريخ ومن سجّلها.',
            'icon' => 'arrow-down-circle',
            'route' => 'admin.expenses.index',
        ],
        [
            'key' => 'payments',
            'category' => 'financial',
            'section' => 'finance',
            'title' => 'وسائل الدفع',
            'desc' => 'توزيع التحصيل على النقد والبطاقة وبقية الوسائل.',
            'icon' => 'credit-card',
            'data' => 'payments',
        ],
        [
            'key' => 'shifts',
            'category' => 'financial',
            'section' => 'finance',
            'title' => 'إقفال الورديات',
            'desc' => 'حصيلة كل وردية وفرق الصندوق عند التسليم.',
            'icon' => 'clock',
            'route' => 'admin.shifts.index',
        ],
        [
            'key' => 'bank',
            'category' => 'financial',
            'section' => 'finance',
            'title' => 'كشف الحساب البنكي',
            'desc' => 'مطابقة حركات البنك بحركات النظام، وما لم يُطابَق منها.',
            'icon' => 'landmark',
            'route' => 'admin.finance.statement',
        ],

        /* ----------------------------- تشغيلية ----------------------------- */
        [
            'key' => 'orders',
            'category' => 'operational',
            'section' => 'orders',
            'title' => 'الطلبات',
            'desc' => 'كل طلبٍ بحالته وفرعه وقيمته ووسيلة دفعه.',
            'icon' => 'shopping-cart',
            'route' => 'admin.orders.index',
        ],
        [
            'key' => 'products',
            'category' => 'operational',
            'section' => 'products',
            'title' => 'المنتجات',
            'desc' => 'المنتجات وأسعارها وأقسامها وكمياتها المتاحة.',
            'icon' => 'package',
            'route' => 'admin.products.index',
        ],
        [
            'key' => 'inventory',
            'category' => 'operational',
            'section' => 'inventory',
            'title' => 'المخزون والكميات',
            'desc' => 'رصيد كل صنف وحدّه الأدنى وما بلغ حدّ إعادة الطلب.',
            'icon' => 'boxes',
            'route' => 'admin.inventory.index',
        ],
        [
            'key' => 'movements',
            'category' => 'operational',
            'section' => 'inventory',
            'title' => 'حركات المخزون',
            'desc' => 'كل دخولٍ وخروجٍ وتسويةٍ على المخزون ومن نفّذها.',
            'icon' => 'refresh-cw',
            'route' => 'admin.inventory.movements',
        ],
        [
            'key' => 'purchases',
            'category' => 'operational',
            'section' => 'purchases',
            'title' => 'أوامر الشراء',
            'desc' => 'أوامر الشراء وقيمتها وحالة استلامها لكل مورّد.',
            'icon' => 'truck',
            'route' => 'admin.purchases.index',
        ],
        [
            'key' => 'suppliers',
            'category' => 'operational',
            'section' => 'suppliers',
            'title' => 'المورّدون',
            'desc' => 'بيانات المورّدين وعدد أوامر الشراء لكل واحد.',
            'icon' => 'store',
            'route' => 'admin.suppliers.index',
        ],
        [
            'key' => 'staff',
            'category' => 'operational',
            'section' => 'employees',
            'title' => 'أداء الموظفين',
            'desc' => 'مبيعات كل موظف هذا الشهر وفرعه وحالته.',
            'icon' => 'users',
            'data' => 'employees',
        ],
        [
            'key' => 'activity',
            'category' => 'operational',
            'section' => 'settings',
            'title' => 'سجل النشاط',
            'desc' => 'من فعل ماذا ومتى على النظام.',
            'icon' => 'history',
            'route' => 'admin.activity.index',
        ],

        /* ----------------------------- تحليلات ----------------------------- */
        [
            'key' => 'analytics',
            'category' => 'analytical',
            'section' => 'reports',
            'title' => 'تحليلات متقدمة',
            'desc' => 'مقارنة الفترات، والبيع حسب اليوم والساعة، وأفضل المنتجات.',
            'icon' => 'line-chart',
            'route' => 'admin.analytics.index',
        ],
        [
            'key' => 'categories',
            'category' => 'analytical',
            'section' => 'reports',
            'title' => 'المبيعات حسب القسم',
            'desc' => 'نصيب كل قسمٍ من المبيعات، وأيّها يحمل المتجر.',
            'icon' => 'layers',
            'data' => 'categories',
        ],
        [
            'key' => 'customers',
            'category' => 'analytical',
            'section' => 'customers',
            'title' => 'العملاء الأكثر إنفاقًا',
            'desc' => 'من يشتري أكثر، وكم طلبًا وكم أنفق.',
            'icon' => 'star',
            'data' => 'customers',
        ],
        [
            'key' => 'marketing',
            'category' => 'analytical',
            'section' => 'marketing',
            'title' => 'الكوبونات والتسويق',
            'desc' => 'استخدام الكوبونات وقيمة الخصومات وشرائح العملاء.',
            'icon' => 'ticket-percent',
            // إلى الشاشة نفسها لا إلى تحويلٍ إليها: بطاقةٌ تقود إلى ٣٠٢
            // تصل إلى وجهتها اليوم، وتصل إلى غيرها يوم يتبدّل التحويل
            'route' => 'admin.marketing.coupons',
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
        return collect(self::ALL)
            ->filter(fn ($r) => $user?->allows($r['section']) ?? false)
            ->map(fn ($r) => [
                'key' => $r['key'],
                'category' => $r['category'],
                'title' => __($r['title']),
                'desc' => __($r['desc']),
                'icon' => $r['icon'],
                'href' => isset($r['route']) ? route($r['route']) : null,
                'data' => $r['data'] ?? null,
            ])
            ->values()
            ->all();
    }

    /** أسماء التصنيفات مترجمةً — الواجهة لا تخمّنها من المفتاح */
    public static function categoryLabels(): array
    {
        return collect(self::CATEGORIES)->map(fn ($l) => __($l))->all();
    }
}
