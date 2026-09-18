import { useEffect, useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import { Check, GitBranch, Pencil, Plus, Store } from 'lucide-react';
import DataTable, { type Column } from '@/Components/DataTable';
import RowActions from '@/Components/RowActions';
import Field from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';
import type { Branch } from '@/types/models';

/**
 * جسم قسم «الفروع» بلا قشرة.
 *
 * يعيش في موضعين: داخل لوحة الإعدادات حيث يُفتح مكانها، وفي صفحته المستقلّة
 * التي تُبقي الرابط المباشر يعمل. ولذلك لا يعرف شيئًا عن AdminLayout ولا عن
 * ترويسة الصفحة — يأخذ بياناته من الخارج ويرسم نفسه، فلا تتباعد النسختان.
 */
export default function BranchesPanel({ branches }: { branches: Branch[] }) {
    const { errors } = usePage<PageProps>().props;
    const t = useTranslate();
    // النموذج يبقى مفتوحًا إن عاد الخادم بأخطاء، وإلا ضاعت مدخلات المستخدم
    const [adding, setAdding] = useState(Object.keys(errors ?? {}).length > 0);

    const form = useForm({ name: '', phone: '', address: '' });

    const [editing, setEditing] = useState<Branch | null>(null);
    const edit = useForm({ name: '', phone: '', address: '' });

    // النافذة تُملأ عند تبديل الفرع لا عند كل رسمة، وإلا مُحيت كتابةُ المستخدم
    useEffect(() => {
        if (editing) {
            edit.setData({
                name: editing.name,
                phone: editing.phone ?? '',
                address: editing.address ?? '',
            });
            edit.clearErrors();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [editing?.id]);

    const saveEdit = (e: React.FormEvent) => {
        e.preventDefault();
        if (!editing) return;
        edit.put(route('admin.branches.update', editing.id), {
            preserveScroll: true,
            onSuccess: () => setEditing(null),
        });
    };

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
            /*
             * التأكيد يقرأ محتوى الفرع قبل أن يسأل.
             *
             * كان يقول «حذف هذا الفرع؟» لا غير — فيضغط التاجر «نعم» على فرعٍ
             * فيه أربعمئة فاتورة وصندوقان، ولا يعلم إلا حين يبحث عن مبيعاته.
             * والرقم أمام عينه يوقف الضغطة، وهو أنفع من سلّةٍ تُصلحها بعدها.
             */
            cell: (b) => (
                <RowActions
                    extra={[
                        { label: 'تعديل', icon: <Pencil className="size-4" />, onSelect: () => setEditing(b) },
                    ]}
                    destroy={{
                        url: route('admin.branches.destroy', b.id),
                        /*
                         * الأعداد بصيغة «تسمية: رقم» لا داخل جملة.
                         *
                         * «:orders فاتورة» تنكسر مع كل عدد: العربية تقول
                         * «فاتورة» للواحدة و«فواتير» للثلاث، والإنجليزية
                         * تقول «1 registers». والتسمية المفصولة صحيحة مع
                         * أي رقم وفي اللغتين.
                         */
                        message:
                            b.orders || b.devices
                                ? t('في هذا الفرع — الفواتير: :orders · الصناديق: :devices. حذفه يُخفيه من كل الشاشات، ويمكن استرجاعه من «المحذوفات». متابعة؟', {
                                      orders: number(b.orders ?? 0),
                                      devices: number(b.devices ?? 0),
                                  })
                                : 'حذف هذا الفرع؟',
                    }}
                />
            ),
        },
    ];

    return (
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
                {/* زرّ الإضافة هنا لا في ترويسة الصفحة: القسم يُفتح داخل
                    الإعدادات حيث لا ترويسة له، فيلزم أن يحمل أدواته معه */}
                <div className="flex items-center justify-between border-b border-[var(--ui-border,#e8e8e8)] px-5 pt-5 pb-4">
                    <h3 className="text-[17px] font-bold text-[#111]">{t('قائمة الفروع')}</h3>
                    <Button onClick={() => setAdding((v) => !v)}>
                        <Plus />
                        {t('إضافة فرع')}
                    </Button>
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

            {/*
                نافذةُ التعديل — بشكل «تعديل الجهاز»: حقولٌ في شبكة والأزرار
                في ذيل النموذج.

                والاسمُ يُصحَّح هنا لا بالحذف وإعادة الفتح: ذاك لا يمرّ إن كان
                في الفرع بضاعة، ولا إن كان آخرَ فرع، ولا إن كانت الباقةُ
                بفرعٍ واحد.
            */}
            <Dialog open={!!editing} onOpenChange={(o) => !o && setEditing(null)}>
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{t('تعديل الفرع')}</DialogTitle>
                    </DialogHeader>

                    <form onSubmit={saveEdit} className="space-y-4 px-5 pb-5">
                        <Field
                            label="اسم الفرع"
                            required
                            hint="الفواتير الصادرة تحتفظ بالاسم الذي طُبع عليها"
                            error={edit.errors.name}
                        >
                            <Input
                                value={edit.data.name}
                                onChange={(e) => edit.setData('name', e.target.value)}
                                required
                            />
                        </Field>

                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <Field label="الهاتف" error={edit.errors.phone}>
                                <Input
                                    dir="ltr"
                                    value={edit.data.phone}
                                    onChange={(e) => edit.setData('phone', e.target.value)}
                                    placeholder="+968 2xxxxxxx"
                                />
                            </Field>
                            <Field label="العنوان" error={edit.errors.address}>
                                <Input
                                    value={edit.data.address}
                                    onChange={(e) => edit.setData('address', e.target.value)}
                                    placeholder={t('المدينة - الحي')}
                                />
                            </Field>
                        </div>

                        <div className="flex justify-end gap-2 pt-1">
                            <Button type="button" variant="outline" onClick={() => setEditing(null)}>
                                {t('إلغاء')}
                            </Button>
                            <Button type="submit" loading={edit.processing}>
                                {t('حفظ التغييرات')}
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>
        </div>
    );
}
