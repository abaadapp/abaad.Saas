import { useMemo, useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import {
    ArrowDownCircle,
    ArrowLeft,
    Boxes,
    Clock,
    CreditCard,
    History,
    Landmark,
    Layers,
    LineChart,
    type LucideIcon,
    Package,
    Percent,
    PiggyBank,
    RefreshCw,
    Search,
    ShoppingCart,
    Star,
    Store,
    TicketPercent,
    TrendingUp,
    Truck,
    Users,
    Wallet,
    X,
} from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import ReportViewer from './partials/ReportViewer';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

interface Report {
    key: string;
    category: string;
    title: string;
    desc: string;
    icon: string;
    /** صفحةٌ تفتحه — أو null فيفتحه العارض */
    href: string | null;
    /** مفتاح بياناته في ReportDataController — أو null فله صفحة */
    data: string | null;
}

interface Props {
    reports: Report[];
    categories: Record<string, string>;
}

/**
 * خريطة صريحة لأسماء الأيقونات القادمة من Support\Reports.
 * صريحة عمدًا: الاستيراد الشامل من lucide-react يضخّ المكتبة كاملة في الحزمة.
 */
const ICONS: Record<string, LucideIcon> = {
    'arrow-down-circle': ArrowDownCircle,
    boxes: Boxes,
    clock: Clock,
    'credit-card': CreditCard,
    history: History,
    landmark: Landmark,
    layers: Layers,
    'line-chart': LineChart,
    package: Package,
    percent: Percent,
    'piggy-bank': PiggyBank,
    'refresh-cw': RefreshCw,
    'shopping-cart': ShoppingCart,
    star: Star,
    store: Store,
    'ticket-percent': TicketPercent,
    'trending-up': TrendingUp,
    truck: Truck,
    users: Users,
    wallet: Wallet,
};

/**
 * فهرس التقارير — بابٌ واحد إلى ما في النظام من تقارير.
 *
 * البطاقات تأتي من الخادم مصفّاةً بصلاحيات صاحبها (Support\Reports)، فما لا
 * يفتحه لا يراه. والعدّ في الشرائح يُحتسب مما وصل لا من القائمة الكاملة، وإلا
 * قال «١٤» ثم عرض ثمانيًا.
 */
export default function ReportsIndex() {
    const { reports, categories } = usePage<PageProps<Props>>().props;
    const t = useTranslate();

    const [query, setQuery] = useState('');
    const [category, setCategory] = useState<string | null>(null);
    const [viewing, setViewing] = useState<string | null>(null);

    /* البحث في الاسم والوصف معًا: من يبحث عن «ضريبة» يجدها في اسمها، ومن
       يبحث عن «الإقرار» لا يجدها إلا في وصفها. */
    const matched = useMemo(() => {
        const q = query.trim().toLowerCase();
        if (!q) return reports;

        return reports.filter(
            (r) => r.title.toLowerCase().includes(q) || r.desc.toLowerCase().includes(q),
        );
    }, [reports, query]);

    const visible = category ? matched.filter((r) => r.category === category) : matched;

    // التصنيف الذي لا تقريرَ فيه بعد البحث لا تُرسم شريحته فارغةً
    const chips = Object.entries(categories)
        .map(([key, label]) => ({ key, label, count: matched.filter((r) => r.category === key).length }))
        .filter((c) => c.count > 0);

    const groups = Object.entries(categories)
        .map(([key, label]) => ({ key, label, items: visible.filter((r) => r.category === key) }))
        .filter((g) => g.items.length > 0);

    return (
        <AdminLayout title="التقارير">
            <PageHeader
                title="التقارير"
                subtitle={t('تصفَّح وافتح التقارير التي تعتمد عليها.')}
            />

            {/* البحث — أيقونةٌ في بداية الحقل، والحقل بعرض الصفحة كما في الشاشة الأصل */}
            <div className="relative mb-4">
                <Search className="pointer-events-none absolute start-4 top-1/2 size-[18px] -translate-y-1/2 text-[#9ca3af]" />
                <input
                    type="search"
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                    placeholder={t('ابحث عن تقرير بالاسم أو الوصف')}
                    aria-label={t('ابحث عن تقرير بالاسم أو الوصف')}
                    className={cn(
                        'h-[52px] w-full rounded-[16px] border border-[var(--ui-border,#e8e8e8)] bg-white',
                        'ps-12 pe-11 text-[14px] text-[#111] placeholder:text-[#9ca3af]',
                        'transition-[border-color,box-shadow] focus:border-[#d1d5db] focus:outline-none',
                        'focus:shadow-[0_0_0_3px_rgba(0,0,0,0.05)]',
                        /* زرّ المسح الأصلي يخرج أزرقَ من لوحة النظام على
                           سفاري وكروم — لونٌ لا وجود له في ثيمة أبعاد. يُخفى
                           ويُرسم بدلًا منه زرٌّ بلون الواجهة. */
                        '[&::-webkit-search-cancel-button]:appearance-none',
                    )}
                />
                {query && (
                    <button
                        type="button"
                        onClick={() => setQuery('')}
                        aria-label={t('مسح البحث')}
                        className="absolute end-3 top-1/2 -translate-y-1/2 rounded-full p-1.5 text-[#9ca3af] transition-colors hover:bg-[#f2f2f0] hover:text-[#111]"
                    >
                        <X className="size-4" />
                    </button>
                )}
            </div>

            {/* شرائح التصنيف — النشطة سوداء كأزرار النظام، وبقيّتها بيضاء بحدّ */}
            <div className="mb-7 flex flex-wrap items-center gap-2">
                {[{ key: null, label: t('الكل'), count: matched.length }, ...chips].map((c) => {
                    const active = category === c.key;

                    return (
                        <button
                            key={c.key ?? 'all'}
                            type="button"
                            onClick={() => setCategory(c.key)}
                            aria-pressed={active}
                            className={cn(
                                'flex h-9 items-center gap-2 rounded-full px-4 text-[13px] font-medium transition-colors',
                                active
                                    ? 'bg-[#111] text-white'
                                    : 'border border-[var(--ui-border,#e8e8e8)] bg-white text-[#6b7280] hover:border-[#d4d4d4] hover:text-[#111]',
                            )}
                        >
                            {c.label}
                            <span
                                className={cn(
                                    'tabular-nums text-[12px]',
                                    active ? 'text-white/60' : 'text-[#9ca3af]',
                                )}
                            >
                                {c.count}
                            </span>
                        </button>
                    );
                })}
            </div>

            {groups.length === 0 ? (
                <div className="rounded-[16px] border border-dashed border-[var(--ui-border,#e8e8e8)] bg-white py-16 text-center">
                    <p className="text-[14px] font-medium text-[#111]">{t('لا تقرير بهذا الاسم')}</p>
                    <p className="mt-1 text-[13px] text-[#9ca3af]">
                        {t('جرّب كلمةً أخرى، أو اعرض الكل.')}
                    </p>
                </div>
            ) : (
                <div className="space-y-9">
                    {groups.map((g) => (
                        <section key={g.key}>
                            <div className="mb-4 flex items-center justify-between gap-3">
                                <h2 className="flex items-center gap-2.5 text-[15px] font-bold text-[#111]">
                                    <span className="size-2 rounded-[3px] bg-[#111]" aria-hidden />
                                    {g.label}
                                </h2>
                                <span className="flex size-7 items-center justify-center rounded-full border border-[var(--ui-border,#e8e8e8)] bg-white text-[12px] tabular-nums text-[#9ca3af]">
                                    {g.items.length}
                                </span>
                            </div>

                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                {g.items.map((r) => (
                                    <ReportCard key={r.key} report={r} onView={setViewing} />
                                ))}
                            </div>
                        </section>
                    ))}
                </div>
            )}

            <ReportViewer dataKey={viewing} onClose={() => setViewing(null)} />
        </AdminLayout>
    );
}

/**
 * بطاقة تقرير — أيقونةٌ في أعلى الطرف، ثم الاسم والوصف، و«فتح» في القاع.
 *
 * البطاقة كلّها هي المقبض لا كلمة «فتح» وحدها: هدفٌ بحجم البطاقة لا يُخطئه
 * إصبعٌ على الآيباد. و«فتح» تبقى مرسومةً لأنها تقول ما يحدث عند النقر.
 */
function ReportCard({ report, onView }: { report: Report; onView: (key: string) => void }) {
    const t = useTranslate();
    const Icon = ICONS[report.icon] ?? LineChart;

    const body = (
        <>
            <div className="flex items-start justify-between gap-3">
                <h3 className="text-[15px] font-semibold text-[#111]">{report.title}</h3>
                <span className="flex size-11 shrink-0 items-center justify-center rounded-[13px] bg-[#f5f5f4] text-[#111] transition-colors group-hover:bg-[#111] group-hover:text-white">
                    <Icon className="size-5" />
                </span>
            </div>

            {/* الوصف يشغل ما بينهما فتستوي أقدام البطاقات في الصف الواحد */}
            <p className="mt-2 flex-1 text-[13px] leading-relaxed text-[#9ca3af]">{report.desc}</p>

            <span className="mt-4 flex items-center gap-1.5 text-[13px] font-medium text-[#111]">
                {t('فتح')}
                {/* السهم يشير إلى جهة القراءة التالية: يسارًا في العربية،
                    ويُقلب في الإنجليزية فلا يعود يشير إلى الخلف */}
                <ArrowLeft className="size-4 transition-transform group-hover:-translate-x-1 ltr:rotate-180 ltr:group-hover:translate-x-1" />
            </span>
        </>
    );

    const cls =
        'group flex h-full flex-col rounded-[16px] border border-[var(--ui-border,#e8e8e8)] bg-white p-5 text-start transition hover:border-[#d4d4d4] hover:shadow-[0_8px_30px_-12px_rgba(17,17,17,0.14)]';

    return report.href ? (
        <Link href={report.href} className={cls}>
            {body}
        </Link>
    ) : (
        <button type="button" onClick={() => onView(report.data!)} className={cls}>
            {body}
        </button>
    );
}
