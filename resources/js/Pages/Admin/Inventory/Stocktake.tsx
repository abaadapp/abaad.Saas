import { useForm, usePage } from '@inertiajs/react';
import { CheckCheck } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { INVENTORY_TABS } from '@/Components/SectionTabs';
import SmartLink from '@/Components/SmartLink';
import Field, { Select } from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/Components/ui/table';
import { number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';
import type { Branch, InventoryItem } from '@/types/models';

interface Props {
    items: InventoryItem[];
    branches: Branch[];
    currentBranch: number | null;
}

export default function Stocktake() {
    const { items, branches, currentBranch } = usePage<PageProps<Props>>().props;
    const t = useTranslate();

    const form = useForm<{ branch_id: string; counts: Record<number, string> }>({
        branch_id: currentBranch ? String(currentBranch) : '',
        counts: {},
    });

    const setCount = (id: number, value: string) =>
        form.setData('counts', { ...form.data.counts, [id]: value });

    /** الفرق بين المعدود والدفتري — null يعني «لم يُعَدّ» فلا يُحتسب */
    const variance = (id: number, book: number): number | null => {
        const raw = form.data.counts[id];
        if (raw === undefined || raw === '') return null;
        const n = Number(raw);

        return Number.isFinite(n) ? n - book : null;
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(route('admin.inventory.stocktake.apply'));
    };

    return (
        <AdminLayout title="الجرد الفعلي">
            <PageHeader
                title="الجرد الفعلي"
                subtitle={t('أدخل الكمية المعدودة فعليًا لكل صنف — يعرض النظام الفرق ويسجّل تسوية تلقائية')}
                breadcrumbs={[
                    { label: 'الرئيسية', href: route('admin.dashboard') },
                    { label: 'المخزون', href: route('admin.inventory.index') },
                    { label: 'الجرد الفعلي' },
                ]}
            />

            <SectionTabs tabs={INVENTORY_TABS} current="admin.inventory.stocktake" />

            <form onSubmit={submit}>
                <Card className="mb-6 p-4">
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
                        <Field label="الفرع" required error={form.errors.branch_id} className="w-full sm:w-72">
                            <Select
                                value={form.data.branch_id}
                                onChange={(e) => form.setData('branch_id', e.target.value)}
                                options={branches.map((b) => ({ label: b.name, value: b.id }))}
                                placeholder="اختر الفرع…"
                                required
                            />
                        </Field>
                        <p className="text-[12px] text-[#9ca3af] sm:pb-2.5">
                            {t('اترك الحقل فارغًا للأصناف التي لم تُعَدّ (لن تتغيّر).')}
                        </p>
                    </div>
                </Card>

                <Card className="overflow-hidden">
                    <Table>
                        <TableHeader>
                            <TableRow className="hover:bg-transparent">
                                {['المنتج', 'SKU', 'الكمية الدفترية', 'الكمية المعدودة', 'الفرق'].map((h) => (
                                    <TableHead key={h}>{t(h)}</TableHead>
                                ))}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {items.map((item) => {
                                const diff = variance(item.id, item.qty);

                                return (
                                    <TableRow key={item.id}>
                                        <TableCell className="font-medium text-[#111]">{item.name}</TableCell>
                                        <TableCell className="font-mono text-[#6b7280]">{item.sku}</TableCell>
                                        <TableCell className="font-semibold tabular-nums">{number(item.qty)}</TableCell>
                                        <TableCell>
                                            <Input
                                                inputMode="numeric"
                                                dir="ltr"
                                                value={form.data.counts[item.id] ?? ''}
                                                onChange={(e) => setCount(item.id, e.target.value)}
                                                placeholder={t('لم يُعَدّ')}
                                                className="h-9 w-28"
                                            />
                                        </TableCell>
                                        <TableCell>
                                            {diff === null ? (
                                                <span className="text-[#d1d5db]">—</span>
                                            ) : (
                                                <span
                                                    className={cn(
                                                        'font-semibold tabular-nums',
                                                        diff === 0
                                                            ? 'text-[#6b7280]'
                                                            : diff > 0
                                                              ? 'text-[#047857]'
                                                              : 'text-[#b91c1c]',
                                                    )}
                                                    dir="ltr"
                                                >
                                                    {diff > 0 ? '+' : ''}
                                                    {diff}
                                                </span>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                    </Table>
                </Card>

                <div className="mt-5 flex items-center gap-3">
                    <Button type="submit" loading={form.processing}>
                        <CheckCheck />
                        {t('تطبيق الجرد والتسوية')}
                    </Button>
                    <Button variant="outline" asChild>
                        <SmartLink routeName="admin.inventory.index" href={route('admin.inventory.index')}>
                            {t('إلغاء')}
                        </SmartLink>
                    </Button>
                </div>
            </form>
        </AdminLayout>
    );
}
