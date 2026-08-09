import { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { Barcode, Monitor, Pencil, Plus, Printer, Scale, Trash2, Wallet } from 'lucide-react';
import Field, { Select } from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input, Textarea } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export interface Peripheral {
    id: number;
    name: string;
    type: string;
    connection: string;
    model: string | null;
    address: string | null;
    port: number | null;
    paperWidth: number | null;
    autoPrint: boolean;
    notes: string | null;
    active: boolean;
    /** تقودها نقطة البيع فعلًا، أم تُسجَّل للجرد وحده */
    drivable: boolean;
}

interface Props {
    deviceId: number;
    deviceName: string;
    peripherals: Peripheral[];
    types: string[];
    drivableTypes: string[];
    paperWidths: number[];
    onClose: () => void;
}

const ICONS: Record<string, typeof Printer> = {
    'طابعة': Printer,
    'ماسح باركود': Barcode,
    'درج نقدي': Wallet,
    'شاشة عميل': Monitor,
    'ميزان': Scale,
};

const CONNECTIONS = [
    { value: 'usb', label: 'USB' },
    { value: 'network', label: 'شبكة' },
    { value: 'bluetooth', label: 'بلوتوث' },
];

const EMPTY = {
    name: '',
    type: 'طابعة',
    connection: 'usb',
    model: '',
    address: '',
    port: '',
    paper_width: '80',
    auto_print: false as boolean,
    notes: '',
    active: true as boolean,
};

/**
 * الأجهزة الملحقة بصندوق بيع بعينه.
 *
 * والملحقات ليست سواءً في ما يُقاد من المتصفّح: الطابعة تُقاد بحوار الطباعة،
 * والماسح يكتب كلوحة مفاتيح فيُلتقط في حقل الباركود. أمّا الدرج والشاشة
 * والميزان فلا يبلغها متصفّحٌ بلا وسيطٍ على الجهاز — فتُسجَّل هنا للجرد
 * والدعم، وتُعلَّم صراحةً بأنها «للسجلّ». وشاشةٌ توهم بأنها تقود ما لا تقوده
 * تُنتج بلاغًا كاذبًا يوم يقف الدرج: «النظام يقول إنه موصول».
 */
export default function PeripheralsDialog({
    deviceId,
    deviceName,
    peripherals,
    types,
    drivableTypes,
    paperWidths,
    onClose,
}: Props) {
    const t = useTranslate();
    const [editing, setEditing] = useState<Peripheral | null>(null);
    const [adding, setAdding] = useState(false);
    const form = useForm({ ...EMPTY });

    const open = (p: Peripheral | null) => {
        if (p) {
            form.setData({
                name: p.name,
                type: p.type,
                connection: p.connection,
                model: p.model ?? '',
                address: p.address ?? '',
                port: p.port ? String(p.port) : '',
                paper_width: String(p.paperWidth ?? 80),
                auto_print: p.autoPrint,
                notes: p.notes ?? '',
                active: p.active,
            });
            setEditing(p);
        } else {
            form.setData({ ...EMPTY });
            setAdding(true);
        }
    };

    const close = () => {
        setEditing(null);
        setAdding(false);
        form.clearErrors();
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        const done = { preserveScroll: true, onSuccess: close };
        if (editing) {
            form.put(route('admin.devices.peripherals.update', [deviceId, editing.id]), done);
        } else {
            form.post(route('admin.devices.peripherals.store', deviceId), done);
        }
    };

    const remove = (p: Peripheral) => {
        router.delete(route('admin.devices.peripherals.destroy', [deviceId, p.id]), {
            preserveScroll: true,
        });
    };

    const isPrinter = form.data.type === 'طابعة';
    const isNetwork = form.data.connection === 'network';
    const formOpen = adding || !!editing;

    return (
        <>
            <Dialog open={!formOpen} onOpenChange={(o) => !o && onClose()}>
                <DialogContent className="max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>
                            {t('الأجهزة الملحقة')} — {deviceName}
                        </DialogTitle>
                    </DialogHeader>

                    <div className="space-y-3 px-5 pb-5">
                        {peripherals.length === 0 && (
                            <p className="rounded-[10px] bg-[#fafafa] p-6 text-center text-[13px] text-[#9ca3af]">
                                {t('لا ملحقات على هذا الصندوق بعد.')}
                            </p>
                        )}

                        {peripherals.map((p) => {
                            const Icon = ICONS[p.type] ?? Printer;
                            return (
                                <div
                                    key={p.id}
                                    className="flex items-center gap-3 rounded-[10px] border border-[var(--ui-border,#e8e8e8)] p-3"
                                >
                                    <span
                                        className={cn(
                                            'flex size-9 shrink-0 items-center justify-center rounded-[10px]',
                                            p.active
                                                ? 'bg-[#f2f2f0] text-[#4b4b4b]'
                                                : 'bg-[#fafafa] text-[#d1d5db]',
                                        )}
                                    >
                                        <Icon className="size-4" />
                                    </span>

                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-sm font-medium text-[#111]">
                                            {p.name}
                                            {!p.active && (
                                                <span className="ms-2 rounded-full bg-[#fef2f2] px-2 py-0.5 text-[11px] text-[#b91c1c]">
                                                    {t('معطّل')}
                                                </span>
                                            )}
                                        </p>
                                        <p className="mt-0.5 truncate text-[12px] text-[#9ca3af]">
                                            {t(p.type)}
                                            {p.model ? ` · ${p.model}` : ''}
                                            {p.address ? ` · ${p.address}${p.port ? ':' + p.port : ''}` : ''}
                                            {p.paperWidth ? ` · ${p.paperWidth}mm` : ''}
                                            {p.autoPrint ? ` · ${t('طباعة تلقائية')}` : ''}
                                        </p>
                                    </div>

                                    {/* لا يُوعَد بما لا يقع: ما لا يقوده المتصفّح يُعلَّم «للسجلّ» */}
                                    {!p.drivable && (
                                        <span
                                            className="shrink-0 rounded-full bg-[#fffbeb] px-2 py-0.5 text-[11px] text-[#b45309]"
                                            title={t('يحتاج وسيطًا على الجهاز — يُسجَّل هنا للجرد والدعم')}
                                        >
                                            {t('للسجلّ')}
                                        </span>
                                    )}

                                    <Button variant="ghost" size="icon-sm" onClick={() => open(p)}>
                                        <Pencil className="size-4" />
                                    </Button>
                                    <Button variant="ghost" size="icon-sm" onClick={() => remove(p)}>
                                        <Trash2 className="size-4 text-[#b91c1c]" />
                                    </Button>
                                </div>
                            );
                        })}

                        <div className="flex justify-between pt-1">
                            <Button variant="outline" onClick={() => open(null)}>
                                <Plus className="size-4" />
                                {t('إضافة جهاز ملحق')}
                            </Button>
                            <Button variant="outline" onClick={onClose}>
                                {t('إغلاق')}
                            </Button>
                        </div>
                    </div>
                </DialogContent>
            </Dialog>

            <Dialog open={formOpen} onOpenChange={(o) => !o && close()}>
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>
                            {editing ? t('تعديل الجهاز الملحق') : t('إضافة جهاز ملحق')}
                        </DialogTitle>
                    </DialogHeader>

                    <form onSubmit={submit} className="space-y-4 px-5 pb-5">
                        <div className="grid grid-cols-2 gap-3">
                            <Field label="اسم الجهاز" required error={form.errors.name}>
                                <Input
                                    value={form.data.name}
                                    onChange={(e) => form.setData('name', e.target.value)}
                                    placeholder={t('مثال: طابعة الكاشير')}
                                    required
                                />
                            </Field>

                            <Field label="النوع" required error={form.errors.type}>
                                <Select
                                    value={form.data.type}
                                    onChange={(e) => form.setData('type', e.target.value)}
                                    options={types.map((x) => ({ value: x, label: x }))}
                                />
                            </Field>

                            <Field label="طريقة التوصيل" required error={form.errors.connection}>
                                <Select
                                    value={form.data.connection}
                                    onChange={(e) => form.setData('connection', e.target.value)}
                                    options={CONNECTIONS}
                                />
                            </Field>

                            <Field label="الطراز (اختياري)" error={form.errors.model}>
                                <Input
                                    dir="ltr"
                                    value={form.data.model}
                                    onChange={(e) => form.setData('model', e.target.value)}
                                    placeholder="e.g. Epson TM-T20"
                                />
                            </Field>

                            {isNetwork && (
                                <>
                                    <Field label="العنوان" required error={form.errors.address}>
                                        <Input
                                            dir="ltr"
                                            value={form.data.address}
                                            onChange={(e) => form.setData('address', e.target.value)}
                                            placeholder="192.168.1.50"
                                        />
                                    </Field>
                                    <Field label="المنفذ (اختياري)" error={form.errors.port}>
                                        <Input
                                            dir="ltr"
                                            inputMode="numeric"
                                            value={form.data.port}
                                            onChange={(e) => form.setData('port', e.target.value)}
                                            placeholder="9100"
                                        />
                                    </Field>
                                </>
                            )}

                            {isPrinter && (
                                <Field
                                    label="عرض الورق"
                                    hint="يُطبَّق على الإيصال المطبوع من هذا الصندوق"
                                    error={form.errors.paper_width}
                                >
                                    <Select
                                        value={form.data.paper_width}
                                        onChange={(e) => form.setData('paper_width', e.target.value)}
                                        options={paperWidths.map((w) => ({
                                            value: String(w),
                                            label: `${w}mm`,
                                        }))}
                                    />
                                </Field>
                            )}
                        </div>

                        {!drivableTypes.includes(form.data.type) && (
                            <p className="rounded-[10px] border border-[#fde68a] bg-[#fffbeb] p-3 text-[12px] text-[#b45309]">
                                {t(
                                    'هذا النوع لا يقوده المتصفّح مباشرةً ويحتاج وسيطًا على الجهاز. يُسجَّل هنا للجرد والدعم الفنّي.',
                                )}
                            </p>
                        )}

                        {isPrinter && (
                            <Toggle
                                label={t('طباعة تلقائية بعد البيع')}
                                hint={t('يفتح حوار الطباعة فور إتمام الفاتورة')}
                                on={form.data.auto_print}
                                onToggle={() => form.setData('auto_print', !form.data.auto_print)}
                            />
                        )}

                        <Toggle
                            label={t('الجهاز مفعّل')}
                            hint={t('المعطّل لا تستعمله نقطة البيع ويبقى في السجلّ')}
                            on={form.data.active}
                            onToggle={() => form.setData('active', !form.data.active)}
                        />

                        <Field label="ملاحظات (اختياري)" error={form.errors.notes}>
                            <Textarea
                                rows={2}
                                value={form.data.notes}
                                onChange={(e) => form.setData('notes', e.target.value)}
                                placeholder={t('رقم الصيانة، مكان الجهاز، أي شيء يفيد من يأتي بعدك…')}
                            />
                        </Field>

                        <div className="flex justify-end gap-2 pt-1">
                            <Button type="button" variant="outline" onClick={close}>
                                {t('إلغاء')}
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                {editing ? t('حفظ التغييرات') : t('إضافة')}
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}

/** مفتاح تبديل — بنفس شكل مفتاح «تفعيل المنتج» في نموذج المنتج */
function Toggle({
    label,
    hint,
    on,
    onToggle,
}: {
    label: string;
    hint: string;
    on: boolean;
    onToggle: () => void;
}) {
    return (
        <div className="flex items-center justify-between gap-3">
            <div>
                <p className="text-sm font-medium text-[#111]">{label}</p>
                <p className="mt-0.5 text-[12px] text-[#9ca3af]">{hint}</p>
            </div>
            <button
                type="button"
                role="switch"
                aria-checked={on}
                onClick={onToggle}
                className={cn(
                    'relative h-6 w-12 shrink-0 rounded-full transition-colors',
                    on ? 'bg-[#111]' : 'bg-[#d1d5db]',
                )}
            >
                <span
                    className={cn(
                        'absolute top-0.5 size-5 rounded-full bg-white shadow transition-[inset-inline-start]',
                        on ? 'start-[26px]' : 'start-0.5',
                    )}
                />
            </button>
        </div>
    );
}
