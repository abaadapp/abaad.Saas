import { useForm, usePage } from '@inertiajs/react';
import { ArrowLeftRight } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { INVENTORY_TABS } from '@/Components/SectionTabs';
import Field, { Select } from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { Branch, InventoryItem } from '@/types/models';

interface Props {
    items: InventoryItem[];
    branches: Branch[];
    /** رصيد كل فرعٍ لكل منتج — [معرّف المنتج][معرّف الفرع] */
    books: Record<number, Record<number, number>>;
}

/**
 * نقل بضاعةٍ من فرعٍ إلى فرع — حركةٌ واحدة لا حركتان.
 *
 * كان السبيل الوحيد «صرف» من فرعٍ ثم «إضافة» في آخر: من نسي الثانية اختفت
 * بضاعته من النظام، ومن أتمّهما قرأ السجلُّ فعلَه تلفًا ثم مكسبًا — فتظهر في
 * التقارير خسارةٌ ثم ربحٌ لم يقعا.
 */
export default function Transfer() {
    const { items, branches, books } = usePage<PageProps<Props>>().props;
    const t = useTranslate();

    const form = useForm({
        from_branch_id: '',
        to_branch_id: '',
        product_id: '',
        quantity: '',
    });

    /** رصيد الصنف في الفرع المُرسِل — الرقم الذي يحدّ ما يمكن تحويله */
    const available =
        form.data.product_id && form.data.from_branch_id
            ? (books[Number(form.data.product_id)]?.[Number(form.data.from_branch_id)] ?? 0)
            : null;

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(route('admin.inventory.transfer.apply'), {
            preserveScroll: true,
            onSuccess: () => form.setData('quantity', ''),
        });
    };

    return (
        <AdminLayout title="التحويل بين الفروع">
            <PageHeader
                title="التحويل بين الفروع"
                subtitle={t('انقل كمية من فرع إلى آخر — لا يتغيّر إجمالي المخزون، ينتقل موضعه')}
                breadcrumbs={[
                    { label: 'الرئيسية', href: route('admin.dashboard') },
                    { label: 'المخزون', href: route('admin.inventory.index') },
                    { label: 'التحويل بين الفروع' },
                ]}
            />

            <SectionTabs tabs={INVENTORY_TABS} current="admin.inventory.transfer" />

            <form onSubmit={submit}>
                <Card className="max-w-3xl p-6">
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field label="من فرع" required error={form.errors.from_branch_id}>
                            <Select
                                value={form.data.from_branch_id}
                                onChange={(e) => form.setData('from_branch_id', e.target.value)}
                                options={branches.map((b) => ({ label: b.name, value: b.id }))}
                                placeholder="اختر الفرع…"
                                required
                            />
                        </Field>

                        <Field label="إلى فرع" required error={form.errors.to_branch_id}>
                            <Select
                                value={form.data.to_branch_id}
                                onChange={(e) => form.setData('to_branch_id', e.target.value)}
                                /* الفرع المُرسِل لا يظهر في قائمة المستقبِل: خيارٌ
                                   لا يُقبل خيرٌ ألّا يُعرض */
                                options={branches
                                    .filter((b) => String(b.id) !== form.data.from_branch_id)
                                    .map((b) => ({ label: b.name, value: b.id }))}
                                placeholder="اختر الفرع…"
                                required
                            />
                        </Field>

                        <Field label="الصنف" required error={form.errors.product_id}>
                            <Select
                                value={form.data.product_id}
                                onChange={(e) => form.setData('product_id', e.target.value)}
                                options={items.map((i) => ({ label: i.name, value: i.id }))}
                                placeholder="اختر الصنف…"
                                required
                            />
                        </Field>

                        <Field
                            label="الكمية"
                            required
                            error={form.errors.quantity}
                            /* الرصيد المتاح تحت الحقل: بلا رقمٍ أمامه يكتب المستخدم
                               ما يظنّ ثم يُردّ برسالة خطأ */
                            hint={
                                available !== null
                                    ? t('المتاح في الفرع المُرسِل: :n', { n: available })
                                    : 'اختر الصنف والفرع لعرض المتاح'
                            }
                        >
                            <Input
                                inputMode="numeric"
                                dir="ltr"
                                value={form.data.quantity}
                                onChange={(e) => form.setData('quantity', e.target.value)}
                                required
                            />
                        </Field>
                    </div>

                    <div className="mt-6">
                        <Button type="submit" loading={form.processing}>
                            <ArrowLeftRight />
                            {t('تنفيذ التحويل')}
                        </Button>
                    </div>
                </Card>
            </form>
        </AdminLayout>
    );
}
