import { Link } from '@inertiajs/react';
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
    ShoppingCart,
    Store,
    Target,
    Truck,
    UserCog,
    Wallet,
} from 'lucide-react';
import { Card } from '@/Components/ui/card';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/** أقسام الإعدادات، مجمَّعة كما تظهر في العمود */
export const SETTINGS_NAV = [
    {
        group: 'المتجر',
        items: [
            { key: 'business', label: 'بيانات النشاط', icon: Store },
            { key: 'language', label: 'اللغة', icon: Languages },
        ],
    },
    {
        group: 'المالية',
        items: [
            { key: 'taxes', label: 'الضرائب', icon: Percent },
            { key: 'currency', label: 'العملة', icon: Coins },
            { key: 'payments', label: 'طرق الدفع', icon: CreditCard },
            { key: 'loyalty', label: 'الولاء', icon: Gift },
            { key: 'shifts', label: 'وردية الصندوق', icon: Wallet },
        ],
    },
    {
        group: 'المبيعات',
        items: [
            { key: 'invoices', label: 'الفواتير', icon: FileText },
            { key: 'orders', label: 'الطلبات', icon: ShoppingCart },
            { key: 'delivery', label: 'التوصيل', icon: Truck },
        ],
    },
    {
        group: 'الطباعة',
        items: [
            { key: 'printing', label: 'الطباعة', icon: Printer },
            { key: 'templates', label: 'قوالب', icon: LayoutTemplate },
        ],
    },
    {
        group: 'الفريق والتنبيهات',
        items: [
            { key: 'permissions', label: 'صلاحيات الموظفين', icon: ShieldCheck },
            { key: 'notifications', label: 'الإشعارات', icon: BellRing },
            { key: 'custom-alerts', label: 'تنبيهات مخصّصة', icon: Target },
            { key: 'notifications-log', label: 'التنبيهات المرسلة', icon: BellOff },
        ],
    },
    {
        group: 'النظام',
        items: [
            { key: 'branches', label: 'الفروع', icon: GitBranch, route: 'admin.branches.index' },
            { key: 'employees', label: 'الموظفون', icon: UserCog, route: 'admin.employees.index' },
            { key: 'devices', label: 'أجهزة نقاط البيع', icon: MonitorSmartphone, route: 'admin.devices.index' },
            { key: 'activity', label: 'سجل النشاط', icon: History, route: 'admin.activity.index' },
            { key: 'backup', label: 'النسخ الاحتياطي', icon: DatabaseBackup },
        ],
    },
] as const;

export type SettingsTabKey = (typeof SETTINGS_NAV)[number]['items'][number]['key'];

interface Props {
    /** القسم النشط — مفتاحٌ من الأقسام أو مفتاح صفحةٍ من صفحات «النظام» */
    current: string;
    /**
     * يُمرَّر من صفحة الإعدادات وحدها: الأقسام تتبدّل في مكانها بلا تنقّل.
     * وفي الصفحات الأخرى يغيب، فتصير الأقسام روابط تعود إلى الإعدادات.
     */
    onPick?: (key: string) => void;
}

/**
 * العمود الجانبي للإعدادات.
 *
 * كان يعيش داخل صفحة الإعدادات وحدها، وكانت «الفروع» و«الموظفون» و«سجل
 * النشاط» روابط تخرج بالمستخدم من الإعدادات فيختفي العمود — فيفقد موضعه
 * ولا سبيل للعودة إلا بالرجوع. صار العمود مكوّنًا مشتركًا تحمله تلك الصفحات
 * نفسها، فيبقى ثابتًا ويظلّ الانتقال بينها نقرةً واحدة.
 *
 * وهذه الصفحات الثلاث ليست في قائمة اللوحة الرئيسية أصلًا — لا تُبلَغ إلا
 * من هنا، فحملُها لعمود الإعدادات هو موضعها الصحيح لا إقحام.
 */
export default function SettingsNav({ current, onPick }: Props) {
    const t = useTranslate();

    return (
        <aside className="lg:sticky lg:top-4 lg:self-start">
            <Card className="p-2">
                <nav className="space-y-3">
                    {SETTINGS_NAV.map((g) => (
                        <div key={g.group}>
                            <p className="px-3 pb-1 pt-2 text-[11px] font-semibold tracking-wide text-[#9ca3af]">
                                {t(g.group)}
                            </p>
                            {g.items.map((x) => {
                                const active = current === x.key;
                                const className = cn(
                                    'flex w-full items-center gap-2.5 rounded-[10px] px-3 py-2 text-start text-sm font-medium transition-colors',
                                    active ? 'bg-[#111] text-white' : 'text-[#4b4b4b] hover:bg-[#f5f5f4]',
                                );
                                const body = (
                                    <>
                                        <x.icon className="size-4 shrink-0" />
                                        <span className="min-w-0 truncate">{t(x.label)}</span>
                                    </>
                                );

                                // صفحة مستقلة: رابط دائمًا، فهي ليست قسمًا يتبدّل في مكانه
                                if ('route' in x && x.route) {
                                    return (
                                        <Link
                                            key={x.key}
                                            href={route(x.route)}
                                            aria-current={active ? 'page' : undefined}
                                            className={className}
                                        >
                                            {body}
                                        </Link>
                                    );
                                }

                                // قسمٌ داخل الإعدادات: يتبدّل في مكانه هناك، ويعود
                                // إليها بالمرساة إن كنّا في صفحة أخرى
                                return onPick ? (
                                    <button
                                        key={x.key}
                                        type="button"
                                        onClick={() => onPick(x.key)}
                                        aria-current={active ? 'page' : undefined}
                                        className={className}
                                    >
                                        {body}
                                    </button>
                                ) : (
                                    <Link
                                        key={x.key}
                                        href={`${route('admin.settings.index')}#${x.key}`}
                                        className={className}
                                    >
                                        {body}
                                    </Link>
                                );
                            })}
                        </div>
                    ))}
                </nav>
            </Card>
        </aside>
    );
}
