import { type FormEvent, useEffect, useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, Save, Send } from 'lucide-react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import PageHeader from '@/Components/PageHeader';
import Field, { Select, type SelectOption } from '@/Components/Field';
import Tabs from '@/Components/Tabs';
import Toggle from '@/Components/Toggle';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { PasswordInput } from '@/Components/ui/password-input';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

type Settings = Record<string, string>;

/** حال وصلة الرقم المشترك — بلا رمزٍ ولا سرّ، انظر WhatsAppConnections::publicView */
interface SharedConnection {
    status: string;
    usable: boolean;
    display_phone_number: string | null;
    connected_at: string | null;
    waba_id?: string | null;
    phone_number_id?: string | null;
    supports_inbox?: boolean;
}

/** حال البريد على الخادم كما تقرؤه PlatformConfig::mailStatus */
interface MailStatus {
    mailer: string;
    host: string;
    port: number | null;
    delivers: boolean;
}

/*
 * التبويبات المحذوفة: العملات، الإشعارات، الشروط، الخصوصية — ومعها حقول
 * تنسيق التاريخ والمنطقة الزمنية والرقم الضريبي والتجديد التلقائي.
 *
 * كانت كلّها تُحفظ في جدول الإعدادات ولا يقرؤها سطرٌ واحد في النظام: يبدّلها
 * المشغّل فلا يتغيّر شيء، ويظنّ أنه ضبط. ومقبضٌ لا يُمسك أسوأ من غيابه لأنه
 * يُطمئن. وما بقي هنا كلُّه موصولٌ بشيء — أو معروضٌ للقراءة لا للتحرير.
 */
/**
 * أيُّ تبويبٍ يملك أيَّ حقل — مصدرٌ واحد.
 *
 * ═══ ولماذا وُجدت أصلًا ═══
 *
 * النموذجُ واحدٌ والتبويباتُ ثمانية، فالحفظُ يُرسل الحقولَ كلَّها مهما كان
 * التبويبُ المفتوح. وردُّ الخادم يكتب خطأه على حقلٍ بعينه — وإن كان ذلك
 * الحقلُ في تبويبٍ مطويّ **لم يُرسَم في الصفحة أصلًا**، فلا شيء يُعرض.
 *
 * ووقع هذا فعلًا: `default_plan` كان يُرسل بقيمةٍ افتراضيّة لا تطابق أيّ
 * باقة، فيردّ الخادمُ كلَّ حفظٍ برسالةٍ على حقلٍ في «الاشتراكات». ومن كان
 * في «واتساب» يضغط «حفظ» فترتدّ الصفحة كأنّ شيئًا لم يكن — عشرَ مرّات.
 * بابٌ يُردّ ولا يُقال لماذا.
 *
 * فهنا: يُفتح التبويبُ الذي فيه الخطأ، ويُقال في أعلى الصفحة ما وقع.
 */
const FIELD_TAB: Record<string, string> = {
    app_name: 'general',
    locale: 'general',
    maintenance_mode: 'general',

    company: 'platform',
    official_email: 'platform',
    phone: 'platform',
    website: 'platform',

    trial_days: 'subscriptions',
    grace_days: 'subscriptions',
    default_plan: 'subscriptions',
    auto_suspend: 'subscriptions',

    vat_rate: 'taxes',
    tax_mode: 'taxes',

    from_address: 'mail',
    from_name: 'mail',

    whatsapp_enabled: 'whatsapp',
    whatsapp_shared_enabled: 'whatsapp',
    whatsapp_shared_default_monthly_limit: 'whatsapp',

    crm_ai_mode: 'ai',
};

const TABS = [
    { key: 'general', label: 'عامة' },
    { key: 'language', label: 'اللغة' },
    { key: 'platform', label: 'بيانات المنصة' },
    { key: 'subscriptions', label: 'الاشتراكات' },
    { key: 'taxes', label: 'الضريبة الافتراضية' },
    { key: 'mail', label: 'البريد' },
    { key: 'whatsapp', label: 'واتساب' },
    { key: 'google', label: 'خرائط Google' },
    { key: 'ai', label: 'المساعد الذكي' },
];

export default function PlatformSettings() {
    const { settings, locale, mail, plans, whatsapp, salesWhatsapp, aiProvider, supportReach, googleHealth, googleKeyHint, googleBilling } =
        usePage<PageProps<{
            settings: Settings;
            mail?: MailStatus;
            plans: SelectOption[];
            googleKeyHint?: string | null;
            whatsapp?: SharedConnection | null;
            /* ورقمُ المبيعات وصلةٌ ثانيةٌ بالشكل نفسه وغرضٍ آخر */
            salesWhatsapp?: SharedConnection | null;
            /* وحالُ مزوّد الذكاء — وجودُه لا قيمتُه */
            aiProvider?: { ready: boolean; name: string } | null;
            supportReach?: { reachable: number; total: number; ambiguous: number };
            googleHealth?: { configured: boolean; linkedBranches: number; branches: number };
            /* حالُ فوترة Google — انظر App\Support\GoogleBilling */
            googleBilling?: {
                state: 'unknown' | 'trial' | 'paid';
                ends_at: string | null;
                days_left: number | null;
                alert: { level: string; text: string } | null;
            };
        }>>().props;
    const t = useTranslate();
    const [tab, setTab] = useState('general');
    const [pickedLocale, setPickedLocale] = useState(locale === 'en' ? 'en' : 'ar');

    const get = (k: string) => settings[k] ?? '';
    const on = (k: string) => (settings[k] ?? '0') !== '0';

    /**
     * القيم مبدوءة بما هو محفوظ فعلًا في جدول الإعدادات.
     * القالب القديم كان يطبع الافتراضيات في value= ولا يقرأ المحفوظ إلا
     * لمربّعات الاختيار، فكل حقل نصّي يعود لقيمته الأولى بعد الحفظ.
     */
    const form = useForm({
        app_name: get('app_name'),
        locale: get('locale'),
        maintenance_mode: on('maintenance_mode'),

        company: get('company'),
        official_email: get('official_email'),
        phone: get('phone'),
        website: get('website'),

        trial_days: get('trial_days'),
        grace_days: get('grace_days'),
        default_plan: get('default_plan'),
        auto_suspend: on('auto_suspend'),

        vat_rate: get('vat_rate'),
        tax_mode: get('tax_mode'),

        from_address: get('from_address'),
        from_name: get('from_name'),

        whatsapp_enabled: on('whatsapp_enabled'),
        whatsapp_shared_enabled: on('whatsapp_shared_enabled'),
        whatsapp_shared_default_monthly_limit: get('whatsapp_shared_default_monthly_limit'),
        crm_ai_mode: get('crm_ai_mode'),
    });

    /*
     * نموذج ربط الرقم المشترك — منفصلٌ عن نموذج الإعدادات.
     *
     * الرمز يُرسل مرّةً ولا يُعاد إلى الشاشة أبدًا، فلا يجوز أن يسكن في نموذجٍ
     * يُعاد إرساله كلّما ضُغط «حفظ التغييرات».
     */
    const connectForm = useForm({
        phone_number_id: '',
        waba_id: '',
        display_phone_number: '',
        access_token: '',
    });

    /*
     * ورقمُ المبيعات نموذجٌ ثانٍ مستقلّ.
     *
     * ولا يُعاد استعمالُ الأوّل: نموذجٌ واحدٌ لوصلتين يعني حقلًا يحمل رمزَ
     * إحداهما ويُرسَل إلى الأخرى — ثمّ يُكتب رمزُ رقم المبيعات على رقم
     * الإشعارات، فتتوقّف رسائلُ كلّ المتاجر.
     */
    const salesForm = useForm({
        phone_number_id: '',
        waba_id: '',
        display_phone_number: '',
        access_token: '',
    });

    /* مفتاح الخرائط — سرٌّ كالرمز: يُرسل مرّةً ولا يعود إلى الشاشة */
    const googleForm = useForm({ google_places_key: '' });

    /*
     * وحالُ الفوترة — نموذجٌ مستقلٌّ عن المفتاح.
     *
     * جمعُهما يعني أنّ تصحيح تاريخٍ يمرّ بحقل مفتاحٍ فارغ، وأنّ حفظ مفتاحٍ
     * جديدٍ يكتب تاريخًا لم يُقصد. وهما شيئان يُبدَّلان في وقتين.
     */
    const billingForm = useForm({
        state: googleBilling?.state ?? 'unknown',
        ends_at: googleBilling?.ends_at ?? '',
    });

    type Key = keyof typeof form.data;

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('super-admin.settings.update'), { preserveScroll: true });
    };

    /*
     * وخطأٌ في تبويبٍ مطويّ يُفتح تبويبُه — لا يُترك صامتًا.
     *
     * `form.errors` تُملأ بعد ردّ الخادم. وحقلٌ لا يُرسَم لا يعرض خطأه، فلا
     * يبقى من الرفض إلّا ارتدادُ الصفحة. انظر `FIELD_TAB`.
     */
    const errorKeys = Object.keys(form.errors);
    const firstError = errorKeys[0];

    useEffect(() => {
        if (! firstError) {
            return;
        }

        const owner = FIELD_TAB[firstError];

        if (owner && owner !== tab) {
            setTab(owner);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [firstError]);

    const saveBar = (
        <div className="mt-6 flex justify-end">
            <Button type="submit" loading={form.processing}>
                <Save />
                {t('حفظ التغييرات')}
            </Button>
        </div>
    );

    const text = (name: Key, label: string, extra?: { type?: string; ltr?: boolean; hint?: string }) => (
        <Field label={label} hint={extra?.hint} error={form.errors[name]}>
            <Input
                type={extra?.type ?? 'text'}
                dir={extra?.ltr ? 'ltr' : undefined}
                value={String(form.data[name] ?? '')}
                onChange={(e) => form.setData(name, e.target.value as never)}
            />
        </Field>
    );

    const choice = (name: Key, label: string, options: { label: string; value: string }[]) => (
        <Field label={label} error={form.errors[name]}>
            <Select
                value={String(form.data[name] ?? '')}
                onChange={(e) => form.setData(name, e.target.value as never)}
                options={options}
            />
        </Field>
    );

    return (
        <PlatformLayout title="الإعدادات">
            <PageHeader
                title="الإعدادات"
                subtitle={t('إدارة إعدادات المنصة العامة والاشتراكات والضرائب والإشعارات')}
            />

            <Tabs tabs={TABS} current={tab} onChange={setTab} className="mb-6" />

            {/*
                وما رُدّ يُقال — نصًّا لا ارتدادًا.

                والرسالةُ من الخادم لا مصنوعةً هنا: هو الذي يعرف لماذا رَدّ.
            */}
            {errorKeys.length > 0 && (
                <div className="mb-4 rounded-[12px] border border-[#fecaca] bg-[#fef2f2] p-4">
                    <p className="text-[13px] font-semibold text-[#991b1b]">
                        {t('لم تُحفظ الإعدادات')}
                    </p>
                    <ul className="mt-1.5 space-y-1">
                        {errorKeys.map((key) => (
                            <li key={key} className="text-[12px] leading-relaxed text-[#b91c1c]">
                                {form.errors[key as Key]}
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            <form onSubmit={submit}>
                {tab === 'general' && (
                    <Card className="p-6">
                        <h3 className="mb-6 text-[18px] font-bold text-[#111]">{t('الإعدادات العامة')}</h3>
                        <div className="space-y-5">
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                {text('app_name', 'اسم المنصة')}
                                {choice('locale', 'اللغة الافتراضية', [
                                    { label: 'العربية', value: 'ar' },
                                    { label: 'English', value: 'en' },
                                ])}
                            </div>
                            <Toggle
                                on={form.data.maintenance_mode}
                                onChange={(v) => form.setData('maintenance_mode', v)}
                                label="وضع الصيانة"
                                hint="يوقف التجّار عن الدخول ولا يوقفك أنت — تبقى جلساتهم مفتوحة ويعودون بمجرّد إطفائه"
                            />
                        </div>
                        {saveBar}
                    </Card>
                )}

                {tab === 'platform' && (
                    <Card className="p-6">
                        <h3 className="mb-6 text-[18px] font-bold text-[#111]">{t('بيانات المنصة')}</h3>
                        <div className="space-y-5">
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                {text('company', 'اسم الشركة المالكة')}
                                {text('official_email', 'البريد الرسمي', { type: 'email', ltr: true })}
                            </div>
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                {text('phone', 'رقم الهاتف', { ltr: true })}
                                {text('website', 'الموقع الإلكتروني', { ltr: true })}
                            </div>
                            <p className="text-[12px] text-[#9ca3af]">
                                {t('هذه البيانات يراها التاجر في صفحة انتهاء الاشتراك ليتواصل معك — لا تُعرض في غيرها.')}
                            </p>
                        </div>
                        {saveBar}
                    </Card>
                )}

                {tab === 'subscriptions' && (
                    <Card className="p-6">
                        <h3 className="mb-6 text-[18px] font-bold text-[#111]">{t('إعدادات الاشتراكات')}</h3>
                        <div className="space-y-5">
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                {text('trial_days', 'مدة الفترة التجريبية (أيام)', {
                                    type: 'number',
                                    ltr: true,
                                    hint: 'تُطبَّق على شركةٍ تُضاف بلا تاريخ انتهاء',
                                })}
                                {text('grace_days', 'مهلة السماح بعد الانتهاء (أيام)', {
                                    type: 'number',
                                    ltr: true,
                                    hint: 'يعمل المتجر كاملًا فيها ويرى شريطًا يعدّ ما بقي',
                                })}
                            </div>
                            {/* تُختار من الباقات القائمة لا تُكتب: اسمٌ لا يطابق شيئًا يعني متجرًا بلا باقة */}
                            <Field label={t('الباقة الافتراضية عند التسجيل')} error={form.errors.default_plan}>
                                <Select
                                    value={String(form.data.default_plan ?? '')}
                                    onChange={(e) => form.setData('default_plan', e.target.value as never)}
                                    options={plans}
                                    placeholder="— بلا باقة —"
                                />
                            </Field>
                            <Toggle
                                on={form.data.auto_suspend}
                                onChange={(v) => form.setData('auto_suspend', v)}
                                label="إقفال المتجر تلقائيًا بعد انتهاء المهلة"
                                hint="إطفاؤه يعني أن المتاجر المنتهية تبقى تعمل حتى تُقفلها بنفسك"
                            />
                        </div>
                        {saveBar}
                    </Card>
                )}

                {tab === 'taxes' && (
                    <Card className="p-6">
                        <h3 className="mb-1 text-[18px] font-bold text-[#111]">{t('الضريبة الافتراضية للمتاجر')}</h3>
                        <p className="mb-6 text-[13px] text-[#6b7280]">
                            {t('تُطبَّق على متجرٍ لم يضبط ضريبته في إعداداته. ومن ضبطها فإعداده أولى.')}
                        </p>
                        <div className="space-y-5">
                            {text('vat_rate', 'نسبة ضريبة القيمة المضافة (%)', {
                                type: 'number',
                                ltr: true,
                                hint: 'النسبة المطبقة في سلطنة عُمان',
                            })}
                            {choice('tax_mode', 'طريقة احتساب الضريبة', [
                                { label: 'شاملة السعر', value: 'inclusive' },
                                { label: 'مضافة على السعر', value: 'exclusive' },
                            ])}
                        </div>
                        {saveBar}
                    </Card>
                )}

                {tab === 'mail' && (
                    <Card className="p-6">
                        <h3 className="mb-1 text-[18px] font-bold text-[#111]">{t('البريد الإلكتروني')}</h3>
                        <p className="mb-6 text-[13px] text-[#6b7280]">
                            {t('خادم الإرسال واعتماداته تُضبط في ملفّ الخادم (.env) لا هنا — كي لا تُخزَّن كلمة سرّه في قاعدة البيانات وتخرج مع كل نسخة احتياطية.')}
                        </p>

                        {/*
                            حالة الإرسال الفعليّة أوّل ما يُقرأ في الشاشة.
                            كانت الحقول تُملأ وتُحفظ ولا تصل إلى النظام، والزرّ
                            يقول «تم الإرسال» لأن المرسِل log — يكتب في ملفّ ولا
                            يُخرج شيئًا. فصار ما يُعرض هو ما يعمل.
                        */}
                        <div
                            className={
                                'mb-5 flex items-start gap-2 rounded-[10px] p-3 text-[13px] ' +
                                (mail?.delivers
                                    ? 'bg-[#f0fdf4] text-[#166534]'
                                    : 'bg-[#fef2f2] text-[#b91c1c]')
                            }
                        >
                            {mail?.delivers ? (
                                <CheckCircle2 className="mt-0.5 size-4 shrink-0" />
                            ) : (
                                <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                            )}
                            <span>
                                {mail?.delivers
                                    ? t('البريد يعمل — الرسائل تخرج من الخادم.')
                                    : t('لا رسائل تخرج من الخادم: تنبيهات الاشتراك وروابط استعادة كلمة المرور لن تصل أحدًا.')}
                            </span>
                        </div>

                        <dl className="mb-6 space-y-2 rounded-[12px] bg-[#fafafa] p-4 text-[13px]">
                            {[
                                { label: 'مزوّد الإرسال', value: mail?.mailer },
                                { label: 'الخادم', value: mail?.host || '—' },
                                { label: 'المنفذ', value: mail?.port ? String(mail.port) : '—' },
                            ].map((row) => (
                                <div key={row.label} className="flex justify-between gap-3">
                                    <dt className="text-[#6b7280]">{t(row.label)}</dt>
                                    <dd className="font-medium text-[#111]" dir="ltr">
                                        {row.value}
                                    </dd>
                                </div>
                            ))}
                        </dl>

                        {/* اسم المُرسِل وعنوانه لا سرّ فيهما، وهما ما يراه المستقبِل — فيُحرَّران ويُطبَّقان */}
                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            {text('from_address', 'بريد المُرسِل', { type: 'email', ltr: true })}
                            {text('from_name', 'اسم المُرسِل')}
                        </div>

                        <div className="mt-6 flex justify-end gap-2">
                            {/* اختبار الإرسال فعل مستقل لا حفظ: يُرسل وحده ولا يكتب الإعدادات */}
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() =>
                                    router.post(route('super-admin.settings.testEmail'), {}, { preserveScroll: true })
                                }
                            >
                                <Send />
                                {t('اختبار الإرسال')}
                            </Button>
                            <Button type="submit" loading={form.processing}>
                                <Save />
                                {t('حفظ التغييرات')}
                            </Button>
                        </div>
                    </Card>
                )}

                {tab === 'whatsapp' && (
                    <Card className="p-6">
                        <h3 className="mb-1 text-[18px] font-bold text-[#111]">{t('واتساب')}</h3>
                        <p className="mb-6 text-[13px] text-[#6b7280]">
                            {t('الرقم المشترك رقم أبعاد، ولكل متجرٍ حصّته الشهرية منه. ومن رُبط رقمه الخاص يُرسل على حسابه ولا يُخصم من الحصّة.')}
                        </p>

                        {/* حال الوصلة أوّل ما يُقرأ — مقابض بلا رقمٍ مربوط لا تُرسل شيئًا */}
                        <div
                            className={
                                'mb-5 flex items-start gap-2 rounded-[10px] p-3 text-[13px] ' +
                                (whatsapp?.usable
                                    ? 'bg-[#f0fdf4] text-[#166534]'
                                    : 'bg-[#fef2f2] text-[#b91c1c]')
                            }
                        >
                            {whatsapp?.usable ? (
                                <CheckCircle2 className="mt-0.5 size-4 shrink-0" />
                            ) : (
                                <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                            )}
                            <span>
                                {whatsapp?.usable
                                    ? `${t('الرقم المشترك مربوط')} — ${whatsapp.display_phone_number ?? whatsapp.phone_number_id ?? ''}`
                                    : t('لا رقم مشترك مربوط — لن تخرج رسالة واحدة مهما فُعّلت المقابض.')}
                            </span>
                        </div>

                        <div className="space-y-5">
                            <Toggle
                                on={form.data.whatsapp_enabled}
                                onChange={(v) => form.setData('whatsapp_enabled', v)}
                                label="تفعيل واتساب في المنصة"
                                hint="إطفاؤه يوقف كل الرسائل في كل المتاجر — بالرقم المشترك وبأرقام المتاجر معًا"
                            />
                            <Toggle
                                on={form.data.whatsapp_shared_enabled}
                                onChange={(v) => form.setData('whatsapp_shared_enabled', v)}
                                label="السماح بالرقم المشترك"
                                hint="إطفاؤه يوقف من يُرسل عبر رقم أبعاد ولا يوقف من ربط رقمه الخاص"
                            />
                            {text('whatsapp_shared_default_monthly_limit', 'الحد الشهري الافتراضي للرسائل', {
                                type: 'number',
                                ltr: true,
                                hint: 'يُطبَّق على كل متجرٍ لم يُحدَّد له حدٌّ خاص. و‎-1 تعني بلا حد.',
                            })}
                        </div>

                        {/*
                            استقبالُ الوارد — مقبضٌ وحده، يُحفظ بضغطته لا مع الإعدادات.
                            وهو قرارٌ لا إعداد: الرقمُ يُرسل نيابةً عن المحلّات فيردّ
                            عليه زبائنُهم، وفتحُ البابِ يعني قراءةَ ما يصل.
                        */}
                        <div className="mt-6 rounded-[12px] border border-[var(--ui-border,#e8e8e8)] p-4">
                            <Toggle
                                on={whatsapp?.supports_inbox ?? false}
                                onChange={(v) =>
                                    router.post(
                                        route('super-admin.whatsapp.support-inbox'),
                                        { supports_inbox: v },
                                        { preserveScroll: true },
                                    )
                                }
                                label="استقبال رسائل الدعم على هذا الرقم"
                                hint="يفتح مركز المحادثات على واتساب: تُخزَّن الرسالة فقط إن جاءت من رقمٍ يطابق مستخدمًا واحدًا له متجر. وردود الزبائن على إشعارات طلباتهم تُسقَط ولا تُكتب."
                            />

                            <p className="mt-3 text-[12px] leading-relaxed text-[#6b7280]">
                                {t('وردُّ الدعم لا يخرج إلى واتساب إلا خلال ٢٤ ساعة من آخر رسالة كتبها التاجر — قاعدةُ ميتا لا قاعدتُنا. وما لا يخرج يُكتب في المحادثة أنه لم يخرج.')}
                            </p>

                            {/* ومن لا رقمَ له لا تصل رسالتُه — يُقال بعددٍ لا بوعد */}
                            {supportReach && (
                                <p className="mt-2 text-[12px] leading-relaxed text-[#6b7280]">
                                    {t(':n من :total مستخدمًا لهم رقمٌ يُطابَق. ومن لا رقمَ مسجّلًا له لا تصل رسالتُه.', {
                                        n: supportReach.reachable,
                                        total: supportReach.total,
                                    })}
                                    {supportReach.ambiguous > 0 && (
                                        <span className="text-[#b45309]">
                                            {' '}
                                            {t('و:n رقمًا مكرّرًا بين مستخدمين — رسائلُها تُسقَط حتى يُصحَّح التكرار.', {
                                                n: supportReach.ambiguous,
                                            })}
                                        </span>
                                    )}
                                </p>
                            )}
                        </div>

                        {saveBar}

                        {/*
                            ربط الرقم — نموذجٌ منفصل عن الإعدادات.
                            الرمز يُرسل مرّةً ولا يُعاد إلى الشاشة أبدًا.
                        */}
                        <div className="mt-8 border-t border-[var(--ui-border,#e8e8e8)] pt-6">
                            <h4 className="mb-4 font-bold text-[#111]">{t('ربط رقم أبعاد المشترك')}</h4>

                            <div className="space-y-4">
                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <Field label={t('معرّف الرقم (Phone Number ID)')} error={connectForm.errors.phone_number_id}>
                                        <Input
                                            dir="ltr"
                                            value={connectForm.data.phone_number_id}
                                            onChange={(e) => connectForm.setData('phone_number_id', e.target.value)}
                                        />
                                    </Field>
                                    <Field label={t('معرّف حساب الأعمال (WABA ID)')} error={connectForm.errors.waba_id}>
                                        <Input
                                            dir="ltr"
                                            value={connectForm.data.waba_id}
                                            onChange={(e) => connectForm.setData('waba_id', e.target.value)}
                                        />
                                    </Field>
                                </div>

                                <Field label={t('الرقم كما يظهر للزبون')} error={connectForm.errors.display_phone_number}>
                                    <Input
                                        dir="ltr"
                                        value={connectForm.data.display_phone_number}
                                        onChange={(e) => connectForm.setData('display_phone_number', e.target.value)}
                                    />
                                </Field>

                                <Field
                                    label={t('رمز الوصول الدائم')}
                                    hint={t('يُخزَّن مشفَّرًا ولا يُعرض بعد الحفظ — احتفظ بنسخةٍ منه عندك.')}
                                    error={connectForm.errors.access_token}
                                >
                                    <PasswordInput
                                        dir="ltr"
                                        value={connectForm.data.access_token}
                                        onChange={(e) => connectForm.setData('access_token', e.target.value)}
                                    />
                                </Field>
                            </div>

                            <div className="mt-5 flex justify-end gap-2">
                                {whatsapp && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() =>
                                            router.delete(route('super-admin.whatsapp.shared.disconnect'), {
                                                preserveScroll: true,
                                            })
                                        }
                                    >
                                        {t('فصل الرقم')}
                                    </Button>
                                )}
                                <Button
                                    type="button"
                                    loading={connectForm.processing}
                                    onClick={() =>
                                        connectForm.post(route('super-admin.whatsapp.shared.connect'), {
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

                        {/*
                            ═══ رقمُ مبيعات أبعاد — رقمٌ ثانٍ لا مقبضٌ على الأوّل ═══

                            رقمُ الإشعارات يُرسل نيابةً عن المحلّات فيردّ عليه
                            **زبائنُهم**، ولا يُخزَّن من وارده إلّا ما طابق
                            مستخدمًا له متجر. ورقمُ المبيعات يحتاج عكسَ ذلك:
                            أن يُقرأ الواردُ من رقمٍ **مجهول** — فالعميلُ
                            المحتمَل ليس مستخدمًا عندنا بعد.

                            والشرطان لا يجتمعان على رقم، فلا مقبضَ يجمعهما.
                        */}
                        <div className="mt-8 border-t border-[var(--ui-border,#e8e8e8)] pt-6">
                            <h4 className="mb-1 font-bold text-[#111]">{t('رقم مبيعات أبعاد (CRM)')}</h4>
                            <p className="mb-4 text-[13px] leading-relaxed text-[#6b7280]">
                                {t('رقمٌ مستقلٌّ يستقبل من يريد أن يشتري أبعاد، ووارده يُقرأ في «CRM ‹ المحادثات». ولا يكون رقمَ الإشعارات نفسَه: ذاك يردّ عليه زبائنُ المحلّات، وقراءةُ المجهول عليه تُدخل رسائلهم في دفتر مبيعاتنا.')}
                            </p>

                            {salesWhatsapp && (
                                <p className="mb-4 text-[13px] text-[#111]">
                                    {t('الموصول الآن')}:{' '}
                                    <span dir="ltr">{salesWhatsapp.display_phone_number ?? salesWhatsapp.phone_number_id}</span>
                                    {' · '}
                                    <span className={salesWhatsapp.usable ? 'text-[#15803d]' : 'text-[#b91c1c]'}>
                                        {salesWhatsapp.usable ? t('يعمل') : t('لا يعمل')}
                                    </span>
                                </p>
                            )}

                            <div className="space-y-4">
                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <Field
                                        label={t('معرّف الرقم (Phone Number ID)')}
                                        error={salesForm.errors.phone_number_id}
                                    >
                                        <Input
                                            dir="ltr"
                                            value={salesForm.data.phone_number_id}
                                            onChange={(e) => salesForm.setData('phone_number_id', e.target.value)}
                                        />
                                    </Field>
                                    <Field
                                        label={t('معرّف حساب الأعمال (WABA ID)')}
                                        error={salesForm.errors.waba_id}
                                    >
                                        <Input
                                            dir="ltr"
                                            value={salesForm.data.waba_id}
                                            onChange={(e) => salesForm.setData('waba_id', e.target.value)}
                                        />
                                    </Field>
                                </div>

                                <Field
                                    label={t('الرقم كما يظهر للعميل')}
                                    error={salesForm.errors.display_phone_number}
                                >
                                    <Input
                                        dir="ltr"
                                        value={salesForm.data.display_phone_number}
                                        onChange={(e) => salesForm.setData('display_phone_number', e.target.value)}
                                    />
                                </Field>

                                <Field
                                    label={t('رمز الوصول الدائم')}
                                    hint={t('يُخزَّن مشفَّرًا ولا يُعرض بعد الحفظ — احتفظ بنسخةٍ منه عندك.')}
                                    error={salesForm.errors.access_token}
                                >
                                    <PasswordInput
                                        dir="ltr"
                                        value={salesForm.data.access_token}
                                        onChange={(e) => salesForm.setData('access_token', e.target.value)}
                                    />
                                </Field>
                            </div>

                            <div className="mt-5 flex justify-end gap-2">
                                {salesWhatsapp && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() =>
                                            router.delete(route('super-admin.whatsapp.sales.disconnect'), {
                                                preserveScroll: true,
                                            })
                                        }
                                    >
                                        {t('فصل رقم المبيعات')}
                                    </Button>
                                )}
                                <Button
                                    type="button"
                                    loading={salesForm.processing}
                                    onClick={() =>
                                        salesForm.post(route('super-admin.whatsapp.sales.connect'), {
                                            preserveScroll: true,
                                            onSuccess: () => salesForm.reset('access_token'),
                                        })
                                    }
                                >
                                    <Save />
                                    {t('ربط رقم المبيعات')}
                                </Button>
                            </div>
                        </div>
                    </Card>
                )}

                {tab === 'ai' && (
                    <Card className="p-6">
                        <h3 className="mb-1 text-[18px] font-bold text-[#111]">{t('مساعد أبعاد الذكي')}</h3>
                        <p className="mb-6 text-[13px] leading-relaxed text-[#6b7280]">
                            {t('يقرأ محادثة العميل المحتمل ويقترح ردًّا يكتبه موظّف المبيعات ويرسله بنفسه. لا يُرسل شيئًا من نفسه.')}
                        </p>

                        {/* حالُ المزوّد أوّلُ ما يُقرأ: مقبضٌ فوق مزوّدٍ غائبٍ لا يُدير شيئًا */}
                        <div
                            className={cn(
                                'mb-6 rounded-[12px] border p-4 text-[13px]',
                                aiProvider?.ready
                                    ? 'border-[#bbf7d0] bg-[#f0fdf4] text-[#166534]'
                                    : 'border-[#fde68a] bg-[#fffbeb] text-[#92400e]',
                            )}
                        >
                            {aiProvider?.ready
                                ? t('المزوّد مضبوط: :name', { name: aiProvider.name })
                                : t('لا مزوّد مضبوط على الخادم — يُضاف ANTHROPIC_API_KEY و AI_PROVIDER=anthropic في ملفّ البيئة، ثم يُعاد تحميل php-fpm. وحتى ذلك لا يُعرض زرّ الاقتراح أصلًا.')}
                        </div>

                        <Field
                            label={t('وضع الذكاء الاصطناعي')}
                            hint={t('الافتراضي «اقتراح الرد فقط» — والرد التلقائي غير مبنيّ في هذه النسخة، فلا يُعرض خيارًا.')}
                        >
                            {/*
                                ولا يُعرض ما لم يُبنَ.
                                خيارٌ اسمُه «ردٌّ تلقائيّ للمبيعات» يُختار ويُحفظ
                                ولا يفعل شيئًا أسوأُ من غيابه: يظنّ المشغّلُ أنّ
                                العملاء يُردّ عليهم، ولا يردّ أحد.
                            */}
                            <Select
                                value={form.data.crm_ai_mode || 'suggestions_only'}
                                onChange={(e) => form.setData('crm_ai_mode', e.target.value)}
                                options={[
                                    { value: 'suggestions_only', label: t('اقتراح الرد فقط') },
                                    { value: 'disabled', label: t('متوقف') },
                                ]}
                                aria-label={t('وضع الذكاء الاصطناعي')}
                            />
                        </Field>

                        <div className="mt-6 rounded-[12px] border border-[var(--ui-border,#e8e8e8)] p-4 text-[12px] leading-relaxed text-[#6b7280]">
                            <p className="mb-2 font-medium text-[#111]">{t('ما لا يفعله المساعد')}</p>
                            <ul className="list-inside list-disc space-y-1">
                                <li>{t('لا يرسل رسالةً إلى أحد — يكتب اقتراحًا في الشاشة فقط.')}</li>
                                <li>{t('لا يذكر سعرًا إلا كما هو في شاشة الباقات الآن.')}</li>
                                <li>{t('لا يَعِد بميزة غير موجودة ولا بميزة قادمة.')}</li>
                                <li>{t('لا تصله ملاحظاتكم الداخلية ولا بيانات عميل آخر.')}</li>
                                <li>{t('يُحوّل إلى إنسان إذا طلب العميل ذلك أو شكا أو طلب استردادًا.')}</li>
                            </ul>
                        </div>

                        {saveBar}
                    </Card>
                )}

                {tab === 'google' && (
                    <Card className="p-6">
                        <h3 className="mb-1 text-[18px] font-bold text-[#111]">{t('خرائط Google')}</h3>
                        <p className="mb-6 text-[13px] text-[#6b7280]">
                            {t('مفتاحٌ واحدٌ لكلّ المتاجر: به تُقرأ تقييمات محلّاتهم ومعدّلاتها. وبدونه على كلّ تاجرٍ أن يفتح حسابًا في Google Cloud بنفسه — فلا يفعل.')}
                        </p>

                        {/* حال المفتاح أوّل ما يُقرأ: بلا مفتاحٍ لا تُقرأ تقييمةٌ واحدة في المنصّة كلّها */}
                        <div
                            className={
                                'mb-5 flex items-start gap-2 rounded-[10px] p-3 text-[13px] ' +
                                (googleKeyHint ? 'bg-[#f0fdf4] text-[#166534]' : 'bg-[#fef2f2] text-[#b91c1c]')
                            }
                        >
                            {googleKeyHint ? (
                                <CheckCircle2 className="mt-0.5 size-4 shrink-0" />
                            ) : (
                                <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                            )}
                            <span dir={googleKeyHint ? 'ltr' : undefined}>
                                {googleKeyHint
                                    ? `${t('المفتاح محفوظ')} — ${googleKeyHint}`
                                    : t('لا مفتاح — «ربط خرائط Google» عند التجّار يقف عند خطوته الأولى.')}
                            </span>
                        </div>

                        {/*
                            وتنبيهُ الفوترة فوق كلّ شيء — لأنّه وحده يُطفئ الميزة
                            عن كلّ التجّار في يومٍ واحد، والباقي يُنقصها لواحد.
                        */}
                        {googleBilling?.alert && (
                            <div
                                className={
                                    'mb-5 flex items-start gap-2 rounded-[10px] p-3 text-[13px] ' +
                                    (googleBilling.alert.level === 'danger'
                                        ? 'bg-[#fef2f2] text-[#b91c1c]'
                                        : 'bg-[#fffbeb] text-[#b45309]')
                                }
                            >
                                <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                                <span>{googleBilling.alert.text}</span>
                            </div>
                        )}

                        {/*
                            وكم فرعًا رُبط فعلًا — عددٌ محسوب.
                            مفتاحٌ محفوظٌ وصفرُ فروع يعني أنّ الميزة لم تصل التجّار،
                            وهو خبرٌ يُقرأ هنا لا يُكتشف بعد شهر.
                        */}
                        {googleHealth && (
                            <p className="mb-5 text-[12px] leading-relaxed text-[#6b7280]">
                                {t(':n من :total فرعًا مربوطٌ بملفّه على الخرائط.', {
                                    n: googleHealth.linkedBranches,
                                    total: googleHealth.branches,
                                })}
                            </p>
                        )}

                        <Field
                            label={t('مفتاح Places API (New)')}
                            hint={t('من مشروع أبعاد في Google Cloud — يُخزَّن معمًّى ولا يُعرض بعد الحفظ.')}
                            error={googleForm.errors.google_places_key}
                        >
                            <PasswordInput
                                dir="ltr"
                                value={googleForm.data.google_places_key}
                                onChange={(e) => googleForm.setData('google_places_key', e.target.value)}
                            />
                        </Field>

                        <div className="mt-5 flex justify-end gap-2">
                            {googleKeyHint && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() =>
                                        router.delete(route('super-admin.settings.googleKey.forget'), {
                                            preserveScroll: true,
                                        })
                                    }
                                >
                                    {t('حذف المفتاح')}
                                </Button>
                            )}
                            <Button
                                type="button"
                                loading={googleForm.processing}
                                onClick={() =>
                                    googleForm.post(route('super-admin.settings.googleKey'), {
                                        preserveScroll: true,
                                        onSuccess: () => googleForm.reset('google_places_key'),
                                    })
                                }
                            >
                                <Save />
                                {t('حفظ المفتاح')}
                            </Button>
                        </div>

                        {/*
                            ═══ حالُ الفوترة ═══

                            وتُكتب بيد: Google لا تخبر واجهةَ Places متى تنتهي
                            تجربةُ مشروعها، فلا سبيل لنا إلى قراءتها. وبلا كتابتها
                            يُكتشف الانتهاءُ يومَ يشكو أوّلُ تاجرٍ من اختفاء تقييماته.
                        */}
                        <div className="mt-8 border-t border-[var(--ui-border,#e8e8e8)] pt-6">
                            <h4 className="text-[15px] font-bold text-[#111]">{t('حال فوترة Google')}</h4>
                            <p className="mt-1 mb-5 text-[13px] leading-relaxed text-[#6b7280]">
                                {t('تجربة Google تسعون يومًا ثمّ يتوقّف المشروع ما لم ترفع الحساب بيدك — ويومها تتوقّف الخرائط عن كلّ تجّارك دفعةً واحدة. اكتب الموعد ليُنبّهك النظام قبله بأسبوعين.')}
                            </p>

                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <Field label={t('الحال')} error={billingForm.errors.state}>
                                    <Select
                                        value={billingForm.data.state}
                                        onChange={(e) => billingForm.setData('state', e.target.value as 'unknown' | 'trial' | 'paid')}
                                        options={[
                                            { value: 'unknown', label: t('لم يُحدَّد') },
                                            { value: 'trial', label: t('تجربة مجانية') },
                                            { value: 'paid', label: t('حساب مدفوع') },
                                        ]}
                                    />
                                </Field>

                                {/* والموعدُ يُعرض مع التجربة وحدها: تاريخٌ تحت «مدفوع» لا يعني شيئًا */}
                                {billingForm.data.state === 'trial' && (
                                    <Field label={t('تنتهي التجربة في')} error={billingForm.errors.ends_at}>
                                        <Input
                                            type="date"
                                            dir="ltr"
                                            value={billingForm.data.ends_at}
                                            onChange={(e) => billingForm.setData('ends_at', e.target.value)}
                                        />
                                    </Field>
                                )}
                            </div>

                            <div className="mt-5 flex justify-end">
                                <Button
                                    type="button"
                                    loading={billingForm.processing}
                                    onClick={() =>
                                        billingForm.post(route('super-admin.settings.googleBilling'), {
                                            preserveScroll: true,
                                        })
                                    }
                                >
                                    <Save />
                                    {t('حفظ حال الفوترة')}
                                </Button>
                            </div>
                        </div>
                    </Card>
                )}
            </form>

            {/*
                خارج النموذج عمدًا: لغة الواجهة تفضيلٌ شخصيّ لحساب مدير المنصة،
                لا إعدادًا من إعدادات المنصة — ولها مسارها الذي يكتب في الحساب.
                وتبويب «عامة» فيه «اللغة الافتراضية» وهي شيء آخر: لغة المنصة
                لمن لم يختر بعد، لا لغة من يقرأ هذه الشاشة الآن.
            */}
            {tab === 'language' && (
                <Card className="p-6">
                    <h3 className="mb-1 text-[18px] font-bold text-[#111]">{t('لغة واجهتك')}</h3>
                    <p className="mb-6 text-[13px] text-[#9ca3af]">
                        {t('تخصّك وحدك ولا تغيّر لغة أحدٍ آخر ولا لغة المنصة الافتراضية.')}
                    </p>

                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        {[
                            { code: 'ar', label: 'العربية', hint: 'من اليمين إلى اليسار (RTL)' },
                            { code: 'en', label: 'English', hint: 'من اليسار إلى اليمين (LTR)' },
                        ].map((l) => (
                            <label
                                key={l.code}
                                className={
                                    'flex cursor-pointer items-center justify-between rounded-[12px] border px-4 py-3.5 transition ' +
                                    (pickedLocale === l.code
                                        ? 'border-[#111] bg-[#fafafa]'
                                        : 'border-[var(--ui-border,#e8e8e8)] hover:bg-[#fafafa]')
                                }
                            >
                                <span>
                                    <span className="block text-sm font-medium text-[#111]">{l.label}</span>
                                    <span className="block text-[12px] text-[#9ca3af]">{t(l.hint)}</span>
                                </span>
                                <input
                                    type="radio"
                                    name="ui-locale"
                                    checked={pickedLocale === l.code}
                                    onChange={() => setPickedLocale(l.code)}
                                    className="size-5"
                                />
                            </label>
                        ))}
                    </div>

                    <div className="mt-6 flex justify-end">
                        <Button
                            onClick={() =>
                                /*
                                 * اتجاه المستند (dir) يُحسم في قالب الجذر، فتحديث
                                 * Inertia الجزئي يترك الصفحة بالاتجاه القديم —
                                 * ولذلك إعادة تحميلٍ كاملة بعد الحفظ.
                                 */
                                router.post(
                                    route('super-admin.language.update'),
                                    { locale: pickedLocale },
                                    { onSuccess: () => window.location.reload() },
                                )
                            }
                            disabled={pickedLocale === (locale === 'en' ? 'en' : 'ar')}
                        >
                            <Save />
                            {t('حفظ اللغة')}
                        </Button>
                    </div>
                </Card>
            )}
        </PlatformLayout>
    );
}
