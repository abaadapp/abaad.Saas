import { type ComponentProps, useMemo, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { AlertTriangle, Eye, Plus, PlusCircle, Save, Star, Trash2 } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import BackLink from '@/Components/BackLink';
import PageHeader from '@/Components/PageHeader';
import Field, { Select } from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input, Textarea } from '@/Components/ui/input';
import { csrfHeaders } from '@/lib/csrf';
import { money } from '@/lib/format';
import { invoiceTotals } from '@/lib/invoice-totals';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';
import { AddonDialog, ProductDialog } from './Create';

type Products = ComponentProps<typeof ProductDialog>['products'];
type Addons = ComponentProps<typeof AddonDialog>['addons'];

/** بندٌ كما صدر — بمعرّفه (`CustomerInvoiceController::edit`) */
interface ItemRow {
    item_id: number;
    parent_item_id: number | null;
    product_id: number | null;
    addon_id: number | null;
    description: string;
    quantity: number;
    unit_price: number;
    discount: number;
    tax_rate: number;
    line_total: number;
    details: string[];
}

interface InvoiceProps {
    id: number;
    number: string | null;
    status: string;
    state: string;
    /** draft · issued · paid — ومنه الصلاحيةُ التي سُئلت */
    kind: 'draft' | 'issued' | 'paid';
    revision: number;
    from_order: boolean;
    orders: string[];
    customer_id: number;
    issued_at: string | null;
    due_at: string | null;
    payment_terms_days: number | null;
    branch_id: number | null;
    po_number: string | null;
    contract_number: string | null;
    external_reference: string | null;
    department: string | null;
    cost_center: string | null;
    attention_to: string | null;
    notes: string | null;
    internal_notes: string | null;
    subtotal: number;
    discount_total: number;
    tax_total: number;
    total: number;
    paid: number;
    credited: number;
    outstanding: number;
    items: ItemRow[];
}

/**
 * بندٌ في النموذج — القائمُ بمعرّفه، والجديدُ بلا معرّف.
 *
 * والقائمُ يبقى صفَّه في الخادم: تتغيّر كمّيّتُه وسعرُه وخصمُه وضريبتُه،
 * ولقطتُه وأبوه كما كانا. وإضافةُ الكتالوج القائمة (`locked`) اسمُها وثمنُها
 * ثمنُ بيعها لا ثمنُ اليوم — فلا يُكتبان هنا.
 */
export interface EditLine {
    key: string;
    item_id: number | null;
    parent_item_id: number | null;
    product_id: string;
    addon_id: string;
    description: string;
    quantity: string;
    unit_price: string;
    discount: string;
    tax_rate: string;
    locked: boolean;
    details: string[];
}

/** ما سيقع لو حُفظ — `CustomerInvoiceController::editPreview` */
interface Plan {
    changed: boolean;
    kind: string;
    changes: { field: string; label: string; before: string | null; after: string | null }[];
    lines: { change: 'added' | 'removed' | 'changed'; id: number | null; before: LineShown | null; after: LineShown | null }[];
    money: {
        total_before: number;
        total_after: number;
        tax_before: number;
        tax_after: number;
        difference: number;
        tax_difference: number;
        paid: number;
        credited: number;
        outstanding_before: number;
        outstanding_after: number;
        customer_credit: number;
    };
    stock: { moves: boolean; why: string };
    ledger: { repost: boolean; live_entry_date: string | null };
    period: {
        needs_choice: boolean;
        mode: string | null;
        reversal_date: string | null;
        entry_date: string | null;
        closed_through: string | null;
        original_allowed: boolean;
    };
    needs_reason: boolean;
    missing: string[];
    blockers: string[];
}

interface LineShown {
    description: string;
    quantity: number;
    unit_price: number;
    discount: number;
    tax_rate: number | null;
}

let seq = 0;
const nextKey = () => `n${++seq}`;

/** البنودُ كما صدرت — كلٌّ بمعرّفه */
export function fromItems(items: ItemRow[]): EditLine[] {
    return items.map((i) => ({
        key: `i${i.item_id}`,
        item_id: i.item_id,
        parent_item_id: i.parent_item_id,
        product_id: i.product_id ? String(i.product_id) : '',
        addon_id: i.addon_id ? String(i.addon_id) : '',
        description: i.description,
        quantity: String(i.quantity),
        unit_price: String(i.unit_price),
        discount: String(i.discount),
        tax_rate: String(i.tax_rate),
        locked: i.addon_id !== null,
        details: i.details ?? [],
    }));
}

/**
 * حذفُ بندٍ ومعه إضافاتُه المدفوعةُ تحته.
 *
 * إضافةٌ بلا صنفها يردّها الخادم (`CustomerInvoiceEdits::lines`) — فتُحذف
 * معه هنا ولا يُترك من يضغط «احفظ» أمام رسالة.
 */
export function removeLine(lines: EditLine[], key: string): EditLine[] {
    const gone = lines.find((l) => l.key === key);

    return lines.filter((l) => l.key !== key && !(gone?.item_id && l.parent_item_id === gone.item_id));
}

/** البنودُ كما يقرؤها الخادم — القائمُ بمعرّفه، والجديدُ ببنده */
export function linesPayload(lines: EditLine[]) {
    return lines.map((l) => ({
        item_id: l.item_id,
        product_id: l.item_id ? null : l.product_id || null,
        addon_id: l.item_id ? null : l.addon_id || null,
        description: l.description,
        quantity: l.quantity,
        unit_price: l.unit_price,
        discount: l.discount || '0',
        tax_rate: l.tax_rate === '' ? null : l.tax_rate,
    }));
}

const TERMS = [0, 7, 15, 30, 45, 60, 90] as const;

function addDays(from: string, days: number): string {
    const d = new Date(from);
    d.setDate(d.getDate() + days);

    return d.toISOString().slice(0, 10);
}

/**
 * تعديلُ فاتورة العميل — مسودّةً أو صادرةً أو مدفوعة.
 *
 * والحسابُ هنا معاينةٌ لا مصدر: الخادمُ يعيد كلَّ رقمٍ من البنود تحت قفل
 * الورقة. و«معاينة التعديل» تسأله ما سيقع — الفرقَ في المال والضريبة، وما
 * يبقى على العميل أو يصير له، وأين يُكتب التصحيح في الدفتر — قبل أن يُحفظ.
 */
export default function CustomerInvoiceEdit() {
    const { invoice, customers, branches, products, addons, tax_rate, tax_inclusive, today, edit_token, may, context, errors } = usePage<
        PageProps<{
            invoice: InvoiceProps;
            customers: { id: number; name: string; phone: string | null; payment_terms_days: number | null }[];
            branches: { id: number; name: string; allowed: boolean }[];
            products: Products;
            addons: Addons;
            tax_rate: number;
            /** قاعدةُ الورقة نفسِها — و`null` حين لا تُعرف من بنودها */
            tax_inclusive: boolean | null;
            today: string;
            edit_token: string;
            may: { correct_period: boolean; owner: boolean };
            errors: Record<string, string>;
        }>
    >().props;
    const t = useTranslate();
    const m = (v: number) => money(v, context!.currency);
    const label = invoice.number ?? `${t('مسودة')} #${invoice.id}`;
    const locked = invoice.from_order;

    const [head, setHead] = useState({
        customer_id: String(invoice.customer_id),
        issued_at: invoice.issued_at ?? today,
        due_at: invoice.due_at ?? '',
        payment_terms_days: invoice.payment_terms_days === null ? '' : String(invoice.payment_terms_days),
        branch_id: invoice.branch_id === null ? '' : String(invoice.branch_id),
        po_number: invoice.po_number ?? '',
        contract_number: invoice.contract_number ?? '',
        external_reference: invoice.external_reference ?? '',
        department: invoice.department ?? '',
        cost_center: invoice.cost_center ?? '',
        attention_to: invoice.attention_to ?? '',
        notes: invoice.notes ?? '',
        internal_notes: invoice.internal_notes ?? '',
    });
    const [lines, setLines] = useState<EditLine[]>(() => fromItems(invoice.items));
    const [reason, setReason] = useState('');
    const [periodMode, setPeriodMode] = useState('');
    const [plan, setPlan] = useState<{ key: string; plan: Plan } | null>(null);
    const [planError, setPlanError] = useState<string | null>(null);
    const [planning, setPlanning] = useState(false);
    const [saving, setSaving] = useState(false);
    const [picking, setPicking] = useState(false);
    const [pickingAddon, setPickingAddon] = useState(false);

    const set = (key: keyof typeof head, value: string) => setHead((h) => ({ ...h, [key]: value }));
    const patch = (key: string, next: Partial<EditLine>) =>
        setLines((prev) => prev.map((l) => (l.key === key ? { ...l, ...next } : l)));

    /*
     * وما يُرسَل للمعاينة هو ما يُرسَل للحفظ — حمولةٌ واحدة.
     *
     * والمولودةُ من طلبٍ لا تُرسَل بنودُها: غيابُها عند الخادم «كما هي».
     */
    const payload = useMemo(
        () => ({
            ...head,
            payment_terms_days: head.payment_terms_days === '' ? null : head.payment_terms_days,
            branch_id: head.branch_id === '' ? null : head.branch_id,
            due_at: head.due_at === '' ? null : head.due_at,
            items: locked ? null : linesPayload(lines),
            reason,
            period_mode: periodMode || null,
            expected_revision: invoice.revision,
            edit_token,
        }),
        [head, lines, reason, periodMode, locked, invoice.revision, edit_token],
    );

    // والمعاينةُ تخصّ النموذجَ الذي عوينت عليه — تغييرٌ بعدها يطلب معاينةً جديدة
    const planKey = JSON.stringify({ ...payload, reason: undefined });
    const current = plan && plan.key === planKey ? plan.plan : null;

    const inclusive = tax_inclusive ?? false;
    const totals = useMemo(() => invoiceTotals(lines, inclusive), [lines, inclusive]);

    const preview = async () => {
        setPlanning(true);
        setPlanError(null);

        try {
            const res = await fetch(route('admin.customerInvoices.editPreview', invoice.id), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...csrfHeaders(),
                },
                credentials: 'same-origin',
                body: JSON.stringify(payload),
            });
            const body = await res.json();

            if (!res.ok) {
                const first = body?.errors ? Object.values(body.errors as Record<string, string[]>)[0]?.[0] : null;
                setPlanError(first ?? body?.message ?? t('تعذّرت المعاينة — أعد المحاولة.'));
                setPlan(null);

                return;
            }

            setPlan({ key: planKey, plan: body as Plan });
        } catch {
            setPlanError(t('تعذّرت المعاينة — أعد المحاولة.'));
        } finally {
            setPlanning(false);
        }
    };

    const save = () => {
        setSaving(true);
        router.put(route('admin.customerInvoices.update', invoice.id), payload, {
            preserveScroll: true,
            onFinish: () => setSaving(false),
        });
    };

    const mayChoose = current?.period.needs_choice ?? false;
    const ready =
        current !== null &&
        current.changed &&
        current.blockers.length === 0 &&
        current.missing.length === 0 &&
        (!current.needs_reason || reason.trim() !== '') &&
        (!mayChoose || periodMode !== '');

    const kindLabel: Record<string, string> = {
        draft: t('مسودة — لا قيد ولا ذمة'),
        issued: t('صادرة غير مدفوعة — يُعكس قيدها ويُرحّل بالمبلغ الجديد'),
        paid: t('مدفوعة جزئيًا أو كليًا — المقبوض لا يُمس'),
    };

    const termOptions = TERMS.map((d) => ({ label: d === 0 ? t('مستحق فورًا') : `${d} ${d <= 10 ? t('أيام') : t('يومًا')}`, value: String(d) }));

    const errorList = Object.entries(errors ?? {}).filter(([k]) => !k.startsWith('items.'));

    return (
        <AdminLayout title={`${t('تعديل الفاتورة')} ${label}`}>
            <BackLink
                routeName="admin.customerInvoices.show"
                href={route('admin.customerInvoices.show', invoice.id)}
                label={label}
            />

            <PageHeader
                title={`${t('تعديل الفاتورة')} ${label}`}
                subtitle={`${invoice.state} — ${kindLabel[invoice.kind]}`}
                actions={
                    <>
                        <Button type="button" variant="outline" disabled={planning} onClick={preview} data-testid="edit-preview">
                            <Eye />
                            {t('معاينة التعديل')}
                        </Button>
                        <Button type="button" disabled={!ready || saving} onClick={save} data-testid="edit-save">
                            <Save />
                            {t('حفظ التعديل')}
                        </Button>
                    </>
                }
            />

            {errorList.length > 0 && (
                <Card className="mb-4 border-[#fecaca] bg-[#fef2f2] p-3 text-[13px] text-[#b91c1c]" data-testid="edit-errors">
                    {errorList.map(([k, v]) => (
                        <p key={k}>{v}</p>
                    ))}
                </Card>
            )}

            {locked && (
                <Card className="mb-4 border-[#fde68a] bg-[#fffbeb] p-3 text-[13px] text-[#92400e]" data-testid="edit-from-order">
                    {t('هذه الفاتورة مولودة من طلب — بنودها ومبلغها وتاريخها وعميلها من الطلب. يُعدَّل هنا ما لا مال فيه، ويُصحَّح المال بإشعار دائن.')}
                </Card>
            )}

            {invoice.kind === 'paid' && (
                <Card className="mb-4 border-[#bfdbfe] bg-[#eff6ff] p-3 text-[13px] text-[#1e40af]" data-testid="edit-paid-note">
                    {t('دُفع من هذه الفاتورة :n. المقبوض لا يتغير: إن زاد الإجمالي بقي الفرق على العميل، وإن نقص عما قُبض صار الفائض رصيدًا دائنًا له — ولا يُرد مال تلقائيًا.').replace(':n', m(invoice.paid))}
                </Card>
            )}

            <div className="grid grid-cols-1 gap-6 xl:grid-cols-5">
                <div className="min-w-0 space-y-4 xl:col-span-3">
                    <Card className="space-y-4 p-4">
                        <h2 className="font-bold text-[#111]">{t('العميل والتواريخ')}</h2>
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <Field label="العميل" error={errors?.customer_id}>
                                <Select
                                    id="customer"
                                    value={head.customer_id}
                                    disabled={locked}
                                    onChange={(e) => set('customer_id', e.target.value)}
                                    options={customers.map((c) => ({ label: c.phone ? `${c.name} — ${c.phone}` : c.name, value: String(c.id) }))}
                                />
                            </Field>
                            <Field label="الفرع" error={errors?.branch_id}>
                                <Select
                                    id="branch"
                                    value={head.branch_id}
                                    disabled={locked}
                                    onChange={(e) => set('branch_id', e.target.value)}
                                    placeholder="بلا فرع"
                                    options={branches.map((b) => ({ label: b.name, value: String(b.id), disabled: !b.allowed && String(b.id) !== head.branch_id }))}
                                />
                            </Field>
                            <Field label="تاريخ الفاتورة" error={errors?.issued_at}>
                                <Input
                                    type="date"
                                    value={head.issued_at}
                                    disabled={locked}
                                    onChange={(e) => set('issued_at', e.target.value)}
                                    data-testid="edit-issued-at"
                                />
                            </Field>
                            <Field label="شروط الدفع (أيام)">
                                <Select
                                    id="terms"
                                    value={head.payment_terms_days}
                                    onChange={(e) => {
                                        const v = e.target.value;
                                        setHead((h) => ({ ...h, payment_terms_days: v, due_at: v === '' ? h.due_at : addDays(h.issued_at || today, Number(v)) }));
                                    }}
                                    placeholder="بلا مدة"
                                    options={termOptions}
                                />
                            </Field>
                            <Field label="تاريخ الاستحقاق" error={errors?.due_at}>
                                <Input type="date" value={head.due_at} onChange={(e) => setHead((h) => ({ ...h, due_at: e.target.value, payment_terms_days: '' }))} />
                            </Field>
                        </div>
                    </Card>

                    <Card className="p-4">
                        <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                            <h2 className="font-bold text-[#111]">{t('البنود')}</h2>
                            {!locked && (
                                <div className="flex flex-wrap gap-2">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        data-testid="edit-add-line"
                                        onClick={() =>
                                            setLines((prev) => [
                                                ...prev,
                                                { key: nextKey(), item_id: null, parent_item_id: null, product_id: '', addon_id: '', description: '', quantity: '1', unit_price: '0', discount: '0', tax_rate: String(tax_rate), locked: false, details: [] },
                                            ])
                                        }
                                    >
                                        <Plus />
                                        {t('إضافة بند')}
                                    </Button>
                                    <Button type="button" variant="outline" size="sm" onClick={() => setPicking(true)}>
                                        <PlusCircle />
                                        {t('من الكتالوج')}
                                    </Button>
                                    <Button type="button" variant="outline" size="sm" onClick={() => setPickingAddon(true)}>
                                        <Star />
                                        {t('إضافة')}
                                    </Button>
                                </div>
                            )}
                        </div>
                        {errors?.items && <p className="mb-2 text-[12px] text-[#b91c1c]">{errors.items}</p>}

                        <div className="overflow-x-auto no-scrollbar">
                            <table className="w-full min-w-[640px] text-[13px]">
                                <thead className="text-[12px] text-[#71717a]">
                                    <tr>
                                        <th className="p-2 text-start">{t('البيان')}</th>
                                        <th className="w-24 p-2 text-start">{t('الكمية')}</th>
                                        <th className="w-28 p-2 text-start">{t('السعر')}</th>
                                        <th className="w-24 p-2 text-start">{t('الخصم')}</th>
                                        <th className="w-20 p-2 text-start">{t('الضريبة %')}</th>
                                        <th className="w-10 p-2" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {lines.map((l) => (
                                        <tr key={l.key} data-testid={l.item_id ? 'edit-line' : 'edit-line-new'} className="border-t border-[var(--ui-border,#e8e8e8)] align-top">
                                            <td className={cn('p-2', l.parent_item_id && 'ps-6')}>
                                                <Input
                                                    value={l.description}
                                                    disabled={locked || l.locked}
                                                    onChange={(e) => patch(l.key, { description: e.target.value })}
                                                    placeholder={t('وصف البند')}
                                                />
                                                {l.details.map((d, k) => (
                                                    <span key={k} className="mt-1 block text-[12px] text-[#6b7280]">
                                                        <bdi>{d}</bdi>
                                                    </span>
                                                ))}
                                                {l.locked && <span className="mt-1 block text-[11px] text-[#6b7280]">{t('إضافة من الكتالوج — اسمها وسعرها كما بيعت')}</span>}
                                            </td>
                                            <td className="p-2">
                                                <Input type="number" inputMode="decimal" min="0" step="any" value={l.quantity} disabled={locked} onChange={(e) => patch(l.key, { quantity: e.target.value })} data-testid="edit-qty" />
                                            </td>
                                            <td className="p-2">
                                                <Input type="number" inputMode="decimal" min="0" step="any" value={l.unit_price} disabled={locked || l.locked} onChange={(e) => patch(l.key, { unit_price: e.target.value })} data-testid="edit-price" />
                                            </td>
                                            <td className="p-2">
                                                <Input type="number" inputMode="decimal" min="0" step="any" value={l.discount} disabled={locked} onChange={(e) => patch(l.key, { discount: e.target.value })} data-testid="edit-discount" />
                                            </td>
                                            <td className="p-2">
                                                <Input type="number" inputMode="decimal" min="0" max="100" step="any" value={l.tax_rate} disabled={locked} onChange={(e) => patch(l.key, { tax_rate: e.target.value })} data-testid="edit-tax" />
                                            </td>
                                            <td className="p-2">
                                                {!locked && lines.length > 1 && (
                                                    <Button type="button" variant="ghost" size="icon" aria-label={t('حذف البند')} onClick={() => setLines((prev) => removeLine(prev, l.key))}>
                                                        <Trash2 />
                                                    </Button>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>

                        <dl className="mt-4 ms-auto max-w-xs space-y-1.5 border-t border-[var(--ui-border,#e8e8e8)] pt-4 text-[13px]">
                            <Row label={t('الإجمالي قبل التعديل')} value={m(invoice.total)} />
                            <Row label={t('المجموع الفرعي')} value={m(locked ? invoice.subtotal : totals.subtotal)} />
                            {(locked ? invoice.discount_total : totals.discount) > 0 && <Row label={t('الخصم')} value={m(locked ? invoice.discount_total : totals.discount)} />}
                            <Row label={t('الضريبة')} value={m(locked ? invoice.tax_total : totals.tax)} />
                            <Row label={t('الإجمالي بعد التعديل')} value={m(locked ? invoice.total : totals.total)} strong />
                        </dl>
                        {tax_inclusive === null && !locked && invoice.kind !== 'draft' && (
                            <p className="mt-2 text-[12px] text-[#b45309]">{t('تعذّر معرفة قاعدة ضريبة هذه الفاتورة من بنودها — تعديل مالها مردود، ويُصحَّح بإشعار دائن.')}</p>
                        )}
                    </Card>

                    <Card className="space-y-3 p-4">
                        <h2 className="font-bold text-[#111]">{t('المراجع والملاحظات')}</h2>
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            {(
                                [
                                    ['po_number', 'مرجع العميل (أمر الشراء)'],
                                    ['contract_number', 'رقم العقد'],
                                    ['external_reference', 'رقم المرجع'],
                                    ['department', 'القسم / الإدارة'],
                                    ['cost_center', 'مركز التكلفة'],
                                    ['attention_to', 'موجه إلى / عناية'],
                                ] as const
                            ).map(([key, name]) => (
                                <Field key={key} label={name} error={errors?.[key]}>
                                    <Input value={head[key]} onChange={(e) => set(key, e.target.value)} />
                                </Field>
                            ))}
                        </div>
                        <Field label="ملاحظات للعميل" error={errors?.notes}>
                            <Textarea rows={3} value={head.notes} onChange={(e) => set('notes', e.target.value)} />
                        </Field>
                        <Field label="ملاحظات داخلية" error={errors?.internal_notes}>
                            <Textarea rows={2} value={head.internal_notes} onChange={(e) => set('internal_notes', e.target.value)} />
                        </Field>
                    </Card>
                </div>

                <div className="min-w-0 space-y-4 xl:col-span-2">
                    <Card className="space-y-3 p-4">
                        <h2 className="font-bold text-[#111]">{t('سبب التعديل')}</h2>
                        <Textarea
                            rows={3}
                            maxLength={120}
                            value={reason}
                            onChange={(e) => setReason(e.target.value)}
                            placeholder={invoice.kind === 'draft' ? t('اختياري للمسودة') : t('يُحفظ في سجل التعديلات وعلى قيد التصحيح')}
                            data-testid="edit-reason"
                        />
                        {errors?.reason && <p className="text-[12px] text-[#b91c1c]">{errors.reason}</p>}
                    </Card>

                    <PlanPanel
                        plan={current}
                        stale={plan !== null && current === null}
                        error={planError}
                        planning={planning}
                        periodMode={periodMode}
                        onPeriod={(v) => setPeriodMode(v)}
                        mayCorrect={may.correct_period}
                        periodError={errors?.period_mode}
                        m={m}
                    />
                </div>
            </div>

            <ProductDialog
                open={picking}
                onOpenChange={setPicking}
                products={products}
                truncated={false}
                picked={lines.map((l) => Number(l.product_id)).filter((n) => n > 0)}
                onPick={(p) =>
                    setLines((prev) => [
                        ...prev,
                        { key: nextKey(), item_id: null, parent_item_id: null, product_id: String(p.id), addon_id: '', description: p.name, quantity: '1', unit_price: String(p.price), discount: '0', tax_rate: String(tax_rate), locked: false, details: [] },
                    ])
                }
            />
            <AddonDialog
                open={pickingAddon}
                onOpenChange={setPickingAddon}
                addons={addons}
                truncated={false}
                picked={lines.map((l) => Number(l.addon_id)).filter((n) => n > 0)}
                onPick={(a) =>
                    setLines((prev) => [
                        ...prev,
                        { key: nextKey(), item_id: null, parent_item_id: null, product_id: '', addon_id: String(a.id), description: a.name, quantity: '1', unit_price: String(a.price), discount: '0', tax_rate: String(tax_rate), locked: false, details: [] },
                    ])
                }
            />
        </AdminLayout>
    );
}

/**
 * ما سيقع لو حُفظ — الفرقُ والذمّةُ والدفترُ والمخزونُ وما يمنع.
 *
 * يُرسم من ردّ الخادم وحده: لا يُحسب هنا أين يقع التصحيح ولا ما يبقى على
 * العميل — حسابان في موضعين يفترقان يومًا.
 */
export function PlanPanel({
    plan,
    stale,
    error,
    planning,
    periodMode,
    onPeriod,
    mayCorrect,
    periodError,
    m,
}: {
    plan: Plan | null;
    stale: boolean;
    error: string | null;
    planning: boolean;
    periodMode: string;
    onPeriod: (v: string) => void;
    mayCorrect: boolean;
    periodError?: string;
    m: (v: number) => string;
}) {
    const t = useTranslate();

    if (error) {
        return (
            <Card className="border-[#fecaca] bg-[#fef2f2] p-4 text-[13px] text-[#b91c1c]" data-testid="plan-error">
                {error}
            </Card>
        );
    }

    if (!plan) {
        return (
            <Card className="p-4 text-[13px] text-[#6b7280]" data-testid="plan-empty">
                {planning
                    ? t('يُحسب أثر التعديل…')
                    : stale
                      ? t('تغيّر النموذج بعد المعاينة — اضغط «معاينة التعديل» من جديد قبل الحفظ.')
                      : t('اضغط «معاينة التعديل» لترى الفرق في المال والضريبة، وأثره على المدفوعات والدفتر، قبل الحفظ.')}
            </Card>
        );
    }

    const diff = (v: number) => (v > 0 ? `+${m(v)}` : m(v));

    return (
        <Card className="space-y-4 p-4 text-[13px]" data-testid="plan">
            <h2 className="font-bold text-[#111]">{t('أثر التعديل')}</h2>

            {plan.blockers.length > 0 && (
                <div className="space-y-1 rounded-lg border border-[#fecaca] bg-[#fef2f2] p-3 text-[#b91c1c]" data-testid="plan-blockers">
                    {plan.blockers.map((b, i) => (
                        <p key={i} className="flex gap-2">
                            <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                            <span>{b}</span>
                        </p>
                    ))}
                </div>
            )}
            {plan.missing.length > 0 && (
                <div className="rounded-lg border border-[#fecaca] bg-[#fef2f2] p-3 text-[#b91c1c]" data-testid="plan-missing">
                    {t('لا تملك صلاحية هذا التعديل:')} {plan.missing.join('، ')}
                </div>
            )}
            {!plan.changed && <p className="text-[#6b7280]">{t('لم يتغيّر شيء في الفاتورة.')}</p>}

            <dl className="space-y-1.5">
                <Row label={t('الإجمالي')} value={`${m(plan.money.total_before)} ← ${m(plan.money.total_after)}`} />
                <Row label={t('الفرق')} value={diff(plan.money.difference)} strong />
                <Row label={t('فرق الضريبة')} value={diff(plan.money.tax_difference)} />
                {plan.money.paid > 0 && <Row label={t('المقبوض — لا يتغير')} value={m(plan.money.paid)} />}
                {plan.money.credited > 0 && <Row label={t('الإشعارات الدائنة')} value={m(plan.money.credited)} />}
                <Row label={t('الباقي على العميل')} value={`${m(plan.money.outstanding_before)} ← ${m(plan.money.outstanding_after)}`} />
                {plan.money.customer_credit > 0 && (
                    <Row label={t('يصير رصيدًا دائنًا للعميل')} value={m(plan.money.customer_credit)} strong />
                )}
            </dl>

            {plan.money.customer_credit > 0 && (
                <p className="rounded-lg bg-[#eff6ff] p-2 text-[12px] text-[#1e40af]" data-testid="plan-credit">
                    {t('لا يُرد مال تلقائيًا: الفائض رصيد للعميل يُخصم من فواتيره القادمة أو يُرد بقرار من يملك ذلك.')}
                </p>
            )}

            {(plan.changes.length > 0 || plan.lines.length > 0) && (
                <div>
                    <h3 className="mb-1 font-semibold text-[#111]">{t('ما تغيّر')}</h3>
                    <ul className="space-y-1 text-[12px]" data-testid="plan-changes">
                        {plan.changes.map((c) => (
                            <li key={c.field}>
                                <span className="font-medium">{c.label}:</span> <bdi>{c.before ?? '—'}</bdi> ← <bdi>{c.after ?? '—'}</bdi>
                            </li>
                        ))}
                        {plan.lines.map((l, i) => (
                            <li key={`l${i}`}>
                                {l.change === 'added' && <span className="text-[#047857]">+ {t('بند جديد')}: </span>}
                                {l.change === 'removed' && <span className="text-[#b91c1c]">− {t('حُذف')}: </span>}
                                {l.change === 'changed' && <span className="text-[#1d4ed8]">≈ {t('عُدّل')}: </span>}
                                <bdi>{(l.after ?? l.before)?.description}</bdi>
                                {l.before && l.after && (
                                    <span className="text-[#6b7280]">
                                        {' '}
                                        ({l.before.quantity} × {l.before.unit_price} ← {l.after.quantity} × {l.after.unit_price})
                                    </span>
                                )}
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            <div className="space-y-1 text-[12px] text-[#4b4b4b]">
                <p data-testid="plan-stock">
                    <span className="font-medium">{t('المخزون')}:</span> {plan.stock.why}
                </p>
                <p data-testid="plan-ledger">
                    <span className="font-medium">{t('الدفتر')}:</span>{' '}
                    {plan.ledger.repost
                        ? plan.period.mode
                            ? t('يُعكس القيد بتاريخ :r ويُرحّل قيد بالمبلغ الجديد بتاريخ :e.')
                                  .replace(':r', plan.period.reversal_date ?? '—')
                                  .replace(':e', plan.period.entry_date ?? '—')
                            : t('يُعكس قيد :d ويُرحّل قيد جديد — اختر موضع التصحيح.').replace(':d', plan.ledger.live_entry_date ?? '—')
                        : t('لا قيد يتغيّر.')}
                </p>
                {plan.period.closed_through && (
                    <p>
                        <span className="font-medium">{t('الدفاتر مقفلة حتى')}:</span> <bdi>{plan.period.closed_through}</bdi>
                    </p>
                )}
            </div>

            {plan.period.needs_choice && (
                <fieldset className="space-y-2 rounded-lg border border-[#fde68a] bg-[#fffbeb] p-3" data-testid="plan-period">
                    <legend className="px-1 text-[12px] font-semibold text-[#92400e]">{t('قيد الفاتورة في شهر مضى أو فترة مقفلة — أين يُسجَّل التصحيح؟')}</legend>
                    {!mayCorrect && <p className="text-[12px] text-[#b91c1c]">{t('تصحيح فترة سابقة يحتاج صلاحية «تصحيح فاتورة عميل من فترة سابقة أو مقفلة».')}</p>}
                    <label className="flex items-start gap-2">
                        <input type="radio" name="period" value="current" checked={periodMode === 'current'} onChange={() => onPeriod('current')} />
                        <span>{t('في الفترة الحالية — يظهر الفرق في الشهر الجاري، وتبقى الفترة السابقة كما أُعلنت.')}</span>
                    </label>
                    <label className={cn('flex items-start gap-2', !plan.period.original_allowed && 'opacity-50')}>
                        <input type="radio" name="period" value="original" disabled={!plan.period.original_allowed} checked={periodMode === 'original'} onChange={() => onPeriod('original')} />
                        <span>{t('بتاريخ القيد الأصلي — لصاحب النشاط في فترة مفتوحة معلومة.')}</span>
                    </label>
                    {periodError && <p className="text-[12px] text-[#b91c1c]">{periodError}</p>}
                    {periodMode !== '' && periodMode !== plan.period.mode && (
                        <p className="text-[12px] text-[#92400e]">{t('اضغط «معاينة التعديل» لترى التواريخ بالاختيار الجديد.')}</p>
                    )}
                </fieldset>
            )}
        </Card>
    );
}

function Row({ label, value, strong = false }: { label: string; value: string; strong?: boolean }) {
    return (
        <div className="flex items-center justify-between gap-3">
            <dt className="text-[#6b7280]">{label}</dt>
            <dd className={cn('tabular-nums', strong && 'font-bold text-[#111]')} dir="ltr">
                {value}
            </dd>
        </div>
    );
}
