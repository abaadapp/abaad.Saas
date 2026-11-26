import { useEffect, useState } from 'react';
import { ChevronLeft, ChevronRight, ExternalLink, FileText, Search } from 'lucide-react';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { Select } from '@/Components/Field';
import { money, number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { Currency } from '@/types';

/**
 * «مبيعات البوتيك وفواتيره» — كلُّ بيعٍ لبوتيكٍ بفاتورته الأصليّة.
 *
 * حالتان لا تُجمعان في رقمٍ واحد: صنفُ البوتيك يُباع منفردًا (له إجماليٌّ
 * وعمولةٌ للمتجر وصافٍ للبوتيك)، وقطعةُ البوتيك تدخل منتجَ المتجر مكوّنًا أو
 * إضافة (للبوتيك ثمنُها لقطةً بلا عمولة، ولا يُعدّ ثمنُ المنتج مبيعًا للبوتيك).
 *
 * والأرقامُ كلُّها من الخادم (`BoutiqueSales`)، صفحةً صفحة: لا تُحمَّل
 * آلافُ الفواتير لتُعرض خمسٌ وعشرون منها، ولا يُعاد في المتصفّح حسابُ لقطة.
 */

export type SaleType = 'standalone' | 'invoice' | 'bundle' | 'addon';
/** `awaiting`: فاتورةُ عميلٍ لم يكتمل تحصيلُها — لا تحملها ورقةٌ بعد */
export type SettleState = 'awaiting' | 'pending' | 'issued' | 'paid';
export type InvoiceState = 'active' | 'partial' | 'cancelled';

export interface SaleRow {
    key: string;
    type: SaleType;
    /** `null` لفاتورة عميلٍ بلا طلب — وهي `invoice_id` */
    order_id: number | null;
    invoice_id?: number | null;
    order_number: string;
    ordered_at: string | null;
    order_status: string;
    /** البابُ الذي بيع منه — باسمه من الخادم */
    channel?: string;
    invoice_state: InvoiceState | 'returned';
    /** فاتورةٌ ملغاةٌ لم تحملها ورقة: بندُها كما سُجّل ولا نصيبَ فيه */
    void?: boolean;
    boutique_id: number;
    boutique: string;
    product: string;
    variant: string | null;
    parent: string | null;
    parent_variant: string | null;
    parent_total: number | null;
    addon: string | null;
    quantity: number;
    unit_price: number;
    amount: number;
    rate: number | null;
    commission: number;
    net: number;
    credited: number;
    kept_state: string | null;
    settle_state: SettleState;
    settlement: string | null;
}

export interface SalesPage {
    rows: SaleRow[];
    page: number;
    last_page: number;
    total: number;
    per_page: number;
}

export interface InvoicePart { lines: number; invoices: number; quantity: number; gross: number; commission: number; net: number }

export interface SalesSummary {
    standalone: { gross: number; commission: number; net: number; quantity: number; lines: number; orders: number; taxed_orders: number };
    components: { bundle: number; addon: number; due: number; quantity: number };
    bundles: { lines: number; revenue: number; boutiques_due: number; shop_remaining: number };
    /** فواتيرُ عملاءٍ صدرت قبل أن تُحفظ لقطةُ بوتيكها — لا تدخل هذه الأرقام */
    uncounted?: { lines: number; invoices: number; amount: number };
    /** فواتيرُ العملاء بلا طلب: ما اكتمل تحصيلُه في الشهر، وما ينتظر، وما أُلغي تحصيلُه بعد ورقته */
    invoices?: InvoicePart & { awaiting: InvoicePart; reopened: InvoicePart };
    owed: number;
    pieces: 'on' | 'off' | 'taxed';
    /** مفتاحُ المنصّة لهذا المتجر — والشاشةُ تبقى لمن عنده بوتيكٌ ولو أُطفئ */
    hosting: boolean;
    /** مستحقُّ قطعٍ كُتب قبل إطفاء حسابها — معروضٌ محسوب ولم يُحذف */
    pieces_recorded: boolean;
    /** أوراقٌ صدرت قبل توحيد القاعدة وفرقُها عن جمع بنودها — كما صدرت */
    old_sheets?: { count: number; difference: number };
    settlement: {
        sheets: number; issued: number; paid: number; unpaid: number; remaining: number;
        pending: { standalone: number; components: number; credit: number; total: number };
    };
    returns: {
        notes: number; amount: number; reduced: number; claims_open: number; review: number; shop: number;
        component_credits: number; cancelled_gross: number;
    };
}

interface Piece {
    boutique: string; product: string; type: SaleType; addon: string | null; quantity: number;
    unit_price: number; amount: number; credited: number; kept_state: string | null;
    settlement: string | null; settle_state: SettleState;
}

export interface InvoiceDetails {
    /** الفاتورةُ كاملةً، أم ما للبوتيكات فيها وحده؟ — لمن لا يملك قسم المبيعات */
    full?: boolean;
    order: {
        id: number; number: string; ordered_at: string | null; status: string; channel?: string; invoice_state: InvoiceState | 'returned';
        subtotal: number | null; discount: number | null; tax: number | null; delivery_fee: number | null; total: number | null;
        /** أين الضريبة من أسعار البنود — مقروءةً من الفاتورة نفسها */
        tax_basis: 'none' | 'inside' | 'added' | 'unknown' | null;
    };
    lines: {
        id: number; name: string; variant: string | null; quantity: number; price: number | null; total: number | null; addons_total: number | null;
        boutique: { name: string; rate: number; commission: number; net: number; settlement: string | null; settle_state: SettleState | 'returned' | 'void' } | null;
        /** لبند فاتورة العميل: خصمُه (على المتجر)، وما رُدّ منه بإشعار */
        discount?: number | null;
        returned?: number;
        pieces: Piece[];
        dues: number;
        shop_remaining: number | null;
        shop_share: number | null;
    }[];
    money: {
        lines: number; discount: number; discount_coupon: number; discount_points: number; after_discount?: number;
        tax: number; tax_basis: 'none' | 'inside' | 'added' | 'unknown'; delivery_fee: number;
        total: number; paid: number; balance: number; boutiques_owed: number;
        /** إشعاراتُ دائنٍ على فاتورة العميل */
        credited?: number;
        shop_after_discount: number; shop_after_tax: number;
    } | null;
    shares: {
        boutiques: { id: number; name: string; standalone_gross: number; commission: number; standalone_net: number; components_due: number; owed: number }[];
        shop: number | null;
        lines_total: number | null;
        reconciles: boolean;
        /** فاتورةٌ ملغاة: بنودُها كما سُجّلت، ولا نصيبَ فيها لأحد */
        void: boolean;
    };
    totals: { standalone_gross: number; commission: number; standalone_net: number; components_due: number; owed: number };
    edits: { kind: string; subject: string | null; qty_before: number | null; qty_after: number | null; reason: string | null; at: string | null }[];
    can_open: boolean;
    url: string | null;
}

interface Props {
    boutiqueId: number;
    boutiques: { value: number; label: string }[];
    period: string;
    periods: { value: string; label: string }[];
    onPeriodChange: (period: string) => void;
    currency: Currency;
}

export const TYPE_LABEL: Record<SaleType, string> = {
    standalone: 'بيع منفرد',
    invoice: 'فاتورة عميل',
    bundle: 'ضمن باقة',
    addon: 'إضافة اختيارية',
};

export const SETTLE_LABEL: Record<SettleState, string> = {
    awaiting: 'بانتظار التحصيل',
    pending: 'لم تُسوَّ',
    issued: 'في ورقة لم تُسدَّد',
    paid: 'سُدِّدت',
};

export const STATE_LABEL: Record<InvoiceState, string> = {
    active: 'سارية',
    partial: 'مرتجعة جزئيًا',
    cancelled: 'ملغاة',
};

const SETTLE_VARIANT: Record<SettleState, 'neutral' | 'warning' | 'success' | 'info'> = {
    awaiting: 'info',
    pending: 'neutral',
    issued: 'warning',
    paid: 'success',
};

const TYPE_VARIANT: Record<SaleType, 'info' | 'primary' | 'outline'> = {
    standalone: 'info',
    invoice: 'info',
    bundle: 'primary',
    addon: 'outline',
};

/** معاملاتُ الطلب كما يقرؤها الخادم — «الكلّ» لا يُرسَل */
export function salesQuery(f: { period: string; boutique: string; type: string; settle: string; state?: string; q: string; page?: number }) {
    return {
        period: f.period,
        ...(f.boutique !== 'all' ? { boutique: f.boutique } : {}),
        ...(f.type && f.type !== 'all' ? { type: f.type } : {}),
        ...(f.settle && f.settle !== 'all' ? { settle: f.settle } : {}),
        ...(f.state && f.state !== 'all' ? { state: f.state } : {}),
        ...(f.q.trim() ? { q: f.q.trim() } : {}),
        ...(f.page && f.page > 1 ? { page: f.page } : {}),
    };
}

/** عددٌ بلا أصفارٍ زائدة: «٢» لا «٢٫٠٠٠» — فلا تُقرأ الكمّيّةُ مبلغًا */
const short = (v: number | null | undefined, max: number) => {
    const n = Number(v ?? 0);
    for (let d = 0; d < max; d++) {
        if (Math.abs(Number(n.toFixed(d)) - n) < 0.5 / 10 ** max) return number(n, d);
    }
    return number(n, max);
};

async function getJson<T>(url: string, signal?: AbortSignal): Promise<T> {
    const res = await fetch(url, {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
        signal,
    });
    if (!res.ok) throw new Error(String(res.status));

    return (await res.json()) as T;
}

export default function SalesPanel({ boutiqueId, boutiques, period, periods, onPeriodChange, currency }: Props) {
    const t = useTranslate();
    const [boutique, setBoutique] = useState<string>(String(boutiqueId));
    // «الكلّ» قيمةٌ لا فراغ: القائمةُ المنسدلة لا تحمل قيمةً فارغة فتُرسم خانةً بيضاء
    const [type, setType] = useState('all');
    const [settle, setSettle] = useState('all');
    const [state, setState] = useState('all');
    const [q, setQ] = useState('');
    const [term, setTerm] = useState('');
    const [page, setPage] = useState(1);
    const [rows, setRows] = useState<SalesPage | null>(null);
    const [summary, setSummary] = useState<SalesSummary | null>(null);
    const [loading, setLoading] = useState(false);
    const [failed, setFailed] = useState(false);
    const [opened, setOpened] = useState<string | null>(null);

    // البحثُ بعد أن يكفّ الكاتب — لا طلبٌ لكلّ حرف
    useEffect(() => {
        const id = window.setTimeout(() => setTerm(q), 300);
        return () => window.clearTimeout(id);
    }, [q]);

    // ومُرشِّحٌ يتغيّر يعيد إلى الصفحة الأولى
    useEffect(() => setPage(1), [period, boutique, type, settle, state, term]);

    // الملخّصُ للشهر والبوتيك وحدهما — لا يتبدّل بالبحث ولا بالصفحة
    useEffect(() => {
        const ctl = new AbortController();
        setSummary(null);
        getJson<SalesSummary>(route('admin.boutiques.sales.summary', salesQuery({ period, boutique, type: '', settle: '', q: '' })), ctl.signal)
            .then(setSummary)
            .catch((e) => e?.name !== 'AbortError' && setFailed(true));
        return () => ctl.abort();
    }, [period, boutique]);

    useEffect(() => {
        const ctl = new AbortController();
        setLoading(true);
        setFailed(false);
        getJson<SalesPage>(route('admin.boutiques.sales', salesQuery({ period, boutique, type, settle, state, q: term, page })), ctl.signal)
            .then((p) => {
                setRows(p);
                setLoading(false);
            })
            .catch((e) => {
                if (e?.name === 'AbortError') return;
                setFailed(true);
                setLoading(false);
            });
        return () => ctl.abort();
    }, [period, boutique, type, settle, state, term, page]);

    const fmt = (v: number | null | undefined) => money(v ?? 0, currency);

    const boutiqueOptions = [
        { value: 'all', label: t('كل البوتيكات') },
        ...boutiques.map((b) => ({ value: String(b.value), label: b.label })),
    ];

    return (
        <Card className="mb-5 overflow-hidden" data-testid="boutique-sales">
            <div className="border-b border-[var(--ui-border,#e8e8e8)] px-4 py-3">
                <h2 className="text-sm font-semibold text-[#111]">{t('مبيعات البوتيك وفواتيره')}</h2>
                <p className="mt-0.5 text-[12px] leading-5 text-[#6b7280]">
                    {t('كل بيعٍ بفاتورته الأصلية: صنف البوتيك منفردًا، أو في فاتورة عميل، أو قطعته داخل منتج المتجر — دون احتساب البيع أو المستحق مرتين.')}
                </p>
                {/* دائمٌ لا مشروط: يُقال قبل أوّل رقم، ولو سقط الملخّصُ أو خلا الشهر */}
                <div role="note" className="mt-2 rounded-md border border-[#fcd34d] bg-[#fffbeb] px-3 py-2 text-[12px] leading-5 text-[#92400e]" data-testid="boutique-scope-note">
                    <p className="font-semibold">{t('للعرض والمتابعة فقط — لا تُعتمد هذه الأرقام وحدها أساسًا للتسوية المالية.')}</p>
                    <p>{t('تشمل نقطة البيع والموقع، وما حُفظت حصة البوتيك على بنوده وقت إصداره من فواتير العملاء وطلبات واتساب. ولا تشمل: ما صدر منها قبل ذلك، وروابط الدفع المستقلة.')}</p>
                </div>
            </div>

            {/* المرشّحات — كلُّها في الخادم */}
            <div className="grid grid-cols-1 gap-2 border-b border-[var(--ui-border,#e8e8e8)] px-4 py-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                <Select value={period} onChange={(e) => onPeriodChange(e.target.value)} options={periods} aria-label={t('الشهر')} />
                <Select value={boutique} onChange={(e) => setBoutique(e.target.value)} options={boutiqueOptions} aria-label={t('البوتيك')} />
                <Select
                    value={type}
                    onChange={(e) => setType(e.target.value)}
                    options={[
                        { value: 'all', label: t('كل أنواع العمليات') },
                        ...(Object.keys(TYPE_LABEL) as SaleType[]).map((k) => ({ value: k, label: t(TYPE_LABEL[k]) })),
                    ]}
                    aria-label={t('نوع العملية')}
                />
                <Select
                    value={settle}
                    onChange={(e) => setSettle(e.target.value)}
                    options={[
                        { value: 'all', label: t('كل حالات التسوية') },
                        ...(Object.keys(SETTLE_LABEL) as SettleState[]).map((k) => ({ value: k, label: t(SETTLE_LABEL[k]) })),
                    ]}
                    aria-label={t('حالة التسوية')}
                />
                <Select
                    value={state}
                    onChange={(e) => setState(e.target.value)}
                    options={[
                        { value: 'all', label: t('كل حالات الفاتورة') },
                        ...(Object.keys(STATE_LABEL) as InvoiceState[]).map((k) => ({ value: k, label: t(STATE_LABEL[k]) })),
                    ]}
                    aria-label={t('حالة الفاتورة')}
                />
                <div className="relative">
                    <Search className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-[#9ca3af]" />
                    <Input
                        value={q}
                        onChange={(e) => setQ(e.target.value)}
                        placeholder={t('رقم الفاتورة أو اسم المنتج')}
                        aria-label={t('بحث في الفواتير')}
                        className="ps-9"
                    />
                </div>
            </div>

            <SummaryBlock summary={summary} fmt={fmt} />

            {failed && (
                <p className="px-4 py-3 text-[13px] text-[#b91c1c]">{t('تعذّر تحميل مبيعات البوتيك — أعد المحاولة.')}</p>
            )}

            {rows && rows.rows.length === 0 && !loading ? (
                <p className="px-4 py-10 text-center text-[13px] text-[#9ca3af]">{t('لا فواتير تطابق هذا الاختيار')}</p>
            ) : (
                <div className={cn(loading && 'opacity-60')}>
                    <RowsTable rows={rows?.rows ?? []} fmt={fmt} onOpen={setOpened} />
                    <RowsCards rows={rows?.rows ?? []} fmt={fmt} onOpen={setOpened} />
                </div>
            )}

            {rows && rows.last_page > 1 && (
                <div className="flex items-center justify-between gap-2 border-t border-[var(--ui-border,#e8e8e8)] px-4 py-3 text-[13px]">
                    <span className="text-[#6b7280]">
                        {t('صفحة :page من :last — :total صفًّا', {
                            page: number(rows.page), last: number(rows.last_page), total: number(rows.total),
                        })}
                    </span>
                    <div className="flex gap-2">
                        <Button variant="outline" size="sm" disabled={rows.page <= 1 || loading} onClick={() => setPage(rows.page - 1)}>
                            <ChevronRight />
                            {t('السابق')}
                        </Button>
                        <Button variant="outline" size="sm" disabled={rows.page >= rows.last_page || loading} onClick={() => setPage(rows.page + 1)}>
                            {t('التالي')}
                            <ChevronLeft />
                        </Button>
                    </div>
                </div>
            )}

            <InvoiceDialog url={opened} onClose={() => setOpened(null)} fmt={fmt} />
        </Card>
    );
}

/* ═══════════ الملخّص ═══════════ */

function Tile({ label, value, hint, tone }: { label: string; value: string; hint?: string; tone?: 'ours' | 'theirs' | 'strong' }) {
    return (
        <div className="rounded-[10px] border border-[var(--ui-border,#e8e8e8)] p-3">
            <p className="text-[12px] text-[#6b7280]">{label}</p>
            <p
                className={cn(
                    'mt-0.5 text-[17px] font-bold tabular-nums',
                    tone === 'ours' && 'text-[#047857]',
                    tone === 'theirs' && 'text-[#6d28d9]',
                    (!tone || tone === 'strong') && 'text-[#111]',
                )}
            >
                {value}
            </p>
            {hint && <p className="mt-0.5 text-[11px] leading-4 text-[#9ca3af]">{hint}</p>}
        </div>
    );
}

function SummaryBlock({ summary, fmt }: { summary: SalesSummary | null; fmt: (v: number) => string }) {
    const t = useTranslate();

    if (!summary) {
        return <div className="h-24 animate-pulse border-b border-[var(--ui-border,#e8e8e8)] bg-[#fafafa]" />;
    }

    const s = summary;
    const returnsShown = s.returns.notes > 0 || s.returns.component_credits > 0 || s.returns.cancelled_gross > 0;

    return (
        <div className="space-y-3 border-b border-[var(--ui-border,#e8e8e8)] px-4 py-3" data-testid="boutique-sales-summary">
            {!s.hosting && (
                <p className="rounded-md bg-[#fff7ed] px-3 py-2 text-[12px] leading-5 text-[#9a3412]" data-testid="boutique-hosting-off">
                    {t('إيواء البوتيكات مطفأ لهذا المتجر من إدارة المنصة. الشاشة باقية لأن لديك بوتيكات، وما سُجّل من مبيعات والتزامات معروض كما هو ولم يُحذف.')}
                </p>
            )}
            <section>
                <p className="mb-1.5 text-[12px] font-semibold text-[#4b4b4b]">{t('مبيعات الشهر')}</p>
                <div className="grid grid-cols-2 gap-2 md:grid-cols-5">
                    <Tile
                        label={t('مبيعات منتجات البوتيك المنفردة')}
                        value={fmt(s.standalone.gross)}
                        hint={t(':pieces قطعة في :invoices فاتورة', { pieces: short(s.standalone.quantity, 3), invoices: number(s.standalone.orders) })}
                    />
                    <Tile label={t('عمولة المتجر من المنفردة')} value={fmt(s.standalone.commission)} tone="ours" />
                    <Tile label={t('صافي المنفرد للبوتيك')} value={fmt(s.standalone.net)} tone="theirs" />
                    <Tile
                        label={t('مستحق القطع في منتجات المتجر')}
                        value={fmt(s.components.due)}
                        hint={t('ضمن باقة :bundle · إضافات :addon — بلا عمولة', { bundle: fmt(s.components.bundle), addon: fmt(s.components.addon) })}
                        tone="theirs"
                    />
                    <Tile
                        label={t('المستحق للبوتيك قبل التسوية')}
                        value={fmt(s.owed)}
                        hint={t('صافي المنفرد وفواتير العملاء المحصّلة + مستحق القطع')}
                        tone="strong"
                    />
                </div>
                {s.invoices && (s.invoices.lines > 0 || s.invoices.awaiting.lines > 0 || s.invoices.reopened.lines > 0) && (
                    <div className="mt-2 grid grid-cols-2 gap-2 md:grid-cols-4" data-testid="boutique-invoice-tiles">
                        <Tile
                            label={t('فواتير عملاء اكتمل تحصيلها')}
                            value={fmt(s.invoices.gross)}
                            hint={t(':lines بندًا في :invoices فاتورة', { lines: number(s.invoices.lines), invoices: number(s.invoices.invoices) })}
                        />
                        <Tile label={t('عمولة المتجر منها')} value={fmt(s.invoices.commission)} tone="ours" />
                        <Tile label={t('صافي البوتيك منها')} value={fmt(s.invoices.net)} tone="theirs" />
                        <Tile
                            label={t('صافي البوتيك بانتظار التحصيل')}
                            value={fmt(s.invoices.awaiting.net)}
                            hint={t('لا تحمله ورقة قبل اكتمال السداد — :n فاتورة', { n: number(s.invoices.awaiting.invoices) })}
                        />
                    </div>
                )}
                {s.invoices && s.invoices.reopened.lines > 0 && (
                    <p className="mt-2 rounded-md bg-[#fef2f2] px-3 py-2 text-[12px] leading-5 text-[#b91c1c]" data-testid="boutique-reopened-note">
                        {t(':n فاتورة عميل أُلغي تحصيلها بعد أن حملت ورقة التسوية :amount منها. الورقة لا تتغير، وما لم يُسدَّد منها معلّق — حصّلها من جديد، أو أصدر إشعار دائن بالقطع المرتجعة، أو راجعها في شاشة البوتيك.', {
                            n: number(s.invoices.reopened.invoices), amount: fmt(s.invoices.reopened.net),
                        })}
                    </p>
                )}
                <p className="mt-2 text-[12px] leading-5 text-[#6b7280]" data-testid="boutique-not-final-note">
                    {t('المبالغ قبل صدور ورقة التسوية للمتابعة وليست تسوية نهائية — المعتمد ما في ورقة التسوية بعد مراجعتها.')}
                </p>
                {s.uncounted && s.uncounted.lines > 0 && (
                    <p className="mt-2 rounded-md bg-[#fef2f2] px-3 py-2 text-[12px] leading-5 text-[#b91c1c]" data-testid="boutique-uncounted-note">
                        {t('وفي الشهر :lines بندًا في :invoices فاتورة عميل (كُتبت بمبلغ :amount) صدرت قبل أن تُحفظ حصة البوتيك على بنودها: لا تدخل المبيعات ولا المستحق، ولا يُحسب لها شيء بنسبة اليوم.', {
                            lines: number(s.uncounted.lines), invoices: number(s.uncounted.invoices), amount: fmt(s.uncounted.amount),
                        })}
                    </p>
                )}
                {s.old_sheets && s.old_sheets.count > 0 && (
                    <p className="mt-2 rounded-md border border-dashed border-[var(--ui-border,#e8e8e8)] px-3 py-2 text-[12px] leading-5 text-[#4b4b4b]" data-testid="boutique-rounding-note">
                        {t('في الشهر :n ورقة صدرت قبل توحيد التقريب، مجموعها يختلف عن جمع بنودها بـ :d. الورقة كما صدرت، والجديدة تطابق بنودها بالبيسة.', {
                            n: number(s.old_sheets.count), d: fmt(s.old_sheets.difference),
                        })}
                    </p>
                )}
                {s.standalone.taxed_orders > 0 && (
                    <p className="mt-2 rounded-md bg-[#fff7ed] px-3 py-2 text-[12px] leading-5 text-[#9a3412]" data-testid="boutique-tax-note">
                        {t('في هذا الشهر :n فاتورة فيها ضريبة. العمولة محسوبة من مبلغ البند كما سُجّل: شامل الضريبة إن كانت الأسعار شاملة، وقبلها إن كانت مضافة. هذه السياسة القائمة ولم تُعتمد بعد — راجعها قبل اعتبار هذه المبالغ نهائية.', { n: number(s.standalone.taxed_orders) })}
                    </p>
                )}
                {s.pieces !== 'on' && (
                    <p className="mt-2 rounded-md bg-[#fff7ed] px-3 py-2 text-[12px] leading-5 text-[#9a3412]" data-testid="boutique-pieces-note">
                        {s.pieces === 'taxed'
                            ? t('هذا المتجر يحتسب الضريبة، والنظام لا يحسب قطع البوتيك داخل منتجات المتجر في متجر بضريبة — فلا تظهر صفوف «ضمن باقة» ولا «إضافة اختيارية». تفعيلها هنا قرار منفصل.')
                            : s.pieces_recorded
                              ? t('حساب قطع البوتيك داخل منتجات المتجر مطفأ الآن — ما يُباع بعد إطفائه لا يُحتسب، وما سُجّل قبله معروض ومحسوب كما هو، ويُسوّى ويُسدَّد.')
                              : t('حساب قطع البوتيك داخل منتجات المتجر مطفأ لهذا المتجر — فلا تظهر صفوف «ضمن باقة» ولا «إضافة اختيارية»، وما بيع قبل تشغيله لا يُعرف. تشغيله قرار منفصل.')}
                    </p>
                )}
            </section>

            <section>
                <p className="mb-1.5 text-[12px] font-semibold text-[#4b4b4b]">{t('التسوية')}</p>
                <div className="grid grid-cols-2 gap-2 md:grid-cols-5">
                    <Tile label={t('صدر في أوراق التسوية')} value={fmt(s.settlement.issued)} hint={t(':n ورقة', { n: number(s.settlement.sheets) })} />
                    <Tile label={t('ما سُدِّد')} value={fmt(s.settlement.paid)} tone="ours" />
                    <Tile label={t('صدر ولم يُسدَّد')} value={fmt(s.settlement.unpaid)} />
                    <Tile label={t('ينتظر ورقة تسوية')} value={fmt(s.settlement.pending.total)} />
                    <Tile label={t('المتبقي المستحق')} value={fmt(s.settlement.remaining)} hint={t('ما ينتظر ورقة + ما صدر ولم يُسدَّد')} tone="strong" />
                </div>
            </section>

            {s.bundles.lines > 0 && (
                <section>
                    <p className="mb-1.5 text-[12px] font-semibold text-[#4b4b4b]">{t('منتجات المتجر التي دخلتها قطع البوتيك')}</p>
                    <div className="grid grid-cols-1 gap-2 sm:grid-cols-3">
                        <Tile label={t('إيراد هذه البنود')} value={fmt(s.bundles.revenue)} hint={t(':n بند — قبل خصم الفاتورة', { n: number(s.bundles.lines) })} />
                        <Tile label={t('مستحق البوتيكات منها')} value={fmt(s.bundles.boutiques_due)} tone="theirs" />
                        <Tile
                            label={t('المتبقي للمتجر من إيرادها')}
                            value={fmt(s.bundles.shop_remaining)}
                            hint={t('قبل خصم الفاتورة وتكاليف المتجر الأخرى — ليس ربحًا')}
                            tone="ours"
                        />
                    </div>
                </section>
            )}

            {/* ثلاثةُ أرقامٍ تُخلط: ما يأخذه المتجر من بيع البوتيك، وما يبقى له من منتجه، وربحُه في الدفتر */}
            <details className="rounded-lg border border-[var(--ui-border,#e8e8e8)] px-3 py-2 text-[12px] leading-5 text-[#4b4b4b]" data-testid="boutique-three-numbers">
                <summary className="cursor-pointer font-semibold">{t('العمولة، والمتبقي للمتجر، وصافي الربح: ما الفرق؟')}</summary>
                <ul className="mt-1.5 list-disc space-y-1 ps-5">
                    <li>{t('العمولة: ما يأخذه المتجر من بيع صنف البوتيك منفردًا، بنسبة البوتيك من السعر. هي إيراد المتجر من هذا البيع.')}</li>
                    <li>{t('المتبقي للمتجر: ما يبقى من منتج المتجر بعد ثمن قطع البوتيك فيه. قبل خصم الفاتورة، وقبل تكلفة الورد والعمالة والإيجار.')}</li>
                    <li>{t('صافي الربح المحاسبي: من تقرير الأرباح والدفتر، بعد كل التكاليف والمصروفات. لا تقوله هذه الشاشة.')}</li>
                </ul>
            </details>

            {returnsShown && (
                <section>
                    <p className="mb-1.5 text-[12px] font-semibold text-[#4b4b4b]">{t('المرتجعات والأرصدة — منفصلة عن المبيعات')}</p>
                    <div className="grid grid-cols-2 gap-2 md:grid-cols-4">
                        <Tile label={t('مرتجعات بعد ورقة التسوية')} value={fmt(s.returns.amount)} hint={t(':n إشعار — :review ينتظر قرارًا', { n: number(s.returns.notes), review: number(s.returns.review) })} />
                        <Tile label={t('مطالبات مفتوحة على البوتيك')} value={fmt(s.returns.claims_open)} />
                        <Tile label={t('أرصدة للمتجر عن قطع أُرجعت')} value={fmt(s.returns.component_credits)} />
                        <Tile label={t('مبيعات منفردة أُلغيت')} value={fmt(s.returns.cancelled_gross)} hint={t('خارج المبيعات أعلاه')} />
                    </div>
                </section>
            )}
        </div>
    );
}

/* ═══════════ الصفوف ═══════════ */

/** بابُ نافذة الفاتورة: طلبٌ، أو فاتورةُ عميلٍ بلا طلب */
export const invoiceUrl = (r: Pick<SaleRow, 'order_id' | 'invoice_id'>) =>
    r.order_id === null ? route('admin.boutiques.sales.invoice', r.invoice_id ?? 0) : route('admin.boutiques.sales.order', r.order_id);

function InvoiceStateBadge({ row }: { row: Pick<SaleRow, 'invoice_state' | 'order_status'> }) {
    const t = useTranslate();
    if (row.invoice_state === 'cancelled') return <Badge variant="danger">{t('ملغاة')}</Badge>;
    if (row.invoice_state === 'returned') return <Badge variant="warning">{t('مرتجعة كاملة')}</Badge>;
    if (row.invoice_state === 'partial') return <Badge variant="warning">{t('مرتجعة جزئيًا')}</Badge>;
    return <Badge variant="neutral">{t(row.order_status)}</Badge>;
}

function ProductCell({ row, fmt }: { row: SaleRow; fmt: (v: number) => string }) {
    const t = useTranslate();
    return (
        <div className="min-w-0">
            <p className="text-[#111]">
                {row.product}
                {row.variant && <span className="text-[12px] text-[#9ca3af]"> — {row.variant}</span>}
            </p>
            {row.parent && (
                <p className="text-[12px] text-[#6b7280]" data-testid="sale-parent">
                    {row.type === 'addon' && row.addon
                        ? t('إضافة «:addon» على «:parent»', { addon: row.addon, parent: row.parent })
                        : t('داخل «:parent»', { parent: row.parent })}
                    {row.parent_total !== null && <> — {t('سعره :total', { total: fmt(row.parent_total) })}</>}
                </p>
            )}
            {row.credited > 0 && (
                <p className="text-[12px] text-[#b45309]">{t('أُرجعت بعد التسوية — رصيد للمتجر :amount', { amount: fmt(row.credited) })}</p>
            )}
            {row.kept_state && <p className="text-[12px] text-[#b45309]">{t('لم تعد القطعة إلى الرف — بقي مستحقها')}</p>}
        </div>
    );
}

function RowsTable({ rows, fmt, onOpen }: { rows: SaleRow[]; fmt: (v: number) => string; onOpen: (url: string) => void }) {
    const t = useTranslate();
    return (
        <div className="no-scrollbar hidden overflow-x-auto md:block">
            <table className="w-full text-sm" data-testid="boutique-sales-table">
                <thead className="bg-[#fafafa] text-[12px] text-[#6b7280]">
                    <tr>
                        <th className="px-3 py-2 text-start font-medium">{t('الفاتورة')}</th>
                        <th className="px-3 py-2 text-start font-medium">{t('المنتج')}</th>
                        <th className="px-3 py-2 text-start font-medium">{t('البوتيك')}</th>
                        <th className="px-3 py-2 text-start font-medium">{t('النوع')}</th>
                        <th className="px-3 py-2 text-end font-medium">{t('الكمية × السعر')}</th>
                        <th className="px-3 py-2 text-end font-medium">{t('الإجمالي')}</th>
                        <th className="px-3 py-2 text-end font-medium">{t('عمولة المتجر')}</th>
                        <th className="px-3 py-2 text-end font-medium">{t('صافي البوتيك')}</th>
                        <th className="px-3 py-2 text-start font-medium">{t('الحالة')}</th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map((r) => (
                        <tr key={r.key} className="border-t border-[var(--ui-border,#e8e8e8)] align-top">
                            <td className="px-3 py-2.5">
                                <button type="button" onClick={() => onOpen(invoiceUrl(r))} className="whitespace-nowrap font-medium text-[#1d4ed8] underline-offset-2 hover:underline">
                                    {r.order_number}
                                </button>
                                <p className="text-[11px] text-[#9ca3af] tabular-nums">{r.ordered_at}</p>
                                {r.channel && <p className="whitespace-nowrap text-[11px] text-[#6b7280]" data-testid="sale-channel">{r.channel}</p>}
                            </td>
                            <td className="px-3 py-2.5"><ProductCell row={r} fmt={fmt} /></td>
                            <td className="px-3 py-2.5 text-[#4b4b4b]">{r.boutique}</td>
                            <td className="px-3 py-2.5"><Badge variant={TYPE_VARIANT[r.type]}>{t(TYPE_LABEL[r.type])}</Badge></td>
                            <td className="whitespace-nowrap px-3 py-2.5 text-end tabular-nums">{short(r.quantity, 3)} × {fmt(r.unit_price)}</td>
                            <td className="whitespace-nowrap px-3 py-2.5 text-end tabular-nums">{fmt(r.amount)}</td>
                            <td className="whitespace-nowrap px-3 py-2.5 text-end tabular-nums text-[#047857]">
                                {r.void ? <span className="text-[#9ca3af]">—</span> : r.rate !== null ? <>{fmt(r.commission)} <span className="text-[11px] text-[#9ca3af]">({number(r.rate ?? 0, 2)}%)</span></> : t('لا عمولة')}
                            </td>
                            <td className="whitespace-nowrap px-3 py-2.5 text-end font-medium tabular-nums text-[#6d28d9]">{r.void ? <span className="font-normal text-[#9ca3af]">—</span> : fmt(r.net)}</td>
                            <td className="space-y-1 px-3 py-2.5">
                                <InvoiceStateBadge row={r} />
                                {!r.void && <Badge variant={SETTLE_VARIANT[r.settle_state]} className="whitespace-nowrap">{t(SETTLE_LABEL[r.settle_state])}</Badge>}
                                {r.settlement && <p className="whitespace-nowrap text-[11px] text-[#9ca3af]">{r.settlement}</p>}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function RowsCards({ rows, fmt, onOpen }: { rows: SaleRow[]; fmt: (v: number) => string; onOpen: (url: string) => void }) {
    const t = useTranslate();
    return (
        <ul className="divide-y divide-[var(--ui-border,#e8e8e8)] md:hidden" data-testid="boutique-sales-cards">
            {rows.map((r) => (
                <li key={r.key} className="space-y-1.5 px-4 py-3 text-[13px]">
                    <div className="flex items-center justify-between gap-2">
                        <button type="button" onClick={() => onOpen(invoiceUrl(r))} className="font-medium text-[#1d4ed8]">
                            {r.order_number}
                        </button>
                        <span className="text-[11px] text-[#9ca3af] tabular-nums">{r.ordered_at}</span>
                    </div>
                    <ProductCell row={r} fmt={fmt} />
                    <div className="flex flex-wrap items-center gap-1.5">
                        <Badge variant={TYPE_VARIANT[r.type]}>{t(TYPE_LABEL[r.type])}</Badge>
                        <span className="text-[12px] text-[#6b7280]">{r.boutique}</span>
                        {r.channel && <span className="text-[12px] text-[#6b7280]">{r.channel}</span>}
                        <InvoiceStateBadge row={r} />
                        {!r.void && <Badge variant={SETTLE_VARIANT[r.settle_state]}>{t(SETTLE_LABEL[r.settle_state])}</Badge>}
                        {r.settlement && <span className="text-[11px] text-[#9ca3af]">{r.settlement}</span>}
                    </div>
                    <p className="text-[12px] tabular-nums text-[#4b4b4b]">{short(r.quantity, 3)} × {fmt(r.unit_price)}</p>
                    <div className="grid grid-cols-3 gap-2 tabular-nums">
                        <div><p className="text-[11px] text-[#9ca3af]">{t('الإجمالي')}</p>{fmt(r.amount)}</div>
                        <div><p className="text-[11px] text-[#9ca3af]">{t('عمولة المتجر')}</p>{r.void ? '—' : r.rate !== null ? fmt(r.commission) : t('لا عمولة')}</div>
                        <div><p className="text-[11px] text-[#9ca3af]">{t('صافي البوتيك')}</p><span className="font-medium text-[#6d28d9]">{r.void ? '—' : fmt(r.net)}</span></div>
                    </div>
                </li>
            ))}
        </ul>
    );
}

/* ═══════════ تفاصيل الفاتورة ═══════════ */

/** المدفوع والخصومات والضريبة وحصّةُ المتجر بعدها — كلٌّ في سطره، ولا يُخلط بمجموع البنود */
function MoneyBlock({ money, fmt }: { money: NonNullable<InvoiceDetails['money']>; fmt: (v: number) => string }) {
    const t = useTranslate();
    const otherDiscount = Math.max(0, Number((money.discount - money.discount_coupon - money.discount_points).toFixed(3)));
    const row = (label: string, value: string, extra = '') => (
        <div className={cn('flex items-baseline justify-between gap-3 py-1', extra)}>
            <dt className="text-[#6b7280]">{label}</dt>
            <dd className="tabular-nums">{value}</dd>
        </div>
    );

    return (
        <dl className="rounded-[10px] border border-[var(--ui-border,#e8e8e8)] px-3 py-2 text-[13px]" data-testid="boutique-invoice-money">
            {row(t('مجموع البنود قبل الخصم'), fmt(money.lines))}
            {money.discount_coupon > 0 && row(t('كوبون (على المتجر)'), `−${fmt(money.discount_coupon)}`)}
            {money.discount_points > 0 && row(t('نقاط الولاء (على المتجر)'), `−${fmt(money.discount_points)}`)}
            {otherDiscount > 0 && row(t('خصم آخر (على المتجر)'), `−${fmt(otherDiscount)}`)}
            {money.discount > 0 && row(t('مجموع البنود بعد الخصم'), fmt(money.after_discount ?? money.lines - money.discount))}
            {money.tax > 0 && row(money.tax_basis === 'inside' ? t('الضريبة (ضمن الأسعار)') : t('الضريبة (مضافة)'), fmt(money.tax))}
            {money.delivery_fee > 0 && row(t('التوصيل'), fmt(money.delivery_fee))}
            {row(t('إجمالي ما على العميل'), fmt(money.total), 'border-t border-dashed border-[var(--ui-border,#e8e8e8)] font-semibold text-[#111]')}
            {(money.credited ?? 0) > 0 && row(t('إشعارات دائن'), `−${fmt(money.credited ?? 0)}`)}
            {row(t('المدفوع فعليًا'), fmt(money.paid))}
            {row(t('المتبقي على العميل'), fmt(money.balance))}
            <div className="mt-1 border-t border-[var(--ui-border,#e8e8e8)] pt-1">
                {row(t('مستحق البوتيكات'), fmt(money.boutiques_owed))}
                {row(t('حصة المتجر بعد تحمّل الخصومات'), fmt(money.shop_after_discount), money.shop_after_discount < 0 ? 'font-semibold text-[#b91c1c]' : 'text-[#047857]')}
                {money.tax_basis === 'inside' && money.tax > 0 && row(t('منها ضريبة يورّدها المتجر'), fmt(money.tax))}
                {money.shop_after_discount < 0 && (
                    <p className="mt-1 rounded-md bg-[#fef2f2] px-2 py-1.5 text-[12px] leading-5 text-[#b91c1c]" data-testid="boutique-invoice-discount-over">
                        {t('الخصومات تجاوزت حصة المتجر من بنود هذه الفاتورة: يتحمّل المتجر الفرق، ومستحق البوتيكات لا ينقص.')}
                    </p>
                )}
                <p className="pt-1 text-[12px] leading-5 text-[#9ca3af]">
                    {t('حصة المتجر ليست صافي ربح: تكلفة المنتجات والمصاريف غير محسوبة فيها.')}
                </p>
            </div>
        </dl>
    );
}

function InvoiceDialog({ url, onClose, fmt }: { url: string | null; onClose: () => void; fmt: (v: number) => string }) {
    const t = useTranslate();
    const [data, setData] = useState<InvoiceDetails | null>(null);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        if (url === null) return;
        const ctl = new AbortController();
        setData(null);
        setFailed(false);
        getJson<InvoiceDetails>(url, ctl.signal)
            .then(setData)
            .catch((e) => e?.name !== 'AbortError' && setFailed(true));
        return () => ctl.abort();
    }, [url]);

    return (
        <Dialog open={url !== null} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-w-2xl">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <FileText className="size-4" />
                        {data ? t('الفاتورة :number', { number: data.order.number }) : t('الفاتورة')}
                    </DialogTitle>
                </DialogHeader>

                <div className="px-5 pb-5">
                {failed && <p className="text-[13px] text-[#b91c1c]">{t('تعذّر تحميل الفاتورة.')}</p>}
                {!data && !failed && <div className="h-32 animate-pulse rounded-[10px] bg-[#fafafa]" />}

                {data && (
                    <div className="space-y-3 text-[13px]" data-testid="boutique-invoice">
                        <div className="flex flex-wrap items-center gap-2 text-[#6b7280]">
                            <span className="tabular-nums">{data.order.ordered_at}</span>
                            {data.order.channel && <span>{data.order.channel}</span>}
                            <InvoiceStateBadge row={{ invoice_state: data.order.invoice_state, order_status: data.order.status }} />
                        </div>

                        {data.full === false ? (
                            <p className="rounded-md bg-[#fff7ed] px-3 py-2 text-[12px] leading-5 text-[#9a3412]" data-testid="boutique-invoice-limited">
                                {t('تُعرض بنود البوتيكات وحدها. منتجات المتجر والخصم والضريبة وما دُفع لمن يملك صلاحية المبيعات.')}
                            </p>
                        ) : (
                            <dl className="grid grid-cols-2 gap-x-4 gap-y-1 rounded-[10px] bg-[#fafafa] p-3 tabular-nums sm:grid-cols-3" data-testid="boutique-invoice-header">
                                <div><dt className="text-[12px] text-[#6b7280]">{t('مجموع البنود')}</dt><dd>{fmt(data.shares.lines_total ?? 0)}</dd></div>
                                {(data.order.discount ?? 0) > 0 && (
                                    <div><dt className="text-[12px] text-[#6b7280]">{t('خصم الفاتورة (على المتجر)')}</dt><dd>−{fmt(data.order.discount ?? 0)}</dd></div>
                                )}
                                {(data.order.tax ?? 0) > 0 && (
                                    <div>
                                        <dt className="text-[12px] text-[#6b7280]">{data.order.tax_basis === 'inside' ? t('الضريبة (ضمن الأسعار)') : t('الضريبة (مضافة)')}</dt>
                                        <dd>{fmt(data.order.tax ?? 0)}</dd>
                                    </div>
                                )}
                                {(data.order.delivery_fee ?? 0) > 0 && (
                                    <div><dt className="text-[12px] text-[#6b7280]">{t('التوصيل')}</dt><dd>{fmt(data.order.delivery_fee ?? 0)}</dd></div>
                                )}
                                <div><dt className="text-[12px] text-[#6b7280]">{t('إجمالي الفاتورة')}</dt><dd className="font-semibold text-[#111]">{fmt(data.order.total ?? 0)}</dd></div>
                            </dl>
                        )}

                        {data.lines.map((l) => (
                            <div key={l.id} className="rounded-[10px] border border-[var(--ui-border,#e8e8e8)] p-3">
                                <div className="flex flex-wrap items-baseline justify-between gap-2">
                                    <p className="font-medium text-[#111]">
                                        {l.name}
                                        {l.variant && <span className="text-[12px] text-[#9ca3af]"> — {l.variant}</span>}
                                    </p>
                                    {l.price !== null && l.total !== null && (
                                        <span className="tabular-nums text-[#4b4b4b]">
                                            {number(l.quantity)} × {fmt(l.price)} = {fmt(l.total)}
                                            {(l.addons_total ?? 0) > 0 && <> + {t('إضافات :amount', { amount: fmt(l.addons_total ?? 0) })}</>}
                                        </span>
                                    )}
                                </div>

                                {!l.boutique && l.pieces.length === 0 && (
                                    <p className="mt-1 text-[12px] text-[#6b7280]">{t('منتج المتجر — لا نصيب فيه لبوتيك')}</p>
                                )}

                                {l.boutique && (
                                    <p className="mt-1 text-[#4b4b4b]">
                                        {t('بيع منفرد لـ:name — عمولة المتجر :rate% = :commission، وصافي البوتيك :net', {
                                            name: l.boutique.name, rate: number(l.boutique.rate, 2),
                                            commission: fmt(l.boutique.commission), net: fmt(l.boutique.net),
                                        })}
                                        {l.boutique.settle_state !== 'void' && l.boutique.settle_state !== 'returned' && (
                                            <span className="ms-1"><Badge variant={SETTLE_VARIANT[l.boutique.settle_state]}>{t(SETTLE_LABEL[l.boutique.settle_state])}</Badge></span>
                                        )}
                                    </p>
                                )}
                                {((l.discount ?? 0) > 0 || (l.returned ?? 0) > 0) && (
                                    <p className="mt-0.5 text-[12px] text-[#6b7280]">
                                        {(l.discount ?? 0) > 0 && t('خصم البند :amount على المتجر', { amount: fmt(l.discount ?? 0) })}
                                        {(l.discount ?? 0) > 0 && (l.returned ?? 0) > 0 && ' · '}
                                        {(l.returned ?? 0) > 0 && t('رُدّ منه :n', { n: short(l.returned, 3) })}
                                    </p>
                                )}

                                {l.pieces.length > 0 && (
                                    <ul className="mt-1.5 space-y-1">
                                        {l.pieces.map((p, i) => (
                                            <li key={i} className="flex flex-wrap items-center justify-between gap-2 text-[#4b4b4b]">
                                                <span>
                                                    {p.product} — {p.boutique}{' '}
                                                    <Badge variant={TYPE_VARIANT[p.type]}>{t(TYPE_LABEL[p.type])}</Badge>
                                                    {p.addon && <span className="text-[12px] text-[#9ca3af]"> ({p.addon})</span>}
                                                </span>
                                                <span className="tabular-nums">
                                                    {short(p.quantity, 3)} × {fmt(p.unit_price)} = <span className="font-medium text-[#6d28d9]">{fmt(p.amount)}</span>
                                                    <span className="ms-1"><Badge variant={SETTLE_VARIANT[p.settle_state]}>{t(SETTLE_LABEL[p.settle_state])}</Badge></span>
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                )}

                                {l.shop_remaining !== null && (
                                    <p className="mt-1.5 border-t border-dashed border-[var(--ui-border,#e8e8e8)] pt-1.5 text-[#047857]">
                                        {t('للبوتيكات :dues — ويبقى للمتجر من إيراد البند :rest (قبل خصم الفاتورة وتكاليفه الأخرى)', {
                                            dues: fmt(l.dues), rest: fmt(l.shop_remaining),
                                        })}
                                    </p>
                                )}
                            </div>
                        ))}

                        <div className="rounded-[10px] border border-[var(--ui-border,#e8e8e8)]" data-testid="boutique-invoice-shares">
                            <p className="border-b border-[var(--ui-border,#e8e8e8)] px-3 py-2 text-[12px] font-semibold text-[#4b4b4b]">
                                {t('نصيب كل جهة من بنود الفاتورة')}
                            </p>
                            <ul className="divide-y divide-[var(--ui-border,#e8e8e8)]">
                                {data.shares.boutiques.map((b) => (
                                    <li key={b.id} className="flex flex-wrap items-baseline justify-between gap-2 px-3 py-2">
                                        <span>
                                            {b.name}
                                            <span className="block text-[12px] text-[#6b7280]">
                                                {t('صافي المنفرد :net + مستحق القطع :pieces', { net: fmt(b.standalone_net), pieces: fmt(b.components_due) })}
                                            </span>
                                        </span>
                                        <span className="font-medium tabular-nums text-[#6d28d9]">{fmt(b.owed)}</span>
                                    </li>
                                ))}
                                {data.shares.shop !== null && data.shares.lines_total !== null && (
                                    <>
                                        <li className="flex flex-wrap items-baseline justify-between gap-2 px-3 py-2">
                                            <span>
                                                {t('المتجر')}
                                                <span className="block text-[12px] text-[#6b7280]">{t('منتجاته وعمولته وباقي باقاته — قبل خصم الفاتورة')}</span>
                                            </span>
                                            <span className="font-medium tabular-nums text-[#047857]">{fmt(data.shares.shop)}</span>
                                        </li>
                                        <li className="flex justify-between gap-2 bg-[#fafafa] px-3 py-2 font-semibold tabular-nums">
                                            <span>{t('مجموع البنود')}</span>
                                            <span>{fmt(data.shares.lines_total)}</span>
                                        </li>
                                    </>
                                )}
                            </ul>
                        </div>

                        {!data.shares.void && data.money && <MoneyBlock money={data.money} fmt={fmt} />}

                        {data.shares.void && (
                            <p className="rounded-md bg-[#fef2f2] px-3 py-2 text-[12px] text-[#b91c1c]" data-testid="boutique-invoice-void">
                                {t('الفاتورة ملغاة — بنودها كما سُجّلت، ولا نصيب فيها لأحد.')}
                            </p>
                        )}

                        {!data.shares.reconciles && (
                            <p className="rounded-md bg-[#fef2f2] px-3 py-2 text-[12px] text-[#b91c1c]" data-testid="boutique-invoice-mismatch">
                                {t('بنود الفاتورة لا تطابق إجماليها المسجّل — راجعها قبل اعتماد الأنصبة.')}
                            </p>
                        )}

                        <p className="text-[12px] leading-5 text-[#6b7280]">
                            {data.order.tax_basis === 'inside'
                                ? t('عمولة المتجر محسوبة من مبلغ البند كما سُجّل، وهو هنا شامل الضريبة. الخصم والتوصيل لا يُوزَّعان على الجهات.')
                                : data.order.tax_basis === 'added'
                                  ? t('عمولة المتجر محسوبة من مبلغ البند قبل الضريبة المضافة. الخصم والضريبة والتوصيل لا تُوزَّع على الجهات.')
                                  : t('الخصم والتوصيل لا يُوزَّعان على الجهات. والأنصبة للمتابعة حتى تصدر ورقة التسوية وتُراجع.')}
                        </p>

                        {data.edits.length > 0 && (
                            <div>
                                <p className="mb-1 text-[12px] font-semibold text-[#4b4b4b]">{t('ما جرى على الفاتورة بعد البيع')}</p>
                                <ul className="space-y-0.5 text-[12px] text-[#6b7280]">
                                    {data.edits.map((e, i) => (
                                        <li key={i}>
                                            {e.at} — {t(e.kind)}
                                            {e.subject && <> «{e.subject}»</>}
                                            {e.qty_before !== null && e.qty_after !== null && <> {number(e.qty_before)} → {number(e.qty_after)}</>}
                                            {e.reason && <> — {e.reason}</>}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}

                        {data.can_open && data.url && (
                            <a href={data.url} className="inline-flex items-center gap-1 text-[13px] font-medium text-[#1d4ed8] underline">
                                <ExternalLink className="size-3.5" />
                                {t('فتح الفاتورة كاملة')}
                            </a>
                        )}
                    </div>
                )}
                </div>
            </DialogContent>
        </Dialog>
    );
}
