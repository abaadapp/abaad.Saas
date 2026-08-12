import { useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { AlertTriangle, Check, Info, ShieldCheck, X } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import { Select } from '@/Components/Field';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
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

interface Row {
    name: string | null;
    categoryDisplay: string;
    categoryNew: boolean;
    sku: string | null;
    barcode: string | null;
    price: number;
    grossPrice: number;
    cost: number;
    quantity: number;
    branchQty: Record<string, number>;
    currentQty: number | null;
    active: boolean;
    status: 'new' | 'update' | 'invalid' | 'dup_file';
    note?: string | null;
}

interface Props {
    rows: Row[];
    counts: { total: number; new: number; update: number; skip: number };
    /** حقولٌ لا يذكرها الملفّ — تبقى كما هي في المنتجات المحدَّثة */
    untouched: string[];
    branchName: string | null;
    newCategories: string[];
    file: string;
    fileColumns: { index: number; label: string; sample: string }[];
    mapping: Record<string, number | null>;
    fields: { key: string; label: string }[];
    options: { prices_include_tax: boolean; branch_mode: 'single' | 'columns' };
    hasHeader: boolean;
    branchColumns: Record<string, number>;
    branchNames: Record<string, string>;
    taxRate: number;
    truncated: number;
    sheets: string[];
}

/** لون الصف وشارته حسب ما سيحدث له عند التأكيد */
const STATUS: Record<Row['status'], { row: string; variant: 'success' | 'info' | 'danger' | 'warning'; label: string }> = {
    new: { row: '', variant: 'success', label: 'سيُضاف' },
    update: { row: 'bg-[#eff6ff]/50', variant: 'info', label: 'سيُحدَّث' },
    invalid: { row: 'bg-[#fef2f2]/50', variant: 'danger', label: 'غير صالح' },
    dup_file: { row: 'bg-[#fafafa]', variant: 'warning', label: 'مكرر' },
};

export default function ProductImportPreview() {
    const {
        rows, counts, untouched, branchName, newCategories, file, fileColumns, mapping, fields,
        options, hasHeader, branchColumns, branchNames, taxRate, truncated, sheets, context,
    } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const currency = context!.currency;
    const [confirming, setConfirming] = useState(false);

    /**
     * الإسناد اليدوي: الكشف اقتراحٌ أوّليّ لا حكم. عمودٌ اسمه «سعر الجملة»
     * يُسنَد إلى السعر بثقة، فتدخل أسعار الجملة أسعارَ تجزئة بلا أن يظهر
     * شيء. كل تغيير يُعيد التحليل من الملف الخام فتتحرّك المعاينة معه.
     */
    const remap = useForm<{
        mapping: Record<string, string>;
        prices_include_tax: boolean;
        branch_mode: 'single' | 'columns';
        has_header: boolean;
    }>({
        mapping: Object.fromEntries(
            fields.map((f) => [f.key, mapping[f.key] === null || mapping[f.key] === undefined ? '' : String(mapping[f.key])]),
        ),
        prices_include_tax: options.prices_include_tax,
        branch_mode: options.branch_mode,
        has_header: hasHeader,
    });

    const apply = (next: Partial<typeof remap.data>) => {
        remap.transform((d) => ({ ...d, ...next }));
        remap.post(route('admin.products.import.remap'), { preserveScroll: true });
    };

    const columnOptions = [
        { label: t('— لا يوجد —'), value: '' },
        ...fileColumns.map((c) => ({
            label: c.sample ? `${c.label} · ${c.sample}` : c.label,
            value: String(c.index),
        })),
    ];

    const branchColumnList = Object.entries(branchColumns).map(
        ([col, id]) => `${fileColumns[Number(col)]?.label ?? col} → ${branchNames[String(id)] ?? id}`,
    );

    const applyCount = counts.new + counts.update;

    const summary = [
        { label: 'إجمالي الصفوف', value: counts.total },
        { label: 'سيُضاف (جديد)', value: counts.new },
        { label: 'سيُحدَّث (موجود)', value: counts.update },
        { label: 'يُتجاهل', value: counts.skip },
    ];

    return (
        <AdminLayout title="معاينة استيراد المنتجات">
            <PageHeader
                title="معاينة استيراد المنتجات"
                subtitle={t('راجع البيانات المستوردة من الملف قبل حفظها نهائيًا')}
                breadcrumbs={[
                    { label: 'الرئيسية', href: route('admin.dashboard') },
                    { label: 'المنتجات', href: route('admin.products.index') },
                    { label: 'معاينة الاستيراد' },
                ]}
                actions={
                    <>
                        <Button
                            variant="outline"
                            onClick={() => router.post(route('admin.products.import.cancel'))}
                        >
                            <X />
                            {t('إلغاء')}
                        </Button>
                        <Button disabled={applyCount === 0} onClick={() => setConfirming(true)}>
                            <Check />
                            {t('تأكيد الاستيراد')} ({number(applyCount)})
                        </Button>
                    </>
                }
            />

            <div className="mb-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
                {summary.map((s) => (
                    <Card key={s.label} className="p-4 text-center">
                        <p className="text-[22px] font-bold tabular-nums text-[#111]">{number(s.value)}</p>
                        <p className="mt-0.5 text-[12px] text-[#9ca3af]">{t(s.label)}</p>
                    </Card>
                ))}
            </div>

            {/*
                ما لا يذكره الملفّ يبقى كما هو — ويُقال قبل التأكيد.
                كان الغائب يُقرأ صفرًا: قائمة أسعارٍ فيها اسمٌ وسعر تمحو
                مخزون المتجر كلّه، وتمحو التكلفة فيصير كلّ بيعٍ ربحًا صافيًا.
            */}
            {untouched.length > 0 && (
                <div className="mb-4 flex items-start gap-2 rounded-[10px] bg-[#f0fdf4] px-3 py-2.5 text-[13px] text-[#166534]">
                    <ShieldCheck className="mt-0.5 size-4 shrink-0" />
                    <span>
                        {t('الملفّ لا يذكر:')} <b>{untouched.join('، ')}</b>{' — '}
                        {t('فتبقى كما هي في المنتجات الموجودة، ولا تُمسّ.')}
                    </span>
                </div>
            )}

            <div className="mb-4 flex flex-wrap items-center gap-2 text-[12px]">
                <span className="inline-flex items-center gap-2 rounded-[8px] bg-[#eff6ff] px-3 py-1.5 text-[#2563eb]">
                    <Info className="size-4" />
                    {t('المطابقة بـ SKU ثم الباركود ثم الاسم — الموجود يُحدَّث بدل تكراره.')}
                </span>
                <span className="text-[#9ca3af]">
                    {file} ·{' '}
                    {branchColumnList.length
                        ? t('الكميات موزّعة بأعمدة الفروع')
                        : `${t('فرع الكميات:')} ${branchName || t('بدون فرع')}`}
                </span>
            </div>

            {/* إسناد الأعمدة — يُراجَع قبل النظر في الصفوف، لأن كل رقم تحته يعتمد عليه */}
            <Card className="mb-4 p-5">
                <div className="mb-3 flex flex-wrap items-baseline justify-between gap-2">
                    <h3 className="font-bold text-[#111]">{t('إسناد الأعمدة')}</h3>
                    <p className="text-[12px] text-[#9ca3af]">
                        {t('الإسناد التالي مقترَح من عناوين الملف — راجعه، وخاصةً السعر والتكلفة.')}
                    </p>
                </div>

                {/* الصفّ الأول: عناوين غير معروفة («عمود أول») لا تُكتشف ترويسةً،
                    فيدخل صفٌّ زائف منتجًا — والتاجر وحده يستطيع أن يقول ذلك */}
                <label className="mb-4 block max-w-sm">
                    <span className="mb-1 block text-[12px] font-medium text-[#4b4b4b]">
                        {t('الصف الأول في الملف')}
                    </span>
                    <Select
                        value={remap.data.has_header ? '1' : '0'}
                        options={[
                            { label: t('عناوين أعمدة'), value: '1' },
                            { label: t('بيانات منتج (لا عناوين)'), value: '0' },
                        ]}
                        onChange={(e) => apply({ has_header: e.target.value === '1' })}
                    />
                </label>

                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    {fields.map((f) => (
                        <label key={f.key} className="block">
                            <span className="mb-1 block text-[12px] font-medium text-[#4b4b4b]">{t(f.label)}</span>
                            <Select
                                value={remap.data.mapping[f.key] ?? ''}
                                options={columnOptions}
                                placeholder="— لا يوجد —"
                                onChange={(e) =>
                                    apply({ mapping: { ...remap.data.mapping, [f.key]: e.target.value } })
                                }
                            />
                        </label>
                    ))}
                </div>

                <div className="mt-4 grid grid-cols-1 gap-3 border-t border-[var(--ui-border,#e8e8e8)] pt-4 sm:grid-cols-2">
                    <label className="block">
                        <span className="mb-1 block text-[12px] font-medium text-[#4b4b4b]">
                            {t('الأسعار في الملف')}
                        </span>
                        <Select
                            value={remap.data.prices_include_tax ? '1' : '0'}
                            options={[
                                { label: t('صافية (بلا ضريبة)'), value: '0' },
                                { label: `${t('شاملة الضريبة')} ${taxRate}%`, value: '1' },
                            ]}
                            onChange={(e) => apply({ prices_include_tax: e.target.value === '1' })}
                        />
                    </label>
                    <label className="block">
                        <span className="mb-1 block text-[12px] font-medium text-[#4b4b4b]">
                            {t('الكميات في الملف')}
                        </span>
                        <Select
                            value={remap.data.branch_mode}
                            options={[
                                { label: t('عمود كمية واحد — لفرع واحد'), value: 'single' },
                                { label: t('عمود لكل فرع (باسم الفرع)'), value: 'columns' },
                            ]}
                            onChange={(e) => apply({ branch_mode: e.target.value as 'single' | 'columns' })}
                        />
                    </label>
                </div>

                {remap.data.branch_mode === 'columns' && (
                    <p
                        className={cn(
                            'mt-3 rounded-[12px] px-3 py-2.5 text-[12px]',
                            branchColumnList.length
                                ? 'bg-[#ecfdf5] text-[#047857]'
                                : 'bg-[#fef2f2] text-[#b91c1c]',
                        )}
                    >
                        {branchColumnList.length
                            ? `${t('أعمدة الفروع:')} ${branchColumnList.join('، ')}`
                            : t('لم يُطابَق أي عمود باسم فرع — لن تُودَع أي كمية. راجع عناوين الملف أو اختر «عمود كمية واحد».')}
                    </p>
                )}
            </Card>

            {/* ما اقتُطع أو أُهمل يُقال — اقتطاعٌ صامت يُقرأ على أنه استيراد كامل */}
            {(truncated > 0 || sheets.length > 1) && (
                <div className="mb-4 space-y-2">
                    {truncated > 0 && (
                        <p className="rounded-[12px] bg-[#fffbeb] px-4 py-3 text-[12px] text-[#b45309]">
                            {t('الملف أكبر من الحدّ — استُبعد')} {number(truncated)} {t('صفًّا. قسّم الملف واستورده على دفعات.')}
                        </p>
                    )}
                    {sheets.length > 1 && (
                        <p className="rounded-[12px] bg-[#fffbeb] px-4 py-3 text-[12px] text-[#b45309]">
                            {t('الملف يحوي أكثر من ورقة — قُرئت الأولى فقط:')} {sheets.join('، ')}
                        </p>
                    )}
                </div>
            )}

            {/* إنشاء قسمٍ أثرٌ باقٍ في الكتالوج — يُعلَن قبل التأكيد لا بعده */}
            {newCategories.length > 0 && (
                <div className="mb-4 flex flex-wrap items-center gap-2 rounded-[12px] bg-[#fffbeb] px-4 py-3 text-[12px] text-[#b45309]">
                    <AlertTriangle className="size-4 shrink-0" />
                    <span>
                        {t('ستُنشأ أقسام جديدة:')} {newCategories.join('، ')}
                    </span>
                </div>
            )}

            <Card className="overflow-hidden">
                <div className="overflow-x-auto">
                    <Table>
                        <TableHeader>
                            <TableRow className="hover:bg-transparent">
                                {['#', 'الاسم', 'القسم', 'SKU', 'الباركود', 'السعر', 'التكلفة', 'الكمية', 'الحالة'].map(
                                    (h) => (
                                        <TableHead key={h}>{h === '#' || h === 'SKU' ? h : t(h)}</TableHead>
                                    ),
                                )}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {rows.map((r, i) => {
                                const s = STATUS[r.status];

                                return (
                                    <TableRow key={i} className={cn(s.row)}>
                                        <TableCell className="text-[12px] text-[#9ca3af]">{i + 1}</TableCell>
                                        <TableCell className="font-medium text-[#111]">
                                            {r.name || '—'}
                                            {!r.active && (
                                                <span className="mt-0.5 block text-[11px] text-[#9ca3af]">
                                                    {t('معطّل')}
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-[#4b4b4b]">
                                            {r.categoryDisplay}
                                            {r.categoryNew && (
                                                <span className="mt-0.5 block text-[11px] text-[#b45309]">
                                                    {t('قسم جديد')}
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell dir="ltr" className="font-mono text-[12px] text-[#6b7280]">
                                            {r.sku || '—'}
                                        </TableCell>
                                        <TableCell dir="ltr" className="font-mono text-[12px] text-[#6b7280]">
                                            {r.barcode || '—'}
                                        </TableCell>
                                        {/* السعر الصافي هو ما سيُحفظ — والخام يبقى ظاهرًا
                                            حتى يرى التاجر أن الضريبة خُصمت فعلًا */}
                                        <TableCell className="tabular-nums font-medium">
                                            {money(r.price, currency)}
                                            {options.prices_include_tax && r.grossPrice !== r.price && (
                                                <span className="mt-0.5 block text-[11px] text-[#9ca3af]">
                                                    {t('شامل')} {money(r.grossPrice, currency)}
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell className="tabular-nums text-[#6b7280]">
                                            {money(r.cost, currency)}
                                        </TableCell>
                                        {/* الكمية تُكتب فوق القديمة لا تُضاف إليها — يُعرض الطرفان */}
                                        <TableCell className="tabular-nums text-[#4b4b4b]">
                                            {r.status === 'update' && r.currentQty !== null && r.currentQty !== r.quantity ? (
                                                <span dir="ltr" className="inline-flex items-center gap-1">
                                                    <span className="text-[#9ca3af] line-through">{r.currentQty}</span>
                                                    <span>→</span>
                                                    <span className="font-semibold">{r.quantity}</span>
                                                </span>
                                            ) : (
                                                number(r.quantity)
                                            )}
                                            {Object.keys(r.branchQty ?? {}).length > 0 && (
                                                <span className="mt-0.5 block text-[11px] text-[#9ca3af]">
                                                    {Object.entries(r.branchQty)
                                                        .map(([id, q]) => `${branchNames[id] ?? id}: ${q}`)
                                                        .join(' · ')}
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <Badge variant={s.variant}>{t(s.label)}</Badge>
                                            {(r.status === 'invalid' || r.status === 'dup_file') && r.note && (
                                                <span className="mt-0.5 block text-[11px] text-[#9ca3af]">{r.note}</span>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                    </Table>
                </div>
            </Card>

            {applyCount === 0 && (
                <div className="mt-5 flex items-center gap-2 rounded-[12px] bg-[#fffbeb] px-4 py-3 text-sm text-[#d97706]">
                    <AlertTriangle className="size-5" />
                    {t('لا يوجد أي صف صالح للإضافة أو التحديث. راجع الملف وأعد المحاولة.')}
                </div>
            )}

            <Dialog open={confirming} onOpenChange={setConfirming}>
                <DialogContent className="max-w-sm">
                    <DialogHeader>
                        <DialogTitle>{t('تأكيد الاستيراد')}</DialogTitle>
                    </DialogHeader>
                    <div className="px-5 pb-5">
                        <p className="text-sm text-[#4b4b4b]">
                            {t('سيُضاف')} {number(counts.new)} {t('ويُحدَّث')} {number(counts.update)}.
                        </p>
                        <p className="mt-2 text-[12px] text-[#9ca3af]">
                            {t('كميات الملف تحلّ محلّ الكميات الحالية، وتُودَع في')} {branchName || t('الفرع الافتراضي')}.
                        </p>
                        <div className="mt-5 flex justify-end gap-2">
                            <Button variant="outline" onClick={() => setConfirming(false)}>
                                {t('إلغاء')}
                            </Button>
                            <Button
                                onClick={() =>
                                    router.post(
                                        route('admin.products.import.confirm'),
                                        {},
                                        { onFinish: () => setConfirming(false) },
                                    )
                                }
                            >
                                {t('تأكيد')}
                            </Button>
                        </div>
                    </div>
                </DialogContent>
            </Dialog>
        </AdminLayout>
    );
}
