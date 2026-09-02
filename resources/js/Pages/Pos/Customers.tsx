import { usePage } from '@inertiajs/react';
import { Award, Receipt, Store } from 'lucide-react';
import PosLayout from '@/Layouts/PosLayout';
import DataTable, { type Column } from '@/Components/DataTable';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { money, number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { Customer } from '@/types/models';

export default function PosCustomers() {
    const { customers, context } = usePage<PageProps<{ customers: Customer[] }>>().props;
    const t = useTranslate();
    const currency = context!.currency;

    const columns: Column<Customer>[] = [
        { key: 'name', header: 'العميل', sortable: true, value: (c) => c.label ?? c.name },
        {
            key: 'phone',
            header: 'الهاتف',
            cell: (c) => (c.phone ? <span dir="ltr" className="font-mono text-[13px]">{c.phone}</span> : '—'),
        },
        {
            key: 'orders',
            header: 'الطلبات',
            align: 'end',
            sortable: true,
            value: (c) => c.orders,
            cell: (c) => <span className="tabular-nums">{number(c.orders)}</span>,
        },
        {
            key: 'total_spent',
            header: 'إجمالي الإنفاق',
            align: 'end',
            sortable: true,
            value: (c) => c.total_spent,
            cell: (c) => <span className="tabular-nums">{money(c.total_spent, currency)}</span>,
        },
        {
            /*
             * آخر فاتورة — رقمُها لا تاريخُها.
             *
             * الكاشير يُسأل «كم كانت فاتورتي الماضية؟» وهو واقفٌ والزبون
             * أمامه، فيفتحها بضغطة. وتاريخٌ بلا رقم يتركه يبحث في سجلّ
             * الفواتير بالاسم ويقلّب الصفحات والزبون ينتظر.
             */
            key: 'last_invoice',
            header: 'آخر فاتورة',
            value: (c) => c.last_invoice ?? '',
            cell: (c) =>
                c.last_invoice ? (
                    <a
                        href={route('pos.order-details', c.last_invoice)}
                        className="inline-flex items-center gap-1.5 font-mono text-[13px] font-medium text-[#111] underline-offset-2 hover:underline"
                        dir="ltr"
                    >
                        <Receipt className="size-3.5 shrink-0 text-[#9ca3af]" />
                        {c.last_invoice}
                        {c.last_invoice_total != null && (
                            <span className="font-sans tabular-nums text-[#6b7280]">
                                {money(c.last_invoice_total, currency)}
                            </span>
                        )}
                    </a>
                ) : (
                    <span className="text-gray-400">—</span>
                ),
        },
        {
            key: 'points',
            header: 'النقاط',
            align: 'end',
            sortable: true,
            value: (c) => c.points,
            cell: (c) =>
                c.points > 0 ? (
                    <span className="inline-flex items-center gap-1 tabular-nums font-medium text-[#be185d]">
                        <Award className="size-3.5" />
                        {number(c.points)}
                    </span>
                ) : (
                    <span className="text-gray-400">0</span>
                ),
        },
        {
            key: 'sell',
            header: '',
            align: 'end',
            // يفتح شاشة البيع بالعميل محدَّدًا مسبقًا (posCart يقرأ ?customer=)
            cell: (c) => (
                <Button variant="outline" size="sm" asChild>
                    <a href={`${route('pos.index')}?customer=${encodeURIComponent(c.name)}`}>
                        <Store />
                        {t('بيع')}
                    </a>
                </Button>
            ),
        },
    ];

    return (
        <PosLayout title={t('العملاء')}>
            <div className="mx-auto max-w-6xl p-4">
                <div className="mb-4 flex items-center justify-between">
                    <h1 className="text-[20px] font-bold text-[#111]">{t('العملاء')}</h1>
                    <span className="text-sm text-gray-500">
                        {number(customers.length)} {t('عميل')}
                    </span>
                </div>

                <Card className="overflow-hidden">
                    <DataTable
                        rows={customers}
                        columns={columns}
                        rowKey={(c) => c.id}
                        searchPlaceholder="ابحث بالاسم أو رقم الهاتف…"
                        searchable={(c) => `${c.name} ${c.name_en ?? ''} ${c.phone} ${c.email ?? ''}`}
                        empty={t('لا يوجد عملاء بعد')}
                    />
                </Card>
            </div>
        </PosLayout>
    );
}
