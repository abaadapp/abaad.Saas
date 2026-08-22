import {
    AppWindow,
    BellOff,
    BellRing,
    DatabaseBackup,
    GitBranch,
    Globe,
    History,
    LayoutTemplate,
    ListTree,
    MonitorSmartphone,
    Percent,
    ShieldCheck,
    Store,
    Target,
    Trash2,
    UserCog,
} from 'lucide-react';

/**
 * أقسام الإعدادات، مجمَّعة كما تظهر في لوحة البطاقات.
 *
 * لكل بند وصفٌ قصير (`desc`) يظهر تحت الاسم في البطاقة، فيعرف المستخدم ما
 * وراء القسم قبل فتحه بدل أن يفتح كلًّا منها ليكتشف محتواه.
 *
 * والبطاقات ستّ عشرة صارت إحدى عشرة: القسم الذي حقلاه حقلان لا يستحقّ بطاقةً
 * وصفحةً ونقرةً — «العملة» ثلاثة حقول، و«الطباعة» حقلٌ واحد، و«الفواتير»
 * حقلان. جُمعت مع ما يُضبط معها في الجلسة نفسها: من يفتح الضريبة يفتح العملة،
 * ومن يغيّر شكل الفاتورة يغيّر ترقيمها وورقها.
 */
export const SETTINGS_NAV = [
    {
        group: 'المتجر',
        items: [
            /* اللغة هنا لا في بطاقةٍ وحدها: مفتاحٌ واحد بين خيارين لا يملأ شاشة */
            { key: 'business', label: 'بيانات النشاط', desc: 'اسم المتجر ورقم التواصل والعنوان ولغة النظام', icon: Store },
            { key: 'domain', label: 'إعدادات الدومين', desc: 'نطاق متجرك على الإنترنت ونشره للزوّار', icon: Globe },
            { key: 'website', label: 'إعدادات الموقع', desc: 'ما يراه زائر موقعك — التعريف والتواصل والعرض', icon: AppWindow },
        ],
    },
    {
        group: 'المالية',
        items: [
            { key: 'finance', label: 'الضرائب والعملة والدفع', desc: 'ضريبة القيمة المضافة ورمز العملة ووسائل الدفع', icon: Percent },
            { key: 'chart', label: 'شجرة الحسابات', desc: 'حسابات الدفتر وأرصدتها وميزان المراجعة', icon: ListTree },
        ],
    },
    {
        group: 'المبيعات',
        items: [
            { key: 'templates', label: 'قوالب الفواتير', desc: 'ترقيم الفواتير ومقاس الورق وشكل كل ورقة تُطبع', icon: LayoutTemplate },
        ],
    },
    {
        group: 'الفريق والتنبيهات',
        items: [
            { key: 'permissions', label: 'صلاحيات الموظفين', desc: 'ما يُسمح لكل موظف بالوصول إليه', icon: ShieldCheck },
            { key: 'notifications', label: 'الإشعارات', desc: 'تنبيهات البريد والملخّص اليومي', icon: BellRing },
            { key: 'custom-alerts', label: 'تنبيهات مخصّصة', desc: 'تنبيهات تصنعها بشروطك على أي مؤشّر', icon: Target },
            { key: 'notifications-log', label: 'التنبيهات المرسلة', desc: 'سِجلّ ما وصلك من تنبيهات', icon: BellOff },
        ],
    },
    {
        group: 'النظام',
        items: [
            { key: 'branches', label: 'الفروع', desc: 'إدارة فروع النشاط', icon: GitBranch, route: 'admin.branches.index' },
            { key: 'employees', label: 'الموظفون', desc: 'حسابات الموظفين وأدوارهم', icon: UserCog, route: 'admin.employees.index' },
            { key: 'devices', label: 'أجهزة نقاط البيع', desc: 'الأجهزة المرتبطة بالنظام', icon: MonitorSmartphone, route: 'admin.devices.index' },
            { key: 'activity', label: 'سجل النشاط', desc: 'سجل العمليات على النظام', icon: History, route: 'admin.activity.index' },
            { key: 'trash', label: 'المحذوفات', desc: 'استعادة ما حُذف', icon: Trash2, route: 'admin.settings.trash' },
            { key: 'backup', label: 'النسخ الاحتياطي', desc: 'تنزيل نسخة من بياناتك واستعادتها', icon: DatabaseBackup },
        ],
    },
] as const;

export type SettingsTabKey = (typeof SETTINGS_NAV)[number]['items'][number]['key'];
