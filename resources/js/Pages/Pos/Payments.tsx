import { useMemo } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { Banknote, CreditCard, Landmark, Wallet } from 'lucide-react';
import PosLayout from '@/Layouts/PosLayout';
import DataTable, { type Column, type Filter } from '@/Components/DataTable';
import BarChart from '@/Components/charts/BarChart';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
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

interface OpenShift {
    opened_at: string | null;
    opening_balance: number;
    expected: number;
}

export default function PosPayments() {
    const { receipts, shift, context } =
        usePage<PageProps<{ receipts: Receipt[]; shift: OpenShift | null }>>().props;
    const t = useTranslate();
    const currency = context!.currency;
    const m = (v: number) => money(v, currency);

    /** تجميع المقبوضات حسب وسيلة الدفع */
    const byMethod = useMemo(() => {
        const map = new Map<string, number>();
        receipts.forEach((r) => map.set(r.payment, (map.get(r.payment) ?? 0) + (r.total ?? 0)));
        const entries = [...map.entries()].sort((a, b) => b[1] - a[1]);
        return {
            labels: entries.map(([k]) => t(k)),
            series: entries.map(([, v]) => v),
            raw: entries,
        };
    }, [receipts, t]);

    // الصفحة محروسة بصلاحية finance، فالمبالغ واصلة دائمًا. الاحتياط هنا
    // لأن النوع صار اختياريًا بعد حجبها عن الكاشير في شاشة الفواتير.
    const grandTotal = receipts.reduce((s, r) => s + (r.total ?? 0), 0);

    const columns: Column<Receipt>[] = [
        {
            key: 'number',
            header: 'رقم الفاتورة',
            sortable: true,
            value: (r) => r.number,
            cell: (r) => (
                <Link href={route('pos.order-details', r.number)} className="font-medium hover:underline">
                    {r.number}
                </Link>
            ),
        },
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
            value: (r) => r.total ?? 0,
            cell: (r) => <span className="tabular-nums font-medium">{m(r.total ?? 0)}</span>,
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
                <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
                    <h1 className="text-[20px] font-bold text-[#111]">{t('مقبوضات الوردية')}</h1>
                    <span className="text-sm text-gray-500">
                        {number(receipts.length)} {t('عملية')} · {m(grandTotal)}
                    </span>
                </div>

                {/*
                    المدى يُقال صراحةً: رقمٌ بلا حدٍّ زمني في شاشة تقفيل صندوق
                    يُقرأ على أنه «اليوم» وهو ليس كذلك.
                */}
                {!shift ? (
                    <Card className="mb-4">
                        <CardContent className="py-10 text-center">
                            <p className="font-medium text-[#111]">{t('لا توجد وردية مفتوحة')}</p>
                            <p className="mt-1 text-[13px] text-[#9ca3af]">
                                {t('افتح وردية الصندوق ليُحسب ما يجب أن يكون فيه.')}
                            </p>
                            <Button className="mt-4" variant="outline" asChild>
                                <Link href={route('pos.shift')}>{t('وردية الصندوق')}</Link>
                            </Button>
                        </CardContent>
                    </Card>
                ) : (
                    <Card className="mb-4">
                        <CardContent className="flex flex-wrap items-center justify-between gap-4 py-4">
                            <div className="min-w-0">
                                <p className="text-[12px] text-[#9ca3af]">
                                    {t('الوردية مفتوحة منذ')} {shift.opened_at}
                                </p>
                                <p className="mt-0.5 text-[13px] text-[#4b4b4b]">
                                    {t('الرصيد الابتدائي')}: {m(shift.opening_balance)}
                                </p>
                            </div>
                            <div className="text-end">
                                <p className="text-[12px] text-[#9ca3af]">{t('المتوقّع في الدرج نقدًا')}</p>
                                <p className="text-[22px] font-bold tabular-nums text-[#111]">
                                    {m(shift.expected)}
                                </p>
                            </div>
                        </CardContent>
                    </Card>
                )}

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
                            empty={t('لا مقبوضات في هذه الوردية بعد')}
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
