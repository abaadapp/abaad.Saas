import { useMemo, useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import { ArrowLeftRight, Check, Plus } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { INVENTORY_TABS } from '@/Components/SectionTabs';
import DataTable, { type Column, type ServerPagination } from '@/Components/DataTable';
import Field, { Select } from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface Transfer {
    id: number;
    number: string;
    product: string;
    sku: string | null;
    from: string;
    to: string;
    quantity: number;
    notes: string | null;
    author: string | null;
    at: string | null;
}

interface ProductOption {
    value: number;
    label: string;
    quantity: number;
    /** رصيد كل فرع من هذا الصنف — المفتاح معرّف الفرع */
    byBranch: Record<string, number>;
}

interface Props {
    transfers: Transfer[];
    pagination: ServerPagination;
    filters: Record<string, string | null | undefined>;
    /** أعمدة يرتّبها الخادم — مصدرها `Sort::keys` في المتحكّم */
    sorts: string[];
    products: ProductOption[];
    branches: { id: number; name: string }[];
    /** الفرع المختار في الشريط العلوي — قيمةٌ ابتدائية، وnull في وضع «كل الفروع» */
    currentBranchId: number | null;
    today: string;
}

/**
 * سندات النقل بين الفروع — البابُ الذي لم يكن.
 *
 * كان طريقُ التاجر حركتين يدويّتين لا شيء يربطهما: صرفٌ من فرع وإضافةٌ في
 * آخر. فإن نسي الثانية نقص مخزونُه بلا سبب، ولا يُكتشف الفرق إلّا في جردٍ
 * آخر السنة حين يكون سببُه قد نُسي.
 */
export default function Transfers() {
    const { transfers, pagination, filters, sorts, products, branches, currentBranchId, today } =
        usePage<PageProps<Props>>().props;
    const t = useTranslate();

    const [adding, setAdding] = useState(false);

    const form = useForm({
        from_branch_id: currentBranchId ? String(currentBranchId) : '',
        to_branch_id: '',
        product_id: '',
        quantity: '',
        notes: '',
        transferred_at: today,
    });

    const product = useMemo(
        () => products.find((p) => String(p.value) === form.data.product_id),
        [products, form.data.product_id],
    );

    /*
     * الرصيد يُعرض قبل الكتابة لا بعد الرفض.
     *
     * من يرى «في مسقط ٣» لا يكتب ٥ — والحارس في الخادم على كلّ حال، لكنّ
     * رفضًا بعد ملء النموذج كلّه يُعاد ملؤه.
     */
    const available = product && form.data.from_branch_id
        ? (product.byBranch[form.data.from_branch_id] ?? 0)
        : null;

    const qty = Math.abs(parseInt(form.data.quantity, 10) || 0);
    const fractional = /[.,]/.test(form.data.quantity);
    const tooMuch = available !== null && qty > available;
    const sameBranch =
        !! form.data.from_branch_id && form.data.from_branch_id === form.data.to_branch_id;

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(route('admin.inventory.transfers.store'), {
            preserveScroll: true,
            onSuccess: () => {
                setAdding(false);
                // الفرعان يبقيان: من ينقل صنفًا بين فرعين ينقل الثاني بينهما غالبًا
                form.reset('product_id', 'quantity', 'notes');
            },
        });
    };

    const columns: Column<Transfer>[] = [
        {
            key: 'number',
            header: 'المرجع',
            cell: (x) => (
                <>
                    <span className="font-mono text-[12px] text-[#4b4b4b]">{x.number}</span>
                    <span className="block text-[12px] text-[#9ca3af]" dir="ltr">
                        {x.at}
                    </span>
                </>
            ),
        },
        {
            key: 'product',
            header: 'المنتج',
            cell: (x) => (
                <>
                    <span className="font-medium text-[#111]">{x.product}</span>
                    {x.sku && <span className="block text-[12px] text-[#9ca3af]">{x.sku}</span>}
                </>
            ),
        },
        {
            key: 'route',
            header: 'من ← إلى',
            cell: (x) => (
                <span className="flex items-center gap-2 text-[#4b4b4b]">
                    <span>{x.from}</span>
                    <ArrowLeftRight className="size-3.5 shrink-0 text-[#9ca3af]" />
                    <span className="font-medium text-[#111]">{x.to}</span>
                </span>
            ),
        },
        {
            key: 'quantity',
            header: 'الكمية',
            align: 'end',
            cell: (x) => <span className="font-semibold tabular-nums text-[#111]">{number(x.quantity)}</span>,
        },
        { key: 'author', header: 'بواسطة', cell: (x) => x.author ?? '—' },
    ];

    // فرعٌ واحد لا يُنقل منه إلى أحد — والزرّ يقول لماذا بدل أن يقف بلا وظيفة
    const canTransfer = branches.length >= 2 && products.length > 0;

    return (
        <AdminLayout title="النقل بين الفروع">
            <PageHeader
                title="النقل بين الفروع"
                subtitle={t('سندٌ واحد يربط الصرف من فرعٍ بالإضافة في آخر — والإجمالي لا يتغيّر')}
                actions={
                    <Button onClick={() => setAdding(true)} disabled={! canTransfer}>
                        <Plus />
                        {t('سند نقل جديد')}
                    </Button>
                }
            />

            <SectionTabs tabs={INVENTORY_TABS} current="admin.inventory.transfers" />

            {branches.length < 2 && (
                <Card className="mb-6 p-5 text-sm text-[#6b7280]">
                    {t('النقل يحتاج فرعين — أضِف فرعًا ثانيًا من الإعدادات ← الفروع.')}
                </Card>
            )}

            <Card className="overflow-hidden">
                <DataTable
                    rows={transfers}
                    columns={columns}
                    rowKey={(x) => x.id}
                    searchPlaceholder="ابحث بالمرجع أو المنتج أو الفرع…"
                    searchable={() => ''}
                    empty="لا سندات نقل بعد"
                    server={{ pagination, params: filters, sorts }}
                />
            </Card>

            {/* السند لا يُحذف: حركتاه في سجلّ المخزون، والتراجع عنه نقلٌ مقابل */}
            <p className="mt-3 text-[12px] text-[#9ca3af]">
                {t('السند لا يُحذف بعد تسجيله — له حركتان في سجلّ المخزون، والتراجع عنه يكون بنقلٍ مقابل لا بمحوه.')}
            </p>

            <Dialog open={adding} onOpenChange={setAdding}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>{t('سند نقل بين فرعين')}</DialogTitle>
                    </DialogHeader>

                    <form onSubmit={submit} className="space-y-4 px-5 pb-5">
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <Field label="من فرع" required error={form.errors.from_branch_id}>
                                <Select
                                    placeholder="اختر الفرع…"
                                    value={form.data.from_branch_id}
                                    onChange={(e) => form.setData('from_branch_id', e.target.value)}
                                    options={branches.map((b) => ({ label: b.name, value: b.id }))}
                                    required
                                />
                            </Field>
                            <Field label="إلى فرع" required error={form.errors.to_branch_id}>
                                <Select
                                    placeholder="اختر الفرع…"
                                    value={form.data.to_branch_id}
                                    onChange={(e) => form.setData('to_branch_id', e.target.value)}
                                    options={branches.map((b) => ({ label: b.name, value: b.id }))}
                                    required
                                />
                            </Field>
                        </div>

                        {sameBranch && (
                            <p className="text-[12px] text-[#b91c1c]">
                                {t('لا يُنقل الصنف من الفرع إلى نفسه.')}
                            </p>
                        )}

                        <Field label="المنتج" required error={form.errors.product_id}>
                            <Select
                                placeholder="اختر المنتج"
                                value={form.data.product_id}
                                onChange={(e) => form.setData('product_id', e.target.value)}
                                options={products}
                            />
                        </Field>

                        {available !== null && (
                            <p className="text-[12px] text-[#9ca3af]">
                                {t('رصيد الفرع المُرسِل')}: {number(available)}
                            </p>
                        )}

                        <Field label="الكمية" required error={form.errors.quantity}>
                            <Input
                                type="number"
                                step="1"
                                min="1"
                                dir="ltr"
                                value={form.data.quantity}
                                onChange={(e) => form.setData('quantity', e.target.value)}
                            />
                        </Field>

                        {fractional && (
                            <p className="text-[12px] text-[#b91c1c]">{t('الكمية أعدادٌ صحيحة — لا كسور')}</p>
                        )}

                        {tooMuch && (
                            <p className="text-[12px] text-[#b91c1c]">
                                {t('رصيد الفرع المُرسِل :n فقط', { n: number(available ?? 0) })}
                            </p>
                        )}

                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <Field label="التاريخ" required error={form.errors.transferred_at}>
                                <Input
                                    type="date"
                                    dir="ltr"
                                    value={form.data.transferred_at}
                                    onChange={(e) => form.setData('transferred_at', e.target.value)}
                                />
                            </Field>
                            <Field label="ملاحظات" error={form.errors.notes}>
                                <Input
                                    value={form.data.notes}
                                    onChange={(e) => form.setData('notes', e.target.value)}
                                    placeholder={t('سبب النقل')}
                                />
                            </Field>
                        </div>

                        <div className="flex justify-end gap-2 pt-1">
                            <Button type="button" variant="ghost" onClick={() => setAdding(false)}>
                                {t('إلغاء')}
                            </Button>
                            <Button
                                type="submit"
                                loading={form.processing}
                                disabled={tooMuch || fractional || sameBranch || qty < 1}
                            >
                                <Check />
                                {t('حفظ السند')}
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>
        </AdminLayout>
    );
}
