import { useEffect, useState } from 'react';
import { usePage } from '@inertiajs/react';
import { Banknote, CheckCircle, CreditCard, Landmark, Plus, Printer } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { CheckoutResult } from '@/hooks/usePosCart';
import type { PageProps } from '@/types';

const ALL_METHODS = [
    { value: 'نقدي', label: 'نقدي', icon: Banknote },
    { value: 'بطاقة', label: 'فيزا', icon: CreditCard },
    { value: 'تحويل بنكي', label: 'تحويل بنكي', icon: Landmark },
];

interface Props {
    /** الوسائل المأذونة من الإعدادات؛ غيابها يعني الثلاث (شاشة قديمة) */
    methods?: string[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
    total: number;
    displayTotal: number;
    customer: string;
    money: (v: number) => string;
    /** تنسيق قيمة هي أصلًا بعملة العرض (بلا تحويل) */
    fmt: (v: number) => string;
    onCheckout: (method: string) => Promise<CheckoutResult>;
    onNewOrder: () => void;
}

export default function PaymentDialog({
    open, onOpenChange, total, displayTotal, customer, money, fmt, onCheckout, onNewOrder, methods,
}: Props) {
    const t = useTranslate();
    const { context } = usePage<PageProps>().props;
    // طابعة هذا الصندوق وحدها — لا طابعة صندوقٍ آخر في الفرع نفسه
    const printer = context?.peripherals?.find((x) => x.type === 'طابعة');
    const METHODS = methods?.length
        ? ALL_METHODS.filter((m) => methods.includes(m.value))
        : ALL_METHODS;
    const [step, setStep] = useState<'pay' | 'success'>('pay');
    const [paid, setPaid] = useState('');
    // أوّل وسيلةٍ مأذونة لا «نقدي» دائمًا: من أطفأ النقد لا يبدأ عليه
    const [method, setMethod] = useState(METHODS[0]?.value ?? 'نقدي');
    const [busy, setBusy] = useState(false);
    const [result, setResult] = useState<CheckoutResult | null>(null);

    // كل فتح جديد يبدأ من خطوة الدفع بمبلغ صفر
    useEffect(() => {
        if (open) {
            setStep('pay');
            setPaid('');
            setResult(null);
            setMethod((m) => (METHODS.some((x) => x.value === m) ? m : METHODS[0]?.value ?? 'نقدي'));
        }
    }, [open]);

    const paidNum = Number(paid) || 0;
    const remaining = Math.max(0, displayTotal - paidNum);
    const change = Math.max(0, paidNum - displayTotal);

    const confirm = async () => {
        setBusy(true);
        try {
            const res = await onCheckout(method);
            setResult(res);
            setStep('success');

            /*
             * الطباعة التلقائية — إن كانت مضبوطة على طابعة هذا الصندوق.
             *
             * ومشروطةٌ بـres.invoice: البيع بلا اتصال يُحفظ في الطابور بلا رقم
             * فاتورة بعد، وفتحُ نافذةٍ على رابطٍ لا وجود له يعطي الكاشير صفحة
             * خطأ عند رأس الزبون. يطبع حين يوجد ما يُطبع.
             *
             * ونافذةٌ منفصلة لا طباعةٌ في مكانها: الشاشة نفسها لا تزال تعرض
             * تأكيد البيع، وحوارُ الطباعة يجمّد ما تحته.
             */
            if (printer?.autoPrint && res.synced && res.invoice) {
                window.open(route('pos.receipt.pdf', res.invoice), '_blank', 'noopener');
            }
        } finally {
            setBusy(false);
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>{step === 'pay' ? t('إتمام الدفع') : t('تم الدفع بنجاح')}</DialogTitle>
                    {step === 'success' && (
                        <DialogDescription>
                            {result?.synced
                                ? t('تمت معالجة الطلب وإصدار الفاتورة')
                                : t('لا يوجد اتصال — حُفظ البيع وسيُرسَل تلقائيًا عند عودة الاتصال')}
                        </DialogDescription>
                    )}
                </DialogHeader>

                {step === 'pay' ? (
                    <div className="space-y-4 px-5 pb-5">
                        <div className="rounded-2xl bg-gray-100 p-4 text-center">
                            <p className="text-sm text-[#111]">{t('الإجمالي المطلوب')}</p>
                            <p className="mt-1 text-3xl font-extrabold text-[#111]">{money(total)}</p>
                        </div>

                        <div>
                            <Label htmlFor="paid" className="mb-1.5">{t('المبلغ المدفوع')}</Label>
                            <Input
                                id="paid"
                                inputMode="decimal"
                                value={paid}
                                onChange={(e) => setPaid(e.target.value)}
                                placeholder="0.000"
                                className="h-12 text-lg font-bold"
                            />
                        </div>

                        <div className="grid grid-cols-2 gap-3">
                            <div className="rounded-xl bg-gray-50 p-3 text-center">
                                <p className="text-xs text-gray-400">{t('المبلغ المتبقي')}</p>
                                <p className="mt-0.5 font-bold text-[#dc2626]">{fmt(remaining)}</p>
                            </div>
                            <div className="rounded-xl bg-gray-50 p-3 text-center">
                                <p className="text-xs text-gray-400">{t('المبلغ المرتجع')}</p>
                                <p className="mt-0.5 font-bold text-[#059669]">{fmt(change)}</p>
                            </div>
                        </div>

                        <div>
                            <Label className="mb-1.5">{t('وسيلة الدفع')}</Label>
                            {/* الأعمدة بعدد المعروض: وسيلتان في ثلاثة أعمدة تتركان فراغًا يبدو عطلًا */}
                            <div
                                className="grid gap-2"
                                style={{ gridTemplateColumns: `repeat(${Math.max(1, METHODS.length)}, minmax(0, 1fr))` }}
                            >
                                {METHODS.map((m) => {
                                    const Icon = m.icon;
                                    const active = method === m.value;
                                    return (
                                        <button
                                            key={m.value}
                                            type="button"
                                            onClick={() => setMethod(m.value)}
                                            className={cn(
                                                'flex flex-col items-center gap-1 rounded-full border py-2.5 text-xs font-medium transition-colors',
                                                active
                                                    ? 'border-[#111] bg-gray-100 text-[#111]'
                                                    : 'border-gray-200 text-gray-600 hover:bg-gray-50',
                                            )}
                                        >
                                            <Icon className="size-5" />
                                            {t(m.label)}
                                        </button>
                                    );
                                })}
                            </div>
                        </div>

                        <Button variant="success" size="lg" className="w-full rounded-full" disabled={busy} onClick={confirm}>
                            {busy ? '…' : t('تأكيد الدفع')}
                        </Button>
                    </div>
                ) : (
                    <div className="space-y-4 px-5 pb-5 text-center">
                        <div className="mx-auto flex size-20 items-center justify-center rounded-full bg-[#ecfdf5] text-[#059669]">
                            <CheckCircle className="size-12" />
                        </div>

                        <div className="space-y-2 rounded-2xl bg-gray-50 p-4 text-start text-sm">
                            {result?.synced && result.invoice && (
                                <div className="flex justify-between">
                                    <span className="text-gray-500">{t('رقم الفاتورة')}</span>
                                    <span className="font-bold text-[#111]">{result.invoice}</span>
                                </div>
                            )}
                            <div className="flex justify-between">
                                <span className="text-gray-500">{t('المبلغ')}</span>
                                <span className="font-bold text-[#111]">{money(total)}</span>
                            </div>
                            <div className="flex justify-between">
                                <span className="text-gray-500">{t('وسيلة الدفع')}</span>
                                <span className="font-bold text-[#111]">
                                    {method === 'بطاقة' ? t('فيزا') : t(method)}
                                </span>
                            </div>
                            <div className="flex justify-between">
                                <span className="text-gray-500">{t('العميل')}</span>
                                <span className="font-bold text-[#111]">{customer}</span>
                            </div>
                        </div>

                        {(result?.points ?? 0) > 0 && (
                            <p className="flex items-center justify-center gap-2 rounded-xl bg-[#fdf2f8] px-3 py-2 text-sm font-bold text-[#be185d]">
                                {t('نقاط ولاء مكتسبة:')} {result!.points}
                            </p>
                        )}

                        <div className="grid grid-cols-2 gap-3">
                            {result?.synced && result.invoice && (
                                <Button
                                    variant="outline"
                                    className="rounded-full"
                                    onClick={() =>
                                        window.open(`/pos/receipt/${encodeURIComponent(result.invoice!)}/pdf`, '_blank')
                                    }
                                >
                                    <Printer />
                                    {t('طباعة الفاتورة')}
                                </Button>
                            )}
                            <Button
                                className={cn('rounded-full', !(result?.synced && result.invoice) && 'col-span-2')}
                                onClick={() => {
                                    onNewOrder();
                                    onOpenChange(false);
                                }}
                            >
                                <Plus />
                                {t('طلب جديد')}
                            </Button>
                        </div>
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}
