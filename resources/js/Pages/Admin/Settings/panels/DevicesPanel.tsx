import { useEffect, useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import { MonitorSmartphone, Pencil, Plug } from 'lucide-react';
import DataTable, { type Column } from '@/Components/DataTable';
import RowActions from '@/Components/RowActions';
import Field, { Select } from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import PeripheralsDialog, { type Peripheral } from '../../Devices/partials/PeripheralsDialog';
import type { PageProps } from '@/types';

export interface Device {
    id: number;
    name: string;
    branch: string;
    branchId: number;
    status: string;
    lastSeen: string;
    activatedAt: string;
    activatedBy: string;
    isThis: boolean;
    peripherals: Peripheral[];
}

export interface DevicesData {
    devices: Device[];
    branches: { value: number; label: string }[];
    peripheralTypes: string[];
    drivableTypes: string[];
    paperWidths: number[];
}

/**
 * جسم قسم «أجهزة نقاط البيع» بلا قشرة — في لوحة الإعدادات وفي صفحته المستقلّة.
 *
 * ولا يُعرض رمز الجهاز هنا ولا في أي مكان: يُولَّد مرّةً، يوضع في كوكي الجهاز،
 * ولا يُخزَّن إلا مجزَّأً. ما لا يُخزَّن لا يُسرَّب.
 */
export default function DevicesPanel({ devices, branches, peripheralTypes, drivableTypes, paperWidths }: DevicesData) {
    const { errors } = usePage<PageProps>().props;
    const t = useTranslate();
    const [editing, setEditing] = useState<Device | null>(null);
    const [linking, setLinking] = useState<number | null>(null);
    const form = useForm({ name: '', branch_id: '' });

    // Radix Select لا يقبل قيمةً تتغيّر أثناء الفتح، فتُملأ عند تبديل الجهاز
    useEffect(() => {
        if (editing) {
            form.setData({ name: editing.name, branch_id: String(editing.branchId) });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [editing?.id]);

    const save = (e: React.FormEvent) => {
        e.preventDefault();
        if (!editing) return;
        form.put(route('admin.devices.update', editing.id), {
            preserveScroll: true,
            onSuccess: () => setEditing(null),
        });
    };

    const columns: Column<Device>[] = [
        {
            key: 'name',
            header: 'الجهاز',
            sortable: true,
            value: (d) => d.name,
            cell: (d) => (
                <div className="flex items-center gap-3">
                    <span className="flex size-9 shrink-0 items-center justify-center rounded-[10px] bg-[#f2f2f0] text-[#4b4b4b]">
                        <MonitorSmartphone className="size-4" />
                    </span>
                    <span className="font-medium text-[#111]">
                        {d.name}
                        {d.isThis && (
                            <span className="ms-2 rounded-full bg-[#eef2ff] px-2 py-0.5 text-[11px] text-[#4338ca]">
                                {t('هذا الجهاز')}
                            </span>
                        )}
                    </span>
                </div>
            ),
        },
        { key: 'branch', header: 'الفرع', sortable: true, value: (d) => d.branch, cell: (d) => d.branch },
        {
            key: 'status',
            header: 'الحالة',
            cell: (d) => (
                <span
                    className={
                        d.status === 'نشط'
                            ? 'rounded-full bg-[#ecfdf5] px-2.5 py-1 text-[12px] text-[#047857]'
                            : 'rounded-full bg-[#fef2f2] px-2.5 py-1 text-[12px] text-[#b91c1c]'
                    }
                >
                    {t(d.status)}
                </span>
            ),
        },
        {
            key: 'peripherals',
            header: 'الملحقات',
            cell: (d) => {
                const on = d.peripherals.filter((p) => p.active).length;
                return (
                    <button
                        type="button"
                        onClick={() => setLinking(d.id)}
                        className="flex items-center gap-1.5 rounded-[8px] px-2 py-1 text-[13px] text-[#4b4b4b] transition-colors hover:bg-[#f2f2f0]"
                    >
                        <Plug className="size-4 text-[#9ca3af]" />
                        {/* الرقم بلا صيغة جمع: «١ جهاز» و«٣ أجهزة» لا تُبنى من
                            قالبٍ واحد بالعربية، والصفر يقول «أضف» لا «٠» */}
                        {on > 0 ? on : t('إضافة')}
                    </button>
                );
            },
        },
        { key: 'lastSeen', header: 'آخر اتصال', cell: (d) => d.lastSeen },
        { key: 'activatedAt', header: 'تاريخ التفعيل', cell: (d) => d.activatedAt },
        { key: 'activatedBy', header: 'فعّله', cell: (d) => d.activatedBy },
        {
            key: 'actions',
            header: '',
            align: 'end',
            cell: (d) => (
                <RowActions
                    extra={[
                        { label: 'تعديل', icon: <Pencil className="size-4" />, onSelect: () => setEditing(d) },
                        {
                            label: 'الأجهزة الملحقة',
                            icon: <Plug className="size-4" />,
                            onSelect: () => setLinking(d.id),
                        },
                    ]}
                    destroy={
                        d.status === 'نشط'
                            ? {
                                  url: route('admin.devices.revoke', d.id),
                                  label: 'إلغاء التفعيل',
                                  message: d.isThis
                                      ? 'هذا هو الجهاز الذي تستخدمه الآن — إلغاؤه يطلب تفعيله من جديد. متابعة؟'
                                      : 'إلغاء تفعيل هذا الجهاز؟ لن يقبل رمز أي موظف بعدها.',
                              }
                            : undefined
                    }
                />
            ),
        },
    ];

    return (
        <div className="min-w-0">
            <Card className="p-0">
                <DataTable
                    columns={columns}
                    rows={devices}
                    rowKey={(d) => d.id}
                    empty={t('لا أجهزة مفعَّلة بعد. افتح نقطة البيع على الجهاز وفعّله من هناك.')}
                />
            </Card>

            <Dialog open={!!editing} onOpenChange={(o) => !o && setEditing(null)}>
                {/* بشكل نافذة «إنشاء كوبون خصم»: حقلان في صفّ، والأزرار في ذيل النموذج */}
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{t('تعديل الجهاز')}</DialogTitle>
                    </DialogHeader>

                    <form onSubmit={save} className="space-y-4 px-5 pb-5">
                        <div className="grid grid-cols-2 gap-3">
                            <Field label="اسم الجهاز" required error={errors.name}>
                                <Input
                                    value={form.data.name}
                                    onChange={(e) => form.setData('name', e.target.value)}
                                    required
                                />
                            </Field>

                            {/*
                                نقل الجهاز إلى فرعٍ آخر يُبطل تفعيله عمدًا: الجهاز
                                يُنقل حين يُنقل فعلًا، وإبقاء رمزه صالحًا يعني أن
                                من كان يملكه يواصل البيع على الفرع الجديد من مكانه.
                            */}
                            <Field
                                label="الفرع"
                                required
                                hint="تغيير الفرع يُبطل التفعيل — يُعاد من الجهاز نفسه"
                                error={errors.branch_id}
                            >
                                <Select
                                    value={form.data.branch_id}
                                    onChange={(e) => form.setData('branch_id', e.target.value)}
                                    options={branches}
                                    placeholder="اختر الفرع…"
                                />
                            </Field>
                        </div>

                        <div className="flex justify-end gap-2 pt-1">
                            <Button type="button" variant="outline" onClick={() => setEditing(null)}>
                                {t('إلغاء')}
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                {t('حفظ التغييرات')}
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>

            {/* الملحقات تُقرأ من devices مباشرةً لا من نسخةٍ في الحالة: بعد
                الإضافة يعيد Inertia الصفحة، فلو حُفظ الجهاز في useState بقيت
                القائمة على ما كانت وبدا أن الإضافة لم تقع */}
            {linking !== null &&
                (() => {
                    const d = devices.find((x) => x.id === linking);
                    return d ? (
                        <PeripheralsDialog
                            deviceId={d.id}
                            deviceName={d.name}
                            peripherals={d.peripherals}
                            types={peripheralTypes}
                            drivableTypes={drivableTypes}
                            paperWidths={paperWidths}
                            onClose={() => setLinking(null)}
                        />
                    ) : null;
                })()}
        </div>
    );
}
