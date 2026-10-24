/** صفُّ مستندٍ من مستندات المنصّة — في القائمة وفي رأس صفحته (`DocumentController::row`) */
export interface DocumentRow {
    id: number;
    url: string;
    number: string;
    title: string;
    service: string | null;
    client_name: string | null;
    client_biz: string | null;
    business: { id: number; name: string | null } | null;
    state: 'sent' | 'signed' | 'cancelled' | 'expired';
    state_label: string;
    created_at: string;
    expires_at: string;
    signed_at: string;
}

/** لونُ كلّ حالة — المنتهي أحمر: رابطٌ أُرسل ولم يُوقَّع ولم يعد يعمل */
export const STATE_VARIANT: Record<DocumentRow['state'], 'warning' | 'success' | 'neutral' | 'danger'> = {
    sent: 'warning',
    signed: 'success',
    cancelled: 'neutral',
    expired: 'danger',
};
