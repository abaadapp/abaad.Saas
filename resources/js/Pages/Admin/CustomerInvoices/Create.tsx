import { useEffect, useMemo, useRef, useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import { ChevronDown, Plus, Search, Trash2, UserPlus } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import BackLink from '@/Components/BackLink';
import PageHeader from '@/Components/PageHeader';
import { Select } from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import { Input, Textarea } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { money } from '@/lib/format';
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
    price: number;
}

interface Props {
    customers: CustomerRow[];
    products: ProductRow[];
    next_number: string;
    tax_rate: number;
    today: string;
    methods: string[];
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
 * حقولُ الجهة — مصدرٌ واحدٌ للمفتاح والتسمية والمثال.
 *
 * والتسمياتُ مطوّلةٌ عمدًا: «المرجع» وحدَه لا يقول مرجعَ ماذا، و«عناية» بلا
 * «موجه إلى» تُقرأ تحذيرًا. ومن يملأ ورقةً لجهةٍ حكوميّة يقرأ الاسمَ مرّةً
 * واحدة — فليقُل ما يعنيه من أوّل قراءة.
 */
const ORG_FIELDS = [
    ['po_number', 'رقم أمر الشراء (PO)', 'PO-2026-154'],
    ['contract_number', 'رقم العقد', 'CNT-2026-08'],
    ['external_reference', 'رقم المرجع', 'REF-8842'],
    ['department', 'القسم / الإدارة', ''],
    ['cost_center', 'مركز التكلفة', 'CC-104'],
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
    next_number,
    tax_rate,
    today,
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
        payment_method: 'آجل',
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

    const submit = (issue: boolean) => {
        // و«مخصص» لا يُرسَل مدّةً: الخادمُ يخزّن عددَ أيّامٍ أو لا شيء
        form.transform((data) => ({
            ...data,
            issue,
            items: lines,
            payment_terms_days: data.payment_terms_days === CUSTOM ? '' : data.payment_terms_days,
        }));
        form.post('/admin/customer-invoices', { preserveScroll: true });
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
                title="إنشاء فاتورة"
                subtitle={t('فاتورة جديدة للعميل مع تحديد البنود والشروط.')}
                actions={
                    <span className="rounded-full bg-[#eff6ff] px-3 py-1 text-[12px] font-medium text-[#1d4ed8]">
                        {t('مسودة')}
                    </span>
                }
            />

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                {/* ───────── بيانات العميل ───────── */}
                <Card className="p-5">
                    <div className="mb-4 flex items-center justify-between gap-3">
                        <h2 className="text-[15px] font-bold text-[#111]">{t('بيانات العميل')}</h2>
                        <Button type="button" variant="outline" size="sm" onClick={() => setAdding(true)}>
                            <UserPlus />
                            {t('عميل جديد')}
                        </Button>
                    </div>

                    <CustomerPicker
                        customers={customers}
                        value={form.data.customer_id}
                        onChange={(id) => {
                            const picked = customers.find((c) => String(c.id) === id);
                            /*
                             * ولا يُنسخ «القسم» من صفّ العميل هنا.
                             *
                             * الخادمُ يسقط إليه حين يُترك الحقلُ فارغًا — ونسخُه
                             * في الشاشة أيضًا موضعان يقرّران الشيء نفسه، وأوّلُ
                             * تغييرٍ في أحدهما يجعل الورقةَ تحمل قسمًا لم يُقصد.
                             */
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
                        }}
                    />
                    {form.errors.customer_id && <Err msg={form.errors.customer_id} />}

                    {customer && <CustomerCard customer={customer} onClear={() => form.setData('customer_id', '')} />}
                </Card>
                {/* ───────── معلومات الفاتورة ───────── */}
                <div className="space-y-4">
                    <Card className="p-5">
                        <h2 className="mb-4 text-[15px] font-bold text-[#111]">{t('معلومات الفاتورة')}</h2>

                        {/*
                            والأساسيُّ وحدَه هنا: رقمٌ وتاريخان ومدّة.
                            وأمرُ الشراء والعقدُ نزلا إلى «معلومات إضافية» —
                            ليسا من الورقة، بل من الجهة التي تطلبها، ونصفُ
                            الفواتير لأفرادٍ لا أمرَ شراء لهم.
                        */}
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                            <div className="space-y-1.5">
                                <Label htmlFor="invoice-number">{t('رقم الفاتورة')}</Label>
                                {/* يُولَّد في الخادم تحت قفل — ولا يُكتب ولا يُرسَل */}
                                <Input id="invoice-number" value={next_number} readOnly disabled dir="ltr" />
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="issued-at" required>
                                    {t('تاريخ الفاتورة')}
                                </Label>
                                <Input
                                    id="issued-at"
                                    type="date"
                                    value={form.data.issued_at}
                                    onChange={(e) => setIssued(e.target.value)}
                                />
                                {form.errors.issued_at && <Err msg={form.errors.issued_at} />}
                            </div>
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
                        </div>

                        <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3">
                            <div className="space-y-1.5">
                                <Label htmlFor="payment-terms">{t('شروط الدفع')}</Label>
                                <Select
                                    id="payment-terms"
                                    value={form.data.payment_terms_days}
                                    onChange={(e) => setTerms(e.target.value)}
                                    options={termOptions(t)}
                                />
                            </div>
                        </div>
                    </Card>

                    {/*
                        وبياناتُ الجهة مطويّة، وخفيفةٌ في النظر.

                        نصفُ الفواتير لأفرادٍ لا مركزَ تكلفةٍ لهم ولا أمرَ شراء،
                        وستّةُ حقولٍ مفتوحةٍ دائمًا تدفع البنودَ — وهي لبُّ
                        الورقة — تحت طيّة الشاشة.
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

                        {/*
                            وستّةٌ لا أكثر، وكلُّها اختياريّة: ما لا يُملأ لا
                            يُخزَّن ولا يُطبع — انظر `pdf/customer-invoice`.
                        */}
                        <div id="org-fields" role="region" aria-labelledby="org-toggle" hidden={!extra}>
                            <div className="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
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
                </div>

            </div>

            {/* ───────── البنود ───────── */}
            <Card className="mt-4 p-5">
                <div className="mb-4 flex flex-wrap items-center gap-2">
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
                        {t('بند مخصص')}
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="ms-auto text-[#b91c1c]"
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
                                <th className="w-[130px] p-2 text-start font-medium">{t('سعر الوحدة')}</th>
                                <th className="w-[120px] p-2 text-start font-medium">{t('الخصم')}</th>
                                <th className="w-[120px] p-2 text-start font-medium">{t('الضريبة')}</th>
                                <th className="w-[110px] p-2 text-start font-medium">{t('الإجمالي')}</th>
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

            {/* ───────── السداد · الملخّص · الملاحظات ───────── */}
            <div className="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
                <Card className="p-5">
                    <h2 className="mb-4 text-[15px] font-bold text-[#111]">{t('طريقة السداد')}</h2>

                    <div className="flex flex-wrap gap-4">
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
                                    className="size-4 accent-[#1d4ed8]"
                                    checked={form.data.payment_method === value}
                                    onChange={() => form.setData('payment_method', value)}
                                />
                                {t(label)}
                            </label>
                        ))}
                    </div>

                    {/*
                        وما يقع بعد الإصدار يُقال قبله: من اختار «آجل» يصنع
                        ذمّةً، ومن اختار غيره يُسجَّل له إيصالُ تحصيلٍ بالمبلغ
                        كلِّه — ولا تُوسَم فاتورةٌ «مدفوعة» بلا إيصال.
                    */}
                    <p className="mt-4 rounded-[10px] bg-[#eff6ff] p-3 text-[12px] leading-relaxed text-[#1d4ed8]">
                        {credit
                            ? t('تُنشأ ذمّة على العميل بالمبلغ المستحق بعد إصدار الفاتورة.')
                            : t('يُسجَّل إيصال تحصيل بالمبلغ كاملًا عند إصدار الفاتورة — ولا يُسجَّل على مسودّة.')}
                    </p>

                    {form.errors.payment_method && <Err msg={form.errors.payment_method} />}
                </Card>

                <Card className="p-5">
                    <h2 className="mb-4 text-[15px] font-bold text-[#111]">{t('ملخص الفاتورة')}</h2>

                    <dl className="space-y-2 text-[13px]">
                        <Row label={t('المجموع الفرعي')} value={m(totals.subtotal)} />
                        <Row label={t('الخصم')} value={m(totals.discount)} />
                        <Row label={t('الضريبة')} value={m(totals.tax)} />
                    </dl>

                    <div className="mt-3 flex items-center justify-between rounded-[10px] bg-[#f7f7f5] px-3 py-2.5">
                        <span className="text-[14px] font-bold text-[#111]">{t('الإجمالي')}</span>
                        <span className="text-[16px] font-bold tabular-nums text-[#111]">{m(totals.total)}</span>
                    </div>
                </Card>

                <Card className="p-5">
                    <h2 className="mb-4 text-[15px] font-bold text-[#111]">{t('ملاحظات')}</h2>
                    <Textarea
                        value={form.data.notes}
                        maxLength={500}
                        onChange={(e) => form.setData('notes', e.target.value)}
                        placeholder={t('أضف أي ملاحظات أو شروط إضافية في الفاتورة…')}
                        className="min-h-28"
                    />
                    <p className="mt-1 text-[12px] text-[#9ca3af]" dir="ltr">
                        {form.data.notes.length}/500
                    </p>
                </Card>
            </div>

            <div className="mt-4 flex flex-wrap justify-end gap-2">
                {/*
                    و«حفظ كمسودة» يختفي مع طريقةِ سدادٍ مقبوضة — لا يُعرض بابٌ
                    يردّ الخادمُ من خلفه. والحارسُ في الخادم على أيّ حال.
                */}
                {credit && (
                    <Button variant="outline" disabled={form.processing} onClick={() => submit(false)}>
                        {t('حفظ كمسودة')}
                    </Button>
                )}
                <Button disabled={form.processing} onClick={() => submit(true)}>
                    {t('إصدار الفاتورة')}
                </Button>
            </div>

            <ProductDialog
                open={picking}
                onOpenChange={setPicking}
                products={products}
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

/** منتجٌ من الكتالوج — يُبحث ويُنقر فيصير بندًا */
function ProductDialog({
    open,
    onOpenChange,
    products,
    onPick,
}: {
    open: boolean;
    onOpenChange: (v: boolean) => void;
    products: ProductRow[];
    onPick: (p: ProductRow) => void;
}) {
    const t = useTranslate();
    const [q, setQ] = useState('');

    const needle = q.trim().toLowerCase();
    const shown = (needle === '' ? products : products.filter((p) => p.name.toLowerCase().includes(needle))).slice(
        0,
        100,
    );

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{t('منتج من الكتالوج')}</DialogTitle>
                </DialogHeader>

                <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder={t('ابحث باسم الصنف')} />

                <div className="mt-2 max-h-72 overflow-y-auto">
                    {shown.map((p) => (
                        <button
                            key={p.id}
                            type="button"
                            className="flex w-full items-center justify-between gap-3 rounded-[8px] px-3 py-2 text-[13px] hover:bg-[#f7f7f5]"
                            onClick={() => {
                                onPick(p);
                                onOpenChange(false);
                            }}
                        >
                            <span className="min-w-0 truncate">{p.name}</span>
                            <span className="tabular-nums text-[#6b7280]">{Number(p.price).toFixed(3)}</span>
                        </button>
                    ))}
                    {shown.length === 0 && <p className="p-3 text-[12px] text-[#9ca3af]">{t('لا صنف بهذا الاسم')}</p>}
                </div>
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
                </DialogHeader>

                <div className="space-y-3">
                    <div className="space-y-1.5">
                        <Label required>{t('الاسم')}</Label>
                        <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                        {form.errors.name && <Err msg={form.errors.name} />}
                    </div>

                    <div className="space-y-1.5">
                        <Label>{t('نوع العميل')}</Label>
                        <Select
                            value={form.data.customer_type}
                            onChange={(e) => form.setData('customer_type', e.target.value)}
                            options={[
                                { label: 'شركة', value: 'شركة' },
                                { label: 'جهة حكومية', value: 'جهة حكومية' },
                                { label: 'فرد', value: 'فرد' },
                            ]}
                        />
                    </div>

                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div className="space-y-1.5">
                            <Label>{t('الهاتف')}</Label>
                            <Input value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} />
                            {form.errors.phone && <Err msg={form.errors.phone} />}
                        </div>
                        <div className="space-y-1.5">
                            <Label>{t('البريد')}</Label>
                            <Input value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />
                            {form.errors.email && <Err msg={form.errors.email} />}
                        </div>
                        <div className="space-y-1.5">
                            <Label>{t('الرقم الضريبي')}</Label>
                            <Input
                                value={form.data.tax_number}
                                onChange={(e) => form.setData('tax_number', e.target.value)}
                            />
                        </div>
                        <div className="space-y-1.5">
                            <Label>{t('سجل تجاري')}</Label>
                            <Input
                                value={form.data.commercial_registration}
                                onChange={(e) => form.setData('commercial_registration', e.target.value)}
                            />
                        </div>
                    </div>

                    <div className="space-y-1.5">
                        <Label>{t('العنوان')}</Label>
                        <Input value={form.data.address} onChange={(e) => form.setData('address', e.target.value)} />
                    </div>
                </div>

                <div className="mt-4 flex justify-end gap-2">
                    <Button variant="outline" onClick={() => onOpenChange(false)}>
                        {t('إلغاء')}
                    </Button>
                    <Button disabled={form.processing} onClick={save}>
                        {t('حفظ العميل')}
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}
