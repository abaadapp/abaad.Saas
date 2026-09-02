import { useEffect, useState } from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    BellOff,
    BellRing,
    ChevronLeft,
    Download,
    Image as ImageIcon,
    Languages,
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
import BackToSettings from './partials/BackToSettings';
import BranchesPanel from './panels/BranchesPanel';
import EmployeesPanel, { type JobTitle } from './panels/EmployeesPanel';
import DevicesPanel, { type DevicesData } from './panels/DevicesPanel';
import ActivityPanel, { type ActivityData } from './panels/ActivityPanel';
import TrashPanel, { type TrashData } from './panels/TrashPanel';
import ChartPanel, { type ChartData } from './panels/ChartPanel';
import DomainPanel, { type DomainData } from './panels/DomainPanel';
import RecoveryEmailSection, { type Recovery } from './panels/RecoveryEmailSection';
import { useConfirm } from '@/Components/ConfirmDialog';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';
import type { Branch, Employee } from '@/types/models';

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
    business: { name: string; phone: string | null; email: string | null; address: string | null; logo: string | null };
    recovery: Recovery;
    /** نطاق موقع التاجر — مجموعة `website` في MarketingSettings */
    site: Record<string, string>;
    /** الطرق الثلاث إلى عنوانٍ على الإنترنت وتكلفةُ كلٍّ منها — انظر DomainOptions */
    domain: DomainData;
    notificationsAll: NotificationRow[];
    customAlerts: CustomAlertRow[];
    staffPermissions: { id: number; name: string; job_title: string; manual: boolean; count: number }[];
    alertMetrics: AlertMetric[];
    alertSections: Record<string, string>;
    locale: string;
    /* لا تصل إلا حين يُطلب قسمها في الرابط — انظر settingsSection في PageController */
    branches?: Branch[];
    employees?: Employee[];
    jobTitles?: JobTitle[];
    devices?: DevicesData['devices'];
    branchOptions?: DevicesData['branches'];
    peripheralTypes?: string[];
    drivableTypes?: string[];
    paperWidths?: number[];
    logs?: ActivityData['logs'];
    pagination?: ActivityData['pagination'];
    filters?: ActivityData['filters'];
    products?: TrashData['products'];
    trashedBranches?: TrashData['trashedBranches'];
    customers?: TrashData['customers'];
    expenses?: TrashData['expenses'];
    windowDays?: number;
    accounts?: ChartData['accounts'];
    trial?: ChartData['trial'];
    types?: ChartData['types'];
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
 * أقسام «النظام» — بياناتها تُحسب على الخادم، فتُطلب في الرابط
 * (?section=branches) لا في المرساة التي لا تصل إليه أصلًا.
 */
/** صفحةٌ واحدة فارغة — تُستعمل قبل وصول بيانات السجلّ لا كحالةٍ دائمة */
const EMPTY_PAGINATION: ActivityData['pagination'] = {
    current_page: 1,
    last_page: 1,
    from: 0,
    to: 0,
    total: 0,
    prev_page_url: null,
    next_page_url: null,
};

const SERVER_TABS = ['branches', 'employees', 'devices', 'activity', 'trash', 'chart'] as const;

/** مفاتيح كل الأقسام التي تُفتح داخل هذه الصفحة — وهي اليوم كلّها. */
const TAB_KEYS = NAV.flatMap((g) => g.items.map((i) => i.key)) as readonly TabKey[];

/**
 * القسم المطلوب من عنوان الصفحة.
 *
 * موضعان: `?section=branches` لأقسام النظام لأن الخادم يحتاج أن يقرأه،
 * و`#finance` لبقيّتها. وكلاهما يُفتح مباشرةً، فرابطٌ مثل «أضِف الرقم الضريبي
 * من الإعدادات» في صفحة الضريبة يُنزل المستخدم في قسم الضريبة لا في اللوحة.
 *
 * والمعامل يُقدَّم على المرساة: هو وحده يصل الخادم، فلو غلبته المرساة لعُرض
 * قسمٌ بلا البيانات التي يحتاجها.
 *
 * والقيمة الغريبة تعود إلى اللوحة (null) بدل أن تُظهر قسمًا فارغًا.
 */
function tabFromUrl(): TabKey | null {
    if (typeof window === 'undefined') return null;
    const url = new URL(window.location.href);
    const key = url.searchParams.get('section') || url.hash.replace(/^#/, '');
    return (TAB_KEYS as readonly string[]).includes(key) ? (key as TabKey) : null;
}

/** طرق الدفع المتاحة — المفاتيح تطابق ما كان يحفظه القالب السابق (pay_*) */
const PAYMENT_METHODS = [
    { key: 'pay_cash', label: 'نقدي', hint: 'الدفع النقدي عند الشراء' },
    { key: 'pay_card', label: 'بطاقة (فيزا)', hint: 'الدفع عبر بطاقات الصراف والائتمان' },
    { key: 'pay_transfer', label: 'تحويل بنكي', hint: 'التحويل المباشر للحساب البنكي' },
] as const;


/** صفحات مستقلة يصل إليها المستخدم من الإعدادات */

/** صفوف قوالب الفواتير — كلٌّ منها يُظهر شيئًا أو يُخفيه في الأوراق الثلاث */
const TEMPLATE_ROWS: { key: string; label: string; hint?: string }[] = [
    { key: 'tpl_show_logo', label: 'شعار المتجر', hint: 'يظهر فقط إن كان للنشاط شعار محفوظ' },
    { key: 'tpl_show_branch', label: 'اسم الفرع' },
    { key: 'tpl_show_employee', label: 'اسم الموظف' },
    /* الاستثناء يُقال لا يُسكت عنه: الفاتورة الضريبية تُعرّف مشتريها كي
       يخصم ما دفعه، فإخفاؤه يُبطل الغرض منها — والمقبض يعمل في الورقتين
       الأخريين. وصمتُ الشاشة عن ذلك كان يجعل التاجر يظنّ المقبض معطوبًا. */
    { key: 'tpl_show_customer', label: 'اسم العميل', hint: 'يبقى ظاهرًا في الفاتورة الضريبية دائمًا — بدونه لا يخصم المشتري ضريبته' },
    { key: 'tpl_show_datetime', label: 'التاريخ والوقت' },
    { key: 'tpl_show_items_count', label: 'عدد الأصناف' },
    { key: 'tpl_show_vat_no', label: 'الرقم الضريبي' },
    /* الوصف كان «إخفاؤه قد يخالف متطلبات الفوترة» — تخويفٌ من شيءٍ غير
       مؤكَّد: الرمز بصيغة ZATCA الخليجية، ولا نعلم أن جهاز الضرائب في عُمان
       يشترطها اليوم. والوصف يقول ما نعرفه ويترك القرار لصاحبه. */
    { key: 'tpl_show_qr', label: 'رمز الفوترة الإلكترونية (QR)', hint: 'بصيغة ZATCA الخليجية. لا يظهر بلا رقم ضريبي. راجع جهاز الضرائب قبل الاعتماد عليه' },
];

const NOTIF_COLORS: Record<string, string> = {
    danger: 'bg-[#fef2f2] text-[#dc2626]',
    warning: 'bg-[#fffbeb] text-[#d97706]',
    info: 'bg-[#eff6ff] text-[#2563eb]',
    success: 'bg-[#f0fdf4] text-[#16a34a]',
};

export default function SettingsIndex() {
    const { settings, business, recovery, site, domain, notificationsAll, customAlerts, alertMetrics, alertSections, staffPermissions, locale, branches, employees, jobTitles, devices, branchOptions, peripheralTypes, drivableTypes, paperWidths,
        logs, pagination, filters, products, expenses, customers: trashedCustomers, trashedBranches, windowDays,
        accounts, trial, types } =
        usePage<PageProps<Props>>().props;
    const { auth } = usePage<PageProps>().props;
    const t = useTranslate();
    // نافذةُ التأكيد من النظام لا من المتصفّح — انظر ConfirmDialog
    const [ask, confirmDialog] = useConfirm();
    const abilities = auth?.abilities ?? [];
    const visible = (item: { key: string }) => item.key !== 'chart' || abilities.includes('finance');

    const [tab, setTab] = useState<TabKey | null>(tabFromUrl);

    /*
     * المرساة تُقرأ عند كل تنقّل لا عند أوّل تركيبٍ وحده.
     *
     * الصفحة تُقرأ مرّةً ثم تبقى، فرابطٌ إلى ‎#business‎ من داخلها كان يغيّر
     * العنوان ولا يغيّر المعروض: لا تركيب جديد فلا قراءة جديدة. ومن ضغط
     * الزرّ وهو واقفٌ على الإعدادات لا يرى شيئًا يتحرّك.
     */
    /*
     * وما لا يُعرض لا يُفتح ولو كُتب في العنوان.
     *
     * إخفاء البطاقة يمنع النقر لا الكتابة: من يعرف `#chart` يكتبها. والخادم
     * لا يرسل بياناتها له، فتُرسم شجرةٌ فارغة تقول «لا حسابات» — وهي كذبة:
     * الحسابات موجودة وهو لا يملك رؤيتها.
     */
    useEffect(() => {
        if (tab === 'chart' && !abilities.includes('finance')) {
            goHub();
        }
    }, [tab, abilities]);

    useEffect(() => {
        const sync = () => setTab(tabFromUrl());
        window.addEventListener('hashchange', sync);
        const off = router.on('navigate', sync);

        return () => {
            window.removeEventListener('hashchange', sync);
            off();
        };
    }, []);
    const [pickedLocale, setPickedLocale] = useState(locale === 'en' ? 'en' : 'ar');
    const [backupFile, setBackupFile] = useState<File | null>(null);
    const [notifs, setNotifs] = useState<NotificationRow[]>(notificationsAll ?? []);

    const pick = (key: string) => {
        const tabKey = key as TabKey;

        /*
         * أقسام «النظام» بياناتها عند الخادم، فيُطلب القسم في الرابط.
         *
         * preserveState: النموذج المفتوح وحقوله لا تُمحى بزيارةٍ لجلب جدول.
         * وreplace: التنقّل بين الأقسام ليس تصفّحًا يستحقّ أن يمتلئ به زرّ
         * الرجوع — وهو ما تفعله المرساة في بقيّة الأقسام.
         */
        if ((SERVER_TABS as readonly string[]).includes(key)) {
            router.get(route('admin.settings.index'), { section: key }, {
                preserveState: true,
                preserveScroll: false,
                replace: true,
                onSuccess: () => setTab(tabKey),
            });

            return;
        }

        setTab(tabKey);
        /*
         * العنوان يتبع القسم المفتوح، فيبقى قابلًا للنسخ والمشاركة وإعادة
         * التحميل. replaceState لا pushState: التنقّل بين الأقسام ليس تصفّحًا
         * يستحقّ أن يمتلئ به زرّ الرجوع.
         *
         * و`?section=` يُمحى معه، ولا يُكتفى بتبديل المرساة.
         *
         * `tabFromUrl` يقدّم المعامل على المرساة — فمن فتح «شجرة الحسابات»
         * (‏?section=chart‎) ثمّ عاد إلى «بيانات النشاط» صار عنوانه
         * ‎?section=chart#business‎: يقرأ صحيحًا الآن، فإذا حفظ عاد الخادم
         * بـback() وقرأ المزامنُ المعاملَ فقفز به إلى الشجرة. يُحفظ ما كتب
         * ويجد نفسه في شاشةٍ أخرى، فيظنّ الحفظ ضاع.
         */
        window.history.replaceState(null, '', `${window.location.pathname}#${key}`);
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

    /*
     * بطاقةٌ لا تُعرض لمن لا يملك قسمها.
     *
     * «شجرة الحسابات» صلاحيتها «المالية»: تُفتح من هنا بمسار الإعدادات، فلا
     * يحرسها `CheckAbility` — يشتقّ القسم من اسم المسار وهو `settings`.
     * فيُحرس في الموضعين: الخادم لا يحسبها، والشاشة لا تعرض بابها. وإخفاء
     * الباب وحده تجميل، وحجبُ البيانات وحده يترك بطاقةً تفتح على فراغ.
     */
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

        pay_cash: on('pay_cash'),
        pay_card: on('pay_card'),
        pay_transfer: on('pay_transfer'),

        /*
         * حُذف من هنا ما كان يُحفظ ولا يقرؤه سطرٌ واحد: مربّعات الصلاحيات
         * التسعة (perm_*)، وشعارُ الفاتورة المكرَّر (invoice_show_logo وله
         * توأمٌ حيّ هو tpl_show_logo)، وعدد النسخ والطباعة التلقائية وطباعة
         * التجهيز — والطباعة التلقائية تعمل فعلًا لكن من «الأجهزة» لكلّ
         * طابعة على حدة — وبادئة الطلب وحالته الافتراضية وتعديله وتأكيد
         * إلغائه، والتوصيل الثلاثة. المقبض الذي لا يُمسك أسوأ من غيابه.
         */
        inv_prefix: get('inv_prefix', 'INV-'),
        inv_start: get('inv_start', '1'),

        paper: get('paper', '80mm'),

        notify_new_order: on('notify_new_order'),
        notify_smart_alerts: on('notify_smart_alerts'),
        notify_daily_summary: on('notify_daily_summary'),

        /*
         * لا نقاط ولا وردية هنا.
         *
         * الولاء أقسامُه حيّةٌ في التسويق ‹ برنامج ولاء بالمفاتيح نفسها ومعها
         * الأعضاء والنقاط — فبطاقتُه هنا كانت نسخةً ثانية لشيءٍ واحد، وتبديلُ
         * إحداهما يترك الأخرى تقول غير الواقع.
         *
         * ووردية الصندوق أُزيلت بطلب صاحب النظام، ومفتاحُها أُطفئ في ترحيلٍ
         * مرافق كي لا يبقى منعُ بيعٍ سارٍ بلا شاشةٍ تُطفئه.
         */

        /* قوالب الطباعة — الافتراضات هي شكل الإيصال قبل وجود هذا القسم
           حرفيًّا: تاجرٌ لم يفتحه قطّ يطبع اليوم ما كان يطبعه أمس. */
        tpl_header: get('tpl_header'),
        tpl_footer: get('tpl_footer', 'شكرًا لزيارتكم\nنتشرف بخدمتكم دائمًا'),
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

    /*
     * الموقع نموذجٌ ثانٍ لا حقولٌ في الأوّل.
     *
     * مفاتيحه تُخزَّن في مجموعة `website` عبر `MarketingSettings` لا في جدول
     * إعدادات المتجر، ومساره غير مسار الحفظ هنا. وجمعُهما في نموذجٍ واحد كان
     * يوجب على كل حفظِ اسمٍ أن يمرّ بمصادقة النطاق.
     *
     * وحقلٌ واحد بقي: السبعة الأخرى كانت تصف واجهة متجرٍ لا وجود لها.
     */
    const siteForm = useForm({
        site_domain: site?.site_domain ?? '',
    });

    const saveSite = (e: React.FormEvent) => {
        e.preventDefault();
        siteForm.post(route('admin.marketing.website.save'), { preserveScroll: true });
    };

    /*
    /*
     * الشعار مسارٌ آخر، فحالتُه هنا لا في `siteForm`.
     *
     * والمعاينة فوريّة من الملفّ المختار لا بعد الحفظ: من يرفع شعارًا يريد
     * أن يراه قبل أن يعتمده، ورفعُ الخطأ ثم اكتشافُه على فاتورةٍ مطبوعة
     * أسوأ من نقرةٍ زائدة.
     */
    const [logoFile, setLogoFile] = useState<File | null>(null);
    const [logoPreview, setLogoPreview] = useState<string | null>(null);
    const [logoBusy, setLogoBusy] = useState(false);

    const pickLogo = (file: File | null) => {
        setLogoFile(file);
        setLogoPreview(file ? URL.createObjectURL(file) : null);
    };

    const sendLogo = (payload: { logo: File } | { remove: true }) => {
        setLogoBusy(true);
        router.post(route('admin.settings.logo'), payload, {
            forceFormData: true,
            preserveScroll: true,
            onFinish: () => {
                setLogoBusy(false);
                pickLogo(null);
            },
        });
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
            />

            {tab === null ? (
                /* لوحة البطاقات — كل قسمٍ بطاقةٌ مستطيلة أفقية: أيقونته ثم
                   اسمه ووصفٌ خافتٌ تحته. النقر يفتح القسم مكان اللوحة.
                   ولا سقف عرضٍ هنا: السقف في AdminLayout يشمل الصفحات كلّها،
                   وسقفٌ ثانٍ فوقه كان يجعل الإعدادات أضيق من كل ما جاورها. */
                <div className="space-y-8">
                    {NAV.map((g) => (
                        <section key={g.group}>
                            <h3 className="mb-3 text-[13px] font-semibold text-[#6b7280]">{t(g.group)}</h3>
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                {g.items.filter(visible).map((x) => {
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
                                    // كل البطاقات تفتح قسمها هنا؛ لا واحدة تقفز بك إلى هيئةٍ أخرى
                                    return (
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
                /* قسمٌ مفتوح — بعرض اللوحة نفسها، فلا ينكمش المحتوى تحت اليد
                   لحظة الفتح. وطولُ السطر يُضبط بعدد الأعمدة في كل نموذج لا
                   بسقفٍ على الصفحة. min-w-0: يمنع جدولًا عريضًا من دفع
                   الحاوية فتتجاوز الشاشة. */
                <div className="min-w-0 scroll-mt-4">
                    {/* زرٌّ لا رابط: القسم يتبدّل هنا في مكانه بلا تنقّل */}
                    <BackToSettings as="button" onClick={goHub} />
            {tab === 'chart' ? (
                <ChartPanel accounts={accounts ?? []} trial={trial ?? { total_debit: 0, total_credit: 0, balanced: true }} types={types ?? []} />
            ) : tab === 'domain' ? (
                /*
                    الدومين وحده في بطاقة — وسؤالٌ قبل حقل.

                    كان حقلًا واحدًا: «اكتب النطاق». وهو يفترض في التاجر أنّه
                    يملك نطاقًا، وأنّه يعرف ما النطاق ومن أين يُشترى وبكم. ومن
                    لا يملك واحدًا — وهم أكثر من يفتح الشاشة أوّل مرّة — يقف
                    أمام حقلٍ فارغ لا يقول له ماذا يفعل، فيتركه ويبقى متجره
                    بلا عنوان.

                    ومحتواه في ملفٍّ وحده لأنّه صار ثلاثة مساراتٍ لا حقلًا:
                    اختيارٌ أوّل، ثمّ نطاقٌ يُربط أو اسمٌ يُحجز أو طلبٌ يُرسل.
                */
                <DomainPanel domain={domain} siteForm={siteForm} onSaveSite={saveSite} />
            ) : tab === 'website' ? (
                /*
                    الشعار وحده بقي هنا.

                    كان تحت هذا التبويب متجرٌ كامل يُضبط: جملةٌ تعريفية، ونبذة،
                    وواتساب وإنستغرام، و«عرض الأسعار» و«قبول الطلبات» — يملؤها
                    التاجر وتُحفظ كلّها ولا يقرؤها شيء، لأنّه لا واجهة متجرٍ في
                    النظام أصلًا. فكان يظنّ أنّه نشر متجرًا على الإنترنت وليس
                    هناك شيء، وينتظر طلبًا لا يأتي.

                    والحقل الذي لا يُقرأ ليس حقلًا زائدًا، هو وعدٌ مكذوب. فرُفعت
                    المقابض، وبقي الشعار لأنّه وحده يُقرأ فعلًا: تُظهره الفواتير
                    والإيصالات، وقوالبها تحمل مقبضًا يشترطه.

                    وما حُفظ منها باقٍ في القاعدة لم يُمحَ — إن بُنيت الواجهة
                    يومًا وجدَ ما كُتب مكانَه.
                */
                <Card className="overflow-hidden">
                    <div className="flex items-center gap-2 border-b border-[var(--ui-border,#e8e8e8)] px-5 py-4">
                        <ImageIcon className="size-4 shrink-0 text-[#6b7280]" />
                        <h3 className="font-bold text-[#111]">{t('الشعار')}</h3>
                    </div>
                    <div className="p-5">
                        <p className="mb-4 text-[13px] text-[#6b7280]">
                            {t('يظهر في الفواتير والإيصالات — وقوالب الفواتير تُظهره أو تُخفيه.')}
                        </p>

                        <div className="flex flex-wrap items-center gap-5">
                            <span className="flex size-24 shrink-0 items-center justify-center overflow-hidden rounded-[14px] border border-[var(--ui-border,#e8e8e8)] bg-[#fafafa]">
                                {logoPreview || business.logo ? (
                                    <img
                                        src={logoPreview ?? business.logo ?? ''}
                                        alt=""
                                        className="size-full object-contain"
                                    />
                                ) : (
                                    <ImageIcon className="size-8 text-[#d1d5db]" />
                                )}
                            </span>

                            <div className="min-w-0 flex-1">
                                <Input
                                    type="file"
                                    accept="image/png,image/jpeg,image/webp,image/svg+xml"
                                    onChange={(e) => pickLogo(e.target.files?.[0] ?? null)}
                                    className="h-auto py-2 file:me-3 file:rounded-lg file:bg-[#111] file:px-4 file:py-2 file:text-white"
                                />
                                <p className="mt-2 text-[12px] text-[#9ca3af]">
                                    {t('أفضل مقاس: 400×100 بكسل · PNG بخلفيّة شفّافة · حتّى ٢ ميغابايت')}
                                </p>

                                <div className="mt-3 flex flex-wrap gap-2">
                                    <Button
                                        type="button"
                                        size="sm"
                                        disabled={!logoFile}
                                        loading={logoBusy && !!logoFile}
                                        onClick={() => logoFile && sendLogo({ logo: logoFile })}
                                    >
                                        <Upload />
                                        {t('رفع الشعار')}
                                    </Button>
                                    {business.logo && (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            loading={logoBusy && !logoFile}
                                            onClick={() => sendLogo({ remove: true })}
                                        >
                                            <Trash2 />
                                            {t('حذف الشعار')}
                                        </Button>
                                    )}
                                </div>
                            </div>
                        </div>
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
                                    onClick={async () => {
                                        if (! await ask({ message: 'حذف جميع التنبيهات المرسلة؟', danger: true, action: 'حذف' })) return;
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
            ) : tab === 'branches' ? (
                /* بياناته تصل مع الرابط؛ وغيابها يعني فتحًا بلا `?section` */
                <BranchesPanel branches={branches ?? []} />
            ) : tab === 'employees' ? (
                <EmployeesPanel employees={employees ?? []} jobTitles={jobTitles ?? []} />
            ) : tab === 'devices' ? (
                <DevicesPanel
                    devices={devices ?? []}
                    branches={branchOptions ?? []}
                    peripheralTypes={peripheralTypes ?? []}
                    drivableTypes={drivableTypes ?? []}
                    paperWidths={paperWidths ?? []}
                />
            ) : tab === 'activity' ? (
                /* العنوان يُمرَّر: التصفية والتصفّح يعودان إلى الإعدادات لا
                   إلى الصفحة المستقلّة، وإلا قفز المستخدم عند أوّل «التالي» */
                <ActivityPanel
                    logs={logs ?? []}
                    pagination={pagination ?? EMPTY_PAGINATION}
                    filters={filters ?? {}}
                    endpoint={route('admin.settings.index')}
                    endpointParams={{ section: 'activity' }}
                />
            ) : tab === 'trash' ? (
                <TrashPanel
                    products={products ?? []}
                    expenses={expenses ?? []}
                    customers={trashedCustomers ?? []}
                    trashedBranches={trashedBranches ?? []}
                    windowDays={windowDays ?? 0}
                />
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
                                    <Field label="العنوان" className="sm:col-span-2" error={form.errors.address}>
                                        <Textarea rows={2} value={form.data.address} onChange={(e) => form.setData('address', e.target.value)} />
                                    </Field>
                                </div>

                                {/*
                                    بريد الاستعادة — من جنس ما فوقه: بيانٌ
                                    يُضبط مرّةً عند التجهيز. ومكانُه هنا لا في
                                    صفحةٍ جديدة، ولا يراه صاحبه إلا حيث يضبط
                                    اسم متجره وهاتفه.
                                */}
                                <RecoveryEmailSection recovery={recovery} />

                                {/*
                                    اللغة هنا لا في بطاقةٍ وحدها.

                                    مفتاحٌ واحد بين خيارين كان يشغل بطاقةً في اللوحة
                                    وصفحةً كاملة، وهو أوّل ما يُضبط مع اسم المتجر
                                    وعنوانه ثمّ لا يُفتح مرّةً أخرى.

                                    ويُحفظ بزرّه لا بزرّ النموذج: مساره آخر
                                    (`admin.language.update`)، واتجاه المستند يُحسم
                                    في قالب الجذر فتلزم إعادة تحميلٍ بعده — وجمعُهما
                                    في زرٍّ واحد يعيد تحميل الصفحة على كل حفظ اسم.
                                */}
                                <div className="mt-8 border-t border-[var(--ui-border,#e8e8e8)] pt-6">
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
                                    <div className="mt-4 flex justify-end">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            disabled={pickedLocale === locale}
                                            onClick={() =>
                                                router.post(
                                                    route('admin.language.update'),
                                                    { locale: pickedLocale },
                                                    { onSuccess: () => window.location.reload() },
                                                )
                                            }
                                        >
                                            <Languages />
                                            {t('حفظ اللغة')}
                                        </Button>
                                    </div>
                                </div>
                            </>
                        )}

                        {/*
                            المالية صفحةٌ واحدة لا ثلاث.

                            الضريبة والعملة وطرق الدفع كانت ثلاث بطاقاتٍ وثلاث
                            صفحات، وهي تُضبط في جلسةٍ واحدة عند تجهيز المتجر ثمّ
                            لا تُفتح إلا نادرًا. وثلاثُ نقراتٍ لثمانية حقول
                            تُفرّق ما يُقرأ معًا: نسبة الضريبة بلا خانات العملة
                            العشرية نصفُ الجواب.
                        */}
                        {tab === 'finance' && (
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

                                <div className="mt-8 border-t border-[var(--ui-border,#e8e8e8)] pt-6">
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
                                </div>

                                <div className="mt-8 border-t border-[var(--ui-border,#e8e8e8)] pt-6">
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
                                </div>
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

                        {tab === 'templates' && (
                            <>
                                {/*
                                    «قوالب الفواتير» لا «قالب الإيصال»: صار
                                    يحكم ثلاث أوراق لا واحدة — الإيصال الحراري،
                                    وفاتورة A4، والفاتورة الضريبية. ومن يقرأ
                                    «الإيصال» لا يخطر له أن فاتورته الضريبية
                                    تُضبط من هنا، فيطلبها تغييرًا في الكود.
                                */}
                                <h3 className="mb-1 font-bold text-[#111]">{t('قوالب الفواتير')}</h3>
                                <p className="mb-4 text-[12px] text-[#9ca3af]">
                                    {t('يحكم الإيصال المطبوع وفاتورة A4 والفاتورة الضريبية معًا — والمعاينة على اليمين تتبع كل تغيير.')}
                                </p>

                                <div className="grid grid-cols-1 gap-6 lg:grid-cols-[1fr_260px]">
                                    <div className="space-y-4">
                                        {/*
                                            الترقيم هنا لا في قسمٍ آخر.

                                            كان «الفواتير» بطاقةً حقلاها بادئة
                                            الرقم وأوّله، و«الطباعة» بطاقةً حقلها
                                            مقاس الورق — وكلاهما يُقرأ في المعاينة
                                            على اليمين: رقم الفاتورة يظهر فيها،
                                            والمقاس مكتوبٌ تحتها. فمن يضبطهما بعيدًا
                                            عنها يضبطهما بلا أن يرى أثرهما.
                                        */}
                                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                            <Field label="بادئة رقم الفاتورة" error={form.errors.inv_prefix}>
                                                <Input dir="ltr" value={form.data.inv_prefix} onChange={(e) => form.setData('inv_prefix', e.target.value)} />
                                            </Field>
                                            <Field
                                                label="رقم البداية"
                                                error={form.errors.inv_start}
                                                hint="يسري على أوّل فاتورةٍ بالبادئة الجديدة، ولا يمسّ ما صدر"
                                            >
                                                <Input type="number" min="1" dir="ltr" value={form.data.inv_start} onChange={(e) => form.setData('inv_start', e.target.value)} />
                                            </Field>
                                        </div>

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
                                                hint="الإيصال الحراري 80 أو 58 ملم، والفاتورة على A4"
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
                                    {/* تحت الترويسة المثبّتة لا خلفها: top-4 كانت
                                        تُوقف المعاينة عند 16px فتغطّيها الترويسة */}
                                    <div className="lg:sticky lg:top-[calc(var(--chrome-top,0px)+5rem)] lg:self-start">
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
                                        {/* كانت هذه الجملة في قسم «الطباعة» المستقلّ، فانتقلت
                                            معه: من يبحث عن الطباعة التلقائية يبحث هنا الآن */}
                                        <p className="mt-3 text-[11px] leading-relaxed text-[#9ca3af]">
                                            {t('الطباعة التلقائية بعد البيع تُضبط لكل طابعة من «الأجهزة» — لأن الصندوق الذي فيه طابعة يطبع، وغيره لا.')}
                                        </p>
                                    </div>
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

                        {saveBar}
                    </Card>
                </form>
            )}
                </div>
            )}

            {confirmDialog}
        </AdminLayout>
    );
}
