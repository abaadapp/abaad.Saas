import { useEffect, useMemo, useRef, useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { ChevronDown, Info, Paperclip, Plus, Save, Search, Send, Trash2, UserPlus } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import BackLink from '@/Components/BackLink';
import PageHeader from '@/Components/PageHeader';
import Field, { Select } from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import { Input, Textarea } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { money } from '@/lib/format';
import { fold } from '@/lib/pages';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';

interface CustomerRow {
    id: number;
    name: string;
    customer_type: string | null;
    legal_name: string | null;
    tax_number: string | null;
    commercial_registration: string | null;
    phone: string | null;
    contact_phone: string | null;
    email: string | null;
    contact_email: string | null;
    address: string | null;
    billing_address: string | null;
    department: string | null;
    customer_reference: string | null;
    contact_person: string | null;
    payment_terms_days: number | null;
    allow_credit_sales: boolean;
}

interface ProductRow {
    id: number;
    name: string;
    /** رمزُ الصنف وباركودُه — يُبحث بهما كما يُبحث بالاسم */
    sku: string | null;
    barcode: string | null;
    price: number;
}

/**
 * حسابٌ بنكيٌّ من «المالية» — والاسمُ محسوبٌ في الخادم لا مركَّبٌ هنا.
 *
 * `BankAccount::displayName` تعرف أيَّ حقلٍ تقرأ: ما سمّاه به التاجر، وإلّا
 * اسمَ البنك مع آخر أربعةٍ من الآيبان. وتركيبُه في الشاشة يعني قاعدتين
 * لتسميةٍ واحدة تفترقان يومًا.
 */
interface BankRow {
    id: number;
    name: string;
    is_primary: boolean;
}

/** ما يملكه من يكتب — والمقابضُ تُرسم عليه لا على الأمنيات */
interface May {
    issue: boolean;
    cancel: boolean;
    credit_note: boolean;
    pay: boolean;
}

interface Props {
    customers: CustomerRow[];
    products: ProductRow[];
    /** هل في الكتالوج ما لم يُرسَل؟ — انظر CATALOG_LIMIT في المتحكّم */
    catalog_truncated: boolean;
    tax_rate: number;
    today: string;
    may: May;
    methods: string[];
    /** حساباتُ المتجر النشطة — الرئيسيُّ أوّلها */
    bank_accounts: BankRow[];
    new_customer_id: number | null;
}

interface Line {
    product_id: string;
    description: string;
    quantity: string;
    unit_price: string;
    discount: string;
    tax_rate: string;
}

const NUMBER = (v: string) => {
    const n = Number(v);

    return Number.isFinite(n) ? n : 0;
};

/**
 * مددُ السداد المعروضة — و«تاريخ مخصص» آخرُها.
 *
 * وهي مصدرُ الخيارات ومصدرُ نصوصها معًا: قائمتان تفترقان يومًا، فيُضاف
 * أسبوعان إلى إحداهما ويُقرأ نصُّ الأخرى.
 */
const TERMS = [0, 7, 15, 30, 45, 60, 90] as const;

/** «تاريخ مخصص» ليس مدّة — قيمةٌ تقول إنّ الاستحقاق بيد كاتب الورقة */
const CUSTOM = 'custom';

/**
 * كم صفًّا يُرسَم في نافذة الكتالوج.
 *
 * والباقي لا يُبتر صامتًا: النافذةُ تقول كم طابق وكم عُرض. وقائمةٌ تُقصّ
 * بلا أن تقول تجعل من رأى آخرَ صفٍّ يظنّه آخرَ ما في المخزن.
 */
const SHOWN = 40;

/**
 * حقولُ الجهة — مصدرٌ واحدٌ للمفتاح والتسمية والمثال.
 *
 * والتسمياتُ مطوّلةٌ عمدًا: «المرجع» وحدَه لا يقول مرجعَ ماذا، و«عناية» بلا
 * «موجه إلى» تُقرأ تحذيرًا. ومن يملأ ورقةً لجهةٍ حكوميّة يقرأ الاسمَ مرّةً
 * واحدة — فليقُل ما يعنيه من أوّل قراءة.
 */
const ORG_FIELDS = [
    ['contract_number', 'رقم العقد', 'CNT-2026-08'],
    ['external_reference', 'رقم المرجع', 'REF-8842'],
    ['attention_to', 'موجه إلى / عناية', ''],
] as const;

/**
 * خياراتُ شروط الدفع — والجمعُ يتبع العدد.
 *
 * «7 يوم» عربيّةٌ مكسورة يقرؤها صاحبُ المتجر كلَّ مرّة، و«15 أيام» مثلها.
 * فالثلاثةُ إلى العشرة جمعُ قلّة، وما فوقها مفردٌ منصوب.
 */
function termOptions(t: (s: string) => string) {
    return [
        { label: 'مستحق فورًا', value: '0' },
        ...TERMS.filter((d) => d > 0).map((d) => ({
            label: `${d} ${d <= 10 ? t('أيام') : t('يومًا')}`,
            value: String(d),
        })),
        { label: 'تاريخ مخصص', value: CUSTOM },
    ];
}

/**
 * إنشاءُ فاتورةِ عميل.
 *
 * وصفحةٌ لا لوحةٌ فوق الجدول: ورقةُ شركةٍ فيها بياناتُ جهةٍ وأمرُ شراءٍ وبنودٌ
 * لكلٍّ ضريبتُه، وما لا يسع الشاشةَ يُطوى خلف زرٍّ فلا يُملأ أبدًا.
 *
 * والحسابُ هنا معاينةٌ لا مصدر: الخادمُ يعيد حسابَ كلّ رقمٍ من البنود — من
 * يفتح أدوات المتصفّح يستطيع إرسال إجماليٍّ صفرٍ لفاتورةٍ بمئة.
 */
export default function CustomerInvoiceCreate({
    customers,
    products,
    catalog_truncated,
    tax_rate,
    today,
    may,
    bank_accounts,
    new_customer_id,
}: Props) {
    const { context } = usePage<PageProps>().props;
    const t = useTranslate();
    const m = (v: number) => money(v, context!.currency);

    const [lines, setLines] = useState<Line[]>([blank(tax_rate)]);
    const [extra, setExtra] = useState(false);
    /*
     * وتاريخُ الاستحقاق له حالان: محسوبٌ من المدّة، أو مكتوبٌ بيد.
     *
     * وبلا التفريق بينهما يقع أحدُ عطبين: إمّا أن يُعاد الحسابُ فوق ما كتبه
     * التاجر — فيكتب ٥ أكتوبر ويجده ٧ لأنّه غيّر تاريخ الفاتورة بعدها —
     * وإمّا أن يُترك المحسوبُ قديمًا فتُصدَر ورقةٌ استحقاقُها قبل تاريخها.
     */
    const [dueManual, setDueManual] = useState(false);
    const dueRef = useRef<HTMLInputElement>(null);
    const [picking, setPicking] = useState(false);
    const [adding, setAdding] = useState(false);
    /* والملاحظاتُ الداخليّة مطفأةٌ حتى تُطلَب — وإطفاؤها يمحوها، انظر المُبدِّل */
    const [internal, setInternal] = useState(false);

    const form = useForm({
        customer_id: new_customer_id ? String(new_customer_id) : '',
        issued_at: today,
        due_at: addDays(today, 30),
        payment_terms_days: '30',
        po_number: '',
        contract_number: '',
        external_reference: '',
        department: '',
        cost_center: '',
        attention_to: '',
        notes: '',
        internal_notes: '',
        // المرفقاتُ في النموذج لا بجواره: فيصل خطؤها مكتوبًا باسمها
        attachments: [] as File[],
        payment_method: 'آجل',
        // والرئيسيُّ أوّلُ القائمة — فما تراه الشاشةُ هو ما يُخزَّن
        bank_account_id: bank_accounts.length > 0 ? String(bank_accounts[0].id) : '',
        issue: false,
        items: [] as Line[],
    });

    /*
     * وعميلٌ أُضيف من النافذة يُختار وحدَه.
     *
     * الصفحةُ تُعاد بعد الحفظ ومعها القائمةُ الجديدة، وحالةُ النموذج محفوظة
     * (`preserveState`) — فلا يبقى إلّا أن يُقال أيُّهم. ومن لا يُختار له
     * يعود فيبحث عن اسمٍ كتبه قبل ثانية.
     */
    useEffect(() => {
        if (new_customer_id) {
            form.setData('customer_id', String(new_customer_id));
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [new_customer_id]);

    const customer = customers.find((c) => String(c.id) === form.data.customer_id) ?? null;

    const setLine = (i: number, key: keyof Line, value: string) =>
        setLines((prev) => prev.map((l, idx) => (idx === i ? { ...l, [key]: value } : l)));

    const totals = useMemo(() => {
        let subtotal = 0;
        let discount = 0;
        let tax = 0;

        for (const l of lines) {
            const gross = NUMBER(l.quantity) * NUMBER(l.unit_price);
            const off = NUMBER(l.discount);
            const net = Math.max(0, gross - off);
            subtotal += gross;
            discount += off;
            tax += (net * NUMBER(l.tax_rate)) / 100;
        }

        return { subtotal, discount, tax, total: subtotal - discount + tax };
    }, [lines]);

    /* ومدّةُ السداد تُحرّك تاريخ الاستحقاق — والتاريخُ يبقى قابلًا للكتابة */
    const setTerms = (value: string) => {
        if (value === CUSTOM) {
            setDueManual(true);
            form.setData('payment_terms_days', CUSTOM);
            // ومن اختار «مخصص» يقصد الكتابة — فالمؤشّر يقع حيث يكتب
            window.setTimeout(() => dueRef.current?.focus(), 0);

            return;
        }

        setDueManual(false);
        form.setData((d) => ({
            ...d,
            payment_terms_days: value,
            due_at: addDays(d.issued_at || today, NUMBER(value)),
        }));
    };

    /* وتغييرُ تاريخ الفاتورة يُزحزح المحسوبَ وحدَه — ولا يمسّ المكتوبَ بيد */
    const setIssued = (value: string) => {
        form.setData((d) => ({
            ...d,
            issued_at: value,
            due_at:
                dueManual || d.payment_terms_days === CUSTOM
                    ? d.due_at
                    : addDays(value || today, NUMBER(d.payment_terms_days)),
        }));
    };

    /* ومن كتب الاستحقاق بيده ملكه — وتصير المدّةُ «مخصصًا» فلا تكذب الشاشة */
    const setDue = (value: string) => {
        setDueManual(true);
        form.setData((d) => ({ ...d, due_at: value, payment_terms_days: CUSTOM }));
    };

    /*
     * واختيارُ العميل يجرّ شروطَ سداده — ولا يجرّ قسمَه.
     *
     * الخادمُ يسقط إلى قسم العميل حين يُترك الحقلُ فارغًا؛ ونسخُه في الشاشة
     * أيضًا موضعان يقرّران الشيء نفسه، وأوّلُ تغييرٍ في أحدهما يجعل الورقة
     * تحمل قسمًا لم يُقصد.
     */
    const pickCustomer = (id: string) => {
        const picked = customers.find((c) => String(c.id) === id);

        form.setData((d) => ({
            ...d,
            customer_id: id,
            payment_terms_days:
                picked?.payment_terms_days != null && !dueManual
                    ? String(picked.payment_terms_days)
                    : d.payment_terms_days,
            due_at:
                picked?.payment_terms_days != null && !dueManual
                    ? addDays(d.issued_at || today, picked.payment_terms_days)
                    : d.due_at,
        }));
    };

    const clearCustomer = () => form.setData('customer_id', '');

    /* والحسابُ يُسأل عنه حين يدخل المالُ بنكًا — لا مع النقد ولا مع الآجل */
    const needsAccount = form.data.payment_method === 'بطاقة' || form.data.payment_method === 'تحويل';

    /*
     * والملفّاتُ خارج `useForm`: تُضاف عند الإرسال.
     *
     * `transform` تحقنها في الحمولة، و`forceFormData` يجعلها ترحل كنموذجٍ
     * متعدّد الأجزاء — ولا تُجبَر إلّا حين توجد، فطلبٌ بلا ملفّاتٍ يبقى JSON
     * كما كان.
     */
    const files = form.data.attachments;

    const addFiles = (list: FileList | null) => {
        if (! list) return;
        form.setData('attachments', [...files, ...Array.from(list)].slice(0, 6));
    };

    const submit = (issue: boolean) => {
        // و«مخصص» لا يُرسَل مدّةً: الخادمُ يخزّن عددَ أيّامٍ أو لا شيء
        form.transform((data) => ({
            ...data,
            issue,
            items: lines,
            payment_terms_days: data.payment_terms_days === CUSTOM ? '' : data.payment_terms_days,
            /*
             * وحسابٌ اختير ثمّ بُدِّلت الوسيلةُ لا يُرسَل.
             *
             * الشاشةُ تُخفي القائمة، والحقلُ يبقى في النموذج — فيصل مع «نقدي»
             * حسابُ بنكٍ لم يمرّ به المال. والخادمُ يُسقطه أيضًا
             * (`CustomerPayments::accountFor`)، وحارسان لا يضرّان.
             */
            bank_account_id: needsAccount ? data.bank_account_id : '',
        }));
        form.post('/admin/customer-invoices', { preserveScroll: true, forceFormData: files.length > 0 });
    };

    const credit = form.data.payment_method === 'آجل';

    return (
        <AdminLayout title={t('إنشاء فاتورة')}>
            <BackLink
                routeName="admin.customerInvoices.index"
                href={route('admin.customerInvoices.index')}
                label="فواتير العملاء"
            />

            <PageHeader
                title="إنشاء فاتورة عميل جديدة"
                subtitle={t('إصدار فاتورة لعميل مقابل منتجات أو خدمات.')}
                actions={
                    <span className="rounded-full bg-[#eff6ff] px-3 py-1 text-[12px] font-medium text-[#1d4ed8]">
                        {t('مسودة')}
                    </span>
                }
            />

            {/*
                وعمودان: الورقةُ في الأوسع، وما يُقرأ عنها في الأضيق.

                الملخّصُ وطريقةُ السداد والمرفقات تُقرأ وتُراجَع أثناء الكتابة
                لا بعدها — فتبقى في العين بينما تُملأ البنود، ولا تنزل تحت طيّة
                الشاشة كما كانت.
            */}
            <div className="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1fr)_320px]">
                <div className="min-w-0 space-y-4">
                    {/* ───────── معلومات الفاتورة ───────── */}
                    <Card className="p-5">
                        <h2 className="mb-4 text-[15px] font-bold text-[#111]">{t('معلومات الفاتورة')}</h2>

                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            {/* ── العميل ── */}
                            <div className="space-y-1.5">
                                <Label htmlFor="customer" required>
                                    {t('العميل')}
                                </Label>
                                {customer ? (
                                    /*
                                        والمختارُ يُعرض اسمًا لا حقلَ بحثٍ فارغًا.

                                        و«تغيير» واحدةٌ لا اثنتان: هي في بطاقة
                                        العميل أسفلُ — ومقبضان يفعلان الشيء
                                        نفسه يجعلان أحدهما يُنسى فيُترك معطوبًا.
                                    */
                                    <div className="flex h-10 items-center rounded-[10px] border border-[var(--ui-border,#e8e8e8)] bg-[#fafafa] px-3">
                                        <span className="truncate text-[13px] font-medium text-[#111]">
                                            {customer.name}
                                        </span>
                                    </div>
                                ) : (
                                    <CustomerPicker
                                        customers={customers}
                                        value={form.data.customer_id}
                                        onChange={pickCustomer}
                                    />
                                )}
                                {form.errors.customer_id ? (
                                    <Err msg={form.errors.customer_id} />
                                ) : (
                                    <button
                                        type="button"
                                        className="flex items-center gap-1 text-[12px] font-medium text-[#6d28d9] hover:underline"
                                        onClick={() => setAdding(true)}
                                    >
                                        <UserPlus className="size-3.5" />
                                        {t('إضافة عميل جديد')}
                                    </button>
                                )}
                            </div>

                            {/* ── تاريخ الإصدار ── */}
                            <div className="space-y-1.5">
                                <Label htmlFor="issued-at" required>
                                    {t('تاريخ الإصدار')}
                                </Label>
                                <Input
                                    id="issued-at"
                                    type="date"
                                    value={form.data.issued_at}
                                    onChange={(e) => setIssued(e.target.value)}
                                />
                                {form.errors.issued_at && <Err msg={form.errors.issued_at} />}
                            </div>

                            {/*
                                ── رقم الفاتورة ──

                                ولا يُعرض قبل الإصدار لأنّه لا يوجد.

                                كان يُعرض «الرقم التالي» فيكتبه التاجر على أمر
                                شراء عميله قبل أن يُصدر، ثمّ يهجر المسودّة أو
                                يسبقه غيرُه إلى الرقم — فتصل الورقةُ برقمٍ غير
                                الذي وعد به. والرقمُ يُقطع عند الإصدار وحده:
                                `CustomerInvoices::issue`.

                                وصندوقٌ منقّطٌ لا قائمةٌ معطّلة: ما لا يُدار لا
                                يُرسم مقبضًا.
                            */}
                            <div className="space-y-1.5">
                                <Label htmlFor="invoice-number">
                                    <span className="flex items-center gap-1.5">
                                        {t('رقم الفاتورة')}
                                        <Info className="size-3.5 text-[#9ca3af]" />
                                    </span>
                                </Label>
                                <div
                                    id="invoice-number"
                                    className="flex h-10 items-center rounded-[10px] border border-dashed border-[var(--ui-border,#e8e8e8)] bg-[#fafafa] px-3 text-[13px] text-[#9ca3af]"
                                >
                                    {t('سيتم إنشاء رقم الفاتورة عند الإصدار')}
                                </div>
                            </div>

                            {/* ── تاريخ الاستحقاق ── */}
                            <div className="space-y-1.5">
                                <Label htmlFor="due-at" required>
                                    {t('تاريخ الاستحقاق')}
                                </Label>
                                <Input
                                    id="due-at"
                                    ref={dueRef}
                                    type="date"
                                    value={form.data.due_at}
                                    onChange={(e) => setDue(e.target.value)}
                                />
                                {form.errors.due_at ? (
                                    <Err msg={form.errors.due_at} />
                                ) : (
                                    <p className="text-[12px] text-[#9ca3af]">
                                        {dueManual || form.data.payment_terms_days === CUSTOM
                                            ? t('مكتوب يدويًّا')
                                            : t('محسوب من شروط الدفع')}
                                    </p>
                                )}
                            </div>

                            {/* ── شروط الدفع ── */}
                            <div className="space-y-1.5">
                                <Label htmlFor="payment-terms">{t('شروط الدفع')}</Label>
                                <Select
                                    id="payment-terms"
                                    value={form.data.payment_terms_days}
                                    onChange={(e) => setTerms(e.target.value)}
                                    options={termOptions(t)}
                                />
                            </div>

                            {/*
                                ── مرجع العميل ──

                                وهو عمود `po_number` باسمه الذي يفهمه من يملأ
                                الورقة: الجهةُ تكتب على طلبها رقمًا وتطلب أن
                                يعود إليها في الفاتورة. والعقدُ والمرجعُ
                                الخارجيّ عمودان آخران — تحت «معلومات إضافية»،
                                ولا يُدمجان في واحد.
                            */}
                            <div className="space-y-1.5">
                                <Label htmlFor="org-po_number">{t('مرجع العميل (أمر الشراء)')}</Label>
                                <Input
                                    id="org-po_number"
                                    value={form.data.po_number}
                                    onChange={(e) => form.setData('po_number', e.target.value)}
                                    placeholder="PO-2026-154"
                                />
                                {form.errors.po_number && <Err msg={form.errors.po_number} />}
                            </div>

                            {/* ── مركز التكلفة ── */}
                            <div className="space-y-1.5">
                                <Label htmlFor="org-cost_center">{t('مركز التكلفة')}</Label>
                                <Input
                                    id="org-cost_center"
                                    value={form.data.cost_center}
                                    onChange={(e) => form.setData('cost_center', e.target.value)}
                                    placeholder="CC-104"
                                />
                                {form.errors.cost_center && <Err msg={form.errors.cost_center} />}
                            </div>

                            {/* ── القسم ── */}
                            <div className="space-y-1.5">
                                <Label htmlFor="org-department">{t('القسم / الإدارة')}</Label>
                                <Input
                                    id="org-department"
                                    value={form.data.department}
                                    onChange={(e) => form.setData('department', e.target.value)}
                                    placeholder={customer?.department ?? ''}
                                />
                                {form.errors.department ? (
                                    <Err msg={form.errors.department} />
                                ) : (
                                    customer?.department && (
                                        <p className="text-[12px] text-[#9ca3af]">
                                            {t('يسقط إلى قسم العميل إن تُرك فارغًا.')}
                                        </p>
                                    )
                                )}
                            </div>

                            {/*
                                ── العملة ──

                                نصٌّ لا قائمة: الفاتورةُ تُكتب بعملة المتجر،
                                ولا مسارَ في النظام يُصدرها بغيرها. وقائمةٌ
                                بسهمٍ لا تُفتح تَعِد بما لا تفعل.
                            */}
                            <div className="space-y-1.5">
                                <Label htmlFor="currency">{t('العملة')}</Label>
                                <div
                                    id="currency"
                                    className="flex h-10 items-center rounded-[10px] border border-[var(--ui-border,#e8e8e8)] bg-[#fafafa] px-3 text-[13px] text-[#4b4b4b]"
                                >
                                    {context!.currency.symbol} — {t(context!.currency.name)}
                                </div>
                            </div>
                        </div>

                        {customer && <CustomerCard customer={customer} onClear={clearCustomer} />}
                    </Card>

                    {/*
                        وبقيّةُ بيانات الجهة مطويّة، وخفيفةٌ في النظر.

                        ثلاثةٌ صعدت إلى الشبكة أعلاه لأنّها تُملأ في أكثر فواتير
                        الجهات، وثلاثةٌ بقيت هنا لأنّ نصف الفواتير لأفرادٍ لا
                        عقدَ لهم ولا «عناية» — وستّةُ حقولٍ مفتوحةٍ دائمًا تدفع
                        البنودَ، وهي لبُّ الورقة، تحت طيّة الشاشة.
                    */}
                    <Card className="border-dashed p-5">
                        <button
                            type="button"
                            id="org-toggle"
                            aria-expanded={extra}
                            aria-controls="org-fields"
                            className="flex w-full items-center justify-between gap-3 rounded-[8px] text-start focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#d1d5db]"
                            onClick={() => setExtra((v) => !v)}
                        >
                            <span>
                                <span className="flex items-center gap-2">
                                    <span className="text-[14px] font-semibold text-[#4b4b4b]">
                                        {t('معلومات إضافية للجهة')}
                                    </span>
                                    <span className="rounded-full bg-[#f2f2f0] px-2 py-0.5 text-[11px] text-[#71717a]">
                                        {t('اختياري')}
                                    </span>
                                </span>
                                <span className="mt-1 block text-[12px] leading-relaxed text-[#9ca3af]">
                                    {t('بيانات اختيارية للشركات والجهات الحكومية، وتظهر في الفاتورة عند تعبئتها.')}
                                </span>
                            </span>
                            <ChevronDown
                                className={cn('size-4 shrink-0 text-[#6b7280] transition-transform', extra && 'rotate-180')}
                            />
                        </button>

                        <div id="org-fields" role="region" aria-labelledby="org-toggle" hidden={!extra}>
                            <div className="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
                                {ORG_FIELDS.map(([key, label, placeholder]) => (
                                    <div key={key} className="space-y-1.5">
                                        <Label htmlFor={`org-${key}`}>{t(label)}</Label>
                                        <Input
                                            id={`org-${key}`}
                                            value={form.data[key]}
                                            onChange={(e) => form.setData(key, e.target.value)}
                                            placeholder={placeholder}
                                        />
                                        {form.errors[key] && <Err msg={form.errors[key] as string} />}
                                    </div>
                                ))}
                            </div>
                        </div>
                    </Card>

                    {/* ───────── الأصناف ───────── */}
                    <Card className="p-5">
                        <div className="mb-4 flex flex-wrap items-center gap-2">
                            <h2 className="me-auto text-[15px] font-bold text-[#111]">{t('الأصناف')}</h2>
                            <Button type="button" variant="outline" size="sm" onClick={() => setPicking(true)}>
                                <Search />
                                {t('منتج من الكتالوج')}
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                onClick={() => setLines((p) => [...p, blank(tax_rate)])}
                            >
                                <Plus />
                                {t('إضافة صنف')}
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                className="text-[#b91c1c]"
                                title={t('تفريغ كل البنود')}
                                onClick={() => setLines([blank(tax_rate)])}
                            >
                                <Trash2 />
                            </Button>
                        </div>

                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[820px] text-[13px]">
                                <thead className="text-[12px] text-[#71717a]">
                                    <tr>
                                        <th className="p-2 text-start font-medium">#</th>
                                        <th className="p-2 text-start font-medium">{t('الوصف')}</th>
                                        <th className="w-[100px] p-2 text-start font-medium">{t('الكمية')}</th>
                                        <th className="w-[130px] p-2 text-start font-medium">
                                            {t('سعر الوحدة')} ({context!.currency.symbol})
                                        </th>
                                        {/*
                                            والخصمُ مبلغٌ لا نسبة — هكذا يُخزَّن
                                            ويُحسب في `CustomerInvoices::compute`.
                                            وعنوانٌ يقول «%» يجعل من يكتب ١٠
                                            قاصدًا عُشرَ الثمن يخصم عشرة ريالات.
                                        */}
                                        <th className="w-[120px] p-2 text-start font-medium">
                                            {t('الخصم')} ({context!.currency.symbol})
                                        </th>
                                        <th className="w-[120px] p-2 text-start font-medium">{t('الضريبة (%)')}</th>
                                        <th className="w-[110px] p-2 text-start font-medium">
                                            {t('الإجمالي')} ({context!.currency.symbol})
                                        </th>
                                        <th className="w-[52px] p-2 text-start font-medium">{t('إجراءات')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {lines.map((line, i) => {
                                        const net = Math.max(
                                            0,
                                            NUMBER(line.quantity) * NUMBER(line.unit_price) - NUMBER(line.discount),
                                        );

                                        return (
                                            <tr key={i} className="border-t border-[var(--ui-border,#e8e8e8)]">
                                                <td className="p-2 text-[#9ca3af]">{i + 1}</td>
                                                <td className="p-2">
                                                    <Input
                                                        value={line.description}
                                                        onChange={(e) => setLine(i, 'description', e.target.value)}
                                                        placeholder={t('الوصف')}
                                                    />
                                                </td>
                                                <td className="p-2">
                                                    <Input
                                                        value={line.quantity}
                                                        onChange={(e) => setLine(i, 'quantity', e.target.value)}
                                                    />
                                                </td>
                                                <td className="p-2">
                                                    <Input
                                                        value={line.unit_price}
                                                        onChange={(e) => setLine(i, 'unit_price', e.target.value)}
                                                    />
                                                </td>
                                                <td className="p-2">
                                                    <Input
                                                        value={line.discount}
                                                        onChange={(e) => setLine(i, 'discount', e.target.value)}
                                                    />
                                                </td>
                                                <td className="p-2">
                                                    <Select
                                                        value={line.tax_rate}
                                                        onChange={(e) => setLine(i, 'tax_rate', e.target.value)}
                                                        options={taxOptions(tax_rate)}
                                                    />
                                                </td>
                                                <td className="p-2 font-semibold tabular-nums">
                                                    {(net + (net * NUMBER(line.tax_rate)) / 100).toFixed(3)}
                                                </td>
                                                <td className="p-2">
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        size="sm"
                                                        className="text-[#b91c1c]"
                                                        title={t('حذف البند')}
                                                        onClick={() =>
                                                            setLines((p) =>
                                                                p.length > 1 ? p.filter((_, idx) => idx !== i) : p,
                                                            )
                                                        }
                                                    >
                                                        <Trash2 />
                                                    </Button>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>

                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            className="mt-3"
                            onClick={() => setLines((p) => [...p, blank(tax_rate)])}
                        >
                            <Plus />
                            {t('إضافة بند')}
                        </Button>

                        {form.errors.items && <Err msg={form.errors.items} />}
                    </Card>

                    {/*
                        ───────── الملاحظتان ─────────

                        وملاحظتان لا واحدة.

                        كان الحقل واحدًا وهو يُطبع على الفاتورة. فمن أراد أن
                        يكتب لنفسه «العميل يماطل، لا تُسلَّم قبل الدفع» كتبها
                        حيث يقرؤها العميلُ في الورقة التي تصله.
                    */}
                    <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                        <Card className="p-5">
                            <h2 className="mb-1 text-[15px] font-bold text-[#111]">{t('ملاحظة للعميل')}</h2>
                            <p className="mb-3 text-[12px] text-[#9ca3af]">{t('تظهر في الفاتورة المطبوعة.')}</p>
                            <Textarea
                                value={form.data.notes}
                                maxLength={500}
                                onChange={(e) => form.setData('notes', e.target.value)}
                                placeholder={t('ستظهر هذه الملاحظة في الفاتورة…')}
                                className="min-h-24"
                            />
                            <p className="mt-1 text-[12px] text-[#9ca3af]" dir="ltr">
                                {form.data.notes.length}/500
                            </p>
                        </Card>

                        <Card className="p-5">
                            <div className="mb-3 flex items-start justify-between gap-3">
                                <div>
                                    <h2 className="text-[15px] font-bold text-[#111]">{t('ملاحظات إضافية')}</h2>
                                    <p className="mt-1 text-[12px] text-[#9ca3af]">
                                        {t('لا تظهر للعميل ولا تُطبع.')}
                                    </p>
                                </div>

                                {/*
                                    والمُبدِّلُ يمحو ما يُخفيه.

                                    حقلٌ مخفيٌّ يبقى في النموذج يُرسَل مع الطلب —
                                    فمن كتب ملاحظةً ثمّ أطفأ المفتاح ظنَّ أنّه
                                    محاها وهي محفوظةٌ في الورقة.
                                */}
                                <label className="flex shrink-0 cursor-pointer items-center gap-2 text-[12px] text-[#4b4b4b]">
                                    <input
                                        type="checkbox"
                                        className="size-4 accent-[#6d28d9]"
                                        checked={internal}
                                        onChange={(e) => {
                                            setInternal(e.target.checked);
                                            if (! e.target.checked) {
                                                form.setData('internal_notes', '');
                                            }
                                        }}
                                    />
                                    {t('إضافة ملاحظات داخلية')}
                                </label>
                            </div>

                            {internal ? (
                                <>
                                    <Textarea
                                        value={form.data.internal_notes}
                                        maxLength={500}
                                        onChange={(e) => form.setData('internal_notes', e.target.value)}
                                        placeholder={t('لفريقك وحده…')}
                                        className="min-h-24"
                                    />
                                    <p className="mt-1 text-[12px] text-[#9ca3af]" dir="ltr">
                                        {form.data.internal_notes.length}/500
                                    </p>
                                </>
                            ) : (
                                <p className="rounded-[10px] border border-dashed border-[var(--ui-border,#e8e8e8)] bg-[#fafafa] p-4 text-[12px] leading-relaxed text-[#9ca3af]">
                                    {t('شغّل المفتاح لكتابة ملاحظةٍ لفريقك وحده — لا تصل العميل ولا تُطبع على فاتورته.')}
                                </p>
                            )}
                        </Card>
                    </div>

                    {/* ───────── الأزرار ───────── */}
                    <div className="flex flex-wrap items-center justify-end gap-2">
                        {/*
                            ومن لا يملك الإصدار يُقال له — ولا يُرسم له زرٌّ
                            يُردّ عنه. والمسودّةُ تبقى له: هي عملُه، ولا تُنشئ
                            ذمّةً ولا تكتب قيدًا. فيكتبها ويتركها لمن يُصدر.
                        */}
                        {! may.issue && (
                            <p className="me-auto text-[12px] text-[#9ca3af]">
                                {t('الإصدار صلاحيةٌ لا تملكها — احفظها مسودّةً ليُصدرها من يملكها.')}
                            </p>
                        )}

                        <Button
                            type="button"
                            variant="outline"
                            disabled={form.processing}
                            onClick={() => router.visit(route('admin.customerInvoices.index'))}
                        >
                            {t('إلغاء')}
                        </Button>

                        {/*
                            و«حفظ كمسودة» يختفي مع طريقةِ سدادٍ مقبوضة — لا
                            يُعرض بابٌ يردّ الخادمُ من خلفه. والحارسُ في الخادم
                            على أيّ حال.
                        */}
                        {(credit || ! may.issue) && (
                            <Button variant="outline" disabled={form.processing} onClick={() => submit(false)}>
                                <Save />
                                {t('حفظ كمسودة')}
                            </Button>
                        )}
                        {may.issue && (
                            <Button disabled={form.processing} onClick={() => submit(true)}>
                                <Send />
                                {t('إصدار الفاتورة')}
                            </Button>
                        )}
                    </div>
                </div>

                {/* ───────── العمود الجانبي ───────── */}
                <div className="space-y-4 xl:sticky xl:top-4 xl:self-start">
                    <Card className="p-5">
                        <h2 className="mb-4 text-[15px] font-bold text-[#111]">{t('ملخص الفاتورة')}</h2>

                        <dl className="space-y-2 text-[13px]">
                            <Row label={t('قيمة الأصناف')} value={m(totals.subtotal)} />
                            {/*
                                و«الخصم» لا «خصم عام»: هو مجموعُ خصومات البنود،
                                ولا خصمَ على مستوى الفاتورة في النظام. واسمٌ
                                يَعِد بحقلٍ لا وجود له يجعل من يبحث عنه يظنّ
                                الشاشةَ ناقصة.
                            */}
                            <Row label={t('الخصم')} value={m(totals.discount)} />
                            <Row label={t('الضريبة')} value={m(totals.tax)} />
                        </dl>

                        <div className="mt-3 flex items-center justify-between rounded-[10px] bg-[#f5f3ff] px-3 py-2.5">
                            <span className="text-[14px] font-bold text-[#111]">{t('الإجمالي')}</span>
                            <span className="text-[16px] font-bold tabular-nums text-[#6d28d9]">{m(totals.total)}</span>
                        </div>
                    </Card>

                    {/* ───────── طريقة السداد ───────── */}
                    <Card className="p-5">
                        <h2 className="mb-4 text-[15px] font-bold text-[#111]">{t('طريقة السداد')}</h2>

                        <div className="space-y-2.5">
                            {(
                                [
                                    ['آجل', 'آجل'],
                                    ['نقدي', 'مدفوع نقدًا'],
                                    ['بطاقة', 'مدفوع بالبطاقة'],
                                    ['تحويل', 'تحويل بنكي'],
                                ] as const
                            ).map(([value, label]) => (
                                <label key={value} className="flex items-center gap-2 text-[13px]">
                                    <input
                                        type="radio"
                                        name="payment_method"
                                        className="size-4 accent-[#6d28d9]"
                                        checked={form.data.payment_method === value}
                                        onChange={() => form.setData('payment_method', value)}
                                    />
                                    {t(label)}
                                </label>
                            ))}
                        </div>

                        {/*
                            ───────── وأيُّ حسابٍ استقبل المال ─────────

                            «بنك» في الدفتر تكفي القيدَ ولا تكفي المطابقة: كشفُ
                            الحساب يصل من بنكٍ بعينه، وسطرٌ لا يعرف حسابَه لا
                            يجد ما يُطابقه. والقائمةُ من «المالية» لا مكتوبةً
                            هنا — ومتجرٌ بلا حسابٍ مسجَّل يُقال له أين يُسجِّله.
                        */}
                        {needsAccount && (
                            <div className="mt-4 space-y-1.5">
                                <Label htmlFor="bank-account">{t('الحساب البنكي المستلِم')}</Label>

                                {bank_accounts.length > 0 ? (
                                    <Select
                                        id="bank-account"
                                        value={form.data.bank_account_id}
                                        onChange={(e) => form.setData('bank_account_id', e.target.value)}
                                        options={bank_accounts.map((a) => ({
                                            label: a.is_primary ? `${a.name} — ${t('رئيسي')}` : a.name,
                                            value: String(a.id),
                                        }))}
                                    />
                                ) : (
                                    <p className="rounded-[10px] border border-dashed border-[#fde68a] bg-[#fffbeb] p-3 text-[12px] leading-relaxed text-[#92400e]">
                                        {t('لا حساب بنكي مسجَّل. سجّله في «المالية ← الحسابات البنكية» ليُطابَق التحصيل بكشف الحساب.')}
                                    </p>
                                )}

                                {form.errors.bank_account_id && <Err msg={form.errors.bank_account_id} />}
                            </div>
                        )}

                        {/*
                            وما يقع بعد الإصدار يُقال قبله: من اختار «آجل» يصنع
                            ذمّةً، ومن اختار غيره يُسجَّل له إيصالُ تحصيلٍ
                            بالمبلغ كلِّه — ولا تُوسَم فاتورةٌ «مدفوعة» بلا إيصال.
                        */}
                        <p className="mt-4 rounded-[10px] bg-[#f5f3ff] p-3 text-[12px] leading-relaxed text-[#5b21b6]">
                            {credit
                                ? t('تُنشأ ذمّة على العميل بالمبلغ المستحق بعد إصدار الفاتورة.')
                                : t('يُسجَّل إيصال تحصيل بالمبلغ كاملًا عند إصدار الفاتورة — ولا يُسجَّل على مسودّة.')}
                        </p>

                        {form.errors.payment_method && <Err msg={form.errors.payment_method} />}
                    </Card>

                    {/*
                        ───────── المرفقات ─────────

                        ومرفقاتُ الورقة: أمرُ شراء الجهة وعقدُها وطلبُها الموقَّع.

                        وهي على قرصٍ خاصّ تُقرأ ببابٍ يسأل — لا على القرص العامّ:
                        أمرُ شراء وزارةٍ ليس مستندًا يُفتح برابطٍ يُخمَّن.
                    */}
                    <Card className="p-5">
                        <h2 className="mb-1 text-[15px] font-bold text-[#111]">{t('مرفقات الفاتورة')}</h2>
                        <p className="mb-3 text-[12px] text-[#9ca3af]">
                            {t('عرض سعر، عقد، أو أي مستندات داعمة.')}
                        </p>

                        <label className="flex cursor-pointer flex-col items-center gap-1.5 rounded-[12px] border border-dashed border-[var(--ui-border,#e8e8e8)] bg-[#fafafa] px-4 py-6 text-center transition-colors hover:bg-[#f4f4f5]">
                            <Paperclip className="size-5 text-[#6d28d9]" />
                            <span className="text-[13px] font-medium text-[#6d28d9]">{t('اختيار ملفات')}</span>
                            <span className="text-[11px] text-[#9ca3af]">JPG · PNG · PDF · WEBP · HEIC</span>
                            <span className="text-[11px] text-[#9ca3af]">
                                {t('حتى ٦ ملفات، ١٠ ميجابايت لكلٍّ')}
                            </span>
                            <input
                                type="file"
                                multiple
                                className="hidden"
                                accept=".jpg,.jpeg,.png,.pdf,.webp,.heic"
                                onChange={(e) => addFiles(e.target.files)}
                            />
                        </label>

                        {files.length > 0 && (
                            <ul className="mt-3 space-y-1.5 text-[13px]">
                                {files.map((f, i) => (
                                    <li key={`${f.name}-${i}`} className="flex items-center gap-2">
                                        <span className="min-w-0 flex-1 truncate">{f.name}</span>
                                        <button
                                            type="button"
                                            aria-label={t('إزالة المرفق')}
                                            className="text-[#b91c1c]"
                                            onClick={() => form.setData('attachments', files.filter((_, k) => k !== i))}
                                        >
                                            <Trash2 className="size-4" />
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        )}

                        {form.errors.attachments && <Err msg={form.errors.attachments} />}
                    </Card>
                </div>
            </div>
            <ProductDialog
                open={picking}
                onOpenChange={setPicking}
                products={products}
                truncated={catalog_truncated}
                picked={lines.map((l) => Number(l.product_id)).filter((n) => n > 0)}
                onPick={(p) =>
                    setLines((prev) => {
                        const line: Line = {
                            product_id: String(p.id),
                            description: p.name,
                            quantity: '1',
                            unit_price: String(p.price),
                            discount: '0',
                            tax_rate: String(tax_rate),
                        };
                        const empty = prev.length === 1 && prev[0].description === '' && prev[0].product_id === '';

                        return empty ? [line] : [...prev, line];
                    })
                }
            />

            <NewCustomerDialog open={adding} onOpenChange={setAdding} />
        </AdminLayout>
    );
}

/* ───────────────────────── قطعٌ صغيرة ───────────────────────── */

function blank(rate: number): Line {
    return { product_id: '', description: '', quantity: '1', unit_price: '0', discount: '0', tax_rate: String(rate) };
}

/** خياراتُ الضريبة: الإعفاء ونسبةُ المتجر — ولا ثالثَ يُخترع في الشاشة */
function taxOptions(rate: number) {
    const values = Array.from(new Set([0, rate])).sort((a, b) => a - b);

    return values.map((v) => ({ label: `${v}%`, value: String(v) }));
}

/** يومٌ بعد يوم — بلا مكتبةِ تواريخ لحسابٍ من ثلاثة أسطر */
function addDays(from: string, days: number): string {
    const d = new Date(from);

    if (Number.isNaN(d.getTime())) {
        return from;
    }

    d.setDate(d.getDate() + days);

    return d.toISOString().slice(0, 10);
}

function Err({ msg }: { msg: string }) {
    return <p className="mt-1 text-[12px] text-[#b91c1c]">{msg}</p>;
}

function Row({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex items-center justify-between">
            <dt className="text-[#6b7280]">{label}</dt>
            <dd className="tabular-nums text-[#111]">{value}</dd>
        </div>
    );
}

/**
 * اختيارُ العميل — بحثٌ لا قائمةٌ تُمرَّر بالعين.
 *
 * متجرٌ فيه مئتان وخمسون عميلًا لا يُختار منه بقائمةٍ منسدلة: من يعرف الاسم
 * يكتب حرفين ويجده، ومن لا يعرفه يفتح القائمة كما هي.
 */
function CustomerPicker({
    customers,
    value,
    onChange,
}: {
    customers: CustomerRow[];
    value: string;
    onChange: (id: string) => void;
}) {
    const t = useTranslate();
    const [q, setQ] = useState('');
    const [open, setOpen] = useState(false);

    if (value) {
        return null;
    }

    const needle = q.trim().toLowerCase();
    const shown = (needle === '' ? customers : customers.filter((c) => c.name.toLowerCase().includes(needle))).slice(
        0,
        50,
    );

    return (
        <div className="relative">
            <Input
                value={q}
                onChange={(e) => {
                    setQ(e.target.value);
                    setOpen(true);
                }}
                onFocus={() => setOpen(true)}
                onBlur={() => window.setTimeout(() => setOpen(false), 150)}
                placeholder={t('ابحث عن عميل أو اختر من القائمة')}
            />

            {open && (
                <div className="absolute inset-x-0 top-full z-20 mt-1 max-h-64 overflow-y-auto rounded-[10px] border border-[var(--ui-border,#e8e8e8)] bg-white shadow-[0_8px_30px_rgba(0,0,0,0.10)]">
                    {shown.map((c) => (
                        <button
                            key={c.id}
                            type="button"
                            className="block w-full px-3 py-2 text-start text-[13px] hover:bg-[#f7f7f5]"
                            onMouseDown={(e) => e.preventDefault()}
                            onClick={() => {
                                onChange(String(c.id));
                                setOpen(false);
                            }}
                        >
                            {c.name}
                            {c.customer_type && c.customer_type !== 'فرد' && (
                                <span className="ms-2 text-[11px] text-[#9ca3af]">{c.customer_type}</span>
                            )}
                        </button>
                    ))}
                    {shown.length === 0 && (
                        <p className="p-3 text-[12px] text-[#9ca3af]">{t('لا عميل بهذا الاسم')}</p>
                    )}
                </div>
            )}
        </div>
    );
}

/** بطاقةُ العميل المختار — ما يُطبع على الورقة يُقرأ قبل إصدارها */
function CustomerCard({ customer, onClear }: { customer: CustomerRow; onClear: () => void }) {
    const t = useTranslate();

    const facts = [
        customer.tax_number && `${t('الرقم الضريبي')}: ${customer.tax_number}`,
        customer.commercial_registration && `${t('سجل تجاري')}: ${customer.commercial_registration}`,
        customer.contact_email || customer.email,
        customer.contact_phone || customer.phone,
        customer.billing_address || customer.address,
        customer.contact_person,
    ].filter(Boolean) as string[];

    return (
        <div className="mt-3 rounded-[12px] border border-[var(--ui-border,#e8e8e8)] bg-[#fafafa] p-4">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <p className="text-[15px] font-bold text-[#111]">{customer.legal_name || customer.name}</p>
                    {customer.customer_type && (
                        <span className="mt-1 inline-block rounded-full bg-[#eff6ff] px-2 py-0.5 text-[11px] text-[#1d4ed8]">
                            {customer.customer_type}
                        </span>
                    )}
                </div>
                <Button type="button" variant="outline" size="sm" onClick={onClear}>
                    {t('تغيير')}
                </Button>
            </div>

            {facts.length > 0 && (
                <ul className="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-[12px] text-[#6b7280]">
                    {facts.map((f) => (
                        <li key={f}>{f}</li>
                    ))}
                </ul>
            )}

            {!customer.allow_credit_sales && (
                <p className="mt-3 text-[12px] text-[#b45309]">
                    {t('البيع الآجل غير مفتوح لهذا العميل — تُفتح صلاحيته من صفحته.')}
                </p>
            )}
        </div>
    );
}

/**
 * منتجٌ من الكتالوج — يُبحث فيُصير بندًا.
 *
 * ═══ لماذا لا تُغلق عند أوّل اختيار ═══
 *
 * كانت تُغلق، فمن يكتب فاتورةً بخمسة بنودٍ يفتحها خمس مرّات ويكتب بحثَه من
 * أوّله في كلّ مرّة. والفاتورةُ بندٌ واحدٌ نادرًا. فتبقى مفتوحةً ويُفرَّغ
 * البحثُ ويعود المؤشّر إليه، ويقول العدّادُ كم أُضيف — و«تمّ» تُغلقها.
 *
 * ═══ ولماذا لا يُعرض الرصيد ═══
 *
 * لأنّ فاتورة العميل التزامٌ ماليٌّ لا حركةَ مخزون: لا تُنقص رصيدًا ولا
 * تُحجزه. ورقمٌ يُعرض هنا يُقرأ وعدًا بالتوفّر لا يفي به المستند.
 */
export function ProductDialog({
    open,
    onOpenChange,
    products,
    truncated,
    picked,
    onPick,
}: {
    open: boolean;
    onOpenChange: (v: boolean) => void;
    products: ProductRow[];
    /** هل بقي في الكتالوج ما لم يُرسَل؟ — يُقال ولا يُكتم */
    truncated: boolean;
    /** ما أُضيف إلى الفاتورة فعلًا — يُعلَّم فلا يُضاف مرّتين سهوًا */
    picked: number[];
    onPick: (p: ProductRow) => void;
}) {
    const { context } = usePage<PageProps>().props;
    const t = useTranslate();
    const [q, setQ] = useState('');
    const [active, setActive] = useState(0);
    const [added, setAdded] = useState(0);
    const inputRef = useRef<HTMLInputElement>(null);
    const listRef = useRef<HTMLDivElement>(null);

    /*
     * والنافذةُ تُفتح على حالٍ جديدة لا على بقايا المرّة الماضية.
     *
     * بحثٌ قديمٌ محفوظٌ يعني قائمةً مرشَّحةً بكلمةٍ لا يذكرها من فتحها الآن،
     * فيقرؤها كتالوجًا فيه ثلاثة أصناف.
     */
    useEffect(() => {
        if (open) {
            setQ('');
            setActive(0);
            setAdded(0);
            // ‏وبعد رسم النافذة: التركيزُ قبلها يذهب إلى عنصرٍ لم يوجد بعد
            const id = setTimeout(() => inputRef.current?.focus(), 30);

            return () => clearTimeout(id);
        }
    }, [open]);

    /*
     * والمطابقةُ تُحسب مرّةً: كم طابق، وأيّها يُعرض.
     *
     * وكُتبت أوّلًا مرّتين — واحدةً للقائمة وواحدةً للعدّاد — فأطفرتُ الأولى
     * فنجت المطفرة: الثانيةُ كانت تحرسها. وشرطان يقولان الشيء نفسه يفترقان
     * يومًا، فيقول العدّادُ «من ٤٠» وتعرض القائمةُ غيرها.
     */
    const matched = useMemo(() => {
        const needle = fold(q);

        if (needle === '') {
            return products;
        }

        return products.filter(
            (p) =>
                fold(p.name).includes(needle) ||
                fold(p.sku ?? '').includes(needle) ||
                fold(p.barcode ?? '').includes(needle),
        );
    }, [products, q]);

    const shown = useMemo(() => matched.slice(0, SHOWN), [matched]);
    const total = matched.length;

    // ‏والمؤشّر يعود إلى أوّل الصفوف كلّما تبدّلت: صفٌّ مضيءٌ خارج القائمة لا يُختار
    useEffect(() => setActive(0), [q]);

    const take = (p: ProductRow) => {
        onPick(p);
        setAdded((n) => n + 1);
        setQ('');
        inputRef.current?.focus();
    };

    /*
     * والسهمان وEnter: قارئُ الباركود يكتب ثمّ يضغط Enter، ومن يكتب بيده
     * لا يريد أن يترك اللوحة إلى الفأرة بين كلّ بندين.
     */
    const onKey = (e: React.KeyboardEvent) => {
        if (shown.length === 0) {
            return;
        }

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setActive((i) => (i + 1) % shown.length);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setActive((i) => (i - 1 + shown.length) % shown.length);
        } else if (e.key === 'Enter') {
            e.preventDefault();
            take(shown[active] ?? shown[0]);
        }
    };

    // ‏والصفُّ المضيء يُجَرّ إلى داخل الإطار: مؤشّرٌ يمشي خارج ما يُرى ليس مؤشّرًا
    useEffect(() => {
        listRef.current?.querySelector('[data-active="true"]')?.scrollIntoView({ block: 'nearest' });
    }, [active, shown]);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-xl">
                <DialogHeader>
                    <DialogTitle>{t('منتج من الكتالوج')}</DialogTitle>
                    <DialogDescription>
                        {t('ابحث بالاسم أو رمز الصنف أو الباركود — والنافذة تبقى مفتوحة لتضيف أكثر من بند.')}
                    </DialogDescription>
                </DialogHeader>

                <div className="px-5">
                    <div className="relative">
                        <Search className="pointer-events-none absolute top-1/2 size-4 -translate-y-1/2 text-[#9ca3af] start-3" />
                        <Input
                            ref={inputRef}
                            value={q}
                            onChange={(e) => setQ(e.target.value)}
                            onKeyDown={onKey}
                            placeholder={t('اسم الصنف أو رمزه أو الباركود')}
                            className="ps-9"
                        />
                    </div>

                    <div ref={listRef} className="mt-2 max-h-80 overflow-y-auto">
                        {shown.map((p, i) => {
                            const already = picked.includes(p.id);

                            return (
                                <button
                                    key={p.id}
                                    type="button"
                                    data-active={i === active}
                                    onMouseEnter={() => setActive(i)}
                                    onClick={() => take(p)}
                                    className={cn(
                                        'flex w-full items-center gap-3 rounded-[8px] px-3 py-2 text-start text-[13px]',
                                        i === active ? 'bg-[#f1f1ef]' : 'hover:bg-[#f7f7f5]',
                                    )}
                                >
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate text-[#111]">{p.name}</span>
                                        {/*
                                            والرمزُ تحت الاسم لاتينيًّا: أرقامٌ
                                            وحروفٌ إن تُركت لاتجاه الصفحة قُرئت
                                            معكوسةً — ورمزٌ معكوسٌ لا يُطابق ورقة.
                                        */}
                                        {(p.sku || p.barcode) && (
                                            <span
                                                dir="ltr"
                                                className="block truncate font-mono text-[11px] text-[#9ca3af]"
                                            >
                                                {[p.sku, p.barcode].filter(Boolean).join(' · ')}
                                            </span>
                                        )}
                                    </span>

                                    {already && (
                                        <span className="shrink-0 rounded-full bg-[#eff6ff] px-2 py-0.5 text-[11px] text-[#1d4ed8]">
                                            {t('في الفاتورة')}
                                        </span>
                                    )}

                                    <span className="shrink-0 tabular-nums text-[#6b7280]">
                                        {money(Number(p.price), context!.currency)}
                                    </span>
                                </button>
                            );
                        })}

                        {shown.length === 0 && (
                            <p className="p-4 text-center text-[12px] text-[#9ca3af]">
                                {products.length === 0
                                    ? t('لا أصناف في الكتالوج بعد — تُضاف من صفحة المنتجات.')
                                    : t('لا صنف بهذا الاسم أو الرمز')}
                            </p>
                        )}

                        {/*
                            وقائمةٌ قُصّت تقول إنّها قُصّت.
                            من رأى عشرين صفًّا وظنّها كلَّ ما طابق يكفّ عن البحث.
                        */}
                        {total > shown.length && (
                            <p className="p-3 text-center text-[11px] text-[#9ca3af]">
                                {t('عُرض :n من :m — ضيّق البحث', { n: shown.length, m: total })}
                            </p>
                        )}
                    </div>

                    {truncated && q.trim() === '' && (
                        <p className="mt-1 text-[11px] text-[#b45309]">
                            {t('الكتالوج أكبر ممّا يُحمَّل هنا — ابحث بالاسم أو الرمز للوصول إلى الباقي.')}
                        </p>
                    )}
                </div>

                <DialogFooter>
                    <Button variant="outline" onClick={() => onOpenChange(false)}>
                        {t('تمّ')}
                    </Button>
                    {added > 0 && (
                        <span className="me-auto text-[12px] text-[#047857]">
                            {t('أُضيف :n بندًا', { n: added })}
                        </span>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/**
 * عميلٌ جديد بلا مغادرة الصفحة.
 *
 * من كتب خمسةَ بنودٍ ثمّ اكتشف أنّ الجهة ليست مسجَّلة كان يفقد ما كتب.
 */
function NewCustomerDialog({ open, onOpenChange }: { open: boolean; onOpenChange: (v: boolean) => void }) {
    const t = useTranslate();
    const form = useForm({
        name: '',
        customer_type: 'شركة',
        phone: '',
        email: '',
        tax_number: '',
        commercial_registration: '',
        address: '',
    });

    const save = () =>
        form.post('/admin/customer-invoices/customers', {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                form.reset();
                onOpenChange(false);
            },
        });

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{t('عميل جديد')}</DialogTitle>
                    <DialogDescription>
                        {t('يُحفظ في قائمة العملاء ويُختار في هذه الفاتورة فورًا.')}
                    </DialogDescription>
                </DialogHeader>

                {/*
                    والحشوُ على الجسم لا على النافذة.

                    `DialogHeader` و`DialogFooter` يحملان `p-5` و`DialogContent`
                    لا حشوَ فيه — فجسمٌ يُكتب بلا `px-5 pb-5` تلتصق حقولُه
                    بحافّتَي النافذة. وهي القاعدةُ في كلّ نوافذ النظام.
                */}
                <div className="space-y-4 px-5 pb-5">
                    <Field label="الاسم" required error={form.errors.name}>
                        <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                    </Field>

                    <Field label="نوع العميل">
                        <Select
                            value={form.data.customer_type}
                            onChange={(e) => form.setData('customer_type', e.target.value)}
                            options={[
                                { label: 'شركة', value: 'شركة' },
                                { label: 'جهة حكومية', value: 'جهة حكومية' },
                                { label: 'فرد', value: 'فرد' },
                            ]}
                        />
                    </Field>

                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <Field label="الهاتف" error={form.errors.phone}>
                            <Input value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} />
                        </Field>
                        <Field label="البريد" error={form.errors.email}>
                            <Input value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />
                        </Field>
                        <Field label="الرقم الضريبي">
                            <Input
                                value={form.data.tax_number}
                                onChange={(e) => form.setData('tax_number', e.target.value)}
                            />
                        </Field>
                        <Field label="سجل تجاري">
                            <Input
                                value={form.data.commercial_registration}
                                onChange={(e) => form.setData('commercial_registration', e.target.value)}
                            />
                        </Field>
                    </div>

                    <Field label="العنوان">
                        <Input value={form.data.address} onChange={(e) => form.setData('address', e.target.value)} />
                    </Field>
                </div>

                <DialogFooter>
                    <Button disabled={form.processing} onClick={save}>
                        {t('حفظ العميل')}
                    </Button>
                    <Button variant="outline" onClick={() => onOpenChange(false)}>
                        {t('إلغاء')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
