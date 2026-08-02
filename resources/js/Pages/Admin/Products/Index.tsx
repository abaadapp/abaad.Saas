import { useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { motion } from 'framer-motion';
import {
    Barcode,
    Eye,
    FileDown,
    FileSpreadsheet,
    FileText,
    LayoutGrid,
    List,
    MoreVertical,
    Plus,
    Undo2,
    Upload,
} from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { PRODUCT_TABS } from '@/Components/SectionTabs';
import RowActions from '@/Components/RowActions';
import SmartLink from '@/Components/SmartLink';
import DataTable, { type Column, type Filter, type ServerPagination } from '@/Components/DataTable';
import Field, { Select } from '@/Components/Field';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';
import { Input } from '@/Components/ui/input';
import { money, number } from '@/lib/format';
import useLiveStock from '@/hooks/useLiveStock';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';
import type { Category, Product } from '@/types/models';

interface Props {
    products: Product[];
    pagination: ServerPagination;
    categories: Category[];
    filters: Record<string, string | null>;
    branches: { id: number; name: string }[];
    currentBranchId: number | null;
    lastImport: { file: string; added: number; updated: number; created_at: string } | null;
}

export default function ProductsIndex() {
    const { products: serverProducts, pagination, categories, filters, branches, currentBranchId, lastImport, context } =
        usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const currency = context!.currency;
    const [importing, setImporting] = useState(false);
    const [undoing, setUndoing] = useState(false);

    const upload = useForm<{
        file: File | null;
        branch_id: string;
        prices_include_tax: boolean;
        branch_mode: 'single' | 'columns';
    }>({
        file: null,
        branch_id: currentBranchId ? String(currentBranchId) : '',
        // سؤالان جوابهما ليس في الملف: سعرٌ شامل الضريبة يبدو كأي سعر،
        // وعمودُ كميةٍ واحد يبدو كعمود فرع. الخطأ فيهما صامت.
        prices_include_tax: false,
        branch_mode: 'single',
    });

    const submitImport = (e: React.FormEvent) => {
        e.preventDefault();
        upload.post(route('admin.products.import.upload'), { forceFormData: true });
    };

    /* بطاقة «منتجات منخفضة المخزون» في اللوحة كانت تتحدّث كل 15 ثانية وهذا
       الجدول مجمّد على لقطة لحظة الفتح — فيقرأ التاجر رقمين متناقضين عن
       الشيء نفسه. التغذية بإجمالي الشركة كما يعرض هذا الجدول. */
    const { products, updatedAt } = useLiveStock(route('admin.products.stockFeed'), serverProducts);
    const [view, setView] = useState<'table' | 'grid'>('table');

    const actionsFor = (p: Product) => (
        <RowActions
            show={{ routeName: 'admin.products.show', href: route('admin.products.show', p.id) }}
            edit={{ routeName: 'admin.products.edit', href: route('admin.products.edit', p.id) }}
            destroy={{ url: route('admin.products.destroy', p.id), message: 'حذف المنتج؟' }}
        />
    );

    const columns: Column<Product>[] = [
        {
            key: 'name',
            header: 'المنتج',
            cell: (p) => (
                <div className="flex items-center gap-3">
                    {p.image ? (
                        <img src={p.image} alt="" className="size-11 shrink-0 rounded-[8px] border border-[var(--ui-border,#e8e8e8)] object-cover" loading="lazy" />
                    ) : (
                        <span className="size-11 shrink-0 rounded-[8px] bg-[#f2f2f0]" />
                    )}
                    <span className="min-w-0">
                        <SmartLink
                            routeName="admin.products.show"
                            href={route('admin.products.show', p.id)}
                            className="block truncate font-medium hover:underline"
                        >
                            {p.label ?? p.name}
                        </SmartLink>
                        <span className="block text-[11px] text-[#9ca3af]">{p.sku || '—'}</span>
                    </span>
                </div>
            ),
        },
        { key: 'cat', header: 'القسم', cell: (p) => p.cat || '—' },
        {
            key: 'price',
            header: 'السعر',
            align: 'end',
            cell: (p) => <span className="tabular-nums font-medium">{money(p.price, currency)}</span>,
        },
        {
            key: 'cost',
            header: 'سعر التكلفة',
            align: 'end',
            cell: (p) => <span className="tabular-nums text-[#6b7280]">{money(p.cost, currency)}</span>,
        },
        {
            key: 'qty',
            header: 'الكمية',
            align: 'end',
            cell: (p) => <span className="tabular-nums">{number(p.qty)}</span>,
        },
        { key: 'stock_status', header: 'المخزون', cell: (p) => <Badge status={p.stock_status} /> },
        {
            key: 'active',
            header: 'الحالة',
            cell: (p) => (
                <Badge variant={p.active ? 'success' : 'neutral'}>{p.active ? t('مفعّل') : t('غير مفعّل')}</Badge>
            ),
        },
        { key: 'actions', header: 'إجراءات', align: 'end', cell: actionsFor },
    ];

    const tableFilters: Filter<Product>[] = [
        {
            label: 'كل الأقسام',
            param: 'category',
            options: categories.map((c) => ({ label: c.name, value: c.name })),
        },
        {
            label: 'كل الحالات',
            param: 'status',
            options: [
                { label: 'مفعّل', value: 'active' },
                { label: 'غير مفعّل', value: 'inactive' },
            ],
        },
        {
            label: 'حالة المخزون',
            param: 'stock',
            options: [
                { label: 'متوفر', value: 'متوفر' },
                { label: 'منخفض', value: 'منخفض' },
                { label: 'نفد المخزون', value: 'نفد المخزون' },
            ],
        },
    ];

    return (
        <AdminLayout title="المنتجات">
            <PageHeader
                title="المنتجات"
                subtitle={t('إدارة منتجات محل الورود')}
                breadcrumbs={[{ label: 'الرئيسية', href: route('admin.dashboard') }, { label: 'المنتجات' }]}
                actions={
                    <>
                        <Button variant="outline" asChild>
                            <SmartLink routeName="admin.products.barcodes" href={route('admin.products.barcodes')}>
                                <Barcode />
                                {t('طباعة الباركود')}
                            </SmartLink>
                        </Button>
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button variant="outline" size="icon" aria-label={t('المزيد')}>
                                    <MoreVertical />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" className="w-72">
                                <DropdownMenuLabel>{t('تصدير')}</DropdownMenuLabel>
                                {/* الأول بيانات تدور: أعمدته هي أعمدة الاستيراد نفسها.
                                    والثاني تقريرٌ للطباعة فيه عنوان ومعرّف وحالة محسوبة —
                                    لا يعود من حيث خرج، فلا يُسمَّى باسمه. */}
                                <DropdownMenuItem asChild>
                                    <a href={route('admin.products.export.xlsx')}>
                                        <FileSpreadsheet className="text-[#059669]" />
                                        {t('تصدير Excel (xlsx)')}
                                    </a>
                                </DropdownMenuItem>
                                <DropdownMenuItem asChild>
                                    <a href={route('admin.products.export.pdf')} target="_blank" rel="noreferrer">
                                        <FileText className="text-[#dc2626]" />
                                        {t('تصدير PDF')}
                                    </a>
                                </DropdownMenuItem>
                                <DropdownMenuItem asChild>
                                    <a href={route('admin.export.products')}>
                                        <FileDown className="text-[#6b7280]" />
                                        {t('تصدير CSV')}
                                    </a>
                                </DropdownMenuItem>
                                <DropdownMenuSeparator />
                                <DropdownMenuLabel>{t('تقارير للطباعة')}</DropdownMenuLabel>
                                <DropdownMenuItem asChild>
                                    <a href={route('admin.products.xlsx')}>
                                        <FileSpreadsheet className="text-[#9ca3af]" />
                                        {t('تقرير المنتجات (Excel)')}
                                    </a>
                                </DropdownMenuItem>
                                <DropdownMenuItem asChild>
                                    <a href={route('admin.products.exportPdf')} target="_blank" rel="noreferrer">
                                        <FileText className="text-[#9ca3af]" />
                                        {t('تقرير المنتجات (PDF)')}
                                    </a>
                                </DropdownMenuItem>
                                <DropdownMenuSeparator />
                                <DropdownMenuLabel>{t('استيراد')}</DropdownMenuLabel>
                                <DropdownMenuItem onSelect={() => setImporting(true)}>
                                    <Upload className="text-[#6d28d9]" />
                                    {t('استيراد من ملف…')}
                                </DropdownMenuItem>
                                {lastImport && (
                                    <DropdownMenuItem onSelect={() => setUndoing(true)}>
                                        <Undo2 className="text-[#b45309]" />
                                        {t('تراجع عن آخر استيراد')}
                                    </DropdownMenuItem>
                                )}
                            </DropdownMenuContent>
                        </DropdownMenu>
                        <Button asChild>
                            <SmartLink routeName="admin.products.create" href={route('admin.products.create')}>
                                <Plus />
                                {t('إضافة منتج')}
                            </SmartLink>
                        </Button>
                    </>
                }
            />

            <SectionTabs tabs={PRODUCT_TABS} current="admin.products.index" />

            {/* مبدّل العرض: شبكي أو جدول — كما كان في Alpine */}
            <div className="mb-4 flex items-center gap-1 border-b border-[var(--ui-border,#e8e8e8)]">
                {([
                    { key: 'grid', label: 'عرض شبكي', Icon: LayoutGrid },
                    { key: 'table', label: 'عرض جدول', Icon: List },
                ] as const).map(({ key, label, Icon }) => (
                    <button
                        key={key}
                        type="button"
                        onClick={() => setView(key)}
                        className={cn(
                            '-mb-px flex items-center gap-2 border-b-2 px-3 py-2.5 text-sm font-medium transition-colors',
                            view === key
                                ? 'border-[#111] text-[#111]'
                                : 'border-transparent text-[#6b7280] hover:text-[#374151]',
                        )}
                    >
                        <Icon className="size-4" />
                        {t(label)}
                    </button>
                ))}
                <span className="ms-auto text-sm text-[#6b7280]">
                    {number(pagination.total)} {t('منتج')}
                </span>
            </div>

            <Card className="overflow-hidden">
                {updatedAt && (
                    <p className="mb-2 text-[12px] text-[#9ca3af]">
                        {t('الكميات محدّثة حتى')} <span dir="ltr">{updatedAt}</span>
                    </p>
                )}

                <DataTable
                    rows={products}
                    columns={columns}
                    rowKey={(p) => p.id}
                    searchPlaceholder="ابحث بالاسم أو SKU…"
                    searchable={() => ''}
                    filters={tableFilters}
                    empty="لا توجد منتجات بعد — أضف أول منتج"
                    server={{ pagination, params: filters }}
                    // العرض الشبكي يستبدل الجدول ويُبقي البحث والتصفية والترقيم فوقه
                    renderBody={
                        view === 'grid'
                            ? (rows) => (
                                  <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                                      {rows.map((p, i) => (
                                          <motion.div
                                              key={p.id}
                                              initial={{ opacity: 0, y: 6 }}
                                              animate={{ opacity: 1, y: 0 }}
                                              transition={{ duration: 0.2, delay: Math.min(i * 0.02, 0.2) }}
                                          >
                                              <Card className="flex h-full flex-col overflow-hidden">
                                                  <div className="relative aspect-[4/3] bg-[#f7f7f5]">
                                                      {p.image && (
                                                          <img src={p.image} alt="" className="size-full object-cover" loading="lazy" />
                                                      )}
                                                      <span className="absolute start-2 top-2">
                                                          <Badge status={p.stock_status} />
                                                      </span>
                                                      <span className="absolute end-2 top-2">{actionsFor(p)}</span>
                                                  </div>
                                                  <div className="flex flex-1 flex-col gap-1 p-3.5">
                                                      <SmartLink
                                                          routeName="admin.products.show"
                                                          href={route('admin.products.show', p.id)}
                                                          className="truncate font-medium text-[#111] hover:underline"
                                                      >
                                                          {p.label ?? p.name}
                                                      </SmartLink>
                                                      <span className="text-[12px] text-[#9ca3af]">{p.cat || '—'}</span>
                                                      <div className="mt-auto flex items-center justify-between pt-2">
                                                          <span className="font-semibold tabular-nums">
                                                              {money(p.price, currency)}
                                                          </span>
                                                          <span className="text-[12px] text-[#6b7280]">
                                                              {t('الكمية')}: {number(p.qty)}
                                                          </span>
                                                      </div>
                                                  </div>
                                              </Card>
                                          </motion.div>
                                      ))}
                                  </div>
                              )
                            : undefined
                    }
                />
            </Card>

            {/* استيراد المنتجات */}
            <Dialog open={importing} onOpenChange={setImporting}>
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{t('استيراد المنتجات من ملف')}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submitImport} className="space-y-4 px-5 pb-5">
                        <Field
                            label="ملف المنتجات"
                            hint="الصيغ المدعومة: CSV، XLS، XLSX، XLSM — الأعمدة: الاسم، القسم، SKU، الباركود، السعر، التكلفة، الكمية، حد التنبيه، الضريبة %، الخصم %، الحالة. يمكنك تصدير ملف ثم تعديله وإعادة استيراده."
                            error={upload.errors.file}
                            required
                        >
                            <Input
                                type="file"
                                accept=".csv,.xls,.xlsx,.xlsm"
                                required
                                onChange={(e) => upload.setData('file', e.target.files?.[0] ?? null)}
                                className="h-auto py-2 file:me-3 file:rounded-lg file:bg-[#111] file:px-4 file:py-2 file:text-white"
                            />
                        </Field>

                        <Field
                            label="هل الأسعار في الملف شاملة الضريبة؟"
                            hint="جوابه ليس في الملف: عمود «السعر» يبدو واحدًا في الحالتين. وإدخال سعرٍ شامل على أنه صافٍ يرفع أسعار المتجر كلها — ولا يظهر إلا في تقرير الأرباح بعد شهر."
                        >
                            <Select
                                value={upload.data.prices_include_tax ? '1' : '0'}
                                onChange={(e) => upload.setData('prices_include_tax', e.target.value === '1')}
                                options={[
                                    { label: 'صافية (بلا ضريبة)', value: '0' },
                                    { label: 'شاملة الضريبة — تُخصم عند الاستيراد', value: '1' },
                                ]}
                            />
                        </Field>

                        <Field
                            label="الكميات في الملف"
                            hint="إن كان لكل فرع عمودٌ باسمه، اختر الثاني — وإلا ستُودَع الكميات كلها في فرع واحد."
                        >
                            <Select
                                value={upload.data.branch_mode}
                                onChange={(e) =>
                                    upload.setData('branch_mode', e.target.value as 'single' | 'columns')
                                }
                                options={[
                                    { label: 'عمود كمية واحد — لفرع واحد', value: 'single' },
                                    { label: 'عمود لكل فرع (باسم الفرع)', value: 'columns' },
                                ]}
                            />
                        </Field>

                        {upload.data.branch_mode === 'single' && (
                            <Field
                                label="فرع الكميات"
                                hint="الكميات المستوردة تُودَع في هذا الفرع — وهو ما يُباع منه لاحقًا."
                                error={upload.errors.branch_id}
                            >
                                <Select
                                    value={upload.data.branch_id}
                                    onChange={(e) => upload.setData('branch_id', e.target.value)}
                                    options={branches.map((b) => ({ label: b.name, value: b.id }))}
                                    placeholder="الفرع الحالي"
                                />
                            </Field>
                        )}

                        <p className="rounded-[12px] bg-[#f5f3ff] px-3 py-2.5 text-[12px] text-[#6d28d9]">
                            {t('المنتجات الموجودة (بنفس SKU أو الباركود) تُحدَّث بدل تكرارها، والأقسام غير الموجودة تُنشأ تلقائيًا. وستظهر معاينة كاملة قبل التأكيد.')}
                        </p>

                        <div className="flex justify-end gap-2 pt-1">
                            <Button type="button" variant="ghost" onClick={() => setImporting(false)}>
                                {t('إلغاء')}
                            </Button>
                            <Button type="submit" disabled={upload.processing}>
                                <Eye />
                                {upload.processing ? '…' : t('معاينة الملف')}
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>

            {/* تراجع عن آخر استيراد */}
            <Dialog open={undoing} onOpenChange={setUndoing}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>{t('تراجع عن آخر استيراد')}</DialogTitle>
                    </DialogHeader>
                    <div className="px-5 pb-5">
                        <p className="text-sm text-[#4b4b4b]">
                            {t('سيُحذف ما أُضيف وتُعاد المنتجات المحدَّثة إلى قيمها السابقة.')}
                        </p>
                        {lastImport && (
                            <p className="mt-2 text-[12px] text-[#9ca3af]">
                                {lastImport.file} · {t('أُضيف')} {number(lastImport.added)} · {t('حُدِّث')}{' '}
                                {number(lastImport.updated)}
                            </p>
                        )}
                        <p className="mt-3 rounded-[12px] bg-[#fffbeb] px-3 py-2.5 text-[12px] text-[#b45309]">
                            {t('المنتجات التي بِيعت بعد استيرادها لا تُحذف — حذفها يُفقد فواتير صدرت بها.')}
                        </p>
                        <div className="mt-5 flex justify-end gap-2">
                            <Button variant="outline" onClick={() => setUndoing(false)}>
                                {t('إلغاء')}
                            </Button>
                            <Button
                                variant="danger"
                                onClick={() =>
                                    router.post(
                                        route('admin.products.import.undo'),
                                        {},
                                        { onFinish: () => setUndoing(false) },
                                    )
                                }
                            >
                                <Undo2 />
                                {t('تراجع')}
                            </Button>
                        </div>
                    </div>
                </DialogContent>
            </Dialog>
        </AdminLayout>
    );
}
