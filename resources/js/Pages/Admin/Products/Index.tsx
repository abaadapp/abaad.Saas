import { useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { motion } from 'framer-motion';
import {
    Copy,
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
import QuickCell from './partials/QuickCell';
import { withFilters } from '@/lib/exportLink';
import { money, number } from '@/lib/format';
import useLiveStock from '@/hooks/useLiveStock';
import { useConfirm } from '@/Components/ConfirmDialog';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';
import type { Category, Product } from '@/types/models';

interface Props {
    products: Product[];
    pagination: ServerPagination;
    categories: Category[];
    filters: Record<string, string | null>;
    /** أعمدة يرتّبها الخادم — مصدرها `Sort::keys` في المتحكّم */
    sorts: string[];
    branches: { id: number; name: string }[];
    currentBranchId: number | null;
    lastImport: { file: string; added: number; updated: number; created_at: string } | null;
}

export default function ProductsIndex() {
    const { products: serverProducts, pagination, categories, filters, sorts, branches, currentBranchId, lastImport, context } =
        usePage<PageProps<Props>>().props;
    const t = useTranslate();
    // نافذةُ التأكيد من النظام لا من المتصفّح — انظر ConfirmDialog
    const [ask, confirmDialog] = useConfirm();
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
    /* التحديد بمعرّفات الصفحة المعروضة: الإجراء الجماعي يمسّ ما تراه العين
       لا ما خلف الترقيم — «طبّقتُه على ١٢» أصدق من «على ٤٠٠ لم ترها» */
    const [selected, setSelected] = useState<number[]>([]);
    const [bulk, setBulk] = useState<'category' | 'price' | null>(null);
    const bulkForm = useForm({ action: '', ids: [] as number[], category_id: '', percent: '5' });

    const toggle = (id: number) =>
        setSelected((cur) => (cur.includes(id) ? cur.filter((x) => x !== id) : [...cur, id]));

    const runBulk = (action: string, extra: Record<string, unknown> = {}) => {
        router.post(route('admin.products.bulk'), { action, ids: selected, ...extra }, {
            preserveScroll: true,
            onSuccess: () => {
                setSelected([]);
                setBulk(null);
            },
        });
    };

    const actionsFor = (p: Product) => (
        <RowActions
            show={{ routeName: 'admin.products.show', href: route('admin.products.show', p.id) }}
            edit={{ routeName: 'admin.products.edit', href: route('admin.products.edit', p.id) }}
            destroy={{ url: route('admin.products.destroy', p.id), message: 'حذف المنتج؟' }}
            extra={[
                {
                    label: 'نسخ المنتج',
                    icon: <Copy className="size-4" />,
                    onSelect: () => router.post(route('admin.products.duplicate', p.id)),
                },
            ]}
        />
    );

    const columns: Column<Product>[] = [
        {
            key: 'select',
            header: '',
            cell: (p) => (
                <input
                    type="checkbox"
                    checked={selected.includes(p.id)}
                    onChange={() => toggle(p.id)}
                    aria-label={t('تحديد')}
                    className="size-4 cursor-pointer accent-[#111]"
                />
            ),
        },
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
            cell: (p) => (
                <QuickCell
                    id={p.id}
                    field="price"
                    value={p.price}
                    display={money(p.price, currency)}
                    className="font-medium"
                />
            ),
        },
        {
            key: 'cost',
            header: 'سعر التكلفة',
            align: 'end',
            cell: (p) => <span className="tabular-nums text-[#6b7280]">{money(p.cost, currency)}</span>,
        },
        {
            key: 'margin',
            header: 'الهامش',
            align: 'end',
            cell: (p) => {
                // بيعٌ بأقلّ من التكلفة يمرّ اليوم بلا اعتراض — فليُرَ بنظرة
                if (!p.price) return <span className="text-[#9ca3af]">—</span>;
                const margin = ((p.price - p.cost) / p.price) * 100;

                return (
                    <span
                        className={cn(
                            'tabular-nums',
                            margin < 0 ? 'font-bold text-[#dc2626]' : margin < 10 ? 'text-[#d97706]' : 'text-[#6b7280]',
                        )}
                    >
                        {margin.toFixed(0)}%
                    </span>
                );
            },
        },
        {
            key: 'qty',
            header: 'الكمية',
            align: 'end',
            cell: (p) => (
                <QuickCell id={p.id} field="quantity" value={p.qty} display={number(p.qty)} />
            ),
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
            asTabs: true,
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
                // مالٌ نائم على الرفّ: الجرد يعرضه «متوفرًا» كغيره
                { label: 'راكد — لم يُبَع منذ ٩٠ يومًا', value: 'راكد' },
            ],
        },
    ];

    return (
        <AdminLayout title="المنتجات">
            <PageHeader
                title="المنتجات"
                subtitle={t('إدارة منتجات محل الورود')}
                actions={
                    <>
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
                                    لا يعود من حيث خرج، فلا يُسمَّى باسمه.

                                    والزوج الدوّار لا يتبع المُرشِّحات عمدًا: من صدّر
                                    نصف الجرد ثمّ استورده ظنّ أنّه ردّ الجرد كلّه.
                                    وما تحت «تقارير للطباعة» يتبعها — تقريرٌ يُقرأ
                                    ويُطبع، فيجب أن يقول ما تقوله الشاشة. */}
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
                                    <a href={withFilters(route('admin.export.products'))}>
                                        <FileDown className="text-[#6b7280]" />
                                        {t('تصدير CSV')}
                                    </a>
                                </DropdownMenuItem>
                                <DropdownMenuSeparator />
                                <DropdownMenuLabel>{t('تقارير للطباعة')}</DropdownMenuLabel>
                                <DropdownMenuItem asChild>
                                    <a href={withFilters(route('admin.products.xlsx'))}>
                                        <FileSpreadsheet className="text-[#9ca3af]" />
                                        {t('تقرير المنتجات (Excel)')}
                                    </a>
                                </DropdownMenuItem>
                                <DropdownMenuItem asChild>
                                    <a href={withFilters(route('admin.products.exportPdf'))} target="_blank" rel="noreferrer">
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

            {/* مبدّل العرض صار في شريط أدوات الجدول — انظر `views` أدناه.
                كان شريطًا قائمًا بذاته فوق البطاقة، فيقف فوق شريط الحالة
                داخلها: شريطان متجاوران يبدّل كلٌّ منهما شيئًا آخر. */}

            {/* خارج البطاقة: Card بلا حشو داخلي (الحشو في CardHeader/CardContent)،
                فالفقرة داخله تلتصق بالحد و overflow-hidden يقصّها.
                والعدد هنا لأن مبدّل العرض الذي كان يحمله في طرفه انتقل إلى
                شريط الجدول — سطرٌ خافت أهون من شريطٍ لسطرٍ واحد. */}
            <p className="mb-3 text-[12px] text-[#9ca3af]">
                {number(pagination.total)} {t('منتج')}
                {updatedAt && (
                    <>
                        {' — '}
                        {t('الكميات محدّثة حتى')} <span dir="ltr">{updatedAt}</span>
                    </>
                )}
            </p>

            <Card className="overflow-hidden">
                <DataTable
                    rows={products}
                    columns={columns}
                    rowKey={(p) => p.id}
                    searchPlaceholder="ابحث بالاسم أو الرمز أو الباركود…"
                    searchable={() => ''}
                    filters={tableFilters}
                    empty="لا توجد منتجات بعد — أضف أول منتج"
                    server={{ pagination, params: filters, sorts }}
                    views={{
                        current: view,
                        onChange: (k) => setView(k as 'grid' | 'table'),
                        options: [
                            { key: 'grid', label: 'عرض شبكي', icon: LayoutGrid },
                            { key: 'table', label: 'عرض جدول', icon: List },
                        ],
                    }}
                    /*
                        شريط الإجراء الجماعي لا يظهر إلا حين يكون له ما يعمل
                        عليه — وكان رفع أسعار قسمٍ يعني فتح كل صنفٍ على حدة.
                    */
                    toolbar={
                        selected.length > 0 ? (
                            <div className="mb-3 flex flex-wrap items-center gap-2 rounded-[12px] border border-[#111] bg-[#fafafa] px-4 py-3">
                                <span className="text-[13px] font-medium text-[#111]">
                                    {t(':n محدَّد', { n: String(selected.length) })}
                                </span>
                                <span className="flex-1" />
                                <Button variant="outline" size="sm" onClick={() => runBulk('activate')}>
                                    {t('تفعيل')}
                                </Button>
                                <Button variant="outline" size="sm" onClick={() => runBulk('deactivate')}>
                                    {t('تعطيل')}
                                </Button>
                                <Button variant="outline" size="sm" onClick={() => setBulk('category')}>
                                    {t('نقل إلى قسم')}
                                </Button>
                                <Button variant="outline" size="sm" onClick={() => setBulk('price')}>
                                    {t('تغيير الأسعار %')}
                                </Button>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={async () => {
                                        // الحذف إلى السلة، ومع ذلك يُسأل: جماعيٌّ لا يُتراجع عنه بضغطة
                                        const yes = await ask({
                                            message: 'حذف :n منتجًا؟ تبقى في سلة المحذوفات.',
                                            values: { n: String(selected.length) },
                                            danger: true,
                                            action: 'حذف',
                                        });

                                        if (yes) runBulk('delete');
                                    }}
                                    className="text-[#dc2626]"
                                >
                                    {t('حذف')}
                                </Button>
                                <Button variant="ghost" size="sm" onClick={() => setSelected([])}>
                                    {t('إلغاء التحديد')}
                                </Button>
                            </div>
                        ) : null
                    }
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
                            <Button type="submit" loading={upload.processing}>
                                <Eye />
                                {t('معاينة الملف')}
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

            {/* نقل المحدَّد إلى قسم */}
            <Dialog open={bulk === 'category'} onOpenChange={(o) => !o && setBulk(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{t('نقل إلى قسم')}</DialogTitle>
                    </DialogHeader>
                    <div className="p-6 pt-0">
                        <Field label="القسم">
                            <Select
                                value={bulkForm.data.category_id}
                                onChange={(e) => bulkForm.setData('category_id', e.target.value)}
                                options={[
                                    { label: 'بلا قسم', value: '' },
                                    ...categories.map((c) => ({ label: c.name, value: String(c.id) })),
                                ]}
                            />
                        </Field>
                        <div className="mt-5 flex justify-end gap-2">
                            <Button variant="outline" onClick={() => setBulk(null)}>
                                {t('إلغاء')}
                            </Button>
                            <Button
                                onClick={() =>
                                    runBulk('category', {
                                        category_id: bulkForm.data.category_id || null,
                                    })
                                }
                            >
                                {t('نقل :n منتجًا', { n: String(selected.length) })}
                            </Button>
                        </div>
                    </div>
                </DialogContent>
            </Dialog>

            {/* تغيير أسعار المحدَّد بنسبة */}
            <Dialog open={bulk === 'price'} onOpenChange={(o) => !o && setBulk(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{t('تغيير الأسعار بنسبة')}</DialogTitle>
                    </DialogHeader>
                    <div className="p-6 pt-0">
                        <Field label="النسبة % (سالبة للخفض)">
                            <Input
                                type="number"
                                dir="ltr"
                                step="0.1"
                                value={bulkForm.data.percent}
                                onChange={(e) => bulkForm.setData('percent', e.target.value)}
                            />
                        </Field>
                        {/* الرقم المطبعيّ يمسح تسعيرة متجر — فيُقرأ قبل الضغط */}
                        <p className="mt-3 rounded-[12px] bg-[#fffbeb] px-3 py-2.5 text-[12px] text-[#b45309]">
                            {t('تُطبَّق على :n منتجًا المحدَّدة، ولا تراجع عنها.', {
                                n: String(selected.length),
                            })}
                        </p>
                        <div className="mt-5 flex justify-end gap-2">
                            <Button variant="outline" onClick={() => setBulk(null)}>
                                {t('إلغاء')}
                            </Button>
                            <Button onClick={() => runBulk('price', { percent: Number(bulkForm.data.percent) })}>
                                {t('تطبيق')}
                            </Button>
                        </div>
                    </div>
                </DialogContent>
            </Dialog>

            {confirmDialog}
        </AdminLayout>
    );
}
