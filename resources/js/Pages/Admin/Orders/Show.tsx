import { usePage } from '@inertiajs/react';
import { Calendar, FileText, Landmark, User } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/Components/ui/table';
import { money, number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface OrderDetail {
    id: string;
    customer: string;
    employee: string;
    branch: string | null;
    date: string;
    payment: string;
    payment_status: string;
    notes: string | null;
    subtotal: number;
    discount: number;
    tax: number;
    delivery: number;
    total: number;
    items: { name: string; qty: number; price: number; total: number }[];
}

export default function OrderShow() {
    const { order, context } = usePage<PageProps<{ order: OrderDetail }>>().props;
    const t = useTranslate();
    const currency = context!.currency;
    const m = (v: number) => money(v, currency);

    const summary: { label: string; value: string; tone?: string }[] = [
        { label: 'المجموع الفرعي', value: m(order.subtotal) },
        { label: 'الخصم', value: `- ${m(order.discount)}`, tone: 'text-[#b91c1c]' },
        { label: 'الضريبة', value: m(order.tax) },
        { label: 'رسوم التوصيل', value: m(order.delivery) },
    ];

    return (
        <AdminLayout title="تفاصيل الطلب">
            <PageHeader
                title={`${t('الطلب')} ${order.id}`}
                subtitle={order.date}
                breadcrumbs={[
                    { label: 'الرئيسية', href: route('admin.dashboard') },
                    { label: 'المبيعات', href: route('admin.orders.index') },
                    { label: order.id },
                ]}
                actions={
                    <>
                        <Button variant="outline" asChild>
                            <a href={route('admin.orders.pdf', order.id)} target="_blank" rel="noreferrer">
                                <FileText />
                                {t('تصدير PDF')}
                            </a>
                        </Button>
                        <Button variant="outline" asChild>
                            <a href={route('admin.orders.taxInvoice', order.id)} target="_blank" rel="noreferrer">
                                <Landmark />
                                {t('فاتورة ضريبية')}
                            </a>
                        </Button>
                    </>
                }
            />

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div className="space-y-6 lg:col-span-2">
                    <div>
                        <h3 className="mb-3 font-bold text-[#111]">{t('تفاصيل المنتجات')}</h3>
                        <Card className="overflow-hidden">
                            <Table>
                                <TableHeader>
                                    <TableRow className="hover:bg-transparent">
                                        <TableHead>{t('المنتج')}</TableHead>
                                        <TableHead className="text-end">{t('الكمية')}</TableHead>
                                        <TableHead className="text-end">{t('السعر')}</TableHead>
                                        <TableHead className="text-end">{t('الإجمالي')}</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {order.items.map((line, i) => (
                                        <TableRow key={i}>
                                            <TableCell className="font-medium text-[#111]">{line.name}</TableCell>
                                            <TableCell className="text-end tabular-nums">{number(line.qty)}</TableCell>
                                            <TableCell className="text-end tabular-nums text-[#4b4b4b]">
                                                {m(line.price)}
                                            </TableCell>
                                            <TableCell className="text-end tabular-nums font-medium">
                                                {m(line.total)}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </Card>
                    </div>

                    <Card className="p-6">
                        <h3 className="mb-4 font-bold text-[#111]">{t('الملخص المالي')}</h3>
                        <dl className="space-y-3 text-sm">
                            {summary.map((row) => (
                                <div key={row.label} className="flex items-center justify-between">
                                    <dt className="text-[#6b7280]">{t(row.label)}</dt>
                                    <dd className={`font-medium tabular-nums ${row.tone ?? 'text-[#111]'}`}>
                                        {row.value}
                                    </dd>
                                </div>
                            ))}
                            <div className="flex items-center justify-between border-t border-[var(--ui-border,#e8e8e8)] pt-3">
                                <dt className="font-bold text-[#111]">{t('الإجمالي')}</dt>
                                <dd className="text-[18px] font-bold tabular-nums text-[#6d28d9]">{m(order.total)}</dd>
                            </div>
                        </dl>
                    </Card>
                </div>

                <div className="space-y-6">
                    <Card className="p-6">
                        <h3 className="mb-4 font-bold text-[#111]">{t('بيانات العميل')}</h3>
                        <div className="mb-4 flex items-center gap-3">
                            <span className="flex size-11 items-center justify-center rounded-full bg-[#f5f3ff] font-bold text-[#6d28d9]">
                                {order.customer.slice(0, 1)}
                            </span>
                            <div>
                                <p className="font-medium text-[#111]">{order.customer}</p>
                                <p className="text-[12px] text-[#9ca3af]">{order.branch || '—'}</p>
                            </div>
                        </div>
                        <dl className="space-y-2.5 text-sm text-[#4b4b4b]">
                            <div className="flex items-center gap-2">
                                <User className="size-4 text-[#9ca3af]" />
                                {t('الموظف')}: {order.employee}
                            </div>
                            <div className="flex items-center gap-2">
                                <Calendar className="size-4 text-[#9ca3af]" />
                                {order.date}
                            </div>
                        </dl>
                    </Card>

                    <Card className="p-6">
                        <h3 className="mb-4 font-bold text-[#111]">{t('حالة الدفع')}</h3>
                        <div className="mb-3 flex items-center justify-between">
                            <span className="text-sm text-[#6b7280]">{t('وسيلة الدفع')}</span>
                            <Badge status={order.payment}>{t(order.payment === 'بطاقة' ? 'فيزا' : order.payment)}</Badge>
                        </div>
                        <div className="flex items-center justify-between">
                            <span className="text-sm text-[#6b7280]">{t('حالة الدفع')}</span>
                            <Badge variant="success">{t(order.payment_status)}</Badge>
                        </div>
                    </Card>

                    {order.notes && (
                        <Card className="p-6">
                            <h3 className="mb-3 font-bold text-[#111]">{t('ملاحظات الطلب')}</h3>
                            <p className="text-sm leading-relaxed text-[#4b4b4b]">{order.notes}</p>
                        </Card>
                    )}
                </div>
            </div>
        </AdminLayout>
    );
}
