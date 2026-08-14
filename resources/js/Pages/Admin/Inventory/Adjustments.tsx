import { useMemo, useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import { Check, Plus } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { INVENTORY_TABS } from '@/Components/SectionTabs';
import StatCard from '@/Components/StatCard';
import DataTable, { type Column, type Filter, type ServerPagination } from '@/Components/DataTable';
import Field, { Select } from '@/Components/Field';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { money, number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

interface Adjustment {
    id: number;
    number: string;
    product: string;
    sku: string | null;
    branch: string | null;
    delta: number;
    cost: number;
    value: number;
    reason: string;
    notes: string | null;
    author: string | null;
    at: string | null;
}

interface ProductOption {
    value: number;
    label: string;
    quantity: number;
    cost: number;
}

interface Props {
    adjustments: Adjustment[];
    pagination: ServerPagination;
    filters: Record<string, string | null | undefined>;
    /** أعمدة يرتّبها الخادم — مصدرها `Sort::keys` في المتحكّم */
    sorts: string[];
    reasons: string[];
    products: ProductOption[];
    summary: { count: number; loss: number; gain: number };
    today: string;
}

export default function Adjustments() {
    const { adjustments, pagination, filters, sorts, reasons, products, summary, today, context } =
        usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const m = (v: number) => money(v, context!.currency);

    const [adding, setAdding] = useState(false);
    // اتجاهٌ منفصل في النموذج وحده — ويُدمج في رقمٍ بإشارة قبل الإرسال
    const [direction, setDirection] = useState<'-' | '+'>('-');

    const form = useForm({
        product_id: '',
        quantity_delta: '',
        reason: 'تلف',
        notes: '',
        adjusted_at: today,
    });

    const product = useMemo(
        () => products.find((p) => String(p.value) === form.data.product_id),
        [products, form.data.product_id],
    );

    const qty = Math.abs(parseFloat(form.data.quantity_delta) || 0);
    const impact = qty * (product?.cost ?? 0);
    const tooMuch = direction === '-' && product ? qty > product.quantity : false;

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({ ...d, quantity_delta: direction === '-' ? -qty : qty }));
        form.post(route('admin.inventory.adjustments.store'), {
            preserveScroll: true,
            onSuccess: () => {
                setAdding(false);
                form.reset();
            },
        });
    };

    const columns: Column<Adjustment>[] = [
        {
            key: 'number',
            header: 'المرجع',
            cell: (a) => (
                <>
                    <span className="font-mono text-[12px] text-[#4b4b4b]">{a.number}</span>
                    <span className="block text-[12px] text-[#9ca3af]" dir="ltr">
                        {a.at}
                    </span>
                </>
            ),
        },
        {
            key: 'product',
            header: 'المنتج',
            cell: (a) => (
                <>
                    <span className="font-medium text-[#111]">{a.product}</span>
                    {a.sku && <span className="block text-[12px] text-[#9ca3af]">{a.sku}</span>}
                </>
            ),
        },
        { key: 'reason', header: 'السبب', cell: (a) => <Badge>{t(a.reason)}</Badge> },
        {
            key: 'delta',
            header: 'الكمية',
            align: 'end',
            cell: (a) => (
                <span
                    className={cn('font-semibold tabular-nums', a.delta < 0 ? 'text-[#b91c1c]' : 'text-[#047857]')}
                >
                    {a.delta > 0 ? '+' : ''}
                    {number(a.delta)}
                </span>
            ),
        },
        {
            key: 'value',
            header: 'الأثر بالمال',
            align: 'end',
            cell: (a) => (
                <span className={cn('tabular-nums', a.value < 0 ? 'text-[#b91c1c]' : 'text-[#6b7280]')}>
                    {m(Math.abs(a.value))}
                </span>
            ),
        },
        { key: 'author', header: 'بواسطة', cell: (a) => a.author ?? '—' },
    ];

    const tableFilters: Filter<Adjustment>[] = [
        { label: 'كل الأسباب', param: 'reason', options: reasons.map((r) => ({ label: r, value: r })) },
    ];

    return (
        <AdminLayout title="تعديلات المخزون">
            <PageHeader
                title="تعديلات المخزون"
                subtitle={t('تلفٌ وفقدٌ وتصحيحُ عدّ — كلٌّ منها يُنقص المخزون ويُقيّد أثره في الدفتر')}
                actions={
                    <Button onClick={() => setAdding(true)} disabled={products.length === 0}>
                        <Plus />
                        {t('تعديل جديد')}
                    </Button>
                }
            />

            <SectionTabs tabs={INVENTORY_TABS} current="admin.inventory.adjustments" />

            <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
                <StatCard stat={{ label: t('التعديلات'), value: number(summary.count), icon: 'clipboard-list', color: 'info' }} index={0} />
                <StatCard
                    stat={{ label: t('خسائر التلف والفقد'), value: m(summary.loss), icon: 'trending-down', color: summary.loss > 0 ? 'danger' : 'success' }}
                    index={1}
                />
                <StatCard stat={{ label: t('زيادات مسجَّلة'), value: m(summary.gain), icon: 'trending-up', color: 'success' }} index={2} />
            </div>

            <Card className="overflow-hidden">
                <DataTable
                    rows={adjustments}
                    columns={columns}
                    rowKey={(a) => a.id}
                    searchPlaceholder="ابحث بالمرجع أو المنتج…"
                    searchable={() => ''}
                    filters={tableFilters}
                    empty="لا تعديلات بعد"
                    server={{ pagination, params: filters, sorts }}
                />
            </Card>

            {/* التعديل لا يُحذف: قيدُه في الدفتر، والتراجع عنه بتعديلٍ مقابل */}
            <p className="mt-3 text-[12px] text-[#9ca3af]">
                {t('التعديل لا يُحذف بعد تسجيله — له قيدٌ في الدفتر، والتراجع عنه يكون بتعديلٍ مقابل لا بمحوه.')}
            </p>

            <Dialog open={adding} onOpenChange={setAdding}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>{t('تعديل مخزون')}</DialogTitle>
                    </DialogHeader>

                    <form onSubmit={submit} className="space-y-4">
                        <Field label="المنتج" required error={form.errors.product_id}>
                            <Select
                                placeholder="اختر المنتج"
                                value={form.data.product_id}
                                onChange={(e) => form.setData('product_id', e.target.value)}
                                options={products}
                            />
                        </Field>

                        {product && (
                            <p className="text-[12px] text-[#9ca3af]">
                                {t('المتوفّر')}: {number(product.quantity)} · {t('التكلفة')}: {m(product.cost)}
                            </p>
                        )}

                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <Field label="الاتجاه" required>
                                <Select
                                    value={direction}
                                    onChange={(e) => setDirection(e.target.value === '+' ? '+' : '-')}
                                    options={[
                                        { value: '-', label: 'نقص' },
                                        { value: '+', label: 'زيادة' },
                                    ]}
                                />
                            </Field>
                            <Field label="الكمية" required error={form.errors.quantity_delta}>
                                <Input
                                    type="number"
                                    step="0.001"
                                    min="0"
                                    dir="ltr"
                                    value={form.data.quantity_delta}
                                    onChange={(e) => form.setData('quantity_delta', e.target.value)}
                                />
                            </Field>
                        </div>

                        {tooMuch && (
                            <p className="text-[12px] text-[#b91c1c]">
                                {t('المتوفّر :n فقط', { n: number(product?.quantity ?? 0) })}
                            </p>
                        )}

                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <Field label="السبب" required error={form.errors.reason}>
                                <Select
                                    value={form.data.reason}
                                    onChange={(e) => form.setData('reason', e.target.value)}
                                    options={reasons.map((r) => ({ value: r, label: r }))}
                                />
                            </Field>
                            <Field label="التاريخ" required error={form.errors.adjusted_at}>
                                <Input
                                    type="date"
                                    dir="ltr"
                                    value={form.data.adjusted_at}
                                    onChange={(e) => form.setData('adjusted_at', e.target.value)}
                                />
                            </Field>
                        </div>

                        <Field label="ملاحظة" error={form.errors.notes}>
                            <Input value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />
                        </Field>

                        {/* الأثر بالمال أمام العين: التعديل ليس تصحيحًا للرقم وحده */}
                        {impact > 0 && (
                            <div className="flex items-center justify-between rounded-[12px] bg-[#fafafa] px-4 py-3 text-sm">
                                <span className="text-[#6b7280]">
                                    {t(direction === '-' ? 'يُقيَّد خسارة' : 'يُقيَّد زيادة في المخزون')}
                                </span>
                                <span className="font-semibold tabular-nums text-[#111]">{m(impact)}</span>
                            </div>
                        )}

                        <div className="flex justify-end gap-2">
                            <Button type="button" variant="ghost" onClick={() => setAdding(false)}>
                                {t('إلغاء')}
                            </Button>
                            <Button
                                type="submit"
                                loading={form.processing}
                                disabled={!form.data.product_id || qty <= 0 || tooMuch}
                            >
                                <Check />
                                {t('تسجيل')}
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>
        </AdminLayout>
    );
}
