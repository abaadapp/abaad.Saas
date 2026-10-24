import { useEffect, useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { Landmark, Plus } from 'lucide-react';

import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { FINANCE_TABS } from '@/Components/SectionTabs';
import StatCard from '@/Components/StatCard';
import Field, { Select } from '@/Components/Field';
import SmartLink from '@/Components/SmartLink';
import { useConfirm } from '@/Components/ConfirmDialog';
import { JustCreated, LinkActions, LinkStatus, LinkWhy, NotConfigured, type PaymentLinkRow, type PaymentLinksPanel } from '@/Components/PaymentLinks';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { money } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface Option {
    value: string;
    label: string;
}

interface Settlement {
    id: number;
    settled_on: string | null;
    gross: number;
    fee: number;
    net: number;
    bank: string | null;
    reference: string | null;
    notes: string | null;
    /** posted رُحّلت · held حُفظت ولم تُرحَّل (والسببُ في hold_reason) · cancelled أُلغيت قبل ترحيلها */
    status: 'posted' | 'held' | 'cancelled';
    hold_reason: string | null;
    items: number;
}

/**
 * مدفوعةُ Paymob لم تحملها تسويةٌ بعد — وأين مالُها في الدفتر.
 * clearing: في «تحت التسوية» · bank: قُيِّد في البنك يومَ البيع (قبل التحديث) · unknown: لا يُعرف بثقة
 */
export interface Payout {
    key: string;
    type: 'intent' | 'link';
    id: number;
    amount: number;
    label: string;
    paid_at: string | null;
    booked: 'clearing' | 'bank' | 'unknown';
    reason: string | null;
}

interface Props extends PaymentLinksPanel {
    status: PaymentLinkRow['status'] | null;
    counts: Partial<Record<PaymentLinkRow['status'], number>>;
    customers: Option[];
    clearing: number;
    payouts?: Payout[];
    settlements: Settlement[];
    bankAccounts: Option[];
}

const FILTERS: Array<{ key: PaymentLinkRow['status'] | null; label: string }> = [
    { key: null, label: 'الكل' },
    { key: 'pending', label: 'بانتظار الدفع' },
    { key: 'paid', label: 'تم الدفع' },
    { key: 'expired', label: 'منتهٍ' },
    { key: 'cancelled', label: 'ملغى' },
];

/**
 * المالية ← روابط الدفع — وتسوياتُ Paymob.
 *
 * الرابطُ يطلب مالًا من عميل عبر حساب Paymob الخاص بالمتجر. يُدفع فيُكتب
 * تحصيلٌ في «Paymob تحت التسوية» — لا في البنك: Paymob تُحوّل المالَ بعد
 * أيّامٍ ناقصًا رسومَها، فيُكتب ذلك هنا «تسويةً» من كشفها.
 */
export default function PaymentLinksPage() {
    const props = usePage<PageProps<Props>>().props;
    const { links, ready, can, can_cancel, settings_url, status, counts, customers, clearing, settlements, bankAccounts, context } = props;
    const payouts = props.payouts ?? [];
    const t = useTranslate();
    // نافذةُ التأكيد من النظام لا من المتصفّح — انظر ConfirmDialog
    const [ask, confirmDialog] = useConfirm();
    const m = (v: number) => money(v, context!.currency);

    const [creating, setCreating] = useState(false);
    const [settling, setSettling] = useState(false);

    const go = (next: PaymentLinkRow['status'] | null) =>
        router.get(route('admin.finance.paymentLinks'), next ? { status: next } : {}, { preserveScroll: true, preserveState: true, replace: true });

    return (
        <AdminLayout title={t('روابط الدفع')}>
            <PageHeader
                title="روابط الدفع"
                subtitle={t('اطلب دفعةً من عميلك برابطٍ من حساب Paymob الخاص بك — يُسجَّل تحصيلًا حين يدفع.')}
                actions={
                    can &&
                    ready && (
                        <Button onClick={() => setCreating(true)} data-testid="payment-links-new">
                            <Plus />
                            {t('رابط دفع')}
                        </Button>
                    )
                }
            />

            <SectionTabs tabs={FINANCE_TABS} current="admin.finance.paymentLinks" />

            {!ready && (
                <div className="mb-4">
                    <NotConfigured settingsUrl={settings_url} />
                </div>
            )}

            <JustCreated />

            <div className="mb-4 flex flex-wrap gap-2">
                {FILTERS.map((f) => (
                    <Button key={f.label} size="sm" variant={f.key === status ? 'primary' : 'outline'} onClick={() => go(f.key)}>
                        {t(f.label)}
                        {f.key && counts[f.key] ? ` (${counts[f.key]})` : ''}
                    </Button>
                ))}
            </div>

            <Card className="mb-8 overflow-x-auto no-scrollbar p-0">
                <Table data-testid="payment-links-table">
                    <TableHeader>
                        <TableRow>
                            <TableHead>{t('العميل')}</TableHead>
                            <TableHead>{t('الفاتورة')}</TableHead>
                            <TableHead>{t('الوصف')}</TableHead>
                            <TableHead>{t('المبلغ')}</TableHead>
                            <TableHead>{t('الحالة')}</TableHead>
                            <TableHead>{t('أُنشئ')}</TableHead>
                            <TableHead>{t('ينتهي')}</TableHead>
                            <TableHead>{t('دُفع')}</TableHead>
                            <TableHead>{t('التحصيل')}</TableHead>
                            <TableHead>{t('الرابط')}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {links.length === 0 && (
                            <TableRow>
                                <TableCell colSpan={10} className="py-10 text-center text-[13px] text-[#9ca3af]">
                                    {t('لا روابط دفع بعد.')}
                                </TableCell>
                            </TableRow>
                        )}
                        {links.map((l) => (
                            <TableRow key={l.id} data-testid="payment-link-row">
                                <TableCell>
                                    <SmartLink routeName="admin.customers.show" href={route('admin.customers.show', l.customer.id)} className="hover:underline">
                                        {l.customer.name}
                                    </SmartLink>
                                </TableCell>
                                <TableCell>
                                    {l.invoice ? (
                                        <SmartLink
                                            routeName="admin.customerInvoices.show"
                                            href={route('admin.customerInvoices.show', l.invoice.id)}
                                            className="text-[#6d28d9] hover:underline"
                                        >
                                            {l.invoice.number}
                                        </SmartLink>
                                    ) : (
                                        <span className="text-[#9ca3af]">{t('بلا فاتورة')}</span>
                                    )}
                                </TableCell>
                                <TableCell className="max-w-[14rem] truncate text-[12.5px] text-[#6b7280]">{l.description ?? '—'}</TableCell>
                                <TableCell className="font-bold tabular-nums">{m(l.amount)}</TableCell>
                                <TableCell>
                                    <LinkStatus status={l.status} />
                                    {l.review && <span className="ms-2 text-[12px] text-[#b45309]">{t('تحتاج مراجعة')}</span>}
                                    <LinkWhy link={l} />
                                </TableCell>
                                <TableCell dir="ltr" className="text-start text-[12px]">{l.created_at}</TableCell>
                                <TableCell dir="ltr" className="text-start text-[12px]">{l.expires_at ?? '—'}</TableCell>
                                <TableCell dir="ltr" className="text-start text-[12px]">{l.paid_at ?? '—'}</TableCell>
                                <TableCell>
                                    {l.payment ? (
                                        <SmartLink
                                            routeName="admin.customerPayments.show"
                                            href={route('admin.customerPayments.show', l.payment.id)}
                                            className="text-[#6d28d9] hover:underline"
                                        >
                                            {l.payment.number}
                                        </SmartLink>
                                    ) : (
                                        '—'
                                    )}
                                </TableCell>
                                <TableCell>
                                    <LinkActions link={l} canCancel={!!can_cancel} />
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </Card>

            {/*
                تسوياتُ Paymob — ما حوّلته Paymob إلى بنكك ناقصًا رسومَها.
                والرصيدُ «تحت التسوية» مالٌ قبضه عملاؤك عبر الروابط ولم يصل البنك.
            */}
            <section data-testid="paymob-settlements">
                <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <h2 className="flex items-center gap-2 text-[16px] font-bold text-[#111]">
                        <Landmark className="size-4" />
                        {t('تسويات Paymob')}
                    </h2>
                    <Button variant="outline" onClick={() => setSettling(true)} data-testid="paymob-settle-open" disabled={payouts.length === 0}>
                        {t('تسجيل تسوية')}
                    </Button>
                </div>

                <div className="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <StatCard
                        stat={{ label: t('Paymob تحت التسوية'), value: m(clearing), icon: 'wallet', color: 'warning', hint: t('قبضه عملاؤك ولم يصل بنكك بعد') }}
                        index={0}
                    />
                </div>

                <Card className="overflow-x-auto no-scrollbar p-0">
                    <Table data-testid="paymob-settlements-table">
                        <TableHeader>
                            <TableRow>
                                <TableHead>{t('التاريخ')}</TableHead>
                                <TableHead>{t('الإجمالي')}</TableHead>
                                <TableHead>{t('الرسوم')}</TableHead>
                                <TableHead>{t('الصافي')}</TableHead>
                                <TableHead>{t('الحساب البنكي')}</TableHead>
                                <TableHead>{t('المرجع')}</TableHead>
                                <TableHead>{t('الحال')}</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {settlements.length === 0 && (
                                <TableRow>
                                    <TableCell colSpan={7} className="py-8 text-center text-[13px] text-[#9ca3af]">
                                        {t('لا تسويات بعد.')}
                                    </TableCell>
                                </TableRow>
                            )}
                            {settlements.map((s) => (
                                <TableRow key={s.id}>
                                    <TableCell dir="ltr" className="text-start text-[12px]">{s.settled_on}</TableCell>
                                    <TableCell className="tabular-nums">{m(s.gross)}</TableCell>
                                    <TableCell className="tabular-nums text-[#b91c1c]">{m(s.fee)}</TableCell>
                                    <TableCell className="font-bold tabular-nums">{m(s.net)}</TableCell>
                                    <TableCell>{s.bank ?? '—'}</TableCell>
                                    <TableCell dir="ltr" className="text-start text-[12px]">{s.reference ?? '—'}</TableCell>
                                    <TableCell data-testid="paymob-settlement-status">
                                        {s.status === 'held' ? (
                                            <div className="grid gap-1.5">
                                                <span className="font-bold text-[#b45309]">{t('معلّقة للمراجعة — لم تُرحَّل')}</span>
                                                {s.hold_reason && (
                                                    <span className="text-[12px] text-[#6b7280]" data-testid="paymob-settlement-hold-reason">
                                                        {s.hold_reason}
                                                    </span>
                                                )}
                                                <div className="flex flex-wrap gap-1.5">
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        data-testid="paymob-settlement-retry"
                                                        onClick={() => router.post(route('admin.finance.paymobSettlements.retry', s.id), {}, { preserveScroll: true })}
                                                    >
                                                        {t('إعادة الفحص')}
                                                    </Button>
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        data-testid="paymob-settlement-cancel"
                                                        onClick={async () => {
                                                            if (!(await ask({ message: 'إلغاء هذه التسوية قبل ترحيلها؟ تعود مدفوعاتها لتُختار، ولا يُكتب في الدفتر شيء.', action: 'إلغاء التسوية' }))) return;
                                                            router.post(route('admin.finance.paymobSettlements.cancel', s.id), {}, { preserveScroll: true });
                                                        }}
                                                    >
                                                        {t('إلغاء قبل الترحيل')}
                                                    </Button>
                                                </div>
                                            </div>
                                        ) : s.status === 'cancelled' ? (
                                            <span className="text-[#9ca3af]">{t('أُلغيت قبل الترحيل')}</span>
                                        ) : (
                                            <span>{t('مرحّلة')}</span>
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </Card>
            </section>

            <CreateDialog open={creating} onClose={() => setCreating(false)} customers={customers} />
            <SettleDialog open={settling} onClose={() => setSettling(false)} payouts={payouts} bankAccounts={bankAccounts} />
            {confirmDialog}
        </AdminLayout>
    );
}

/** «+ رابط دفع» — عميلٌ إلزاميّ، وفاتورةٌ اختياريّة من فواتيره التي عليها باقٍ */
function CreateDialog({ open, onClose, customers }: { open: boolean; onClose: () => void; customers: Option[] }) {
    const t = useTranslate();
    const [invoices, setInvoices] = useState<Array<Option & { outstanding: number }>>([]);
    const form = useForm({ customer_id: '', customer_invoice_id: '', amount: '', description: '' });

    // فواتيرُ العميل المختار — من الخادم، بمتجر الجلسة وحده
    useEffect(() => {
        setInvoices([]);
        form.setData('customer_invoice_id', '');
        if (!form.data.customer_id) return;
        fetch(route('admin.finance.paymentLinks.invoices', { customer_id: form.data.customer_id }), {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then((r) => (r.ok ? r.json() : []))
            .then((rows) => setInvoices(Array.isArray(rows) ? rows : []))
            .catch(() => setInvoices([]));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [form.data.customer_id]);

    const pickInvoice = (id: string) => {
        const invoice = invoices.find((i) => i.value === id);
        form.setData((d) => ({ ...d, customer_invoice_id: id, amount: invoice ? invoice.outstanding.toFixed(3) : d.amount }));
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({ ...d, customer_invoice_id: d.customer_invoice_id || null, amount: d.amount || null }));
        form.post(route('admin.finance.paymentLinks.store'), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onClose();
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>{t('رابط دفع جديد')}</DialogTitle>
                </DialogHeader>
                <form onSubmit={submit} className="grid gap-4 px-5 pb-5" data-testid="payment-links-create">
                    <Field label="العميل" error={form.errors.customer_id}>
                        <Select
                            value={form.data.customer_id}
                            onChange={(e) => form.setData('customer_id', e.target.value)}
                            options={customers}
                            placeholder="اختر العميل"
                            aria-label={t('العميل')}
                        />
                    </Field>
                    <Field label="الفاتورة" hint="اختيارية — بلا فاتورة يُسجَّل المبلغ رصيدًا للعميل" error={form.errors.customer_invoice_id}>
                        <Select
                            value={form.data.customer_invoice_id}
                            onChange={(e) => pickInvoice(e.target.value)}
                            options={invoices.map((i) => ({ value: i.value, label: i.label }))}
                            placeholder="بلا فاتورة"
                            aria-label={t('الفاتورة')}
                        />
                    </Field>
                    <Field label="المبلغ" error={form.errors.amount}>
                        <Input dir="ltr" inputMode="decimal" value={form.data.amount} onChange={(e) => form.setData('amount', e.target.value)} aria-label={t('المبلغ')} />
                    </Field>
                    <Field label="الوصف" hint="اختياري — يراه العميل في صفحة الدفع" error={form.errors.description}>
                        <Input value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} aria-label={t('الوصف')} />
                    </Field>
                    <div className="flex gap-2">
                        <Button type="submit" loading={form.processing} data-testid="payment-links-submit">
                            {t('إنشاء الرابط')}
                        </Button>
                        <Button type="button" variant="outline" onClick={onClose}>
                            {t('إلغاء')}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/** أين مالُ المدفوعة — وما يقع له إن اختيرت. انظر `PaymobSettlements` */
const BOOKED: Array<{ key: Payout['booked']; title: string; hint: string }> = [
    { key: 'clearing', title: 'تحت التسوية', hint: 'روابط الدفع وبيعات الموقع بعد التحديث — يخرج مالها من «تحت التسوية» ويدخل الصافي البنك.' },
    {
        key: 'bank',
        title: 'قُيّدت في البنك يوم البيع',
        hint: 'بيعات موقعٍ قبل التحديث — مالها في البنك أصلًا فلا يُضاف مرّة ثانية، ويُقيَّد فرق الرسوم وحده.',
    },
    { key: 'unknown', title: 'تحتاج مراجعة', hint: 'لا يُعرف بثقة أين مالها — إن اخترتها حُفظت التسوية ولم تُرحَّل حتى تراجعها.' },
];

/**
 * «تسجيل تسوية» — يختار التاجرُ المدفوعاتِ التي حملها تحويلُ Paymob.
 *
 * الإجماليُّ مجموعُ المختار لا رقمٌ يُكتب، والصافي والرسومُ من كشف Paymob أو
 * البنك، والخادمُ يتحقّق أنّ الإجماليّ = الصافي + الرسوم. وكلُّ مدفوعةٍ تقول
 * أين مالُها: فلا يدخل مالُ بيعةٍ قديمةٍ البنكَ مرّتين.
 */
function SettleDialog({ open, onClose, payouts, bankAccounts }: { open: boolean; onClose: () => void; payouts: Payout[]; bankAccounts: Option[] }) {
    const t = useTranslate();
    const form = useForm<{ items: string[]; fee: string; net: string; settled_on: string; bank_account_id: string; reference: string; notes: string }>({
        items: [],
        fee: '0',
        net: '',
        settled_on: new Date().toISOString().slice(0, 10),
        bank_account_id: bankAccounts[0]?.value ?? '',
        reference: '',
        notes: '',
    });

    // بالبيسة لا بالكسر العشريّ — كما يجمع الخادم
    const total = (keys: string[]) => payouts.filter((p) => keys.includes(p.key)).reduce((sum, p) => sum + Math.round(p.amount * 1000), 0) / 1000;
    const gross = total(form.data.items);
    const picked = payouts.filter((p) => form.data.items.includes(p.key));

    // الصافي يُقترح من المختار والرسوم — ويبقى قابلًا للكتابة، والخادمُ يتحقّق
    const suggest = (g: number, fee: string) => (Number.isFinite(Number(fee)) && g > 0 ? (g - Number(fee)).toFixed(3) : '');

    const toggle = (key: string) =>
        form.setData((d) => {
            const items = d.items.includes(key) ? d.items.filter((k) => k !== key) : [...d.items, key];

            return { ...d, items, net: suggest(total(items), d.fee) };
        });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(route('admin.finance.paymobSettlements.store'), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onClose();
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{t('تسجيل تسوية Paymob')}</DialogTitle>
                </DialogHeader>
                <form onSubmit={submit} className="grid gap-4 px-5 pb-5 sm:grid-cols-2" data-testid="paymob-settle-form">
                    <div className="grid gap-3 sm:col-span-2" data-testid="paymob-settle-payouts">
                        <p className="text-[13px] text-[#374151]">{t('اختر المدفوعات التي حملها هذا التحويل كما في كشف Paymob.')}</p>
                        {BOOKED.map((group) => {
                            const rows = payouts.filter((p) => p.booked === group.key);
                            if (rows.length === 0) return null;

                            return (
                                <fieldset key={group.key} className="rounded-lg border border-[#e5e7eb] p-3" data-testid={`paymob-settle-group-${group.key}`}>
                                    <legend className="px-1 text-[13px] font-bold">{t(group.title)}</legend>
                                    <p className="mb-2 text-[12px] text-[#6b7280]">{t(group.hint)}</p>
                                    <div className="grid gap-1.5">
                                        {rows.map((p) => (
                                            <label key={p.key} className="flex items-start gap-2 text-[13px]">
                                                <input
                                                    type="checkbox"
                                                    className="mt-1"
                                                    checked={form.data.items.includes(p.key)}
                                                    onChange={() => toggle(p.key)}
                                                    aria-label={p.label}
                                                    data-testid="paymob-settle-pick"
                                                />
                                                <span className="flex-1">
                                                    {p.label}
                                                    {p.paid_at && (
                                                        <span dir="ltr" className="ms-2 text-[12px] text-[#9ca3af]">
                                                            {p.paid_at}
                                                        </span>
                                                    )}
                                                    {p.reason && <span className="block text-[12px] text-[#b45309]">{p.reason}</span>}
                                                </span>
                                                <span className="tabular-nums">{p.amount.toFixed(3)}</span>
                                            </label>
                                        ))}
                                    </div>
                                </fieldset>
                            );
                        })}
                        {form.errors.items && <p className="text-[12px] text-[#b91c1c]">{form.errors.items}</p>}
                        {picked.some((p) => p.booked === 'bank') && (
                            <p className="rounded-md bg-[#eff6ff] p-2 text-[12px] text-[#1e40af]" data-testid="paymob-settle-bank-notice">
                                {t('في المختار بيعاتٌ مالُها في البنك منذ يوم البيع — لن يُضاف مرّة ثانية، ويُقيَّد فرق الرسوم وحده.')}
                            </p>
                        )}
                        {picked.some((p) => p.booked === 'unknown') && (
                            <p className="rounded-md bg-[#fffbeb] p-2 text-[12px] text-[#92400e]" data-testid="paymob-settle-hold-notice">
                                {t('في المختار ما يحتاج مراجعة — ستُحفظ التسوية ولا تُرحَّل حتى تراجعه.')}
                            </p>
                        )}
                    </div>
                    <Field label="الإجمالي" hint="مجموع المدفوعات المختارة">
                        <Input dir="ltr" value={gross.toFixed(3)} readOnly aria-label={t('الإجمالي')} data-testid="paymob-settle-gross" />
                    </Field>
                    <Field label="الرسوم" error={form.errors.fee}>
                        <Input
                            dir="ltr"
                            inputMode="decimal"
                            value={form.data.fee}
                            onChange={(e) => form.setData((d) => ({ ...d, fee: e.target.value, net: suggest(gross, e.target.value) }))}
                            aria-label={t('الرسوم')}
                        />
                    </Field>
                    <Field label="الصافي" hint="ما وصل البنك فعلًا" error={form.errors.net}>
                        <Input dir="ltr" inputMode="decimal" value={form.data.net} onChange={(e) => form.setData('net', e.target.value)} aria-label={t('الصافي')} />
                    </Field>
                    <Field label="تاريخ التسوية" error={form.errors.settled_on}>
                        <Input dir="ltr" type="date" value={form.data.settled_on} onChange={(e) => form.setData('settled_on', e.target.value)} aria-label={t('تاريخ التسوية')} />
                    </Field>
                    <Field label="الحساب البنكي" error={form.errors.bank_account_id}>
                        <Select
                            value={form.data.bank_account_id}
                            onChange={(e) => form.setData('bank_account_id', e.target.value)}
                            options={bankAccounts}
                            placeholder="الحساب الرئيسي"
                            aria-label={t('الحساب البنكي')}
                        />
                    </Field>
                    <Field label="المرجع" error={form.errors.reference}>
                        <Input dir="ltr" value={form.data.reference} onChange={(e) => form.setData('reference', e.target.value)} aria-label={t('المرجع')} />
                    </Field>
                    <Field label="ملاحظات" error={form.errors.notes} className="sm:col-span-2">
                        <Input value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} aria-label={t('ملاحظات')} />
                    </Field>
                    <div className="flex gap-2 sm:col-span-2">
                        <Button type="submit" loading={form.processing} disabled={form.data.items.length === 0} data-testid="paymob-settle-submit">
                            {t('تسجيل التسوية')}
                        </Button>
                        <Button type="button" variant="outline" onClick={onClose}>
                            {t('إلغاء')}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
