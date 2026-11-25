import { router } from '@inertiajs/react';
import { useState } from 'react';
import StatusPill, { type SetupState } from '@/Components/StatusPill';
import { Button } from '@/Components/ui/button';
import { useTranslate } from '@/lib/i18n';

/** إحدى خمس حالاتٍ لجهة رسوم واتساب — انظر `App\Support\WhatsAppBillingLock` */
export type BillingState = 'unverified' | 'pending' | 'proven' | 'problem' | 'recheck';

/** بترتيب ما يحتاج النظر أوّلًا */
export const BILLING_STATES: BillingState[] = ['problem', 'recheck', 'unverified', 'pending', 'proven'];

export const BILLING_PILL: Record<BillingState, SetupState> = {
    unverified: 'action',
    pending: 'progress',
    proven: 'ready',
    problem: 'error',
    recheck: 'action',
};

/** ما يراه التاجرُ عن جهة رسومه — بلا رمزٍ ولا وقائعَ داخليّة (`WhatsAppBillingLock::merchantView`) */
export interface MerchantBilling {
    state: BillingState;
    label: string;
    account: string | null;
    owner: string | null;
    text: string | null;
    who: 'merchant' | 'abaad' | 'meta' | null;
    trial: boolean;
    may_request: boolean;
    may_retry: boolean;
    requested_at: string | null;
    proven_at: string | null;
}

const PAYMENT_PAGE = 'https://business.facebook.com/latest/billing_hub/payment_methods/';

/**
 * على من رسومُ واتساب — يدفعها التاجرُ لـMeta مباشرة، ولا تخرج رسالةٌ من رقمه قبل أن يثبت ذلك.
 *
 * ولا يُرفع ملفٌّ هنا: صورةُ صفحة الدفع قد تحمل رقم بطاقة. يُرسلها لفريق أبعاد بعد أن يخفيه.
 */
export default function WhatsappBilling({ billing, mayManage }: { billing: MerchantBilling; mayManage: boolean }) {
    const t = useTranslate();
    const [busy, setBusy] = useState<'request' | 'retry' | null>(null);
    const [error, setError] = useState<string | null>(null);

    const send = (what: 'request' | 'retry') =>
        router.post(route(`admin.integrations.whatsapp.billing.${what}`), {}, {
            preserveScroll: true,
            onStart: () => setBusy(what),
            onFinish: () => setBusy(null),
            onSuccess: () => setError(null),
            onError: (e) => setError((e as Record<string, string>).billing ?? null),
        });

    return (
        <div className="rounded-[12px] border border-[var(--ui-border,#e8e8e8)] bg-white p-4 text-[13px]" data-testid="wa-billing" data-state={billing.state}>
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="font-semibold text-[#111]">{t('رسوم واتساب')}</p>
                <StatusPill state={BILLING_PILL[billing.state]} label={billing.label} />
            </div>
            <p className="mt-1 leading-relaxed text-[#6b7280]">
                {t('تدفع رسوم رسائلك لـMeta مباشرة من وسيلة الدفع في حسابك. أبعاد لا تدفعها عنك ولا تخصمها من رصيد رسائلك لديها.')}
            </p>

            {billing.text && (
                <p
                    className={billing.state === 'problem' || billing.state === 'recheck'
                        ? 'mt-2 rounded-[10px] bg-[#fef2f2] px-3 py-2 text-[12px] leading-relaxed text-[#b91c1c]'
                        : 'mt-2 rounded-[10px] bg-[#fffbeb] px-3 py-2 text-[12px] leading-relaxed text-[#92400e]'}
                    data-testid="wa-billing-text"
                >
                    {billing.text}
                    <span className="block">{t('لا تخرج رسالة من رقمك حتى يكتمل ذلك — ولا تخرج من رقم آخر بدلًا منه.')}</span>
                </p>
            )}

            {billing.trial && (
                <p className="mt-2 text-[12px] text-[#1d4ed8]" data-testid="wa-billing-trial">
                    {t('متجرك في تجربة الفوترة — يخرج منه عدد محدود من الرسائل برسوم حقيقية على حسابك.')}
                </p>
            )}

            {billing.may_request && (
                <ol className="mt-3 list-inside list-decimal space-y-1 text-[12px] leading-relaxed text-[#374151]" data-testid="wa-billing-steps">
                    <li>
                        {t('أضف وسيلة دفعك في Meta على هذا الحساب')}
                        {billing.account && <> (<bdi dir="ltr">{billing.account}</bdi>)</>}{': '}
                        <a href={PAYMENT_PAGE} target="_blank" rel="noreferrer" className="font-medium text-[#2563eb] underline">
                            {t('فتح وسائل الدفع في Meta')}
                        </a>
                    </li>
                    <li>{t('اضغط «اطلب التحقق».')}</li>
                    <li>{t('أرسل لفريق أبعاد صورة صفحة الدفع تُظهر معرّف الحساب ووسيلة الدفع، وأخفِ رقم البطاقة.')}</li>
                </ol>
            )}

            {billing.state === 'pending' && billing.requested_at && (
                <p className="mt-2 text-[12px] text-[#6b7280]" data-testid="wa-billing-requested">
                    {t('طلبت التحقق في')} <span dir="ltr">{billing.requested_at}</span>
                </p>
            )}

            {billing.state === 'proven' && (
                <p className="mt-2 text-[12px] text-[#047857]" data-testid="wa-billing-proven">
                    {billing.owner ? t('تُحتسب على: :owner', { owner: billing.owner }) : t('تُحتسب على حسابك')}
                    {billing.proven_at && <> · <span dir="ltr">{billing.proven_at}</span></>}
                </p>
            )}

            {mayManage && billing.may_request && (
                <Button type="button" size="sm" className="mt-3" loading={busy === 'request'} onClick={() => send('request')} data-testid="wa-billing-request">
                    {t('اطلب التحقق')}
                </Button>
            )}
            {mayManage && billing.may_retry && (
                <Button type="button" size="sm" className="mt-3" loading={busy === 'retry'} onClick={() => send('retry')} data-testid="wa-billing-retry">
                    {t('أصلحت وسيلة الدفع — أعد المحاولة')}
                </Button>
            )}
            {error && <p className="mt-2 text-[12px] text-[#b91c1c]" data-testid="wa-billing-error">{error}</p>}
        </div>
    );
}
