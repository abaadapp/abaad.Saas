import { useEffect, useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import { Check, Plus, PlusCircle } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { PRODUCT_TABS } from '@/Components/SectionTabs';
import DataTable, { type Column, type Filter } from '@/Components/DataTable';
import RowActions from '@/Components/RowActions';
import EmojiPicker, { type EmojiGroups } from '@/Components/EmojiPicker';
import Field from '@/Components/Field';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { money, number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { Addon } from '@/types/models';

interface Props {
    addons: Addon[];
    emojiGroups: EmojiGroups;
}

const BLANK = { name: '', name_en: '', price: '0', icon: '🎁', active: true };

export default function AddonsIndex() {
    const { addons, emojiGroups, context } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const currency = context!.currency;
    const [adding, setAdding] = useState(false);
    const [editing, setEditing] = useState<Addon | null>(null);

    const form = useForm({ ...BLANK });

    // كل فتح جديد يبدأ من بيانات الصف المختار لا من بقايا الفتح السابق
    useEffect(() => {
        if (editing) {
            form.setData({
                name: editing.name,
                name_en: editing.name_en ?? '',
                price: String(editing.price),
                icon: editing.icon || '🎁',
                active: editing.active,
            });
        } else if (adding) {
            form.setData({ ...BLANK });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [editing, adding]);

    const close = () => {
        setAdding(false);
        setEditing(null);
        form.clearErrors();
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        const done = { preserveScroll: true, onSuccess: close };
        if (editing) form.put(route('admin.addons.update', editing.id), done);
        else form.post(route('admin.addons.store'), done);
    };

    const columns: Column<Addon>[] = [
        {
            key: 'name',
            header: 'العنصر',
            sortable: true,
            value: (a) => a.name,
            cell: (a) => (
                <div className="flex items-center gap-3">
                    <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-[#f5f3ff] text-lg leading-none">
                        {a.icon || '🎁'}
                    </span>
                    <span className="font-medium text-[#111]">{a.name}</span>
                </div>
            ),
        },
        {
            key: 'price',
            header: 'السعر',
            align: 'end',
            sortable: true,
            value: (a) => a.price,
            cell: (a) => <span className="tabular-nums font-medium">{money(a.price, currency)}</span>,
        },
        {
            key: 'active',
            header: 'الحالة',
            cell: (a) => (
                <Badge variant={a.active ? 'success' : 'neutral'}>{a.active ? t('مفعّل') : t('معطّل')}</Badge>
            ),
        },
        {
            key: 'actions',
            header: '',
            align: 'end',
            cell: (a) => (
                <RowActions
                    extra={[{ label: 'تعديل', onSelect: () => setEditing(a) }]}
                    destroy={{
                        url: route('admin.addons.destroy', a.id),
                        message: `حذف الإضافة «${a.name}»؟`,
                    }}
                />
            ),
        },
    ];

    const filters: Filter<Addon>[] = [
        {
            label: 'كل الحالات',
            options: [
                { label: 'مفعّل', value: 'active' },
                { label: 'معطّل', value: 'inactive' },
            ],
            match: (a, v) => (v === 'active' ? a.active : !a.active),
        },
    ];

    const open = adding || editing !== null;

    return (
        <AdminLayout title="الإضافات">
            <PageHeader
                title="الإضافات"
                subtitle={t('عناصر وخدمات تُضاف على المنتج مثل التغليف وبطاقة الإهداء')}
                breadcrumbs={[
                    { label: 'الرئيسية', href: route('admin.dashboard') },
                    { label: 'المنتجات', href: route('admin.products.index') },
                    { label: 'الإضافات' },
                ]}
                actions={
                    <Button onClick={() => setAdding(true)}>
                        <Plus />
                        {t('إضافة عنصر')}
                    </Button>
                }
            />

            <SectionTabs tabs={PRODUCT_TABS} current="admin.addons.index" />

            {addons.length === 0 ? (
                <Card className="px-5 py-16 text-center">
                    <PlusCircle className="mx-auto size-8 text-[#d1d5db]" />
                    <p className="mt-3 font-medium text-[#111]">{t('لا توجد إضافات')}</p>
                    <p className="mt-1 text-[13px] text-[#9ca3af]">
                        {t('أضِف أول عنصر يُضاف على المنتجات (مثل التغليف أو بطاقة الإهداء).')}
                    </p>
                    <Button className="mt-5" onClick={() => setAdding(true)}>
                        <Plus />
                        {t('إضافة عنصر')}
                    </Button>
                </Card>
            ) : (
                <Card className="overflow-hidden">
                    <DataTable
                        rows={addons}
                        columns={columns}
                        rowKey={(a) => a.id}
                        searchPlaceholder="ابحث باسم العنصر..."
                        searchable={(a) => `${a.name} ${a.name_en ?? ''}`}
                        filters={filters}
                        empty="لا توجد إضافات"
                        toolbar={
                            <span className="text-[12px] text-[#9ca3af]">
                                {number(addons.length)} {t('عنصر')}
                            </span>
                        }
                    />
                </Card>
            )}

            <Dialog open={open} onOpenChange={(v) => !v && close()}>
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{editing ? t('تعديل الإضافة') : t('إضافة عنصر')}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submit} className="space-y-4 px-5 pb-5">
                        <Field label="اسم العنصر" required error={form.errors.name}>
                            <Input
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                                placeholder={t('مثال: تغليف هدية')}
                                required
                            />
                        </Field>
                        <Field label="الاسم بالإنجليزية (اختياري)" error={form.errors.name_en}>
                            <Input
                                dir="ltr"
                                value={form.data.name_en}
                                onChange={(e) => form.setData('name_en', e.target.value)}
                                placeholder="e.g. Gift Wrap"
                            />
                        </Field>
                        <Field label={`${t('السعر')} (${currency.symbol ?? t('ر.ع')})`} error={form.errors.price}>
                            <Input
                                type="number"
                                step="0.001"
                                min="0"
                                dir="ltr"
                                value={form.data.price}
                                onChange={(e) => form.setData('price', e.target.value)}
                                placeholder="0.000"
                            />
                        </Field>

                        <EmojiPicker
                            value={form.data.icon}
                            onChange={(icon) => form.setData('icon', icon)}
                            groups={emojiGroups}
                        />

                        <label className="flex items-center gap-2 text-sm text-[#4b4b4b]">
                            <input
                                type="checkbox"
                                checked={form.data.active}
                                onChange={(e) => form.setData('active', e.target.checked)}
                                className="size-4 rounded border-[#d1d5db]"
                            />
                            {t('مفعّل')}
                        </label>

                        <div className="flex justify-end gap-2 pt-1">
                            <Button type="button" variant="outline" onClick={close}>
                                {t('إلغاء')}
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                <Check />
                                {form.processing ? '…' : t('حفظ')}
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>
        </AdminLayout>
    );
}
