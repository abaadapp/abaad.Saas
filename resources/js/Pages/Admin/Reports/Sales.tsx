import { router, usePage } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import BackToReports from '@/Components/BackToReports';
import ExportMenu from '@/Components/ExportMenu';
import StatCard from '@/Components/StatCard';
import AreaChart from '@/Components/charts/AreaChart';
import BarChart from '@/Components/charts/BarChart';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@/Components/ui/table';
import useLiveFeed from '@/hooks/useLiveFeed';
import RangeTabs, { type ReportRange } from '@/Components/RangeTabs';
import { money, number } from '@/lib/format';
import { cn } from '@/lib/utils';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface Summary {
    sales: number;
    /** ثمنُ الإضافات — جزءٌ من `sales` لا فوقه (انظر `AddonSales`) */
    addons?: number;
    cogs: number;
    profit: number;
    /** «صافٍ» للمتجر كلِّه، و«مُجمل» لقناةٍ بعينها — يسمّيه الخادم */
    profit_kind: 'net' | 'gross';
    expenses: number;
    tax: number;
    products: number;
    inventory_alerts: number;
    employees: number;
    customers: number;
    payment_methods: number;
}

interface TopProduct {
    name: string;
    cat: string;
    sold: number;
    revenue: number;
    pct: string;
}

/** أرقامُ نطاق البوتيك — من بنوده وحدها. انظر `Boutiques::soldTotals` */
interface BoutiqueTotals {
    gross: number;
    commission: number;
    net: number;
    quantity: number;
    orders: number;
    lines: number;
}

/** النطاقُ المختار — `null` لكلّ المبيعات */
type BoutiqueScope = { kind: 'own'; id: null; name: null } | { kind: 'boutique'; id: number; name: string } | null;

interface Props {
    /** `null` بنطاق بوتيك: أرقامُ الطلب كاملًا لا تُنسب إلى بند */
    summary: Summary | null;
    salesSeries: { labels: string[]; full: string[]; data: (number | null)[]; counts: (number | null)[]; range: ReportRange };
    range: ReportRange;
    /** `null` بنطاق بوتيك: الدفعُ على الطلب كاملًا */
    paymentDistribution: { labels: string[]; series: number[] } | null;
    topSellingProducts: TopProduct[];
    boutiqueTotals: BoutiqueTotals | null;
    boutique: BoutiqueScope;
    /** بوتيكاتُ المتجر — فارغةٌ لمن لا بوتيكَ عنده فلا يُرسم المُرشِّح */
    boutiques: { value: number; label: string; active: boolean }[];
    /** القناةُ المختارة — أو null للمتجر كلِّه */
    channel: string | null;
    /** القنوات من مصدرها الواحد — انظر App\Support\SalesChannel */
    channels: { value: string; label: string }[];
}

/** عنوان المخطّط بحسب دقّة محوره — انظر Demo::salesTrend */
const CHART_TITLE: Record<ReportRange, string> = {
    today: 'مبيعات اليوم حسب الساعة',
    week: 'مبيعات الأسبوع حسب اليوم',
    month: 'مبيعات الشهر حسب اليوم',
    year: 'مبيعات السنة حسب الشهر',
    all: 'المبيعات في آخر ١٢ شهرًا',
};

/**
 * ملخّص المبيعات — كان هو صفحة «التقارير» كلّها قبل أن يصير الفهرس بابها.
 * لم يتغيّر من محتواه شيء: تغيّر مكانه فقط، وصار بطاقةً في الفهرس تقود إليه.
 */
export default function ReportsSales() {
    const { context, ...server } = usePage<PageProps<Props>>().props;

    /* التقرير يُحتسب لحظة الفتح ثم يتجمّد. صفحة تُترك مفتوحة على مكتب التاجر
       كانت تعرض أرقام الصباح بعد يوم بيع كامل — وعليها يُبنى قرار. */
    /* الفترة تُمرَّر إلى التغذية أيضًا، وإلا انقلبت أرقام «اليوم» إلى أرقام
       الشهر بعد أوّل تحديث تلقائيّ بلا أن يلمس التاجر شيئًا */
    /*
     * والقناةُ تُمرَّر إلى التغذية كما تُمرَّر الفترة — وإلّا انقلبت أرقامُ
     * الموقع إلى أرقام المتجر كلِّه بعد أوّل تحديثٍ تلقائيّ بلا أن يلمس
     * التاجر شيئًا. وهو العطبُ نفسُه المكتوب فوقه في الفترة.
     */
    /*
     * والبوتيكُ كالقناة: يُحمل في كلّ رابطٍ يخرج من الشاشة — التغذيةِ
     * والفترةِ والقناةِ والملفّاتِ الثلاثة. وإلّا انقلبت أرقامُ بوتيكٍ إلى
     * أرقام المتجر كلِّه بعد أوّل تحديثٍ أو أوّل ضغطة.
     */
    const boutiques = server.boutiques ?? [];
    const boutiqueParam = server.boutique
        ? server.boutique.kind === 'own'
            ? 'own'
            : String(server.boutique.id)
        : undefined;
    const params = { range: server.range, channel: server.channel ?? undefined, boutique: boutiqueParam };

    const { data: live, updatedAt } = useLiveFeed<Props>(route('admin.reports.feed', params));
    const { summary, salesSeries, paymentDistribution, topSellingProducts, boutiqueTotals } = live ?? server;

    const t = useTranslate();
    const currency = context!.currency;
    const m = (v: number) => money(v, currency);

    const whole = summary?.profit_kind === 'net';
    /** أنطاقُ بوتيكٍ هذا؟ — أرقامُه من البنود، وما على الطلب كاملًا يسقط */
    const scoped = boutiqueTotals != null;

    /** والانتقالُ يحمل الفترةَ معه كما تحمل الفترةُ القناة — والبوتيكَ كذلك */
    const pickChannel = (next: string | null) => {
        if (next === server.channel) return;

        router.get(
            window.location.pathname,
            { range: server.range, ...(next ? { channel: next } : {}), ...(boutiqueParam ? { boutique: boutiqueParam } : {}) },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    /** واختيارُ البوتيك يحمل الفترةَ والقناة */
    const pickBoutique = (next: string | undefined) => {
        if (next === boutiqueParam) return;

        router.get(
            window.location.pathname,
            { range: server.range, ...(server.channel ? { channel: server.channel } : {}), ...(next ? { boutique: next } : {}) },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const chip = (on: boolean) =>
        cn(
            'rounded-full border px-4 py-1.5 text-[13px] transition',
            on ? 'border-[#111] bg-[#111] text-white' : 'border-[#e5e7eb] bg-white text-[#4b5563] hover:border-[#d1d5db]',
        );

    /*
     * ═══ وبطاقاتُ النطاق من بنوده ═══
     *
     * إجماليُّ بنود البوتيك، وعمولةُ المتجر بنسبة كلّ بندٍ ساعةَ بيعه،
     * والمستحقُّ له، والكميّة. ولا ضريبةَ ولا ربحَ ولا مصروفات: تلك على
     * الطلب كاملًا، ولا يُنسب منها إلى بندٍ شيءٌ بالظنّ.
     */
    const scopedStats = !boutiqueTotals
        ? []
        : server.boutique?.kind === 'own'
          ? [
                { label: t('مبيعات منتجات المتجر'), value: m(boutiqueTotals.gross), icon: 'wallet', color: 'primary' },
                { label: t('الكمية المباعة'), value: number(boutiqueTotals.quantity), icon: 'package', color: 'info' },
            ]
          : [
                { label: t('إجمالي مبيعات البوتيك'), value: m(boutiqueTotals.gross), icon: 'wallet', color: 'primary' },
                { label: t('عمولة المتجر'), value: m(boutiqueTotals.commission), icon: 'trending-up', color: 'success' },
                { label: t('المستحق للبوتيك'), value: m(boutiqueTotals.net), icon: 'arrow-down-circle', color: 'warning' },
                { label: t('الكمية المباعة'), value: number(boutiqueTotals.quantity), icon: 'package', color: 'info' },
            ];

    const stats = !summary ? scopedStats : [
        { label: t('إجمالي المبيعات'), value: m(summary.sales), icon: 'wallet', color: 'primary' },
        // التكلفة بجانب الربح لا في ورقةٍ أخرى: من يقرأ ربحًا يحتاج أن يرى ممّ طُرح
        { label: t('تكلفة البضاعة المباعة'), value: m(summary.cogs), icon: 'package', color: 'info' },
        {
            /*
             * والبطاقةُ تسمّي ما فيها: «صافي الربح» للمتجر كلِّه، و«مُجمل
             * الربح» لقناةٍ بعينها. والاسمُ من الخادم لا من هنا — وإلّا
             * افترق عن حسابه يومًا فقُرئ مُجملٌ باسم الصافي.
             */
            label: t(whole ? 'صافي الربح' : 'مُجمل الربح'),
            value: m(summary.profit),
            icon: summary.profit >= 0 ? 'trending-up' : 'trending-down',
            color: summary.profit >= 0 ? 'success' : 'danger',
        },
        /*
         * والمصروفاتُ تسقط من بطاقات القناة — لا تُعرض صفرًا.
         *
         * صفرٌ يُقرأ «لا مصروف على هذا الباب»، والحقُّ أنّها لا تُنسب إلى
         * بابٍ أصلًا: الإيجارُ والراتبُ والكهرباء على المتجر كلِّه.
         */
        ...(whole
            ? [{ label: t('المصروفات التشغيلية'), value: m(summary.expenses), icon: 'arrow-down-circle', color: 'warning' }]
            : []),
        { label: t('الضريبة المحصّلة'), value: m(summary.tax), icon: 'receipt', color: 'info' },
    ];

    // حالُ المتجر الآن لا حصيلةُ نطاق — فلا تُعرض بنطاق بوتيك
    const counters = !summary ? [] : [
        { label: 'المنتجات', value: summary.products },
        { label: 'تنبيهات المخزون', value: summary.inventory_alerts },
        { label: 'الموظفون', value: summary.employees },
        { label: 'العملاء', value: summary.customers },
        { label: 'وسائل الدفع', value: summary.payment_methods },
    ];


    return (
        <AdminLayout title="ملخّص المبيعات">
            <PageHeader
                title="ملخّص المبيعات"
                subtitle={t('ملخّص أداء المتجر: المبيعات والأرباح والمصروفات')}
                actions={
                    /* الملفّ يحمل الفترة المعروضة — لا فترته الخاصّة */
                    <ExportMenu
                        feature="reports_advanced"
                        xlsx={route('admin.reports.xlsx', params)}
                        pdf={route('admin.reports.pdf', params)}
                        csv={route('admin.export.reports', params)}
                    />
                }
            />

            {/* شريط أقسامٍ لتقريرٍ واحد لا معنى له — والعودة إلى الفهرس تُقال زرًّا */}
            {/* رجوعٌ واحد بشكلٍ واحد في التقارير كلّها */}
            <BackToReports />

            {updatedAt && (
                <p className="mb-3 text-[12px] text-[#9ca3af]">
                    {t('الأرقام محدّثة حتى')} <span dir="ltr">{updatedAt}</span>
                </p>
            )}

            {/* والفترةُ تحمل القناةَ معها — وإلّا فُقد المُرشِّحُ عند كلّ تبديل */}
            <RangeTabs current={server.range} params={{ channel: server.channel ?? undefined, boutique: boutiqueParam }} />

            {/*
                ═══ ومن أين جاءت البيعة ═══

                والسؤالُ «كم باع موقعي؟» كان يُجاب من شاشة الطلبات عددًا
                ومبلغًا، ولا يبلغ التقريرَ الذي فيه الربحُ والتكلفةُ والمنحنى.

                والقناةُ في الرابط لا في الجلسة، كالفترة: رابطٌ يُرسَل إلى
                المحاسب يفتح على ما فُتح عليه.
            */}
            <div className="mb-4 flex flex-wrap items-center gap-2" data-testid="channel-filter">
                <button
                    type="button"
                    onClick={() => pickChannel(null)}
                    className={chip(server.channel === null)}
                >
                    {t('كل القنوات')}
                </button>
                {server.channels.map((c) => (
                    <button
                        key={c.value}
                        type="button"
                        onClick={() => pickChannel(c.value)}
                        className={chip(server.channel === c.value)}
                        data-testid={`channel-${c.value}`}
                    >
                        {t(c.label)}
                    </button>
                ))}
            </div>

            {/*
                ═══ ولمن البيع: المحلُّ أم بوتيكٌ بعينه ═══

                لمن عنده بوتيكات وحده. والأرقامُ تحته من بنود البيع ولقطتها
                ساعةَ البيع — لا من صاحب الصنف اليوم.
            */}
            {boutiques.length > 0 && (
                <div className="mb-4 flex flex-wrap items-center gap-2" data-testid="boutique-filter">
                    <button type="button" onClick={() => pickBoutique(undefined)} className={chip(!boutiqueParam)}>
                        {t('كل المبيعات')}
                    </button>
                    <button
                        type="button"
                        onClick={() => pickBoutique('own')}
                        className={chip(boutiqueParam === 'own')}
                        data-testid="boutique-own"
                    >
                        {t('منتجات المتجر')}
                    </button>
                    {boutiques.map((b) => (
                        <button
                            key={b.value}
                            type="button"
                            onClick={() => pickBoutique(String(b.value))}
                            className={chip(boutiqueParam === String(b.value))}
                            data-testid={`boutique-${b.value}`}
                        >
                            {b.label}
                        </button>
                    ))}
                </div>
            )}

            {/*
                وما لا يُنسب إلى بندٍ يُقال — لا يُعرض رقمًا ولا يُترك يُقرأ ناقصًا.
            */}
            {scoped && (
                <p
                    data-testid="boutique-note"
                    className="mb-4 rounded-[10px] bg-[#eff6ff] px-3 py-2 text-[12px] leading-relaxed text-[#1d4ed8]"
                >
                    {t('أرقام بنود البيع وحدها بسعرها وقت البيع، والعمولة بنسبة البوتيك وقت البيع. الضريبة ووسائل الدفع وخصم الفاتورة والمصروفات وصافي الربح تُسجَّل على الطلب كاملًا فلا تُنسب لبند — لذلك لا تظهر هنا. والتسوية الفعلية من شاشة البوتيكات.')}
                </p>
            )}

            {/*
                وما لا يُنسب إلى قناةٍ يُقال — لا يُترك يُقرأ ناقصًا.

                المصروفاتُ وتكلفةُ الإضافات على المتجر كلِّه، فبطاقةُ الربح
                هنا مُجملٌ لا صافٍ. ومن قرأ «ربح» بلا هذا السطر قاس قناةً
                بمقياس متجرٍ كامل.
            */}
            {!whole && !scoped && (
                <p
                    data-testid="channel-note"
                    className="mb-4 rounded-[10px] bg-[#eff6ff] px-3 py-2 text-[12px] leading-relaxed text-[#1d4ed8]"
                >
                    {t('أرقام قناةٍ واحدة: الربح هنا مُجمل (المبيعات − الضريبة − تكلفة البضاعة). والمصروفات تُنفَق على المتجر كلّه فلا تُقسَم على القنوات.')}
                </p>
            )}

            <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                {stats.map((s, i) => (
                    <StatCard key={s.label} stat={s} index={i} />
                ))}
            </div>

            {/*
                «منها إضافات» سطرٌ تحت البطاقات لا بطاقةٌ بجانبها: بطاقةٌ
                سادسةٌ بجوار «إجمالي المبيعات» تُقرأ رقمًا آخر يُجمع عليه.
                وهي منه — دُفعت مع البند في الفاتورة نفسها.
            */}
            {summary && (summary.addons ?? 0) > 0 && (
                <p data-testid="sales-addons-note" className="-mt-3 mb-6 text-[12.5px] text-[#4b5563]">
                    {t('منها إضافات: :value — جزءٌ من إجمالي المبيعات لا مضافٌ إليه.', { value: m(summary.addons ?? 0) })}
                </p>
            )}

            <div className="mb-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
                {/*
                    لا مخطّط في فترة «الكل» بطلب المالك.
                    الأرقام والجداول تبقى — المحذوف هو المخطّط وحده، وفي هذه
                    الفترة وحدها. وبقيّة الفترات كما هي.
                */}
                {server.range !== 'all' && (
                    <Card className={cn(paymentDistribution ? 'lg:col-span-2' : 'lg:col-span-3')}>
                        <CardHeader>
                            {/* عنوان المخطّط يتبع دقّته: ساعات اليوم، أو أيّام الشهر، أو أشهر السنة */}
                            <CardTitle>{t(CHART_TITLE[server.range])}</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <AreaChart
                                labels={salesSeries.labels}
                                fullLabels={salesSeries.full}
                                counts={salesSeries.counts}
                                data={salesSeries.data}
                                format={m}
                            />
                        </CardContent>
                    </Card>
                )}

                {/* بلا المخطّط تأخذ البطاقة العرض كلّه بدل ثلثٍ وفراغين */}
                {/* وبنطاق بوتيكٍ لا بطاقةَ دفع: الدفعُ على الطلب كاملًا لا على بنده */}
                {paymentDistribution && (
                    <Card className={cn(server.range === 'all' && 'lg:col-span-3')}>
                        <CardHeader>
                            <CardTitle>{t('توزيع وسائل الدفع')}</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <BarChart
                                labels={paymentDistribution.labels.map((l) => t(l))}
                                series={paymentDistribution.series}
                                format={m}
                            />
                        </CardContent>
                    </Card>
                )}
            </div>


            {counters.length > 0 && (
            <div className="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
                {counters.map((c) => (
                    <Card key={c.label} className="p-4 text-center">
                        <p className="text-[22px] font-bold tabular-nums text-[#111]">{number(c.value)}</p>
                        <p className="mt-0.5 text-[12px] text-[#9ca3af]">{t(c.label)}</p>
                    </Card>
                ))}
            </div>
            )}

            <Card className="overflow-hidden">
                <div className="border-b border-[var(--ui-border,#e8e8e8)] px-5 py-4">
                    <h3 className="font-bold text-[#111]">{t('الأكثر مبيعًا')}</h3>
                </div>
                <Table>
                    <TableHeader>
                        <TableRow className="hover:bg-transparent">
                            <TableHead>{t('المنتج')}</TableHead>
                            <TableHead>{t('القسم')}</TableHead>
                            <TableHead className="text-end">{t('المُباع')}</TableHead>
                            <TableHead className="text-end">{t('الإيراد')}</TableHead>
                            <TableHead className="text-end">{t('النسبة')}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {topSellingProducts.length === 0 ? (
                            <TableEmpty colSpan={5}>{t('لا توجد مبيعات بعد')}</TableEmpty>
                        ) : (
                            topSellingProducts.map((p) => (
                                <TableRow key={p.name}>
                                    <TableCell className="font-medium text-[#111]">{p.name}</TableCell>
                                    <TableCell className="text-[#6b7280]">{p.cat}</TableCell>
                                    <TableCell className="text-end tabular-nums">{number(p.sold)}</TableCell>
                                    <TableCell className="text-end tabular-nums font-medium">{m(p.revenue)}</TableCell>
                                    <TableCell className="text-end tabular-nums text-[#6d28d9]">{p.pct}</TableCell>
                                </TableRow>
                            ))
                        )}
                    </TableBody>
                </Table>
            </Card>
        </AdminLayout>
    );
}
