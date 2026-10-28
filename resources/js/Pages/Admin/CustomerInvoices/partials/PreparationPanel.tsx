import { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { ClipboardList, Printer, Send, Trash2 } from 'lucide-react';
import CustomerInvoicePreparationFields, {
    preparationForm,
    preparationPayload,
    type PreparationData,
    type PreparationOptions,
} from '@/Components/CustomerInvoicePreparationFields';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { useTranslate } from '@/lib/i18n';

/**
 * تجهيزُ الفاتورة بعد سدادها — كما يُقرأ في صفحتها.
 *
 * ما طُلب (الفرعُ والموعدُ وإلى من)، وأين وصل: بانتظار السداد الكامل، أو في
 * لوحة التجهيز وحالُه هناك، وورقةُ الصندوق الداخليّة. ولمَ لم يظهر بعد —
 * بجملةٍ يقرؤها الخادم (`CustomerInvoicePreparations::blocker`).
 *
 * والأفعالُ بما يقوله الخادم: يُطلب لفاتورةٍ لم تُسدَّد، ويُعدَّل ما لم
 * يُسلَّم، ويُلغى ما لم يظهر، ويُرسل بيدِ من يملك التحصيل فاتورةً سُدّدت
 * بغير رابط. ولا شيءَ من هذا يمسّ بندًا ولا مبلغًا.
 */
export interface PreparationPanelData {
    enabled: boolean;
    attachable: boolean;
    editable: boolean;
    removable: boolean;
    activatable: boolean;
    paid: boolean;
    data: PreparationData | null;
    active: boolean;
    status: string | null;
    activated_at: string | null;
    activation_source: string | null;
    activation_error: string | null;
    waiting_for: string | null;
    print: { status: string; label: string | null; error: string | null; confirmable?: boolean; requeueable?: boolean } | null;
    options: PreparationOptions | null;
}

const SOURCE: Record<string, string> = {
    paymob: 'عبر رابط الدفع',
    reconcile: 'عبر رابط الدفع',
    manual: 'أرسلها موظف بعد السداد',
};

export default function PreparationPanel({ invoiceId, preparation }: { invoiceId: number; preparation: PreparationPanelData }) {
    const t = useTranslate();
    const [editing, setEditing] = useState(false);
    const form = useForm({ preparation: preparationForm(preparation.data) });
    const p = preparation.data;

    const save = () => {
        form.transform((d) => ({ preparation: preparationPayload({ ...d.preparation, enabled: true }) }));
        form.post(route('admin.customerInvoices.preparation.save', invoiceId), {
            preserveScroll: true,
            onSuccess: () => setEditing(false),
        });
    };

    const remove = () =>
        router.delete(route('admin.customerInvoices.preparation.remove', invoiceId), { preserveScroll: true });

    const activate = () =>
        router.post(route('admin.customerInvoices.preparation.activate', invoiceId), {}, { preserveScroll: true });

    if (editing && preparation.options) {
        return (
            <div className="space-y-2" data-testid="preparation-panel">
                <CustomerInvoicePreparationFields
                    value={form.data.preparation}
                    onChange={(patch) => form.setData('preparation', { ...form.data.preparation, ...patch })}
                    options={preparation.options}
                    errors={form.errors as Record<string, string | undefined>}
                    toggleable={false}
                />
                <div className="flex gap-2">
                    <Button onClick={save} disabled={form.processing}>{t('حفظ طلب التجهيز')}</Button>
                    <Button variant="outline" onClick={() => setEditing(false)}>{t('إلغاء')}</Button>
                </div>
            </div>
        );
    }

    if (!p) {
        if (!preparation.attachable) return null;

        return (
            <Card className="p-4" data-testid="preparation-panel">
                <h3 className="mb-1 flex items-center gap-1.5 text-[13px] font-bold">
                    <ClipboardList className="size-4" />
                    {t('التجهيز والتسليم')}
                </h3>
                <p className="mb-3 text-[12px] text-[#6b7280]">{t('تظهر في لوحة التجهيز بعد سداد الفاتورة بالكامل — ولا تغيّر بنودها ولا مبالغها.')}</p>
                <Button variant="outline" size="sm" onClick={() => setEditing(true)}>
                    {t('إرسال إلى لوحة التجهيز بعد السداد')}
                </Button>
            </Card>
        );
    }

    const row = (label: string, value: string | null | undefined) =>
        value ? (
            <div className="flex justify-between gap-3 text-[12px]">
                <span className="text-[#6b7280]">{t(label)}</span>
                <span className="text-end text-[#111]" dir="auto">{value}</span>
            </div>
        ) : null;

    return (
        <Card className="space-y-3 p-4" data-testid="preparation-panel">
            <h3 className="flex items-center gap-1.5 text-[13px] font-bold">
                <ClipboardList className="size-4" />
                {t('التجهيز والتسليم')}
            </h3>

            {/*
                أين وصل — والسببُ بجملةٍ لا رمز. و«مؤكّد» تُقرأ «بانتظار التجهيز»
                كعمودها في اللوحة. ومسدَّدةٌ لم تُرسل بعد لا تُقال «بانتظار السداد».
            */}
            {preparation.active ? (
                <p className="rounded-[10px] bg-[#ecfdf5] p-2.5 text-[12px] text-[#065f46]" data-testid="preparation-state">
                    {t('في لوحة التجهيز')} — {t(preparation.status === 'مؤكّد' ? 'بانتظار التجهيز' : (preparation.status ?? ''))}
                    {preparation.activation_source ? ` · ${t(SOURCE[preparation.activation_source] ?? '')}` : ''}
                    {preparation.activated_at ? ` · ${preparation.activated_at}` : ''}
                </p>
            ) : (
                <p className="rounded-[10px] bg-[#fffbeb] p-2.5 text-[12px] text-[#92400e]" data-testid="preparation-state">
                    {preparation.waiting_for ?? (preparation.paid ? t('الفاتورة مسدّدة — لم تُرسل إلى لوحة التجهيز بعد') : t('بانتظار السداد الكامل'))}
                </p>
            )}
            {preparation.activation_error && (
                <p className="text-[12px] text-[#b91c1c]">{preparation.activation_error}</p>
            )}

            <div className="space-y-1">
                {row('نوع التسليم', p.fulfillment_type === 'delivery' ? t('توصيل') : t('استلام من المحل'))}
                {row('الفرع', p.branch)}
                {row('الموعد', p.scheduled_for?.replace('T', ' '))}
                {row('المستلم', p.recipient_name)}
                {row('الهاتف', p.recipient_phone)}
                {row('العنوان', p.delivery_address)}
            </div>

            {preparation.print && (
                <p className="flex items-center gap-1.5 text-[12px] text-[#374151]">
                    <Printer className="size-3.5" />
                    {t('فاتورة عميل — نسخة داخلية')}: {preparation.print.label}
                </p>
            )}

            <div className="flex flex-wrap gap-2">
                {preparation.activatable && (
                    <Button size="sm" onClick={activate} data-testid="preparation-activate">
                        <Send />
                        {t('إرسال إلى لوحة التجهيز الآن')}
                    </Button>
                )}
                {preparation.editable && preparation.options && (
                    <Button size="sm" variant="outline" onClick={() => setEditing(true)}>
                        {t('تعديل')}
                    </Button>
                )}
                {preparation.removable && (
                    <Button size="sm" variant="ghost" onClick={remove}>
                        <Trash2 />
                        {t('إلغاء طلب التجهيز')}
                    </Button>
                )}
            </div>
        </Card>
    );
}
