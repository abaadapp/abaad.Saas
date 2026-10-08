import { type FormEvent } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import { Pencil, Plus, Repeat, Trash2, Wallet } from 'lucide-react';
import Field, { Select } from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';

/**
 * تصحيحُ فاتورةٍ بيعت — نافذتاه وسطرُ أثره، في موضعٍ واحد.
 *
 * ويقرؤهما شاشتان: صندوقُ البيع وشاشةُ المبيعات. وكانتا ستُكتبان مرّتين —
 * ونسختان تفترقان يومًا: تُشدَّد الرسالة في إحداهما ويبقى الكاشير يقرأ
 * القديمة، أو يُضاف حقلٌ هنا ولا يُضاف هناك.
 *
 * والمسارُ يُمرَّر مبنيًّا (`url`) لا اسمًا يُركَّب هنا: البابان مختلفان —
 * `pos.orders.*` يتبع صلاحية نقطة البيع، و`admin.orders.*` يتبع المبيعات —
 * والمكوّنُ لا يعرف من أين نُودي، ولا ينبغي أن يعرف.
 */

/** بندٌ يُصحَّح — أقلُّ ما تحتاجه النافذة منه */
export interface CorrectableItem {
    id: number;
    name: string;
    qty: number;
}

/** أثرُ تصحيحٍ وقع على فاتورة — يُعرض ولا يُخفى */
export interface OrderEditRecord {
    kind: string;
    subject: string;
    qty_before: number | null;
    qty_after: number | null;
    value_before: string | null;
    value_after: string | null;
    total_before: number;
    total_after: number;
    reason: string;
    by: string;
    at: string;
}

/**
 * سطرُ التصحيح كما يُقرأ.
 *
 * نوعان في قائمةٍ واحدة تحت الفاتورة: بندٌ تغيّرت كميّته أو حُذف، ووسيلة
 * دفعٍ صُحّحت. وقائمتان منفصلتان كانتا ستجعلان القارئ يجمعهما بعينه ليعرف
 * ما جرى على فاتورةٍ واحدة.
 */
export function correctionLabel(e: OrderEditRecord, t: (s: string) => string): string {
    if (e.kind === 'وسيلة دفع') {
        return `${t('وسيلة الدفع')}: ${t(e.value_before ?? '')} ← ${t(e.value_after ?? '')}`;
    }

    // وما بعد الإصدار لنشاطٍ فُتحت له الميزة — اسمُ العملية بلغة الشاشة، والنصُّ كما كُتب
    if (e.kind === 'صنف مضاف') {
        return `${t('أُضيف')} «${e.value_after ?? e.subject}»`;
    }
    if (e.kind === 'صنف مستبدل') {
        return `${t('استُبدل')} «${e.value_before ?? ''}» ← «${e.value_after ?? ''}»`;
    }
    if (e.kind === 'ملاحظة منتج') {
        return `${t('ملاحظة المنتج')} «${e.subject}»: ${e.value_before ?? '—'} ← ${e.value_after ?? '—'}`;
    }
    if (e.kind === 'تحصيل متبقّي') {
        return `${t('تحصيل المتبقّي')}: ${e.value_before ?? ''} — ${t(e.value_after ?? '')}`;
    }
    if (e.kind === 'ردّ فرق') {
        return `${t('ردّ الفرق للعميل')}: ${e.value_before ?? ''} — ${t(e.value_after ?? '')}`;
    }

    return e.qty_after === 0
        ? `${t('حُذف')} «${e.subject}»`
        : `«${e.subject}» ${e.qty_before} ← ${e.qty_after}`;
}

/**
 * ردُّ المنع — يُقرأ من أخطاء الصفحة لا من حقول النموذج.
 *
 * `OrderEditController::refuse` يكتبه تحت مفتاح `permission` عمدًا: خلطُه
 * بخطأ حقلٍ يجعل رسالة المنع تظهر تحت خانة السبب كأنّ ما كُتب فيها هو
 * العطب. وكان لا يُعرض في أيّ شاشة — فمن رُفع إذنُه يضغط «حفظ» فلا يُحفظ
 * شيءٌ ولا يُقال له لماذا.
 */
function useDenial(): string | undefined {
    const errors = usePage().props.errors as Record<string, string> | undefined;

    return errors?.permission;
}

/** نصُّ المنع مرسومًا — أحمرُ فوق الأزرار لا تحت حقل */
function Denial({ text }: { text?: string }) {
    if (!text) {
        return null;
    }

    return (
        <p className="rounded-[10px] bg-[#fef2f2] px-3 py-2 text-[12px] text-[#b91c1c]">{text}</p>
    );
}

/** التحصيلُ الآن والردُّ الآن يسألان: بأيّ وسيلة؟ — والبقاءُ على العميل لا يسأل */
const settleAsksMethod = (settle: string) => settle === 'collected' || settle === 'refunded';

const SETTLE_OPTIONS = {
    due: 'زاد — يبقى على العميل حتى يُحصَّل',
    collected: 'زاد — حُصِّل الفرق الآن',
    refunded: 'نقص — رُدّ الفرق للعميل الآن',
} as const;

type SettleKey = keyof typeof SETTLE_OPTIONS;

/** ما يُعرض من الخيارات: اتّجاهُ الفرق يحصرها، وبلا اتّجاهٍ تُعرض الثلاثة */
const settleKeys = (direction?: 'up' | 'down'): SettleKey[] =>
    direction === 'up' ? ['due', 'collected'] : direction === 'down' ? ['refunded'] : ['due', 'collected', 'refunded'];

/**
 * فرقُ الفاتورة المدفوعة ووسيلتُه — حقلان تقرؤهما الإضافةُ والاستبدالُ وتصحيحُ الكمّيّة.
 *
 * لا يُكتب الفرقُ مدفوعًا من تلقاء نفسه ولا يُشحن له شيء — يُسأل الموظّف
 * (`OrderCorrection::settleDifference`). والتحصيلُ أو الردُّ الآن بوسيلةٍ:
 * صفٌّ وقيدٌ مستقلّان، لا زيادةٌ في صفّ البيعة ولا نقصٌ منه.
 *
 * `direction` يحصر الخيارات حين يُعرف اتّجاهُ الفرق (تصحيحُ كمّيّة)، وبلا
 * اتّجاهٍ (صنفٌ يُختار ثمنُه بعدُ) تُعرض الثلاثة.
 */
function SettleFields({
    settle,
    method,
    methods,
    errors,
    direction,
    onChange,
}: {
    settle: string;
    method: string;
    methods: string[];
    errors: Record<string, string | undefined>;
    direction?: 'up' | 'down';
    onChange: (settle: string, method: string) => void;
}) {
    const t = useTranslate();
    const keys = settleKeys(direction);
    const methodLabel = settle === 'refunded' ? 'وسيلة الردّ' : 'وسيلة التحصيل';

    return (
        <>
            <Field label="فرق الفاتورة المدفوعة" hint="إن تغيّر الإجمالي — النظام لا يشحن البطاقة ولا يستردّ آليًّا" error={errors.settle}>
                <Select
                    name="settle"
                    value={keys.includes(settle as SettleKey) ? settle : ''}
                    onChange={(e) => onChange(e.target.value, method || methods[0] || '')}
                    placeholder="اختر"
                    options={keys.map((k) => ({ label: t(SETTLE_OPTIONS[k]), value: k }))}
                />
            </Field>

            {keys.includes(settle as SettleKey) && settleAsksMethod(settle) && (
                <Field label={methodLabel} required hint="تُكتب حركةً مستقلّة بيومها — لا يُشحن شيء ولا يُستردّ آليًّا" error={errors.payment_method}>
                    <Select
                        required
                        value={method}
                        onChange={(e) => onChange(settle, e.target.value)}
                        name="payment_method"
                        aria-label={t(methodLabel)}
                        options={methods.map((x) => ({ label: t(x === 'بطاقة' ? 'فيزا' : x), value: x }))}
                    />
                </Field>
            )}
        </>
    );
}

/**
 * تصحيحُ كمّيّة بند.
 *
 * و`settle` يُمرَّر لنشاطٍ فُتح له «تعديل أصناف الفاتورة» وفاتورتُه مدفوعة:
 * فرقُها يُسأل عنه كما في الإضافة — يبقى أو حُصِّل الآن (زيادة)، أو رُدّ
 * الآن (نقص)، بوسيلة. وسواه كما كان.
 */
export function CorrectItemDialog({
    url,
    item,
    settle,
    onClose,
}: {
    url: string;
    item: CorrectableItem;
    settle?: { methods: string[] };
    onClose: () => void;
}) {
    const t = useTranslate();
    const denied = useDenial();
    const form = useForm({ quantity: String(item.qty), reason: '', settle: '', payment_method: '' });
    const errors = form.errors as Record<string, string | undefined>;
    const qty = Number(form.data.quantity);
    const direction = qty > item.qty ? 'up' : qty < item.qty ? 'down' : undefined;

    const submit = (e: FormEvent) => {
        e.preventDefault();
        // وخيارٌ بقي من اتّجاهٍ سابق (زاد ثمّ نقص) لا يُرسل — يُسأل عنه الخادم من جديد
        const chosen = settle && direction && settleKeys(direction).includes(form.data.settle as SettleKey) ? form.data.settle : '';
        form.transform((d) => ({
            ...d,
            settle: chosen || null,
            payment_method: chosen && settleAsksMethod(chosen) ? d.payment_method : null,
        }));
        form.put(url, { preserveScroll: true, onSuccess: () => onClose() });
    };

    const removing = Number(form.data.quantity) === 0;

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>
                        {t('تصحيح')} «{item.name}»
                    </DialogTitle>
                </DialogHeader>

                <form onSubmit={submit} className="space-y-4 px-5 pb-5">
                    <Field
                        label="الكمية الصحيحة"
                        required
                        hint="صفرٌ يحذف البند من الفاتورة"
                        error={form.errors.quantity}
                    >
                        <Input
                            type="number"
                            min="0"
                            dir="ltr"
                            required
                            value={form.data.quantity}
                            onChange={(e) => form.setData('quantity', e.target.value)}
                        />
                    </Field>

                    {settle && direction && (
                        <SettleFields
                            settle={form.data.settle}
                            method={form.data.payment_method}
                            methods={settle.methods}
                            errors={errors}
                            direction={direction}
                            onChange={(next, payment_method) => form.setData({ ...form.data, settle: next, payment_method })}
                        />
                    )}

                    {/* السبب مطلوب: تصحيحٌ بلا سببٍ سطرٌ لا يُدقَّق */}
                    <Field
                        label="سبب التصحيح"
                        required
                        hint="يُقرأ في سجلّ الفاتورة — اكتب ما يفهمه غيرك"
                        error={form.errors.reason}
                    >
                        <Input
                            required
                            minLength={3}
                            placeholder={t('مثال: أدخلتُ الكمية خطأً')}
                            value={form.data.reason}
                            onChange={(e) => form.setData('reason', e.target.value)}
                        />
                    </Field>

                    {/* ومن مُنع عن التصحيح يُردّ بنصٍّ يقول ماذا يفعل — لا تحت خانة السبب */}
                    <Denial text={denied} />

                    <p className="rounded-[10px] bg-[#fffbeb] px-3 py-2 text-[12px] text-[#92400e]">
                        {t('يُعاد المخزون وتُحتسب الضريبة والنقاط من جديد، ويبقى هذا التصحيح مقيَّدًا باسمك في سجلّ الفاتورة.')}
                    </p>

                    <div className="flex justify-end gap-2 pt-1">
                        <Button type="button" variant="outline" onClick={onClose}>
                            {t('إلغاء')}
                        </Button>
                        <Button type="submit" loading={form.processing}>
                            {removing ? <Trash2 /> : <Pencil />}
                            {t(removing ? 'حذف البند' : 'حفظ التصحيح')}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export function CorrectPaymentDialog({
    url,
    current,
    methods,
    onClose,
}: {
    url: string;
    current: string;
    methods: string[];
    onClose: () => void;
}) {
    const t = useTranslate();
    const denied = useDenial();
    const form = useForm({ payment_method: current, reason: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.put(url, { preserveScroll: true, onSuccess: () => onClose() });
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>{t('تصحيح وسيلة الدفع')}</DialogTitle>
                </DialogHeader>

                <form onSubmit={submit} className="space-y-4 px-5 pb-5">
                    <Field label="الوسيلة الصحيحة" required error={form.errors.payment_method}>
                        <Select
                            required
                            value={form.data.payment_method}
                            onChange={(e) => form.setData('payment_method', e.target.value)}
                            options={methods.map((x) => ({ label: t(x === 'بطاقة' ? 'فيزا' : x), value: x }))}
                        />
                    </Field>

                    <Field
                        label="سبب التصحيح"
                        required
                        hint="يُقرأ في سجلّ الفاتورة — اكتب ما يفهمه غيرك"
                        error={form.errors.reason}
                    >
                        <Input
                            required
                            minLength={3}
                            placeholder={t('مثال: دفع بالبطاقة وسجّلتُها نقدًا')}
                            value={form.data.reason}
                            onChange={(e) => form.setData('reason', e.target.value)}
                        />
                    </Field>

                    <Denial text={denied} />

                    <p className="rounded-[10px] bg-[#fffbeb] px-3 py-2 text-[12px] text-[#92400e]">
                        {t('يتغيّر المتوقَّع في درج ورديتك المفتوحة فورًا. والورديات المقفلة تبقى على أرقامها — عدُّها وقع يومه.')}
                    </p>

                    <div className="flex justify-end gap-2 pt-1">
                        <Button type="button" variant="outline" onClick={onClose}>
                            {t('إلغاء')}
                        </Button>
                        <Button type="submit" loading={form.processing}>
                            <Pencil />
                            {t('حفظ التصحيح')}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/* ═══════════════ إضافةُ صنفٍ واستبدالُه وملاحظتُه وتحصيلُ المتبقّي ═══════════════ */

/** صنفٌ يُضاف إلى فاتورة — الأسعارُ للعرض، والخادمُ يسعّر من القاعدة */
export interface CatalogProduct {
    id: number;
    name: string;
    price: number;
    variants: { id: number; name: string; price: number }[];
    addons: { id: number; name: string; price: number }[];
}

/** ما يُفتح على شاشة الطلب — `null` لنشاطٍ لم تُفتح له الميزة (`NotesAndEdits::screen`) */
export interface LineEdit {
    lines: boolean;
    notes: boolean;
    catalog: CatalogProduct[];
}

/** خطأُ الخدمة تحت الحوار — سعرٌ أو مخزونٌ أو قيد */
function LineError({ text }: { text?: string }) {
    if (!text) {
        return null;
    }

    return (
        <p className="rounded-[10px] bg-[#fef2f2] px-3 py-2 text-[12px] text-[#b91c1c]" data-testid="line-error">
            {text}
        </p>
    );
}

/**
 * إضافةُ صنفٍ أو استبدالُه — صنفٌ ومقاسٌ وكمّيّةٌ وإضافاتٌ وملاحظةٌ وسبب.
 *
 * ولا سعرَ يُكتب هنا: يُعرض ثمنُ القاعدة للعلم، ويُسعَّر في الخادم
 * (`OrderCorrection::addLine`). وفاتورةٌ مدفوعة يُسأل فيها عن الفرق: يبقى
 * على العميل، أم حُصِّل الآن، أم رُدّ له الآن — والأخيران بوسيلةٍ يختارها
 * الموظّف، ولكلٍّ حركتُه وقيدُه. ولا شيءَ آليّ.
 */
export function LineDialog({
    url,
    title,
    catalog,
    paid,
    methods,
    replacing,
    format,
    onClose,
}: {
    url: string;
    title: string;
    catalog: CatalogProduct[];
    paid: boolean;
    methods: string[];
    replacing?: string;
    format: (v: number) => string;
    onClose: () => void;
}) {
    const t = useTranslate();
    const denied = useDenial();
    const form = useForm<{
        product_id: string;
        variant_id: string;
        qty: string;
        addons: { addon_id: number; qty: number }[];
        note: string;
        reason: string;
        settle: string;
        payment_method: string;
    }>({ product_id: '', variant_id: '', qty: '1', addons: [], note: '', reason: '', settle: '', payment_method: '' });

    const asksMethod = settleAsksMethod(form.data.settle);

    const product = catalog.find((p) => String(p.id) === form.data.product_id);
    const errors = form.errors as Record<string, string | undefined>;

    const pick = (id: string) => {
        const next = catalog.find((p) => String(p.id) === id);
        form.setData({
            ...form.data,
            product_id: id,
            variant_id: next?.variants[0] ? String(next.variants[0].id) : '',
            addons: (next?.addons ?? []).map((a) => ({ addon_id: a.id, qty: 0 })),
        });
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({
            ...d,
            addons: d.addons.filter((a) => a.qty > 0),
            settle: d.settle || null,
            payment_method: asksMethod ? d.payment_method : null,
        }));
        form.post(url, { preserveScroll: true, onSuccess: () => onClose() });
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                </DialogHeader>

                <form onSubmit={submit} className="space-y-4 px-5 pb-5" data-testid="line-dialog">
                    {replacing && (
                        <p className="rounded-[10px] bg-[#f5f3ff] px-3 py-2 text-[12px] text-[#5b21b6]">
                            {t('يُحذف من الفاتورة')}: «{replacing}» — {t('ويعود مخزونه إلى الرفّ')}
                        </p>
                    )}

                    <Field label="المنتج" required error={errors.product_id}>
                        <Select
                            required
                            value={form.data.product_id}
                            onChange={(e) => pick(e.target.value)}
                            placeholder="اختر المنتج"
                            options={catalog.map((p) => ({ label: `${p.name} — ${format(p.price)}`, value: String(p.id) }))}
                        />
                    </Field>

                    {product && product.variants.length > 0 && (
                        <Field label="المقاس" required error={errors.variant_id}>
                            <Select
                                required
                                value={form.data.variant_id}
                                onChange={(e) => form.setData('variant_id', e.target.value)}
                                options={product.variants.map((v) => ({ label: `${v.name} — ${format(v.price)}`, value: String(v.id) }))}
                            />
                        </Field>
                    )}

                    <Field label="الكمية" required error={errors.qty}>
                        <Input
                            type="number"
                            min="1"
                            dir="ltr"
                            required
                            value={form.data.qty}
                            onChange={(e) => form.setData('qty', e.target.value)}
                        />
                    </Field>

                    {product && product.addons.length > 0 && (
                        <fieldset className="space-y-2">
                            <legend className="mb-1 text-[13px] font-medium text-[#374151]">{t('الإضافات')}</legend>
                            {product.addons.map((a, i) => (
                                <label key={a.id} className="flex items-center justify-between gap-3 text-[13px]">
                                    <span>
                                        {a.name} <span className="text-[#6b7280]">— {format(a.price)}</span>
                                    </span>
                                    <Input
                                        type="number"
                                        min="0"
                                        dir="ltr"
                                        className="w-20"
                                        aria-label={a.name}
                                        value={String(form.data.addons[i]?.qty ?? 0)}
                                        onChange={(e) =>
                                            form.setData(
                                                'addons',
                                                form.data.addons.map((x, k) => (k === i ? { ...x, qty: Math.max(0, Number(e.target.value) || 0) } : x)),
                                            )
                                        }
                                    />
                                </label>
                            ))}
                        </fieldset>
                    )}

                    <Field label="ملاحظة المنتج" error={errors.note}>
                        <textarea
                            className="min-h-[72px] w-full rounded-[10px] border border-[#e5e7eb] px-3 py-2 text-sm"
                            maxLength={500}
                            dir="auto"
                            value={form.data.note}
                            onChange={(e) => form.setData('note', e.target.value)}
                        />
                    </Field>

                    {/*
                        والفرقُ في فاتورةٍ مدفوعة لا يُكتب مدفوعًا من تلقاء نفسه، ولا
                        يُشحن له شيء — يُسأل الموظّف (`OrderCorrection::settleDifference`).
                        والتحصيلُ أو الردُّ الآن بوسيلةٍ: صفٌّ وقيدٌ مستقلّان، لا زيادةٌ في صفّ البيعة.
                    */}
                    {paid && (
                        <SettleFields
                            settle={form.data.settle}
                            method={form.data.payment_method}
                            methods={methods}
                            errors={errors}
                            onChange={(settle, payment_method) => form.setData({ ...form.data, settle, payment_method })}
                        />
                    )}

                    <Field label="سبب التعديل" required hint="يُقرأ في سجلّ الفاتورة — اكتب ما يفهمه غيرك" error={form.errors.reason}>
                        <Input
                            required
                            minLength={3}
                            value={form.data.reason}
                            onChange={(e) => form.setData('reason', e.target.value)}
                        />
                    </Field>

                    <LineError text={errors.line} />
                    <Denial text={denied} />

                    <p className="rounded-[10px] bg-[#fffbeb] px-3 py-2 text-[12px] text-[#92400e]">
                        {t('السعر من النظام، ويُخصم المخزون وتُحتسب الضريبة والقيد والنقاط من جديد، ويبقى التعديل مقيَّدًا باسمك في سجلّ الفاتورة.')}
                    </p>

                    <div className="flex justify-end gap-2 pt-1">
                        <Button type="button" variant="outline" onClick={onClose}>
                            {t('إلغاء')}
                        </Button>
                        <Button type="submit" loading={form.processing} data-testid="line-submit">
                            {replacing ? <Repeat /> : <Plus />}
                            {t(replacing ? 'استبدال الصنف' : 'إضافة الصنف')}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * ملاحظةُ المنتج بعد الإصدار — نصٌّ وحده: لا مخزونَ ولا ضريبةَ ولا قيد.
 *
 * والموظّفُ يكتب بما شاء — «بالإنجليزيّة وحدها» قاعدةُ العميل في الموقع.
 */
export function NoteDialog({
    url,
    item,
    onClose,
}: {
    url: string;
    item: { name: string; note: string | null };
    onClose: () => void;
}) {
    const t = useTranslate();
    const denied = useDenial();
    const form = useForm({ note: item.note ?? '', reason: '' });
    const errors = form.errors as Record<string, string | undefined>;

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.put(url, { preserveScroll: true, onSuccess: () => onClose() });
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>
                        {t('ملاحظة المنتج')} — «{item.name}»
                    </DialogTitle>
                </DialogHeader>

                <form onSubmit={submit} className="space-y-4 px-5 pb-5" data-testid="note-dialog">
                    <Field label="ملاحظة المنتج" error={errors.note}>
                        <textarea
                            className="min-h-[88px] w-full rounded-[10px] border border-[#e5e7eb] px-3 py-2 text-sm"
                            maxLength={500}
                            dir="auto"
                            value={form.data.note}
                            onChange={(e) => form.setData('note', e.target.value)}
                        />
                    </Field>
                    <Field label="سبب التعديل" required error={form.errors.reason}>
                        <Input required minLength={3} value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} />
                    </Field>

                    <LineError text={errors.line} />
                    <Denial text={denied} />

                    <div className="flex justify-end gap-2 pt-1">
                        <Button type="button" variant="outline" onClick={onClose}>
                            {t('إلغاء')}
                        </Button>
                        <Button type="submit" loading={form.processing}>
                            <Pencil />
                            {t('حفظ الملاحظة')}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/** تحصيلُ ما بقي على فاتورةٍ مدفوعة — قيدٌ بيومه، لا إعادةُ كتابةٍ للبيعة */
export function CollectDialog({
    url,
    amount,
    methods,
    onClose,
}: {
    url: string;
    amount: string;
    methods: string[];
    onClose: () => void;
}) {
    const t = useTranslate();
    const denied = useDenial();
    const form = useForm({ payment_method: methods[0] ?? 'نقدي', reason: '' });
    const errors = form.errors as Record<string, string | undefined>;

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(url, { preserveScroll: true, onSuccess: () => onClose() });
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>
                        {t('تحصيل المتبقّي')} — {amount}
                    </DialogTitle>
                </DialogHeader>

                <form onSubmit={submit} className="space-y-4 px-5 pb-5" data-testid="collect-dialog">
                    <Field label="وسيلة الدفع" required error={form.errors.payment_method}>
                        <Select
                            required
                            value={form.data.payment_method}
                            onChange={(e) => form.setData('payment_method', e.target.value)}
                            options={methods.map((x) => ({ label: t(x === 'بطاقة' ? 'فيزا' : x), value: x }))}
                        />
                    </Field>
                    <Field label="سبب التعديل" required error={form.errors.reason}>
                        <Input required minLength={3} value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} />
                    </Field>

                    <LineError text={errors.line} />
                    <Denial text={denied} />

                    <div className="flex justify-end gap-2 pt-1">
                        <Button type="button" variant="outline" onClick={onClose}>
                            {t('إلغاء')}
                        </Button>
                        <Button type="submit" loading={form.processing}>
                            <Wallet />
                            {t('تسجيل التحصيل')}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
