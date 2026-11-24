import { useCallback, useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { CheckCircle2, KeyRound, Link2Off, PlugZap } from 'lucide-react';
import AuthLayout from '@/Layouts/AuthLayout';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import EmbeddedSignup, { type EmbeddedConfig, type OnboardingKind, type SignupAssets } from '@/Pages/Admin/Integrations/partials/EmbeddedSignup';
import LinkKinds from '@/Pages/Admin/Integrations/partials/LinkKinds';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

/** حالُ الرابط كما حسبها الخادم — انظر App\Support\WhatsAppClientLinks */
export interface ClientLink {
    state: 'open' | 'used' | 'expired' | 'revoked' | 'exhausted' | 'closed' | 'missing';
    /** اسمُ المتجر — لرابطٍ يعمل، أو لمن ربط به من هذا المتصفّح */
    shop: string | null;
    expires: string | null;
    /** الرقمُ الذي رُبط — للمتصفّح الذي ربطه وحده */
    phone: string | null;
    needs_pin: boolean;
}

interface Props {
    link: ClientLink;
    config: EmbeddedConfig | null;
    year: number;
}

/** لماذا لا يعمل الرابط — جملةٌ لكلّ حال، وما بعدها واحدٌ لكلّها */
const DEAD: Record<Exclude<ClientLink['state'], 'open'>, string> = {
    used: 'استُعمل هذا الرابط من قبل',
    expired: 'انتهت مدّة هذا الرابط',
    revoked: 'أُلغي هذا الرابط',
    exhausted: 'استُنفدت محاولات هذا الرابط',
    closed: 'لا يمكن الربط من هذا الرابط الآن',
    missing: 'افتح الرابط الذي وصلك',
};

/**
 * صفحةُ ربط واتساب لصاحب المتجر — من رابطٍ أرسله فريقُ أبعاد، بلا حسابٍ ولا دخول.
 *
 * ═══ خطوةٌ واحدة ═══
 *
 * يقرأ اسمَ متجره ويُقرّ أنّه متجرُه، ويختار طريقةَ الربط، ويضغط — فتفتح نافذةُ Meta. والنجاحُ ما يقوله
 * الخادم بعد أن يفحص الرقم، لا ما تقوله النافذة.
 *
 * ولا شريطَ تنبيهات هنا (هذه أبوابُ الزائر): ما يُقال يُكتب في الصفحة ويبقى.
 */
export default function WhatsappLinkPage() {
    const { link, config, year, flash, errors } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const [kind, setKind] = useState<OnboardingKind>('cloud_api');
    const [confirmed, setConfirmed] = useState(false);
    const [sending, setSending] = useState(false);
    const [note, setNote] = useState<string | null>(null);

    const submit = useCallback((code: string, assets: SignupAssets) => {
        setSending(true);
        setNote(null);

        router.post(
            route('clientLink.connect'),
            { code, waba_id: assets.waba_id ?? '', phone_number_id: assets.phone_number_id ?? '', type: kind, shop_confirmed: confirmed },
            { preserveScroll: true, onFinish: () => setSending(false) },
        );
    }, [kind, confirmed]);

    const problem = Object.entries((errors ?? {}) as Record<string, string>).find(([field]) => field !== 'pin')?.[1] ?? null;

    return (
        <AuthLayout title={t('ربط واتساب')} year={year}>
            <Card className="p-5 sm:p-6">
                {link.state === 'open' && config ? (
                    <>
                        <h1 className="text-[18px] font-bold text-[#111]">{t('اربط واتساب متجرك')}</h1>
                        <p className="mt-2 text-[13px] leading-relaxed text-[#6b7280]">
                            {t('أرسل لك فريق أبعاد هذا الرابط لتربط رقم واتساب متجرك بالنظام. لا تحتاج حسابًا في أبعاد، وكلمة مرور فيسبوك تكتبها عند Meta وحدها.')}
                        </p>

                        <div className="mt-4 rounded-[12px] bg-[#fafafa] p-4" data-testid="client-link-shop">
                            <p className="text-[12px] text-[#6b7280]">{t('المتجر الذي سيُربط به الرقم')}</p>
                            <p className="mt-0.5 text-[16px] font-bold text-[#111]" dir="auto">{link.shop}</p>
                            {link.expires && (
                                <p className="mt-1 text-[12px] text-[#6b7280]">
                                    {t('يعمل الرابط مرّة واحدة، وينتهي في')} <span dir="ltr" className="tabular-nums">{link.expires}</span>
                                </p>
                            )}
                        </div>

                        <LinkKinds config={config} kind={kind} onKind={setKind} />

                        <label className="mt-4 flex cursor-pointer items-start gap-2 rounded-[12px] border border-[var(--ui-border,#e8e8e8)] p-3 text-[13px] leading-relaxed text-[#374151]">
                            <input
                                type="checkbox"
                                className="mt-1 size-4 shrink-0 accent-[#111]"
                                checked={confirmed}
                                onChange={(e) => setConfirmed(e.target.checked)}
                                data-testid="client-link-confirm"
                            />
                            <span>{t('«:shop» متجري، وأوافق أن يرسل أبعاد رسائله ويستقبلها من الرقم الذي سأربطه. وأستطيع سحب ذلك من إعدادات نشاطي عند Meta.', { shop: link.shop ?? '' })}</span>
                        </label>

                        {flash.status && (
                            <p className="mt-3 rounded-[10px] bg-[#eff6ff] px-3 py-2 text-[12px] leading-relaxed text-[#1d4ed8]" data-testid="client-link-status">{flash.status}</p>
                        )}
                        {(problem || note) && (
                            <p role="alert" className="mt-3 rounded-[10px] bg-[#fef2f2] px-3 py-2 text-[12px] leading-relaxed text-[#b91c1c]" data-testid="client-link-problem">
                                {problem ?? note}
                            </p>
                        )}

                        <EmbeddedSignup
                            config={config}
                            kind={kind}
                            preload
                            onCode={submit}
                            onCancel={() => setNote(t('أُلغي الربط قبل أن يكتمل.'))}
                            onError={() => setNote(t('تعذّر فتح نافذة ميتا — أعد المحاولة.'))}
                        >
                            {({ open, busy }) => (
                                <Button type="button" className="mt-4 w-full" onClick={open} loading={busy || sending} disabled={! confirmed} data-testid="client-link-connect">
                                    <PlugZap />
                                    {t(kind === 'coexistence' ? 'ربط واتساب مع التطبيق' : 'ربط واتساب')}
                                </Button>
                            )}
                        </EmbeddedSignup>

                        <p className="mt-3 text-[12px] leading-relaxed text-[#6b7280]">
                            {t('إن لم تفتح نافذة Meta، فافتح هذا الرابط في متصفّح Chrome أو Safari واسمح بالنوافذ المنبثقة.')}
                        </p>
                    </>
                ) : link.phone ? (
                    <Linked link={link} />
                ) : (
                    <div className="flex flex-col items-center py-4 text-center" data-testid="client-link-dead" data-state={link.state}>
                        <Link2Off className="size-9 text-[#9ca3af]" />
                        <h1 className="mt-3 text-[17px] font-bold text-[#111]">{t(DEAD[link.state === 'open' ? 'closed' : link.state])}</h1>
                        <p className="mt-2 text-[13px] leading-relaxed text-[#6b7280]">
                            {t(link.state === 'missing'
                                ? 'ادخل إليه من الرسالة التي وصلتك من فريق أبعاد.'
                                : 'إن كنت تحتاج رابطًا جديدًا فاطلبه من فريق أبعاد. ويمكنك الربط من حساب متجرك في أيّ وقت.')}
                        </p>
                        {problem && <p role="alert" className="mt-3 text-[12px] text-[#b91c1c]" data-testid="client-link-problem">{problem}</p>}
                    </div>
                )}
            </Card>
        </AuthLayout>
    );
}

/** بعد الربط — الرقمُ ومتجرُه، ورمزُ التفعيل إن كان رقمًا جديدًا */
function Linked({ link }: { link: ClientLink }) {
    const { flash } = usePage<PageProps>().props;
    const t = useTranslate();
    // رمزُ التحقّق بخطوتين — يمرّ إلى الخادم ثمّ إلى ميتا، ولا يُحفظ في أيٍّ منهما
    const registration = useForm({ pin: '' });

    return (
        <div data-testid="client-link-done">
            <div className="flex flex-col items-center text-center">
                <CheckCircle2 className="size-9 text-[#047857]" />
                <h1 className="mt-3 text-[17px] font-bold text-[#111]">{t(link.needs_pin ? 'بقيت خطوة واحدة' : 'رُبط رقمك')}</h1>
                <p className="mt-1 text-[15px] font-semibold text-[#111]" dir="ltr">{link.phone}</p>
                {link.shop && <p className="text-[13px] text-[#6b7280]" dir="auto">{link.shop}</p>}
            </div>

            {flash.status && (
                <p className="mt-4 rounded-[10px] bg-[#ecfdf5] px-3 py-2 text-[12px] leading-relaxed text-[#047857]" data-testid="client-link-status">{flash.status}</p>
            )}

            {link.needs_pin ? (
                <form
                    className="mt-4 space-y-2"
                    data-testid="client-link-register"
                    onSubmit={(e) => {
                        e.preventDefault();
                        registration.post(route('clientLink.register'), { preserveScroll: true, onFinish: () => registration.reset('pin') });
                    }}
                >
                    <label className="block text-[12px] text-[#6b7280]">
                        {t('رمز التحقّق بخطوتين')}
                        <Input
                            dir="ltr"
                            inputMode="numeric"
                            autoComplete="one-time-code"
                            maxLength={6}
                            value={registration.data.pin}
                            onChange={(e) => registration.setData('pin', e.target.value.replace(/\D/g, ''))}
                            className="mt-1 tabular-nums"
                            data-testid="client-link-pin"
                        />
                    </label>
                    <p className="text-[12px] leading-relaxed text-[#6b7280]">
                        {t('إن كان لرقمك رمزٌ مضبوط فاكتبه هو، وإلّا صار ما تكتبه رمزَه. لا يُحفظ عندنا.')}
                    </p>
                    {registration.errors.pin && <p role="alert" className="text-[12px] text-[#b91c1c]" data-testid="client-link-pin-error">{registration.errors.pin}</p>}
                    <Button type="submit" className="w-full" loading={registration.processing} disabled={registration.data.pin.length !== 6} data-testid="client-link-activate">
                        <KeyRound />
                        {t('تفعيل الرقم')}
                    </Button>
                </form>
            ) : (
                <p className="mt-4 text-center text-[13px] leading-relaxed text-[#6b7280]">{t('يظهر الرقم الآن في صفحة واتساب في حساب متجرك. يمكنك إغلاق هذه الصفحة.')}</p>
            )}
        </div>
    );
}
