import { useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { Award, ArrowRight, Mail, MapPin, Phone, Save, Receipt } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import StatCard from '@/Components/StatCard';
import SmartLink from '@/Components/SmartLink';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Textarea } from '@/Components/ui/input';
import {
    Table, TableBody, TableCell, TableEmpty, TableHead, TableHeader, TableRow,
} from '@/Components/ui/table';
import { money, number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { Customer, Order } from '@/types/models';

interface Props {
    customer: Customer & { address?: string | null; notes?: string | null };
    orders: Order[];
}

export default function CustomerShow() {
    const { customer, orders, context } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const currency = context!.currency;
    const m = (v: number) => money(v, currency);
    const [redeeming, setRedeeming] = useState(false);

    const notes = useForm({ notes: customer.notes ?? '' });

    const stats = [
        { label: t('عدد الطلبات'), value: String(customer.orders), icon: 'shopping-bag', color: 'primary' },
        { label: t('إجمالي المشتريات'), value: m(customer.total_spent), icon: 'wallet', color: 'success' },
        {
            label: t('متوسط الطلب'),
            value: m(customer.orders > 0 ? customer.total_spent / customer.orders : 0),
            icon: 'calculator', color: 'info',
        },
        { label: t('نقاط الولاء'), value: number(customer.points), icon: 'star', color: 'warning' },
    ];

    const contact = [
        { icon: <Phone className="size-4 text-[#9ca3af]" />, label: 'الهاتف', value: customer.phone, ltr: true },
        { icon: <Mail className="size-4 text-[#9ca3af]" />, label: 'البريد', value: customer.email, ltr: true },
        { icon: <MapPin className="size-4 text-[#9ca3af]" />, label: 'العنوان', value: customer.address ?? null },
        { icon: <Receipt className="size-4 text-[#9ca3af]" />, label: 'الرقم الضريبي', value: customer.tax_number, ltr: true },
    ];

    return (
        <AdminLayout title={customer.name}>
            <PageHeader
                title="ملف العميل"
                subtitle={t('سجل مشتريات العميل ونقاط ولائه')}
                breadcrumbs={[
                    { label: 'الرئيسية', href: route('admin.dashboard') },
                    { label: 'العملاء', href: route('admin.customers.index') },
                    { label: customer.name },
                ]}
                actions={
                    <>
                        <Button variant="outline" asChild>
                            <SmartLink routeName="admin.customers.index" href={route('admin.customers.index')}>
                                <ArrowRight />{t('رجوع')}
                            </SmartLink>
                        </Button>
                        <Button variant="outline" asChild>
                            <a href={route('admin.customers.statement', customer.id)} target="_blank" rel="noreferrer">
                                <Receipt />{t('كشف حساب')}
                            </a>
                        </Button>
                        {customer.points > 0 && (
                            <Button onClick={() => setRedeeming(true)}>
                                <Award />{t('صرف النقاط')}
                            </Button>
                        )}
                    </>
                }
            />

            <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                {stats.map((s, i) => <StatCard key={s.label} stat={s} index={i} />)}
            </div>

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div className="space-y-6">
                    <Card className="p-6 text-center">
                        {customer.avatar ? (
                            <img src={customer.avatar} alt="" className="mx-auto size-24 rounded-full object-cover ring-4 ring-[#f5f3ff]" />
                        ) : (
                            <span className="mx-auto flex size-24 items-center justify-center rounded-full bg-[#f5f3ff] text-[28px] font-bold text-[#6d28d9]">
                                {customer.name.slice(0, 1)}
                            </span>
                        )}
                        <h2 className="mt-4 text-[17px] font-bold text-[#111]">{customer.name}</h2>
                        <p className="mt-1 font-mono text-[12px] text-[#9ca3af]">#{customer.id}</p>
                        {customer.points > 0 && (
                            <Badge variant="warning" className="mt-3">
                                <Award className="size-3.5" />{number(customer.points)} {t('نقطة')}
                            </Badge>
                        )}
                    </Card>

                    <Card className="p-6">
                        <h3 className="mb-4 text-sm font-bold text-[#111]">{t('بيانات الاتصال')}</h3>
                        <ul className="space-y-3 text-sm">
                            {contact.map((c) => (
                                <li key={c.label} className="flex items-center justify-between gap-3">
                                    <span className="flex items-center gap-2 text-[#6b7280]">{c.icon}{t(c.label)}</span>
                                    <span dir={c.ltr ? 'ltr' : undefined} className="truncate text-[#111]">{c.value || '—'}</span>
                                </li>
                            ))}
                        </ul>
                    </Card>

                    <Card className="p-6">
                        <h3 className="mb-3 text-sm font-bold text-[#111]">{t('ملاحظات')}</h3>
                        <form onSubmit={(e) => { e.preventDefault(); notes.post(route('admin.customers.note', customer.id), { preserveScroll: true }); }}>
                            <Textarea
                                rows={4}
                                value={notes.data.notes}
                                onChange={(e) => notes.setData('notes', e.target.value)}
                                placeholder={t('ملاحظات داخلية عن العميل...')}
                            />
                            <Button type="submit" size="sm" className="mt-3" disabled={notes.processing}>
                                <Save />{notes.processing ? '…' : t('حفظ الملاحظة')}
                            </Button>
                        </form>
                    </Card>
                </div>

                <Card className="overflow-hidden lg:col-span-2">
                    <div className="border-b border-[var(--ui-border,#e8e8e8)] px-5 py-4">
                        <h3 className="font-bold text-[#111]">{t('سجل الطلبات')}</h3>
                    </div>
                    <Table>
                        <TableHeader>
                            <TableRow className="hover:bg-transparent">
                                {['رقم الطلب', 'المنتجات', 'الإجمالي', 'الدفع', 'الحالة', 'التاريخ'].map((h) => (
                                    <TableHead key={h}>{t(h)}</TableHead>
                                ))}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {orders.length === 0 ? (
                                <TableEmpty colSpan={6}>{t('لا توجد طلبات لهذا العميل بعد')}</TableEmpty>
                            ) : orders.map((o) => (
                                <TableRow key={o.id}>
                                    <TableCell>
                                        <SmartLink routeName="admin.orders.show" href={route('admin.orders.show', o.id)}
                                            className="font-medium text-[#111] hover:underline">{o.id}</SmartLink>
                                    </TableCell>
                                    <TableCell className="tabular-nums text-[#4b4b4b]">{number(o.items_count)}</TableCell>
                                    <TableCell className="tabular-nums font-medium">{m(o.total)}</TableCell>
                                    <TableCell>{t(o.payment === 'بطاقة' ? 'فيزا' : o.payment)}</TableCell>
                                    <TableCell><Badge status={o.status}>{t(o.status)}</Badge></TableCell>
                                    <TableCell className="text-[#6b7280]">{o.date}</TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </Card>
            </div>

            <Dialog open={redeeming} onOpenChange={setRedeeming}>
                <DialogContent className="max-w-sm">
                    <DialogHeader><DialogTitle>{t('صرف النقاط')}</DialogTitle></DialogHeader>
                    <div className="px-5 pb-5">
                        <p className="text-sm text-[#4b4b4b]">
                            {t('سيتم صرف')} {number(customer.points)} {t('نقطة')} ({m(customer.points / 100)}).
                        </p>
                        <div className="mt-5 flex justify-end gap-2">
                            <Button variant="outline" onClick={() => setRedeeming(false)}>{t('إلغاء')}</Button>
                            <Button onClick={() => router.post(route('admin.customers.redeem', customer.id),
                                { points: customer.points }, { onFinish: () => setRedeeming(false) })}>
                                {t('صرف')}
                            </Button>
                        </div>
                    </div>
                </DialogContent>
            </Dialog>
        </AdminLayout>
    );
}
