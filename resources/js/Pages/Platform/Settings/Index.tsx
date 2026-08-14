import { type FormEvent, useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, Save, Send } from 'lucide-react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import PageHeader from '@/Components/PageHeader';
import Field, { Select } from '@/Components/Field';
import Tabs from '@/Components/Tabs';
import Toggle from '@/Components/Toggle';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

type Settings = Record<string, string>;

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
const TABS = [
    { key: 'general', label: 'عامة' },
    { key: 'language', label: 'اللغة' },
    { key: 'platform', label: 'بيانات المنصة' },
    { key: 'subscriptions', label: 'الاشتراكات' },
    { key: 'taxes', label: 'الضريبة الافتراضية' },
    { key: 'mail', label: 'البريد' },
];

export default function PlatformSettings() {
    const { settings, locale, mail } = usePage<PageProps<{ settings: Settings; mail?: MailStatus }>>().props;
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
    });

    type Key = keyof typeof form.data;

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(route('super-admin.settings.update'), { preserveScroll: true });
    };

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
                breadcrumbs={[
                    { label: 'الرئيسية', href: route('super-admin.dashboard') },
                    { label: 'الإعدادات' },
                ]}
            />

            <Tabs tabs={TABS} current={tab} onChange={setTab} className="mb-6" />

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
                            {text('default_plan', 'الباقة الافتراضية عند التسجيل', {
                                hint: 'اسم باقةٍ قائمة بالحرف — تُسنَد لمن يُضاف بلا باقة',
                            })}
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
