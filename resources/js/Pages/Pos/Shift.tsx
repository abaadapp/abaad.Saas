import { useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import {
    ArrowDownLeft,
    ArrowUpRight,
    Banknote,
    CreditCard,
    Landmark,
    LockKeyhole,
    Play,
    Wallet,
} from 'lucide-react';
import PosLayout from '@/Layouts/PosLayout';
import Field from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { money, number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

const METHOD_ICON: Record<string, typeof Banknote> = {
    'نقدي': Banknote,
    'بطاقة': CreditCard,
    'تحويل بنكي': Landmark,
};

interface OpenShift {
    id: number;
    opened_at: string | null;
    opened_by: string | null;
    count: number;
    /** null لمن لا يملك صلاحية «المالية» — العدّ يبقى أعمى */
    opening_balance: number | null;
    byMethod: Record<string, number> | null;
    sales: number | null;
    expected: number | null;
    movements: Movement[];
}

interface Movement {
    id: number;
    type: 'in' | 'out';
    /** null لمن لا يرى المبالغ — والسبب يبقى ظاهرًا ليعلم أنّ حركته سُجّلت */
    amount: number | null;
    reason: string;
    by: string | null;
    at: string | null;
}

interface Props {
    shift: OpenShift | null;
    showsAmounts: boolean;
    branchName: string;
    /** المعدود قد يكون فارغًا: وردية أُقفلت بلا عدّ لا رقم لها يُورَّث */
    lastClosed: { closed_at: string; actual_balance: number | null } | null;
}

export default function PosShift() {
    const { shift, showsAmounts, branchName, lastClosed, context } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const m = (v: number) => money(v, context!.currency);

    // الرصيد الابتدائي يُقترح من إقفال أمس: الدرج يُترك عادةً بما فيه
    const openForm = useForm({
        opening_balance: lastClosed?.actual_balance ? String(lastClosed.actual_balance) : '',
    });
    const closeForm = useForm({ counted: '', note: '' });
    const moveForm = useForm({ type: 'out', amount: '', reason: '' });
    const [moving, setMoving] = useState<'in' | 'out' | null>(null);

    const openMove = (type: 'in' | 'out') => {
        moveForm.clearErrors();
        moveForm.setData({ type, amount: '', reason: '' });
        setMoving(type);
    };

    const saveMove = () =>
        moveForm.post(route('pos.shift.move'), {
            preserveScroll: true,
            onSuccess: () => {
                moveForm.reset();
                setMoving(null);
            },
        });

    return (
        <PosLayout title={t('وردية الصندوق')}>
            <div className="mx-auto max-w-3xl p-4">
                <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
                    <h1 className="text-[20px] font-bold text-[#111]">{t('وردية الصندوق')}</h1>
                    <span className="text-sm text-gray-500">{branchName}</span>
                </div>

                {!shift ? (
                    <Card>
                        <CardHeader>
                            <CardTitle>{t('فتح الوردية')}</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <p className="text-[13px] text-[#6b7280]">
                                {t('اعدد ما في الدرج الآن وأدخله. عليه يُبنى حساب الإقفال في آخر اليوم.')}
                            </p>

                            <Field label="الرصيد الابتدائي في الدرج" required error={openForm.errors.opening_balance}>
                                <Input
                                    inputMode="decimal"
                                    dir="ltr"
                                    autoFocus
                                    value={openForm.data.opening_balance}
                                    onChange={(e) => openForm.setData('opening_balance', e.target.value)}
                                    placeholder="0.000"
                                />
                            </Field>

                            {lastClosed && (
                                <p className="text-[12px] text-[#9ca3af]">
                                    {t('آخر إقفال')}: {lastClosed.closed_at}
                                    {showsAmounts && lastClosed.actual_balance !== null && ` · ${m(lastClosed.actual_balance)}`}
                                </p>
                            )}

                            <Button
                                loading={openForm.processing}
                                onClick={() => openForm.post(route('pos.shift.open'))}
                            >
                                <Play />
                                {t('افتح الوردية')}
                            </Button>
                        </CardContent>
                    </Card>
                ) : (
                    <div className="space-y-4">
                        <Card>
                            <CardHeader>
                                <CardTitle>{t('الوردية مفتوحة')}</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                <div className="flex flex-wrap gap-x-6 gap-y-1 text-sm text-[#4b4b4b]">
                                    <span>
                                        {t('فُتحت')}: {shift.opened_at}
                                    </span>
                                    {shift.opened_by && (
                                        <span>
                                            {t('بواسطة')}: {shift.opened_by}
                                        </span>
                                    )}
                                    <span>
                                        {number(shift.count)} {t('عملية')}
                                    </span>
                                </div>

                                {showsAmounts && shift.byMethod && (
                                    <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                        {Object.entries(shift.byMethod).map(([k, v]) => {
                                            const Icon = METHOD_ICON[k] ?? Wallet;
                                            return (
                                                <div
                                                    key={k}
                                                    className="flex items-center justify-between rounded-[12px] border border-[var(--ui-border,#e8e8e8)] px-3 py-2.5"
                                                >
                                                    <span className="inline-flex items-center gap-2 text-sm text-[#4b4b4b]">
                                                        <Icon className="size-4 text-gray-400" />
                                                        {t(k)}
                                                    </span>
                                                    <span className="tabular-nums font-medium">{m(v)}</span>
                                                </div>
                                            );
                                        })}
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle>{t('حركة النقد')}</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <p className="text-[13px] text-[#6b7280]">
                                    {t('سجّل ما خرج من الدرج أو دخله — وإلا ظهر سحبُك نقصًا عند الإقفال.')}
                                </p>

                                <div className="flex flex-wrap gap-2">
                                    <Button variant="outline" onClick={() => openMove('out')}>
                                        <ArrowUpRight />
                                        {t('سحب من الدرج')}
                                    </Button>
                                    <Button variant="outline" onClick={() => openMove('in')}>
                                        <ArrowDownLeft />
                                        {t('إيداع في الدرج')}
                                    </Button>
                                </div>

                                {shift.movements.length > 0 && (
                                    <ul className="divide-y divide-[var(--ui-border,#e8e8e8)] rounded-[12px] border border-[var(--ui-border,#e8e8e8)]">
                                        {shift.movements.map((mv) => (
                                            <li key={mv.id} className="flex items-center gap-3 px-3 py-2.5">
                                                <span
                                                    className={
                                                        'flex size-7 shrink-0 items-center justify-center rounded-full ' +
                                                        (mv.type === 'out'
                                                            ? 'bg-[#fef2f2] text-[#b91c1c]'
                                                            : 'bg-[#ecfdf5] text-[#047857]')
                                                    }
                                                >
                                                    {mv.type === 'out' ? (
                                                        <ArrowUpRight className="size-4" />
                                                    ) : (
                                                        <ArrowDownLeft className="size-4" />
                                                    )}
                                                </span>
                                                <span className="min-w-0 flex-1">
                                                    <span className="block truncate text-sm text-[#111]">
                                                        {mv.reason}
                                                    </span>
                                                    <span className="block text-[12px] text-[#9ca3af]">
                                                        {mv.by} · {mv.at}
                                                    </span>
                                                </span>
                                                {mv.amount !== null && (
                                                    <span
                                                        className={
                                                            'shrink-0 tabular-nums font-medium ' +
                                                            (mv.type === 'out' ? 'text-[#b91c1c]' : 'text-[#047857]')
                                                        }
                                                    >
                                                        {mv.type === 'out' ? '−' : '+'}
                                                        {m(mv.amount)}
                                                    </span>
                                                )}
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle>{t('إقفال الوردية')}</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                {/*
                                    عدٌّ أعمى: الرقم المتوقّع لا يُعرض قبل الإدخال حتى لمن
                                    يملك صلاحية المالية. من يراه يميل — بلا قصدٍ غالبًا —
                                    إلى كتابته بدل ما عدّه، فيختفي النقص ولا يُكتشف.
                                */}
                                <p className="text-[13px] text-[#6b7280]">
                                    {t('اعدد ما في الدرج نقدًا وأدخله. يظهر الفرق بعد الحفظ.')}
                                </p>

                                <Field label="المبلغ المعدود نقدًا" required error={closeForm.errors.counted}>
                                    <Input
                                        inputMode="decimal"
                                        dir="ltr"
                                        value={closeForm.data.counted}
                                        onChange={(e) => closeForm.setData('counted', e.target.value)}
                                        placeholder="0.000"
                                    />
                                </Field>

                                <Field label="ملاحظة" error={closeForm.errors.note}>
                                    <Input
                                        value={closeForm.data.note}
                                        onChange={(e) => closeForm.setData('note', e.target.value)}
                                        placeholder={t('سبب الفرق إن وُجد (اختياري)')}
                                    />
                                </Field>

                                <Button
                                    loading={closeForm.processing}
                                    onClick={() => closeForm.post(route('pos.shift.close'))}
                                >
                                    <LockKeyhole />
                                    {t('أقفل الوردية')}
                                </Button>
                            </CardContent>
                        </Card>
                    </div>
                )}
            </div>

            <Dialog open={moving !== null} onOpenChange={(v) => !v && setMoving(null)}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>{t(moving === 'in' ? 'إيداع في الدرج' : 'سحب من الدرج')}</DialogTitle>
                    </DialogHeader>
                    <div className="space-y-4 px-5 pb-5">
                        <Field label="المبلغ" required error={moveForm.errors.amount}>
                            <Input
                                inputMode="decimal"
                                dir="ltr"
                                autoFocus
                                value={moveForm.data.amount}
                                onChange={(e) => moveForm.setData('amount', e.target.value)}
                                placeholder="0.000"
                            />
                        </Field>

                        {/* السبب إلزامي: مبلغٌ بلا سبب لا يُراجَع ولا يُسأل عنه أحد */}
                        <Field label="السبب" required error={moveForm.errors.reason}>
                            <Input
                                value={moveForm.data.reason}
                                onChange={(e) => moveForm.setData('reason', e.target.value)}
                                placeholder={t(moving === 'in' ? 'مثال: فكّة' : 'مثال: دفعة لمورّد')}
                            />
                        </Field>

                        <div className="flex justify-end gap-2 pt-1">
                            <Button type="button" variant="ghost" onClick={() => setMoving(null)}>
                                {t('إلغاء')}
                            </Button>
                            <Button type="button" loading={moveForm.processing} onClick={saveMove}>
                                {t('حفظ')}
                            </Button>
                        </div>
                    </div>
                </DialogContent>
            </Dialog>
        </PosLayout>
    );
}
