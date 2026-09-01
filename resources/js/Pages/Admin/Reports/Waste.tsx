import { router, usePage } from '@inertiajs/react';
import { AlertTriangle, Info, Lightbulb, TrendingDown, TrendingUp } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import BackToReports from '@/Components/BackToReports';
import ExportMenu from '@/Components/ExportMenu';
import StatCard from '@/Components/StatCard';
import BarChart from '@/Components/charts/BarChart';
import { Select } from '@/Components/Field';
import { Badge } from '@/Components/ui/badge';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { money, number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

interface Slice {
    label: string;
    quantity: number;
    value: number;
}

interface RateRow {
    label: string;
    waste: number;
    consumed: number;
    rate: number;
    value: number;
}

interface Insight {
    text: string;
    tone: string;
}

interface Suspicious {
    id: number;
    number: string;
    product: string;
    reason: string;
    delta: number;
    at: string | null;
}

interface Option {
    value: number | string;
    label: string;
}

interface Props {
    totals: { count: number; quantity: number; value: number };
    previous: { count: number; quantity: number; value: number };
    change: number | null;
    byProduct: Slice[];
    byCategory: Slice[];
    byBranch: Slice[];
    byReason: Slice[];
    overTime: { label: string; value: number; quantity: number }[];
    versusConsumption: RateRow[];
    insights: Insight[];
    suspicious: Suspicious[];
    filters: Record<string, string | null>;
    options: {
        branches: Option[];
        categories: Option[];
        products: Option[];
        reasons: string[];
    };
}

const TONE: Record<string, string> = {
    warning: 'border-[#fed7aa] bg-[#fff7ed] text-[#9a3412]',
    good: 'border-[#bbf7d0] bg-[#f0fdf4] text-[#166534]',
    info: 'border-[#e5e7eb] bg-[#fafafa] text-[#4b4b4b]',
};

/**
 * تحليلات الهالك — قراءةٌ فوق تعديلات المخزون، لا سجلٌّ ثانٍ لها.
 *
 * الصفوف نفسها التي تكتبها شاشة «سجل المخزون» تُقرأ هنا على ستّة
 * أبعاد. ولا تُكتب من هنا شيء: من أراد تسجيل تلفٍ فمن بابه.
 */
export default function WasteAnalytics() {
    const {
        totals, previous, change, byProduct, byCategory, byBranch, byReason,
        overTime, versusConsumption, insights, suspicious, filters, options, context,
    } = usePage<PageProps<Props>>().props;

    const t = useTranslate();
    const m = (v: number) => money(v, context!.currency);

    /** كل تغييرٍ في مرشّح يعيد تحميل الصفحة بالفترة نفسها — لا حساب في المتصفّح */
    const go = (patch: Record<string, string>) =>
        router.get(route('admin.reports.waste'), { ...filters, ...patch }, {
            preserveState: true, preserveScroll: true, replace: true,
        });

    const rising = (change ?? 0) > 0;

    return (
        <AdminLayout title={t('تحليلات الهالك')}>
            <div className="no-print">
                <BackToReports />
            </div>

            <PageHeader
                title="تحليلات الهالك"
                subtitle="ما تلف وما فُقد: قيمته واتجاهه، وأيّ صنفٍ وفرعٍ يبتلعه."
                actions={
                    /* الملفّ يحمل المرشّحات المعروضة — `withFilters` تُلحق سلسلة الاستعلام */
                    <ExportMenu
                        xlsx={route('admin.reports.export.xlsx', 'waste')}
                        pdf={route('admin.reports.export.pdf', 'waste')}
                        csv={route('admin.reports.export.csv', 'waste')}
                    />
                }
            />

            {/* الرجوع فوق الترويسة: الباب واحد وإليه يُرجع بضغطة */}

            {/* المرشّحات — بنفس عناصر بقية الشاشات، لا منتقي تواريخ جديد */}
            <Card className="mb-6 grid grid-cols-1 gap-3 p-4 sm:grid-cols-3 lg:grid-cols-6">
                <label className="flex flex-col gap-1">
                    <span className="text-[12px] text-[#6b7280]">{t('من')}</span>
                    <Input
                        type="date"
                        dir="ltr"
                        value={filters.from ?? ''}
                        onChange={(e) => go({ from: e.target.value })}
                    />
                </label>
                <label className="flex flex-col gap-1">
                    <span className="text-[12px] text-[#6b7280]">{t('إلى')}</span>
                    <Input
                        type="date"
                        dir="ltr"
                        value={filters.to ?? ''}
                        onChange={(e) => go({ to: e.target.value })}
                    />
                </label>
                <label className="flex flex-col gap-1">
                    <span className="text-[12px] text-[#6b7280]">{t('الفرع')}</span>
                    <Select
                        placeholder="كل الفروع"
                        value={filters.branch_id ?? ''}
                        onChange={(e) => go({ branch_id: e.target.value })}
                        options={options.branches}
                    />
                </label>
                <label className="flex flex-col gap-1">
                    <span className="text-[12px] text-[#6b7280]">{t('القسم')}</span>
                    <Select
                        placeholder="كل الأقسام"
                        value={filters.category_id ?? ''}
                        onChange={(e) => go({ category_id: e.target.value })}
                        options={options.categories}
                    />
                </label>
                <label className="flex flex-col gap-1">
                    <span className="text-[12px] text-[#6b7280]">{t('المنتج')}</span>
                    <Select
                        placeholder="كل المنتجات"
                        value={filters.product_id ?? ''}
                        onChange={(e) => go({ product_id: e.target.value })}
                        options={options.products}
                    />
                </label>
                <label className="flex flex-col gap-1">
                    <span className="text-[12px] text-[#6b7280]">{t('السبب')}</span>
                    <Select
                        placeholder="كل الأسباب"
                        value={filters.reason ?? ''}
                        onChange={(e) => go({ reason: e.target.value })}
                        options={options.reasons.map((r) => ({ value: r, label: t(r) }))}
                    />
                </label>
            </Card>

            <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard
                    stat={{
                        label: t('قيمة الهالك'),
                        value: m(totals.value),
                        icon: 'alert-triangle',
                        color: 'danger',
                        trend: change === null ? undefined : `${change > 0 ? '+' : ''}${number(change)}%`,
                        up: rising,
                    }}
                />
                <StatCard
                    stat={{
                        label: t('الكمية الهالكة'),
                        value: number(totals.quantity),
                        icon: 'package',
                        color: 'warning',
                    }}
                />
                <StatCard
                    stat={{ label: t('عدد التسجيلات'), value: number(totals.count), icon: 'clipboard-list', color: 'secondary' }}
                />
                <StatCard
                    stat={{
                        label: t('المدّة السابقة'),
                        value: m(previous.value),
                        icon: 'clock',
                        color: 'primary',
                    }}
                />
            </div>

            {/* الملاحظات أوّلًا: الأرقام تُقرأ، والجملة تُفهم */}
            {insights.length > 0 && (
                <Card className="mb-6 p-5">
                    <h3 className="mb-3 flex items-center gap-2 text-[15px] font-bold text-[#111]">
                        <Lightbulb className="size-4 text-[#d97706]" />
                        {t('ملاحظات')}
                    </h3>
                    <ul className="space-y-2">
                        {insights.map((i, idx) => (
                            <li
                                key={idx}
                                className={cn(
                                    'flex items-start gap-2 rounded-[10px] border p-3 text-[13px] leading-relaxed',
                                    TONE[i.tone] ?? TONE.info,
                                )}
                            >
                                {i.tone === 'good' ? (
                                    <TrendingDown className="mt-px size-4 shrink-0" />
                                ) : i.tone === 'info' ? (
                                    <Info className="mt-px size-4 shrink-0" />
                                ) : (
                                    <TrendingUp className="mt-px size-4 shrink-0" />
                                )}
                                <span>{i.text}</span>
                            </li>
                        ))}
                    </ul>
                </Card>
            )}

            <div className="mb-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
                <Card className="p-5">
                    <h3 className="mb-4 text-[15px] font-bold text-[#111]">{t('الهالك شهرًا بشهر')}</h3>
                    <BarChart
                        labels={overTime.map((r) => r.label)}
                        series={overTime.map((r) => r.value)}
                        format={m}
                    />
                </Card>

                <Card className="p-5">
                    <h3 className="mb-4 text-[15px] font-bold text-[#111]">{t('الهالك حسب المنتج')}</h3>
                    <BarChart
                        labels={byProduct.slice(0, 8).map((r) => r.label)}
                        series={byProduct.slice(0, 8).map((r) => r.value)}
                        format={m}
                    />
                </Card>

                <Card className="p-5">
                    <h3 className="mb-4 text-[15px] font-bold text-[#111]">{t('الهالك حسب القسم')}</h3>
                    <BarChart
                        labels={byCategory.map((r) => r.label)}
                        series={byCategory.map((r) => r.value)}
                        format={m}
                    />
                </Card>

                <Card className="p-5">
                    <h3 className="mb-4 text-[15px] font-bold text-[#111]">{t('الهالك حسب الفرع')}</h3>
                    <BarChart
                        labels={byBranch.map((r) => r.label)}
                        series={byBranch.map((r) => r.value)}
                        format={m}
                    />
                </Card>
            </div>

            <div className="mb-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
                <Card className="p-5">
                    <h3 className="mb-4 text-[15px] font-bold text-[#111]">{t('الهالك حسب السبب')}</h3>
                    <BarChart
                        labels={byReason.map((r) => t(r.label))}
                        series={byReason.map((r) => r.value)}
                        format={m}
                    />
                </Card>

                {/*
                    نسبة الهالك إلى الاستهلاك — المقارنة الوحيدة الصادقة بعد
                    الوصفات: بيعُ باقةٍ ليس بيعَ وردة، فالمقام هو ما استُهلك
                    من الصنف نفسه لا عدد الباقات المباعة.
                */}
                <Card className="p-5">
                    <h3 className="mb-1 text-[15px] font-bold text-[#111]">{t('الهالك مقارنةً بالاستهلاك')}</h3>
                    <p className="mb-4 text-[12px] text-[#9ca3af]">
                        {t('لأصناف الوصفات — ما استُهلك داخل الباقات مقابل ما هلك منه.')}
                    </p>
                    {versusConsumption.length === 0 ? (
                        <p className="py-8 text-center text-[13px] text-[#9ca3af]">
                            {t('لا توجد بيانات كافية بعد')}
                        </p>
                    ) : (
                        <ul className="space-y-2">
                            {versusConsumption.map((r) => (
                                <li
                                    key={r.label}
                                    className="flex items-center justify-between gap-3 rounded-[10px] bg-[#fafafa] px-3 py-2 text-[13px]"
                                >
                                    <span className="font-medium text-[#111]">{r.label}</span>
                                    <span className="text-[#6b7280] tabular-nums">
                                        {number(r.waste)} / {number(r.consumed)}
                                    </span>
                                    <Badge variant={r.rate >= 10 ? 'danger' : 'neutral'}>{number(r.rate)}%</Badge>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
            </div>

            {/*
                صفوفٌ قديمة تخالف القاعدة: سببُ هالكٍ بفرقٍ موجب. تُعرض ولا
                تُصلَح — لا نعرف أكانت خطأ إدخالٍ أم عكسَ قيدٍ مقصودًا،
                والتخمين يفسد سجلًّا قد يكون له تفسير.
            */}
            {suspicious.length > 0 && (
                <Card className="p-5">
                    <h3 className="mb-1 flex items-center gap-2 text-[15px] font-bold text-[#111]">
                        <AlertTriangle className="size-4 text-[#b45309]" />
                        {t('تسجيلات تحتاج مراجعة')}
                    </h3>
                    <p className="mb-4 text-[13px] text-[#6b7280]">
                        {t('سبب هالكٍ بكمية موجبة — سُجّلت قبل أن يمنع النظام ذلك. لم تُغيَّر، وهي خارج الأرقام أعلاه.')}
                    </p>
                    <ul className="space-y-2">
                        {suspicious.map((s) => (
                            <li
                                key={s.id}
                                className="flex flex-wrap items-center justify-between gap-2 rounded-[10px] border border-[#fed7aa] bg-[#fff7ed] px-3 py-2 text-[13px]"
                            >
                                <span className="font-mono text-[12px] text-[#9a3412]">{s.number}</span>
                                <span className="font-medium text-[#111]">{s.product}</span>
                                <Badge>{t(s.reason)}</Badge>
                                <span className="font-semibold text-[#047857] tabular-nums">+{number(s.delta)}</span>
                                <span className="text-[12px] text-[#9ca3af]" dir="ltr">
                                    {s.at}
                                </span>
                            </li>
                        ))}
                    </ul>
                </Card>
            )}
        </AdminLayout>
    );
}
