import { useEffect, useRef } from 'react';
import type { PrepOrder } from '@/Pages/Admin/Preparation/partials/types';
import { forgetPreparedReceipts } from '@/lib/printing/share-print';

/**
 * ما تقوله أوراقُ مهمّة الفاتورة — سندُ التسليم والنسخةُ الداخليّة وورقةُ التجهيز.
 *
 * حقولُ البطاقة التي تُطبع، لا حالُها ولا ورقةُ صندوقها ولا ما بقي من الوقت:
 * تلك تتبدّل كلَّ استطلاعٍ ولا يتبدّل بها ما على الورق.
 */
export function papersSay(o: PrepOrder): string {
    return JSON.stringify([
        o.fulfillment, o.scheduled_for, o.branch, o.recipient, o.recipient_phone, o.address,
        o.is_gift ?? null, o.occasion, o.card_message, o.card_align ?? null, o.card_file ?? null,
        o.sender, o.hide_sender, o.delivery_notes, o.internal_notes, o.order_notes,
        o.customer, o.total ?? null, o.items,
    ]);
}

/**
 * ورقةٌ جُهّزت لقائمة المشاركة ثمّ عُدّلت مهمّتُها من جهازٍ آخر — تُنسى.
 *
 * ═══ العطب ═══
 *
 * الآيفون والآيباد يشاركان ملفَّ PDF نُزّل قبل الضغطة (`share-print`)، والملفُّ
 * يبقى في ذاكرة الصفحة. فمن نزّل سندَ التسليم على آيباد الطاولة، ثمّ عدّل
 * زميلُه المستلِمَ أو الموعد من المكتب، شارك الآيبادُ السندَ القديم: نموذجُ
 * التعديل ينسى ملفّاتِ جهازه وحده (`InvoiceDeliveryEdit`).
 *
 * ═══ وما يقع ═══
 *
 * اللوحةُ تستطلع الخادم. فإن تبدّل ما تقوله أوراقُ مهمّةٍ عمّا رأته الشاشةُ
 * قبلُ، نُسيت ملفّاتُها المجهَّزة — وتُنزَّل الضغطةَ التالية من الخادم بالجديد.
 * وأوّلُ ما تراه الشاشةُ خطُّ أساسٍ لا تبدّل، والمرئيُّ يبقى معروفًا ولو غاب
 * عن المرشّح ثمّ عاد.
 */
export default function useChangedPapersForgotten(orders: PrepOrder[]): void {
    const seen = useRef(new Map<number, string>());

    useEffect(() => {
        for (const o of orders) {
            if (o.kind !== 'invoice' || ! o.prep_id) continue;

            const now = papersSay(o);
            const was = seen.current.get(o.prep_id);
            if (was !== undefined && was !== now) {
                forgetPreparedReceipts((url) => url.includes(`/preparation/customer-invoices/${o.prep_id}/`));
            }
            seen.current.set(o.prep_id, now);
        }
    }, [orders]);
}
