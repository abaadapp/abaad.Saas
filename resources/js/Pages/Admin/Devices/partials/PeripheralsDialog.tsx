import { useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { Barcode, CheckCircle2, Loader2, Monitor, Pencil, Plug, Plus, Printer, Scale, Trash2, Wallet, XCircle } from 'lucide-react';
import { useConfirm } from '@/Components/ConfirmDialog';
import Field, { Select } from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input, Textarea } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import { effectivePort, failureMessage, stageMessage, testPrinter } from '@/lib/printing/printer-service';
import type { PrintOutcome, PrintStage } from '@/lib/printing/types';
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
    /** طباعة طلب الموقع عند تأكيده على هذا الجهاز — مستقلّةٌ عن «بعد البيع» */
    autoPrintWebsite: boolean;
    /** يستلم «فاتورة عميل — نسخة داخلية» حين تُسدَّد — لمن فُتح له التجهيزُ بعد السداد */
    autoPrintCustomerInvoices?: boolean;
    notes: string | null;
    active: boolean;
    /** تقودها نقطة البيع فعلًا، أم تُسجَّل للجرد وحده */
    drivable: boolean;
    /** طابعةُ إيصال البيع على هذا الصندوق — يحسبها الخادم (`PosPeripheral::receiptAmong`) */
    receipt?: boolean;
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

/**
 * ما تعنيه طريقةُ التوصيل للطابعة — يُقال قبل الحفظ لا بعد أوّل بيع.
 *
 * تطبيقُ أبعاد يطبع مباشرةً على طابعة الشبكة وحدها (`mobile/PROTOCOL.md`).
 * وUSB والبلوتوث يمرّان بحوار الطباعة في النظام: يعملان على حاسوبٍ ثُبّتت فيه
 * الطابعة، ولا يصلهما جهازٌ لوحيّ إلّا بتطبيقٍ من صانعها.
 */
const CONNECTION_HINTS: Record<string, string> = {
    network: 'تطبيق أبعاد يطبع عليها مباشرةً. وفي المتصفّح يُفتح حوار الطباعة.',
    usb: 'لا يطبع عليها تطبيق أبعاد مباشرةً: يُفتح حوار الطباعة في النظام، فتعمل على حاسوب ثُبّتت فيه الطابعة. على الجهاز اللوحي اختر طابعة شبكة.',
    bluetooth: 'لا يطبع عليها تطبيق أبعاد مباشرةً: يُفتح حوار الطباعة في النظام، فتعمل حيث ثُبّتت الطابعة في النظام. على الجهاز اللوحي اختر طابعة شبكة.',
};

const EMPTY = {
    name: '',
    type: 'طابعة',
    connection: 'usb',
    model: '',
    address: '',
    port: '',
    paper_width: '80',
    auto_print: false as boolean,
    auto_print_website_confirm: false as boolean,
    auto_print_customer_invoices: false as boolean,
    notes: '',
    active: true as boolean,
};

/**
 * الأجهزة الملحقة بصندوق بيع بعينه.
 *
 * والملحقات ليست سواءً في ما يُقاد من المتصفّح: الطابعة تُقاد بحوار الطباعة
 * (ومباشرةً على الشبكة من تطبيق أبعاد — انظر `lib/printing`)،
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
    // أفُتح التجهيزُ بعد السداد لهذا النشاط؟ — من صفحة الأجهزة (`DeviceController::panelData`)
    const customerInvoicePrinting = Boolean(usePage<{ customerInvoicePrinting?: boolean }>().props.customerInvoicePrinting);
    const [editing, setEditing] = useState<Peripheral | null>(null);
    const [adding, setAdding] = useState(false);
    const form = useForm({ ...EMPTY });
    const [test, setTest] = useState<{ stage: PrintStage; outcome: PrintOutcome | null }>({ stage: 'idle', outcome: null });
    const [ask, confirmDialog] = useConfirm();
    /*
        أكثرُ من طابعةٍ مفعّلة على صندوقٍ واحد — يُقال أيُّها يطبع الإيصال.
        كانت «الأولى» صامتةً، فطابعةٌ أُضيفت مرّتين تطبع على القديمة ولا يُعرف لمَ.
    */
    const activePrinters = peripherals.filter((p) => p.active && p.type === 'طابعة');
    const receipt = activePrinters.find((p) => p.receipt) ?? null;

    const open = (p: Peripheral | null) => {
        setTest({ stage: 'idle', outcome: null });
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
                auto_print_website_confirm: p.autoPrintWebsite,
                auto_print_customer_invoices: p.autoPrintCustomerInvoices ?? false,
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
        setTest({ stage: 'idle', outcome: null });
    };

    /**
     * «اختبار الطابعة» — بما في النموذج الآن، قبل الحفظ أو بعده.
     *
     * في تطبيق أبعاد: اتّصالٌ حقيقيّ وصفحةُ اختبارٍ تُطبع. وفي المتصفّح لا
     * يُوهم باختبار: المتصفّحُ لا يبلغ طابعةً على الشبكة المحليّة، فيُقال ذلك
     * صراحةً — `NATIVE_REQUIRED`.
     */
    const runTest = async () => {
        const raw = form.data.port.trim();
        const port = raw === '' ? null : Number(raw);
        const printer = {
            id: editing?.id,
            type: form.data.type,
            name: form.data.name,
            model: form.data.model || null,
            connection: 'network' as const,
            address: form.data.address,
            port,
            paperWidth: Number(form.data.paper_width) || null,
        };

        // منفذٌ مكتوبٌ خطأً لا يُستبدل بالافتراضيّ صامتًا — يُقال
        if (port !== null && !(Number.isInteger(port) && port >= 1 && port <= 65535)) {
            setTest({ stage: 'failed', outcome: { ok: false, code: 'INVALID_PORT', message: '', via: 'native' } });

            return;
        }

        setTest({ stage: 'connecting', outcome: null });
        const outcome = await testPrinter(printer, deviceName, (stage) => setTest((s) => ({ ...s, stage })));
        setTest({ stage: outcome.ok ? 'success' : 'failed', outcome });
    };
    const testing = test.stage !== 'idle' && test.stage !== 'success' && test.stage !== 'failed';

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        const done = { preserveScroll: true, onSuccess: close };
        if (editing) {
            form.put(route('admin.devices.peripherals.update', [deviceId, editing.id]), done);
        } else {
            form.post(route('admin.devices.peripherals.store', deviceId), done);
        }
    };

    // الحذفُ يُسأل عنه: نقرةٌ خاطئة كانت تمحو طابعةَ الصندوق وإعداداتِها بلا رجعة
    const remove = async (p: Peripheral) => {
        const yes = await ask({
            message: 'حذف «:name» من هذا الصندوق؟ تُمحى إعداداته، ولا تُمسّ المبيعات. ولإيقافه مؤقتًا عطّله بدل حذفه.',
            values: { name: p.name },
            action: 'حذف',
            danger: true,
        });
        if (!yes) return;

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
                        {activePrinters.length > 1 && receipt && (
                            <p className="rounded-[10px] border border-[#fde68a] bg-[#fffbeb] p-3 text-[12px] text-[#b45309]">
                                {t('على هذا الصندوق أكثر من طابعة مفعّلة. إيصال البيع يُطبع على «:name» — وهي التي عليها «طباعة تلقائية بعد البيع» إن وُجدت. عطّل المكرّرة أو احذفها.', {
                                    name: receipt.name,
                                })}
                            </p>
                        )}

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
                                            {p.receipt && activePrinters.length > 1 && (
                                                <span className="ms-2 rounded-full bg-[#ecfdf5] px-2 py-0.5 text-[11px] text-[#047857]">
                                                    {t('طابعة الإيصال')}
                                                </span>
                                            )}
                                        </p>
                                        <p className="mt-0.5 truncate text-[12px] text-[#9ca3af]">
                                            {t(p.type)}
                                            {p.model ? ` · ${p.model}` : ''}
                                            {p.address ? ` · ${p.address}${p.port ? ':' + p.port : ''}` : ''}
                                            {p.paperWidth ? ` · ${p.paperWidth}mm` : ''}
                                            {p.autoPrint ? ` · ${t('طباعة تلقائية')}` : ''}
                                            {p.autoPrintWebsite ? ` · ${t('طباعة طلب الموقع عند تأكيده')}` : ''}
                                            {p.autoPrintCustomerInvoices ? ` · ${t('فواتير العملاء المسددة')}` : ''}
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

                            <Field
                                label="طريقة التوصيل"
                                required
                                hint={isPrinter ? CONNECTION_HINTS[form.data.connection] : undefined}
                                error={form.errors.connection}
                            >
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
                                    <Field
                                        label="المنفذ (اختياري)"
                                        hint="فارغًا يُستعمل 9100 — المنفذ الشائع للطباعة الخام على الشبكة"
                                        error={form.errors.port}
                                    >
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

                        {isPrinter && isNetwork && (
                            <div className="space-y-2 rounded-[10px] border border-[var(--ui-border,#e8e8e8)] p-3">
                                <Button type="button" variant="outline" size="sm" disabled={testing || !form.data.address.trim()} onClick={runTest}>
                                    {testing ? <Loader2 className="animate-spin" /> : <Plug />}
                                    {t('اختبار الطابعة')}
                                </Button>
                                <TestResult
                                    stage={test.stage}
                                    outcome={test.outcome}
                                    port={test.outcome?.effectivePort ?? effectivePort({ port: Number(form.data.port) || null })}
                                />
                            </div>
                        )}

                        {!drivableTypes.includes(form.data.type) && (
                            <p className="rounded-[10px] border border-[#fde68a] bg-[#fffbeb] p-3 text-[12px] text-[#b45309]">
                                {t(
                                    'هذا النوع لا يقوده المتصفّح مباشرةً ويحتاج وسيطًا على الجهاز. يُسجَّل هنا للجرد والدعم الفنّي.',
                                )}
                            </p>
                        )}

                        {/*
                            خياران مستقلّان للطابعة وحدها — لا يُشتقّ أحدُهما من الآخر.

                            والثاني يقع على الجهاز الذي ضُغط فيه «مؤكّد»، على طابعة
                            الصندوق المربوط به. فلا يُوعَد بطباعةٍ من هاتفٍ بعيد لا
                            صندوقَ عليه. وفي تطبيق أبعاد يطبع الاثنان على طابعة الشبكة
                            مباشرةً — وفي المتصفّح يُفتح الإيصالُ كما كان.
                        */}
                        {isPrinter && (
                            <>
                                <Toggle
                                    label={t('طباعة تلقائية بعد البيع')}
                                    hint={t('فتح إيصال الطباعة فور إتمام البيع في نقطة البيع.')}
                                    on={form.data.auto_print}
                                    onToggle={() => form.setData('auto_print', !form.data.auto_print)}
                                />
                                <Toggle
                                    label={t('طباعة تلقائية عند تأكيد طلب الموقع')}
                                    hint={t('فتح إيصال طلب الموقع عند تأكيده على هذا الجهاز.')}
                                    on={form.data.auto_print_website_confirm}
                                    onToggle={() =>
                                        form.setData('auto_print_website_confirm', !form.data.auto_print_website_confirm)
                                    }
                                />
                                {/*
                                    وفواتيرُ العملاء المسدَّدة — لمن فتح له مديرُ المنصّة التجهيزَ
                                    بعد السداد. والخادمُ يكتب «لا» لغيره أيًّا أُرسل.
                                */}
                                {customerInvoicePrinting && (
                                    <Toggle
                                        label={t('طباعة فواتير العملاء المسددة')}
                                        hint={t('تُطبع على هذا الجهاز «فاتورة عميل — نسخة داخلية» حين تُسدَّد الفاتورة، ما دامت نقطة البيع مفتوحة في تطبيق أبعاد.')}
                                        on={form.data.auto_print_customer_invoices}
                                        onToggle={() =>
                                            form.setData('auto_print_customer_invoices', !form.data.auto_print_customer_invoices)
                                        }
                                    />
                                )}
                            </>
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

            {confirmDialog}
        </>
    );
}

/**
 * نتيجةُ «اختبار الطابعة» — بجملٍ لا رموز، ومعها المنفذُ الذي طُلب فعلًا.
 *
 * والاتّصالُ والطباعةُ يُقالان منفصلين: طابعةٌ ردّت ولم تطبع غيرُ طابعةٍ لم
 * تردّ، وإصلاحُ كلٍّ منهما في مكانٍ آخر.
 */
function TestResult({ stage, outcome, port }: { stage: PrintStage; outcome: PrintOutcome | null; port: number }) {
    const t = useTranslate();

    if (stage === 'idle') return null;

    if (outcome && !outcome.ok && outcome.code === 'NATIVE_REQUIRED') {
        return (
            <div role="status" className="rounded-[10px] border border-[#fde68a] bg-[#fffbeb] p-3 text-[12px] text-[#b45309]">
                <p className="font-medium">{t('الطباعة المباشرة تحتاج تطبيق أبعاد')}</p>
                <p className="mt-1">
                    {t('هذا المتصفح لا يصل إلى طابعة على الشبكة المحلية، فلم يُجرَ أي اختبار. افتح هذه الشاشة من تطبيق أبعاد على الجهاز اللوحي لاختبار الطابعة.')}
                </p>
            </div>
        );
    }

    if (!outcome) {
        return (
            <p role="status" className="flex items-center gap-1.5 text-[12px] text-[#6b7280]">
                <Loader2 className="size-3.5 animate-spin" />
                {stageMessage(stage, t)}
            </p>
        );
    }

    const ok = (text: string) => (
        <p className="flex items-center gap-1.5 text-[#047857]">
            <CheckCircle2 className="size-3.5" />
            {text}
        </p>
    );

    return (
        <div role="status" data-testid="printer-test-result" className="space-y-1 text-[12px]">
            {(outcome.ok || outcome.connected) && ok(t('تم الاتصال بالطابعة'))}
            {outcome.ok ? (
                ok(t('تمت طباعة صفحة الاختبار'))
            ) : (
                <p className="flex items-center gap-1.5 text-[#b91c1c]">
                    <XCircle className="size-3.5" />
                    {failureMessage(outcome.code, t)}
                </p>
            )}
            <p className="text-[#9ca3af]">
                {t('المنفذ المستخدم')}: <span dir="ltr">{port}</span>
            </p>
        </div>
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
