import { useForm } from '@inertiajs/react';
import CustomerInvoicePreparationFields, {
    preparationForm,
    preparationPayload,
    type PreparationData,
    type PreparationOptions,
} from '@/Components/CustomerInvoicePreparationFields';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { useTranslate } from '@/lib/i18n';
import { forgetPreparedReceipts } from '@/lib/printing/share-print';

/**
 * «تعديل تفاصيل التسليم» — من تفاصيل مهمّة فاتورة العميل على لوحة التجهيز.
 *
 * ═══ ما يُعدَّل ═══
 *
 * الموعدُ والمستلِمُ وهاتفُه والعنوانُ والفرعُ ونوعُ التسليم والتعليماتُ
 * والملاحظاتُ والهديّةُ والمناسبةُ واسمُ المُهدي وإخفاؤه ورسالةُ البطاقة — حقولُ
 * صفحة الفاتورة نفسُها (`CustomerInvoicePreparationFields`). ولا بندَ ولا سعرَ
 * ولا ضريبةَ ولا تحصيل: النموذجُ لا يحملها، والخادمُ لا يقرؤها لو حُملت.
 *
 * ═══ والأوراقُ بعده ═══
 *
 * تُبنى في الخادم من الصفّ ساعةَ تُطبع. وما جهّزه الآيفون لقائمة المشاركة
 * قبل الحفظ يُنسى هنا (`forgetPreparedReceipts`) — فلا تُشارَك ورقةٌ من قبل
 * التعديل. وورقةُ الصندوق التي خرجت يكتب لها الخادمُ ورقةً جديدة.
 */
export default function InvoiceDeliveryEdit({
    prepId,
    details,
    options,
    onClose,
}: {
    prepId: number;
    details: PreparationData;
    options: PreparationOptions;
    onClose: () => void;
}) {
    const t = useTranslate();
    const form = useForm({ preparation: preparationForm(details) });

    const save = () => {
        form.transform((d) => ({ preparation: preparationPayload({ ...d.preparation, enabled: true }) }));
        form.post(route('admin.preparation.invoiceDetails', prepId), {
            preserveScroll: true,
            onSuccess: () => {
                forgetPreparedReceipts((url) => url.includes(`/preparation/customer-invoices/${prepId}/`));
                onClose();
            },
        });
    };

    return (
        <Dialog open onOpenChange={(open) => ! open && onClose()}>
            <DialogContent className="max-w-2xl p-0" data-testid="prep-edit-details-dialog">
                <DialogHeader className="border-b border-[var(--ui-border,#e8e8e8)] p-5">
                    <DialogTitle>{t('تعديل تفاصيل التسليم')}</DialogTitle>
                </DialogHeader>
                <div className="flex flex-col gap-3 p-5">
                    <CustomerInvoicePreparationFields
                        value={form.data.preparation}
                        onChange={(patch) => form.setData('preparation', { ...form.data.preparation, ...patch })}
                        options={options}
                        errors={form.errors as Record<string, string | undefined>}
                        toggleable={false}
                        className="p-0 shadow-none ring-0"
                    />
                    <div className="flex flex-wrap gap-2">
                        <Button onClick={save} disabled={form.processing} data-testid="prep-edit-details-save">
                            {t('حفظ بيانات التسليم')}
                        </Button>
                        <Button variant="outline" onClick={onClose} disabled={form.processing}>
                            {t('إلغاء')}
                        </Button>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    );
}
