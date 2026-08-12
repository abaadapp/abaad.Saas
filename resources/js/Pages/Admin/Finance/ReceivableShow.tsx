import { useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import { HandCoins, Phone } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import Field, { Select } from '@/Components/Field';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { money as fmtMoney } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface Invoice {
    id: number;
    number: string;
    date: string | null;
    due: string | null;
    total: number;
    paid: number;
    remaining: number;
    overdue: boolean;
}

interface Payment {
    id: number;
    date: string | null;
    amount: number;
    method: string;
    note: string | null;
    employee: string | null;
}

interface Props {
    customer: { id: number; name: string; phone: string | null };
    orders: Invoice[];
    payments: Payment[];
    balance: number;
    today: string;
}

/**
 * حساب عميلٍ واحد: ما عليه، وما سدّده، ومتى.
 *
 * الدفعة تُقيَّد صفًّا مستقلًّا لا تُكتب فوق الفاتورة — العميل يسدّد على
 * دفعات، ومحوُ ذلك يمحو ما يُحتجّ به حين يختلف الطرفان.
 */
export default function ReceivableShow() {
    const { customer, orders, payments, balance, today, context } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const m = (v: number) => fmtMoney(v, context!.currency);
    const [paying, setPaying] = useState(false);

    const form = useForm({
        amount: '',
        method: 'نقدي',
        order_id: '' as string,
        note: '',
        paid_at: today,
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(route('admin.receivables.pay', customer.id), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset('amount', 'note', 'order_id');
                setPaying(false);
            },
        });
    };

    return (
        <AdminLayout title={customer.name}>
            <PageHeader
                title={customer.name}
                subtitle={t('حساب العميل — الفواتير غير المسدَّدة ودفعاته')}
                breadcrumbs={[
                    { label: 'الرئيسية', href: route('admin.dashboard') },
                    { label: 'الذمم', href: route('admin.receivables.index') },
                    { label: customer.name },
                ]}
                actions={
                    <Button onClick={() => setPaying(true)} disabled={balance <= 0}>
                        <HandCoins />
                        {t('تسجيل دفعة')}
                    </Button>
                }
            />

            <div className="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Card className="p-5">
                    <p className="text-[12px] text-[#9ca3af]">{t('الرصيد المستحق')}</p>
                    <p className="mt-1 text-[26px] font-bold tabular-nums text-[#111]">{m(balance)}</p>
                </Card>
                {customer.phone && (
                    <Card className="flex items-center gap-3 p-5">
                        <Phone className="size-5 text-[#6b7280]" />
                        <a href={`tel:${customer.phone.replace(/\s/g, '')}`} className="font-medium text-[#111]" dir="ltr">
                            {customer.phone}
                        </a>
                    </Card>
                )}
            </div>

            <Card className="mb-4 overflow-hidden">
                <div className="border-b border-[var(--ui-border,#e8e8e8)] px-5 py-4">
                    <h3 className="font-bold text-[#111]">{t('فواتير لم تُسدَّد')}</h3>
                </div>
                {orders.length === 0 ? (
                    <p className="p-8 text-center text-[13px] text-[#9ca3af]">{t('لا فواتير مستحقّة')}</p>
                ) : (
                    <div className="divide-y divide-[#f5f5f4]">
                        {orders.map((o) => (
                            <div key={o.id} className="flex flex-wrap items-center justify-between gap-3 px-5 py-3.5">
                                <div>
                                    <p className="font-medium text-[#111]" dir="ltr">{o.number}</p>
                                    <p className="text-[12px] text-[#9ca3af]">
                                        {o.date}
                                        {o.due && ` · ${t('الاستحقاق:')} ${o.due}`}
                                    </p>
                                </div>
                                <div className="flex items-center gap-4">
                                    {o.paid > 0 && (
                                        <span className="text-[12px] text-[#6b7280]">
                                            {t('سُدِّد')} {m(o.paid)} {t('من')} {m(o.total)}
                                        </span>
                                    )}
                                    <span className="font-bold tabular-nums text-[#111]">{m(o.remaining)}</span>
                                    {o.overdue && <Badge variant="danger">{t('تجاوز الموعد')}</Badge>}
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </Card>

            <Card className="overflow-hidden">
                <div className="border-b border-[var(--ui-border,#e8e8e8)] px-5 py-4">
                    <h3 className="font-bold text-[#111]">{t('الدفعات')}</h3>
                </div>
                {payments.length === 0 ? (
                    <p className="p-8 text-center text-[13px] text-[#9ca3af]">{t('لا دفعات بعد')}</p>
                ) : (
                    <div className="divide-y divide-[#f5f5f4]">
                        {payments.map((p) => (
                            <div key={p.id} className="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                                <div>
                                    <p className="font-medium text-[#111]">{m(p.amount)}</p>
                                    <p className="text-[12px] text-[#9ca3af]">
                                        {p.date} · {t(p.method)}
                                        {p.employee && ` · ${p.employee}`}
                                        {p.note && ` · ${p.note}`}
                                    </p>
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </Card>

            <Dialog open={paying} onOpenChange={setPaying}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{t('تسجيل دفعة')}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        <Field
                            label="المبلغ"
                            required
                            error={form.errors.amount}
                            hint={t('المستحق: :n', { n: m(balance) })}
                        >
                            <Input
                                inputMode="decimal"
                                dir="ltr"
                                value={form.data.amount}
                                onChange={(e) => form.setData('amount', e.target.value)}
                                required
                            />
                        </Field>

                        <Field label="الوسيلة" required error={form.errors.method}>
                            <Select
                                value={form.data.method}
                                onChange={(e) => form.setData('method', e.target.value)}
                                options={[
                                    { label: 'نقدي', value: 'نقدي' },
                                    { label: 'بطاقة', value: 'بطاقة' },
                                    { label: 'تحويل بنكي', value: 'تحويل بنكي' },
                                ]}
                            />
                        </Field>

                        <Field
                            label="على فاتورة"
                            error={form.errors.order_id}
                            /* بلا تخصيص تُسدَّد الأقدم فالأحدث — وهو العرف */
                            hint="اتركه فارغًا لتسديد الأقدم فالأحدث"
                        >
                            <Select
                                value={form.data.order_id}
                                onChange={(e) => form.setData('order_id', e.target.value)}
                                options={orders.map((o) => ({ label: `${o.number} — ${m(o.remaining)}`, value: o.id }))}
                                placeholder="كل الحساب"
                            />
                        </Field>

                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <Field label="التاريخ" error={form.errors.paid_at}>
                                <Input
                                    type="date"
                                    value={form.data.paid_at}
                                    onChange={(e) => form.setData('paid_at', e.target.value)}
                                />
                            </Field>
                            <Field label="ملاحظة" error={form.errors.note}>
                                <Input value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} />
                            </Field>
                        </div>

                        <div className="flex justify-end gap-2">
                            <Button type="button" variant="outline" onClick={() => setPaying(false)}>
                                {t('إلغاء')}
                            </Button>
                            <Button type="submit" loading={form.processing}>
                                <HandCoins />
                                {t('تسجيل')}
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>
        </AdminLayout>
    );
}
