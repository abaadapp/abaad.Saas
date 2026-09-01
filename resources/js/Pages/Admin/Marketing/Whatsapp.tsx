import { router, useForm, usePage } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, Save } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import Toggle from '@/Components/Toggle';
import Field from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

/** ما يُعرض من حال الأتمتة — بلا رمزٍ ولا سرّ (انظر Admin\WhatsAppController::view) */
interface Automation {
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
    settings: Record<string, string>;
    automation: Automation;
}

export default function Whatsapp() {
    const { settings, automation } = usePage<PageProps<Props>>().props;
    const t = useTranslate();

    /*
     * ربط رقم المتجر — نموذجٌ منفصل عن نموذج القوالب.
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
     * أربعةُ مقابضَ لا أكثر — ولا مقبضَ لا يُدير شيئًا.
     *
     * كانت الشاشة تعرض معها مفتاح «تفعيل الإشعارات» وحقلَ «رقم المتجر»
     * وثلاثةَ نصوصِ رسائلَ بمعاينةٍ حيّة تحتها. يكتبها التاجر ويحفظها ولا
     * يقرأ منها أحدٌ حرفًا: الإطفاء في بطاقة الوصلة أعلاه، والرقم رقمُ
     * الوصلة المعتمدة، والنصّ قالبٌ معتمَدٌ عند ميتا باسمه — فميتا لا تقبل
     * نصًّا حرًّا في رسالةٍ يبدؤها العمل.
     */
    const form = useForm<Record<string, boolean>>(
        Object.fromEntries(automation.events.map((e) => [e.setting, settings[e.setting] === '1'])),
    );

    return (
        <AdminLayout title="إشعارات واتساب">
            <PageHeader
                title="إشعارات واتساب"
                subtitle={t('رسائل تُرسَل للعميل عند تغيّر حال طلبه')}
            />

            {/*
                الأتمتة — ما يُرسله النظام بنفسه، وهو غير ما تحته.
                والبطاقات التي تليه هي «افتح محادثة» القديمة: نصٌّ يُفتح بيد
                التاجر. الاثنان يتشاركان مقابض الأحداث نفسها فلا مفتاح مكرّر.
            */}
            <Card className="mb-6 max-w-3xl p-6">
                <h3 className="mb-1 font-bold text-[#111]">{t('الإرسال التلقائي')}</h3>
                <p className="mb-5 text-[13px] text-[#6b7280]">
                    {t('رسائل تخرج من النظام نفسه عند تغيّر حال الطلب — بلا فتح محادثة.')}
                </p>

                <div
                    className={
                        'mb-5 flex items-start gap-2 rounded-[10px] p-3 text-[13px] ' +
                        (automation.global_enabled && automation.enabled
                            ? 'bg-[#f0fdf4] text-[#166534]'
                            : 'bg-[#fef2f2] text-[#b91c1c]')
                    }
                >
                    {automation.global_enabled && automation.enabled ? (
                        <CheckCircle2 className="mt-0.5 size-4 shrink-0" />
                    ) : (
                        <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                    )}
                    <span>
                        {!automation.global_enabled || !automation.enabled
                            ? t('الإرسال التلقائي غير مفعّل لحسابك — راجع أبعاد.')
                            : `${t('مفعّل — يُرسل عبر')} ${t(automation.sending_via)}`}
                    </span>
                </div>

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

                {/* ربط رقم المتجر — لمن مُنح الميزة وحده */}
                {automation.own_allowed && (
                    <div className="mt-6 border-t border-[var(--ui-border,#e8e8e8)] pt-6">
                        <h4 className="mb-4 font-bold text-[#111]">{t('رقم متجرك على واتساب')}</h4>

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
                                                route('admin.marketing.whatsapp.mode'),
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
                                                route('admin.marketing.whatsapp.mode'),
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
                                            router.delete(route('admin.marketing.whatsapp.disconnect'), {
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
                                    <Input
                                        dir="ltr"
                                        type="password"
                                        value={connectForm.data.access_token}
                                        onChange={(e) => connectForm.setData('access_token', e.target.value)}
                                    />
                                </Field>

                                <div className="flex justify-end">
                                    <Button
                                        type="button"
                                        loading={connectForm.processing}
                                        onClick={() =>
                                            connectForm.post(route('admin.marketing.whatsapp.connect'), {
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

            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post(route('admin.marketing.whatsapp.save'), { preserveScroll: true });
                }}
                className="max-w-3xl space-y-6"
            >
                <Card className="p-6">
                    <h3 className="mb-1 font-bold text-[#111]">{t('متى تُرسَل الرسالة')}</h3>
                    <p className="mb-5 text-[13px] text-[#6b7280]">
                        {t('نصّ الرسالة قالبٌ معتمَدٌ لدى واتساب ولا يُكتب هنا — وهذه الأحداث قرارُك.')}
                    </p>

                    {/* من مصدرٍ واحد: WhatsAppEvent — حدثٌ يُضاف يظهر هنا بلا سطر */}
                    <div className="space-y-5">
                        {automation.events.map((e) => (
                            <Toggle
                                key={e.key}
                                label={e.label}
                                on={form.data[e.setting]}
                                onChange={(v) => form.setData(e.setting, v)}
                            />
                        ))}
                    </div>
                </Card>

                <div className="flex justify-end">
                    <Button type="submit" loading={form.processing}>
                        <Save />
                        {t('حفظ')}
                    </Button>
                </div>
            </form>
        </AdminLayout>
    );
}
