import { router, useForm } from '@inertiajs/react';
import { AlertTriangle, MessageSquare, PlugZap, RefreshCw, Smartphone, Unplug } from 'lucide-react';
import { useCallback, useState } from 'react';
import { toast } from 'sonner';
import StatusPill, { type SetupState } from '@/Components/StatusPill';
import { useConfirm } from '@/Components/ConfirmDialog';
import { PageActions, SettingsSection } from '@/Components/Settings';
import { Button } from '@/Components/ui/button';
import { useTranslate } from '@/lib/i18n';
import EmbeddedSignup, { type EmbeddedConfig, type SignupAssets } from '@/Pages/Admin/Integrations/partials/EmbeddedSignup';

/** حالُ الربط كما حسبها الخادم — انظر App\Support\WhatsAppLink */
export interface WhatsappLink {
    state: 'disconnected' | 'connecting' | 'connected' | 'reauthorization_required' | 'expired' | 'error';
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
    config: EmbeddedConfig;
    mayManage: boolean;
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
export default function WhatsappLinkCard({ link, config, mayManage }: Props) {
    const t = useTranslate();
    const [sending, setSending] = useState(false);
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
            { code, waba_id: assets.waba_id ?? '', phone_number_id: assets.phone_number_id ?? '' },
            {
                preserveScroll: true,
                onFinish: () => setSending(false),
            },
        );
    }, []);

    const autoReply = useForm({});

    const state = link.state;
    const connected = state === 'connected';

    return (
        <SettingsSection
            title="ربط واتساب"
            description="اربط رقم متجرك بضغطة — ويبقى الرقم في تطبيق واتساب للأعمال على هاتفك إن سمحت ميتا بذلك."
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
                {state === 'disconnected' && t('لم يُربط رقمٌ بعد — تخرج رسائلك من رقم أبعاد المشترك.')}
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
            {(connected || state === 'connecting' || state === 'reauthorization_required') && (
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
            {! config.configured && (
                <p className="mt-4 rounded-[10px] bg-[#f5f3ff] px-3 py-2 text-[12px] leading-relaxed text-[#5b21b6]">
                    {t('الربط بضغطة غير مهيّأ على الخادم بعد — استعمل الطريقة اليدوية أدناه، أو راجع أبعاد.')}
                </p>
            )}

            {/*
                ٤ · وأهليّةُ ميتا ليست بأيدينا.

                «التعايش» يفتحه لنا ميتا حين يعتمد تصنيفَ التطبيق. وقبله تعمل
                النافذةُ ويُربط الرقمُ ربطًا عاديًّا. وقولُ ذلك قبل الضغط خيرٌ
                من أن يكتشفه التاجر حين يخرج رقمُه من تطبيقه.
            */}
            {config.configured && ! link.coexistence && state !== 'connected' && (
                <p className="mt-4 flex items-start gap-2 text-[12px] leading-relaxed text-[#6b7280]">
                    <AlertTriangle className="mt-0.5 size-4 shrink-0 text-[#d97706]" />
                    {t('بقاء رقمك في تطبيق واتساب للأعمال تُقرّره ميتا بحسب أهلية حسابك ونسخة تطبيقك — ونحن لا نُلغي تسجيل رقمك في كل الأحوال.')}
                </p>
            )}

            <PageActions className="mt-5">
                {mayManage && config.configured && (
                    <EmbeddedSignup
                        config={config}
                        onCode={submit}
                        onCancel={() => toast.message(t('أُلغي الربط قبل أن يكتمل.'))}
                        onError={() => toast.error(t('تعذّر فتح نافذة ميتا — أعد المحاولة.'))}
                    >
                        {({ open, busy }) => (
                            <Button type="button" onClick={open} loading={busy || sending} data-testid="connect-whatsapp">
                                {state === 'disconnected' ? <PlugZap /> : <RefreshCw />}
                                {t(state === 'disconnected' ? 'ربط WhatsApp Business' : 'إعادة تفويض واتساب')}
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
                        {t('اختبار الاتصال')}
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
