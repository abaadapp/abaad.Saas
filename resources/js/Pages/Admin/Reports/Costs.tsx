import { useEffect, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { Info } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import BackToReports from '@/Components/BackToReports';
import ExportMenu from '@/Components/ExportMenu';
import StatCard from '@/Components/StatCard';
import { Select } from '@/Components/Field';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@/Components/ui/table';
import { money } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

interface Change {
    delta: number;
    /** `null` حين لا سابقَ موجب — نسبةٌ من صفر لا تُكتب */
    change_pct: number | null;
    share?: number | null;
}

export interface CostRow extends Change {
    category: string;
    account_id: number;
    code: string;
    account: string;
    current: number;
    previous: number;
}

export interface CostCategory extends Change {
    key: string;
    label: string;
    current: number;
    previous: number;
    rows: CostRow[];
}

interface Summary {
    total: number;
    cost_of_sales: number;
    operating: number;
    losses: number;
    other: number;
}

interface Option {
    value: string;
    label: string;
}

interface DrillLine {
    id: number;
    date: string;
    number: string;
    description: string;
    memo: string | null;
    branch: string | null;
    account: string;
    code: string;
    category: string;
    net: number;
    source: string;
    reference: string | null;
    reversal: boolean;
}

interface Drill {
    total: number;
    count: number;
    page: number;
    last_page: number;
    lines: DrillLine[];
}

interface Props {
    summary: Summary;
    previousSummary: Summary;
    comparison: ({ key: keyof Summary; current: number; previous: number } & Change)[];
    categories: CostCategory[];
    scope: {
        from: string;
        to: string;
        branch_id: number | null;
        category: string | null;
        name: string;
        restricted: boolean;
        previous: { from: string; to: string };
    };
    reconciliation: { unposted_count: number; unposted_amount: number; unpaid_count: number; unpaid_amount: number };
    filters: Record<string, string | null>;
    options: { branches: Option[]; categories: Option[] };
}

const COMPARE_LABELS: Record<keyof Summary, string> = {
    total: 'إجمالي التكاليف والخسائر',
    cost_of_sales: 'تكلفة المبيعات',
    operating: 'مصروفات التشغيل',
    losses: 'الخسائر',
    other: 'مصروفات وخسائر أخرى',
};

/** «+١٢٫٥٪» أو «—» — والنسبةُ من الخادم، لا تُحسب هنا */
export function pctText(v: number | null | undefined): string {
    if (v === null || v === undefined) return '—';

    return `${v > 0 ? '+' : ''}${v.toFixed(1)}%`;
}

/**
 * التكاليف والخسائر — ما أنقص الربحَ كما قيّده دفترُ الأستاذ.
 *
 * ═══ والشاشةُ لا تحسب ═══
 *
 * كلُّ رقمٍ من الخادم (`ReportData::costs`): صافي كلّ حسابٍ في فئته، ومجموعُ
 * الفئة، والإجماليُّ، والمدّةُ السابقة وفرقُها ونسبتُه. والسطورُ تحت كلّ صفٍّ
 * تُقرأ من الاستعلام نفسِه (`admin.reports.costs.lines`) بالمرشّحات نفسِها،
 * فمجموعُها مجموعُ الصفّ.
 */
export default function ReportsCosts() {
    const { summary, previousSummary, comparison, categories, scope, reconciliation, filters, options, context } =
        usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const m = (v: number) => money(v, context!.currency);

    const [drill, setDrill] = useState<{ title: string; category: string; accountId: number | null } | null>(null);

    /** كل تغييرٍ في مرشّح يعيد تحميل الصفحة — لا حساب في المتصفّح */
    const go = (patch: Record<string, string>) =>
        router.get(route('admin.reports.costs'), { ...filters, ...patch }, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });

    const trend = (current: number, previous: number, pct: number | null) =>
        pct === null ? undefined : { trend: pctText(pct), up: current > previous };

    return (
        <AdminLayout title={t('التكاليف والخسائر')}>
            <div className="no-print">
                <BackToReports />
            </div>

            <PageHeader
                title="التكاليف والخسائر"
                subtitle="ما أنقص الربح في المدّة كما قيّده دفتر الأستاذ: تكلفة المبيعات والموظفون والتشغيل والإهلاك وخسائر المخزون، مقارنةً بالمدّة السابقة."
                actions={
                    /* الملفّ يحمل المرشّحات المعروضة — `withFilters` تُلحق سلسلة الاستعلام */
                    <ExportMenu
                        feature="reports_advanced"
                        xlsx={route('admin.reports.export.xlsx', 'costs')}
                        pdf={route('admin.reports.export.pdf', 'costs')}
                        csv={route('admin.reports.export.csv', 'costs')}
                    />
                }
            />

            <Card className="mb-6 grid grid-cols-1 gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4" data-testid="costs-filters">
                <label className="flex flex-col gap-1">
                    <span className="text-[12px] text-[#6b7280]">{t('من')}</span>
                    <Input type="date" dir="ltr" value={filters.from ?? ''} onChange={(e) => go({ from: e.target.value })} />
                </label>
                <label className="flex flex-col gap-1">
                    <span className="text-[12px] text-[#6b7280]">{t('إلى')}</span>
                    <Input type="date" dir="ltr" value={filters.to ?? ''} onChange={(e) => go({ to: e.target.value })} />
                </label>
                <label className="flex flex-col gap-1">
                    <span className="text-[12px] text-[#6b7280]">{t('الفرع')}</span>
                    <Select
                        placeholder={scope.restricted ? 'فروعي' : 'كل الفروع'}
                        value={filters.branch_id ?? ''}
                        onChange={(e) => go({ branch_id: e.target.value })}
                        options={options.branches}
                    />
                </label>
                <label className="flex flex-col gap-1">
                    <span className="text-[12px] text-[#6b7280]">{t('الفئة')}</span>
                    <Select
                        placeholder="كل الفئات"
                        value={filters.category ?? ''}
                        onChange={(e) => go({ category: e.target.value })}
                        options={options.categories}
                    />
                </label>
            </Card>

            <div className="mb-3 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5" data-testid="costs-cards">
                <StatCard
                    stat={{
                        label: t('إجمالي التكاليف والخسائر'),
                        value: m(summary.total),
                        icon: 'trending-down',
                        color: 'danger',
                        ...trend(summary.total, previousSummary.total, comparison[0]?.change_pct ?? null),
                    }}
                />
                <StatCard stat={{ label: t('تكلفة المبيعات'), value: m(summary.cost_of_sales), icon: 'package', color: 'warning' }} />
                <StatCard stat={{ label: t('مصروفات التشغيل'), value: m(summary.operating), icon: 'wallet', color: 'primary' }} />
                <StatCard stat={{ label: t('الخسائر'), value: m(summary.losses), icon: 'alert-triangle', color: 'secondary' }} />
                <StatCard stat={{ label: t('مصروفات وخسائر أخرى'), value: m(summary.other), icon: 'receipt', color: 'info' }} />
            </div>

            <p className="mb-6 text-[12px] leading-relaxed text-[#71717a]" data-testid="costs-period">
                {scope.name} · {scope.from} → {scope.to} · {t('مقارنة بالفترة السابقة')}: {scope.previous.from} → {scope.previous.to}
            </p>

            <Card className="mb-6 overflow-x-auto">
                <Table data-testid="costs-table">
                    <TableHeader>
                        <TableRow className="hover:bg-transparent">
                            <TableHead>{t('الفئة / الحساب')}</TableHead>
                            <TableHead className="text-end">{t('هذه المدّة')}</TableHead>
                            <TableHead className="text-end">{t('من الإجمالي')}</TableHead>
                            <TableHead className="text-end">{t('المدّة السابقة')}</TableHead>
                            <TableHead className="text-end">{t('الفرق')}</TableHead>
                            <TableHead className="text-end">{t('التغيّر')}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {categories.every((c) => c.rows.length === 0) ? (
                            <TableEmpty colSpan={6}>{t('لا تكاليف مرحّلة في هذه المدّة')}</TableEmpty>
                        ) : (
                            categories
                                .filter((c) => c.rows.length > 0)
                                .map((c) => (
                                    <CategoryBlock
                                        key={c.key}
                                        category={c}
                                        money={m}
                                        onOpen={(title, accountId) => setDrill({ title, category: c.key, accountId })}
                                    />
                                ))
                        )}
                        <TableRow className="bg-[#fafafa] hover:bg-[#fafafa]" data-testid="costs-total">
                            <TableCell className="font-bold">{t('إجمالي التكاليف والخسائر')}</TableCell>
                            <Money value={summary.total} money={m} strong />
                            <TableCell className="text-end tabular-nums font-bold">{summary.total > 0 ? '100.0%' : '—'}</TableCell>
                            <Money value={previousSummary.total} money={m} strong />
                            <Money value={comparison[0]?.delta ?? 0} money={m} strong signed />
                            <TableCell className="text-end tabular-nums font-bold">{pctText(comparison[0]?.change_pct)}</TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </Card>

            <Card className="mb-6 overflow-hidden" data-testid="costs-comparison">
                <h2 className="px-4 pt-4 text-[14px] font-bold text-[#111]">{t('مقارنة بالفترة السابقة')}</h2>
                <Table>
                    <TableHeader>
                        <TableRow className="hover:bg-transparent">
                            <TableHead>{t('البند')}</TableHead>
                            <TableHead className="text-end">{t('هذه المدّة')}</TableHead>
                            <TableHead className="text-end">{t('المدّة السابقة')}</TableHead>
                            <TableHead className="text-end">{t('الفرق')}</TableHead>
                            <TableHead className="text-end">{t('التغيّر')}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {comparison.map((c) => (
                            <TableRow key={c.key}>
                                <TableCell>{t(COMPARE_LABELS[c.key])}</TableCell>
                                <Money value={c.current} money={m} />
                                <Money value={c.previous} money={m} />
                                <Money value={c.delta} money={m} signed />
                                <TableCell className="text-end tabular-nums">{pctText(c.change_pct)}</TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </Card>

            <Card className="mb-6 p-4 text-[12.5px] leading-relaxed text-[#374151]" data-testid="costs-notes">
                <h2 className="mb-2 flex items-center gap-2 text-[14px] font-bold text-[#111]">
                    <Info className="size-4" />
                    {t('كيف يُقرأ هذا التقرير؟')}
                </h2>
                <ul className="list-disc space-y-1 ps-5">
                    <li>{t('الأرقام من القيود المرحّلة في دفتر الأستاذ وحده، وكل حدثٍ مرّة واحدة: الراتب يوم اعتماد المسيرة لا يوم صرفها، والهالك بقيده لا بصفّ مصروفه.')}</li>
                    <li>{t('مشتريات المخزون وسداد المورّدين ومسحوبات المالك والتحويلات وشراء الأصول ليست تكاليف هنا. البضاعة تصير تكلفةً حين تُباع أو تتلف.')}</li>
                    <li>{t('الإهلاك المرحّل وحده يُحسب — ما لم يُرحَّل من شاشة الأصول لا يظهر. وخسارة استبعاد الأصل تُقرأ بمبلغها المرحّل ضمن «الخسائر».')}</li>
                    {scope.branch_id !== null && (
                        <li data-testid="costs-branch-note">
                            {t('عند اختيار فرع تُعرض قيوده وحدها. القيود العامة للنشاط بلا فرع — الرواتب والإهلاك والمصروف الموزّع — لا تُنسب إليه.')}
                        </li>
                    )}
                    {reconciliation.unposted_count > 0 && (
                        <li data-testid="costs-unposted" className="text-[#92400e]">
                            {t(':count مصروفًا مدفوعًا في هذه المدّة بقيمة :amount ليس له قيدٌ في الدفتر، فلا يدخل هذا التقرير.', {
                                count: String(reconciliation.unposted_count),
                                amount: m(reconciliation.unposted_amount),
                            })}
                        </li>
                    )}
                    {reconciliation.unpaid_count > 0 && (
                        <li data-testid="costs-unpaid">
                            {t(':count مصروفًا غير مدفوع بقيمة :amount — يدخل الدفتر يوم يُدفع.', {
                                count: String(reconciliation.unpaid_count),
                                amount: m(reconciliation.unpaid_amount),
                            })}
                        </li>
                    )}
                </ul>
            </Card>

            <DrillDialog drill={drill} filters={filters} money={m} onClose={() => setDrill(null)} />
        </AdminLayout>
    );
}

function Money({ value, money: m, strong = false, signed = false }: { value: number; money: (v: number) => string; strong?: boolean; signed?: boolean }) {
    return (
        <TableCell className={cn('text-end tabular-nums', strong && 'font-bold', value < 0 && 'text-[#b91c1c]')}>
            {signed && value > 0 ? '+' : ''}
            {m(value)}
        </TableCell>
    );
}

/** فئةٌ وحساباتُها — والضغطُ على صفٍّ يفتح سطورَه */
function CategoryBlock({
    category: c,
    money: m,
    onOpen,
}: {
    category: CostCategory;
    money: (v: number) => string;
    onOpen: (title: string, accountId: number | null) => void;
}) {
    const t = useTranslate();
    const share = (v: number | null | undefined) => (v === null || v === undefined ? '—' : `${v.toFixed(1)}%`);

    return (
        <>
            <TableRow className="cursor-pointer bg-[#f9fafb]" data-testid={`costs-category-${c.key}`} onClick={() => onOpen(c.label, null)}>
                <TableCell className="font-bold">{c.label}</TableCell>
                <Money value={c.current} money={m} strong />
                <TableCell className="text-end tabular-nums font-bold">{share(c.share)}</TableCell>
                <Money value={c.previous} money={m} strong />
                <Money value={c.delta} money={m} strong signed />
                <TableCell className="text-end tabular-nums font-bold">{pctText(c.change_pct)}</TableCell>
            </TableRow>
            {c.rows.map((r) => (
                <TableRow
                    key={`${c.key}-${r.account_id}`}
                    className="cursor-pointer"
                    data-testid="costs-account"
                    onClick={() => onOpen(`${c.label} — ${r.account}`, r.account_id)}
                >
                    <TableCell className="ps-8">
                        <span className="me-2 text-[11px] text-[#9ca3af]" dir="ltr">{r.code}</span>
                        <span className="underline decoration-dotted underline-offset-4">{r.account}</span>
                        <span className="sr-only">{t('اعرض السطور')}</span>
                    </TableCell>
                    <Money value={r.current} money={m} />
                    <TableCell className="text-end tabular-nums">{share(r.share)}</TableCell>
                    <Money value={r.previous} money={m} />
                    <Money value={r.delta} money={m} signed />
                    <TableCell className="text-end tabular-nums">{pctText(r.change_pct)}</TableCell>
                </TableRow>
            ))}
        </>
    );
}

/** سطورُ الصفّ — من الخادم صفحةً صفحة، ومجموعُها كلُّها معها */
function DrillDialog({
    drill,
    filters,
    money: m,
    onClose,
}: {
    drill: { title: string; category: string; accountId: number | null } | null;
    filters: Record<string, string | null>;
    money: (v: number) => string;
    onClose: () => void;
}) {
    const t = useTranslate();
    const [data, setData] = useState<Drill | null>(null);
    const [loading, setLoading] = useState(false);
    const [failed, setFailed] = useState(false);

    const load = async (page: number) => {
        if (!drill) return;
        setLoading(true);
        setFailed(false);
        try {
            const params: Record<string, string> = { category: drill.category, page: String(page) };
            for (const key of ['from', 'to', 'branch_id'] as const) {
                if (filters[key]) params[key] = filters[key] as string;
            }
            if (drill.accountId !== null) params.account_id = String(drill.accountId);
            const res = await fetch(route('admin.reports.costs.lines', params), {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!res.ok) throw new Error(String(res.status));
            setData((await res.json()) as Drill);
        } catch {
            setFailed(true);
        } finally {
            setLoading(false);
        }
    };

    // صفٌّ جديد يُفتح من صفحته الأولى — وسطورُ الصفّ السابق لا تبقى تحته
    useEffect(() => {
        setData(null);
        if (drill) void load(1);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [drill]);

    return (
        <Dialog open={drill !== null} onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-4xl">
                <DialogHeader>
                    <DialogTitle>{drill?.title}</DialogTitle>
                </DialogHeader>
                {failed && <p className="text-[13px] text-[#b91c1c]">{t('تعذّر تحميل السطور — حاول مرّة أخرى.')}</p>}
                {data && (
                    <div data-testid="costs-drill">
                        <p className="mb-2 text-[12.5px] text-[#374151]">
                            {t('مجموع السطور')}: <strong className="tabular-nums">{m(data.total)}</strong> · {t(':count سطرًا', { count: String(data.count) })}
                        </p>
                        <div className="max-h-[60vh] overflow-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow className="hover:bg-transparent">
                                        <TableHead>{t('التاريخ')}</TableHead>
                                        <TableHead>{t('القيد')}</TableHead>
                                        <TableHead>{t('البيان')}</TableHead>
                                        <TableHead>{t('الفرع')}</TableHead>
                                        <TableHead>{t('الحساب')}</TableHead>
                                        <TableHead>{t('المصدر')}</TableHead>
                                        <TableHead className="text-end">{t('الصافي')}</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {data.lines.length === 0 ? (
                                        <TableEmpty colSpan={7}>{t('لا سطور')}</TableEmpty>
                                    ) : (
                                        data.lines.map((l) => (
                                            <TableRow key={l.id}>
                                                <TableCell dir="ltr" className="whitespace-nowrap tabular-nums">{l.date}</TableCell>
                                                <TableCell dir="ltr" className="whitespace-nowrap">{l.number}</TableCell>
                                                <TableCell>
                                                    {l.description}
                                                    {l.memo && <span className="block text-[11px] text-[#9ca3af]">{l.memo}</span>}
                                                </TableCell>
                                                <TableCell>{l.branch ?? t('النشاط بالكامل')}</TableCell>
                                                <TableCell>{l.account}</TableCell>
                                                <TableCell>
                                                    {l.source}
                                                    {l.reference && <span className="block text-[11px] text-[#9ca3af]" dir="ltr">{l.reference}</span>}
                                                </TableCell>
                                                <Money value={l.net} money={m} />
                                            </TableRow>
                                        ))
                                    )}
                                </TableBody>
                            </Table>
                        </div>
                        {data.last_page > 1 && (
                            <div className="mt-3 flex items-center justify-between text-[12.5px]">
                                <button type="button" className="rounded border px-3 py-1 disabled:opacity-40" disabled={loading || data.page <= 1} onClick={() => load(data.page - 1)}>
                                    {t('السابق')}
                                </button>
                                <span className="tabular-nums">
                                    {data.page} / {data.last_page}
                                </span>
                                <button type="button" className="rounded border px-3 py-1 disabled:opacity-40" disabled={loading || data.page >= data.last_page} onClick={() => load(data.page + 1)}>
                                    {t('التالي')}
                                </button>
                            </div>
                        )}
                    </div>
                )}
                {loading && !data && <p className="text-[13px] text-[#6b7280]">{t('جارٍ التحميل…')}</p>}
            </DialogContent>
        </Dialog>
    );
}
