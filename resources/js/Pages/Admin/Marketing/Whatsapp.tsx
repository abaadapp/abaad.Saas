import { router, useForm, usePage } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, MessageCircle, Save } from 'lucide-react';
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
    storeName: string | null;
    /** ما يقبله القالب من متغيّرات — تُعرض للتاجر بدل أن يخمّنها */
    variables: Record<string, string>;
    automation: Automation;
}

const TEMPLATES = [
    { key: 'wa_template_order', toggle: 'wa_on_order', label: 'عند استلام الطلب' },
    { key: 'wa_template_ready', toggle: 'wa_on_ready', label: 'عند جاهزية الطلب' },
    { key: 'wa_template_delivered', toggle: 'wa_on_delivered', label: 'عند التسليم' },
] as const;

export default function Whatsapp() {
    const { settings, storeName, variables, automation } = usePage<PageProps<Props>>().props;
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

    const form = useForm<Record<string, string | boolean>>({
        wa_enabled: settings.wa_enabled === '1',
        wa_number: settings.wa_number ?? '',
        wa_on_order: settings.wa_on_order === '1',
        wa_on_ready: settings.wa_on_ready === '1',
        wa_on_out_for_delivery: settings.wa_on_out_for_delivery === '1',
        wa_on_delivered: settings.wa_on_delivered === '1',
        wa_template_order: settings.wa_template_order ?? '',
        wa_template_ready: settings.wa_template_ready ?? '',
        wa_template_delivered: settings.wa_template_delivered ?? '',
    });

    /** المعاينة بقيمٍ حقيقية: قالبٌ بمتغيّراته الخام لا يُقرأ رسالةً */
    const preview = (template: string) =>
        template
            .replace(':store', storeName ?? t('متجري'))
            .replace(':number', 'ORD-1042')
            .replace(':total', '12.500')
            .replace(':customer', t('أحمد'));

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

                {/*
                    «خرج للتوصيل» — المقبض الوحيد الذي لم يكن له مكان.
                    والثلاثة الأخرى مقابضها في بطاقات القوالب أدناه، وهي
                    المفاتيح نفسها: لا مفتاح مكرّر يُطفئه التاجر في موضعٍ
                    ويبقى يعمل في الآخر.
                */}
                <Toggle
                    label="عند خروج الطلب للتوصيل"
                    hint="يُرسَل للمشتري حين ينتقل الطلب إلى «خرج للتوصيل»"
                    on={form.data.wa_on_out_for_delivery as boolean}
                    onChange={(v) => form.setData('wa_on_out_for_delivery', v)}
                />

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
                    <div className="mb-5">
                        <Toggle
                            label="تفعيل الإشعارات"
                            hint="الرسائل تُفتح من رقم المتجر على واتساب."
                            on={form.data.wa_enabled as boolean}
                            onChange={(v) => form.setData('wa_enabled', v)}
                        />
                    </div>

                    <Field
                        label="رقم المتجر على واتساب"
                        hint="بصيغة دولية بلا + ولا مسافات — مثل: 96890000000"
                        error={form.errors.wa_number}
                    >
                        <Input
                            dir="ltr"
                            value={form.data.wa_number as string}
                            onChange={(e) => form.setData('wa_number', e.target.value)}
                            placeholder="96890000000"
                        />
                    </Field>

                    {(form.data.wa_enabled as boolean) && !(form.data.wa_number as string) && (
                        <p className="mt-3 rounded-[12px] bg-[#fffbeb] px-4 py-3 text-[13px] text-[#b45309]">
                            {t('الإشعارات مفعّلة بلا رقم — لن تُرسَل رسالة واحدة.')}
                        </p>
                    )}
                </Card>

                {TEMPLATES.map((tpl) => (
                    <Card key={tpl.key} className="p-6">
                        <div className="mb-4">
                            <Toggle
                                label={tpl.label}
                                on={form.data[tpl.toggle] as boolean}
                                onChange={(v) => form.setData(tpl.toggle, v)}
                            />
                        </div>

                        <Field error={form.errors[tpl.key]}>
                            <textarea
                                rows={3}
                                value={form.data[tpl.key] as string}
                                onChange={(e) => form.setData(tpl.key, e.target.value)}
                                className="w-full rounded-[10px] border border-[var(--ui-border,#e8e8e8)] bg-white px-3 py-2 text-sm transition-[border-color,box-shadow] focus:border-[#d1d5db] focus:shadow-[0_0_0_3px_rgba(0,0,0,0.05)] focus:outline-none"
                            />
                        </Field>

                        {(form.data[tpl.key] as string) && (
                            <div className="mt-3 flex gap-2 rounded-[12px] bg-[#ecfdf5] px-4 py-3">
                                <MessageCircle className="mt-0.5 size-4 shrink-0 text-[#047857]" />
                                <p className="text-[13px] text-[#065f46]">
                                    {preview(form.data[tpl.key] as string)}
                                </p>
                            </div>
                        )}
                    </Card>
                ))}

                <Card className="p-6">
                    <h3 className="mb-3 font-bold text-[#111]">{t('المتغيّرات المتاحة')}</h3>
                    <div className="flex flex-wrap gap-2">
                        {Object.entries(variables).map(([key, label]) => (
                            <span
                                key={key}
                                className="rounded-[8px] bg-[#f2f2f0] px-2.5 py-1 text-[12px] text-[#4b4b4b]"
                            >
                                <span dir="ltr" className="font-mono">
                                    {key}
                                </span>{' '}
                                — {label}
                            </span>
                        ))}
                    </div>
                    <p className="mt-3 text-[12px] text-[#9ca3af]">
                        {t('ما لا يُعرف من المتغيّرات يبقى مكتوبًا كما هو في الرسالة.')}
                    </p>
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
