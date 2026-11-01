import { router, useForm } from '@inertiajs/react';
import { AlertTriangle, KeyRound, MessageSquare, PlugZap, RefreshCw, Smartphone, Unplug } from 'lucide-react';
import { useCallback, useState } from 'react';
import { toast } from 'sonner';
import StatusPill, { type SetupState } from '@/Components/StatusPill';
import { useConfirm } from '@/Components/ConfirmDialog';
import { PageActions, SettingsSection } from '@/Components/Settings';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import OwnAppLink, { type OwnAppView } from '@/Pages/Admin/Integrations/partials/OwnAppLink';
import { useTranslate } from '@/lib/i18n';
import EmbeddedSignup, { type EmbeddedConfig, type OnboardingKind, type SignupAssets } from '@/Pages/Admin/Integrations/partials/EmbeddedSignup';
import { cn } from '@/lib/utils';

/** حالُ الربط كما حسبها الخادم — انظر App\Support\WhatsAppLink */
export interface WhatsappLink {
    state: 'disconnected' | 'connecting' | 'billing_unproven' | 'needs_registration' | 'connected' | 'reauthorization_required' | 'expired' | 'error';
    label: string;
    waba_id: string | null;
    meta_business_id: string | null;
    phone_number_id: string | null;
    display_phone_number: string | null;
    coexistence: boolean;
    connected_at: string | null;
    connected_by: string | null;
    last_webhook_at: string | null;
    expires_at: string | null;
    days_left: number | null;
    alert_days: number | null;
    error_code: string | null;
    error_message: string | null;
}

/**
 * أيستقبل الرقمُ رسائلَ زبائنه فعلًا؟ — انظر App\Support\WhatsAppLinkHealth.
 *
 * «متّصل» وحدها لا تقول ذلك: الرقمُ قد يُرسل وإشعاراتُه تذهب إلى تطبيقٍ آخر.
 * و«وصلت حالةُ تسليم» لا تقول أنّ رسالةَ زبونٍ وصلت — لكلٍّ ختمُه.
 */
export interface LinkHealth {
    key:
        | 'awaiting_authorization'
        | 'wrong_app'
        | 'incomplete_authorization'
        | 'number_connected'
        | 'webhook_incomplete'
        | 'routing_elsewhere'
        | 'app_subscribed'
        | 'ready'
        | 'status_received'
        | 'inbound_received'
        | 'inbound_refused'
        | 'needs_repair';
    label: string;
    hint: string;
    tone: 'success' | 'info' | 'neutral' | 'danger';
    checked_at: string | null;
    /** آخرُ حالةِ تسليمٍ وصلت لما أرسله المتجر */
    last_status_at?: string | null;
    /** آخرُ رسالةٍ كتبها زبونٌ ووصلت الرقم — وصولٌ لا حفظ */
    last_inbound_at?: string | null;
    /** آخرُ رسالةِ زبونٍ حُفظت في المحادثات — دليلُ الاستقبال */
    last_inbound_saved_at?: string | null;
    /** لماذا لم تُحفظ آخرُ رسالةٍ وصلت — بلا نصّها */
    inbound_problem?: string | null;
}

const HEALTH_TONE: Record<LinkHealth['tone'], string> = {
    success: 'bg-[#ecfdf5] text-[#047857]',
    info: 'bg-[#eff6ff] text-[#1d4ed8]',
    neutral: 'bg-[#f5f5f5] text-[#4b5563]',
    danger: 'bg-[#fef2f2] text-[#b91c1c]',
};

/**
 * لونُ الشارة لكلّ حال — والحالُ من الخادم لا تُستنتج هنا.
 *
 * «يحتاج إعادة تفويض» صفراءُ لا حمراء: هو يعمل اليوم. و«انتهت» حمراء لأنّه
 * لا يعمل. وتسويتُهما لونًا واحدًا تُفقد الفرقَ الذي بُنيت الحالتان له.
 */
export function pillFor(state: WhatsappLink['state']): SetupState {
    switch (state) {
        case 'connected':
            return 'ready';
        case 'connecting':
            return 'progress';
        case 'reauthorization_required':
        case 'needs_registration':
        case 'billing_unproven':
            return 'action';
        case 'expired':
        case 'error':
            return 'error';
        default:
            return 'idle';
    }
}

interface Props {
    link: WhatsappLink;
    /** أيستقبل؟ — من الخادم، ولا يُحسب هنا */
    health?: LinkHealth | null;
    config: EmbeddedConfig;
    mayManage: boolean;
    /**
     * أيُرسل المتجر من رقم أبعاد ما دام رقمُه غيرَ مربوط؟ — مفتوحٌ افتراضًا.
     * ومن أُغلق عنه لا يُقال له إنّ رسائله تخرج من رقم أبعاد: لا تخرج.
     */
    sharedAllowed?: boolean;
    /** الربطُ بتطبيق التاجر — يحلّ محلَّ زرّ تطبيق أبعاد حين يكون الطريقَ (`WhatsAppOwnApp`) */
    ownApp?: OwnAppView | null;
}

/**
 * بطاقةُ «ربط واتساب» — حالٌ واحدة، وزرٌّ واحد، ولا رمزَ يُلصق.
 *
 * ═══ ولمَ زالت حقولُ اللصق من هنا ═══
 *
 * لم تزل: الطريقُ اليدويّ باقٍ تحت «طريقة يدويّة» لمن ربط قبل اليوم أو
 * لمن لا تُتيح ميتا له التسجيل المدمج. وما تغيّر أنّ الطريق الأوّل صار
 * ضغطةً: لا حساب مطوّرين، ولا رمزٌ دائمٌ يُصنع بيد التاجر ويُلصق في حقلٍ
 * يمرّ بالمتصفّح.
 */
export default function WhatsappLinkCard({ link, health = null, config, mayManage, sharedAllowed = true, ownApp = null }: Props) {
    const own = ownApp?.enabled === true;
    const t = useTranslate();
    const [sending, setSending] = useState(false);
    /* رقمٌ مستقلٌّ افتراضًا — والتعايشُ يُختار صراحةً أو لا يكون */
    const [kind, setKind] = useState<OnboardingKind>('cloud_api');
    /* نافذةُ التأكيد من النظام لا من المتصفّح — انظر ConfirmDialog */
    const [ask, dialog] = useConfirm();

    /*
     * الكودُ يُرسَل إلى الخادم بجلسة المستخدم ورمز CSRF — لا إلى ميتا.
     *
     * و`router.post` يحمل رمز CSRF وحدَه؛ ولا يُكتب الكودُ في أيّ مخزنٍ ولا
     * يُقرأ من عنوان: يعيش في ذاكرة الصفحة ثوانيَ ثمّ يُنسى.
     */
    const submit = useCallback((code: string, assets: SignupAssets) => {
        setSending(true);

        router.post(
            route('admin.integrations.whatsapp.embedded.callback'),
            { code, waba_id: assets.waba_id ?? '', phone_number_id: assets.phone_number_id ?? '', type: kind },
            {
                preserveScroll: true,
                onFinish: () => setSending(false),
            },
        );
    }, [kind]);

    const autoReply = useForm({});
    // رمزُ التحقّق بخطوتين — يمرّ إلى الخادم ثمّ إلى ميتا، ولا يُحفظ في أيٍّ منهما
    const registration = useForm({ pin: '' });

    const state = link.state;
    const connected = state === 'connected';

    return (
        <SettingsSection
            title="ربط واتساب"
            description="اربط رقم واتساب API الخاص بمتجرك عبر تطبيق أبعاد — ثم نتحقق من وصول رسائل عملائك قبل أن نقول إنه يستقبل."
            icon={Smartphone}
            status={<StatusPill state={pillFor(state)} label={t(link.label)} connected={connected} />}
        >
            {/* ١ · ما يقوله الحال — جملةٌ واحدة لكلّ حالة، لا شارةٌ وحدَها */}
            <p
                data-testid="link-line"
                className={
                    state === 'expired' || state === 'error'
                        ? 'rounded-[10px] bg-[#fef2f2] px-3 py-2 text-[12px] leading-relaxed text-[#b91c1c]'
                        : state === 'reauthorization_required'
                          ? 'rounded-[10px] bg-[#fffbeb] px-3 py-2 text-[12px] leading-relaxed text-[#b45309]'
                          : 'text-[13px] leading-relaxed text-[#6b7280]'
                }
            >
                {state === 'connected' && t('رقمك مربوط، والرسائل تخرج منه.')}
                {state === 'connecting' && t('تمّ التفويض — وحسابك لم يُظهر رقمًا بعد. تكتمل عادةً خلال دقائق.')}
                {state === 'billing_unproven' &&
                    t('رُبط رقمك بتطبيقك، ولم يثبت أنّ رسائل حسابك تُحتسب عليك عند ميتا — لا تخرج رسالة حتى يثبت. أرسل لأبعاد ما يثبت وسيلة الدفع في حسابك.')}
                {state === 'needs_registration' &&
                    t('رُبط رقمك ولم يُفعَّل في واتساب للأعمال بعد — اكتب رمز التحقّق بخطوتين (ستّة أرقام) ليبدأ الإرسال منه.')}
                {state === 'disconnected' &&
                    (sharedAllowed
                        ? t('لم يُربط رقمٌ بعد — تخرج رسائلك من رقم أبعاد المشترك.')
                        : t('واتساب غير مربوط — اربط رقم متجرك لتخرج رسائلك منه. لا رسالةَ تخرج قبل ذلك.'))}
                {state === 'reauthorization_required' &&
                    (link.alert_days
                        ? t('ينتهي تفويض واتساب خلال أقل من :d يومًا — جدّده قبل أن تقف الرسائل.', {
                              d: String(link.alert_days),
                          })
                        : t('تفويض واتساب يحتاج تجديدًا — جدّده قبل أن تقف الرسائل.'))}
                {state === 'expired' && t('انتهت صلاحية التفويض — لا تخرج رسالةٌ حتى تُعيد الربط.')}
                {state === 'error' && t('حدث خطأ في الربط — أعد المحاولة، وإن تكرّر راجع أبعاد.')}
            </p>

            {/*
                ١ب · أيستقبل؟ — «متّصل» يقول إنّ الرقم يُرسل، وهذا يقول هل يصلنا
                ما يكتبه الزبائن: تطبيقُ أبعاد مشتركٌ، أو إشعارٌ وصل فعلًا.
            */}
            {health && health.key !== 'awaiting_authorization' && (
                <div data-testid="link-health" data-state={health.key} className={cn('mt-3 rounded-[10px] px-3 py-2 text-[12px] leading-relaxed', HEALTH_TONE[health.tone])}>
                    <p className="font-semibold">{t(health.label)}</p>
                    <p className="mt-0.5">{t(health.hint)}</p>
                    {health.checked_at && <p className="mt-0.5 opacity-80" dir="auto">{t('آخر فحص')}: <span dir="ltr">{health.checked_at}</span></p>}
                    <dl className="mt-1 flex flex-wrap gap-x-4 gap-y-0.5 opacity-80" data-testid="link-health-facts">
                        <div>
                            <dt className="inline">{t('آخر حالة تسليم')}: </dt>
                            <dd className="inline" dir="ltr">{health.last_status_at ?? '—'}</dd>
                        </div>
                        <div>
                            <dt className="inline">{t('آخر رسالة عميل وصلت')}: </dt>
                            <dd className="inline" dir="ltr">{health.last_inbound_at ?? '—'}</dd>
                        </div>
                        <div>
                            <dt className="inline">{t('آخر رسالة حُفظت في المحادثات')}: </dt>
                            <dd className="inline" dir="ltr" data-testid="link-health-saved">{health.last_inbound_saved_at ?? '—'}</dd>
                        </div>
                        {health.inbound_problem && (
                            <div className="basis-full" data-testid="link-health-refusal">
                                <dt className="inline">{t('لم تُحفظ آخر رسالة')}: </dt>
                                <dd className="inline">{t(health.inbound_problem)}</dd>
                            </div>
                        )}
                    </dl>
                </div>
            )}

            {/*
                ورسالةُ ميتا تُعرض كما قالتها.

                «الرقم غير مؤهّل» و«الحساب موقوف» جملٌ تقولها ميتا للتاجر في
                لوحتها، وإخفاؤها يترك من يقرأ «حدث خطأ» بلا طريق. ولا شيء
                منها سرٌّ: لا رمزَ فيها ولا مفتاح.
            */}
            {link.error_message && (
                <p data-testid="meta-error" dir="auto" className="mt-2 text-[12px] leading-relaxed text-[#9ca3af]">
                    {t('ميتا تقول')}: {link.error_message}
                </p>
            )}

            {/* ٢ · ما رُبط — يُقرأ بعد النجاح: الرقم واسمه وحساب الأعمال */}
            {/*
                ١ج · تفعيلُ رقمٍ جديد — ميتا تطلبه بعد التسجيل المدمج لرقمٍ لم يُسجَّل
                في Cloud API. ورقمٌ مسجَّلٌ أصلًا لا يصل هذه الحال (`WhatsAppOnboarding`).
            */}
            {state === 'needs_registration' && mayManage && (
                <form
                    className="mt-3 flex flex-wrap items-end gap-2"
                    data-testid="register-number"
                    onSubmit={(e) => {
                        e.preventDefault();
                        registration.post(route('admin.integrations.whatsapp.register'), {
                            preserveScroll: true,
                            onFinish: () => registration.reset('pin'),
                        });
                    }}
                >
                    <label className="flex flex-col gap-1 text-[12px] text-[#6b7280]">
                        {t('رمز التحقّق بخطوتين')}
                        <Input
                            dir="ltr"
                            inputMode="numeric"
                            autoComplete="one-time-code"
                            maxLength={6}
                            value={registration.data.pin}
                            onChange={(e) => registration.setData('pin', e.target.value.replace(/\D/g, ''))}
                            className="w-32 tabular-nums"
                            data-testid="register-pin"
                        />
                    </label>
                    <Button type="submit" loading={registration.processing} disabled={registration.data.pin.length !== 6} data-testid="register-submit">
                        <KeyRound />
                        {t('تفعيل الرقم')}
                    </Button>
                    <p className="basis-full text-[12px] leading-relaxed text-[#6b7280]">
                        {t('إن كان لرقمك رمزٌ مضبوط فاكتبه هو، وإلّا صار ما تكتبه رمزَه. لا يُحفظ عندنا.')}
                    </p>
                    {registration.errors.pin && (
                        <p className="basis-full text-[12px] text-[#b91c1c]" data-testid="register-error">
                            {registration.errors.pin}
                        </p>
                    )}
                </form>
            )}

            {(connected || state === 'connecting' || state === 'billing_unproven' || state === 'needs_registration' || state === 'reauthorization_required') && (
                <dl className="mt-4 space-y-2 rounded-[12px] bg-[#fafafa] p-4 text-[13px]" data-testid="link-facts">
                    {link.display_phone_number && (
                        <div className="flex flex-wrap justify-between gap-3">
                            <dt className="text-[#6b7280]">{t('الرقم')}</dt>
                            <dd dir="ltr" className="font-medium text-[#111]">{link.display_phone_number}</dd>
                        </div>
                    )}
                    {link.waba_id && (
                        <div className="flex flex-wrap justify-between gap-3">
                            <dt className="text-[#6b7280]">{t('حساب واتساب للأعمال (WABA ID)')}</dt>
                            <dd dir="ltr" className="font-medium tabular-nums text-[#111]">{link.waba_id}</dd>
                        </div>
                    )}
                    {link.coexistence && (
                        <div className="flex flex-wrap justify-between gap-3">
                            <dt className="text-[#6b7280]">{t('تطبيق واتساب للأعمال')}</dt>
                            <dd className="font-medium text-[#047857]">{t('يعمل مع النظام على الرقم نفسه')}</dd>
                        </div>
                    )}
                    {link.expires_at && (
                        <div className="flex flex-wrap justify-between gap-3">
                            <dt className="text-[#6b7280]">{t('ينتهي التفويض')}</dt>
                            <dd dir="ltr" className="font-medium text-[#111]">{link.expires_at}</dd>
                        </div>
                    )}
                    {link.last_webhook_at && (
                        <div className="flex flex-wrap justify-between gap-3">
                            <dt className="text-[#6b7280]">{t('آخر إشعار من ميتا')}</dt>
                            <dd dir="ltr" className="font-medium text-[#111]">{link.last_webhook_at}</dd>
                        </div>
                    )}
                </dl>
            )}

            {/*
                ٣ · إعدادُ الخادم ناقص — يُقال ولا يُترك الزرُّ يفتح نافذةً تفشل.

                ومن يقرأ هذا ليس التاجرَ وحده: هو خبرٌ لمن يُدير الخادم.
            */}
            {/* ٢ · الربطُ بتطبيق التاجر — دليلُه ونموذجُه وعنوانُ إشعاراته */}
            {own && ownApp && <OwnAppLink ownApp={ownApp} mayManage={mayManage} />}

            {! own && ! config.configured && (
                <p className="mt-4 rounded-[10px] bg-[#f5f3ff] px-3 py-2 text-[12px] leading-relaxed text-[#5b21b6]">
                    {t('الربط بضغطة غير مهيّأ على الخادم بعد — استعمل الطريقة اليدوية أدناه، أو راجع أبعاد.')}
                </p>
            )}

            {/*
                ٤ · نوعُ الرقم — يختاره التاجر، ولا يُفترض التعايش.

                رقمٌ مستقلٌّ للنظام هو الافتراض: رقمُ هاتفك الذي تستعمله لا يُمسّ.
                والتعايشُ (الرقمُ نفسُه في تطبيق الأعمال وفي النظام) يُختار صراحةً،
                ويبقى مطفأً بسببه ما لم يُفتح على الخادم: ربطُه بلا مزامنةٍ
                يُضيّع تاريخَ محادثاته مرّةً واحدة.
            */}
            {! own && mayManage && config.configured && (
                <fieldset className="mt-4 space-y-2" data-testid="link-kind">
                    <legend className="mb-1 text-[12px] font-medium text-[#374151]">{t('نوع الرقم')}</legend>
                    {(['cloud_api', 'coexistence'] as const).map((option) => {
                        const locked = option === 'coexistence' && ! config.coexistence_open;

                        return (
                            <label
                                key={option}
                                data-testid={`link-kind-${option}`}
                                className={cn(
                                    'flex items-start gap-2 rounded-[10px] border px-3 py-2 text-[12px] leading-relaxed',
                                    kind === option ? 'border-[#6d28d9] bg-[#f5f3ff]' : 'border-[var(--ui-border,#e8e8e8)]',
                                    locked && 'cursor-not-allowed opacity-60',
                                )}
                            >
                                <input
                                    type="radio"
                                    name="whatsapp-link-kind"
                                    className="mt-1"
                                    value={option}
                                    checked={kind === option}
                                    disabled={locked}
                                    onChange={() => setKind(option)}
                                />
                                <span>
                                    <span className="block font-medium text-[#111]">
                                        {t(option === 'cloud_api' ? 'رقم Cloud API مستقل' : 'رقم على تطبيق واتساب للأعمال (التعايش)')}
                                    </span>
                                    <span className="block text-[#6b7280]">
                                        {option === 'cloud_api'
                                            ? t('رقم مخصص للنظام منفصل عن رقم هاتفك — رقم واتساب في هاتفك لا يُمس.')
                                            : locked
                                              ? t('غير متاح بعد في أبعاد — يحتاج إعدادًا في تطبيق Meta ومزامنة لم تُبنَ بعد.')
                                              : t('الرقم نفسه يبقى في تطبيق واتساب للأعمال على هاتفك ويصل النظام معًا.')}
                                    </span>
                                </span>
                            </label>
                        );
                    })}
                    {kind === 'coexistence' && (
                        <p className="flex items-start gap-2 text-[12px] leading-relaxed text-[#6b7280]">
                            <AlertTriangle className="mt-0.5 size-4 shrink-0 text-[#d97706]" />
                            {t('بقاء رقمك في تطبيق واتساب للأعمال تُقرّره ميتا بحسب أهلية حسابك ونسخة تطبيقك — ونحن لا نُلغي تسجيل رقمك في كل الأحوال.')}
                        </p>
                    )}
                </fieldset>
            )}

            <PageActions className="mt-5">
                {! own && mayManage && config.configured && (
                    <EmbeddedSignup
                        config={config}
                        kind={kind}
                        onCode={submit}
                        onCancel={() => toast.message(t('أُلغي الربط قبل أن يكتمل.'))}
                        onError={() => toast.error(t('تعذّر فتح نافذة ميتا — أعد المحاولة.'))}
                    >
                        {({ open, busy }) => (
                            <Button type="button" onClick={open} loading={busy || sending} data-testid="connect-whatsapp">
                                {state === 'disconnected' ? <PlugZap /> : <RefreshCw />}
                                {t(state === 'disconnected' ? 'ربط واتساب API' : 'إعادة تفويض واتساب')}
                            </Button>
                        )}
                    </EmbeddedSignup>
                )}

                {connected || state === 'reauthorization_required' ? (
                    <Button
                        type="button"
                        variant="outline"
                        loading={autoReply.processing}
                        onClick={() =>
                            autoReply.post(route('admin.integrations.whatsapp.test'), { preserveScroll: true })
                        }
                        data-testid="test-whatsapp"
                    >
                        <MessageSquare />
                        {t('فحص الربط')}
                    </Button>
                ) : null}

                {/*
                    والفصلُ محلّيّ — ولا يُحذف رقمٌ عند ميتا ولا يُفكّ تسجيلُه.

                    من فصل عندنا يبقى رقمُه في حسابه وفي تطبيقه كما هو، ويعود
                    بضغطةٍ متى شاء. وحذفُ الرقم عند ميتا فعلٌ لا رجعةَ فيه، ولا
                    يقع من زرٍّ في لوحتنا.
                */}
                {mayManage && state !== 'disconnected' && (
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={async () => {
                            const yes = await ask({
                                title: 'فصل التكامل',
                                message: 'فصل التكامل من أبعاد فقط — يبقى رقمك في حسابك على ميتا وفي تطبيق واتساب للأعمال. متابعة؟',
                                danger: true,
                                action: 'فصل التكامل',
                            });

                            if (yes) router.delete(route('admin.integrations.whatsapp.disconnect'), { preserveScroll: true });
                        }}
                        data-testid="disconnect-whatsapp"
                    >
                        <Unplug />
                        {t('فصل التكامل')}
                    </Button>
                )}
            </PageActions>

            {dialog}
        </SettingsSection>
    );
}
