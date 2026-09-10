import { Link, router, useForm, usePage } from '@inertiajs/react';
import { MessageCircle, Save, SlidersHorizontal } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import Field from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
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
                    icon={MessageCircle}
                    name={t('واتساب بزنس')}
                    line={t('يصل العميل خبرُ طلبه على واتساب لحظةَ تغيّره — بلا أن يتّصل أحد.')}
                    tool="whatsapp"
                    tint="#25d366"
                    note={automation.global_enabled ? null : t('واتساب غير مفعَّل في المنصّة بعد — يفتحه أبعاد.')}
                />
            </AdminLayout>
        );
    }

    return (
        <AdminLayout title="واتساب بزنس">
            <PageHeader
                title="واتساب بزنس"
                subtitle={t('الوصلة ووضعُ الإرسال — وأيُّ رسالةٍ تخرج قرارٌ في «إشعارات واتساب»')}
            />

            <ConnectSteps
                readiness={automation.readiness}
                title={t('مراحل الربط')}
                done={`${t('جاهز — تخرج الرسائل عبر')} ${t(automation.sending_via)}`}
                waiting={t('لا تخرج رسالةٌ واحدة قبل أن تكتمل هذه المراحل.')}
            />

            {/* وبطاقةٌ فارغة لا تُرسم: من لا حصّة له ولا رقمَ خاصًّا لا شيء له هنا */}
            {(automation.usage || automation.own_allowed || automation.mode === 'business_own') && (
            <Card className="mb-6 max-w-3xl p-6">

                {/* الاستهلاك للمشترك وحده: من ربط رقمه يُرسل على حسابه فلا حدَّ عليه منّا */}
                {automation.usage && (
                    <dl className="mb-5 space-y-2 rounded-[12px] bg-[#fafafa] p-4 text-[13px]">
                        <div className="flex justify-between gap-3">
                            <dt className="text-[#6b7280]">{t('رسائل هذا الشهر')}</dt>
                            <dd className="font-medium text-[#111]" dir="ltr">
                                {automation.usage.unlimited
                                    ? String(automation.usage.used)
                                    : `${automation.usage.used} / ${automation.usage.limit}`}
                            </dd>
                        </div>
                        <div className="flex justify-between gap-3">
                            <dt className="text-[#6b7280]">{t('المتبقّي')}</dt>
                            <dd className="font-medium text-[#111]" dir="ltr">
                                {automation.usage.unlimited ? t('بلا حد') : String(automation.usage.remaining)}
                            </dd>
                        </div>
                        {automation.usage.is_exhausted && (
                            <p className="text-[12px] text-[#b45309]">
                                {t('نفدت رسائل هذا الشهر — الطلبات تعمل كالمعتاد، والرسائل تعود مع الشهر الجديد.')}
                            </p>
                        )}
                    </dl>
                )}

                {/*
                    ومن سُحبت منه الميزة وهو على رقمه لا يبقى واقفًا.

                    الوضع يبقى `business_own` ولا يُدفع إلى الرقم المشترك
                    بصمت — وهو الصواب. لكنّ بطاقة الربط تختفي معها أزرارُ
                    التبديل، فيقرأ «بدّل الإرسال إلى رقم أبعاد» ولا يجد زرًّا.
                */}
                {! automation.own_allowed && automation.mode === 'business_own' && (
                    <div className="mt-6 border-t border-[var(--ui-border,#e8e8e8)] pt-6">
                        <h4 className="mb-1 font-bold text-[#111]">{t('الرقم الذي يُرسل منه')}</h4>
                        <p className="mb-4 text-[13px] text-[#6b7280]">
                            {t('متجرك مضبوطٌ على رقمه الخاص وميزتُه غير مفعّلة الآن — فلا تخرج رسالة.')}
                        </p>
                        <Button
                            type="button"
                            onClick={() =>
                                router.post(
                                    route('admin.integrations.whatsapp.mode'),
                                    { mode: 'abaad_shared' },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            {t('أرسل عبر أبعاد')}
                        </Button>
                    </div>
                )}

                {/* ربط رقم المتجر — لمن مُنح الميزة وحده */}
                {automation.own_allowed && (
                    <div className="mt-6 border-t border-[var(--ui-border,#e8e8e8)] pt-6">
                        <h4 className="mb-1 font-bold text-[#111]">{t('رقم متجرك على واتساب')}</h4>
                        <p className="mb-4 text-[13px] text-[#6b7280]">
                            {t('المعرّفان والرمز من حساب المطوّرين في ميتا — والرمز يُخزَّن مشفَّرًا ولا يُعرض بعد الحفظ.')}
                        </p>

                        {automation.own_connection?.usable ? (
                            <>
                                <p className="mb-4 text-[13px] text-[#166534]">
                                    {t('مربوط')} —{' '}
                                    <span dir="ltr">
                                        {automation.own_connection.display_phone_number ??
                                            automation.own_connection.phone_number_id}
                                    </span>
                                </p>

                                <div className="flex flex-wrap gap-2">
                                    <Button
                                        type="button"
                                        variant={automation.mode === 'business_own' ? 'primary' : 'outline'}
                                        onClick={() =>
                                            router.post(
                                                route('admin.integrations.whatsapp.mode'),
                                                { mode: 'business_own' },
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        {t('أرسل من رقم متجري')}
                                    </Button>
                                    <Button
                                        type="button"
                                        variant={automation.mode === 'abaad_shared' ? 'primary' : 'outline'}
                                        onClick={() =>
                                            router.post(
                                                route('admin.integrations.whatsapp.mode'),
                                                { mode: 'abaad_shared' },
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        {t('أرسل عبر أبعاد')}
                                    </Button>
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
                                </div>
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

                                <div className="flex justify-end">
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
                                </div>
                            </div>
                        )}
                    </div>
                )}
            </Card>
            )}

            {/*
                وبابُ ما بعد الربط يُقال هنا لا يُترك ليُبحث عنه.

                من أتمّ الوصلة يسأل بعدها سؤالًا واحدًا: «أيُّ رسالةٍ تخرج؟» —
                وجوابُه في قسمٍ آخر. فيُقاد إليه بدل أن يعود إلى القائمة يبحث.
            */}
            <Card className="flex max-w-3xl flex-wrap items-center justify-between gap-4 p-6">
                <div>
                    <h3 className="font-bold text-[#111]">{t('أيُّ رسالةٍ تخرج ومتى')}</h3>
                    <p className="mt-0.5 text-[13px] text-[#6b7280]">
                        {t('اختيارُ الأحداث في «إشعارات واتساب» — تحت أدوات التسويق.')}
                    </p>
                </div>
                <Button asChild variant="outline">
                    <Link href={route('admin.marketing.whatsapp')}>
                        <SlidersHorizontal />
                        {t('إشعارات واتساب')}
                    </Link>
                </Button>
            </Card>
        </AdminLayout>
    );
}
