import { useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { Check, Package, Plus, TrendingDown, Trash2 } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { ACCOUNTING_TABS } from '@/Components/SectionTabs';
import StatCard from '@/Components/StatCard';
import Field, { Select } from '@/Components/Field';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@/Components/ui/table';
import { money, number } from '@/lib/format';
import { useConfirm } from '@/Components/ConfirmDialog';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

interface Asset {
    id: number;
    name: string;
    code: string | null;
    category: string | null;
    purchased_at: string | null;
    cost: number;
    salvage_value: number;
    life_months: number;
    monthly: number;
    accumulated: number;
    book_value: number;
    depreciated_through: string | null;
    status: string;
    disposed_at: string | null;
    disposal_amount: number | null;
    /** ما ينتظر الترحيل عن هذا الأصل حتى الشهر المعروض */
    due: number;
}

interface Props {
    assets: Asset[];
    summary: { count: number; cost: number; accumulated: number; book_value: number; due: number };
    month: string;
    today: string;
    categories: string[];
}

export default function Assets() {
    const { assets, summary, month, today, categories, context } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    // نافذةُ التأكيد من النظام لا من المتصفّح — انظر ConfirmDialog
    const [ask, confirmDialog] = useConfirm();
    const m = (v: number) => money(v, context!.currency);

    const [adding, setAdding] = useState(false);
    const [disposing, setDisposing] = useState<Asset | null>(null);

    const form = useForm({
        name: '',
        code: '',
        category: '',
        purchased_at: today,
        cost: '',
        salvage_value: '0',
        life_months: '60',
        paid_from: 'cash',
        notes: '',
    });

    const disposeForm = useForm({ disposed_at: today, amount: '', received_in: 'cash' });
    const disposeError = (disposeForm.errors as Record<string, string | undefined>).dispose;

    return (
        <AdminLayout title="الأصول الثابتة">
            <PageHeader
                title="الأصول الثابتة"
                subtitle={t('ما يُشترى ليُستعمل سنين — وقسطُ إهلاكه شهرًا بشهر')}
                actions={
                    <>
                        {/* الشهر يُختار هنا: الإهلاك يُرحَّل عن شهرٍ بعينه لا عن «الآن» */}
                        <Input
                            type="month"
                            dir="ltr"
                            className="w-40"
                            aria-label={t('الشهر')}
                            value={month}
                            onChange={(e) =>
                                router.get(route('admin.finance.assets'), { month: e.target.value }, {
                                    preserveState: true,
                                    preserveScroll: true,
                                    replace: true,
                                })
                            }
                        />
                        <Button
                            variant="outline"
                            disabled={summary.due <= 0}
                            onClick={async () => {
                                if (! await ask({ message: 'ترحيل إهلاك الشهر إلى الدفتر؟ يُكتب قيدٌ لا يُحذف.', action: 'ترحيل' })) return;
                                router.post(route('admin.finance.assets.depreciate'), { month }, { preserveScroll: true });
                            }}
                        >
                            <TrendingDown />
                            {t('ترحيل الإهلاك')}
                        </Button>
                        <Button onClick={() => setAdding(true)}>
                            <Plus />
                            {t('أصل جديد')}
                        </Button>
                    </>
                }
            />

            <SectionTabs tabs={ACCOUNTING_TABS} current="admin.finance.assets" />

            <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard stat={{ label: t('أصول نشطة'), value: number(summary.count), icon: 'package', color: 'info' }} index={0} />
                <StatCard stat={{ label: t('التكلفة'), value: m(summary.cost), icon: 'coins', color: 'secondary' }} index={1} />
                <StatCard stat={{ label: t('مجمّع الإهلاك'), value: m(summary.accumulated), icon: 'trending-down', color: 'warning' }} index={2} />
                <StatCard stat={{ label: t('القيمة الدفترية'), value: m(summary.book_value), icon: 'wallet', color: 'primary' }} index={3} />
            </div>

            {summary.due > 0 && (
                <Card className="mb-6 flex flex-wrap items-center justify-between gap-3 bg-[#fffbeb] p-4">
                    <p className="text-sm text-[#92400e]">
                        {t('إهلاكٌ لم يُرحَّل حتى :month بقيمة :value', { month, value: m(summary.due) })}
                    </p>
                    <span className="text-[12px] text-[#b45309]">
                        {t('حتى يُرحَّل، يظهر الربح أكبر ممّا هو والأصل بثمن شرائه.')}
                    </span>
                </Card>
            )}

            <Card className="overflow-hidden">
                <Table>
                    <TableHeader>
                        <TableRow className="hover:bg-transparent">
                            <TableHead>{t('الأصل')}</TableHead>
                            <TableHead>{t('تاريخ الشراء')}</TableHead>
                            <TableHead className="text-end">{t('التكلفة')}</TableHead>
                            <TableHead className="text-end">{t('القسط الشهري')}</TableHead>
                            <TableHead className="text-end">{t('مجمّع الإهلاك')}</TableHead>
                            <TableHead className="text-end">{t('القيمة الدفترية')}</TableHead>
                            <TableHead>{t('الحالة')}</TableHead>
                            <TableHead className="text-end">{t('إجراءات')}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {assets.length === 0 ? (
                            <TableEmpty colSpan={8}>{t('لا أصول مسجّلة بعد')}</TableEmpty>
                        ) : (
                            assets.map((a) => (
                                <TableRow key={a.id} className={cn(a.status !== 'نشط' && 'opacity-60')}>
                                    <TableCell>
                                        <span className="font-medium text-[#111]">{a.name}</span>
                                        {(a.code || a.category) && (
                                            <span className="block text-[12px] text-[#9ca3af]">
                                                {[a.code, a.category].filter(Boolean).join(' · ')}
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell dir="ltr" className="text-[#6b7280]">
                                        {a.purchased_at ?? '—'}
                                    </TableCell>
                                    <TableCell className="text-end tabular-nums">{m(a.cost)}</TableCell>
                                    <TableCell className="text-end tabular-nums text-[#6b7280]">
                                        {m(a.monthly)}
                                        <span className="block text-[12px] text-[#9ca3af]">
                                            {number(a.life_months)} {t('شهرًا')}
                                        </span>
                                    </TableCell>
                                    <TableCell className="text-end tabular-nums text-[#d97706]">
                                        {m(a.accumulated)}
                                        {a.due > 0 && (
                                            <span className="block text-[12px] text-[#b45309]">
                                                +{m(a.due)} {t('لم يُرحَّل')}
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-end font-semibold tabular-nums text-[#111]">
                                        {m(a.book_value)}
                                    </TableCell>
                                    <TableCell>
                                        <Badge
                                            variant={
                                                a.status === 'نشط' ? 'success' : a.status === 'مباع' ? 'info' : 'neutral'
                                            }
                                        >
                                            {t(a.status)}
                                        </Badge>
                                    </TableCell>
                                    <TableCell className="text-end">
                                        <div className="flex items-center justify-end gap-1">
                                            {a.status === 'نشط' && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() => {
                                                        disposeForm.clearErrors();
                                                        disposeForm.setData({
                                                            disposed_at: today,
                                                            amount: '',
                                                            received_in: 'cash',
                                                        });
                                                        setDisposing(a);
                                                    }}
                                                >
                                                    {t('استبعاد')}
                                                </Button>
                                            )}
                                            {a.accumulated === 0 && a.status === 'نشط' && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    className="text-[#b91c1c]"
                                                    onClick={async () => {
                                                        if (! await ask({ message: 'حذف الأصل؟', danger: true, action: 'حذف' })) return;
                                                        router.delete(route('admin.finance.assets.destroy', a.id), {
                                                            preserveScroll: true,
                                                        });
                                                    }}
                                                >
                                                    <Trash2 />
                                                </Button>
                                            )}
                                        </div>
                                    </TableCell>
                                </TableRow>
                            ))
                        )}
                    </TableBody>
                </Table>
            </Card>

            {/* ===== أصل جديد ===== */}
            <Dialog open={adding} onOpenChange={setAdding}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{t('أصل ثابت جديد')}</DialogTitle>
                    </DialogHeader>

                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post(route('admin.finance.assets.store'), {
                                preserveScroll: true,
                                onSuccess: () => {
                                    setAdding(false);
                                    form.reset();
                                },
                            });
                        }}
                        className="space-y-4 px-5 pb-5"
                    >
                        <Field label="الاسم" required error={form.errors.name}>
                            <Input
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                                placeholder={t('ثلاجة عرض')}
                            />
                        </Field>

                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <Field label="الرمز" error={form.errors.code}>
                                <Input value={form.data.code} onChange={(e) => form.setData('code', e.target.value)} />
                            </Field>
                            <Field label="التصنيف" error={form.errors.category}>
                                <Input
                                    list="asset-categories"
                                    value={form.data.category}
                                    onChange={(e) => form.setData('category', e.target.value)}
                                    placeholder={t('أجهزة')}
                                />
                                <datalist id="asset-categories">
                                    {categories.map((c) => (
                                        <option key={c} value={c} />
                                    ))}
                                </datalist>
                            </Field>
                        </div>

                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <Field label="تاريخ الشراء" required error={form.errors.purchased_at}>
                                <Input
                                    type="date"
                                    dir="ltr"
                                    value={form.data.purchased_at}
                                    onChange={(e) => form.setData('purchased_at', e.target.value)}
                                />
                            </Field>
                            <Field label="التكلفة" required error={form.errors.cost}>
                                <Input
                                    type="number"
                                    step="0.001"
                                    min="0"
                                    dir="ltr"
                                    value={form.data.cost}
                                    onChange={(e) => form.setData('cost', e.target.value)}
                                />
                            </Field>
                        </div>

                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <Field
                                label="قيمة الخردة"
                                hint="ما يُتوقّع بيعه به بعد انتهاء عمره — لا يُهلَك"
                                error={form.errors.salvage_value}
                            >
                                <Input
                                    type="number"
                                    step="0.001"
                                    min="0"
                                    dir="ltr"
                                    value={form.data.salvage_value}
                                    onChange={(e) => form.setData('salvage_value', e.target.value)}
                                />
                            </Field>
                            <Field label="العمر بالأشهر" required error={form.errors.life_months}>
                                <Input
                                    type="number"
                                    min="1"
                                    dir="ltr"
                                    value={form.data.life_months}
                                    onChange={(e) => form.setData('life_months', e.target.value)}
                                />
                            </Field>
                        </div>

                        <Field
                            label="دُفع من"
                            hint="يُقيَّد شراؤه في الدفتر — واتركه فارغًا إن كان مسجَّلًا أصلًا"
                            error={form.errors.paid_from}
                        >
                            <Select
                                placeholder="لا تُقيّد الشراء"
                                value={form.data.paid_from}
                                onChange={(e) => form.setData('paid_from', e.target.value)}
                                options={[
                                    { value: 'cash', label: 'الصندوق' },
                                    { value: 'bank', label: 'البنك' },
                                    { value: 'payable', label: 'ذمم الموردين (آجل)' },
                                ]}
                            />
                        </Field>

                        <div className="flex justify-end gap-2 pt-2">
                            <Button type="button" variant="ghost" onClick={() => setAdding(false)}>
                                {t('إلغاء')}
                            </Button>
                            <Button type="submit" loading={form.processing}>
                                <Check />
                                {t('حفظ')}
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>

            {/* ===== استبعاد أو بيع ===== */}
            <Dialog open={disposing !== null} onOpenChange={(o) => !o && setDisposing(null)}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>
                            {t('استبعاد الأصل')} — {disposing?.name}
                        </DialogTitle>
                    </DialogHeader>

                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            if (!disposing) return;
                            disposeForm.post(route('admin.finance.assets.dispose', disposing.id), {
                                preserveScroll: true,
                                onSuccess: () => setDisposing(null),
                            });
                        }}
                        className="space-y-4 px-5 pb-5"
                    >
                        <div className="rounded-[12px] bg-[#fafafa] p-3 text-[13px] text-[#6b7280]">
                            <p className="flex justify-between">
                                <span>{t('القيمة الدفترية')}</span>
                                <span className="font-semibold tabular-nums text-[#111]">
                                    {m(disposing?.book_value ?? 0)}
                                </span>
                            </p>
                            <p className="mt-1 text-[12px] text-[#9ca3af]">
                                {t('ما زاد عن القيمة الدفترية يُقيَّد ربحًا، وما نقص يُقيَّد خسارة.')}
                            </p>
                        </div>

                        <Field label="التاريخ" required error={disposeForm.errors.disposed_at}>
                            <Input
                                type="date"
                                dir="ltr"
                                value={disposeForm.data.disposed_at}
                                onChange={(e) => disposeForm.setData('disposed_at', e.target.value)}
                            />
                        </Field>

                        <Field
                            label="المبلغ المستلم"
                            hint="اتركه فارغًا إن استُبعد بلا بيع"
                            error={disposeForm.errors.amount}
                        >
                            <Input
                                type="number"
                                step="0.001"
                                min="0"
                                dir="ltr"
                                value={disposeForm.data.amount}
                                onChange={(e) => disposeForm.setData('amount', e.target.value)}
                            />
                        </Field>

                        {(parseFloat(disposeForm.data.amount) || 0) > 0 && (
                            <Field label="أُودع في" error={disposeForm.errors.received_in}>
                                <Select
                                    value={disposeForm.data.received_in}
                                    onChange={(e) => disposeForm.setData('received_in', e.target.value)}
                                    options={[
                                        { value: 'cash', label: 'الصندوق' },
                                        { value: 'bank', label: 'البنك' },
                                    ]}
                                />
                            </Field>
                        )}

                        {/* خطأُ الترحيل ليس خطأ حقلٍ بعينه: القيد يُرفض كوحدة */}
                        {disposeError && <p className="text-[12px] text-[#b91c1c]">{disposeError}</p>}

                        <div className="flex justify-end gap-2 pt-2">
                            <Button type="button" variant="ghost" onClick={() => setDisposing(null)}>
                                {t('إلغاء')}
                            </Button>
                            <Button type="submit" loading={disposeForm.processing}>
                                <Package />
                                {t('تسجيل')}
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>

            {confirmDialog}
        </AdminLayout>
    );
}
