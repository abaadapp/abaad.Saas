import {
    BellOff,
    BellRing,
    Coins,
    CreditCard,
    DatabaseBackup,
    FileText,
    Gift,
    GitBranch,
    MonitorSmartphone,
    History,
    Languages,
    LayoutTemplate,
    Percent,
    Printer,
    ShieldCheck,
    Store,
    Target,
    Trash2,
    UserCog,
    Wallet,
} from 'lucide-react';

/**
 * أقسام الإعدادات، مجمَّعة كما تظهر في لوحة البطاقات.
 *
 * لكل بند وصفٌ قصير (`desc`) يظهر تحت الاسم في البطاقة، فيعرف المستخدم ما
 * وراء القسم قبل فتحه بدل أن يفتح كلًّا منها ليكتشف محتواه.
 */
export const SETTINGS_NAV = [
    {
        group: 'المتجر',
        items: [
            { key: 'business', label: 'بيانات النشاط', desc: 'اسم المتجر ورقم التواصل والعنوان والموقع الإلكتروني', icon: Store },
            { key: 'language', label: 'اللغة', desc: 'لغة واجهة النظام واتجاهها', icon: Languages },
        ],
    },
    {
        group: 'المالية',
        items: [
            { key: 'taxes', label: 'الضرائب', desc: 'ضريبة القيمة المضافة ونسبتها والرقم الضريبي', icon: Percent },
            { key: 'currency', label: 'العملة', desc: 'رمز العملة والخانات العشرية وموضع الرمز', icon: Coins },
            { key: 'payments', label: 'طرق الدفع', desc: 'وسائل الدفع المتاحة عند البيع', icon: CreditCard },
            { key: 'loyalty', label: 'الولاء', desc: 'نقاط العملاء ومعدّل كسبها واستبدالها', icon: Gift },
            { key: 'shifts', label: 'وردية الصندوق', desc: 'اشتراط فتح وردية قبل البيع', icon: Wallet },
        ],
    },
    {
        group: 'المبيعات',
        items: [
            { key: 'invoices', label: 'الفواتير', desc: 'بادئة رقم الفاتورة ورقمها الأول', icon: FileText },
        ],
    },
    {
        group: 'الطباعة',
        items: [
            { key: 'printing', label: 'الطباعة', desc: 'مقاس ورق الإيصال', icon: Printer },
            { key: 'templates', label: 'قالب الإيصال', desc: 'شكل الإيصال المطبوع وما يظهر فيه', icon: LayoutTemplate },
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
