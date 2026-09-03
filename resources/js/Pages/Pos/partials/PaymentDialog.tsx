import { useEffect, useRef, useState } from 'react';
import { usePage } from '@inertiajs/react';
import { Banknote, Check, CheckCircle, ChevronDown, CreditCard, Landmark, Plus, Printer, X } from 'lucide-react';
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
    /*
     * لا وسيلةَ مختارةً حتى تُختار.
     *
     * كانت أوّل المأذون محدَّدةً سلفًا، فتمرّ بيعةُ البطاقة مقيَّدةً «نقدي»
     * لأنّ الكاشير ضغط «تأكيد الدفع» ولم ينتبه إلى ما فوقه. وأثرُ ذلك في
     * الدرج: إقفال الوردية يطلب مالًا لم يدخل الصندوق.
     */
    const [method, setMethod] = useState('');
    const [methodError, setMethodError] = useState(false);
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
    /*
     * القسم يُنزَّل إليه عند فتحه.
     *
     * فتحُه يُضيف أحد عشر حقلًا تحت الزرّ مباشرة، فيبقى الكاشير ينظر إلى
     * موضع الزرّ ولا يرى أنّ شيئًا ظهر — أو يراه ولا يعرف أين انتهى.
     */
    const flowerRef = useRef<HTMLDivElement>(null);

    /*
     * المناسبات نسخةٌ محليّة لا خاصيّةٌ مقروءة.
     *
     * ما يضيفه الكاشير يجب أن يظهر في القائمة في اللحظة نفسها، وخصائص
     * الصفحة لا تتغيّر إلا بإعادة تحميلها — وهو ما لا يجوز وسط بيعة.
     */
    const [occasions, setOccasions] = useState(orderOptions?.occasions ?? []);
    const [addingOccasion, setAddingOccasion] = useState(false);
    const [newOccasion, setNewOccasion] = useState('');
    const [occasionBusy, setOccasionBusy] = useState(false);
    const [occasionError, setOccasionError] = useState<string | null>(null);
    const isDelivery = flower.fulfillment_type === 'delivery';
    /** طلبٌ يُجهَّز: قيل عنه موعدٌ أو نوع تنفيذ — فيصير الباقي إلزامًا */
    const scheduling = !!(flower.scheduled_for.trim() || flower.fulfillment_type.trim());
    const namedCustomer = !!customer && customer.trim() !== '' && customer.trim() !== 'عميل نقدي';
    const set = <K extends keyof FlowerDetails>(k: K, v: FlowerDetails[K]) =>
        setFlower((f) => ({ ...f, [k]: v }));

    /*
     * تُغلق النافذة بعد بيعةٍ تمّت فتبدأ التالية نظيفة — أيًّا كان سبيلُ
     * الإغلاق.
     *
     * كان التنظيف معلّقًا بزرّ «طلب جديد» وحده: من أغلق بالضغط خارج النافذة
     * أو بمفتاح الهروب يعود إلى الشاشة وفيها سلّةُ البيعة التي دفعها تَوًّا
     * واسمُ زبونها — فيضيف صنفًا فوقها ويبيع الأصناف مرّتين.
     */
    const closeTo = (next: boolean) => {
        if (!next && step === 'success') {
            onNewOrder();
            setFlower(BLANK);
            setFlowerOpen(false);
        }

        onOpenChange(next);
    };

    // كل فتح جديد يبدأ من خطوة الدفع بمبلغ صفر
    useEffect(() => {
        if (open) {
            setStep('pay');
            setPaid('');
            setResult(null);
            setFlowerError(null);
            setMethod('');
            setMethodError(false);
        }
    }, [open]);

    // خيارات الخادم تسبق المحليّة: إعادة تحميل الصفحة تعيد القائمة الصحيحة
    useEffect(() => {
        if (orderOptions?.occasions) setOccasions(orderOptions.occasions);
    }, [orderOptions?.occasions]);

    useEffect(() => {
        if (flowerOpen) {
            flowerRef.current?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    }, [flowerOpen]);

    /** إضافة مناسبةٍ للمتجر — تُحفظ ثم تُختار */
    const addOccasion = async () => {
        const label = newOccasion.trim();
        if (!label) return;
        setOccasionBusy(true);
        setOccasionError(null);
        try {
            const res = await fetch(route('pos.occasions.store'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': decodeURIComponent(
                        document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '',
                    ),
                },
                body: JSON.stringify({ label }),
            });
            const body = await res.json();
            if (!res.ok || !body.ok) {
                setOccasionError(body?.message ?? body?.errors?.label?.[0] ?? t('تعذّرت إضافة المناسبة.'));
                return;
            }
            setOccasions(body.options);
            set('occasion_type', body.value);
            setNewOccasion('');
            setAddingOccasion(false);
        } catch {
            // بلا اتصال: المناسبة إعدادُ متجرٍ لا بيعة، فلا تدخل طابور الرفع
            setOccasionError(t('لا يوجد اتصال — تُضاف المناسبة عند عودته.'));
        } finally {
            setOccasionBusy(false);
        }
    };

    const paidNum = Number(paid) || 0;
    const remaining = Math.max(0, displayTotal - paidNum);
    const change = Math.max(0, paidNum - displayTotal);

    const confirm = async () => {
        /*
         * الوسيلة أوّل ما يُسأل عنه: هي التي تفرّق بين ما يدخل الدرج وما لا
         * يدخله، والخادم يرفض بدونها على أيّ حال — فيُقال هنا قبل أن يُرفع.
         */
        if (!METHODS.some((m) => m.value === method)) {
            setMethodError(true);
            return;
        }
        setMethodError(false);

        /*
         * الفحص هنا تسهيلٌ لا حراسة: الخادم يرفض التوصيل الناقص على أي حال.
         *
         * لكنّ البيع يمرّ بطابور عدم الاتصال، فرفضُ الخادم قد يصل بعد دقائق
         * والزبون قد مضى. فيُقال للكاشير الآن، وهو أمام الشاشة.
         */
        /*
         * طلبٌ له موعدٌ طلبٌ يذهب إلى لوحة التجهيز — وبطاقته لا تُقرأ ناقصة.
         *
         * من يقف عند الطاولة يسأل: لمن؟ ومتى؟ وإلى أين؟ فبطاقةٌ تقول «عميل
         * نقدي» لعشرة طلباتٍ في يومٍ واحد لا تُسلَّم لأحد. والشرط معلَّقٌ
         * بالموعد وحده: بيعةُ المنضدة لا موعد لها ولا تدخل اللوحة أصلًا.
         */
        if (scheduling) {
            if (!flower.fulfillment_type.trim()) {
                setFlowerOpen(true);
                setFlowerError(t('حدّد نوع التنفيذ: توصيل أو استلام من المحل.'));

                return;
            }
            if (!flower.scheduled_for.trim()) {
                setFlowerOpen(true);
                setFlowerError(t('موعد التسليم مطلوب للطلبات التي تُجهَّز.'));

                return;
            }
            if (!namedCustomer) {
                setFlowerOpen(true);
                setFlowerError(t('اختر العميل أوّلًا — الطلب الذي يُجهَّز لا يُسجَّل باسم «عميل نقدي».'));

                return;
            }
        }

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
        <Dialog open={open} onOpenChange={closeTo}>
            {/*
                النافذة عمودٌ لا كتلةٌ حرّة الطول.

                كانت بلا سقف، ففتحُ «تفاصيل الطلب» يمدّها فوق الشاشة وتحتها:
                العنوان يخرج من أعلاها وزرّ «تأكيد الدفع» من أسفلها، ولا شيء
                يُمرَّر لأنّ النافذة نفسها هي ما تجاوز الشاشة لا ما فيها.
            */}
            <DialogContent className="flex max-w-lg flex-col">
                <DialogHeader className="shrink-0">
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
                    <div className="flex min-h-0 flex-1 flex-col">
                    <div className="min-h-0 flex-1 space-y-4 overflow-y-auto overscroll-contain px-5 pb-4">
                        <div className="rounded-2xl bg-gray-100 p-4 text-center">
                            <p className="text-sm text-[#111]">{t('الإجمالي المطلوب')}</p>
                            <p className="mt-1 text-3xl font-extrabold text-[#111]">{money(total)}</p>
                        </div>

                        <div>
                            <Label htmlFor="paid" className="mb-1.5">{t('المبلغ المدفوع')}</Label>
                            <Input
                                id="paid"
                                inputMode="decimal"
                                enterKeyHint="done"
                                autoComplete="off"
                                value={paid}
                                onChange={(e) => setPaid(e.target.value)}
                                placeholder="0.000"
                                className="h-12 text-lg font-bold pointer-coarse:h-14"
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
                            <Label className="mb-1.5" required>{t('وسيلة الدفع')}</Label>
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
                                            onClick={() => { setMethod(m.value); setMethodError(false); }}
                                            className={cn(
                                                'flex flex-col items-center gap-1 rounded-full border py-2.5 text-xs font-medium transition-colors pointer-coarse:py-3.5',
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
                            {methodError && (
                                <p className="mt-1.5 text-[12px] text-[#b91c1c]">
                                    {t('اختر وسيلة الدفع.')}
                                </p>
                            )}
                        </div>

                        {/*
                            تفاصيل طلب الورد — داخل نافذة الدفع نفسها لا في خطوةٍ ثانية.
                            التدفّق يبقى: أضف الأصناف ← ادفع. والقسم مطويّ فلا يراه
                            من يبيع باقةً من المنضدة.
                        */}
                        {orderOptions && (
                            <div ref={flowerRef} className="rounded-xl border border-gray-200 scroll-mt-2">
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

                                        {/* يُقال قبل الحفظ لا بعده: تغيير العميل
                                            بابُه فوق السلّة، لا داخل هذا الحوار */}
                                        {scheduling && !namedCustomer && (
                                            <p className="text-[12px] text-[#9a3412]">
                                                {t('اختر العميل من أعلى السلّة — اسمه يظهر على بطاقة التجهيز.')}
                                            </p>
                                        )}

                                        <div className="grid grid-cols-2 gap-3">
                                            <Field label="نوع التنفيذ" required={scheduling}>
                                                <Select
                                                    placeholder="—"
                                                    options={orderOptions.fulfillments}
                                                    value={flower.fulfillment_type}
                                                    onChange={(e) => set('fulfillment_type', e.target.value)}
                                                />
                                            </Field>
                                            <Field label="موعد التسليم" required={scheduling}>
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

                                        {/* المناسبة — وما ليس في القائمة يُضاف إليها هنا لا في الإعدادات */}
                                        <Field label="المناسبة" error={occasionError ?? undefined}>
                                            {addingOccasion ? (
                                                <div className="flex items-center gap-2">
                                                    <Input
                                                        autoFocus
                                                        className="flex-1"
                                                        value={newOccasion}
                                                        maxLength={40}
                                                        placeholder={t('اسم المناسبة')}
                                                        onChange={(e) => setNewOccasion(e.target.value)}
                                                        onKeyDown={(e) => {
                                                            if (e.key === 'Enter') { e.preventDefault(); addOccasion(); }
                                                            if (e.key === 'Escape') { setAddingOccasion(false); setOccasionError(null); }
                                                        }}
                                                    />
                                                    <Button
                                                        type="button"
                                                        size="icon"
                                                        variant="outline"
                                                        className="shrink-0"
                                                        disabled={occasionBusy || !newOccasion.trim()}
                                                        onClick={addOccasion}
                                                        aria-label={t('حفظ المناسبة')}
                                                    >
                                                        <Check className="size-4" />
                                                    </Button>
                                                    <Button
                                                        type="button"
                                                        size="icon"
                                                        variant="ghost"
                                                        className="shrink-0"
                                                        onClick={() => { setAddingOccasion(false); setNewOccasion(''); setOccasionError(null); }}
                                                        aria-label={t('إلغاء')}
                                                    >
                                                        <X className="size-4" />
                                                    </Button>
                                                </div>
                                            ) : (
                                                <div className="flex items-center gap-2">
                                                    <Select
                                                        className="flex-1"
                                                        placeholder="—"
                                                        options={occasions}
                                                        value={flower.occasion_type}
                                                        onChange={(e) => set('occasion_type', e.target.value)}
                                                    />
                                                    <Button
                                                        type="button"
                                                        size="icon"
                                                        variant="outline"
                                                        className="shrink-0"
                                                        onClick={() => { setAddingOccasion(true); setOccasionError(null); }}
                                                        aria-label={t('إضافة مناسبة')}
                                                        title={t('إضافة مناسبة')}
                                                    >
                                                        <Plus className="size-4" />
                                                    </Button>
                                                </div>
                                            )}
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

                    </div>

                    {/* زرّ الدفع خارج المجرى: لا يُمرَّر بعيدًا مهما طال ما فوقه */}
                    <div className="shrink-0 border-t border-gray-100 px-5 pb-5 pt-4">
                        <Button variant="success" size="lg" className="w-full rounded-full pointer-coarse:h-14 pointer-coarse:text-base" disabled={busy} onClick={confirm}>
                            {busy ? '…' : t('تأكيد الدفع')}
                        </Button>
                    </div>
                    </div>
                ) : (
                    <div className="min-h-0 flex-1 space-y-4 overflow-y-auto overscroll-contain px-5 pb-5 text-center">
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
                                /* الإغلاق وحده — و`closeTo` تُنظّف لكلّ سبيل */
                                onClick={() => closeTo(false)}
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
