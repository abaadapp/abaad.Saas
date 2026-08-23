import { useRef, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { AlertTriangle, ClipboardList, PackageCheck, PackagePlus, Paperclip, Plus, Trash2, Truck, Upload } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { PURCHASE_TABS } from '@/Components/SectionTabs';
import StatCard, { type Stat } from '@/Components/StatCard';
import DataTable, { type Column, type Filter } from '@/Components/DataTable';
import SmartLink from '@/Components/SmartLink';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
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
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';
import { Input } from '@/Components/ui/input';
import type { PurchaseOrder } from '@/types/models';

interface ReorderRow {
    name: string;
    sku: string;
    qty: number;
    alert: number;
    suggested: number;
    cost: number;
}

interface Props {
    orders: PurchaseOrder[];
    stats: Stat[];
    reorder: ReorderRow[];
}

export default function PurchasesIndex() {
    const { orders, stats, reorder, context } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const currency = context!.currency;
    const m = (v: number) => money(v, currency);

    const [confirm, setConfirm] = useState<{ order: PurchaseOrder; kind: 'receive' | 'delete' } | null>(null);
    const uploads = useRef<Record<number, HTMLInputElement | null>>({});

    /*
     * الكمية المستلمة لكل بند — تبدأ بما بقي منه.
     *
     * البداية بالمتبقّي لا بصفر: الشحنة الكاملة هي الحال الغالب، فمن وصلته
     * كاملةً يضغط «تأكيد» بلا كتابة، ومن نقصت شحنته يصحّح السطر الناقص وحده.
     */
    const [taking, setTaking] = useState<Record<number, string>>({});

    const openReceive = (order: PurchaseOrder) => {
        setTaking(Object.fromEntries(order.items.map((i) => [i.id, String(i.remaining)])));
        setConfirm({ order, kind: 'receive' });
    };

    const act = () => {
        if (!confirm) return;
        const { order, kind } = confirm;
        const done = { preserveScroll: true, onFinish: () => setConfirm(null) };

        if (kind === 'receive') {
            router.post(
                route('admin.purchases.receive', order.id),
                {
                    items: order.items.map((i) => ({
                        id: i.id,
                        // حقلٌ فُرّغ يعني «لم يصل منه شيء» لا «وصل كلّه»
                        quantity: Number(taking[i.id] ?? 0) || 0,
                    })),
                },
                done,
            );
        } else {
            router.delete(route('admin.purchases.destroy', order.id), done);
        }
    };

    const takingTotal = confirm?.order.items.reduce((sum, i) => sum + (Number(taking[i.id]) || 0), 0) ?? 0;
    const partial =
        confirm?.order.items.some((i) => (Number(taking[i.id]) || 0) < i.remaining) ?? false;

    const uploadReceipt = (order: PurchaseOrder, file: File) => {
        router.post(route('admin.purchases.receipt', order.id), { receipt: file }, {
            forceFormData: true,
            preserveScroll: true,
        });
    };

    const columns: Column<PurchaseOrder>[] = [
        { key: 'number', header: 'الرقم', cell: (o) => <span className="font-mono text-[#4b4b4b]">{o.number}</span> },
        { key: 'branch', header: 'الفرع', cell: (o) => o.branch || '—' },
        { key: 'supplier', header: 'المورّد', cell: (o) => o.supplier || '—' },
        {
            key: 'items_count',
            header: 'الأصناف',
            align: 'end',
            cell: (o) => <span className="tabular-nums">{number(o.items_count)}</span>,
        },
        {
            key: 'total',
            header: 'الإجمالي',
            align: 'end',
            cell: (o) => <span className="tabular-nums font-semibold">{m(o.total)}</span>,
        },
        {
            key: 'status',
            header: 'الحالة',
            cell: (o) => (
                <>
                    <Badge status={o.status}>{t(o.status)}</Badge>
                    {/*
                        الشارة تقول «مستلم جزئيًا» ولا تقول كم من كم. والرقمان
                        تحتها هما ما يُسأل عنه أوّلًا: ماذا في يدي، وماذا ما زال
                        عند المورّد.
                    */}
                    {o.received_qty > 0 && o.received_qty < o.ordered_qty && (
                        <span className="mt-0.5 block text-[12px] tabular-nums text-[#b45309]">
                            {t('وصل')} {number(o.received_qty)} {t('من')} {number(o.ordered_qty)}
                        </span>
                    )}
                    {/*
                        ورابطٌ إلى أوراقه — والبحث في شاشة الإشعارات يقبل رقم
                        الأمر أصلًا، فلا مسار جديد ولا استعلام.

                        ولا يُرسم إلا لأمرٍ له ورقةٌ فعلًا: أوامرُ ما قبل
                        الإشعارات «مستلمة» ولا ورقة لها، ورابطٌ يقود إلى قائمةٍ
                        فارغة يَعِد ولا يفي.
                    */}
                    {o.receipts > 0 && (
                        <SmartLink
                            routeName="admin.inventory.receipts"
                            href={route('admin.inventory.receipts', { q: o.number })}
                            className="mt-1 inline-flex items-center gap-1 text-[12px] text-[#4b4b4b] hover:text-[#111] hover:underline"
                        >
                            <PackagePlus className="size-3.5 text-[#9ca3af]" />
                            {o.receipts === 1
                                ? t('إشعار الاستلام')
                                : `${t('إشعارات الاستلام')} (${number(o.receipts)})`}
                        </SmartLink>
                    )}
                </>
            ),
        },
        {
            key: 'receipt',
            header: 'إيصال الدفع',
            cell: (o) =>
                o.receipt ? (
                    <a
                        href={o.receipt}
                        target="_blank"
                        rel="noreferrer"
                        title={o.receipt_name ?? ''}
                        className="inline-flex items-center gap-1.5 text-sm text-[#4b4b4b] hover:underline"
                    >
                        <Paperclip className="size-4 text-[#9ca3af]" />
                        {t('عرض')}
                    </a>
                ) : (
                    <>
                        <button
                            type="button"
                            onClick={() => uploads.current[o.id]?.click()}
                            className="inline-flex items-center gap-1.5 text-sm text-[#6b7280] hover:text-[#111]"
                        >
                            <Upload className="size-4" />
                            {t('رفع')}
                        </button>
                        <input
                            ref={(el) => {
                                uploads.current[o.id] = el;
                            }}
                            type="file"
                            hidden
                            accept=".jpg,.jpeg,.png,.pdf,.webp,.heic"
                            onChange={(e) => {
                                const f = e.target.files?.[0];
                                if (f) uploadReceipt(o, f);
                            }}
                        />
                    </>
                ),
        },
        { key: 'ordered', header: 'تاريخ الطلب', cell: (o) => <span dir="ltr" className="text-[#6b7280]">{o.ordered}</span> },
        {
            key: 'actions',
            header: 'إجراء',
            align: 'end',
            cell: (o) => (
                <div className="flex items-center justify-end gap-1.5">
                    {o.status !== 'مستلم' && o.status !== 'ملغي' && (
                        <Button
                            variant="success"
                            size="sm"
                            className="rounded-full"
                            onClick={() => openReceive(o)}
                        >
                            <PackageCheck />
                            {t('استلام')}
                        </Button>
                    )}
                    <Button
                        variant="ghost"
                        size="icon-sm"
                        aria-label={t('حذف')}
                        className="text-[#b91c1c]"
                        onClick={() => setConfirm({ order: o, kind: 'delete' })}
                    >
                        <Trash2 />
                    </Button>
                </div>
            ),
        },
    ];

    const filters: Filter<PurchaseOrder>[] = [
        {
            label: 'كل الحالات',
            asTabs: true,
            options: ['مسودة', 'مُرسل', 'مستلم جزئيًا', 'مستلم', 'ملغي'].map((s) => ({ label: s, value: s })),
            match: (o, v) => o.status === v,
        },
    ];

    return (
        <AdminLayout title="أوامر الشراء">
            <PageHeader
                title="أوامر الشراء"
                subtitle={t('طلبات التزويد من المورّدين واستلام البضاعة')}
                actions={
                    <>
                        <Button variant="outline" asChild>
                            <SmartLink routeName="admin.suppliers.index" href={route('admin.suppliers.index')}>
                                <Truck />
                                {t('المورّدون')}
                            </SmartLink>
                        </Button>
                        <Button asChild>
                            <SmartLink routeName="admin.purchases.create" href={route('admin.purchases.create')}>
                                <Plus />
                                {t('أمر شراء جديد')}
                            </SmartLink>
                        </Button>
                    </>
                }
            />

            <SectionTabs tabs={PURCHASE_TABS} current="admin.purchases.orders" />

            <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-4">
                {stats.map((s, i) => (
                    <StatCard key={s.label} stat={s} index={i} />
                ))}
            </div>

            {reorder.length > 0 && (
                <Card className="mb-6 overflow-hidden border-[#fde68a]">
                    <div className="flex flex-wrap items-center gap-2 border-b border-[#fef3c7] bg-[#fffbeb]/60 px-5 py-4">
                        <AlertTriangle className="size-5 text-[#d97706]" />
                        <h3 className="font-bold text-[#111]">
                            {t('اقتراح إعادة الطلب')} — {number(reorder.length)} {t('منتج وصل حدّ التنبيه')}
                        </h3>
                        <SmartLink
                            routeName="admin.purchases.create"
                            href={`${route('admin.purchases.create')}?from=reorder`}
                            className="ms-auto text-sm font-medium text-[#6d28d9] hover:underline"
                        >
                            {t('إنشاء أمر شراء لها')} ←
                        </SmartLink>
                    </div>

                    <Table>
                        <TableHeader>
                            <TableRow className="hover:bg-transparent">
                                {['المنتج', 'SKU', 'المتبقّي', 'حد التنبيه', 'الكمية المقترحة', 'التكلفة التقديرية'].map(
                                    (h) => (
                                        <TableHead key={h}>{t(h)}</TableHead>
                                    ),
                                )}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {reorder.map((r) => (
                                <TableRow key={r.sku}>
                                    <TableCell className="font-medium text-[#111]">{r.name}</TableCell>
                                    <TableCell className="font-mono text-[#6b7280]">{r.sku}</TableCell>
                                    <TableCell>
                                        <span className={cn('font-semibold', r.qty === 0 ? 'text-[#b91c1c]' : 'text-[#d97706]')}>
                                            {number(r.qty)}
                                        </span>
                                    </TableCell>
                                    <TableCell className="text-[#6b7280]">{number(r.alert)}</TableCell>
                                    <TableCell className="font-semibold text-[#6d28d9]">
                                        {number(r.suggested)} {t('وحدة')}
                                    </TableCell>
                                    <TableCell className="tabular-nums">{m(r.suggested * r.cost)}</TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </Card>
            )}

            {orders.length === 0 ? (
                <Card className="px-5 py-16 text-center">
                    <ClipboardList className="mx-auto size-8 text-[#d1d5db]" />
                    <p className="mt-3 font-medium text-[#111]">{t('لا توجد أوامر شراء بعد')}</p>
                    <p className="mt-1 text-[13px] text-[#9ca3af]">
                        {t('أنشئ أول أمر شراء لتزويد مخزونك من المورّدين.')}
                    </p>
                    <Button className="mt-5" asChild>
                        <SmartLink routeName="admin.purchases.create" href={route('admin.purchases.create')}>
                            <Plus />
                            {t('أمر شراء جديد')}
                        </SmartLink>
                    </Button>
                </Card>
            ) : (
                <Card className="overflow-hidden">
                    <div className="border-b border-[var(--ui-border,#e8e8e8)] px-5 pt-5">
                        <h3 className="font-bold text-[#111]">{t('أوامر الشراء')}</h3>
                    </div>
                    <DataTable
                        rows={orders}
                        columns={columns}
                        rowKey={(o) => o.id}
                        searchPlaceholder="ابحث بالرقم أو المورّد…"
                        searchable={(o) => `${o.number} ${o.supplier} ${o.branch ?? ''}`}
                        filters={filters}
                        empty="لا توجد أوامر شراء بعد"
                    />
                </Card>
            )}

            <Dialog open={confirm !== null} onOpenChange={(v) => !v && setConfirm(null)}>
                <DialogContent className={cn(confirm?.kind === 'receive' ? 'sm:max-w-xl' : 'max-w-sm')}>
                    <DialogHeader>
                        <DialogTitle>
                            {confirm?.kind === 'receive' ? t('تأكيد الاستلام') : t('تأكيد الحذف')}
                        </DialogTitle>
                    </DialogHeader>

                    {confirm?.kind === 'receive' ? (
                        <div className="px-5 pb-5">
                            {/*
                                الكمية تُكتب لكل صنف لا تُفترض.

                                كان الزرّ يستلم الأمر كاملًا مهما وصل: مورّدٌ يشحن
                                ثمانين من مئة يُسجَّل مئةً — فيزيد المخزون عشرين لم
                                تصل، ويُحسب متوسّط التكلفة على مئةٍ دُفع ثمن ثمانين
                                منها.
                            */}
                            <p className="mb-3 text-sm text-[#4b4b4b]">
                                {t('اكتب ما وصلك من كل صنف — والباقي يبقى مفتوحًا لدفعةٍ قادمة.')}
                            </p>

                            <Table>
                                <TableHeader>
                                    <TableRow className="hover:bg-transparent">
                                        <TableHead>{t('الصنف')}</TableHead>
                                        <TableHead className="text-end">{t('المطلوب')}</TableHead>
                                        <TableHead className="text-end">{t('استُلم')}</TableHead>
                                        <TableHead className="text-end">{t('يُستلم الآن')}</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {confirm.order.items.map((i) => (
                                        <TableRow key={i.id}>
                                            <TableCell className="font-medium text-[#111]">{i.name}</TableCell>
                                            <TableCell className="text-end tabular-nums text-[#6b7280]">
                                                {number(i.quantity)}
                                            </TableCell>
                                            <TableCell className="text-end tabular-nums text-[#6b7280]">
                                                {number(i.received)}
                                            </TableCell>
                                            <TableCell className="text-end">
                                                <Input
                                                    type="number"
                                                    min={0}
                                                    max={i.remaining}
                                                    inputMode="numeric"
                                                    value={taking[i.id] ?? ''}
                                                    onChange={(e) =>
                                                        setTaking((v) => ({ ...v, [i.id]: e.target.value }))
                                                    }
                                                    className="h-9 w-24 text-end tabular-nums"
                                                    aria-label={`${t('يُستلم الآن')} — ${i.name}`}
                                                />
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>

                            <div className="mt-4 flex items-center justify-between gap-3">
                                <p className="text-[13px] text-[#6b7280]">
                                    {partial
                                        ? t('استلامٌ جزئيّ — يبقى الأمر مفتوحًا')
                                        : t('استلامٌ كامل — يُقفل الأمر')}
                                </p>
                                <div className="flex justify-end gap-2">
                                    <Button variant="outline" onClick={() => setConfirm(null)}>
                                        {t('إلغاء')}
                                    </Button>
                                    <Button variant="success" onClick={act} disabled={takingTotal <= 0}>
                                        {t('استلام')}
                                    </Button>
                                </div>
                            </div>
                        </div>
                    ) : (
                        <div className="px-5 pb-5">
                            <p className="text-sm text-[#4b4b4b]">{t('حذف أمر الشراء؟')}</p>
                            <div className="mt-5 flex justify-end gap-2">
                                <Button variant="outline" onClick={() => setConfirm(null)}>
                                    {t('إلغاء')}
                                </Button>
                                <Button variant="danger" onClick={act}>
                                    {t('حذف')}
                                </Button>
                            </div>
                        </div>
                    )}
                </DialogContent>
            </Dialog>
        </AdminLayout>
    );
}
