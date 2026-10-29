import { Card } from '@/Components/ui/card';
import Field, { Select } from '@/Components/Field';
import { Input, Textarea } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * «التجهيز والتسليم» — قسمُ فاتورة العميل لمن فُتح له التجهيزُ بعد السداد.
 *
 * ═══ ما يقوله ═══
 *
 * الفاتورةُ ورقةٌ ماليّة لا تتغيّر بهذا القسم: لا بندَ ولا سعرَ ولا ضريبة.
 * وما يُكتب هنا يُقرأ في لوحة التجهيز **بعد** أن تُسدَّد الفاتورةُ كلُّها —
 * الفرعُ والموعدُ وإلى من، والبطاقةُ إن كانت هديّة.
 *
 * والتحقّقُ في الخادم (`CustomerInvoicePreparations::rules`): الشاشةُ تُخفي
 * ما لا يلزم، ولا تقرّر ما يلزم. والأخطاءُ بمفاتيحها `preparation.*`.
 *
 * ومكوّنٌ واحدٌ للشاشتين — الإنشاءُ وصفحةُ الفاتورة — فلا يفترق نموذجان.
 */

export interface PreparationForm {
    enabled: boolean;
    fulfillment_type: '' | 'delivery' | 'pickup';
    branch_id: string;
    scheduled_for: string;
    recipient_name: string;
    recipient_phone: string;
    delivery_address: string;
    delivery_notes: string;
    internal_notes: string;
    is_gift: boolean;
    occasion_type: string;
    occasion_text: string;
    card_message: string;
    sender_name: string;
    hide_sender: boolean;
    card_identity_confirmed: boolean;
}

export interface PreparationOptions {
    branches: { id: number; name: string }[];
    occasions: { value: string; label: string }[];
}

export const EMPTY_PREPARATION: PreparationForm = {
    enabled: false,
    fulfillment_type: '',
    branch_id: '',
    scheduled_for: '',
    recipient_name: '',
    recipient_phone: '',
    delivery_address: '',
    delivery_notes: '',
    internal_notes: '',
    is_gift: false,
    occasion_type: '',
    occasion_text: '',
    card_message: '',
    sender_name: '',
    hide_sender: false,
    card_identity_confirmed: false,
};

/**
 * التجهيزُ كما يصل من الخادم (`CustomerInvoicePreparations::formData`) — شكلٌ
 * واحدٌ لصفحة الفاتورة ولوحة التجهيز.
 */
export type PreparationData = Omit<PreparationForm, 'enabled' | 'branch_id' | 'fulfillment_type'> & {
    branch_id: number | null;
    branch: string | null;
    fulfillment_type: 'delivery' | 'pickup';
};

/** النموذجُ مملوءًا بما حُفظ — والفارغُ نصٌّ فارغ لا `null` في حقلٍ يُكتب فيه */
export function preparationForm(data: PreparationData | null): PreparationForm {
    if (!data) return { ...EMPTY_PREPARATION, enabled: true };

    return {
        ...EMPTY_PREPARATION,
        ...Object.fromEntries(Object.entries(data).map(([k, v]) => [k, v ?? ''])),
        enabled: true,
        branch_id: data.branch_id ? String(data.branch_id) : '',
        fulfillment_type: data.fulfillment_type,
        is_gift: Boolean(data.is_gift),
        hide_sender: Boolean(data.hide_sender),
        card_identity_confirmed: Boolean(data.card_identity_confirmed),
    } as PreparationForm;
}

/** ما يُرسل — والمطفأُ لا يحمل غيرَ مفتاحه: حقلٌ مخفيٌّ لا يُرسل بقيمته القديمة */
export function preparationPayload(value: PreparationForm): Partial<PreparationForm> {
    if (!value.enabled) return { enabled: false };

    return {
        ...value,
        // والعنوانُ للتوصيل وحده — والخادمُ يُسقطه للاستلام أيضًا
        delivery_address: value.fulfillment_type === 'delivery' ? value.delivery_address : '',
        occasion_text: value.occasion_type === 'other' ? value.occasion_text : '',
    };
}

export default function CustomerInvoicePreparationFields({
    value,
    onChange,
    options,
    errors,
    toggleable = true,
    className,
}: {
    value: PreparationForm;
    onChange: (patch: Partial<PreparationForm>) => void;
    options: PreparationOptions;
    /** أخطاءُ الخادم بمفاتيحها — `preparation.branch_id` وأخواتها */
    errors: Record<string, string | undefined>;
    /** في صفحة فاتورةٍ قائمة القسمُ مطلوبٌ أصلًا — بلا مربّع «إرسال» */
    toggleable?: boolean;
    className?: string;
}) {
    const t = useTranslate();
    const err = (k: string) => errors[`preparation.${k}`];
    const delivery = value.fulfillment_type === 'delivery';
    const pickup = value.fulfillment_type === 'pickup';
    const on = !toggleable || value.enabled;

    return (
        <Card className={cn('p-5', className)} data-testid="invoice-preparation">
            <div className="mb-3">
                <h2 className="text-[15px] font-bold text-[#111]">{t('التجهيز والتسليم')}</h2>
                <p className="mt-1 text-[12px] text-[#9ca3af]">
                    {t('تظهر في لوحة التجهيز بعد سداد الفاتورة بالكامل — ولا تغيّر بنودها ولا مبالغها.')}
                </p>
            </div>

            {toggleable && (
                <label className="mb-3 flex cursor-pointer items-center gap-2 text-sm text-[#374151]">
                    <input
                        type="checkbox"
                        className="size-4 accent-[#6d28d9]"
                        checked={value.enabled}
                        onChange={(e) => onChange({ enabled: e.target.checked })}
                        data-testid="preparation-enabled"
                    />
                    {t('إرسال إلى لوحة التجهيز بعد السداد')}
                </label>
            )}

            {errors.preparation && <p className="mb-2 text-[12px] text-[#b91c1c]">{errors.preparation}</p>}

            {on && (
                <div className="space-y-4">
                    <Field label="نوع التسليم" required error={err('fulfillment_type')}>
                        <div className="grid grid-cols-2 gap-2" role="radiogroup">
                            {(['delivery', 'pickup'] as const).map((kind) => (
                                <button
                                    key={kind}
                                    type="button"
                                    role="radio"
                                    aria-checked={value.fulfillment_type === kind}
                                    onClick={() => onChange({ fulfillment_type: kind })}
                                    className={cn(
                                        'rounded-[10px] border px-3 py-2 text-sm transition-colors',
                                        value.fulfillment_type === kind
                                            ? 'border-[#6d28d9] bg-[#f5f3ff] font-bold text-[#4c1d95]'
                                            : 'border-[var(--ui-border,#e8e8e8)] text-[#374151]',
                                    )}
                                >
                                    {t(kind === 'delivery' ? 'توصيل' : 'استلام من المحل')}
                                </button>
                            ))}
                        </div>
                    </Field>

                    {(delivery || pickup) && (
                        <>
                            <div className="grid gap-3 sm:grid-cols-2">
                                <Field label={delivery ? 'فرع التجهيز' : 'فرع الاستلام'} required error={err('branch_id')} htmlFor="prep-branch">
                                    <Select
                                        id="prep-branch"
                                        value={value.branch_id}
                                        placeholder={t('اختر الفرع')}
                                        options={options.branches.map((b) => ({ value: String(b.id), label: b.name }))}
                                        onChange={(e) => onChange({ branch_id: e.target.value })}
                                    />
                                </Field>
                                <Field label={delivery ? 'موعد التسليم' : 'موعد الاستلام'} required error={err('scheduled_for')} htmlFor="prep-when">
                                    <Input
                                        id="prep-when"
                                        type="datetime-local"
                                        value={value.scheduled_for}
                                        onChange={(e) => onChange({ scheduled_for: e.target.value })}
                                    />
                                </Field>
                                <Field label={delivery ? 'اسم المستلم' : 'من يستلم'} required error={err('recipient_name')} htmlFor="prep-name">
                                    <Input
                                        id="prep-name"
                                        dir="auto"
                                        maxLength={120}
                                        value={value.recipient_name}
                                        onChange={(e) => onChange({ recipient_name: e.target.value })}
                                    />
                                </Field>
                                <Field label={delivery ? 'هاتف المستلم' : 'هاتف التواصل'} required error={err('recipient_phone')} htmlFor="prep-phone">
                                    <Input
                                        id="prep-phone"
                                        type="tel"
                                        dir="ltr"
                                        maxLength={32}
                                        value={value.recipient_phone}
                                        onChange={(e) => onChange({ recipient_phone: e.target.value })}
                                    />
                                </Field>
                            </div>

                            {delivery && (
                                <Field label="عنوان التوصيل" required error={err('delivery_address')} htmlFor="prep-address">
                                    <Textarea
                                        id="prep-address"
                                        dir="auto"
                                        maxLength={500}
                                        value={value.delivery_address}
                                        onChange={(e) => onChange({ delivery_address: e.target.value })}
                                        className="min-h-20"
                                    />
                                </Field>
                            )}

                            <Field
                                label={delivery ? 'تعليمات التوصيل' : 'تعليمات الاستلام'}
                                hint="تُطبع في سند التسليم ويقرؤها المستلم."
                                error={err('delivery_notes')}
                                htmlFor="prep-notes"
                            >
                                <Textarea
                                    id="prep-notes"
                                    dir="auto"
                                    maxLength={1000}
                                    value={value.delivery_notes}
                                    onChange={(e) => onChange({ delivery_notes: e.target.value })}
                                    className="min-h-16"
                                />
                            </Field>

                            <Field
                                label="ملاحظات التجهيز"
                                hint="لمن يجهّز وحده — لا تُطبع في سند التسليم."
                                error={err('internal_notes')}
                                htmlFor="prep-internal"
                            >
                                <Textarea
                                    id="prep-internal"
                                    dir="auto"
                                    maxLength={2000}
                                    value={value.internal_notes}
                                    onChange={(e) => onChange({ internal_notes: e.target.value })}
                                    className="min-h-16"
                                />
                            </Field>

                            <label className="flex cursor-pointer items-center gap-2 text-sm text-[#374151]">
                                <input
                                    type="checkbox"
                                    className="size-4 accent-[#6d28d9]"
                                    checked={value.is_gift}
                                    onChange={(e) => onChange({ is_gift: e.target.checked })}
                                />
                                {t('هدية وبطاقة')}
                            </label>

                            {value.is_gift && (
                                <div className="space-y-3 rounded-[10px] border border-[var(--ui-border,#e8e8e8)] p-3">
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        <Field label="المناسبة" error={err('occasion_type')} htmlFor="prep-occasion">
                                            <Select
                                                id="prep-occasion"
                                                value={value.occasion_type}
                                                placeholder={t('بلا مناسبة')}
                                                options={options.occasions}
                                                onChange={(e) => onChange({ occasion_type: e.target.value })}
                                            />
                                        </Field>
                                        {value.occasion_type === 'other' && (
                                            <Field label="المناسبة" error={err('occasion_text')} htmlFor="prep-occasion-text">
                                                <Input
                                                    id="prep-occasion-text"
                                                    dir="auto"
                                                    maxLength={40}
                                                    value={value.occasion_text}
                                                    onChange={(e) => onChange({ occasion_text: e.target.value })}
                                                />
                                            </Field>
                                        )}
                                        <Field label="اسم المرسل" error={err('sender_name')} htmlFor="prep-sender">
                                            <Input
                                                id="prep-sender"
                                                dir="auto"
                                                maxLength={120}
                                                value={value.sender_name}
                                                onChange={(e) => onChange({ sender_name: e.target.value })}
                                            />
                                        </Field>
                                    </div>
                                    <Field label="رسالة البطاقة" error={err('card_message')} htmlFor="prep-card">
                                        <Textarea
                                            id="prep-card"
                                            dir="auto"
                                            maxLength={500}
                                            value={value.card_message}
                                            onChange={(e) => onChange({ card_message: e.target.value, card_identity_confirmed: false })}
                                            className="min-h-20"
                                        />
                                    </Field>
                                    <label className="flex cursor-pointer items-center gap-2 text-sm text-[#374151]">
                                        <input
                                            type="checkbox"
                                            className="size-4 accent-[#6d28d9]"
                                            checked={value.hide_sender}
                                            onChange={(e) => onChange({ hide_sender: e.target.checked, card_identity_confirmed: false })}
                                        />
                                        {t('إخفاء اسم المرسل عن المستلم')}
                                    </label>
                                    {/*
                                        ورسالةٌ تكشف اسمَ من أخفى نفسَه — يسأل الخادمُ عنها
                                        (`CardIdentity::reveals`)، والتأكيدُ هنا بعد سؤاله.
                                    */}
                                    {value.hide_sender && (err('card_message') || value.card_identity_confirmed) && (
                                        <label className="flex cursor-pointer items-start gap-2 text-[13px] text-[#92400e]">
                                            <input
                                                type="checkbox"
                                                className="mt-0.5 size-4 accent-[#b45309]"
                                                checked={value.card_identity_confirmed}
                                                onChange={(e) => onChange({ card_identity_confirmed: e.target.checked })}
                                                data-testid="card-identity-confirm"
                                            />
                                            {t('أؤكد طباعة الرسالة كما هي ولو ذكرت المرسل')}
                                        </label>
                                    )}
                                </div>
                            )}
                        </>
                    )}
                </div>
            )}
        </Card>
    );
}
