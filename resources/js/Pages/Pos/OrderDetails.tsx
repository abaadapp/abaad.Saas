import { usePage } from '@inertiajs/react';
import { ArrowRight, Printer } from 'lucide-react';
import PosLayout from '@/Layouts/PosLayout';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { money } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface OrderItem {
    id: number;
    product_id: number | null;
    name: string;
    price: number;
    qty: number;
    total: number;
    note?: string | null;
}

interface OrderDetail {
    id: string;
    db_id: number;
    customer: string;
    employee: string;
    branch: string;
    status: string;
    payment: string;
    payment_status: string;
    date: string;
    subtotal: number;
    discount: number;
    tax: number;
    delivery: number;
    total: number;
    notes: string | null;
    items: OrderItem[];
}

export default function PosOrderDetails() {
    const { order, context } = usePage<PageProps<{ order: OrderDetail }>>().props;
    const t = useTranslate();
    const currency = context!.currency;
    const m = (v: number) => money(v, currency);

    const rows: [string, string][] = [
        [t('العميل'), order.customer],
        [t('الكاشير'), order.employee],
        [t('الفرع'), order.branch],
        [t('التاريخ'), order.date],
        [t('وسيلة الدفع'), order.payment],
    ];

    return (
        <PosLayout title={`${t('الطلب')} ${order.id}`}>
            <div className="mx-auto max-w-4xl p-4">
                <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-[20px] font-bold text-[#111]">
                            {t('الطلب')} {order.id}
                        </h1>
                        <div className="mt-1 flex items-center gap-2">
                            <Badge status={order.status} />
                            <Badge status={order.payment_status} />
                        </div>
                    </div>
                    <div className="flex gap-2">
                        <Button variant="outline" asChild>
                            <a href={route('pos.orders')}>
                                <ArrowRight className="ltr:rotate-180" />
                                {t('رجوع')}
                            </a>
                        </Button>
                        <Button asChild>
                            <a href={route('pos.receipt.pdf', order.id)} target="_blank" rel="noreferrer">
                                <Printer />
                                {t('طباعة الفاتورة')}
                            </a>
                        </Button>
                    </div>
                </div>

                <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                    <Card className="lg:col-span-2">
                        <CardHeader>
                            <CardTitle>{t('الأصناف')}</CardTitle>
                        </CardHeader>
                        <CardContent className="px-0">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>{t('الصنف')}</TableHead>
                                        <TableHead className="text-center">{t('الكمية')}</TableHead>
                                        <TableHead className="text-end">{t('السعر')}</TableHead>
                                        <TableHead className="text-end">{t('الإجمالي')}</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {order.items.map((it) => (
                                        <TableRow key={it.id}>
                                            <TableCell>
                                                <span className="font-medium">{it.name}</span>
                                                {it.note && (
                                                    <span className="block text-[11px] text-gray-400">{it.note}</span>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-center tabular-nums">{it.qty}</TableCell>
                                            <TableCell className="text-end tabular-nums">{m(it.price)}</TableCell>
                                            <TableCell className="text-end tabular-nums font-medium">{m(it.total)}</TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>

                    <div className="flex flex-col gap-4">
                        <Card>
                            <CardHeader>
                                <CardTitle>{t('التفاصيل')}</CardTitle>
                            </CardHeader>
                            <CardContent className="flex flex-col gap-2 text-[13px]">
                                {rows.map(([label, value]) => (
                                    <div key={label} className="flex items-center justify-between gap-2">
                                        <span className="text-gray-500">{label}</span>
                                        <span className="truncate font-medium text-[#111]">{value || '—'}</span>
                                    </div>
                                ))}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle>{t('الملخص')}</CardTitle>
                            </CardHeader>
                            <CardContent className="flex flex-col gap-2 text-[13px]">
                                <div className="flex justify-between">
                                    <span className="text-gray-500">{t('المجموع الفرعي')}</span>
                                    <span className="tabular-nums">{m(order.subtotal)}</span>
                                </div>
                                {order.discount > 0 && (
                                    <div className="flex justify-between text-[#b91c1c]">
                                        <span>{t('الخصم')}</span>
                                        <span className="tabular-nums">- {m(order.discount)}</span>
                                    </div>
                                )}
                                <div className="flex justify-between">
                                    <span className="text-gray-500">{t('الضريبة')}</span>
                                    <span className="tabular-nums">{m(order.tax)}</span>
                                </div>
                                {order.delivery > 0 && (
                                    <div className="flex justify-between">
                                        <span className="text-gray-500">{t('التوصيل')}</span>
                                        <span className="tabular-nums">{m(order.delivery)}</span>
                                    </div>
                                )}
                                <div className="mt-1 flex justify-between border-t border-dashed border-gray-200 pt-2 text-[15px] font-bold">
                                    <span>{t('الإجمالي')}</span>
                                    <span className="tabular-nums">{m(order.total)}</span>
                                </div>
                            </CardContent>
                        </Card>

                        {order.notes && (
                            <Card>
                                <CardHeader>
                                    <CardTitle>{t('ملاحظات')}</CardTitle>
                                </CardHeader>
                                <CardContent className="text-[13px] text-gray-600">{order.notes}</CardContent>
                            </Card>
                        )}
                    </div>
                </div>
            </div>
        </PosLayout>
    );
}
