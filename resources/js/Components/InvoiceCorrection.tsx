import { type FormEvent } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import { Pencil, Trash2 } from 'lucide-react';
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

export function CorrectItemDialog({
    url,
    item,
    onClose,
}: {
    url: string;
    item: CorrectableItem;
    onClose: () => void;
}) {
    const t = useTranslate();
    const denied = useDenial();
    const form = useForm({ quantity: String(item.qty), reason: '' });

    const submit = (e: FormEvent) => {
        e.preventDefault();
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
