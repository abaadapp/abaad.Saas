import { useEffect, useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import { CircleHelp, MonitorSmartphone, Pencil, Plug, TriangleAlert } from 'lucide-react';
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

/**
 * ما يمنع إيصالَ صندوقٍ من الخروج، أو يحتاج مراجعة — يقيسه الخادم (`TillHealth`).
 *
 * `confirmed` يُعرض تحذيرًا. و`suspected` يُعرض «للمراجعة» بلونٍ هادئ: نمطٌ
 * قد يكون مقصودًا، فلا يُقال للتاجر إنّه عطل.
 */
export interface TillIssue {
    code: string;
    level: 'confirmed' | 'suspected';
    peripheral: number | null;
    message: string;
}

export interface Device {
    id: number;
    name: string;
    branch: string;
    branchId: number;
    /** بنكُ جهاز الشبكة — فارغٌ يعني الحساب الرئيسيّ، لا «لا حساب» */
    bankAccountId: number | null;
    bankAccount: string | null;
    status: string;
    lastSeen: string;
    activatedAt: string;
    activatedBy: string;
    isThis: boolean;
    /** أيُحذف صفُّه؟ — يقيسه الخادم، ولا يُعاد حسابُه هنا */
    erasable: boolean;
    orders: number;
    shifts: number;
    peripherals: Peripheral[];
    issues?: TillIssue[];
}

export interface DevicesData {
    devices: Device[];
    branches: { value: number; label: string }[];
    bankAccounts: { value: number; label: string }[];
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
export default function DevicesPanel({
    devices,
    branches,
    bankAccounts,
    peripheralTypes,
    drivableTypes,
    paperWidths,
}: DevicesData) {
    const { errors } = usePage<PageProps>().props;
    const t = useTranslate();
    const [editing, setEditing] = useState<Device | null>(null);
    const [linking, setLinking] = useState<number | null>(null);
    const form = useForm({ name: '', branch_id: '', bank_account_id: '', confirm_move: false as boolean });
    // نقلٌ إلى فرعٍ آخر — يُعرض أثرُه ويُؤكَّد قبل الحفظ (`DeviceController::update`)
    const moving = !!editing && form.data.branch_id !== '' && form.data.branch_id !== String(editing.branchId);
    const movingPrinters = editing ? editing.peripherals.filter((p) => p.active && p.type === 'طابعة').length : 0;

    // Radix Select لا يقبل قيمةً تتغيّر أثناء الفتح، فتُملأ عند تبديل الجهاز
    useEffect(() => {
        if (editing) {
            form.setData({
                name: editing.name,
                branch_id: String(editing.branchId),
                bank_account_id: editing.bankAccountId ? String(editing.bankAccountId) : '',
                confirm_move: false,
            });
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
                    <span className="min-w-0">
                        <span className="font-medium text-[#111]">
                            {d.name}
                            {d.isThis && (
                                <span className="ms-2 rounded-full bg-[#eef2ff] px-2 py-0.5 text-[11px] text-[#4338ca]">
                                    {t('هذا الجهاز')}
                                </span>
                            )}
                        </span>
                        {/* ما يمنع إيصالَه من الخروج أو يُراجَع — أوّلُه (المؤكَّدُ أوّلًا)، ومعه عددُ البقيّة */}
                        {!!d.issues?.length && (
                            <button
                                type="button"
                                onClick={() => setLinking(d.id)}
                                className={`mt-1 flex items-start gap-1 text-start text-[12px] hover:underline ${
                                    d.issues[0].level === 'confirmed' ? 'text-[#b45309]' : 'text-[#475569]'
                                }`}
                            >
                                {d.issues[0].level === 'confirmed' ? (
                                    <TriangleAlert className="mt-px size-3.5 shrink-0" />
                                ) : (
                                    <CircleHelp className="mt-px size-3.5 shrink-0" />
                                )}
                                <span>
                                    {d.issues[0].level === 'confirmed' ? '' : `${t('للمراجعة')}: `}
                                    {d.issues[0].message}
                                    {d.issues.length > 1 ? ` (+${d.issues.length - 1})` : ''}
                                </span>
                            </button>
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
                    /*
                        إجراءان لا واحد، ولا يجتمعان على صفٍّ واحد:

                        النشطُ يُبطَّل — يموت رمزُه ويبقى سجلّه، فما باعه
                        يبقى منسوبًا إليه.

                        والملغى يُحذف — إن لم يبع. وصندوقٌ باع لا يُعرض عليه
                        الزرّ أصلًا: `erasable` يقيسها الخادمُ بالشرط نفسِه
                        الذي يردّ به، فلا بابَ معروضًا لا يُفتح.
                    */
                    destroy={
                        d.status === 'نشط'
                            ? {
                                  url: route('admin.devices.revoke', d.id),
                                  label: 'إلغاء التفعيل',
                                  message: d.isThis
                                      ? 'هذا هو الجهاز الذي تستخدمه الآن — إلغاؤه يطلب تفعيله من جديد. متابعة؟'
                                      : 'إلغاء تفعيل هذا الجهاز؟ لن يقبل رمز أي موظف بعدها.',
                              }
                            : d.erasable
                              ? {
                                    url: route('admin.devices.destroy', d.id),
                                    label: 'حذف السجل',
                                    message: 'يُمحى سجلّ هذا الصندوق وملحقاته نهائيًا. لم يبع شيئًا، فلا تُفقد نسبة فاتورة. متابعة؟',
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

            {/*
                وسطرٌ يقول لمَ لا يُحذف بعضُ الصفوف.

                زرُّ «حذف السجل» يغيب عن صندوقٍ باع — وغيابٌ بلا سببٍ مكتوب
                يُقرأ عطبًا. والسطرُ ثابتٌ صادقٌ في كل حال، فلا يُلاحق حالةَ
                صفٍّ بعينه ولا يَعِد بما لا يقع.
            */}
            <p className="mt-3 text-[12px] text-[#9ca3af]">
                {t('الصندوق الملغى يُحذف سجلّه إن لم يبع شيئًا. وما باع يبقى في القائمة لتبقى فواتيره منسوبة إليه.')}
            </p>

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

                        {/*
                            بنكُ جهاز الشبكة: البيعُ بالبطاقة من هذا الصندوق
                            يُرحَّل إلى ورقة هذا الحساب ويُطابَق بكشفه. والفارغ
                            ليس «بلا حساب» بل الحسابُ الرئيسيّ — وهو مكتوبٌ في
                            الخيار نفسه لا في حاشيةٍ تُقرأ بعد فوات الأوان.
                        */}
                        <Field
                            label="بنك جهاز الشبكة"
                            hint="إليه تُرحَّل مبيعات البطاقة من هذا الصندوق ويُطابَق كشفُه"
                            error={errors.bank_account_id}
                        >
                            <Select
                                value={form.data.bank_account_id}
                                onChange={(e) => form.setData('bank_account_id', e.target.value)}
                                options={bankAccounts}
                                placeholder="الحساب الرئيسي"
                            />
                        </Field>

                        {/*
                            النقلُ يُرى أثرُه قبل أن يقع: يُبطل التفعيل، والطابعةُ على
                            شبكة الفرع القديم، والبنكُ يتبع الجهاز. والخادمُ يردّ
                            نقلًا بلا هذا التأكيد (`confirm_move`).
                        */}
                        {moving && (
                            <div className="space-y-2 rounded-[10px] border border-[#fde68a] bg-[#fffbeb] p-3 text-[12px] text-[#b45309]">
                                <p className="font-medium">{t('نقل الصندوق إلى فرع آخر')}</p>
                                <ul className="list-disc space-y-0.5 ps-4">
                                    <li>{t('يُبطل تفعيله، ويُعاد من الجهاز نفسه في الفرع الجديد.')}</li>
                                    <li>
                                        {t('طابعاته المفعّلة: :n — تبقى بعناوينها على شبكة الفرع القديم؛ راجعها بعد النقل.', { n: movingPrinters })}
                                    </li>
                                    <li>
                                        {t('بنك جهاز الشبكة: :bank', {
                                            bank:
                                                bankAccounts.find((b) => String(b.value) === form.data.bank_account_id)?.label ??
                                                t('الحساب الرئيسي'),
                                        })}
                                    </li>
                                </ul>
                                <label className="flex items-center gap-2 text-[#111]">
                                    <input
                                        type="checkbox"
                                        checked={form.data.confirm_move}
                                        onChange={(e) => form.setData('confirm_move', e.target.checked)}
                                    />
                                    {t('راجعت الطابعات والبنك، وأريد النقل')}
                                </label>
                                {errors.confirm_move && <p className="text-[#b91c1c]">{errors.confirm_move}</p>}
                            </div>
                        )}

                        <div className="flex justify-end gap-2 pt-1">
                            <Button type="button" variant="outline" onClick={() => setEditing(null)}>
                                {t('إلغاء')}
                            </Button>
                            <Button type="submit" disabled={form.processing || (moving && !form.data.confirm_move)}>
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
                            issues={d.issues ?? []}
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
