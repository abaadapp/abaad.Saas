import { useMemo } from 'react';
import { usePage } from '@inertiajs/react';
import { Banknote, CreditCard, Landmark, Wallet } from 'lucide-react';
import PosLayout from '@/Layouts/PosLayout';
import DataTable, { type Column, type Filter } from '@/Components/DataTable';
import BarChart from '@/Components/charts/BarChart';
import { Badge } from '@/Components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { money, number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { Receipt } from '@/types/models';

const METHOD_ICON: Record<string, typeof Banknote> = {
    'نقدي': Banknote,
    'بطاقة': CreditCard,
    'تحويل بنكي': Landmark,
};

export default function PosPayments() {
    const { receipts, context } = usePage<PageProps<{ receipts: Receipt[] }>>().props;
    const t = useTranslate();
    const currency = context!.currency;
    const m = (v: number) => money(v, currency);

    /** تجميع المقبوضات حسب وسيلة الدفع */
    const byMethod = useMemo(() => {
        const map = new Map<string, number>();
        receipts.forEach((r) => map.set(r.payment, (map.get(r.payment) ?? 0) + r.total));
        const entries = [...map.entries()].sort((a, b) => b[1] - a[1]);
        return {
            labels: entries.map(([k]) => t(k)),
            series: entries.map(([, v]) => v),
            raw: entries,
        };
    }, [receipts, t]);

    const grandTotal = receipts.reduce((s, r) => s + r.total, 0);

    const columns: Column<Receipt>[] = [
        { key: 'number', header: 'رقم الفاتورة', sortable: true, value: (r) => r.number },
        { key: 'customer', header: 'العميل', cell: (r) => r.customer || '—' },
        {
            key: 'payment',
            header: 'وسيلة الدفع',
            cell: (r) => {
                const Icon = METHOD_ICON[r.payment] ?? Wallet;
                return (
                    <span className="inline-flex items-center gap-1.5">
                        <Icon className="size-4 text-gray-400" />
                        {t(r.payment)}
                    </span>
                );
            },
        },
        { key: 'employee', header: 'الكاشير', cell: (r) => r.employee || '—' },
        { key: 'time', header: 'الوقت', sortable: true, value: (r) => r.time },
        {
            key: 'total',
            header: 'المبلغ',
            align: 'end',
            sortable: true,
            value: (r) => r.total,
            cell: (r) => <span className="tabular-nums font-medium">{m(r.total)}</span>,
        },
    ];

    const filters: Filter<Receipt>[] = [
        {
            label: 'كل الوسائل',
            options: [...new Set(receipts.map((r) => r.payment))].map((p) => ({ label: p, value: p })),
            match: (r, v) => r.payment === v,
        },
    ];

    return (
        <PosLayout title={t('المدفوعات')}>
            <div className="mx-auto max-w-6xl p-4">
                <div className="mb-4 flex items-center justify-between">
                    <h1 className="text-[20px] font-bold text-[#111]">{t('المدفوعات')}</h1>
                    <span className="text-sm text-gray-500">
                        {number(receipts.length)} {t('عملية')} · {m(grandTotal)}
                    </span>
                </div>

                <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                    <Card>
                        <CardHeader>
                            <CardTitle>{t('حسب وسيلة الدفع')}</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <BarChart labels={byMethod.labels} series={byMethod.series} format={m} />
                        </CardContent>
                    </Card>

                    <Card className="overflow-hidden lg:col-span-2">
                        <DataTable
                            rows={receipts}
                            columns={columns}
                            rowKey={(r) => r.number}
                            searchPlaceholder="ابحث برقم الفاتورة أو العميل…"
                            searchable={(r) => `${r.number} ${r.customer} ${r.employee} ${r.payment}`}
                            filters={filters}
                            empty={t('لا توجد مدفوعات بعد')}
                            toolbar={
                                byMethod.raw.length > 0 ? (
                                    <div className="flex flex-wrap gap-1.5">
                                        {byMethod.raw.map(([k, v]) => (
                                            <Badge key={k} variant="outline">
                                                {t(k)}: {m(v)}
                                            </Badge>
                                        ))}
                                    </div>
                                ) : undefined
                            }
                        />
                    </Card>
                </div>
            </div>
        </PosLayout>
    );
}
