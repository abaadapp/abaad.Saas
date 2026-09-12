import { Link, router, useForm, usePage } from '@inertiajs/react';
import { Gauge, Save, Smartphone, SlidersHorizontal } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import Field from '@/Components/Field';
import StatusPill, { readinessState } from '@/Components/StatusPill';
import { PageActions, SettingsPage, SettingsSection } from '@/Components/Settings';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { PasswordInput } from '@/Components/ui/password-input';
import { useTranslate } from '@/lib/i18n';
import { ConnectGate, ConnectSteps, type Readiness } from '@/Components/Connect';
import type { PageProps } from '@/types';

/** ما يُعرض من حال الأتمتة — بلا رمزٍ ولا سرّ (انظر Admin\WhatsAppController::view) */
export interface Automation {
    readiness: Readiness;
    global_enabled: boolean;
    enabled: boolean;
    mode: string;
    effective_mode: string;
    sending_via: string;
    own_allowed: boolean;
    own_connection: {
        status: string;
        usable: boolean;
        display_phone_number: string | null;
        phone_number_id?: string | null;
    } | null;
    shared_active: boolean;
    usage: {
        used: number;
        limit: number;
        unlimited: boolean;
        remaining: number | null;
        percentage: number | null;
        is_exhausted: boolean;
    } | null;
    events: { key: string; setting: string; label: string }[];
}

interface Props {
    automation: Automation;
}

/**
 * ربطُ واتساب — الوصلةُ ووضعُ الإرسال والحصّة، ولا مقبضَ حدثٍ واحد.
 *
 * ومقابضُ الأحداث في «إشعارات واتساب» تحت أدوات التسويق: تلك تُفتح كلّما
 * بُدّلت خطّةُ المتجر في مخاطبة زبائنه، وهذه تُفتح مرّةً عند الربط ثمّ لا
 * تُفتح. وجمعُهما كان يعني أنّ من يريد إطفاء رسالةٍ واحدة يمرّ على رمز
 * تفعيلٍ من ميتا لا شأن له به.
 *
 * ═══ ترتيبُ الشاشة ═══
 *
 * عمودٌ واحد من أعلى إلى أسفل: مراحلُ الربط، ثمّ الحصّة، ثمّ الرقمُ الذي
 * يُرسل منه، ثمّ البابُ التالي. وكانت الحصّةُ والرقمُ في بطاقةٍ واحدة
 * يفصلهما خطّ — وهما شيئان لا علاقة لأحدهما بالآخر: الحصّةُ تُقرأ، والرقمُ
 * يُضبط.
 */
export default function IntegrationsWhatsapp() {
    const { automation } = usePage<PageProps<Props>>().props;
    const t = useTranslate();

    /*
     * ربط رقم المتجر — نموذجٌ قائمٌ بذاته.
     *
     * الرمز يُرسل مرّةً ولا يُعاد إلى الشاشة، فلا يسكن في نموذجٍ يُعاد إرساله
     * مع كلّ «حفظ».
     */
    const connectForm = useForm({
        phone_number_id: '',
        waba_id: '',
        display_phone_number: '',
        access_token: '',
    });

    const setMode = (mode: 'business_own' | 'abaad_shared') =>
        router.post(route('admin.integrations.whatsapp.mode'), { mode }, { preserveScroll: true });

    /*
        بابٌ قبل الشاشة — لمن لم يبدأ.

        وكانت تُفتح على كلّ شيءٍ دفعةً واحدة: مراحلُ ربطٍ لم تبدأ، وحقولُ
        معرّفاتٍ من حساب ميتا. فيقرأ التاجر عشرين سطرًا ليعرف أنّ لا شيء
        منها يعمل بعد.
    */
    if (! automation.readiness.connected) {
        return (
            <AdminLayout title="واتساب بزنس">
                <PageHeader
                    title="واتساب بزنس"
                    subtitle={t('اربط واتساب ليصل العميل خبرُ طلبه لحظةَ تغيّره')}
                />
                <ConnectGate
                    name={t('واتساب بزنس')}
                    line={t('يصل العميل خبرُ طلبه على واتساب لحظةَ تغيّره — بلا أن يتّصل أحد.')}
                    tool="whatsapp"
                    note={automation.global_enabled ? null : t('واتساب غير مفعَّل في المنصّة بعد — يفتحه أبعاد.')}
                />
            </AdminLayout>
        );
    }

    const onOwn = automation.mode === 'business_own';

    return (
        <AdminLayout title="واتساب بزنس">
            <PageHeader
                title="واتساب بزنس"
                subtitle={t('الوصلة ووضعُ الإرسال — وأيُّ رسالةٍ تخرج قرارٌ في «إشعارات واتساب»')}
                actions={<StatusPill state={readinessState(automation.readiness)} connected />}
            />

            <SettingsPage>
                <ConnectSteps
                    readiness={automation.readiness}
                    title={t('مراحل الربط')}
                    done={`${t('جاهز — تخرج الرسائل عبر')} ${t(automation.sending_via)}`}
                    waiting={t('لا تخرج رسالةٌ واحدة قبل أن تكتمل هذه المراحل.')}
                />

                {/* الاستهلاك للمشترك وحده: من ربط رقمه يُرسل على حسابه فلا حدَّ عليه منّا */}
                {automation.usage && (
                    <SettingsSection
                        title="حصّة هذا الشهر"
                        description="رسائل أبعاد المشتركة — وتعود الحصّة مع الشهر الجديد."
                        icon={Gauge}
                        status={
                            automation.usage.is_exhausted
                                ? <StatusPill state="action" label="نفدت الحصّة" />
                                : <StatusPill state="ready" />
                        }
                    >
                        <dl className="space-y-2 rounded-[12px] bg-[#fafafa] p-4 text-[13px]">
                            <div className="flex justify-between gap-3">
                                <dt className="text-[#6b7280]">{t('رسائل هذا الشهر')}</dt>
                                <dd className="font-medium tabular-nums text-[#111]" dir="ltr">
                                    {automation.usage.unlimited
                                        ? String(automation.usage.used)
                                        : `${automation.usage.used} / ${automation.usage.limit}`}
                                </dd>
                            </div>
                            <div className="flex justify-between gap-3">
                                <dt className="text-[#6b7280]">{t('المتبقّي')}</dt>
                                <dd className="font-medium tabular-nums text-[#111]" dir="ltr">
                                    {automation.usage.unlimited ? t('بلا حد') : String(automation.usage.remaining)}
                                </dd>
                            </div>
                        </dl>

                        {automation.usage.is_exhausted && (
                            <p className="mt-3 rounded-[10px] bg-[#fffbeb] px-3 py-2 text-[12px] leading-relaxed text-[#b45309]">
                                {t('نفدت رسائل هذا الشهر — الطلبات تعمل كالمعتاد، والرسائل تعود مع الشهر الجديد.')}
                            </p>
                        )}
                    </SettingsSection>
                )}

                {/*
                    ومن سُحبت منه الميزة وهو على رقمه لا يبقى واقفًا.

                    الوضع يبقى `business_own` ولا يُدفع إلى الرقم المشترك
                    بصمت — وهو الصواب. لكنّ بطاقة الربط تختفي معها أزرارُ
                    التبديل، فيقرأ «بدّل الإرسال إلى رقم أبعاد» ولا يجد زرًّا.
                */}
                {! automation.own_allowed && onOwn && (
                    <SettingsSection
                        title="الرقم الذي يُرسل منه"
                        description="متجرك مضبوطٌ على رقمه الخاص وميزتُه غير مفعّلة الآن — فلا تخرج رسالة."
                        icon={Smartphone}
                        status={<StatusPill state="error" label="لا تخرج رسالة" />}
                    >
                        <PageActions>
                            <Button type="button" onClick={() => setMode('abaad_shared')}>
                                {t('أرسل عبر أبعاد')}
                            </Button>
                        </PageActions>
                    </SettingsSection>
                )}

                {/* ربط رقم المتجر — لمن مُنح الميزة وحده */}
                {automation.own_allowed && (
                    <SettingsSection
                        title="رقم متجرك على واتساب"
                        description="المعرّفان والرمز من حساب المطوّرين في ميتا — والرمز يُخزَّن مشفَّرًا ولا يُعرض بعد الحفظ."
                        icon={Smartphone}
                        status={
                            automation.own_connection?.usable
                                ? <StatusPill state="ready" connected />
                                : <StatusPill state="idle" label="غير مربوط" />
                        }
                    >
                        {automation.own_connection?.usable ? (
                            <>
                                <dl className="space-y-2 rounded-[12px] bg-[#fafafa] p-4 text-[13px]">
                                    <div className="flex flex-wrap justify-between gap-3">
                                        <dt className="text-[#6b7280]">{t('الرقم المربوط')}</dt>
                                        <dd dir="ltr" className="font-medium text-[#111]">
                                            {automation.own_connection.display_phone_number ??
                                                automation.own_connection.phone_number_id}
                                        </dd>
                                    </div>
                                    <div className="flex flex-wrap justify-between gap-3">
                                        <dt className="text-[#6b7280]">{t('تخرج الرسائل الآن من')}</dt>
                                        <dd className="font-medium text-[#111]">
                                            {t(onOwn ? 'رقم متجرك' : 'رقم أبعاد المشترك')}
                                        </dd>
                                    </div>
                                </dl>

                                {/*
                                    الفعلُ المعروض هو المتاح لا الاثنان: زرٌّ
                                    يعيد ضبط الوضع على وضعه الحاليّ لا يفعل
                                    شيئًا — ومن ضغطه مرّتين يظنّ العطب في يده.
                                */}
                                <PageActions className="mt-5">
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        onClick={() =>
                                            router.delete(route('admin.integrations.whatsapp.disconnect'), {
                                                preserveScroll: true,
                                            })
                                        }
                                    >
                                        {t('فصل الرقم')}
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => setMode(onOwn ? 'abaad_shared' : 'business_own')}
                                    >
                                        {t(onOwn ? 'أرسل عبر أبعاد بدلًا منه' : 'أرسل من رقم متجري')}
                                    </Button>
                                </PageActions>
                            </>
                        ) : (
                            <div className="space-y-4">
                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <Field
                                        label="معرّف الرقم (Phone Number ID)"
                                        error={connectForm.errors.phone_number_id}
                                    >
                                        <Input
                                            dir="ltr"
                                            value={connectForm.data.phone_number_id}
                                            onChange={(e) => connectForm.setData('phone_number_id', e.target.value)}
                                        />
                                    </Field>
                                    <Field
                                        label="معرّف حساب الأعمال (WABA ID)"
                                        error={connectForm.errors.waba_id}
                                    >
                                        <Input
                                            dir="ltr"
                                            value={connectForm.data.waba_id}
                                            onChange={(e) => connectForm.setData('waba_id', e.target.value)}
                                        />
                                    </Field>
                                </div>

                                <Field
                                    label="رمز الوصول الدائم"
                                    hint="يُخزَّن مشفَّرًا ولا يُعرض بعد الحفظ"
                                    error={connectForm.errors.access_token}
                                >
                                    <PasswordInput
                                        dir="ltr"
                                        value={connectForm.data.access_token}
                                        onChange={(e) => connectForm.setData('access_token', e.target.value)}
                                    />
                                </Field>

                                <PageActions>
                                    <Button
                                        type="button"
                                        loading={connectForm.processing}
                                        onClick={() =>
                                            connectForm.post(route('admin.integrations.whatsapp.connect'), {
                                                preserveScroll: true,
                                                onSuccess: () => connectForm.reset('access_token'),
                                            })
                                        }
                                    >
                                        <Save />
                                        {t('ربط الرقم')}
                                    </Button>
                                </PageActions>
                            </div>
                        )}
                    </SettingsSection>
                )}

                {/*
                    وبابُ ما بعد الربط يُقال هنا لا يُترك ليُبحث عنه.

                    من أتمّ الوصلة يسأل بعدها سؤالًا واحدًا: «أيُّ رسالةٍ تخرج؟» —
                    وجوابُه في قسمٍ آخر. فيُقاد إليه بدل أن يعود إلى القائمة يبحث.
                */}
                <SettingsSection
                    title="أيُّ رسالةٍ تخرج ومتى"
                    description="اختيارُ الأحداث في «إشعارات واتساب» — تحت أدوات التسويق."
                    icon={SlidersHorizontal}
                    action={
                        <Button asChild variant="outline">
                            <Link href={route('admin.marketing.whatsapp')}>
                                <SlidersHorizontal />
                                {t('إشعارات واتساب')}
                            </Link>
                        </Button>
                    }
                />
            </SettingsPage>
        </AdminLayout>
    );
}
