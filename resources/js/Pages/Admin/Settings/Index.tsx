import { useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { AlertTriangle, Download, Save, Upload } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import Field, { Select } from '@/Components/Field';
import Toggle from '@/Components/Toggle';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input, Textarea } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

type Settings = Record<string, string>;

interface Props {
    settings: Settings;
    business: { name: string; phone: string | null; email: string | null; address: string | null };
    locale: string;
}

const TABS = [
    { key: 'business', label: 'بيانات النشاط' },
    { key: 'taxes', label: 'الضرائب' },
    { key: 'currency', label: 'العملة' },
    { key: 'invoices', label: 'الفواتير' },
    { key: 'printing', label: 'الطباعة' },
    { key: 'notifications', label: 'الإشعارات' },
    { key: 'orders', label: 'الطلبات' },
    { key: 'delivery', label: 'التوصيل' },
    { key: 'loyalty', label: 'الولاء' },
    { key: 'language', label: 'اللغة' },
    { key: 'backup', label: 'النسخ الاحتياطي' },
] as const;

type TabKey = (typeof TABS)[number]['key'];

export default function SettingsIndex() {
    const { settings, business, locale } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const [tab, setTab] = useState<TabKey>('business');
    const [pickedLocale, setPickedLocale] = useState(locale === 'en' ? 'en' : 'ar');
    const [backupFile, setBackupFile] = useState<File | null>(null);

    const get = (k: string, fallback = '') => settings[k] ?? fallback;
    const on = (k: string, fallback = '1') => (settings[k] ?? fallback) === '1';

    const form = useForm({
        shop_name: business.name ?? '',
        phone: business.phone ?? '',
        email: business.email ?? '',
        address: business.address ?? '',

        vat_enabled: on('vat_enabled'),
        vat_rate: get('vat_rate', '5'),
        vat_number: get('vat_number'),
        tax_mode: get('tax_mode', 'exclusive'),

        currency: get('currency', 'OMR'),
        decimals: get('decimals', '3'),
        symbol_pos: get('symbol_pos', 'after'),

        inv_prefix: get('inv_prefix', 'INV-'),
        inv_start: get('inv_start', '1'),
        invoice_show_logo: on('invoice_show_logo'),

        paper: get('paper', '80mm'),
        copies: get('copies', '1'),
        print_auto: on('print_auto', '0'),
        print_kitchen: on('print_kitchen', '0'),

        notify_new_order: on('notify_new_order'),
        notify_smart_alerts: on('notify_smart_alerts'),
        notify_daily_summary: on('notify_daily_summary'),

        order_prefix: get('order_prefix', 'ORD-'),
        default_status: get('default_status', 'جديد'),
        order_allow_edit: on('order_allow_edit'),
        order_confirm_cancel: on('order_confirm_cancel'),

        delivery_enabled: on('delivery_enabled', '0'),
        delivery_fee: get('delivery_fee', '0'),
        free_threshold: get('free_threshold', '0'),

        loyalty_enabled: on('loyalty_enabled'),
        loyalty_earn_rate: get('loyalty_earn_rate', '5'),
        loyalty_redeem_max_pct: get('loyalty_redeem_max_pct', '50'),
        loyalty_redeem_min: get('loyalty_redeem_min', '100'),
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(route('admin.settings.update'), { preserveScroll: true });
    };

    const saveBar = (
        <div className="mt-6 flex justify-end">
            <Button type="submit" disabled={form.processing}>
                <Save />
                {form.processing ? '…' : t('حفظ التغييرات')}
            </Button>
        </div>
    );

    return (
        <AdminLayout title="الإعدادات">
            <PageHeader
                title="الإعدادات"
                subtitle={t('إعدادات المتجر والضرائب والفواتير والإشعارات')}
                breadcrumbs={[{ label: 'الرئيسية', href: route('admin.dashboard') }, { label: 'الإعدادات' }]}
            />

            <div className="mb-6 flex items-center gap-1 overflow-x-auto border-b border-[var(--ui-border,#e8e8e8)]">
                {TABS.map((x) => (
                    <button
                        key={x.key}
                        type="button"
                        onClick={() => setTab(x.key)}
                        className={cn(
                            '-mb-px whitespace-nowrap border-b-2 px-4 py-3 text-sm font-medium transition-colors',
                            tab === x.key
                                ? 'border-[#111] text-[#111]'
                                : 'border-transparent text-[#6b7280] hover:text-[#374151]',
                        )}
                    >
                        {t(x.label)}
                    </button>
                ))}
            </div>

            {tab === 'language' ? (
                <Card className="max-w-2xl p-6">
                    <h3 className="mb-4 font-bold text-[#111]">{t('لغة النظام')}</h3>
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        {[
                            { code: 'ar', label: 'العربية', hint: 'من اليمين إلى اليسار (RTL)' },
                            { code: 'en', label: 'English', hint: 'من اليسار إلى اليمين (LTR)' },
                        ].map((l) => (
                            <label
                                key={l.code}
                                className={cn(
                                    'flex cursor-pointer items-center justify-between rounded-[12px] border px-4 py-3.5 transition',
                                    pickedLocale === l.code
                                        ? 'border-[#111] bg-[#fafafa]'
                                        : 'border-[var(--ui-border,#e8e8e8)] hover:bg-[#fafafa]',
                                )}
                            >
                                <span>
                                    <span className="block text-sm font-medium text-[#111]">{l.label}</span>
                                    <span className="block text-[12px] text-[#9ca3af]">{t(l.hint)}</span>
                                </span>
                                <input
                                    type="radio"
                                    name="locale"
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
                                // اتجاه المستند يُحسم في قالب الجذر، فنُعيد التحميل بعد الحفظ
                                router.post(
                                    route('admin.language.update'),
                                    { locale: pickedLocale },
                                    { onSuccess: () => window.location.reload() },
                                )
                            }
                        >
                            <Save />
                            {t('حفظ اللغة')}
                        </Button>
                    </div>
                </Card>
            ) : tab === 'backup' ? (
                <div className="grid max-w-3xl grid-cols-1 gap-6">
                    <Card className="p-6">
                        <h3 className="mb-2 font-bold text-[#111]">{t('تنزيل نسخة احتياطية')}</h3>
                        <p className="mb-4 text-[13px] text-[#6b7280]">
                            {t('يشمل الملف كامل بيانات متجرك: المنتجات، الأقسام، العملاء، الطلبات، المصروفات وغيرها.')}
                        </p>
                        <Button asChild>
                            <a href={route('admin.backup.download')}>
                                <Download />
                                {t('تنزيل النسخة الآن')}
                            </a>
                        </Button>
                    </Card>

                    <Card className="p-6">
                        <h3 className="mb-2 font-bold text-[#111]">{t('استعادة من نسخة احتياطية')}</h3>
                        <p className="mb-4 flex items-start gap-2 rounded-[12px] bg-[#fef2f2] px-3 py-2.5 text-[12px] text-[#b91c1c]">
                            <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                            {t('تحذير: ستحل بيانات النسخة محل بيانات متجرك الحالية.')}
                        </p>
                        <form
                            onSubmit={(e) => {
                                e.preventDefault();
                                if (!backupFile) return;
                                router.post(
                                    route('admin.backup.restore'),
                                    { backup: backupFile },
                                    { forceFormData: true },
                                );
                            }}
                        >
                            <Field label="اختر ملف النسخة الاحتياطية (JSON)">
                                <Input
                                    type="file"
                                    accept=".json,application/json"
                                    onChange={(e) => setBackupFile(e.target.files?.[0] ?? null)}
                                    className="h-auto py-2 file:me-3 file:rounded-lg file:bg-[#111] file:px-4 file:py-2 file:text-white"
                                />
                            </Field>
                            <Button type="submit" variant="danger" className="mt-4" disabled={!backupFile}>
                                <Upload />
                                {t('استعادة البيانات')}
                            </Button>
                        </form>
                    </Card>
                </div>
            ) : (
                <form onSubmit={submit} className="max-w-3xl">
                    <Card className="p-6">
                        {tab === 'business' && (
                            <>
                                <h3 className="mb-4 font-bold text-[#111]">{t('بيانات النشاط')}</h3>
                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <Field label="اسم المتجر" required error={form.errors.shop_name}>
                                        <Input value={form.data.shop_name} onChange={(e) => form.setData('shop_name', e.target.value)} required />
                                    </Field>
                                    <Field label="رقم الهاتف" error={form.errors.phone}>
                                        <Input dir="ltr" value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} />
                                    </Field>
                                    <Field label="البريد الإلكتروني" error={form.errors.email}>
                                        <Input type="email" dir="ltr" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />
                                    </Field>
                                    <Field label="العنوان" className="sm:col-span-2" error={form.errors.address}>
                                        <Textarea rows={2} value={form.data.address} onChange={(e) => form.setData('address', e.target.value)} />
                                    </Field>
                                </div>
                            </>
                        )}

                        {tab === 'taxes' && (
                            <>
                                <h3 className="mb-4 font-bold text-[#111]">{t('الضرائب')}</h3>
                                <Toggle
                                    on={form.data.vat_enabled}
                                    onChange={(v) => form.setData('vat_enabled', v)}
                                    label="تفعيل ضريبة القيمة المضافة"
                                    hint="تُحتسب على كل فاتورة بيع"
                                />
                                <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <Field label="نسبة الضريبة (%)" error={form.errors.vat_rate}>
                                        <Input type="number" step="0.01" min="0" dir="ltr" value={form.data.vat_rate} onChange={(e) => form.setData('vat_rate', e.target.value)} />
                                    </Field>
                                    <Field label="الرقم الضريبي (TRN)" error={form.errors.vat_number}>
                                        <Input dir="ltr" value={form.data.vat_number} onChange={(e) => form.setData('vat_number', e.target.value)} placeholder="OM1100XXXXXX" />
                                    </Field>
                                    <Field label="طريقة الاحتساب" error={form.errors.tax_mode}>
                                        <Select
                                            value={form.data.tax_mode}
                                            onChange={(e) => form.setData('tax_mode', e.target.value)}
                                            options={[
                                                { label: 'تُضاف على السعر', value: 'exclusive' },
                                                { label: 'مشمولة في السعر', value: 'inclusive' },
                                            ]}
                                        />
                                    </Field>
                                </div>
                            </>
                        )}

                        {tab === 'currency' && (
                            <>
                                <h3 className="mb-4 font-bold text-[#111]">{t('العملة')}</h3>
                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <Field label="العملة" error={form.errors.currency}>
                                        <Input dir="ltr" value={form.data.currency} onChange={(e) => form.setData('currency', e.target.value)} />
                                    </Field>
                                    <Field label="عدد الخانات العشرية" error={form.errors.decimals}>
                                        <Select
                                            value={form.data.decimals}
                                            onChange={(e) => form.setData('decimals', e.target.value)}
                                            options={[0, 1, 2, 3].map((n) => ({ label: String(n), value: n }))}
                                        />
                                    </Field>
                                    <Field label="موضع الرمز" error={form.errors.symbol_pos}>
                                        <Select
                                            value={form.data.symbol_pos}
                                            onChange={(e) => form.setData('symbol_pos', e.target.value)}
                                            options={[
                                                { label: 'بعد المبلغ', value: 'after' },
                                                { label: 'قبل المبلغ', value: 'before' },
                                            ]}
                                        />
                                    </Field>
                                </div>
                            </>
                        )}

                        {tab === 'invoices' && (
                            <>
                                <h3 className="mb-4 font-bold text-[#111]">{t('الفواتير')}</h3>
                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <Field label="بادئة رقم الفاتورة" error={form.errors.inv_prefix}>
                                        <Input dir="ltr" value={form.data.inv_prefix} onChange={(e) => form.setData('inv_prefix', e.target.value)} />
                                    </Field>
                                    <Field label="رقم البداية" error={form.errors.inv_start}>
                                        <Input type="number" min="1" dir="ltr" value={form.data.inv_start} onChange={(e) => form.setData('inv_start', e.target.value)} />
                                    </Field>
                                </div>
                                <div className="mt-2">
                                    <Toggle
                                        on={form.data.invoice_show_logo}
                                        onChange={(v) => form.setData('invoice_show_logo', v)}
                                        label="إظهار الشعار في الفاتورة"
                                    />
                                </div>
                            </>
                        )}

                        {tab === 'printing' && (
                            <>
                                <h3 className="mb-4 font-bold text-[#111]">{t('الطباعة')}</h3>
                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <Field label="مقاس الورق" error={form.errors.paper}>
                                        <Select
                                            value={form.data.paper}
                                            onChange={(e) => form.setData('paper', e.target.value)}
                                            options={[
                                                { label: '80mm', value: '80mm' },
                                                { label: '58mm', value: '58mm' },
                                                { label: 'A4', value: 'A4' },
                                            ]}
                                        />
                                    </Field>
                                    <Field label="عدد النسخ" error={form.errors.copies}>
                                        <Select
                                            value={form.data.copies}
                                            onChange={(e) => form.setData('copies', e.target.value)}
                                            options={[1, 2, 3].map((n) => ({ label: String(n), value: n }))}
                                        />
                                    </Field>
                                </div>
                                <div className="mt-2">
                                    <Toggle on={form.data.print_auto} onChange={(v) => form.setData('print_auto', v)} label="طباعة تلقائية بعد كل بيع" />
                                    <Toggle on={form.data.print_kitchen} onChange={(v) => form.setData('print_kitchen', v)} label="طباعة نسخة للتجهيز" />
                                </div>
                            </>
                        )}

                        {tab === 'notifications' && (
                            <>
                                <h3 className="mb-4 font-bold text-[#111]">{t('الإشعارات')}</h3>
                                <Toggle
                                    on={form.data.notify_new_order}
                                    onChange={(v) => form.setData('notify_new_order', v)}
                                    label="إرسال بريد إلكتروني عند كل طلب جديد"
                                    hint="يُرسل إلى بريد صاحب النشاط عند إتمام أي عملية بيع."
                                />
                                <Toggle
                                    on={form.data.notify_smart_alerts}
                                    onChange={(v) => form.setData('notify_smart_alerts', v)}
                                    label="التنبيهات الذكية"
                                    hint="نفاد المخزون، ركود المنتجات، وتغيّر الأداء."
                                />
                                <Toggle
                                    on={form.data.notify_daily_summary}
                                    onChange={(v) => form.setData('notify_daily_summary', v)}
                                    label="ملخّص الأداء اليومي"
                                    hint="يصل آخر اليوم بمبيعات اليوم وأبرز أرقامه."
                                />
                            </>
                        )}

                        {tab === 'orders' && (
                            <>
                                <h3 className="mb-4 font-bold text-[#111]">{t('إعدادات الطلبات')}</h3>
                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <Field label="بادئة رقم الطلب" error={form.errors.order_prefix}>
                                        <Input dir="ltr" value={form.data.order_prefix} onChange={(e) => form.setData('order_prefix', e.target.value)} />
                                    </Field>
                                    <Field label="الحالة الافتراضية للطلب الجديد" error={form.errors.default_status}>
                                        <Select
                                            value={form.data.default_status}
                                            onChange={(e) => form.setData('default_status', e.target.value)}
                                            options={[
                                                { label: 'جديد', value: 'جديد' },
                                                { label: 'قيد التجهيز', value: 'قيد التجهيز' },
                                                { label: 'مكتمل', value: 'مكتمل' },
                                            ]}
                                        />
                                    </Field>
                                </div>
                                <div className="mt-2">
                                    <Toggle on={form.data.order_allow_edit} onChange={(v) => form.setData('order_allow_edit', v)} label="السماح بتعديل الطلب بعد إنشائه" />
                                    <Toggle on={form.data.order_confirm_cancel} onChange={(v) => form.setData('order_confirm_cancel', v)} label="طلب تأكيد قبل إلغاء الطلب" />
                                </div>
                            </>
                        )}

                        {tab === 'delivery' && (
                            <>
                                <h3 className="mb-4 font-bold text-[#111]">{t('إعدادات التوصيل')}</h3>
                                <Toggle
                                    on={form.data.delivery_enabled}
                                    onChange={(v) => form.setData('delivery_enabled', v)}
                                    label="تفعيل خدمة التوصيل"
                                    hint="إتاحة التوصيل للطلبات الخارجية"
                                />
                                <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <Field label="رسوم التوصيل الافتراضية" error={form.errors.delivery_fee}>
                                        <Input type="number" step="0.001" min="0" dir="ltr" value={form.data.delivery_fee} onChange={(e) => form.setData('delivery_fee', e.target.value)} />
                                    </Field>
                                    <Field label="حد التوصيل المجاني" error={form.errors.free_threshold}>
                                        <Input type="number" step="0.001" min="0" dir="ltr" value={form.data.free_threshold} onChange={(e) => form.setData('free_threshold', e.target.value)} />
                                    </Field>
                                </div>
                            </>
                        )}

                        {tab === 'loyalty' && (
                            <>
                                <h3 className="mb-4 font-bold text-[#111]">{t('نقاط الولاء')}</h3>
                                <Toggle
                                    on={form.data.loyalty_enabled}
                                    onChange={(v) => form.setData('loyalty_enabled', v)}
                                    label="تفعيل برنامج الولاء"
                                    hint="١٠٠ نقطة = وحدة عملة واحدة عند الاستبدال"
                                />
                                <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
                                    <Field label="نقاط لكل وحدة شراء" error={form.errors.loyalty_earn_rate}>
                                        <Input type="number" min="0" dir="ltr" value={form.data.loyalty_earn_rate} onChange={(e) => form.setData('loyalty_earn_rate', e.target.value)} />
                                    </Field>
                                    <Field label="أقصى نسبة استبدال (%)" error={form.errors.loyalty_redeem_max_pct}>
                                        <Input type="number" min="0" max="100" dir="ltr" value={form.data.loyalty_redeem_max_pct} onChange={(e) => form.setData('loyalty_redeem_max_pct', e.target.value)} />
                                    </Field>
                                    <Field label="الحد الأدنى لبدء الاستبدال" error={form.errors.loyalty_redeem_min}>
                                        <Input type="number" min="0" dir="ltr" value={form.data.loyalty_redeem_min} onChange={(e) => form.setData('loyalty_redeem_min', e.target.value)} />
                                    </Field>
                                </div>
                            </>
                        )}

                        {saveBar}
                    </Card>
                </form>
            )}
        </AdminLayout>
    );
}
