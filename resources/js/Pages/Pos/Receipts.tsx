import { useEffect, useState } from 'react';
import { usePage } from '@inertiajs/react';
import { Printer, Receipt as ReceiptIcon, Search } from 'lucide-react';
import PosLayout from '@/Layouts/PosLayout';
import DataTable, { type Column } from '@/Components/DataTable';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Card } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { money } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { Receipt } from '@/types/models';

interface Props {
    receipts: Receipt[];
    branchName: string;
    /** هل تصل المبالغ في صفوف القائمة؟ الخادم ينزعها عن الكاشير */
    showsAmounts: boolean;
}

export default function PosReceipts() {
    const { receipts, branchName, showsAmounts, context } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const currency = context!.currency;
    const m = (v: number) => money(v, currency);
    const [open, setOpen] = useState<Receipt | null>(null);

    /**
     * بحث من الخادم. الصفحة تحمّل آخر 30 فاتورة فقط، فالبحث داخلها وحدها
     * لا يجد الأقدم منها — pos.receipts.search يبحث في الطلبات كلها ويعيد
     * حتى 50. أقل من حرفين يعود إلى القائمة الأصلية بلا طلب.
     */
    const [q, setQ] = useState('');
    const [found, setFound] = useState<Receipt[] | null>(null);
    const [searching, setSearching] = useState(false);

    useEffect(() => {
        const term = q.trim();
        if (term.length < 2) {
            setFound(null);
            return;
        }
        let alive = true;
        setSearching(true);
        const id = setTimeout(async () => {
            try {
                const res = await fetch(`${route('pos.receipts.search')}?q=${encodeURIComponent(term)}`, {
                    headers: { Accept: 'application/json' },
                });
                if (!res.ok || !alive) return;
                const data = await res.json();
                if (alive) setFound(data.receipts ?? []);
            } catch {
                // تعذّر الاتصال — نُبقي آخر نتيجة ظاهرة بدل إفراغ الجدول
            } finally {
                if (alive) setSearching(false);
            }
        }, 300);
        return () => {
            alive = false;
            clearTimeout(id);
        };
    }, [q]);

    const rows = found ?? receipts;

    /**
     * صفوف الكاشير تصل بلا مبالغ، فتفصيل الفاتورة يُطلب عند النقر وحده.
     * فاتورةٌ واحدة في الطلب الواحد: يكفي للإرجاع، ولا يُمكّن من جمع اليوم.
     */
    const [loading, setLoading] = useState<string | null>(null);

    const reveal = async (r: Receipt) => {
        if (r.total !== undefined) {
            setOpen(r);
            return;
        }
        setLoading(r.number);
        try {
            const res = await fetch(route('pos.receipts.show', r.number), {
                headers: { Accept: 'application/json' },
            });
            if (res.ok) {
                const data = await res.json();
                setOpen(data.receipt as Receipt);
            }
        } finally {
            setLoading(null);
        }
    };

    const columns: Column<Receipt>[] = [
        {
            key: 'number',
            header: 'رقم الفاتورة',
            sortable: true,
            value: (r) => r.number,
            cell: (r) => (
                <button
                    type="button"
                    onClick={() => reveal(r)}
                    disabled={loading === r.number}
                    className="font-medium hover:underline disabled:opacity-50"
                >
                    {r.number}
                </button>
            ),
        },
        { key: 'customer', header: 'العميل', cell: (r) => r.customer || '—' },
        { key: 'employee', header: 'الكاشير', cell: (r) => r.employee || '—' },
        { key: 'payment', header: 'وسيلة الدفع', cell: (r) => t(r.payment) },
        { key: 'time', header: 'الوقت', sortable: true, value: (r) => r.time },
        // عمود الإجمالي لمن يملك صلاحية finance. الحجب على الخادم لا هنا:
        // إخفاء العمود وحده يترك الأرقام في الاستجابة لمن يفتح أدوات المتصفّح.
        ...(showsAmounts
            ? [
                  {
                      key: 'total',
                      header: 'الإجمالي',
                      align: 'end' as const,
                      sortable: true,
                      value: (r: Receipt) => r.total ?? 0,
                      cell: (r: Receipt) => (
                          <span className="tabular-nums font-medium">{m(r.total ?? 0)}</span>
                      ),
                  },
              ]
            : []),
        {
            key: 'print',
            header: '',
            align: 'end',
            cell: (r) => (
                <Button variant="ghost" size="icon-sm" asChild>
                    <a href={route('pos.receipt.pdf', r.number)} target="_blank" rel="noreferrer" title={t('طباعة الفاتورة')}>
                        <Printer />
                    </a>
                </Button>
            ),
        },
    ];

    return (
        <PosLayout title={t('الفواتير')}>
            <div className="mx-auto max-w-6xl p-4">
                <div className="mb-4 flex items-center justify-between">
                    <h1 className="text-[20px] font-bold text-[#111]">{t('الفواتير')}</h1>
                    <span className="text-sm text-gray-500">{branchName}</span>
                </div>

                <Card className="mb-3 p-3">
                    <div className="relative">
                        <Search className="pointer-events-none absolute top-1/2 size-4 -translate-y-1/2 text-[#9ca3af] start-3" />
                        <Input
                            value={q}
                            onChange={(e) => setQ(e.target.value)}
                            placeholder={t('ابحث برقم الفاتورة أو العميل أو الهاتف…')}
                            className="ps-9"
                        />
                    </div>
                    {found !== null && (
                        <p className="mt-2 text-[12px] text-[#9ca3af]">
                            {searching
                                ? t('جارٍ البحث…')
                                : `${t('نتائج البحث')}: ${found.length}`}
                        </p>
                    )}
                </Card>

                <Card className="overflow-hidden">
                    <DataTable
                        rows={rows}
                        columns={columns}
                        rowKey={(r) => r.number}
                        empty={found !== null ? t('لا نتائج مطابقة') : t('لا توجد فواتير بعد')}
                    />
                </Card>
            </div>

            {/* معاينة الفاتورة */}
            <Dialog open={open !== null} onOpenChange={(v) => !v && setOpen(null)}>
                <DialogContent className="max-w-sm">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <ReceiptIcon className="size-4" />
                            {open?.number}
                        </DialogTitle>
                    </DialogHeader>

                    {open && (
                        <div className="px-5 pb-5">
                            <div className="mb-3 space-y-1 text-[13px]">
                                <div className="flex justify-between">
                                    <span className="text-gray-500">{t('العميل')}</span>
                                    <span>{open.customer}</span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-gray-500">{t('الكاشير')}</span>
                                    <span>{open.employee}</span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-gray-500">{t('الوقت')}</span>
                                    <span>{open.time}</span>
                                </div>
                            </div>

                            <div className="space-y-1.5 border-y border-dashed border-gray-200 py-2.5 text-[13px]">
                                {(open.lines ?? []).map((l, i) => (
                                    <div key={i} className="flex justify-between gap-2">
                                        <span className="min-w-0 truncate">
                                            {l.qty} × {l.name}
                                        </span>
                                        <span className="shrink-0 tabular-nums">{m(l.total)}</span>
                                    </div>
                                ))}
                            </div>

                            <div className="mt-2.5 space-y-1 text-[13px]">
                                <div className="flex justify-between">
                                    <span className="text-gray-500">{t('المجموع الفرعي')}</span>
                                    <span className="tabular-nums">{m(open.subtotal ?? 0)}</span>
                                </div>
                                {(open.discount ?? 0) > 0 && (
                                    <div className="flex justify-between text-[#b91c1c]">
                                        <span>{t('الخصم')}</span>
                                        <span className="tabular-nums">- {m(open.discount ?? 0)}</span>
                                    </div>
                                )}
                                <div className="flex justify-between">
                                    <span className="text-gray-500">{t('الضريبة')}</span>
                                    <span className="tabular-nums">{m(open.tax ?? 0)}</span>
                                </div>
                                {(open.delivery_fee ?? 0) > 0 && (
                                    <div className="flex justify-between">
                                        <span className="text-gray-500">{t('التوصيل')}</span>
                                        <span className="tabular-nums">{m(open.delivery_fee ?? 0)}</span>
                                    </div>
                                )}
                                <div className="flex justify-between border-t border-dashed border-gray-200 pt-1.5 font-bold">
                                    <span>{t('الإجمالي')}</span>
                                    <span className="tabular-nums">{m(open.total ?? 0)}</span>
                                </div>
                            </div>

                            <Button className="mt-4 w-full rounded-full" asChild>
                                <a href={route('pos.receipt.pdf', open.number)} target="_blank" rel="noreferrer">
                                    <Printer />
                                    {t('طباعة الفاتورة')}
                                </a>
                            </Button>
                        </div>
                    )}
                </DialogContent>
            </Dialog>
        </PosLayout>
    );
}
