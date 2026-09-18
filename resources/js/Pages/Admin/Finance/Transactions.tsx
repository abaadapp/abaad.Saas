import { useEffect, useState } from 'react';
import { usePage } from '@inertiajs/react';
import { ArrowRight, Check, FileText, Plus } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { FINANCE_TABS } from '@/Components/SectionTabs';
import ExportMenu from '@/Components/ExportMenu';
import MovementForm, { type Movement } from '@/Components/MovementForm';
import DataTable, { type Column, type Filter, type ServerPagination } from '@/Components/DataTable';
import DocumentPanel, { DocumentAside } from '@/Components/DocumentPanel';
import StatCard from '@/Components/StatCard';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { money } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

interface Row {
    id: number;
    reference: string;
    date: string | null;
    description: string | null;
    method: string;
    /** اتّجاه المال: دخل · مصروف · تحويل */
    type: string;
    kind: string | null;
    kind_label: string;
    amount: number;
    employee: string | null;
    /** هل له قيدٌ في دفتر الأستاذ؟ */
    posted: boolean;
    /** بيعةٌ أُلغيت فاتورتها — تبقى في السجلّ ولا تُجمع */
    cancelled: boolean;
    /** رقمُ فاتورتها إن كانت لها فاتورة — وبه يصير المرجع بابًا */
    invoice: string | null;
}

/** الورقةُ كما يردّها الخادم — تُطلب عند فتحها لا مع الصفحة */
interface Paper {
    html: string;
    size: string;
    number: string;
    url: string;
}

interface Props {
    rows: Row[];
    pagination: ServerPagination;
    filters: Record<string, string | null>;
    sorts: string[];
    movements: Movement[];
    kinds: { value: string; label: string }[];
    /** أنواع المصروفات كما عرّفها التاجر — اختياريّة في النموذج */
    expenseTypes: string[];
    summary: { in: number; out: number; transfers: number };
    today: string;
}

/** لون الصف حسب اتّجاه المال — والتحويل رماديّ: لا دخل ولا خرج */
const TONE: Record<string, string> = {
    دخل: 'text-[#15803d]',
    مصروف: 'text-[#b91c1c]',
};

const SIGN: Record<string, string> = { دخل: '+', مصروف: '−' };

/**
 * الحركة المالية — ماذا دخل وماذا خرج، وبابُ تسجيل ما لا مستند له.
 *
 * والنموذج يسأل «ماذا حدث؟» لا «مدين أم دائن؟». التاجر يعرف أنه دفع إيجارًا
 * أو حوّل من الدرج إلى البنك؛ ولا يعرف — ولا يلزمه أن يعرف — أنّ الأوّل
 * يُقيَّد مدينَ مصروفاتٍ دائنَ صندوق. فالوصفة في الخادم (`Books::MOVEMENTS`)
 * ولا تُرسَل إلى هنا: شاشةٌ تعرف الحسابات تُغري بأن تجعل التاجر يختار منها.
 */
export default function Transactions() {
    const { rows, pagination, filters, sorts, movements, kinds, expenseTypes, summary, today, context } =
        usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const m = (v: number) => money(v, context!.currency);

    const [adding, setAdding] = useState(false);

    /*
     * الورقةُ إلى جانب الجدول — لا تخرج بالتاجر من صفحته.
     *
     * كان المرجعُ نصًّا لا يُنقر، فمن أراد ورقةَ حركةٍ خرج إلى الطلبات وبحث
     * برقمها، ثمّ عاد فوجد الجدولَ على صفحته الأولى بلا فلترته. فصارت تُفتح
     * هنا، ويعود منها بزرٍّ يعرفه — لا بزرِّ رجوع المتصفّح.
     *
     * و`viewing` معرّفُ الحركة لا الورقة: بالنقر يُعرَض الإطارُ فورًا وفيه
     * «يُحمَّل…»، فلا يُنقر زرٌّ ولا يحدث شيءٌ حتى يصل الخادم.
     */
    const [viewing, setViewing] = useState<Row | null>(null);
    const [paper, setPaper] = useState<Paper | null>(null);
    const [paperError, setPaperError] = useState<string | null>(null);
    const [previewing, setPreviewing] = useState(false);

    useEffect(() => {
        if (!viewing) return;

        let alive = true;
        setPaper(null);
        setPaperError(null);

        fetch(route('admin.finance.transactionPaper', viewing.id), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((r) => (r.ok ? r.json() : Promise.reject(new Error(String(r.status)))))
            .then((data: Paper) => {
                if (alive) setPaper(data);
            })
            .catch(() => {
                if (alive) setPaperError('تعذّر فتح الورقة — أعد المحاولة.');
            });

        return () => {
            alive = false;
        };
    }, [viewing?.id]);

    const close = () => {
        setViewing(null);
        setPaper(null);
        setPaperError(null);
    };

    const columns: Column<Row>[] = [
        {
            key: 'reference',
            header: 'المرجع',
            sortable: true,
            /*
             * والمرجعُ بابٌ حين يكون خلفه ورقة.
             *
             * ومصروفٌ أو تحويلٌ لا فاتورةَ له فيبقى نصًّا: مقبضٌ لا يُدير
             * شيئًا أسوأ من غياب المقبض.
             */
            cell: (r) =>
                r.invoice ? (
                    <button
                        type="button"
                        onClick={() => setViewing(r)}
                        title={t('عرض الفاتورة')}
                        className={cn(
                            'inline-flex items-center gap-1.5 rounded font-mono text-[13px] underline-offset-4 hover:underline',
                            viewing?.id === r.id ? 'font-semibold text-[#111]' : 'text-[#1d4ed8]',
                        )}
                    >
                        <FileText className="size-3.5 shrink-0" />
                        {r.reference}
                    </button>
                ) : (
                    <span className="font-mono text-[13px] text-[#6b7280]">{r.reference}</span>
                ),
        },
        {
            key: 'date',
            header: 'التاريخ',
            sortable: true,
            cell: (r) => (
                <span dir="ltr" className="text-[#6b7280]">
                    {r.date ?? '—'}
                </span>
            ),
        },
        {
            key: 'description',
            header: 'البيان',
            sortable: true,
            cell: (r) => <span className="text-[#111]">{r.description || '—'}</span>,
        },
        {
            key: 'kind',
            header: 'ماذا حدث',
            cell: (r) => (
                <span className="flex flex-wrap items-center gap-1.5">
                    <Badge
                        variant={
                            r.cancelled
                                ? 'neutral'
                                : r.type === 'دخل'
                                  ? 'success'
                                  : r.type === 'مصروف'
                                    ? 'danger'
                                    : 'neutral'
                        }
                    >
                        {r.kind_label}
                    </Badge>
                    {/* الملغاة تُوسم ولا تُحذف: خرجت من المجموع وبقيت في السجلّ */}
                    {r.cancelled && <Badge variant="warning">{t('ملغاة')}</Badge>}
                </span>
            ),
        },
        {
            key: 'method',
            header: 'الوسيلة',
            cell: (r) => <span className="text-[#6b7280]">{t(r.method)}</span>,
        },
        {
            key: 'amount',
            header: 'المبلغ',
            align: 'end',
            sortable: true,
            cell: (r) => (
                <span
                    className={cn(
                        'font-semibold tabular-nums',
                        r.cancelled ? 'text-[#9ca3af] line-through' : (TONE[r.type] ?? 'text-[#6b7280]'),
                    )}
                >
                    {r.cancelled ? '' : (SIGN[r.type] ?? '')}
                    {m(r.amount)}
                </span>
            ),
        },
        {
            key: 'posted',
            header: 'الدفتر',
            align: 'center',
            cell: (r) =>
                r.posted ? (
                    <Check className="mx-auto size-4 text-[#15803d]" />
                ) : (
                    /* حركةٌ قديمة سبقت الترحيل التلقائي — تُقال ولا تُخفى */
                    <span className="text-[12px] text-[#9ca3af]">{t('غير مُرحَّلة')}</span>
                ),
        },
    ];

    const tableFilters: Filter<Row>[] = [
        {
            label: 'نوع الحركة',
            param: 'kind',
            asTabs: kinds.length > 1 && kinds.length <= 6,
            options: kinds.map((k) => ({ label: k.label, value: k.value })),
        },
        { label: 'من تاريخ', type: 'date', param: 'from' },
        { label: 'إلى تاريخ', type: 'date', param: 'to' },
    ];

    return (
        <AdminLayout title="الحركة المالية">
            <PageHeader
                title="الحركة المالية"
                subtitle={t('كلّ ما دخل وما خرج — ولكلّ حركةٍ قيدُها في الدفتر')}
                actions={
                    <div className="flex flex-wrap items-center gap-2">
                        <ExportMenu
                            label="تصدير"
                            xlsx={route('admin.finance.xlsx')}
                            pdf={route('admin.finance.pdf')}
                            csv={route('admin.export.transactions')}
                        />
                        <Button onClick={() => setAdding(true)}>
                            <Plus />
                            {t('تسجيل حركة')}
                        </Button>
                    </div>
                }
            />

            <SectionTabs tabs={FINANCE_TABS} current="admin.finance.transactions" />

            <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
                <StatCard
                    stat={{
                        label: t('دخل'),
                        value: m(summary.in),
                        icon: 'trending-up',
                        color: 'success',
                    }}
                    index={0}
                />
                <StatCard
                    stat={{
                        label: t('خرج'),
                        value: m(summary.out),
                        icon: 'arrow-down-circle',
                        color: 'danger',
                    }}
                    index={1}
                />
                {/* التحويل بطاقةٌ ثالثة لا يُجمع في الأوليين: مالٌ انتقل ولم يدخل ولم يخرج */}
                <StatCard
                    stat={{
                        label: t('تحويلات بين الصندوق والبنك'),
                        value: m(summary.transfers),
                        icon: 'refresh-cw',
                        color: 'info',
                    }}
                    index={2}
                />
            </div>

            {/*
                والجدولُ يضيق ولا يُستبدل.
                الورقةُ تُفتح إلى جانبه فيبقى السطرُ المفتوح في مكانه، وفلترةُ
                التاجر وصفحتُه كما تركها. وعلى الشاشة الضيّقة يعلوها الجدول —
                عمودان لا يتّسعان لهاتف.
            */}
            <div
                className={cn(
                    viewing && 'grid grid-cols-1 items-start gap-6 xl:grid-cols-5',
                )}
            >
                <div className={cn('min-w-0', viewing && 'xl:col-span-3')}>
                    <DataTable
                        rows={rows}
                        columns={columns}
                        rowKey={(r) => r.id}
                        filters={tableFilters}
                        searchPlaceholder="ابحث بالمرجع أو البيان…"
                        empty="لا حركة في هذه المدة"
                        server={{ pagination, params: filters, sorts }}
                    />
                </div>

                {viewing && (
                    <DocumentAside className="xl:col-span-2">
                        {/*
                            وزرُّ الرجوع فوق الورقة لا تحتها: هو أوّلُ ما تقع
                            عليه العين حين تُفتح، ولا يُبحث عنه بعد تمريرة.
                        */}
                        <div className="mb-3 flex items-center justify-between gap-2">
                            <Button variant="outline" size="sm" onClick={close}>
                                <ArrowRight />
                                {t('رجوع إلى الحركة المالية')}
                            </Button>
                            <span className="truncate font-mono text-[13px] text-[#6b7280]">
                                {viewing.invoice}
                            </span>
                        </div>

                        {paper ? (
                            <DocumentPanel
                                html={paper.html}
                                size={paper.size}
                                url={paper.url}
                                filename={`${paper.number}.pdf`}
                                label={`${t('الفاتورة')} ${paper.number}`}
                                open={previewing}
                                onOpenChange={setPreviewing}
                            />
                        ) : (
                            <Card className="p-6 text-center text-[13px] text-[#6b7280]">
                                {t(paperError ?? 'يُحمَّل…')}
                            </Card>
                        )}
                    </DocumentAside>
                )}
            </div>

            <Dialog open={adding} onOpenChange={(o) => !o && setAdding(false)}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{t('ماذا حدث؟')}</DialogTitle>
                    </DialogHeader>

                    <MovementForm
                        movements={movements}
                        expenseTypes={expenseTypes}
                        today={today}
                        onCancel={() => setAdding(false)}
                        onSuccess={() => setAdding(false)}
                    />
                </DialogContent>
            </Dialog>
        </AdminLayout>
    );
}
