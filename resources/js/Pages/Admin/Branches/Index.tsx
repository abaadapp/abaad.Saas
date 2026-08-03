import { useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import { Check, GitBranch, Plus, Store } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import DataTable, { type Column } from '@/Components/DataTable';
import RowActions from '@/Components/RowActions';
import Field from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { Branch } from '@/types/models';

export default function BranchesIndex() {
    const { branches, errors } = usePage<PageProps<{ branches: Branch[] }>>().props;
    const t = useTranslate();
    // النموذج يبقى مفتوحًا إن عاد الخادم بأخطاء، وإلا ضاعت مدخلات المستخدم
    const [adding, setAdding] = useState(Object.keys(errors ?? {}).length > 0);

    const form = useForm({ name: '', phone: '', address: '' });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(route('admin.branches.store'), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setAdding(false);
            },
        });
    };

    const columns: Column<Branch>[] = [
        {
            key: 'name',
            header: 'الفرع',
            sortable: true,
            value: (b) => b.name,
            cell: (b) => (
                <div className="flex items-center gap-3">
                    <span className="flex size-9 shrink-0 items-center justify-center rounded-[10px] bg-[#f2f2f0] text-[#4b4b4b]">
                        <Store className="size-4" />
                    </span>
                    <span className="font-medium text-[#111]">{b.name}</span>
                </div>
            ),
        },
        {
            key: 'phone',
            header: 'الهاتف',
            cell: (b) => (b.phone ? <span dir="ltr" className="text-[#4b4b4b]">{b.phone}</span> : '—'),
        },
        { key: 'address', header: 'العنوان', cell: (b) => b.address || '—' },
        {
            key: 'actions',
            header: '',
            align: 'end',
            cell: (b) => (
                <RowActions destroy={{ url: route('admin.branches.destroy', b.id), message: 'حذف هذا الفرع؟' }} />
            ),
        },
    ];

    return (
        <AdminLayout title="الفروع">
            <PageHeader
                title="الفروع"
                subtitle={t('أضِف فروع نشاطك وأدرها من مكان واحد')}
                breadcrumbs={[{ label: 'الرئيسية', href: route('admin.dashboard') }, { label: 'الفروع' }]}
                actions={
                    <Button onClick={() => setAdding((v) => !v)}>
                        <Plus />
                        {t('إضافة فرع')}
                    </Button>
                }
            />

            <div className="space-y-6">
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <Card className="flex items-center gap-4 p-5">
                        <span className="flex size-11 shrink-0 items-center justify-center rounded-[12px] bg-[#111] text-white">
                            <GitBranch className="size-5" />
                        </span>
                        <div>
                            <p className="text-[22px] font-bold text-[#111]">{number(branches.length)}</p>
                            <p className="text-[12px] text-[#9ca3af]">{t('إجمالي الفروع')}</p>
                        </div>
                    </Card>
                </div>

                {adding && (
                    <Card className="p-6">
                        <div className="mb-5 flex items-center gap-2">
                            <span className="flex size-9 items-center justify-center rounded-[10px] bg-[#111] text-white">
                                <Plus className="size-5" />
                            </span>
                            <h3 className="text-[17px] font-bold text-[#111]">{t('إضافة فرع جديد')}</h3>
                        </div>

                        <form onSubmit={submit} className="grid grid-cols-1 items-end gap-4 sm:grid-cols-4">
                            <Field label="اسم الفرع" required error={form.errors.name}>
                                <Input
                                    value={form.data.name}
                                    onChange={(e) => form.setData('name', e.target.value)}
                                    placeholder={t('فرع صحار')}
                                    required
                                />
                            </Field>
                            <Field label="الهاتف" error={form.errors.phone}>
                                <Input
                                    dir="ltr"
                                    value={form.data.phone}
                                    onChange={(e) => form.setData('phone', e.target.value)}
                                    placeholder="+968 2xxxxxxx"
                                />
                            </Field>
                            <Field label="العنوان" error={form.errors.address}>
                                <Input
                                    value={form.data.address}
                                    onChange={(e) => form.setData('address', e.target.value)}
                                    placeholder={t('المدينة - الحي')}
                                />
                            </Field>
                            <Button type="submit" loading={form.processing}>
                                <Check />
                                {t('حفظ الفرع')}
                            </Button>
                        </form>
                    </Card>
                )}

                <Card className="overflow-hidden">
                    <div className="border-b border-[var(--ui-border,#e8e8e8)] px-5 pt-5">
                        <h3 className="text-[17px] font-bold text-[#111]">{t('قائمة الفروع')}</h3>
                    </div>
                    <DataTable
                        rows={branches}
                        columns={columns}
                        rowKey={(b) => b.id}
                        searchPlaceholder="ابحث بالاسم أو الهاتف أو العنوان…"
                        searchable={(b) => `${b.name} ${b.phone ?? ''} ${b.address ?? ''}`}
                        empty="لا توجد فروع — أضف أول فرع من زر «إضافة فرع» بالأعلى."
                    />
                </Card>
            </div>
        </AdminLayout>
    );
}
