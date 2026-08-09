import { useState } from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    BellOff,
    BellRing,
    ChevronLeft,
    ChevronRight,
    Download,
    Save,
    Trash2,
    Upload,
} from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import Field, { Select } from '@/Components/Field';
import Toggle from '@/Components/Toggle';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input, Textarea } from '@/Components/ui/input';
import CustomAlerts, {
    type AlertMetric,
    type CustomAlertRow,
} from './partials/CustomAlerts';
import { SETTINGS_NAV } from './partials/SettingsNav';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

type Settings = Record<string, string>;

interface NotificationRow {
    key: string;
    text: string;
    time?: string;
    icon?: string;
    color?: string;
    url?: string;
}

interface Props {
    settings: Settings;
    business: { name: string; phone: string | null; email: string | null; address: string | null };
    notificationsAll: NotificationRow[];
    customAlerts: CustomAlertRow[];
    staffPermissions: { id: number; name: string; job_title: string; manual: boolean; count: number }[];
    alertMetrics: AlertMetric[];
    alertSections: Record<string, string>;
    locale: string;
}

/**
 * أقسام الإعدادات معروضةً كلوحة بطاقات مستطيلة أفقية.
 *
 * كانت شريطًا أفقيًّا من ستة عشر تبويبًا ثم عمودًا جانبيًّا؛ صارت اللوحة
 * تُظهرها كلّها دفعةً واحدة، كلٌّ في بطاقةٍ تحمل أيقونتها واسمها ووصفًا
 * قصيرًا تحته — فيُعرف ما وراء القسم قبل فتحه. والنقر على بطاقةٍ يفتح قسمها
 * مكان اللوحة، ويعود إليها زرُّ رجوع.
 */
const NAV = SETTINGS_NAV;

type TabKey = (typeof NAV)[number]['items'][number]['key'];

/**
 * مفاتيح الأقسام التي تُفتح داخل هذه الصفحة — دون بنود «النظام» التي لها
 * `route` فتنقل إلى صفحاتها المستقلّة. مرساةٌ تحمل أحدها تفتح اللوحة لا قسمًا
 * فارغًا لا محتوى له هنا.
 */
const TAB_KEYS = NAV.flatMap((g) =>
    g.items.filter((i) => !('route' in i && i.route)).map((i) => i.key),
) as readonly TabKey[];

/**
 * القسم المطلوب من عنوان الصفحة: /admin/settings#taxes.
 *
 * بلا مرساة تُفتح لوحة البطاقات (الحالة الرئيسية للإعدادات). ومع مرساةٍ
 * صحيحة يُفتح ذلك القسم مباشرةً، فرابطٌ مثل «أضِف الرقم الضريبي من الإعدادات»
 * في صفحة الضريبة يُنزل المستخدم في قسم الضريبة لا في اللوحة.
 *
 * القيمة الغريبة أو مفتاح صفحةٍ مستقلّة يعود إلى اللوحة (null) بدل أن يُظهر
 * قسمًا فارغًا.
 */
function tabFromHash(): TabKey | null {
    if (typeof window === 'undefined') return null;
    const key = window.location.hash.replace(/^#/, '');
    return (TAB_KEYS as readonly string[]).includes(key) ? (key as TabKey) : null;
}

/** طرق الدفع المتاحة — المفاتيح تطابق ما كان يحفظه القالب السابق (pay_*) */
const PAYMENT_METHODS = [
    { key: 'pay_cash', label: 'نقدي', hint: 'الدفع النقدي عند الشراء' },
    { key: 'pay_card', label: 'بطاقة (فيزا)', hint: 'الدفع عبر بطاقات الصراف والائتمان' },
    { key: 'pay_transfer', label: 'تحويل بنكي', hint: 'التحويل المباشر للحساب البنكي' },
] as const;


/** صفحات مستقلة يصل إليها المستخدم من الإعدادات */

/** صفوف قالب الإيصال — كلٌّ منها يُظهر شيئًا أو يُخفيه */
const TEMPLATE_ROWS: { key: string; label: string; hint?: string }[] = [
    { key: 'tpl_show_logo', label: 'شعار المتجر', hint: 'يظهر فقط إن كان للنشاط شعار محفوظ' },
    { key: 'tpl_show_branch', label: 'اسم الفرع' },
    { key: 'tpl_show_employee', label: 'اسم الموظف' },
    { key: 'tpl_show_customer', label: 'اسم العميل' },
    { key: 'tpl_show_datetime', label: 'التاريخ والوقت' },
    { key: 'tpl_show_items_count', label: 'عدد الأصناف' },
    { key: 'tpl_show_vat_no', label: 'الرقم الضريبي' },
    { key: 'tpl_show_qr', label: 'رمز الفوترة الإلكترونية (QR)', hint: 'إخفاؤه قد يخالف متطلبات الفوترة' },
];

const NOTIF_COLORS: Record<string, string> = {
    danger: 'bg-[#fef2f2] text-[#dc2626]',
    warning: 'bg-[#fffbeb] text-[#d97706]',
    info: 'bg-[#eff6ff] text-[#2563eb]',
    success: 'bg-[#f0fdf4] text-[#16a34a]',
};

export default function SettingsIndex() {
    const { settings, business, notificationsAll, customAlerts, alertMetrics, alertSections, staffPermissions, locale } =
        usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const [tab, setTab] = useState<TabKey | null>(tabFromHash);
    const [pickedLocale, setPickedLocale] = useState(locale === 'en' ? 'en' : 'ar');
    const [backupFile, setBackupFile] = useState<File | null>(null);
    const [notifs, setNotifs] = useState<NotificationRow[]>(notificationsAll ?? []);

    const pick = (key: string) => {
        const tabKey = key as TabKey;
        setTab(tabKey);
        // العنوان يتبع القسم المفتوح، فيبقى قابلًا للنسخ والمشاركة وإعادة
        // التحميل. replaceState لا pushState: التنقّل بين الأقسام ليس تصفّحًا
        // يستحقّ أن يمتلئ به زرّ الرجوع.
        window.history.replaceState(null, '', `#${key}`);
        // القسم يحلّ محلّ اللوحة كاملةً، فنبدأ المستخدم من رأسه لا من موضع
        // البطاقة التي نقرها.
        window.scrollTo({ top: 0 });
    };

    /** العودة من قسمٍ إلى لوحة البطاقات، ومحو المرساة كي يعكس العنوان اللوحة. */
    const goHub = () => {
        setTab(null);
        window.history.replaceState(null, '', window.location.pathname);
        window.scrollTo({ top: 0 });
    };

    const get = (k: string, fallback = '') => settings[k] ?? fallback;
    const on = (k: string, fallback = '1') => (settings[k] ?? fallback) === '1';

    const form = useForm({
        shop_name: business.name ?? '',
        phone: business.phone ?? '',
        email: business.email ?? '',
        address: business.address ?? '',
        website: get('website'),

        vat_enabled: on('vat_enabled'),
        vat_rate: get('vat_rate', '5'),
        vat_number: get('vat_number'),
        tax_mode: get('tax_mode', 'exclusive'),

        currency: get('currency', 'OMR'),
        decimals: get('decimals', '3'),
        symbol_pos: get('symbol_pos', 'after'),

        pay_cash: on('pay_cash'),
        pay_card: on('pay_card'),
        pay_transfer: on('pay_transfer'),

        perm_0_0: on('perm_0_0'),
        perm_0_1: on('perm_0_1'),
        perm_0_2: on('perm_0_2'),
        perm_1_0: on('perm_1_0'),
        perm_1_1: on('perm_1_1'),
        perm_1_2: on('perm_1_2'),
        perm_2_0: on('perm_2_0'),
        perm_2_1: on('perm_2_1'),
        perm_2_2: on('perm_2_2'),

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
        // مطفأ افتراضيًّا: منعُ البيع أخطر تصرّف في نقطة بيع
        require_open_shift: on('require_open_shift', '0'),
        loyalty_earn_rate: get('loyalty_earn_rate', '5'),
        loyalty_redeem_max_pct: get('loyalty_redeem_max_pct', '50'),
        loyalty_redeem_min: get('loyalty_redeem_min', '100'),

        /* قوالب الطباعة — الافتراضات هي شكل الإيصال قبل وجود هذا القسم
           حرفيًّا: تاجرٌ لم يفتحه قطّ يطبع اليوم ما كان يطبعه أمس. */
        tpl_header: get('tpl_header'),
        tpl_footer: get('tpl_footer', 'شكرًا لزيارتكم 🌹\nنتشرف بخدمتكم دائمًا'),
        tpl_font: get('tpl_font', 'عادي'),
        tpl_show_logo: on('tpl_show_logo', '0'),
        tpl_show_branch: on('tpl_show_branch'),
        tpl_show_employee: on('tpl_show_employee'),
        tpl_show_customer: on('tpl_show_customer'),
        tpl_show_datetime: on('tpl_show_datetime'),
        tpl_show_items_count: on('tpl_show_items_count'),
        tpl_show_vat_no: on('tpl_show_vat_no', '0'),
        tpl_show_qr: on('tpl_show_qr'),
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(route('admin.settings.update'), { preserveScroll: true });
    };

    const saveBar = (
        <div className="mt-6 flex justify-end">
            <Button type="submit" loading={form.processing}>
                <Save />
                {t('حفظ التغييرات')}
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

            {tab === null ? (
                /* لوحة البطاقات — كل قسمٍ بطاقةٌ مستطيلة أفقية: أيقونته ثم
                   اسمه ووصفٌ خافتٌ تحته. النقر يفتح القسم مكان اللوحة. */
                <div className="max-w-6xl space-y-8">
                    {NAV.map((g) => (
                        <section key={g.group}>
                            <h3 className="mb-3 text-[13px] font-semibold text-[#6b7280]">{t(g.group)}</h3>
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                {g.items.map((x) => {
                                    const body = (
                                        <>
                                            <span className="flex size-[52px] shrink-0 items-center justify-center rounded-[14px] bg-[#f5f5f4] text-[#111] transition-colors group-hover:bg-[#111] group-hover:text-white">
                                                <x.icon className="size-6" />
                                            </span>
                                            <span className="min-w-0 flex-1">
                                                <span className="block text-[15px] font-semibold text-[#111]">{t(x.label)}</span>
                                                <span className="mt-1 block text-[13px] leading-snug text-[#9ca3af]">{t(x.desc)}</span>
                                            </span>
                                            <ChevronLeft className="size-4 shrink-0 text-[#d1d5db] transition-colors group-hover:text-[#6b7280]" />
                                        </>
                                    );
                                    const cls =
                                        'group flex items-center gap-4 rounded-[16px] border border-[var(--ui-border,#e8e8e8)] bg-white p-5 text-start transition hover:border-[#d4d4d4] hover:bg-[#fafafa]';
                                    // بند «النظام» بمساره ينقل إلى صفحته المستقلّة؛ سواه يفتح قسمه هنا
                                    return 'route' in x && x.route ? (
                                        <Link key={x.key} href={route(x.route)} className={cls}>
                                            {body}
                                        </Link>
                                    ) : (
                                        <button key={x.key} type="button" onClick={() => pick(x.key)} className={cls}>
                                            {body}
                                        </button>
                                    );
                                })}
                            </div>
                        </section>
                    ))}
                </div>
            ) : (
                /* قسمٌ مفتوح — سقفٌ للعرض كي لا يمتدّ السطر عبر الشاشة كاملةً */
                <div className="min-w-0 max-w-4xl scroll-mt-4">
                    <button
                        type="button"
                        onClick={goHub}
                        className="mb-5 inline-flex items-center gap-1.5 text-sm font-medium text-[#6b7280] transition-colors hover:text-[#111]"
                    >
                        <ChevronRight className="size-4" />
                        {t('كل الإعدادات')}
                    </button>
            {tab === 'language' ? (
                <Card className="p-6">
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
            ) : tab === 'custom-alerts' ? (
                <CustomAlerts
                    alerts={customAlerts ?? []}
                    metrics={alertMetrics ?? []}
                    sections={alertSections ?? {}}
                />
            ) : tab === 'notifications-log' ? (
                <Card className="p-6">
                    <div className="mb-6 flex items-start justify-between gap-3">
                        <div>
                            <h3 className="font-bold text-[#111]">{t('التنبيهات المرسلة')}</h3>
                            <p className="mt-1 text-[13px] text-[#6b7280]">
                                {t('جميع التنبيهات التي أُرسلت إليك — مخزون منخفض وطلبات بانتظار التجهيز.')}
                            </p>
                        </div>
                        <div className="flex shrink-0 items-center gap-2">
                            <span className="inline-flex items-center gap-1.5 rounded-full bg-[#f3f4f6] px-3 py-1.5 text-sm font-medium text-[#374151]">
                                <BellRing className="size-4" />
                                {notifs.length} {t('تنبيه')}
                            </span>
                            {notifs.length > 0 && (
                                <Button
                                    type="button"
                                    variant="danger"
                                    onClick={() => {
                                        if (!confirm(t('حذف جميع التنبيهات المرسلة؟'))) return;
                                        router.post(
                                            route('admin.notifications.clear'),
                                            {},
                                            { preserveScroll: true, onSuccess: () => setNotifs([]) },
                                        );
                                    }}
                                >
                                    <Trash2 />
                                    {t('حذف الكل')}
                                </Button>
                            )}
                        </div>
                    </div>

                    {notifs.length === 0 ? (
                        <div className="py-12 text-center">
                            <BellOff className="mx-auto mb-3 size-8 text-[#d1d5db]" />
                            <p className="font-medium text-[#374151]">{t('لا توجد تنبيهات')}</p>
                            <p className="mt-1 text-[13px] text-[#9ca3af]">
                                {t('ستظهر هنا عند انخفاض المخزون أو ورود طلبات جديدة.')}
                            </p>
                        </div>
                    ) : (
                        <div className="divide-y divide-[var(--ui-border,#e8e8e8)]">
                            {notifs.map((n) => (
                                <div key={n.key} className="flex items-start gap-3 py-3">
                                    <span
                                        className={cn(
                                            'flex size-9 shrink-0 items-center justify-center rounded-full',
                                            NOTIF_COLORS[n.color ?? 'info'] ?? NOTIF_COLORS.info,
                                        )}
                                    >
                                        <BellRing className="size-[18px]" />
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        {n.url ? (
                                            <Link href={n.url} className="text-sm text-[#111] hover:underline">
                                                {n.text}
                                            </Link>
                                        ) : (
                                            <p className="text-sm text-[#111]">{n.text}</p>
                                        )}
                                        {n.time && <p className="mt-0.5 text-[12px] text-[#9ca3af]">{n.time}</p>}
                                    </div>
                                    <button
                                        type="button"
                                        title={t('حذف')}
                                        onClick={() =>
                                            router.post(
                                                route('admin.notifications.dismiss'),
                                                { key: n.key },
                                                {
                                                    preserveScroll: true,
                                                    onSuccess: () =>
                                                        setNotifs((prev) => prev.filter((x) => x.key !== n.key)),
                                                },
                                            )
                                        }
                                        className="flex size-8 shrink-0 items-center justify-center rounded-full text-[#d1d5db] transition-colors hover:bg-[#fef2f2] hover:text-[#dc2626]"
                                    >
                                        <Trash2 className="size-4" />
                                    </button>
                                </div>
                            ))}
                        </div>
                    )}
                </Card>
            ) : tab === 'backup' ? (
                <div className="grid grid-cols-1 gap-6">
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
                <form onSubmit={submit}>
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
                                    <Field
                                        label="الموقع الإلكتروني"
                                        hint="يظهر كزرّ في الرئيسية بجانب «فتح نقطة البيع»"
                                        error={form.errors.website}
                                    >
                                        <Input
                                            dir="ltr"
                                            value={form.data.website}
                                            onChange={(e) => form.setData('website', e.target.value)}
                                            placeholder="example.com"
                                        />
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

                        {tab === 'payments' && (
                            <>
                                <h3 className="mb-4 font-bold text-[#111]">{t('طرق الدفع')}</h3>
                                {PAYMENT_METHODS.map((m) => (
                                    <Toggle
                                        key={m.key}
                                        on={form.data[m.key]}
                                        onChange={(v) => form.setData(m.key, v)}
                                        label={m.label}
                                        hint={m.hint}
                                    />
                                ))}
                            </>
                        )}

                        {tab === 'permissions' && (
                            <>
                                <h3 className="mb-2 font-bold text-[#111]">{t('صلاحيات الموظفين')}</h3>
                                <p className="mb-5 text-[13px] text-[#6b7280]">
                                    {t('الصلاحية تُحدَّد لكل موظف على حدة من ملفه — ولا قسم يُفتح ما لم يُمنح.')}
                                </p>

                                {/*
                                    كان هنا جدول مربّعات لكل دور يحفظ مفاتيح perm_*
                                    لا يقرؤها أي كود: تُبدَّل فلا يتغيّر شيء. استُبدل
                                    بقائمة الموظفين الفعليين وحالة صلاحية كلٍّ منهم،
                                    ومنها يُفتح ملفه حيث تُحدَّد فعلًا.
                                */}
                                {(staffPermissions ?? []).length === 0 ? (
                                    <p className="py-8 text-center text-[13px] text-[#9ca3af]">
                                        {t('لا يوجد موظفون بعد')}
                                    </p>
                                ) : (
                                    <ul className="space-y-2">
                                        {(staffPermissions ?? []).map((e) => (
                                            <li
                                                key={e.id}
                                                className="flex items-center gap-3 rounded-[12px] border border-[var(--ui-border,#e8e8e8)] p-3"
                                            >
                                                <div className="min-w-0 flex-1">
                                                    <p className="truncate text-sm font-medium text-[#111]">{e.name}</p>
                                                    <p className="truncate text-[12px] text-[#9ca3af]">
                                                        {e.job_title} ·{' '}
                                                        {e.manual
                                                            ? t(':n قسم مخصّص', { n: e.count })
                                                            : t('لم تُحدَّد بعد')}
                                                    </p>
                                                </div>
                                                <Button variant="outline" size="sm" asChild>
                                                    <Link href={route('admin.employees.edit', e.id)}>
                                                        {t('تحديد الصلاحيات')}
                                                    </Link>
                                                </Button>
                                            </li>
                                        ))}
                                    </ul>
                                )}
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

                        {tab === 'templates' && (
                            <>
                                <h3 className="mb-1 font-bold text-[#111]">{t('قالب الإيصال')}</h3>
                                <p className="mb-4 text-[12px] text-[#9ca3af]">
                                    {t('ما يظهر على الإيصال المطبوع بعد كل بيع — والمعاينة على اليمين تتبع كل تغيير.')}
                                </p>

                                <div className="grid grid-cols-1 gap-6 lg:grid-cols-[1fr_260px]">
                                    <div className="space-y-4">
                                        <Field
                                            label="سطر تحت اسم المتجر"
                                            hint="شعار أو عبارة ترحيب — يُترك فارغًا فلا يظهر"
                                            error={form.errors.tpl_header}
                                        >
                                            <Input
                                                value={form.data.tpl_header}
                                                onChange={(e) => form.setData('tpl_header', e.target.value)}
                                                placeholder={t('مثال: أجمل الورود منذ 1998')}
                                            />
                                        </Field>

                                        <Field
                                            label="نص التذييل"
                                            hint="يظهر أسفل الإيصال — كل سطر كما كتبته"
                                            error={form.errors.tpl_footer}
                                        >
                                            <Textarea
                                                rows={3}
                                                value={form.data.tpl_footer}
                                                onChange={(e) => form.setData('tpl_footer', e.target.value)}
                                            />
                                        </Field>

                                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                            <Field label="حجم الخط" error={form.errors.tpl_font}>
                                                <Select
                                                    value={form.data.tpl_font}
                                                    onChange={(e) => form.setData('tpl_font', e.target.value)}
                                                    options={['صغير', 'عادي', 'كبير'].map((x) => ({
                                                        label: t(x),
                                                        value: x,
                                                    }))}
                                                />
                                            </Field>
                                            <Field
                                                label="مقاس الورق"
                                                hint="نفس إعداد قسم «الطباعة»"
                                                error={form.errors.paper}
                                            >
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
                                        </div>

                                        <div className="border-t border-[var(--ui-border,#e8e8e8)] pt-4">
                                            <p className="mb-2 text-[13px] font-semibold text-[#111]">
                                                {t('ما يظهر على الإيصال')}
                                            </p>
                                            {TEMPLATE_ROWS.map((r) => (
                                                <Toggle
                                                    key={r.key}
                                                    on={form.data[r.key as keyof typeof form.data] as boolean}
                                                    onChange={(v) => form.setData(r.key as 'tpl_show_qr', v)}
                                                    label={r.label}
                                                    hint={r.hint}
                                                />
                                            ))}
                                        </div>
                                    </div>

                                    {/* معاينة حيّة — محرّر قالبٍ بلا معاينة تخمين،
                                        ولا يُكتشف خطؤه إلا على ورقٍ أمام الزبون */}
                                    <div className="lg:sticky lg:top-4 lg:self-start">
                                        <p className="mb-2 text-[12px] text-[#9ca3af]">{t('معاينة')}</p>
                                        <div
                                            dir="rtl"
                                            className="rounded-[12px] border border-dashed border-[#d1d5db] bg-white p-4 font-mono leading-relaxed text-[#111]"
                                            style={{
                                                fontSize:
                                                    form.data.tpl_font === 'صغير'
                                                        ? '9px'
                                                        : form.data.tpl_font === 'كبير'
                                                          ? '12px'
                                                          : '10.5px',
                                            }}
                                        >
                                            <div className="text-center">
                                                {form.data.tpl_show_logo && (
                                                    <div className="mx-auto mb-1 h-6 w-14 rounded bg-[#f3f4f6]" />
                                                )}
                                                <p className="font-bold">{form.data.shop_name || t('اسم المتجر')}</p>
                                                {form.data.tpl_header && (
                                                    <p className="text-[#6b7280]">{form.data.tpl_header}</p>
                                                )}
                                                {form.data.tpl_show_branch && (
                                                    <p className="text-[#6b7280]">{t('الفرع الرئيسي')}</p>
                                                )}
                                                {form.data.tpl_show_vat_no && form.data.vat_number && (
                                                    <p className="text-[#6b7280]">
                                                        {t('الرقم الضريبي')}: {form.data.vat_number}
                                                    </p>
                                                )}
                                            </div>
                                            <div className="my-2 border-t border-dashed border-[#d1d5db]" />
                                            <p>{t('رقم الفاتورة')}: {form.data.inv_prefix}000001</p>
                                            {form.data.tpl_show_employee && <p>{t('الموظف')}: {t('أحمد')}</p>}
                                            {form.data.tpl_show_customer && <p>{t('العميل')}: {t('عميل نقدي')}</p>}
                                            {form.data.tpl_show_datetime && <p dir="ltr" className="text-end">2026-08-02 10:15</p>}
                                            <div className="my-2 border-t border-dashed border-[#d1d5db]" />
                                            <p>{t('باقة ورد')} × 2 — 21.000</p>
                                            {form.data.tpl_show_items_count && (
                                                <p className="text-[#6b7280]">{t('عدد الأصناف')}: 2</p>
                                            )}
                                            <div className="my-2 border-t border-dashed border-[#d1d5db]" />
                                            <p className="font-bold">{t('الإجمالي')}: 22.050</p>
                                            {form.data.tpl_show_qr && (
                                                <div className="mx-auto my-2 size-12 rounded bg-[#f3f4f6]" />
                                            )}
                                            <div className="my-2 border-t border-dashed border-[#d1d5db]" />
                                            <div className="whitespace-pre-line text-center text-[#6b7280]">
                                                {form.data.tpl_footer}
                                            </div>
                                        </div>
                                        <p className="mt-2 text-[11px] text-[#9ca3af]">
                                            {t('معاينة تقريبية — الشكل النهائي على ورق')} {form.data.paper}
                                        </p>
                                    </div>
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

                        {tab === 'shifts' && (
                            <>
                                <h3 className="mb-2 font-bold text-[#111]">{t('وردية الصندوق')}</h3>
                                <p className="mb-5 text-[13px] text-[#6b7280]">
                                    {t('الوردية تُحسب وتُقفل دائمًا. هذا المفتاح يقرّر هل تمنع البيع أيضًا.')}
                                </p>
                                <Toggle
                                    on={form.data.require_open_shift}
                                    onChange={(v) => form.setData('require_open_shift', v)}
                                    label="امنع البيع بلا وردية مفتوحة"
                                    hint="بتفعيله لا تُقبل بيعة قبل فتح الوردية — كاشير ينسى الفتح يوقف الصندوق."
                                />
                                <p className="mt-4 flex items-start gap-2 rounded-[12px] bg-[#fffbeb] px-3 py-2.5 text-[12px] text-[#b45309]">
                                    <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                                    <span>
                                        {t('فعّله بعد أن يعتاد موظفوك فتح الوردية كل صباح — لا قبل ذلك.')}
                                    </span>
                                </p>
                            </>
                        )}

                        {tab === 'loyalty' && (
                            <>
                                <h3 className="mb-4 font-bold text-[#111]">{t('نقاط الولاء')}</h3>
                                <Toggle
                                    on={form.data.loyalty_enabled}
                                    onChange={(v) => form.setData('loyalty_enabled', v)}
                                    label="تفعيل برنامج الولاء"
                                    hint="100 نقطة = وحدة عملة واحدة عند الاستبدال"
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
                </div>
            )}
        </AdminLayout>
    );
}
