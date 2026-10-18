import { useEffect, useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { Landmark, Plus } from 'lucide-react';

import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { FINANCE_TABS } from '@/Components/SectionTabs';
import StatCard from '@/Components/StatCard';
import Field, { Select } from '@/Components/Field';
import SmartLink from '@/Components/SmartLink';
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
}

interface Props extends PaymentLinksPanel {
    status: PaymentLinkRow['status'] | null;
    counts: Partial<Record<PaymentLinkRow['status'], number>>;
    customers: Option[];
    clearing: number;
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
    const t = useTranslate();
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
                    <Button variant="outline" onClick={() => setSettling(true)} data-testid="paymob-settle-open" disabled={clearing <= 0}>
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
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {settlements.length === 0 && (
                                <TableRow>
                                    <TableCell colSpan={6} className="py-8 text-center text-[13px] text-[#9ca3af]">
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
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </Card>
            </section>

            <CreateDialog open={creating} onClose={() => setCreating(false)} customers={customers} />
            <SettleDialog open={settling} onClose={() => setSettling(false)} clearing={clearing} bankAccounts={bankAccounts} />
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

/** «تسجيل تسوية» — من كشف Paymob: الإجمالي = الصافي + الرسوم */
function SettleDialog({ open, onClose, clearing, bankAccounts }: { open: boolean; onClose: () => void; clearing: number; bankAccounts: Option[] }) {
    const t = useTranslate();
    const form = useForm({
        gross: '',
        fee: '0',
        net: '',
        settled_on: new Date().toISOString().slice(0, 10),
        bank_account_id: bankAccounts[0]?.value ?? '',
        reference: '',
        notes: '',
    });

    // الصافي يُقترح من الإجمالي والرسوم — ويبقى قابلًا للكتابة، والخادمُ يتحقّق
    const suggest = (gross: string, fee: string) => {
        const g = Number(gross);
        const f = Number(fee);

        return Number.isFinite(g) && Number.isFinite(f) && gross !== '' ? (g - f).toFixed(3) : '';
    };

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
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>{t('تسجيل تسوية Paymob')}</DialogTitle>
                </DialogHeader>
                <form onSubmit={submit} className="grid gap-4 px-5 pb-5 sm:grid-cols-2" data-testid="paymob-settle-form">
                    <Field label="الإجمالي" hint={`${t('تحت التسوية')}: ${clearing.toFixed(3)}`} error={form.errors.gross}>
                        <Input
                            dir="ltr"
                            inputMode="decimal"
                            value={form.data.gross}
                            onChange={(e) => form.setData((d) => ({ ...d, gross: e.target.value, net: suggest(e.target.value, d.fee) }))}
                            aria-label={t('الإجمالي')}
                        />
                    </Field>
                    <Field label="الرسوم" error={form.errors.fee}>
                        <Input
                            dir="ltr"
                            inputMode="decimal"
                            value={form.data.fee}
                            onChange={(e) => form.setData((d) => ({ ...d, fee: e.target.value, net: suggest(d.gross, e.target.value) }))}
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
                        <Button type="submit" loading={form.processing} data-testid="paymob-settle-submit">
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
