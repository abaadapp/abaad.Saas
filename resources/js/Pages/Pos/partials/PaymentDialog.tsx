import { useEffect, useState } from 'react';
import { usePage } from '@inertiajs/react';
import { Banknote, CheckCircle, ChevronDown, CreditCard, Landmark, Plus, Printer } from 'lucide-react';
import Field, { Select } from '@/Components/Field';
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

export interface OrderOptions {
    occasions: { value: string; label: string }[];
    fulfillments: { value: string; label: string }[];
    cardMax: number;
}

/** ما يُرسل مع البيعة من تفاصيل طلب الورد — كلّه اختياريّ */
export interface FlowerDetails {
    fulfillment_type: string;
    recipient_name: string;
    recipient_phone: string;
    scheduled_for: string;
    occasion_type: string;
    card_message: string;
    sender_name: string;
    hide_sender: boolean;
    delivery_address: string;
    delivery_notes: string;
    internal_notes: string;
}

const BLANK: FlowerDetails = {
    fulfillment_type: '', recipient_name: '', recipient_phone: '', scheduled_for: '',
    occasion_type: '', card_message: '', sender_name: '', hide_sender: false,
    delivery_address: '', delivery_notes: '', internal_notes: '',
};

interface Props {
    /** الوسائل المأذونة من الإعدادات؛ غيابها يعني الثلاث (شاشة قديمة) */
    methods?: string[];
    /** خيارات طلب الورد؛ غيابها يعني شاشةً قديمة فيُخفى القسم كلّه */
    orderOptions?: OrderOptions;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    total: number;
    displayTotal: number;
    customer: string;
    money: (v: number) => string;
    /** تنسيق قيمة هي أصلًا بعملة العرض (بلا تحويل) */
    fmt: (v: number) => string;
    onCheckout: (method: string, details?: Record<string, unknown>) => Promise<CheckoutResult>;
    onNewOrder: () => void;
}

export default function PaymentDialog({
    open, onOpenChange, total, displayTotal, customer, money, fmt, onCheckout, onNewOrder, methods, orderOptions,
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
    /*
     * تفاصيل طلب الورد — مطويّةٌ افتراضيًّا.
     *
     * أكثر بيعات اليوم باقةٌ تُؤخذ من المنضدة: تُدفع وتُحمل. ولو فُتحت هذه
     * الحقول على كلّ بيعة لَصار الكاشير يمرّ على أحد عشر حقلًا فارغًا قبل كلّ
     * دفعة — فيتجاهلها، أو يملؤها بأيّ شيء ليتخلّص منها. وتُفتح بضغطة لمن
     * يبيع طلبًا بموعد.
     */
    const [flowerOpen, setFlowerOpen] = useState(false);
    const [flower, setFlower] = useState<FlowerDetails>(BLANK);
    const [flowerError, setFlowerError] = useState<string | null>(null);
    const isDelivery = flower.fulfillment_type === 'delivery';
    const set = <K extends keyof FlowerDetails>(k: K, v: FlowerDetails[K]) =>
        setFlower((f) => ({ ...f, [k]: v }));

    // كل فتح جديد يبدأ من خطوة الدفع بمبلغ صفر
    useEffect(() => {
        if (open) {
            setStep('pay');
            setPaid('');
            setResult(null);
            setFlowerError(null);
            setMethod((m) => (METHODS.some((x) => x.value === m) ? m : METHODS[0]?.value ?? 'نقدي'));
        }
    }, [open]);

    const paidNum = Number(paid) || 0;
    const remaining = Math.max(0, displayTotal - paidNum);
    const change = Math.max(0, paidNum - displayTotal);

    const confirm = async () => {
        /*
         * الفحص هنا تسهيلٌ لا حراسة: الخادم يرفض التوصيل الناقص على أي حال.
         *
         * لكنّ البيع يمرّ بطابور عدم الاتصال، فرفضُ الخادم قد يصل بعد دقائق
         * والزبون قد مضى. فيُقال للكاشير الآن، وهو أمام الشاشة.
         */
        if (isDelivery && !(flower.recipient_name.trim() && flower.recipient_phone.trim() && flower.delivery_address.trim())) {
            setFlowerOpen(true);
            setFlowerError(t('طلب التوصيل يحتاج اسم المستلِم ورقمه وعنوانه.'));
            return;
        }
        setFlowerError(null);

        setBusy(true);
        try {
            // المفاتيح الفارغة لا تُرسل: الخادم يقرأ الفراغ قيمةً تُكتب
            const details = Object.fromEntries(
                Object.entries(flower).filter(([, v]) => (typeof v === 'boolean' ? v : String(v).trim() !== '')),
            );
            const res = await onCheckout(method, details);
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

                        {/*
                            تفاصيل طلب الورد — داخل نافذة الدفع نفسها لا في خطوةٍ ثانية.
                            التدفّق يبقى: أضف الأصناف ← ادفع. والقسم مطويّ فلا يراه
                            من يبيع باقةً من المنضدة.
                        */}
                        {orderOptions && (
                            <div className="rounded-xl border border-gray-200">
                                <button
                                    type="button"
                                    onClick={() => setFlowerOpen((o) => !o)}
                                    className="flex w-full items-center justify-between px-3 py-2.5 text-sm font-medium text-[#111]"
                                >
                                    <span>{t('تفاصيل الطلب (اختياري)')}</span>
                                    <ChevronDown className={cn('size-4 text-gray-400 transition-transform', flowerOpen && 'rotate-180')} />
                                </button>

                                {flowerOpen && (
                                    <div className="space-y-3 border-t border-gray-200 px-3 py-3">
                                        {flowerError && (
                                            <p className="text-[12px] text-[#b91c1c]">{flowerError}</p>
                                        )}

                                        <div className="grid grid-cols-2 gap-3">
                                            <Field label="نوع التنفيذ">
                                                <Select
                                                    placeholder="—"
                                                    options={orderOptions.fulfillments}
                                                    value={flower.fulfillment_type}
                                                    onChange={(e) => set('fulfillment_type', e.target.value)}
                                                />
                                            </Field>
                                            <Field label="موعد التسليم">
                                                <Input
                                                    type="datetime-local"
                                                    value={flower.scheduled_for}
                                                    onChange={(e) => set('scheduled_for', e.target.value)}
                                                />
                                            </Field>
                                        </div>

                                        <div className="grid grid-cols-2 gap-3">
                                            <Field label="اسم المستلِم" required={isDelivery}>
                                                <Input
                                                    value={flower.recipient_name}
                                                    onChange={(e) => set('recipient_name', e.target.value)}
                                                />
                                            </Field>
                                            <Field label="هاتف المستلِم" required={isDelivery}>
                                                <Input
                                                    inputMode="tel"
                                                    value={flower.recipient_phone}
                                                    onChange={(e) => set('recipient_phone', e.target.value)}
                                                />
                                            </Field>
                                        </div>

                                        {/* حقول التوصيل تظهر عند التوصيل وحده — الاستلام لا يُسأل عن عنوان */}
                                        {isDelivery && (
                                            <>
                                                <Field label="عنوان التوصيل" required>
                                                    <Input
                                                        value={flower.delivery_address}
                                                        onChange={(e) => set('delivery_address', e.target.value)}
                                                    />
                                                </Field>
                                                <Field label="تعليمات التوصيل">
                                                    <Input
                                                        value={flower.delivery_notes}
                                                        onChange={(e) => set('delivery_notes', e.target.value)}
                                                    />
                                                </Field>
                                            </>
                                        )}

                                        <Field label="المناسبة">
                                            <Select
                                                placeholder="—"
                                                options={orderOptions.occasions}
                                                value={flower.occasion_type}
                                                onChange={(e) => set('occasion_type', e.target.value)}
                                            />
                                        </Field>

                                        <Field
                                            label="نصّ البطاقة"
                                            hint={`${flower.card_message.length}/${orderOptions.cardMax}`}
                                        >
                                            <textarea
                                                rows={2}
                                                maxLength={orderOptions.cardMax}
                                                value={flower.card_message}
                                                onChange={(e) => set('card_message', e.target.value)}
                                                className="w-full rounded-[10px] border border-[var(--ui-border,#e8e8e8)] bg-white px-3 py-2 text-sm transition-[border-color,box-shadow] focus:border-[#d1d5db] focus:shadow-[0_0_0_3px_rgba(0,0,0,0.05)] focus:outline-none"
                                            />
                                        </Field>

                                        <div className="grid grid-cols-2 gap-3">
                                            <Field label="اسم المُهدي">
                                                <Input
                                                    value={flower.sender_name}
                                                    onChange={(e) => set('sender_name', e.target.value)}
                                                />
                                            </Field>
                                            <Field label="إخفاء المُهدي" hint="لا يظهر للمستلِم">
                                                <label className="flex h-9 items-center gap-2 text-sm text-[#4b4b4b]">
                                                    <input
                                                        type="checkbox"
                                                        checked={flower.hide_sender}
                                                        onChange={(e) => set('hide_sender', e.target.checked)}
                                                        className="size-4 accent-[#6d28d9]"
                                                    />
                                                    {t('إخفاء')}
                                                </label>
                                            </Field>
                                        </div>

                                        <Field label="ملاحظات داخلية" hint="لا تُطبع للزبون">
                                            <Input
                                                value={flower.internal_notes}
                                                onChange={(e) => set('internal_notes', e.target.value)}
                                            />
                                        </Field>
                                    </div>
                                )}
                            </div>
                        )}

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
                                    setFlower(BLANK);
                                    setFlowerOpen(false);
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
