import { useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { FileText, MessageCircle, Paperclip, Trash2, Undo2 } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { money } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { PageProps } from '@/types';

interface Invoice {
    id: number;
    /** فارغٌ للمسودّة: الرقمُ يُقطع عند الإصدار */
    number: string | null;
    customer: string;
    customer_id: number;
    status: string;
    state: string;
    issued_at: string | null;
    due_at: string | null;
    total: number;
    paid: number;
    outstanding: number;
    days_overdue: number;
    po_number: string | null;
    contract_number: string | null;
    external_reference: string | null;
    department: string | null;
    cost_center: string | null;
    attention_to: string | null;
    subtotal: number;
    discount_total: number;
    tax_total: number;
    notes: string | null;
    /** لا تُطبع ولا تخرج من هذه الشاشة */
    internal_notes: string | null;
    may_read_attachments: boolean;
    attachments: { id: number; name: string; size: number; url: string | null }[];
    orders: string[];
    cancellation_reason: string | null;
    items: { description: string; quantity: number; unit_price: number; discount: number; tax_rate: number; line_total: number }[];
    payments: { number: string; amount: number; method: string; at: string | null }[];
    credit_notes: { number: string; amount: number; reason: string | null; at: string | null }[];
}

/**
 * ما يملكه من يقرأ.
 *
 * والمقابضُ تُرسم عليه: «بابٌ معروضٌ لا يُفتح أسوأ من بابٍ لا يُعرض» — من
 * يضغط «إلغاء» فيُردّ بـ٤٠٣ يظنّ النظامَ معطوبًا لا نفسَه غيرَ مأذون.
 */
/** حسابٌ بنكيٌّ من «المالية» — اسمُه محسوبٌ في الخادم، انظر شاشةَ الإنشاء */
interface BankRow {
    id: number;
    name: string;
    is_primary: boolean;
}

interface May {
    issue: boolean;
    cancel: boolean;
    credit_note: boolean;
    pay: boolean;
}

/** فاتورةُ عميل — بنودُها وتحصيلاتُها وما بقي منها */
export default function CustomerInvoiceShow() {
    const { invoice, may, bank_accounts, methods, bank_methods, context } =
        usePage<
            PageProps<{
                invoice: Invoice;
                may: May;
                bank_accounts: BankRow[];
                /** وسائلُ التحصيل — من `CustomerPayments::METHODS` لا مكتوبةً هنا */
                methods: string[];
                /** أيُّها يدخل مالُه بنكًا — من `CustomerPayments::sideFor` */
                bank_methods: string[];
            }>
        >().props;
    const t = useTranslate();
    const m = (v: number) => money(v, context!.currency);
    /* ومسودّةٌ بلا رقم تُعرف بمعرّفها — والعنوانُ لا يكون فراغًا */
    const label = invoice.number ?? `${t('مسودة')} #${invoice.id}`;

    const [paying, setPaying] = useState(false);
    const [crediting, setCrediting] = useState(false);

    const issue = useForm({});

    /*
     * والرفعُ والحذفُ يمرّان على البابين القائمين — لا نسخةَ ثانية من
     * القاعدة في المتصفّح: الخادمُ يقول ما يُقبل حجمًا وصيغةً وعددًا.
     */
    const attach = (list: FileList | null) => {
        if (! list || list.length === 0) return;
        router.post(
            `/admin/customer-invoices/${invoice.id}/attachments`,
            { attachments: Array.from(list) },
            { forceFormData: true, preserveScroll: true },
        );
    };

    const detach = (id: number) =>
        router.delete(`/admin/customer-invoices/${invoice.id}/attachments/${id}`, { preserveScroll: true });
    const cancel = useForm({ reason: '' });
    const remind = useForm({});

    return (
        <AdminLayout title={label}>
            <PageHeader
                title={label}
                subtitle={`${invoice.customer} — ${invoice.state}`}
                actions={
                    <>
                        {invoice.status === 'مسودة' && may.issue && (
                            <Button disabled={issue.processing} onClick={() => issue.post(`/admin/customer-invoices/${invoice.id}/issue`, { preserveScroll: true })}>
                                {t('إصدار الفاتورة')}
                            </Button>
                        )}
                        {invoice.status === 'صادرة' && (
                            <>
                                {may.pay && (
                                    <Button variant="outline" onClick={() => setPaying((v) => !v)}>
                                        {t('تسجيل دفعة')}
                                    </Button>
                                )}
                                {invoice.outstanding > 0 && (
                                    <Button
                                        variant="outline"
                                        disabled={remind.processing}
                                        onClick={() => remind.post(`/admin/customer-invoices/${invoice.id}/remind`, { preserveScroll: true })}
                                    >
                                        <MessageCircle />
                                        {t('تذكير بالسداد')}
                                    </Button>
                                )}
                                {/*
                                    وإشعارُ الدائن مقبضٌ لا بابٌ في الخادم وحده.
                                    كان يعمل ولا زرَّ يفتحه: بضاعةٌ تُردّ أو خصمٌ
                                    يُتّفق عليه بعد الإصدار كان يُعالَج بإلغاء
                                    الورقة كلِّها — وورقةٌ سُلّمت لا تُلغى لأنّ
                                    عشرةً منها رُدّت.
                                */}
                                {may.credit_note && invoice.outstanding + invoice.paid > 0 && (
                                    <Button variant="outline" onClick={() => setCrediting((v) => !v)}>
                                        <Undo2 />
                                        {t('إشعار دائن')}
                                    </Button>
                                )}
                            </>
                        )}
                        <Button variant="outline" asChild>
                            <a href={`/admin/customer-invoices/${invoice.id}/pdf`} target="_blank" rel="noreferrer">
                                <FileText />
                                {t('تصدير PDF')}
                            </a>
                        </Button>
                    </>
                }
            />

            {invoice.status === 'ملغاة' && (
                <Card className="mb-4 border-[#fecaca] bg-[#fef2f2] p-3 text-[13px] text-[#b91c1c]">
                    {t('فاتورة ملغاة')} — {invoice.cancellation_reason}
                </Card>
            )}

            {paying && (
                <PayForm
                    invoice={invoice}
                    accounts={bank_accounts}
                    methods={methods}
                    bankMethods={bank_methods}
                    onDone={() => setPaying(false)}
                />
            )}
            {crediting && <CreditNoteForm invoice={invoice} onDone={() => setCrediting(false)} />}

            <div className="grid gap-4 lg:grid-cols-3">
                <Card className="p-4 lg:col-span-2">
                    <table className="w-full text-[13px]">
                        <thead className="text-[12px] text-[#71717a]">
                            <tr>
                                <th className="p-2 text-start">{t('البيان')}</th>
                                <th className="p-2 text-start">{t('الكمية')}</th>
                                <th className="p-2 text-start">{t('السعر')}</th>
                                <th className="p-2 text-start">{t('الإجمالي')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {invoice.items.map((it, i) => (
                                <tr key={i} className="border-t border-[var(--ui-border,#e8e8e8)]">
                                    <td className="p-2">{it.description}</td>
                                    <td className="p-2">{it.quantity}</td>
                                    <td className="p-2">{m(it.unit_price)}</td>
                                    <td className="p-2">{m(it.line_total)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </Card>

                <Card className="space-y-2 p-4 text-[13px]">
                    <Row label={t('المجموع الفرعي')} value={m(invoice.subtotal)} />
                    {invoice.discount_total > 0 && <Row label={t('الخصم')} value={m(invoice.discount_total)} />}
                    {invoice.tax_total > 0 && <Row label={t('الضريبة')} value={m(invoice.tax_total)} />}
                    <Row label={t('الإجمالي')} value={m(invoice.total)} strong />
                    <Row label={t('المسدَّد')} value={m(invoice.paid)} />
                    <Row label={t('الباقي')} value={m(invoice.outstanding)} strong />
                    {invoice.due_at && <Row label={t('الاستحقاق')} value={invoice.due_at} />}
                    {invoice.po_number && <Row label={t('أمر الشراء')} value={invoice.po_number} />}
                    {invoice.contract_number && <Row label={t('رقم العقد')} value={invoice.contract_number} />}
                    {invoice.department && <Row label={t('القسم')} value={invoice.department} />}
                    {invoice.attention_to && <Row label={t('عناية')} value={invoice.attention_to} />}
                </Card>
            </div>

            {invoice.payments.length > 0 && (
                <Card className="mt-4 p-4">
                    <h3 className="mb-2 text-[13px] font-bold">{t('التحصيلات')}</h3>
                    {invoice.payments.map((p) => (
                        <div key={p.number} className="flex justify-between border-t border-[var(--ui-border,#e8e8e8)] py-2 text-[13px]">
                            <span>{p.number} — {p.method}</span>
                            <span dir="ltr">{p.at}</span>
                            <span>{m(p.amount)}</span>
                        </div>
                    ))}
                </Card>
            )}

            {invoice.credit_notes.length > 0 && (
                <Card className="mt-4 p-4">
                    <h3 className="mb-2 text-[13px] font-bold">{t('إشعارات دائن')}</h3>
                    {invoice.credit_notes.map((n) => (
                        <div key={n.number} className="flex justify-between border-t border-[var(--ui-border,#e8e8e8)] py-2 text-[13px]">
                            <span>{n.number} — {n.reason}</span>
                            <span>{m(n.amount)}</span>
                        </div>
                    ))}
                </Card>
            )}

            {invoice.status !== 'ملغاة' && may.cancel && (
                <Card className="mt-4 flex flex-wrap items-end gap-2 p-4">
                    <label className="flex-1 text-[13px]">
                        {t('سبب الإلغاء')}
                        <Input value={cancel.data.reason} onChange={(e) => cancel.setData('reason', e.target.value)} />
                        {cancel.errors.reason && <p className="text-[12px] text-[#b91c1c]">{cancel.errors.reason}</p>}
                    </label>
                    {/* والسببُ مطلوب: إلغاءٌ بلا سبب لا يُقرأ بعد شهر */}
                    <Button
                        variant="danger"
                        disabled={cancel.processing}
                        onClick={() => cancel.post(`/admin/customer-invoices/${invoice.id}/cancel`, { preserveScroll: true })}
                    >
                        {t('إلغاء الفاتورة')}
                    </Button>
                </Card>
            )}

            <div className="mt-4 grid gap-4 lg:grid-cols-2">
                {/*
                    ومستنداتُ الورقة: أمرُ شراء الجهة وعقدُها وطلبُها الموقَّع.
                    على قرصٍ خاصّ تُقرأ ببابٍ يسأل عن المتجر وعن الصلاحية.
                */}
                <Card className="p-4">
                    <div className="mb-3 flex items-center justify-between gap-2">
                        <h2 className="font-bold text-[#111]">{t('مرفقات الفاتورة')}</h2>
                        <label className="cursor-pointer text-[13px] font-medium text-[#6d28d9] hover:underline">
                            {t('إضافة مستند')}
                            <input
                                type="file"
                                multiple
                                className="hidden"
                                accept=".jpg,.jpeg,.png,.pdf,.webp,.heic"
                                onChange={(e) => attach(e.target.files)}
                            />
                        </label>
                    </div>

                    {invoice.attachments.length === 0 ? (
                        <p className="text-[13px] text-[#9ca3af]">{t('لا مستندات مرفقة')}</p>
                    ) : (
                        <ul className="space-y-2 text-[13px]">
                            {invoice.attachments.map((a) => (
                                <li key={a.id} className="flex items-center gap-2">
                                    <Paperclip className="size-3.5 shrink-0 text-[#9ca3af]" />
                                    {/*
                                        ومرفقٌ لا يُقرأ يُقال موجودًا ولا يُبنى
                                        له رابط — لا يُكتم فيُظنّ غيرَ موجود.
                                    */}
                                    {a.url ? (
                                        <a href={a.url} target="_blank" rel="noreferrer" className="min-w-0 flex-1 truncate text-[#6d28d9] hover:underline">
                                            {a.name}
                                        </a>
                                    ) : (
                                        <span className="min-w-0 flex-1 truncate text-[#9ca3af]" title={t('فتحُ المرفقات صلاحيةٌ لا تملكها')}>
                                            {a.name}
                                        </span>
                                    )}
                                    <button
                                        type="button"
                                        aria-label={t('حذف المرفق')}
                                        className="shrink-0 text-[#b91c1c]"
                                        onClick={() => detach(a.id)}
                                    >
                                        <Trash2 className="size-4" />
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>

                {/* والملاحظةُ الداخليّة لا تُطبع ولا تصل العميل */}
                <Card className="p-4">
                    <h2 className="mb-1 font-bold text-[#111]">{t('ملاحظات داخلية')}</h2>
                    <p className="mb-3 text-[12px] text-[#9ca3af]">{t('لا تظهر للعميل ولا تُطبع.')}</p>
                    <p className="whitespace-pre-line text-[13px] text-[#4b4b4b]">
                        {invoice.internal_notes || t('لا ملاحظات داخلية')}
                    </p>
                </Card>
            </div>
        </AdminLayout>
    );
}

function Row({ label, value, strong }: { label: string; value: string; strong?: boolean }) {
    return (
        <div className={'flex justify-between ' + (strong ? 'font-bold' : '')}>
            <span className="text-[#71717a]">{label}</span>
            <span>{value}</span>
        </div>
    );
}

function PayForm({
    invoice,
    accounts,
    methods,
    bankMethods,
    onDone,
}: {
    invoice: Invoice;
    accounts: BankRow[];
    methods: string[];
    bankMethods: string[];
    onDone: () => void;
}) {
    const t = useTranslate();
    const form = useForm({
        customer_id: String(invoice.customer_id),
        customer_invoice_id: String(invoice.id),
        amount: String(invoice.outstanding),
        // وأوّلُ ما يقبله الخادم — لا اسمًا مكتوبًا هنا قد يسقط من قائمته
        method: methods[0] ?? '',
        // والرئيسيُّ أوّلُ القائمة — انظر `CustomerInvoiceController::bankAccounts`
        bank_account_id: accounts.length > 0 ? String(accounts[0].id) : '',
        occurred_at: '',
        external_reference: '',
    });

    /*
     * والحسابُ يُسأل عنه حين يدخل المالُ بنكًا — والجوابُ من الخادم.
     *
     * كان الشرطُ `!== 'نقدي'` — يصيب اليوم ويخطئ غدًا: وسيلةٌ نقديّةٌ ثانية
     * تُضاف في `CustomerPayments::sideFor` تسأل هنا عن حسابٍ بنكيٍّ لا يمرّ
     * به مالُها.
     */
    const needsAccount = bankMethods.includes(form.data.method);

    return (
        <Card className="mb-4 flex flex-wrap items-end gap-3 p-4">
            <label className="text-[13px]">
                {t('المبلغ')}
                <Input value={form.data.amount} onChange={(e) => form.setData('amount', e.target.value)} />
                {form.errors.amount && <p className="text-[12px] text-[#b91c1c]">{form.errors.amount}</p>}
            </label>
            <label className="text-[13px]">
                {t('وسيلة الدفع')}
                <select
                    className="mt-1 block rounded-[8px] border border-[var(--ui-border,#e8e8e8)] p-2"
                    value={form.data.method}
                    onChange={(e) => form.setData('method', e.target.value)}
                >
                    {/* والقائمةُ من الخادم — كانت مكتوبةً بيدها هنا وثالثةً في شاشة الإنشاء */}
                    {methods.map((x) => (
                        <option key={x} value={x}>{t(x)}</option>
                    ))}
                </select>
            </label>

            {/*
                وأيُّ حسابٍ استقبل المال — لا «بنك» وحدَها.

                كانت النافذةُ تسأل عن الوسيلة وتسكت عن الحساب، والخادمُ يقبل
                `bank_account_id` منذ كُتب. فكلُّ تحصيلٍ غيرِ نقديٍّ كان يُكتب
                بحسابٍ فارغ، ولا تجد له مطابقةُ كشف الحساب حسابًا تُسنده إليه.
            */}
            {needsAccount && accounts.length > 0 && (
                <label className="text-[13px]">
                    {t('الحساب البنكي')}
                    <select
                        className="mt-1 block rounded-[8px] border border-[var(--ui-border,#e8e8e8)] p-2"
                        value={form.data.bank_account_id}
                        onChange={(e) => form.setData('bank_account_id', e.target.value)}
                    >
                        {accounts.map((a) => (
                            <option key={a.id} value={String(a.id)}>
                                {a.is_primary ? `${a.name} — ${t('رئيسي')}` : a.name}
                            </option>
                        ))}
                    </select>
                    {form.errors.bank_account_id && (
                        <p className="text-[12px] text-[#b91c1c]">{form.errors.bank_account_id}</p>
                    )}
                </label>
            )}

            <label className="text-[13px]">
                {t('التاريخ')}
                <Input type="date" value={form.data.occurred_at} onChange={(e) => form.setData('occurred_at', e.target.value)} />
            </label>
            <Button
                disabled={form.processing}
                onClick={() => {
                    // وحسابٌ اختير ثمّ رُدَّت الوسيلةُ إلى النقد لا يُرسَل
                    form.transform((d) => ({ ...d, bank_account_id: needsAccount ? d.bank_account_id : '' }));
                    form.post('/admin/customer-payments', { preserveScroll: true, onSuccess: onDone });
                }}
            >
                {t('حفظ التحصيل')}
            </Button>
        </Card>
    );
}

/**
 * إشعارُ دائن — ما يُنقَص من ورقةٍ صدرت، بلا إعادة كتابتها.
 *
 * وورقةٌ سُلّمت لا تُعدَّل: الجهةُ تحمل نسختَها، ورقمُها في دفتر مشترياتها.
 * فالنقصُ يُكتب مستندًا ثانيًا يُقرأ بجوارها.
 *
 * والحصّةُ الضريبيّة تُقترح بنسبة الورقة نفسها لا تُترك صفرًا: إشعارٌ بلا
 * ضريبةٍ يُقيّد المبلغَ كلَّه مردودَ مبيعات، فتبقى ضريبةُ ما رُدّ مستحقّةً
 * على التاجر في الإقرار — يدفع عن بضاعةٍ رجعت إليه. وتبقى قابلةً للكتابة:
 * ما رُدّ قد يكون بندًا معفًى.
 */
function CreditNoteForm({ invoice, onDone }: { invoice: Invoice; onDone: () => void }) {
    const t = useTranslate();
    const { context } = usePage<PageProps>().props;
    const m = (v: number) => money(v, context!.currency);

    /* ولا يتجاوز الإشعارُ ما بقي من قيمة الورقة بعد إشعاراتٍ سبقته */
    const cap = round3(invoice.outstanding + invoice.paid);
    const share = invoice.total > 0 ? invoice.tax_total / invoice.total : 0;

    const form = useForm({
        amount: String(cap),
        tax_amount: String(round3(cap * share)),
        reason: '',
    });

    /* والضريبةُ تتبع المبلغ ما لم تُكتب بيد — فمن غيّرها يملكها */
    const [touched, setTouched] = useState(false);
    const setAmount = (value: string) => {
        const next = Number(value);
        form.setData((d) => ({
            ...d,
            amount: value,
            tax_amount: touched || !Number.isFinite(next) ? d.tax_amount : String(round3(next * share)),
        }));
    };

    return (
        <Card className="mb-4 flex flex-wrap items-end gap-3 p-4">
            <label className="text-[13px]">
                {t('المبلغ')}
                <Input value={form.data.amount} onChange={(e) => setAmount(e.target.value)} />
                <span className="mt-1 block text-[12px] text-[#9ca3af]">
                    {t('الحدّ الأعلى')}: {m(cap)}
                </span>
                {form.errors.amount && <p className="text-[12px] text-[#b91c1c]">{form.errors.amount}</p>}
            </label>
            <label className="text-[13px]">
                {t('منه ضريبة')}
                <Input
                    value={form.data.tax_amount}
                    onChange={(e) => {
                        setTouched(true);
                        form.setData('tax_amount', e.target.value);
                    }}
                />
                {form.errors.tax_amount && <p className="text-[12px] text-[#b91c1c]">{form.errors.tax_amount}</p>}
            </label>
            <label className="flex-1 text-[13px]">
                {t('السبب')}
                <Input
                    value={form.data.reason}
                    onChange={(e) => form.setData('reason', e.target.value)}
                    placeholder={t('مثال: رُدّت باقتان')}
                />
                {form.errors.reason && <p className="text-[12px] text-[#b91c1c]">{form.errors.reason}</p>}
            </label>
            <Button
                disabled={form.processing}
                onClick={() =>
                    form.post(`/admin/customer-invoices/${invoice.id}/credit-note`, {
                        preserveScroll: true,
                        onSuccess: onDone,
                    })
                }
            >
                {t('حفظ الإشعار')}
            </Button>
        </Card>
    );
}

/** ثلاثُ خاناتٍ — كما يُخزَّن المال في هذا النظام */
function round3(v: number): number {
    return Math.round(v * 1000) / 1000;
}
