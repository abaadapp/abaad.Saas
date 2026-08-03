import { type FormEvent, useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { Save, Send } from 'lucide-react';
import PlatformLayout from '@/Layouts/PlatformLayout';
import PageHeader from '@/Components/PageHeader';
import Field, { Select } from '@/Components/Field';
import Tabs from '@/Components/Tabs';
import Toggle from '@/Components/Toggle';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input, Textarea } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

type Settings = Record<string, string>;

const TABS = [
    { key: 'general', label: 'عامة' },
    { key: 'platform', label: 'بيانات المنصة' },
    { key: 'subscriptions', label: 'الاشتراكات' },
    { key: 'taxes', label: 'الضرائب' },
    { key: 'currencies', label: 'العملات' },
    { key: 'notifications', label: 'الإشعارات' },
    { key: 'mail', label: 'البريد' },
    { key: 'terms', label: 'الشروط والأحكام' },
    { key: 'privacy', label: 'سياسة الخصوصية' },
];

/** إشعارات المنصة — التسميات بترتيب مفاتيحها platform_notif_0..5 */
const NOTIFICATIONS = [
    'إشعار عند تسجيل شركة جديدة',
    'إشعار عند دفع فاتورة',
    'إشعار قرب انتهاء الاشتراك',
    'إشعار عند انتهاء الاشتراك',
    'إشعارات عبر البريد الإلكتروني',
    'إشعارات عبر الرسائل النصية',
];

export default function PlatformSettings() {
    const { settings } = usePage<PageProps<{ settings: Settings }>>().props;
    const t = useTranslate();
    const [tab, setTab] = useState('general');

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
        timezone: get('timezone'),
        date_format: get('date_format'),
        maintenance_mode: on('maintenance_mode'),

        company: get('company'),
        official_email: get('official_email'),
        phone: get('phone'),
        website: get('website'),
        address: get('address'),
        cr: get('cr'),

        trial_days: get('trial_days'),
        grace_days: get('grace_days'),
        default_plan: get('default_plan'),
        auto_renew: on('auto_renew'),
        auto_suspend: on('auto_suspend'),

        vat_rate: get('vat_rate'),
        tax_number: get('tax_number'),
        tax_mode: get('tax_mode'),
        platform_vat_enabled: on('platform_vat_enabled'),

        base_currency: get('base_currency'),
        currency_symbol: get('currency_symbol'),
        decimals: get('decimals'),
        symbol_position: get('symbol_position'),

        platform_notif_0: on('platform_notif_0'),
        platform_notif_1: on('platform_notif_1'),
        platform_notif_2: on('platform_notif_2'),
        platform_notif_3: on('platform_notif_3'),
        platform_notif_4: on('platform_notif_4'),
        platform_notif_5: on('platform_notif_5'),

        mailer: get('mailer'),
        mail_host: get('mail_host'),
        mail_port: get('mail_port'),
        mail_encryption: get('mail_encryption'),
        from_address: get('from_address'),
        from_name: get('from_name'),

        terms: get('terms'),
        privacy: get('privacy'),
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

            <Tabs tabs={TABS} current={tab} onChange={setTab} className="mb-6 px-0" />

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
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                {choice('timezone', 'المنطقة الزمنية', [
                                    { label: 'مسقط (GMT+4)', value: 'Asia/Muscat' },
                                    { label: 'الرياض (GMT+3)', value: 'Asia/Riyadh' },
                                ])}
                                {choice('date_format', 'تنسيق التاريخ', [
                                    { label: '2026-07-30', value: 'Y-m-d' },
                                    { label: '30/07/2026', value: 'd/m/Y' },
                                ])}
                            </div>
                            <Toggle
                                on={form.data.maintenance_mode}
                                onChange={(v) => form.setData('maintenance_mode', v)}
                                label="وضع الصيانة"
                                hint="إيقاف الوصول للمنصة مؤقتًا"
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
                            {text('address', 'العنوان')}
                            {text('cr', 'السجل التجاري', { ltr: true })}
                        </div>
                        {saveBar}
                    </Card>
                )}

                {tab === 'subscriptions' && (
                    <Card className="p-6">
                        <h3 className="mb-6 text-[18px] font-bold text-[#111]">{t('إعدادات الاشتراكات')}</h3>
                        <div className="space-y-5">
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                {text('trial_days', 'مدة الفترة التجريبية (أيام)', { type: 'number', ltr: true })}
                                {text('grace_days', 'مهلة السماح بعد الانتهاء (أيام)', { type: 'number', ltr: true })}
                            </div>
                            {text('default_plan', 'الباقة الافتراضية عند التسجيل')}
                            <Toggle
                                on={form.data.auto_renew}
                                onChange={(v) => form.setData('auto_renew', v)}
                                label="التجديد التلقائي للاشتراكات"
                            />
                            <Toggle
                                on={form.data.auto_suspend}
                                onChange={(v) => form.setData('auto_suspend', v)}
                                label="تعطيل الحساب تلقائيًا عند انتهاء المهلة"
                            />
                        </div>
                        {saveBar}
                    </Card>
                )}

                {tab === 'taxes' && (
                    <Card className="p-6">
                        <h3 className="mb-6 text-[18px] font-bold text-[#111]">{t('إعدادات الضرائب')}</h3>
                        <div className="space-y-5">
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                {text('vat_rate', 'نسبة ضريبة القيمة المضافة (%)', {
                                    type: 'number',
                                    ltr: true,
                                    hint: 'النسبة المطبقة في سلطنة عُمان',
                                })}
                                {text('tax_number', 'الرقم الضريبي', { ltr: true })}
                            </div>
                            {choice('tax_mode', 'طريقة احتساب الضريبة', [
                                { label: 'شاملة السعر', value: 'inclusive' },
                                { label: 'مضافة على السعر', value: 'exclusive' },
                            ])}
                            <Toggle
                                on={form.data.platform_vat_enabled}
                                onChange={(v) => form.setData('platform_vat_enabled', v)}
                                label="تفعيل ضريبة القيمة المضافة"
                            />
                        </div>
                        {saveBar}
                    </Card>
                )}

                {tab === 'currencies' && (
                    <Card className="p-6">
                        <h3 className="mb-6 text-[18px] font-bold text-[#111]">{t('إعدادات العملات')}</h3>
                        <div className="space-y-5">
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                {choice('base_currency', 'العملة الأساسية', [
                                    { label: 'ريال عماني (OMR)', value: 'OMR' },
                                    { label: 'درهم إماراتي (AED)', value: 'AED' },
                                    { label: 'ريال سعودي (SAR)', value: 'SAR' },
                                ])}
                                {text('currency_symbol', 'رمز العملة')}
                            </div>
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                {text('decimals', 'عدد الخانات العشرية', {
                                    type: 'number',
                                    ltr: true,
                                    hint: 'الريال العماني يستخدم 3 خانات عشرية',
                                })}
                                {choice('symbol_position', 'موضع الرمز', [
                                    { label: 'بعد المبلغ (12.500 ر.ع)', value: 'after' },
                                    { label: 'قبل المبلغ (ر.ع 12.500)', value: 'before' },
                                ])}
                            </div>
                        </div>
                        {saveBar}
                    </Card>
                )}

                {tab === 'notifications' && (
                    <Card className="p-6">
                        <h3 className="mb-6 text-[18px] font-bold text-[#111]">{t('إعدادات الإشعارات')}</h3>
                        <div className="divide-y divide-[#f5f5f4]">
                            {NOTIFICATIONS.map((label, i) => {
                                const key = `platform_notif_${i}` as Key;

                                return (
                                    <Toggle
                                        key={key}
                                        on={Boolean(form.data[key])}
                                        onChange={(v) => form.setData(key, v as never)}
                                        label={label}
                                    />
                                );
                            })}
                        </div>
                        {saveBar}
                    </Card>
                )}

                {tab === 'mail' && (
                    <Card className="p-6">
                        <h3 className="mb-6 text-[18px] font-bold text-[#111]">
                            {t('إعدادات البريد الإلكتروني')}
                        </h3>
                        <div className="space-y-5">
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                {choice('mailer', 'مزود الإرسال', [
                                    { label: 'SMTP', value: 'smtp' },
                                    { label: 'Amazon SES', value: 'ses' },
                                    { label: 'Mailgun', value: 'mailgun' },
                                ])}
                                {text('mail_host', 'خادم SMTP', { ltr: true })}
                            </div>
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                {text('mail_port', 'المنفذ', { type: 'number', ltr: true })}
                                {choice('mail_encryption', 'التشفير', [
                                    { label: 'TLS', value: 'tls' },
                                    { label: 'SSL', value: 'ssl' },
                                    { label: 'بدون', value: 'none' },
                                ])}
                            </div>
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                {text('from_address', 'بريد المُرسِل', { type: 'email', ltr: true })}
                                {text('from_name', 'اسم المُرسِل')}
                            </div>
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

                {(tab === 'terms' || tab === 'privacy') && (
                    <Card className="p-6">
                        <h3 className="mb-6 text-[18px] font-bold text-[#111]">
                            {t(tab === 'terms' ? 'الشروط والأحكام' : 'سياسة الخصوصية')}
                        </h3>
                        <Field label={tab === 'terms' ? 'نص الشروط والأحكام' : 'نص سياسة الخصوصية'}>
                            <Textarea
                                rows={12}
                                value={tab === 'terms' ? form.data.terms : form.data.privacy}
                                onChange={(e) => form.setData(tab, e.target.value as never)}
                            />
                        </Field>
                        {saveBar}
                    </Card>
                )}
            </form>
        </PlatformLayout>
    );
}
