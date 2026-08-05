import { useMemo, useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import { Check, Plus, Trash2 } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SmartLink from '@/Components/SmartLink';
import Field, { Select } from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input, Textarea } from '@/Components/ui/input';
import { currencyLabel, money } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { Branch, Product, Supplier } from '@/types/models';

interface ReorderRow {
    name: string;
    sku: string;
    suggested: number;
    cost: number;
}

interface Line {
    product_id: string;
    name: string;
    cost: string;
    qty: string;
}

interface Props {
    suppliers: Supplier[];
    products: Product[];
    reorderSuggestions: ReorderRow[];
    branches: Branch[];
    currentBranchId: number | null;
    fromReorder: boolean;
}

const blankLine = (): Line => ({ product_id: '', name: '', cost: '', qty: '1' });

export default function PurchaseCreate() {
    const { suppliers, products, reorderSuggestions, branches, currentBranchId, fromReorder, context } =
        usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const currency = context!.currency;

    // القدوم من "إعادة الطلب" يملأ الأصناف المقترحة مسبقًا
    const [lines, setLines] = useState<Line[]>(() =>
        fromReorder && reorderSuggestions.length
            ? reorderSuggestions.map((r) => {
                  const p = products.find((x) => x.sku === r.sku);

                  return {
                      product_id: p ? String(p.id) : '',
                      name: r.name,
                      cost: String(r.cost),
                      qty: String(r.suggested),
                  };
              })
            : [blankLine()],
    );

    const form = useForm<{ branch_id: string; supplier_id: string; notes: string; receipt: File | null }>({
        branch_id: currentBranchId ? String(currentBranchId) : '',
        supplier_id: '',
        notes: '',
        receipt: null,
    });

    const total = useMemo(
        () => lines.reduce((s, l) => s + (Number(l.cost) || 0) * (Number(l.qty) || 0), 0),
        [lines],
    );

    const setLine = (i: number, patch: Partial<Line>) =>
        setLines((prev) => prev.map((l, x) => (x === i ? { ...l, ...patch } : l)));

    /** اختيار منتج يملأ اسمه وتكلفته تلقائيًا؛ "صنف يدوي" يترك الاسم للكتابة */
    const pickProduct = (i: number, id: string) => {
        const p = products.find((x) => String(x.id) === id);
        setLine(i, { product_id: id, name: p ? p.name : '', cost: p ? String(p.cost) : lines[i].cost });
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        // transform يُعيد void في هذا الإصدار، فيُستدعى قبل post لا مسلسلًا معه
        form.transform((data) => ({
            ...data,
            items: lines
                .filter((l) => (Number(l.qty) || 0) > 0 && (l.product_id || l.name.trim()))
                .map((l) => ({
                    product_id: l.product_id || null,
                    name: l.name,
                    cost: Number(l.cost) || 0,
                    quantity: Number(l.qty) || 0,
                })),
        }));
        form.post(route('admin.purchases.store'), { forceFormData: true });
    };

    return (
        <AdminLayout title="أمر شراء جديد">
            <PageHeader
                title="أمر شراء جديد"
                subtitle={t('حدّد المورّد والأصناف المطلوبة')}
                breadcrumbs={[
                    { label: 'الرئيسية', href: route('admin.dashboard') },
                    { label: 'أوامر الشراء', href: route('admin.purchases.index') },
                    { label: 'جديد' },
                ]}
            />

            <form onSubmit={submit} className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <Card className="p-6 lg:col-span-2">
                    <h3 className="mb-4 font-bold text-[#111]">{t('الأصناف')}</h3>

                    <div className="space-y-3">
                        {lines.map((l, i) => (
                            <div key={i} className="grid grid-cols-1 items-end gap-3 sm:grid-cols-12">
                                <Field label={i === 0 ? 'المنتج' : undefined} className="sm:col-span-4">
                                    <Select
                                        value={l.product_id}
                                        onChange={(e) => pickProduct(i, e.target.value)}
                                        options={products.map((p) => ({ label: p.name, value: p.id }))}
                                        placeholder="— صنف يدوي —"
                                    />
                                </Field>
                                <Field label={i === 0 ? 'اسم الصنف' : undefined} className="sm:col-span-3">
                                    <Input
                                        value={l.name}
                                        onChange={(e) => setLine(i, { name: e.target.value })}
                                        placeholder={t('اسم الصنف')}
                                    />
                                </Field>
                                <Field
                                    label={i === 0 ? `${t('تكلفة الوحدة')} (${currencyLabel(currency)})` : undefined}
                                    className="sm:col-span-2"
                                >
                                    <Input
                                        type="number"
                                        step="0.001"
                                        min="0"
                                        dir="ltr"
                                        value={l.cost}
                                        onChange={(e) => setLine(i, { cost: e.target.value })}
                                        placeholder="0.000"
                                    />
                                </Field>
                                <Field label={i === 0 ? 'الكمية' : undefined} className="sm:col-span-2">
                                    <Input
                                        type="number"
                                        min="1"
                                        dir="ltr"
                                        value={l.qty}
                                        onChange={(e) => setLine(i, { qty: e.target.value })}
                                    />
                                </Field>
                                <div className="sm:col-span-1">
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        aria-label={t('حذف الصنف')}
                                        className="text-[#b91c1c]"
                                        disabled={lines.length === 1}
                                        onClick={() => setLines((prev) => prev.filter((_, x) => x !== i))}
                                    >
                                        <Trash2 />
                                    </Button>
                                </div>
                            </div>
                        ))}
                    </div>

                    <Button
                        type="button"
                        variant="outline"
                        className="mt-4"
                        onClick={() => setLines((prev) => [...prev, blankLine()])}
                    >
                        <Plus />
                        {t('إضافة صنف')}
                    </Button>
                </Card>

                <div className="space-y-6">
                    <Card className="space-y-4 p-6">
                        <h3 className="font-bold text-[#111]">{t('تفاصيل الأمر')}</h3>

                        <Field label="الفرع" required error={form.errors.branch_id}>
                            <Select
                                value={form.data.branch_id}
                                onChange={(e) => form.setData('branch_id', e.target.value)}
                                options={branches.map((b) => ({ label: b.name, value: b.id }))}
                                placeholder="اختر الفرع…"
                                required
                            />
                        </Field>

                        <Field label="المورّد" error={form.errors.supplier_id}>
                            <Select
                                value={form.data.supplier_id}
                                onChange={(e) => form.setData('supplier_id', e.target.value)}
                                options={suppliers.map((s) => ({ label: s.name, value: s.id }))}
                                placeholder="— بدون مورّد —"
                            />
                        </Field>

                        <Field label="ملاحظات" error={form.errors.notes}>
                            <Textarea
                                rows={3}
                                value={form.data.notes}
                                onChange={(e) => form.setData('notes', e.target.value)}
                            />
                        </Field>

                        <Field
                            label="إيصال الدفع"
                            hint="JPG · PNG · PDF · WEBP · HEIC — حتى 10 ميجابايت"
                            error={form.errors.receipt}
                        >
                            <Input
                                type="file"
                                accept=".jpg,.jpeg,.png,.pdf,.webp,.heic"
                                onChange={(e) => form.setData('receipt', e.target.files?.[0] ?? null)}
                                className="h-auto py-2 file:me-3 file:rounded-lg file:bg-[#111] file:px-4 file:py-2 file:text-white"
                            />
                        </Field>
                    </Card>

                    <Card className="p-6">
                        <div className="flex items-center justify-between">
                            <span className="font-bold text-[#111]">{t('الإجمالي')}</span>
                            <span className="text-[20px] font-bold tabular-nums text-[#6d28d9]">
                                {money(total, currency)}
                            </span>
                        </div>
                        <Button type="submit" className="mt-5 w-full" loading={form.processing}>
                            <Check />
                            {t('إنشاء أمر الشراء')}
                        </Button>
                        <Button variant="outline" className="mt-3 w-full" asChild>
                            <SmartLink routeName="admin.purchases.index" href={route('admin.purchases.index')}>
                                {t('إلغاء')}
                            </SmartLink>
                        </Button>
                    </Card>
                </div>
            </form>
        </AdminLayout>
    );
}
