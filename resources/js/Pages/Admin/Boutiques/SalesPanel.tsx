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

export type SaleType = 'standalone' | 'bundle' | 'addon';
export type SettleState = 'pending' | 'issued' | 'paid';
export type InvoiceState = 'active' | 'partial' | 'cancelled';

export interface SaleRow {
    key: string;
    type: SaleType;
    order_id: number;
    order_number: string;
    ordered_at: string | null;
    order_status: string;
    invoice_state: InvoiceState;
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

export interface SalesSummary {
    standalone: { gross: number; commission: number; net: number; quantity: number; lines: number; orders: number; taxed_orders: number };
    components: { bundle: number; addon: number; due: number; quantity: number };
    bundles: { lines: number; revenue: number; boutiques_due: number; shop_remaining: number };
    owed: number;
    pieces: 'on' | 'off' | 'taxed';
    /** مفتاحُ المنصّة لهذا المتجر — والشاشةُ تبقى لمن عنده بوتيكٌ ولو أُطفئ */
    hosting: boolean;
    /** مستحقُّ قطعٍ كُتب قبل إطفاء حسابها — معروضٌ محسوب ولم يُحذف */
    pieces_recorded: boolean;
    /** مطابقةُ التقريب بالبيسة: الفرقُ موجبٌ حين تقول دالّةُ النظام أكثر */
    rounding: {
        lines_commission: number; report_commission: number; report_difference: number;
        lines_pending_net: number; sheet_pending_net: number; sheet_difference: number;
    };
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
    order: {
        id: number; number: string; ordered_at: string | null; status: string; invoice_state: InvoiceState;
        subtotal: number; discount: number; tax: number; delivery_fee: number; total: number;
        /** أين الضريبة من أسعار البنود — مقروءةً من الفاتورة نفسها */
        tax_basis: 'none' | 'inside' | 'added' | 'unknown';
    };
    lines: {
        id: number; name: string; variant: string | null; quantity: number; price: number; total: number; addons_total: number;
        boutique: { name: string; rate: number; commission: number; net: number; settlement: string | null; settle_state: SettleState } | null;
        pieces: Piece[];
        dues: number;
        shop_remaining: number | null;
        shop_share: number;
    }[];
    money: {
        lines: number; discount: number; discount_coupon: number; discount_points: number;
        tax: number; tax_basis: 'none' | 'inside' | 'added' | 'unknown'; delivery_fee: number;
        total: number; paid: number; balance: number; boutiques_owed: number;
        shop_after_discount: number; shop_after_tax: number;
    };
    shares: {
        boutiques: { id: number; name: string; standalone_gross: number; commission: number; standalone_net: number; components_due: number; owed: number }[];
        shop: number;
        lines_total: number;
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
    bundle: 'ضمن باقة',
    addon: 'إضافة اختيارية',
};

export const SETTLE_LABEL: Record<SettleState, string> = {
    pending: 'لم تُسوَّ',
    issued: 'في ورقة لم تُسدَّد',
    paid: 'سُدِّدت',
};

export const STATE_LABEL: Record<InvoiceState, string> = {
    active: 'سارية',
    partial: 'مرتجعة جزئيًا',
    cancelled: 'ملغاة',
};

const SETTLE_VARIANT: Record<SettleState, 'neutral' | 'warning' | 'success'> = {
    pending: 'neutral',
    issued: 'warning',
    paid: 'success',
};

const TYPE_VARIANT: Record<SaleType, 'info' | 'primary' | 'outline'> = {
    standalone: 'info',
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
    const [opened, setOpened] = useState<number | null>(null);

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
                    {t('كل بيعٍ بفاتورته الأصلية: صنف البوتيك منفردًا، أو قطعته داخل منتج المتجر — دون احتساب البيع أو المستحق مرتين.')}
                </p>
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

            <InvoiceDialog orderId={opened} onClose={() => setOpened(null)} fmt={fmt} />
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
                    <Tile label={t('مبيعات منتجات البوتيك المنفردة')} value={fmt(s.standalone.gross)} />
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
                        hint={t('صافي المنفرد + مستحق القطع')}
                        tone="strong"
                    />
                </div>
                <p className="mt-2 text-[12px] leading-5 text-[#6b7280]" data-testid="boutique-not-final-note">
                    {t('المبالغ قبل صدور ورقة التسوية للمتابعة وليست تسوية نهائية — المعتمد ما في ورقة التسوية بعد مراجعتها.')}
                </p>
                {(s.rounding.report_difference !== 0 || s.rounding.sheet_difference !== 0) && (
                    <div className="mt-2 rounded-md border border-dashed border-[var(--ui-border,#e8e8e8)] px-3 py-2 text-[12px] leading-5 text-[#4b4b4b]" data-testid="boutique-rounding-note">
                        <p className="font-semibold">{t('مطابقة التقريب (لم يُعدَّل أي مبلغ)')}</p>
                        <p>
                            {t('العمولة هنا لكل بند بالبيسة: :lines', { lines: fmt(s.rounding.lines_commission) })}
                            {s.rounding.report_difference !== 0 &&
                                <> · {t('تقرير مبيعات البوتيك يقرّبها على المجموع: :report (فرق :d)', { report: fmt(s.rounding.report_commission), d: fmt(s.rounding.report_difference) })}</>}
                        </p>
                        {s.rounding.sheet_difference !== 0 && (
                            <p>
                                {t('ورقة التسوية لما لم يُسوَّ تحسب صافي البوتيك :sheet مقابل :lines هنا (فرق :d) — المعتمد للدفع الورقة حتى يُقرَّر.', {
                                    sheet: fmt(s.rounding.sheet_pending_net), lines: fmt(s.rounding.lines_pending_net), d: fmt(s.rounding.sheet_difference),
                                })}
                            </p>
                        )}
                    </div>
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
                              ? t('حساب قطع البوتيك داخل منتجات المتجر مطفأ الآن — ما يُباع بعد إطفائه لا يُحتسب، وما سُجّل قبله معروض ومحسوب كما هو. وسداد ما صدر منه في ورقة تسوية يحتاج إعادة تشغيله من إدارة المنصة.')
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

function InvoiceStateBadge({ row }: { row: Pick<SaleRow, 'invoice_state' | 'order_status'> }) {
    const t = useTranslate();
    if (row.invoice_state === 'cancelled') return <Badge variant="danger">{t('ملغاة')}</Badge>;
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

function RowsTable({ rows, fmt, onOpen }: { rows: SaleRow[]; fmt: (v: number) => string; onOpen: (id: number) => void }) {
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
                                <button type="button" onClick={() => onOpen(r.order_id)} className="whitespace-nowrap font-medium text-[#1d4ed8] underline-offset-2 hover:underline">
                                    {r.order_number}
                                </button>
                                <p className="text-[11px] text-[#9ca3af] tabular-nums">{r.ordered_at}</p>
                            </td>
                            <td className="px-3 py-2.5"><ProductCell row={r} fmt={fmt} /></td>
                            <td className="px-3 py-2.5 text-[#4b4b4b]">{r.boutique}</td>
                            <td className="px-3 py-2.5"><Badge variant={TYPE_VARIANT[r.type]}>{t(TYPE_LABEL[r.type])}</Badge></td>
                            <td className="whitespace-nowrap px-3 py-2.5 text-end tabular-nums">{number(r.quantity, 3)} × {fmt(r.unit_price)}</td>
                            <td className="whitespace-nowrap px-3 py-2.5 text-end tabular-nums">{fmt(r.amount)}</td>
                            <td className="whitespace-nowrap px-3 py-2.5 text-end tabular-nums text-[#047857]">
                                {r.type === 'standalone' ? <>{fmt(r.commission)} <span className="text-[11px] text-[#9ca3af]">({number(r.rate ?? 0, 2)}%)</span></> : t('لا عمولة')}
                            </td>
                            <td className="whitespace-nowrap px-3 py-2.5 text-end font-medium tabular-nums text-[#6d28d9]">{fmt(r.net)}</td>
                            <td className="space-y-1 px-3 py-2.5">
                                <InvoiceStateBadge row={r} />
                                <Badge variant={SETTLE_VARIANT[r.settle_state]} className="whitespace-nowrap">{t(SETTLE_LABEL[r.settle_state])}</Badge>
                                {r.settlement && <p className="whitespace-nowrap text-[11px] text-[#9ca3af]">{r.settlement}</p>}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function RowsCards({ rows, fmt, onOpen }: { rows: SaleRow[]; fmt: (v: number) => string; onOpen: (id: number) => void }) {
    const t = useTranslate();
    return (
        <ul className="divide-y divide-[var(--ui-border,#e8e8e8)] md:hidden" data-testid="boutique-sales-cards">
            {rows.map((r) => (
                <li key={r.key} className="space-y-1.5 px-4 py-3 text-[13px]">
                    <div className="flex items-center justify-between gap-2">
                        <button type="button" onClick={() => onOpen(r.order_id)} className="font-medium text-[#1d4ed8]">
                            {r.order_number}
                        </button>
                        <span className="text-[11px] text-[#9ca3af] tabular-nums">{r.ordered_at}</span>
                    </div>
                    <ProductCell row={r} fmt={fmt} />
                    <div className="flex flex-wrap items-center gap-1.5">
                        <Badge variant={TYPE_VARIANT[r.type]}>{t(TYPE_LABEL[r.type])}</Badge>
                        <span className="text-[12px] text-[#6b7280]">{r.boutique}</span>
                        <InvoiceStateBadge row={r} />
                        <Badge variant={SETTLE_VARIANT[r.settle_state]}>{t(SETTLE_LABEL[r.settle_state])}</Badge>
                    </div>
                    <div className="grid grid-cols-3 gap-2 tabular-nums">
                        <div><p className="text-[11px] text-[#9ca3af]">{t('الإجمالي')}</p>{fmt(r.amount)}</div>
                        <div><p className="text-[11px] text-[#9ca3af]">{t('عمولة المتجر')}</p>{r.type === 'standalone' ? fmt(r.commission) : t('لا عمولة')}</div>
                        <div><p className="text-[11px] text-[#9ca3af]">{t('صافي البوتيك')}</p><span className="font-medium text-[#6d28d9]">{fmt(r.net)}</span></div>
                    </div>
                </li>
            ))}
        </ul>
    );
}

/* ═══════════ تفاصيل الفاتورة ═══════════ */

/** المدفوع والخصومات والضريبة وحصّةُ المتجر بعدها — كلٌّ في سطره، ولا يُخلط بمجموع البنود */
function MoneyBlock({ money, fmt }: { money: InvoiceDetails['money']; fmt: (v: number) => string }) {
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
            {money.tax > 0 && row(money.tax_basis === 'inside' ? t('الضريبة (ضمن الأسعار)') : t('الضريبة (مضافة)'), fmt(money.tax))}
            {money.delivery_fee > 0 && row(t('التوصيل'), fmt(money.delivery_fee))}
            {row(t('إجمالي ما على العميل'), fmt(money.total), 'border-t border-dashed border-[var(--ui-border,#e8e8e8)] font-semibold text-[#111]')}
            {row(t('المدفوع فعليًا'), fmt(money.paid))}
            {money.balance > 0 && row(t('المتبقي على العميل'), fmt(money.balance))}
            <div className="mt-1 border-t border-[var(--ui-border,#e8e8e8)] pt-1">
                {row(t('مستحق البوتيكات'), fmt(money.boutiques_owed))}
                {row(t('حصة المتجر بعد تحمّل الخصومات'), fmt(money.shop_after_discount), 'text-[#047857]')}
                {money.tax_basis === 'inside' && money.tax > 0 && row(t('منها ضريبة يورّدها المتجر'), fmt(money.tax))}
                <p className="pt-1 text-[12px] leading-5 text-[#9ca3af]">
                    {t('حصة المتجر ليست صافي ربح: تكلفة المنتجات والمصاريف غير محسوبة فيها.')}
                </p>
            </div>
        </dl>
    );
}

function InvoiceDialog({ orderId, onClose, fmt }: { orderId: number | null; onClose: () => void; fmt: (v: number) => string }) {
    const t = useTranslate();
    const [data, setData] = useState<InvoiceDetails | null>(null);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        if (orderId === null) return;
        const ctl = new AbortController();
        setData(null);
        setFailed(false);
        getJson<InvoiceDetails>(route('admin.boutiques.sales.order', orderId), ctl.signal)
            .then(setData)
            .catch((e) => e?.name !== 'AbortError' && setFailed(true));
        return () => ctl.abort();
    }, [orderId]);

    return (
        <Dialog open={orderId !== null} onOpenChange={(o) => !o && onClose()}>
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
                            <InvoiceStateBadge row={{ invoice_state: data.order.invoice_state, order_status: data.order.status }} />
                        </div>

                        <dl className="grid grid-cols-2 gap-x-4 gap-y-1 rounded-[10px] bg-[#fafafa] p-3 tabular-nums sm:grid-cols-3" data-testid="boutique-invoice-header">
                            <div><dt className="text-[12px] text-[#6b7280]">{t('مجموع البنود')}</dt><dd>{fmt(data.shares.lines_total)}</dd></div>
                            {data.order.discount > 0 && (
                                <div><dt className="text-[12px] text-[#6b7280]">{t('خصم الفاتورة (على المتجر)')}</dt><dd>−{fmt(data.order.discount)}</dd></div>
                            )}
                            {data.order.tax > 0 && (
                                <div>
                                    <dt className="text-[12px] text-[#6b7280]">{data.order.tax_basis === 'inside' ? t('الضريبة (ضمن الأسعار)') : t('الضريبة (مضافة)')}</dt>
                                    <dd>{fmt(data.order.tax)}</dd>
                                </div>
                            )}
                            {data.order.delivery_fee > 0 && (
                                <div><dt className="text-[12px] text-[#6b7280]">{t('التوصيل')}</dt><dd>{fmt(data.order.delivery_fee)}</dd></div>
                            )}
                            <div><dt className="text-[12px] text-[#6b7280]">{t('إجمالي الفاتورة')}</dt><dd className="font-semibold text-[#111]">{fmt(data.order.total)}</dd></div>
                        </dl>

                        {data.lines.map((l) => (
                            <div key={l.id} className="rounded-[10px] border border-[var(--ui-border,#e8e8e8)] p-3">
                                <div className="flex flex-wrap items-baseline justify-between gap-2">
                                    <p className="font-medium text-[#111]">
                                        {l.name}
                                        {l.variant && <span className="text-[12px] text-[#9ca3af]"> — {l.variant}</span>}
                                    </p>
                                    <span className="tabular-nums text-[#4b4b4b]">
                                        {number(l.quantity)} × {fmt(l.price)} = {fmt(l.total)}
                                        {l.addons_total > 0 && <> + {t('إضافات :amount', { amount: fmt(l.addons_total) })}</>}
                                    </span>
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
                                        <span className="ms-1"><Badge variant={SETTLE_VARIANT[l.boutique.settle_state]}>{t(SETTLE_LABEL[l.boutique.settle_state])}</Badge></span>
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
                                                    {number(p.quantity, 3)} × {fmt(p.unit_price)} = <span className="font-medium text-[#6d28d9]">{fmt(p.amount)}</span>
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
                            </ul>
                        </div>

                        {!data.shares.void && <MoneyBlock money={data.money} fmt={fmt} />}

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
