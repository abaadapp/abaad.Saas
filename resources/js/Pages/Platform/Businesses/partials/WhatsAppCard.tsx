import { router, useForm } from '@inertiajs/react';
import { Check, MessageCircle, RefreshCw, Save, X } from 'lucide-react';
import Field from '@/Components/Field';
import Toggle from '@/Components/Toggle';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import type { LinkHealth } from '@/Pages/Admin/Integrations/partials/WhatsappLinkCard';

/** ما يصل من الخادم — بلا رمزٍ ولا سرّ (انظر SuperAdmin\WhatsAppController::businessView) */
export interface BusinessWhatsApp {
    enabled: boolean;
    mode: string;
    /** الوضعُ الذي يُرسل به فعلًا — من أُغلق عنه رقمُ أبعاد على رقمه وإن بقي صفُّه `abaad_shared` */
    effective_mode: string;
    own_allowed: boolean;
    /** أيُرسل من رقم أبعاد المشترك؟ — انظر `WhatsAppFeature::canUseShared` */
    shared_allowed: boolean;
    limit_override: number | null;
    platform_default: number;
    usage: {
        used: number;
        limit: number;
        unlimited: boolean;
        remaining: number | null;
        percentage: number | null;
        credits: number;
        monthly_exhausted: boolean;
        is_exhausted: boolean;
    };
    /** حزمُ الرسائل التي طلبها هذا المتجر — الأحدثُ أوّلًا */
    packs: {
        id: number;
        messages: number;
        amount: number;
        status: string;
        invoice_number: string | null;
        requested_by_name: string | null;
        created_at: string;
    }[];
    pack_offer: { size: number; price: number };
    own_connection: {
        status: string;
        usable: boolean;
        display_phone_number: string | null;
        phone_number_id?: string | null;
        expires_at?: string | null;
    } | null;
    /** آخرُ إشعارٍ وصل من رقم المتجر، وآخرُ خطأٍ قالته ميتا — لا رمزَ ولا سرّ */
    own_health?: {
        last_webhook_at: string | null;
        last_error: string | null;
        last_error_at: string | null;
        /** متّصلٌ أم مشتركٌ أم يستقبل فعلًا — `WhatsAppLinkHealth::view` */
        link?: LinkHealth;
        can_check?: boolean;
        /** فوترةُ ميتا لوصلة المتجر: وقائعُها، وما يمنع تأكيدها، وما رُوجع — `WhatsAppOwnApp::reviewView` */
        own_app?: BillingReview | null;
    } | null;
    /** أيُختار له «API مستقلّ» الآن؟ — الباقةُ والإضافات (`WhatsAppFeature::canUseOwnNumber`) */
    own_eligible?: boolean;
    /** متجرُ سياسة الإضافات بلا إضافة «ربط واتساب برقم المتجر» */
    own_needs_addon?: boolean;
    /** صندوقُ محادثات واتساب مفتوحٌ له — تلقائيًّا: الباقةُ تشمل واتساب ولم يُطفأ (`WhatsAppInbox::available`) */
    inbox_available?: boolean;
}

/** ما يراه فريقُ أبعاد قبل أن يؤكّد أنّ فوترة ميتا على التاجر */
export interface BillingReview {
    own_app: boolean;
    /** معرّفُ حساب الرسائل المربوط — به يُطابَق الدليل، لا باسم النشاط */
    waba_id?: string | null;
    /** ثلاثةُ أسئلةٍ على حدة: أعليه وسيلةُ دفع؟ ومن يدفع؟ وهل يُرسل؟ */
    stages?: {
        payment_method: 'present' | 'absent' | 'unknown';
        responsibility: 'confirmed' | 'unproven' | 'abaad';
        sending: 'allowed' | 'blocked';
        sending_reason: string | null;
    };
    billing_state: string | null;
    billing_owner: string | null;
    awaits_billing: boolean;
    may_confirm: boolean;
    facts: {
        ownership: string | null;
        funding: string | null;
        abaad_access: string | null;
        partner_owned: boolean;
        /** أيُرسل الرمزُ من هذا الحساب، وأفيه الرقم، وكم حسابَ رسائل يصله الرمزُ على الرقم */
        token_sends?: boolean | null;
        phone_in_account?: boolean | null;
        reach?: number | null;
        read_at: string | null;
    } | null;
    blocker: string | null;
    stopped: string | null;
    evidence: { kind: string; reference: string | null; reviewed_by: string | null; reviewed_at: string | null } | null;
    kinds: { key: string; label: string }[];
    /** يُسمّى حسابُ الرسائل في كلّ رسالة (`messaging_account_id`) على إصدار الواجهة الحاليّ */
    names_account?: boolean;
}

const yesNo = (v: boolean | null | undefined) => (v === true ? 'نعم' : v === false ? 'لا' : 'تعذّرت القراءة');

const FUNDING: Record<string, string> = {
    present: 'عليه وسيلة دفع (لا نعرف لمن)',
    absent: 'بلا وسيلة دفع',
    unreadable: 'لا تُطلعنا ميتا عليها',
};

const PAYMENT: Record<string, string> = {
    present: 'موجودة (لا تقول لمن تُحتسب)',
    absent: 'ميتا تقول لا وسيلة دفع',
    unknown: 'لا تُطلعنا ميتا عليها — تُرى في دليل التاجر',
};

const RESPONSIBILITY: Record<string, string> = {
    confirmed: 'على التاجر — رُوجع دليلها',
    unproven: 'غير مثبتة بعد — تنتظر مراجعة دليل',
    abaad: 'على أبعاد — يُردّ الربط',
};

const ACCESS: Record<string, string> = {
    none: 'لا يملكه ولا يشاركه',
    shared: 'مشارَكٌ معه',
    owned: 'يملكه',
    unknown: 'تعذّرت القراءة',
};

/**
 * مراجعةُ دليل الفوترة — لا زرٌّ يُضغط.
 *
 * ميتا لا تُطلع تطبيقًا ليس لـ«مزوّد حلول أعمال» على وسيلة الدفع، فلا يثبت
 * آليًّا على من تُحتسب الرسائل. فيُسمّى الدليلُ ومرجعُه، ويُقرّ المراجعُ أنّه
 * رأى فيه اسمَ نشاط التاجر ومعرّفَ هذا الحساب — والخادمُ يقرأ ميتا لحظتها.
 */
export function BillingReviewBlock({ businessId, review }: { businessId: number; review: BillingReview }) {
    const t = useTranslate();
    const confirm = useForm({ evidence_kind: '', evidence_reference: '', evidence_waba_id: '', evidence_checked: false });
    const facts = review.facts;
    const ready = confirm.data.evidence_kind !== '' && confirm.data.evidence_reference.trim().length >= 3
        && confirm.data.evidence_waba_id.trim() !== '' && confirm.data.evidence_checked;
    const stages = review.stages;

    return (
        <div className="mt-2 border-t border-[#f0f0f0] pt-2" data-testid="whatsapp-billing" data-state={review.billing_state ?? ''}>
            <div className="flex justify-between gap-3">
                <dt className="text-[#6b7280]">{t('فوترة ميتا')}</dt>
                <dd className="font-medium text-[#111]">
                    {review.billing_state === 'confirmed'
                        ? `${t('رُوجع دليلها — على التاجر')}${review.billing_owner ? ` — ${review.billing_owner}` : ''}`
                        : review.own_app
                          ? t('لم يُراجَع دليلها — لا يُرسل الربط')
                          : t('لم يُراجَع دليلها — يُراجَع قبل أن ينتقل إلى تطبيقه')}
                </dd>
            </div>
            {review.waba_id && (
                <p className="mt-1 text-[11px] text-[#374151]" data-testid="whatsapp-billing-account">
                    {t('حساب الرسائل المربوط')}: <bdi dir="ltr">{review.waba_id}</bdi>
                </p>
            )}
            {stages && (
                <ul className="mt-1 space-y-0.5 text-[11px]" data-testid="whatsapp-billing-stages">
                    <li data-testid="whatsapp-billing-stage-payment">
                        {t('وسيلة الدفع على هذا الحساب')}: {t(PAYMENT[stages.payment_method])}
                    </li>
                    <li data-testid="whatsapp-billing-stage-responsibility">
                        {t('مسؤولية الرسوم')}: {t(RESPONSIBILITY[stages.responsibility])}
                    </li>
                    <li data-testid="whatsapp-billing-stage-sending" className={stages.sending === 'allowed' ? 'text-[#047857]' : 'text-[#b91c1c]'}>
                        {t('الإرسال')}: {stages.sending === 'allowed' ? t('مسموح') : t('محظور')}
                        {stages.sending_reason ? ` — ${stages.sending_reason}` : ''}
                    </li>
                </ul>
            )}
            {review.stopped && <p className="mt-1 text-[11px] text-[#b91c1c]">{review.stopped}</p>}
            {facts && (
                <ul className="mt-1 space-y-0.5 text-[11px] text-[#6b7280]" data-testid="whatsapp-billing-facts">
                    <li>{t('الملكيّة عند ميتا')}: {facts.ownership ?? t('تعذّرت القراءة')}{facts.partner_owned ? ` — ${t('لشريكٍ نيابةً عن التاجر')}` : ''}</li>
                    <li>{t('وسيلة الدفع')}: {t(FUNDING[facts.funding ?? 'unreadable'] ?? FUNDING.unreadable)}</li>
                    <li>{t('نشاط أبعاد')}: {t(ACCESS[facts.abaad_access ?? 'unknown'] ?? ACCESS.unknown)}</li>
                    <li>{t('الرمز يُرسل من هذا الحساب')}: {t(yesNo(facts.token_sends))}</li>
                    <li>{t('الرقم في هذا الحساب')}: {t(yesNo(facts.phone_in_account))}</li>
                    <li>
                        {t('حسابات الرسائل التي يصلها الرمز على الرقم')}: {facts.reach ?? t('تعذّرت القراءة')}
                        {review.names_account ? ` — ${t('يُسمّى حساب التاجر في كلّ رسالة')}` : ''}
                    </li>
                    {facts.read_at && <li>{t('قُرئت')}: {facts.read_at}</li>}
                </ul>
            )}
            {review.blocker && <p className="mt-1 text-[11px] font-medium text-[#b91c1c]" data-testid="whatsapp-billing-blocker">{review.blocker}</p>}
            {review.evidence && (
                <p className="mt-1 text-[11px] text-[#6b7280]" data-testid="whatsapp-billing-evidence">
                    {review.evidence.kind} — {review.evidence.reference} · {review.evidence.reviewed_by} · {review.evidence.reviewed_at}
                </p>
            )}
            {review.may_confirm && (
                <form
                    className="mt-2 space-y-2"
                    data-testid="whatsapp-billing-review"
                    onSubmit={(e) => {
                        e.preventDefault();
                        confirm.post(route('super-admin.businesses.whatsapp.billing', businessId), { preserveScroll: true });
                    }}
                >
                    <p className="text-[11px] leading-relaxed text-[#6b7280]">
                        {t('ميتا لا تُطلعنا على وسيلة الدفع، وإقرار التاجر لا يكفي. أكّد بعد أن ترى دليلًا من ميتا نفسها:')}
                    </p>
                    <select
                        className="w-full rounded-[8px] border border-[#e5e7eb] p-2 text-[12px]"
                        value={confirm.data.evidence_kind}
                        onChange={(e) => confirm.setData('evidence_kind', e.target.value)}
                        data-testid="whatsapp-billing-kind"
                    >
                        <option value="">{t('ما الدليل؟')}</option>
                        {review.kinds.map((k) => (
                            <option key={k.key} value={k.key}>
                                {k.label}
                            </option>
                        ))}
                    </select>
                    <Input
                        value={confirm.data.evidence_reference}
                        onChange={(e) => confirm.setData('evidence_reference', e.target.value)}
                        placeholder={t('مرجعه: رقم الفاتورة أو تاريخ الصورة')}
                        data-testid="whatsapp-billing-reference"
                    />
                    <Input
                        value={confirm.data.evidence_waba_id}
                        onChange={(e) => confirm.setData('evidence_waba_id', e.target.value)}
                        placeholder={t('معرّف الحساب كما في صفحة ميتا (WABA ID)')}
                        dir="ltr"
                        inputMode="numeric"
                        data-testid="whatsapp-billing-waba"
                    />
                    <label className="flex items-start gap-2 text-[11px] leading-relaxed text-[#374151]">
                        <input
                            type="checkbox"
                            className="mt-0.5"
                            checked={confirm.data.evidence_checked}
                            onChange={(e) => confirm.setData('evidence_checked', e.target.checked)}
                            data-testid="whatsapp-billing-checked"
                        />
                        <span>{t('رأيت في الدليل معرّفَ هذا الحساب نفسه — لا اسمَ النشاط وحده — ووسيلةَ دفعٍ ليست لأبعاد.')}</span>
                    </label>
                    {(confirm.errors.evidence_kind || confirm.errors.evidence_reference || confirm.errors.evidence_waba_id || confirm.errors.evidence_checked) && (
                        <p className="text-[11px] text-[#b91c1c]">{confirm.errors.evidence_kind || confirm.errors.evidence_reference || confirm.errors.evidence_waba_id || confirm.errors.evidence_checked}</p>
                    )}
                    <Button type="submit" variant="outline" size="sm" loading={confirm.processing} disabled={! ready} data-testid="whatsapp-billing-confirm">
                        <Check />
                        {t('راجعتُ الدليل — الفوترة على التاجر')}
                    </Button>
                </form>
            )}
        </div>
    );
}

const MODES = [
    { key: 'abaad_shared', title: 'رقم أبعاد المشترك', hint: 'تخرج رسائله من رقم أبعاد، وردودُ زبائنه تُنسب إليه حين يثبت أنّها له وحده' },
    { key: 'business_own', title: 'API مستقل للمتجر', hint: 'رقمُه على WhatsApp Cloud API بحسابه — لا يُرسل من رقم أبعاد ولو انقطع' },
] as const;

/**
 * واتساب هذا المتجر — بطاقةٌ في ملفّه لا شاشةٌ جديدة.
 *
 * وما فيها كلّه ممّا لا يملكه التاجر: الحدّ، وصلاحية الرقم الخاص، والتفعيل.
 * ومكانها هنا لأنّ السؤال يُسأل وأنت تنظر إلى المتجر — «كم استهلك؟ أأرفع
 * له؟» — لا في شاشةٍ ثالثة تُفتح بعد أن تبحث عن اسمه فيها من جديد.
 */
export default function WhatsAppCard({ businessId, data }: { businessId: number; data: BusinessWhatsApp }) {
    const t = useTranslate();

    const form = useForm({
        whatsapp_enabled: data.enabled,
        whatsapp_own_allowed: data.own_allowed,
        whatsapp_shared_allowed: data.shared_allowed,
        whatsapp_mode: data.mode,
        // فارغٌ يعني «افتراضي المنصّة» — لا صفرًا، والصفر يعني المنع
        whatsapp_monthly_limit: data.limit_override === null ? '' : String(data.limit_override),
    });

    const submit = () => {
        // الفارغ يصل `null` — «افتراضي المنصّة»؛ ولو أُرسل نصًّا فارغًا لَقُرئ صفرًا، وهو المنع
        form.transform(({ whatsapp_mode, ...d }) => ({
            ...d,
            whatsapp_monthly_limit: d.whatsapp_monthly_limit === '' ? null : Number(d.whatsapp_monthly_limit),
            // والوضعُ يُرسل حين يُبدَّل وحده — حفظُ الحدّ لا يعيد اختيار الرقم
            ...(whatsapp_mode !== data.mode ? { whatsapp_mode } : {}),
        }));

        form.put(route('super-admin.businesses.whatsapp.update', businessId), { preserveScroll: true });
    };

    return (
        <Card className="mb-6 p-6">
            <h3 className="mb-1 flex items-center gap-2 font-bold text-[#111]">
                <MessageCircle className="size-4" />
                {t('واتساب')}
            </h3>
            <p className="mb-5 text-[13px] text-[#6b7280]">
                {t('الحدّ وصلاحية الرقم الخاص يضبطهما مدير المنصة وحده — لا يراهما التاجر ولا يغيّرهما.')}
            </p>

            <dl className="mb-5 space-y-2 rounded-[12px] bg-[#fafafa] p-4 text-[13px]">
                <div className="flex justify-between gap-3">
                    <dt className="text-[#6b7280]">{t('وضع الإرسال')}</dt>
                    <dd className="font-medium text-[#111]">
                        {t(data.effective_mode === 'business_own' ? 'رقم المتجر' : 'رقم أبعاد')}
                    </dd>
                </div>
                <div className="flex justify-between gap-3">
                    <dt className="text-[#6b7280]">{t('رسائل هذا الشهر')}</dt>
                    <dd className="font-medium text-[#111]" dir="ltr">
                        {data.usage.unlimited
                            ? String(data.usage.used)
                            : `${data.usage.used} / ${data.usage.limit}`}
                    </dd>
                </div>
                <div className="flex justify-between gap-3">
                    <dt className="text-[#6b7280]">{t('المتبقّي')}</dt>
                    <dd className="font-medium text-[#111]" dir="ltr">
                        {data.usage.unlimited ? t('بلا حد') : String(data.usage.remaining)}
                    </dd>
                </div>
                <div className="flex justify-between gap-3">
                    <dt className="text-[#6b7280]">{t('رصيد مشترًى')}</dt>
                    <dd className="font-medium text-[#111]" dir="ltr">
                        {String(data.usage.credits)}
                    </dd>
                </div>
                <div className="flex justify-between gap-3">
                    <dt className="text-[#6b7280]">{t('رقم المتجر الخاص')}</dt>
                    <dd className="font-medium text-[#111]" dir="ltr">
                        {data.own_connection
                            ? `${data.own_connection.display_phone_number ?? data.own_connection.phone_number_id ?? ''} · ${data.own_connection.usable ? t('يعمل') : t('متوقف')}`
                            : t('غير مربوط')}
                    </dd>
                </div>
            </dl>

            {data.own_connection && (
                <dl className="mb-5 space-y-2 rounded-[12px] border border-[#f0f0f0] p-4 text-[13px]" data-testid="whatsapp-own-health">
                    <div className="flex justify-between gap-3">
                        <dt className="text-[#6b7280]">{t('حالة الربط')}</dt>
                        <dd className="font-medium text-[#111]">{data.own_connection.status}</dd>
                    </div>
                    <div className="flex justify-between gap-3">
                        <dt className="text-[#6b7280]">{t('صلاحية الرمز')}</dt>
                        <dd className="font-medium text-[#111]" dir="ltr">{data.own_connection.expires_at ?? t('لا تنتهي')}</dd>
                    </div>
                    <div className="flex justify-between gap-3">
                        <dt className="text-[#6b7280]">{t('آخر إشعار من ميتا')}</dt>
                        <dd className="font-medium text-[#111]" dir="ltr">{data.own_health?.last_webhook_at ?? t('لم يصل بعد')}</dd>
                    </div>
                    {data.own_health?.last_error && (
                        <div className="flex justify-between gap-3">
                            <dt className="text-[#6b7280]">{t('آخر خطأ')}</dt>
                            <dd className="text-end font-medium text-[#b91c1c]">
                                {data.own_health.last_error}
                                <span className="block text-[11px] text-[#9ca3af]" dir="ltr">{data.own_health.last_error_at}</span>
                            </dd>
                        </div>
                    )}
                    {/* أيستقبل؟ — تطبيقُ أبعاد مشتركٌ وإشعارٌ وصل، لا «متّصل» وحدها */}
                    {data.own_health?.link && (
                        <div className="border-t border-[#f0f0f0] pt-2" data-testid="whatsapp-link-health" data-state={data.own_health.link.key}>
                            <div className="flex justify-between gap-3">
                                <dt className="text-[#6b7280]">{t('استقبال الرسائل')}</dt>
                                <dd className={data.own_health.link.tone === 'danger' ? 'font-medium text-[#b91c1c]' : data.own_health.link.tone === 'success' ? 'font-medium text-[#047857]' : 'font-medium text-[#111]'}>
                                    {t(data.own_health.link.label)}
                                </dd>
                            </div>
                            <p className="mt-1 text-[12px] leading-relaxed text-[#6b7280]">{t(data.own_health.link.hint)}</p>
                            {data.own_health.link.checked_at && (
                                <p className="mt-0.5 text-[11px] text-[#9ca3af]">{t('آخر فحص')}: <span dir="ltr">{data.own_health.link.checked_at}</span></p>
                            )}
                            {/* حالةُ التسليم ليست رسالةَ زبون — لكلٍّ ختمُه */}
                            <div className="mt-2 flex justify-between gap-3" data-testid="whatsapp-last-status">
                                <dt className="text-[#6b7280]">{t('آخر حالة تسليم')}</dt>
                                <dd className="font-medium text-[#111]" dir="ltr">{data.own_health.link.last_status_at ?? '—'}</dd>
                            </div>
                            <div className="flex justify-between gap-3" data-testid="whatsapp-last-inbound">
                                <dt className="text-[#6b7280]">{t('آخر رسالة عميل وصلت')}</dt>
                                <dd className="font-medium text-[#111]" dir="ltr">{data.own_health.link.last_inbound_at ?? t('لم تصل بعد')}</dd>
                            </div>
                            <div className="flex justify-between gap-3" data-testid="whatsapp-last-saved">
                                <dt className="text-[#6b7280]">{t('آخر رسالة حُفظت في المحادثات')}</dt>
                                <dd className="font-medium text-[#111]" dir="ltr">{data.own_health.link.last_inbound_saved_at ?? '—'}</dd>
                            </div>
                            {data.own_health.can_check && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    className="mt-2"
                                    data-testid="whatsapp-link-check"
                                    onClick={() => router.post(route('super-admin.businesses.whatsapp.check', businessId), {}, { preserveScroll: true })}
                                >
                                    <RefreshCw />
                                    {t('فحص الربط')}
                                </Button>
                            )}
                            <p className="mt-1 text-[11px] text-[#9ca3af]">{t('الفحص قراءة عند ميتا فقط — لا يغيّر الرقم ولا الرمز ولا الاشتراك.')}</p>
                            {/* وفوترةُ ميتا: لا تُرسل وصلةُ تطبيق التاجر قبل أن يُراجَع دليلُها */}
                            {data.own_health.own_app && <BillingReviewBlock businessId={businessId} review={data.own_health.own_app} />}
                        </div>
                    )}
                </dl>
            )}

            <div className="space-y-5">
                {/*
                    ═══ من أيّ رقمٍ يتكلّم المتجر ═══

                    خياران لا ثالث لهما (`WhatsAppMode`). و«API مستقلّ» لمتجر
                    سياسة الإضافات يحتاج إضافتَه سارية — والخادمُ يردّ سواه.
                */}
                <div className="space-y-2" data-testid="whatsapp-mode">
                    <p className="text-[13px] font-medium text-[#111]">{t('رقم الإرسال')}</p>
                    <div className="grid grid-cols-1 gap-2 sm:grid-cols-2" role="radiogroup" aria-label={t('رقم الإرسال')}>
                        {MODES.map((m) => {
                            const picked = form.data.whatsapp_mode === m.key;
                            const locked = m.key === 'business_own' && !picked && data.own_needs_addon === true;

                            return (
                                <button
                                    key={m.key}
                                    type="button"
                                    role="radio"
                                    aria-checked={picked}
                                    disabled={locked}
                                    data-testid={`whatsapp-mode-${m.key}`}
                                    onClick={() => form.setData('whatsapp_mode', m.key)}
                                    className={`rounded-[10px] border p-3 text-start ${picked ? 'border-[#111] bg-[#f9fafb]' : 'border-[#e5e7eb]'} ${locked ? 'cursor-not-allowed opacity-60' : ''}`}
                                >
                                    <span className="block text-sm font-medium text-[#111]">{t(m.title)}</span>
                                    <span className="mt-1 block text-[12px] text-[#6b7280]">{t(m.hint)}</span>
                                </button>
                            );
                        })}
                    </div>
                    {data.own_needs_addon && (
                        <p className="text-[12px] text-[#92400e]">
                            {t('API المستقلّ إضافةٌ لهذا المتجر — اطلبها له أو امنحها بتاريخ من «خدمات أبعاد» في صفحته.')}
                        </p>
                    )}
                    {form.errors.whatsapp_mode && <p className="text-[12px] text-[#b91c1c]">{form.errors.whatsapp_mode}</p>}
                </div>

                {/* لا مقبضَ للصندوق: يُفتح وحده لمن يملك واتساب — يُقرأ هنا ولا يُبدَّل */}
                <p className="rounded-[10px] bg-[#fafafa] px-3 py-2 text-[12px] leading-relaxed text-[#6b7280]" data-testid="inbox-status">
                    {data.inbox_available
                        ? t('صندوق محادثات واتساب مفتوحٌ له تلقائيًا — باقته تشمل واتساب. موظّفوه بصلاحيةٍ يمنحها صاحب المتجر.')
                        : t('صندوق محادثات واتساب مغلقٌ له — باقته لا تشمل واتساب، أو واتساب مطفأ لحسابه.')}
                </p>
                <Toggle
                    on={form.data.whatsapp_enabled}
                    onChange={(v) => form.setData('whatsapp_enabled', v)}
                    label="تفعيل واتساب لهذا المتجر"
                    hint="إطفاؤه يوقف رسائله وحده ولا يمسّ غيره"
                />
                <Toggle
                    on={form.data.whatsapp_own_allowed}
                    onChange={(v) => form.setData('whatsapp_own_allowed', v)}
                    label="صلاحية ربط رقمه الخاص"
                    hint="ممنوحة لكل متجر افتراضًا — وسحبها يوقف الإرسال من رقمه ولا يحذف وصلته"
                />
                <Toggle
                    on={form.data.whatsapp_shared_allowed}
                    onChange={(v) => form.setData('whatsapp_shared_allowed', v)}
                    label="الإرسال عبر رقم أبعاد المشترك"
                    hint="مسموحٌ لكل متجر افتراضًا — وإطفاؤه يجعله على رقمه وحده: يربطه فيُرسل منه، وإلا فلا رسالة. ولا يمسّ رقم أبعاد ولا غيره"
                />
                <Field
                    label={t('الحد الشهري لهذا المتجر')}
                    hint={`${t('اتركه فارغًا ليأخذ افتراضي المنصة')} (${data.platform_default}) · ${t('و‎-1 تعني بلا حد')}`}
                    error={form.errors.whatsapp_monthly_limit}
                >
                    <Input
                        type="number"
                        dir="ltr"
                        value={form.data.whatsapp_monthly_limit}
                        onChange={(e) => form.setData('whatsapp_monthly_limit', e.target.value)}
                        placeholder={String(data.platform_default)}
                    />
                </Field>
            </div>

            <div className="mt-5 flex justify-end">
                <Button type="button" loading={form.processing} onClick={submit}>
                    <Save />
                    {t('حفظ')}
                </Button>
            </div>

            {/*
                ═══ حزمُ الرسائل — وما ينتظر فعلًا منها ═══

                طلبٌ `مطلوبة` ينتظر ضغطةً من هنا: تُصدَر فاتورتُه. وما بعدها
                ينتظر مالًا لا ضغطة — فالسدادُ يُسجَّل في شاشة الفواتير،
                والرصيدُ يُضاف وحدَه عندها (انظر `Billing::markPaid`).

                ولا يُضاف رصيدٌ من هنا بزرّ: لو أُضيف لَصار الاعتمادُ هديّةً
                تُعطى قبل أن يُدفع شيء.
            */}
            {data.packs.length > 0 && (
                <div className="mt-6 border-t border-[#f0f0f0] pt-5">
                    <h4 className="mb-3 text-[13px] font-bold text-[#111]">
                        {t('حزم الرسائل')}
                        <span className="me-2 font-normal text-[#6b7280]" dir="ltr">
                            {' '}
                            ({data.pack_offer.size} · {data.pack_offer.price.toFixed(3)})
                        </span>
                    </h4>

                    <ul className="space-y-2">
                        {data.packs.map((p) => (
                            <li
                                key={p.id}
                                className="flex flex-wrap items-center justify-between gap-3 rounded-[10px] bg-[#fafafa] px-3 py-2 text-[13px]"
                            >
                                <span className="text-[#111]">
                                    <span dir="ltr">
                                        {p.messages} · {p.amount.toFixed(3)}
                                    </span>
                                    <span className="me-2 text-[#6b7280]">
                                        {' '}
                                        {p.status}
                                        {p.invoice_number ? ` · ${p.invoice_number}` : ''}
                                    </span>
                                </span>

                                <span className="flex items-center gap-2">
                                    <span className="text-[12px] text-[#9ca3af]" dir="ltr">
                                        {p.created_at}
                                    </span>
                                    {p.status === 'مطلوبة' && (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={() =>
                                                router.post(
                                                    route('super-admin.whatsapp.packs.approve', p.id),
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            <Check />
                                            {t('أصدر الفاتورة')}
                                        </Button>
                                    )}
                                    {(p.status === 'مطلوبة' || p.status === 'مصدَّرة') && (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            onClick={() =>
                                                router.delete(route('super-admin.whatsapp.packs.cancel', p.id), {
                                                    preserveScroll: true,
                                                })
                                            }
                                        >
                                            <X />
                                            {t('إلغاء')}
                                        </Button>
                                    )}
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </Card>
    );
}
