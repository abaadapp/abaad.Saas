import { type ComponentProps, type FocusEvent, type ReactNode, useMemo, useRef, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { AlertTriangle, Eye, FileMinus, FilePlus, Plus, PlusCircle, Replace, Save, Star, Trash2 } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import BackLink from '@/Components/BackLink';
import PageHeader from '@/Components/PageHeader';
import Field, { Select } from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input, Textarea } from '@/Components/ui/input';
import { csrfHeaders } from '@/lib/csrf';
import { money } from '@/lib/format';
import { invoiceTotals } from '@/lib/invoice-totals';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';
import { AddonDialog, ProductDialog } from './Create';

type Products = ComponentProps<typeof ProductDialog>['products'];
type Addons = ComponentProps<typeof AddonDialog>['addons'];

/**
 * بندٌ كما هو الآن — بمفتاحه (`CustomerInvoiceController::edit`).
 *
 * للمسودّة صفُّها (`i{id}`)، وللصادرة حالُها بعد آخر تصحيح: البندُ الذي أضافه
 * تصحيحٌ مفتاحُه من رقم ذلك التصحيح ولا صفَّ له — الفاتورةُ نفسُها كما صدرت.
 */
interface ItemRow {
    key: string;
    item_id: number | null;
    parent_key: string | null;
    product_id: number | null;
    addon_id: number | null;
    description: string;
    quantity: number;
    unit_price: number;
    discount: number;
    tax_rate: number;
    line_total: number;
    details: string[];
}

interface InvoiceProps {
    id: number;
    number: string | null;
    status: string;
    state: string;
    /** draft · issued · paid — ومنه الصلاحيةُ التي سُئلت */
    kind: 'draft' | 'issued' | 'paid';
    revision: number;
    from_order: boolean;
    orders: string[];
    customer_id: number;
    customer: string | null;
    issued_at: string | null;
    due_at: string | null;
    payment_terms_days: number | null;
    branch_id: number | null;
    po_number: string | null;
    contract_number: string | null;
    external_reference: string | null;
    department: string | null;
    cost_center: string | null;
    attention_to: string | null;
    notes: string | null;
    internal_notes: string | null;
    subtotal: number;
    discount_total: number;
    tax_total: number;
    total: number;
    /** إجماليُّ الفاتورة كما صدرت، وما عليها بعد مستندات تصحيحها */
    issued_total: number;
    effective_total: number;
    paid: number;
    credited: number;
    debited: number;
    outstanding: number;
    items: ItemRow[];
}

/**
 * بندٌ في النموذج — القائمُ بمفتاحه، والجديدُ بلا مفتاحٍ من الخادم.
 *
 * والقائمُ في الصادرة لا يتغيّر وصفُه ولا نسبةُ ضريبته (`locked_text`): من بيانات
 * الفاتورة المطبوعة، وتغييرُهما قرارٌ ضريبيّ. وإضافةُ الكتالوج القائمة (`locked`)
 * اسمُها وثمنُها ثمنُ بيعها لا ثمنُ اليوم.
 */
export interface EditLine {
    key: string;
    /** مفتاحُ البند عند الخادم — و`null` للجديد */
    server_key: string | null;
    item_id: number | null;
    parent_key: string | null;
    product_id: string;
    addon_id: string;
    description: string;
    quantity: string;
    unit_price: string;
    discount: string;
    tax_rate: string;
    locked: boolean;
    locked_text: boolean;
    details: string[];
}

/**
 * أعمدةُ البند من md فصاعدًا — رأسُها وصفوفُها بالقالب نفسِه.
 *
 * وتحتها كلُّ بندٍ بطاقةٌ بعمودين: البيانُ بعرضها، ثمّ الكمّيّةُ والسعر،
 * ثمّ الخصمُ والضريبة. كان جدولًا بعرضٍ أدنى 640 في تمريرٍ أفقيّ بلا
 * شريط، فيقع السعرُ — وهو ما يُصحَّح غالبًا — خارج شاشة الهاتف بلا ما
 * يدلّ عليه. والاتّجاهُ من الصفحة: العمودُ الأوّل يمينًا بالعربيّة ويسارًا
 * بالإنجليزيّة.
 */
const LINE_COLUMNS = 'md:grid-cols-[minmax(0,1fr)_6rem_7rem_6rem_5.5rem_2.5rem]';

/**
 * حقلُ بندٍ بعنوانه — ظاهرٌ فوقه على الهاتف، ولقارئ الشاشة وحده على الواسعة
 * حيث رأسُ العمود يقوله. وما تحت الحقل (`after`) خارجَ عنوانه.
 */
function LineField({ label, className, after, children }: { label: string; className?: string; after?: ReactNode; children: ReactNode }) {
    return (
        <div className={cn('min-w-0', className)}>
            <label className="block">
                <span className="mb-1 block text-[12px] text-[#71717a] md:sr-only">{label}</span>
                {children}
            </label>
            {after}
        </div>
    );
}

/** حقلٌ يُكتب فيه — فتنفتح له لوحةُ المفاتيح على الهاتف */
function opensKeyboard(el: EventTarget): boolean {
    if (el instanceof HTMLTextAreaElement) {
        return true;
    }

    return el instanceof HTMLInputElement && !['checkbox', 'radio', 'button', 'submit', 'hidden', 'range', 'file'].includes(el.type);
}

/** مستندُ تصحيحٍ سيصدر — من ردّ المعاينة */
interface PlanDocument {
    amount: number;
    tax: number;
    net: number;
    lines: { description: string; change: string; total: number; tax: number }[];
}

/** ما سيقع لو حُفظ — `CustomerInvoiceController::editPreview` */
interface Plan {
    changed: boolean;
    kind: string;
    changes: { field: string; label: string; before: string | null; after: string | null }[];
    lines: { change: 'added' | 'removed' | 'changed'; key: string | null; before: LineShown | null; after: LineShown | null }[];
    money: {
        total_before: number;
        total_after: number;
        tax_before: number;
        tax_after: number;
        difference: number;
        tax_difference: number;
        paid: number;
        credited: number;
        debited: number;
        outstanding_before: number;
        outstanding_after: number;
        customer_credit: number;
    };
    documents: { credit: PlanDocument | null; debit: PlanDocument | null };
    stock: { moves: boolean; why: string };
    period: { note_date: string | null; prior: boolean; closed_through: string | null; filed_through: string | null };
    needs_reason: boolean;
    needs_basis: boolean;
    missing: string[];
    blockers: string[];
}

interface LineShown {
    description: string;
    quantity: number;
    unit_price: number;
    discount: number;
    tax_rate: number | null;
}

let seq = 0;
const nextKey = () => `n${++seq}`;

/** البنودُ كما هي الآن — كلٌّ بمفتاحه */
export function fromItems(items: ItemRow[], correcting = false): EditLine[] {
    return items.map((i) => ({
        key: i.key,
        server_key: i.key,
        item_id: i.item_id,
        parent_key: i.parent_key,
        product_id: i.product_id ? String(i.product_id) : '',
        addon_id: i.addon_id ? String(i.addon_id) : '',
        description: i.description,
        quantity: String(i.quantity),
        unit_price: String(i.unit_price),
        discount: String(i.discount),
        tax_rate: String(i.tax_rate),
        locked: i.addon_id !== null,
        locked_text: correcting,
        details: i.details ?? [],
    }));
}

/**
 * حذفُ بندٍ ومعه إضافاتُه المدفوعةُ تحته.
 *
 * إضافةٌ بلا صنفها يردّها الخادم — فتُحذف معه هنا ولا يُترك من يضغط «احفظ»
 * أمام رسالة.
 */
export function removeLine(lines: EditLine[], key: string): EditLine[] {
    const gone = lines.find((l) => l.key === key);

    return lines.filter((l) => l.key !== key && !(gone?.server_key && l.parent_key === gone.server_key));
}

/** البنودُ كما يقرؤها الخادم — القائمُ بمفتاحه ومعرّفه، والجديدُ ببنده */
export function linesPayload(lines: EditLine[]) {
    return lines.map((l) => ({
        key: l.server_key,
        item_id: l.item_id,
        product_id: l.server_key ? null : l.product_id || null,
        addon_id: l.server_key ? null : l.addon_id || null,
        description: l.description,
        quantity: l.quantity,
        unit_price: l.unit_price,
        discount: l.discount || '0',
        tax_rate: l.tax_rate === '' ? null : l.tax_rate,
    }));
}

const TERMS = [0, 7, 15, 30, 45, 60, 90] as const;

function addDays(from: string, days: number): string {
    const d = new Date(from);
    d.setDate(d.getDate() + days);

    return d.toISOString().slice(0, 10);
}

/**
 * تعديلُ المسودّة وتصحيحُ الفاتورة الصادرة.
 *
 * ═══ المسودّة ═══
 *
 * تُكتب ثانيةً كما كُتبت: العميلُ والتواريخُ والبنودُ والمراجع.
 *
 * ═══ والصادرة ═══
 *
 * لا تُعاد كتابتُها: بياناتُها المطبوعة مقفلة، وتصحيحُ بنودها يُصدر إشعارًا دائنًا
 * لما نقص ومدينًا لما زاد — بسببٍ وأساسٍ مطلوبين. و«معاينة التصحيح» تسمّي
 * المستندين بمبالغهما وضريبتهما وما يبقى على العميل أو يصير له قبل أن يُحفظ.
 * وعميلٌ آخر طريقُه «إلغاء وإصدار بديلة» تحتها.
 *
 * والحسابُ هنا معاينةٌ لا مصدر: الخادمُ يعيد كلَّ رقمٍ تحت قفل الورقة.
 */
export default function CustomerInvoiceEdit() {
    const { invoice, customers, branches, products, addons, tax_rate, tax_rates, tax_inclusive, today, edit_token, bases, standing, may, context, errors } = usePage<
        PageProps<{
            invoice: InvoiceProps;
            customers: { id: number; name: string; phone: string | null; payment_terms_days: number | null }[];
            branches: { id: number; name: string; allowed: boolean }[];
            products: Products;
            addons: Addons;
            tax_rate: number;
            /** نسبُ بنود الصادرة — والبندُ الجديدُ بإحداها وحدها */
            tax_rates: number[] | null;
            /** قاعدةُ الورقة نفسِها — و`null` حين لا تُعرف من بنودها */
            tax_inclusive: boolean | null;
            today: string;
            edit_token: string;
            /** والإرجاعُ مقفلٌ بسببه: مسارٌ مستقلّ لم يُفتح (`CustomerInvoiceEdits::OPEN_BASES`) */
            bases: { value: string; label: string; disabled?: boolean }[];
            /** ما يمنع تصحيحَ المال الآن من حال الفاتورة — `CustomerInvoiceEdits::standing` */
            standing?: Record<string, string>;
            may: { correct_period: boolean; owner: boolean; replace?: boolean };
            errors: Record<string, string>;
        }>
    >().props;
    const t = useTranslate();
    const m = (v: number) => money(v, context!.currency);
    const label = invoice.number ?? `${t('مسودة')} #${invoice.id}`;
    const correcting = invoice.kind !== 'draft';
    const locked = invoice.from_order;
    const verb = correcting ? t('تصحيح خطأ في الفاتورة') : t('تعديل الفاتورة');

    const [head, setHead] = useState({
        customer_id: String(invoice.customer_id),
        issued_at: invoice.issued_at ?? today,
        due_at: invoice.due_at ?? '',
        payment_terms_days: invoice.payment_terms_days === null ? '' : String(invoice.payment_terms_days),
        branch_id: invoice.branch_id === null ? '' : String(invoice.branch_id),
        po_number: invoice.po_number ?? '',
        contract_number: invoice.contract_number ?? '',
        external_reference: invoice.external_reference ?? '',
        department: invoice.department ?? '',
        cost_center: invoice.cost_center ?? '',
        attention_to: invoice.attention_to ?? '',
        notes: invoice.notes ?? '',
        internal_notes: invoice.internal_notes ?? '',
    });
    const [lines, setLines] = useState<EditLine[]>(() => fromItems(invoice.items, correcting));
    const [reason, setReason] = useState('');
    const [basis, setBasis] = useState('');
    const [plan, setPlan] = useState<{ key: string; plan: Plan } | null>(null);
    const [planError, setPlanError] = useState<string | null>(null);
    const [planning, setPlanning] = useState(false);
    const [saving, setSaving] = useState(false);
    // ولوحةُ المفاتيح مفتوحةٌ ما دام حقلٌ يُكتب فيه — فيغيب الشريطُ السفليّ كي لا يغطّيه
    const [typing, setTyping] = useState(false);
    const planRef = useRef<HTMLDivElement>(null);
    const [picking, setPicking] = useState(false);
    const [pickingAddon, setPickingAddon] = useState(false);

    const set = (key: keyof typeof head, value: string) => setHead((h) => ({ ...h, [key]: value }));
    const patch = (key: string, next: Partial<EditLine>) =>
        setLines((prev) => prev.map((l) => (l.key === key ? { ...l, ...next } : l)));

    // والبندُ الجديدُ في الصادرة بنسبةٍ من نسبها — الأكثرُ فيها افتراضًا
    const newRate = String(tax_rate);
    const fresh = (over: Partial<EditLine>): EditLine => ({
        key: nextKey(), server_key: null, item_id: null, parent_key: null, product_id: '', addon_id: '', description: '',
        quantity: '1', unit_price: '0', discount: '0', tax_rate: newRate, locked: false, locked_text: false, details: [], ...over,
    });

    /*
     * وما يُرسَل للمعاينة هو ما يُرسَل للحفظ — حمولةٌ واحدة.
     *
     * والمولودةُ من طلبٍ لا تُرسَل بنودُها: غيابُها عند الخادم «كما هي».
     */
    const payload = useMemo(
        () => ({
            ...head,
            payment_terms_days: head.payment_terms_days === '' ? null : head.payment_terms_days,
            branch_id: head.branch_id === '' ? null : head.branch_id,
            due_at: head.due_at === '' ? null : head.due_at,
            items: locked ? null : linesPayload(lines),
            reason,
            basis: basis || null,
            expected_revision: invoice.revision,
            edit_token,
        }),
        [head, lines, reason, basis, locked, invoice.revision, edit_token],
    );

    // والمعاينةُ تخصّ النموذجَ الذي عوينت عليه — تغييرٌ بعدها يطلب معاينةً جديدة
    const planKey = JSON.stringify({ ...payload, reason: undefined });
    const current = plan && plan.key === planKey ? plan.plan : null;

    const inclusive = tax_inclusive ?? false;
    const totals = useMemo(() => invoiceTotals(lines, inclusive), [lines, inclusive]);

    /*
     * وعلى الهاتف واللوحيّ لوحةُ المعاينة تحت النموذج كلِّه — فيُنزَل إليها
     * بعد المعاينة، فيرى التاجرُ ما سيصدر قبل أن يعتمد. وعلى الواسعة هي
     * بجانب النموذج فلا يتحرّك شيء.
     */
    const showPlan = () => {
        const wide = typeof window.matchMedia === 'function' && window.matchMedia('(min-width: 1280px)').matches;

        if (!wide) {
            requestAnimationFrame(() => planRef.current?.scrollIntoView?.({ behavior: 'smooth', block: 'start' }));
        }
    };

    const preview = async () => {
        setPlanning(true);
        setPlanError(null);

        try {
            const res = await fetch(route('admin.customerInvoices.editPreview', invoice.id), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...csrfHeaders(),
                },
                credentials: 'same-origin',
                body: JSON.stringify(payload),
            });
            const body = await res.json();

            if (!res.ok) {
                const first = body?.errors ? Object.values(body.errors as Record<string, string[]>)[0]?.[0] : null;
                setPlanError(first ?? body?.message ?? t('تعذّرت المعاينة — أعد المحاولة.'));
                setPlan(null);

                return;
            }

            setPlan({ key: planKey, plan: body as Plan });
        } catch {
            setPlanError(t('تعذّرت المعاينة — أعد المحاولة.'));
        } finally {
            setPlanning(false);
            showPlan();
        }
    };

    const save = () => {
        setSaving(true);
        router.put(route('admin.customerInvoices.update', invoice.id), payload, {
            preserveScroll: true,
            onFinish: () => setSaving(false),
        });
    };

    const ready =
        current !== null &&
        current.changed &&
        current.blockers.length === 0 &&
        current.missing.length === 0 &&
        (!current.needs_reason || reason.trim() !== '') &&
        (!current.needs_basis || basis !== '');

    /* ما يقوله الشريطُ السفليّ في سطر — من ردّ المعاينة وحده كلوحتها */
    const barStatus = planning
        ? t('يُحسب أثر التعديل…')
        : planError
          ? planError
          : current === null
            ? plan !== null
                ? t('تغيّر النموذج بعد المعاينة — عاين من جديد.')
                : t('عاين قبل الاعتماد لترى ما سيصدر.')
            : current.blockers.length > 0 || current.missing.length > 0
              ? t('لا يُعتمد — انظر أثر التصحيح.')
              : !current.changed
                ? t('لم يتغيّر شيء في الفاتورة.')
                : !ready
                  ? t('أكمل أساس التصحيح وسببه.')
                  : current.documents.debit || current.documents.credit
                    ? [
                          current.documents.debit && `${t('إشعار مدين')} +${m(current.documents.debit.amount)}`,
                          current.documents.credit && `${t('إشعار دائن')} −${m(current.documents.credit.amount)}`,
                      ]
                          .filter(Boolean)
                          .join(' · ')
                    : `${t('الإجمالي')} ${m(current.money.total_before)} ← ${m(current.money.total_after)}`;

    const kindLabel: Record<string, string> = {
        draft: t('مسودة — لا قيد ولا ذمة ولا رقم ضريبي بعد'),
        issued: t('صادرة غير مدفوعة — تبقى كما صدرت، ويصدر مستند تصحيح'),
        paid: t('مدفوعة جزئيًا أو كليًا — المقبوض لا يُمس، ويصدر مستند تصحيح'),
    };

    const termOptions = TERMS.map((d) => ({ label: d === 0 ? t('مستحق فورًا') : `${d} ${d <= 10 ? t('أيام') : t('يومًا')}`, value: String(d) }));

    const errorList = Object.entries(errors ?? {}).filter(([k]) => !k.startsWith('items.'));
    const rates = (tax_rates ?? []).map((r) => ({ label: `${r}%`, value: String(r) }));

    return (
        <AdminLayout title={`${verb} ${label}`}>
            <BackLink
                routeName="admin.customerInvoices.show"
                href={route('admin.customerInvoices.show', invoice.id)}
                label={label}
            />

            <PageHeader
                title={`${verb} ${label}`}
                subtitle={`${invoice.state} — ${kindLabel[invoice.kind]}`}
                actions={
                    <>
                        <Button type="button" variant="outline" className="hidden xl:inline-flex" disabled={planning} onClick={preview} data-testid="edit-preview">
                            <Eye />
                            {correcting ? t('معاينة التصحيح') : t('معاينة التعديل')}
                        </Button>
                        <Button type="button" className="hidden xl:inline-flex" disabled={!ready || saving} onClick={save} data-testid="edit-save">
                            <Save />
                            {correcting ? t('اعتماد التصحيح') : t('حفظ التعديل')}
                        </Button>
                    </>
                }
            />

            {errorList.length > 0 && (
                <Card className="mb-4 border-[#fecaca] bg-[#fef2f2] p-3 text-[13px] text-[#b91c1c]" data-testid="edit-errors">
                    {errorList.map(([k, v]) => (
                        <p key={k}>{v}</p>
                    ))}
                </Card>
            )}

            {locked && (
                <Card className="mb-4 border-[#fde68a] bg-[#fffbeb] p-3 text-[13px] text-[#92400e]" data-testid="edit-from-order">
                    {t('هذه الفاتورة مولودة من طلب — بنودها ومبلغها وقيدها ومخزونها من الطلب. صحّح الطلب نفسه، ويُعدَّل هنا ما لا يُطبع.')}
                </Card>
            )}

            {correcting && (
                <Card className="mb-4 border-[#bfdbfe] bg-[#eff6ff] p-3 text-[13px] text-[#1e40af]" data-testid="edit-issued-note">
                    {t('الفاتورة الصادرة تبقى كما صدرت برقمها وتاريخها وبنودها. ما تصحّحه هنا يصدر بمستند مستقل: إشعار دائن لما نقص، وإشعار مدين لما زاد، بتاريخ اليوم وقيده.')}
                    {invoice.paid > 0 && (
                        <span className="mt-1 block" data-testid="edit-paid-note">
                            {t('دُفع منها :n. المقبوض لا يتغير: إن زاد المطلوب بقي الفرق على العميل، وإن نقص عما قُبض صار الفائض رصيدًا دائنًا له — ولا يُرد مال تلقائيًا.').replace(':n', m(invoice.paid))}
                        </span>
                    )}
                </Card>
            )}

            {/*
             * وما يمنع تصحيحَ المال الآن يُقال عند الفتح — لا بعد أن يصحّح الموظّفُ
             * البنودَ ويعاين. والمولودةُ من طلبٍ لها بطاقتُها أعلاه.
             */}
            {correcting && Object.entries(standing ?? {}).filter(([why]) => why !== 'order').length > 0 && (
                <Card className="mb-4 border-[#fecaca] bg-[#fef2f2] p-3 text-[13px] text-[#b91c1c]" data-testid="edit-standing">
                    <p className="mb-1 flex items-center gap-2 font-semibold">
                        <AlertTriangle className="size-4 shrink-0" />
                        {t('تصحيح المبلغ ممنوع الآن')}
                    </p>
                    {Object.entries(standing ?? {})
                        .filter(([why]) => why !== 'order')
                        .map(([why, text]) => (
                            <p key={why} data-testid={`edit-standing-${why}`}>
                                {text}
                            </p>
                        ))}
                </Card>
            )}

            <div
                className="grid grid-cols-1 gap-6 xl:grid-cols-5"
                onFocus={(e: FocusEvent) => setTyping(opensKeyboard(e.target))}
                onBlur={() => setTyping(false)}
            >
                <div className="min-w-0 space-y-4 xl:col-span-3">
                    {correcting ? (
                        <Card className="space-y-2 p-4 text-[13px]" data-testid="edit-printed">
                            <h2 className="font-bold text-[#111]">{t('بيانات الفاتورة كما صدرت')}</h2>
                            <dl className="grid grid-cols-1 gap-2 sm:grid-cols-3">
                                <Row label={t('العميل')} value={invoice.customer ?? '—'} />
                                <Row label={t('تاريخ الفاتورة')} value={invoice.issued_at ?? '—'} />
                                <Row label={t('إجمالي الفاتورة')} value={m(invoice.issued_total)} />
                            </dl>
                            <p className="text-[12px] text-[#6b7280]">
                                {t('العميل والتاريخ والمراجع والملاحظات المطبوعة لا تتغيّر بعد الإصدار. لعميل آخر استعمل «إلغاء وإصدار بديلة» أسفل الصفحة.')}
                            </p>
                        </Card>
                    ) : (
                        <Card className="space-y-4 p-4">
                            <h2 className="font-bold text-[#111]">{t('العميل والتواريخ')}</h2>
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <Field label="العميل" error={errors?.customer_id}>
                                    <Select
                                        id="customer"
                                        value={head.customer_id}
                                        disabled={locked}
                                        onChange={(e) => set('customer_id', e.target.value)}
                                        options={customers.map((c) => ({ label: c.phone ? `${c.name} — ${c.phone}` : c.name, value: String(c.id) }))}
                                    />
                                </Field>
                                <Field label="الفرع" error={errors?.branch_id}>
                                    <Select
                                        id="branch"
                                        value={head.branch_id}
                                        disabled={locked}
                                        onChange={(e) => set('branch_id', e.target.value)}
                                        placeholder="بلا فرع"
                                        options={branches.map((b) => ({ label: b.name, value: String(b.id), disabled: !b.allowed && String(b.id) !== head.branch_id }))}
                                    />
                                </Field>
                                <Field label="تاريخ الفاتورة" error={errors?.issued_at}>
                                    <Input type="date" value={head.issued_at} disabled={locked} onChange={(e) => set('issued_at', e.target.value)} data-testid="edit-issued-at" />
                                </Field>
                                <Field label="شروط الدفع (أيام)">
                                    <Select
                                        id="terms"
                                        value={head.payment_terms_days}
                                        onChange={(e) => {
                                            const v = e.target.value;
                                            setHead((h) => ({ ...h, payment_terms_days: v, due_at: v === '' ? h.due_at : addDays(h.issued_at || today, Number(v)) }));
                                        }}
                                        placeholder="بلا مدة"
                                        options={termOptions}
                                    />
                                </Field>
                                <Field label="تاريخ الاستحقاق" error={errors?.due_at}>
                                    <Input type="date" value={head.due_at} onChange={(e) => setHead((h) => ({ ...h, due_at: e.target.value, payment_terms_days: '' }))} />
                                </Field>
                            </div>
                        </Card>
                    )}

                    <Card className="p-4">
                        <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                            <h2 className="font-bold text-[#111]">{correcting ? t('البنود كما هي الآن') : t('البنود')}</h2>
                            {!locked && (
                                <div className="flex flex-wrap gap-2">
                                    <Button type="button" variant="outline" size="sm" data-testid="edit-add-line" onClick={() => setLines((prev) => [...prev, fresh({})])}>
                                        <Plus />
                                        {t('إضافة بند')}
                                    </Button>
                                    <Button type="button" variant="outline" size="sm" onClick={() => setPicking(true)}>
                                        <PlusCircle />
                                        {t('من الكتالوج')}
                                    </Button>
                                    <Button type="button" variant="outline" size="sm" onClick={() => setPickingAddon(true)}>
                                        <Star />
                                        {t('إضافة')}
                                    </Button>
                                </div>
                            )}
                        </div>
                        {errors?.items && <p className="mb-2 text-[12px] text-[#b91c1c]">{errors.items}</p>}

                        <div className={cn('hidden gap-x-2 px-2 pb-1 text-[12px] text-[#71717a] md:grid', LINE_COLUMNS)} aria-hidden="true">
                            <span>{t('البيان')}</span>
                            <span>{t('الكمية')}</span>
                            <span>{t('السعر')}</span>
                            <span>{t('الخصم')}</span>
                            <span>{t('الضريبة %')}</span>
                            <span />
                        </div>
                        <div className="space-y-3 text-[13px] md:space-y-0" data-testid="edit-lines">
                            {lines.map((l) => (
                                <div
                                    key={l.key}
                                    data-testid={l.server_key ? 'edit-line' : 'edit-line-new'}
                                    className={cn(
                                        'grid grid-cols-2 gap-x-3 gap-y-2 rounded-lg border border-[var(--ui-border,#e8e8e8)] p-3',
                                        'md:items-start md:gap-x-2 md:gap-y-0 md:rounded-none md:border-x-0 md:border-b-0 md:p-2',
                                        LINE_COLUMNS,
                                        l.parent_key && 'ms-4 md:ms-0',
                                    )}
                                >
                                    <LineField
                                        label={t('البيان')}
                                        className={cn('col-span-2 md:col-span-1', l.parent_key && 'md:ps-4')}
                                        after={
                                            <>
                                                {l.details.map((d, k) => (
                                                    <span key={k} className="mt-1 block text-[12px] text-[#6b7280]">
                                                        <bdi>{d}</bdi>
                                                    </span>
                                                ))}
                                                {l.locked && <span className="mt-1 block text-[11px] text-[#6b7280]">{t('إضافة من الكتالوج — اسمها وسعرها كما بيعت')}</span>}
                                            </>
                                        }
                                    >
                                        <Input
                                            value={l.description}
                                            disabled={locked || l.locked || l.locked_text}
                                            onChange={(e) => patch(l.key, { description: e.target.value })}
                                            placeholder={t('وصف البند')}
                                            data-testid="edit-description"
                                        />
                                    </LineField>
                                    <LineField label={t('الكمية')}>
                                        <Input type="number" inputMode="decimal" min="0" step="any" value={l.quantity} disabled={locked} onChange={(e) => patch(l.key, { quantity: e.target.value })} data-testid="edit-qty" />
                                    </LineField>
                                    <LineField label={t('السعر')}>
                                        <Input type="number" inputMode="decimal" min="0" step="any" value={l.unit_price} disabled={locked || l.locked} onChange={(e) => patch(l.key, { unit_price: e.target.value })} data-testid="edit-price" />
                                    </LineField>
                                    <LineField label={t('الخصم')}>
                                        <Input type="number" inputMode="decimal" min="0" step="any" value={l.discount} disabled={locked} onChange={(e) => patch(l.key, { discount: e.target.value })} data-testid="edit-discount" />
                                    </LineField>
                                    {correcting && !l.server_key && rates.length > 1 ? (
                                        <div className="min-w-0">
                                            <span className="mb-1 block text-[12px] text-[#71717a] md:sr-only">{t('الضريبة %')}</span>
                                            <Select id={`rate-${l.key}`} value={l.tax_rate} onChange={(e) => patch(l.key, { tax_rate: e.target.value })} options={rates} />
                                        </div>
                                    ) : (
                                        <LineField label={t('الضريبة %')}>
                                            <Input
                                                type="number"
                                                inputMode="decimal"
                                                min="0"
                                                max="100"
                                                step="any"
                                                value={l.tax_rate}
                                                disabled={locked || correcting}
                                                onChange={(e) => patch(l.key, { tax_rate: e.target.value })}
                                                data-testid="edit-tax"
                                            />
                                        </LineField>
                                    )}
                                    <div className="col-span-2 flex justify-end md:col-span-1 md:justify-center">
                                        {!locked && lines.length > 1 && (
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                className="text-[#b91c1c] md:size-9 md:p-0 md:text-inherit"
                                                aria-label={t('حذف البند')}
                                                onClick={() => setLines((prev) => removeLine(prev, l.key))}
                                            >
                                                <Trash2 />
                                                <span className="md:sr-only">{t('حذف البند')}</span>
                                            </Button>
                                        )}
                                    </div>
                                </div>
                            ))}
                        </div>

                        <dl className="mt-4 ms-auto max-w-xs space-y-1.5 border-t border-[var(--ui-border,#e8e8e8)] pt-4 text-[13px]">
                            <Row label={correcting ? t('البنود قبل التصحيح') : t('الإجمالي قبل التعديل')} value={m(invoice.total)} />
                            <Row label={t('المجموع الفرعي')} value={m(locked ? invoice.subtotal : totals.subtotal)} />
                            {(locked ? invoice.discount_total : totals.discount) > 0 && <Row label={t('الخصم')} value={m(locked ? invoice.discount_total : totals.discount)} />}
                            <Row label={t('الضريبة')} value={m(locked ? invoice.tax_total : totals.tax)} />
                            <Row label={correcting ? t('البنود بعد التصحيح') : t('الإجمالي بعد التعديل')} value={m(locked ? invoice.total : totals.total)} strong />
                        </dl>
                        {tax_inclusive === null && !locked && correcting && (
                            <p className="mt-2 text-[12px] text-[#b45309]">{t('تعذّر معرفة قاعدة ضريبة هذه الفاتورة من بنودها — تصحيح مالها مردود، ويُصحَّح بإشعار دائن بمبلغه.')}</p>
                        )}
                    </Card>

                    {correcting ? (
                        <Card className="space-y-3 p-4">
                            <h2 className="font-bold text-[#111]">{t('ملاحظات داخلية')}</h2>
                            <p className="text-[12px] text-[#6b7280]">{t('لا تُطبع على الفاتورة — فتُعدَّل في مكانها.')}</p>
                            <Textarea rows={2} value={head.internal_notes} onChange={(e) => set('internal_notes', e.target.value)} data-testid="edit-internal-notes" />
                        </Card>
                    ) : (
                        <Card className="space-y-3 p-4">
                            <h2 className="font-bold text-[#111]">{t('المراجع والملاحظات')}</h2>
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                {(
                                    [
                                        ['po_number', 'مرجع العميل (أمر الشراء)'],
                                        ['contract_number', 'رقم العقد'],
                                        ['external_reference', 'رقم المرجع'],
                                        ['department', 'القسم / الإدارة'],
                                        ['cost_center', 'مركز التكلفة'],
                                        ['attention_to', 'موجه إلى / عناية'],
                                    ] as const
                                ).map(([key, name]) => (
                                    <Field key={key} label={name} error={errors?.[key]}>
                                        <Input value={head[key]} onChange={(e) => set(key, e.target.value)} />
                                    </Field>
                                ))}
                            </div>
                            <Field label="ملاحظات للعميل" error={errors?.notes}>
                                <Textarea rows={3} value={head.notes} onChange={(e) => set('notes', e.target.value)} />
                            </Field>
                            <Field label="ملاحظات داخلية" error={errors?.internal_notes}>
                                <Textarea rows={2} value={head.internal_notes} onChange={(e) => set('internal_notes', e.target.value)} />
                            </Field>
                        </Card>
                    )}

                    {correcting && may.replace && <ReplaceCard invoiceId={invoice.id} customers={customers.filter((c) => c.id !== invoice.customer_id)} error={errors?.replace} />}
                </div>

                <div className="min-w-0 space-y-4 xl:col-span-2">
                    <Card className="space-y-3 p-4">
                        <h2 className="font-bold text-[#111]">{correcting ? t('أساس التصحيح وسببه') : t('سبب التعديل')}</h2>
                        {correcting && (
                            <Field label="أساس التصحيح" error={errors?.basis}>
                                <Select id="basis" value={basis} onChange={(e) => setBasis(e.target.value)} placeholder="اختر" options={bases} data-testid="edit-basis" />
                            </Field>
                        )}
                        <Textarea
                            rows={3}
                            maxLength={120}
                            value={reason}
                            onChange={(e) => setReason(e.target.value)}
                            placeholder={correcting ? t('يُطبع على مستند التصحيح ويُحفظ في السجل') : t('اختياري للمسودة')}
                            data-testid="edit-reason"
                        />
                        {errors?.reason && <p className="text-[12px] text-[#b91c1c]">{errors.reason}</p>}
                    </Card>

                    <div ref={planRef} className="scroll-mt-4">
                        <PlanPanel plan={current} stale={plan !== null && current === null} error={planError} planning={planning} mayCorrect={may.correct_period} m={m} />
                    </div>
                </div>
            </div>

            {/*
             * المعاينةُ والاعتمادُ تحت إصبع التاجر على الهاتف واللوحيّ.
             *
             * زرّا الرأس فوق النموذج، ولوحةُ المعاينة تحته كلِّه — فكان يصعد
             * إلى الزرّ وينزل إلى النتيجة ثمّ يصعد ليعتمد. والشريطُ يلزم أسفل
             * الشاشة ويقول في سطرٍ ما سيصدر، ويغيب ما دامت لوحةُ المفاتيح
             * مفتوحة كي لا يغطّي الحقل الذي يُكتب فيه.
             */}
            <div
                className={cn(
                    'sticky bottom-0 z-20 -mx-4 mt-4 border-t border-[var(--ui-border,#e8e8e8)] bg-white/95 px-4 pt-3 backdrop-blur lg:-mx-6 lg:px-6 xl:hidden',
                    'pb-[max(0.75rem,env(safe-area-inset-bottom))]',
                    typing && 'hidden',
                )}
                data-testid="edit-actions-bar"
            >
                <p className="mb-2 truncate text-[12px] text-[#4b4b4b]" data-testid="edit-actions-status">
                    {barStatus}
                </p>
                <div className="grid grid-cols-2 gap-2">
                    <Button type="button" variant="outline" disabled={planning} onClick={preview} data-testid="edit-preview-bar">
                        <Eye />
                        {correcting ? t('معاينة التصحيح') : t('معاينة التعديل')}
                    </Button>
                    <Button type="button" disabled={!ready || saving} onClick={save} data-testid="edit-save-bar">
                        <Save />
                        {correcting ? t('اعتماد التصحيح') : t('حفظ التعديل')}
                    </Button>
                </div>
            </div>

            <ProductDialog
                open={picking}
                onOpenChange={setPicking}
                products={products}
                truncated={false}
                picked={lines.map((l) => Number(l.product_id)).filter((n) => n > 0)}
                onPick={(p) => setLines((prev) => [...prev, fresh({ product_id: String(p.id), description: p.name, unit_price: String(p.price) })])}
            />
            <AddonDialog
                open={pickingAddon}
                onOpenChange={setPickingAddon}
                addons={addons}
                truncated={false}
                picked={lines.map((l) => Number(l.addon_id)).filter((n) => n > 0)}
                onPick={(a) => setLines((prev) => [...prev, fresh({ addon_id: String(a.id), description: a.name, unit_price: String(a.price) })])}
            />
        </AdminLayout>
    );
}

/**
 * «إلغاء وإصدار بديلة» — عميلٌ آخر لفاتورةٍ صدرت ولم يُقبض منها شيء.
 *
 * تُلغى هذه برقمها وتُكتب بديلتُها مسودّةً للعميل الصحيح (`CustomerInvoiceEdits::replace`).
 * والخادمُ يردّ ما عليه مالٌ أو إشعارٌ أو رابطٌ أو تجهيز، بسببه.
 */
function ReplaceCard({ invoiceId, customers, error }: { invoiceId: number; customers: { id: number; name: string; phone: string | null }[]; error?: string }) {
    const t = useTranslate();
    const [customer, setCustomer] = useState('');
    const [why, setWhy] = useState('');
    const [busy, setBusy] = useState(false);

    return (
        <Card className="space-y-3 p-4" data-testid="edit-replace">
            <h2 className="flex items-center gap-2 font-bold text-[#111]">
                <Replace className="size-4" />
                {t('إلغاء وإصدار بديلة لعميل آخر')}
            </h2>
            <p className="text-[12px] text-[#6b7280]">
                {t('لفاتورة لم يُقبض منها شيء: تُلغى برقمها ويُعكس قيدها اليوم، وتُكتب بديلتها مسودة للعميل الصحيح تراجعها ثم تصدرها برقم جديد.')}
            </p>
            <Field label="العميل الصحيح" error={error}>
                <Select id="replace-customer" value={customer} onChange={(e) => setCustomer(e.target.value)} placeholder="اختر" options={customers.map((c) => ({ label: c.phone ? `${c.name} — ${c.phone}` : c.name, value: String(c.id) }))} />
            </Field>
            <Input value={why} maxLength={120} onChange={(e) => setWhy(e.target.value)} placeholder={t('سبب الاستبدال')} data-testid="edit-replace-reason" />
            <Button
                type="button"
                variant="outline"
                disabled={customer === '' || why.trim() === '' || busy}
                data-testid="edit-replace-submit"
                onClick={() => {
                    setBusy(true);
                    router.post(route('admin.customerInvoices.replace', invoiceId), { customer_id: customer, reason: why }, { preserveScroll: true, onFinish: () => setBusy(false) });
                }}
            >
                {t('إلغاء الفاتورة وكتابة البديلة')}
            </Button>
        </Card>
    );
}

/**
 * ما سيقع لو حُفظ — المستنداتُ والفرقُ والذمّةُ والمخزونُ وما يمنع.
 *
 * يُرسم من ردّ الخادم وحده: لا يُحسب هنا أيُّ مستندٍ يصدر ولا ما يبقى على
 * العميل — حسابان في موضعين يفترقان يومًا.
 */
export function PlanPanel({
    plan,
    stale,
    error,
    planning,
    mayCorrect,
    m,
}: {
    plan: Plan | null;
    stale: boolean;
    error: string | null;
    planning: boolean;
    mayCorrect: boolean;
    m: (v: number) => string;
}) {
    const t = useTranslate();

    if (error) {
        return (
            <Card className="border-[#fecaca] bg-[#fef2f2] p-4 text-[13px] text-[#b91c1c]" data-testid="plan-error">
                {error}
            </Card>
        );
    }

    if (!plan) {
        return (
            <Card className="p-4 text-[13px] text-[#6b7280]" data-testid="plan-empty">
                {planning
                    ? t('يُحسب أثر التعديل…')
                    : stale
                      ? t('تغيّر النموذج بعد المعاينة — اضغط المعاينة من جديد قبل الحفظ.')
                      : t('اضغط المعاينة لترى المستندات التي ستصدر، والفرق في المال والضريبة، وأثره على المدفوعات، قبل الحفظ.')}
            </Card>
        );
    }

    const diff = (v: number) => (v > 0 ? `+${m(v)}` : m(v));
    const correcting = plan.kind !== 'draft';

    return (
        <Card className="space-y-4 p-4 text-[13px]" data-testid="plan">
            <h2 className="font-bold text-[#111]">{correcting ? t('أثر التصحيح') : t('أثر التعديل')}</h2>

            {plan.blockers.length > 0 && (
                <div className="space-y-1 rounded-lg border border-[#fecaca] bg-[#fef2f2] p-3 text-[#b91c1c]" data-testid="plan-blockers">
                    {plan.blockers.map((b, i) => (
                        <p key={i} className="flex gap-2">
                            <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                            <span>{b}</span>
                        </p>
                    ))}
                </div>
            )}
            {plan.missing.length > 0 && (
                <div className="rounded-lg border border-[#fecaca] bg-[#fef2f2] p-3 text-[#b91c1c]" data-testid="plan-missing">
                    {t('لا تملك صلاحية هذا التعديل:')} {plan.missing.join('، ')}
                    {!mayCorrect && plan.period.prior && <span className="block">{t('الفاتورة من فترة سابقة أو مقفلة.')}</span>}
                </div>
            )}
            {!plan.changed && <p className="text-[#6b7280]">{t('لم يتغيّر شيء في الفاتورة.')}</p>}

            {correcting && (plan.documents.credit || plan.documents.debit) && (
                <div className="space-y-2" data-testid="plan-documents">
                    <h3 className="font-semibold text-[#111]">{t('المستندات التي ستصدر')}</h3>
                    {plan.documents.debit && <DocumentRow kind="debit" doc={plan.documents.debit} date={plan.period.note_date} m={m} />}
                    {plan.documents.credit && <DocumentRow kind="credit" doc={plan.documents.credit} date={plan.period.note_date} m={m} />}
                    <p className="text-[12px] text-[#6b7280]">{t('والفاتورة الأصلية تبقى كما صدرت، وتُفتح وتُطبع وحدها.')}</p>
                </div>
            )}

            <dl className="space-y-1.5">
                <Row label={correcting ? t('المطلوب') : t('الإجمالي')} value={`${m(plan.money.total_before)} ← ${m(plan.money.total_after)}`} />
                <Row label={t('الفرق')} value={diff(plan.money.difference)} strong />
                <Row label={t('الضريبة')} value={`${m(plan.money.tax_before)} ← ${m(plan.money.tax_after)}`} />
                {plan.money.paid > 0 && <Row label={t('المقبوض — لا يتغير')} value={m(plan.money.paid)} />}
                {correcting && <Row label={t('الباقي على العميل')} value={`${m(plan.money.outstanding_before)} ← ${m(plan.money.outstanding_after)}`} />}
                {plan.money.customer_credit > 0 && <Row label={t('يصير رصيدًا دائنًا للعميل')} value={m(plan.money.customer_credit)} strong />}
            </dl>

            {plan.money.customer_credit > 0 && (
                <p className="rounded-lg bg-[#eff6ff] p-2 text-[12px] text-[#1e40af]" data-testid="plan-credit">
                    {t('لا يُرد مال تلقائيًا: الفائض رصيد للعميل يُخصم من فواتيره القادمة أو يُرد بقرار من يملك ذلك.')}
                </p>
            )}

            {(plan.changes.length > 0 || plan.lines.length > 0) && (
                <div>
                    <h3 className="mb-1 font-semibold text-[#111]">{t('ما تغيّر')}</h3>
                    <ul className="space-y-1 text-[12px]" data-testid="plan-changes">
                        {plan.changes.map((c) => (
                            <li key={c.field}>
                                <span className="font-medium">{c.label}:</span> <bdi>{c.before ?? '—'}</bdi> ← <bdi>{c.after ?? '—'}</bdi>
                            </li>
                        ))}
                        {plan.lines.map((l, i) => (
                            <li key={`l${i}`}>
                                {l.change === 'added' && <span className="text-[#047857]">+ {t('بند جديد')}: </span>}
                                {l.change === 'removed' && <span className="text-[#b91c1c]">− {t('حُذف')}: </span>}
                                {l.change === 'changed' && <span className="text-[#1d4ed8]">≈ {t('عُدّل')}: </span>}
                                <bdi>{(l.after ?? l.before)?.description}</bdi>
                                {l.before && l.after && (
                                    <span className="text-[#6b7280]">
                                        {' '}
                                        ({l.before.quantity} × {l.before.unit_price} ← {l.after.quantity} × {l.after.unit_price})
                                    </span>
                                )}
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            <div className="space-y-1 text-[12px] text-[#4b4b4b]">
                <p data-testid="plan-stock">
                    <span className="font-medium">{t('المخزون')}:</span> {plan.stock.why}
                </p>
                {correcting && (
                    <p data-testid="plan-ledger">
                        <span className="font-medium">{t('الدفتر')}:</span>{' '}
                        {plan.documents.credit || plan.documents.debit
                            ? t('يُقيَّد كل مستند بتاريخ :d، وأثره الضريبي في فترته. قيد الفاتورة الأصلي لا يُمس.').replace(':d', plan.period.note_date ?? '—')
                            : t('لا قيد يتغيّر.')}
                    </p>
                )}
                {plan.period.prior && (
                    <p className="text-[#92400e]" data-testid="plan-prior">
                        {t('الفاتورة من شهر مضى أو فترة مقفلة — يصدر المستند بتاريخ اليوم ويقع أثره في الفترة الحالية، ويحتاج صلاحية «تصحيح فاتورة عميل من فترة سابقة أو مقفلة».')}
                    </p>
                )}
                {plan.period.closed_through && (
                    <p>
                        <span className="font-medium">{t('الدفاتر مقفلة حتى')}:</span> <bdi>{plan.period.closed_through}</bdi>
                    </p>
                )}
            </div>
        </Card>
    );
}

function DocumentRow({ kind, doc, date, m }: { kind: 'credit' | 'debit'; doc: PlanDocument; date: string | null; m: (v: number) => string }) {
    const t = useTranslate();
    const Icon = kind === 'debit' ? FilePlus : FileMinus;

    return (
        <div className="rounded-lg border border-[var(--ui-border,#e8e8e8)] p-2" data-testid={`plan-doc-${kind}`}>
            <p className="flex items-center justify-between gap-2 font-medium">
                <span className="flex items-center gap-1.5">
                    <Icon className="size-4" />
                    {kind === 'debit' ? t('إشعار مدين — يزيد المطلوب') : t('إشعار دائن — ينقص المطلوب')}
                </span>
                <span dir="ltr" className="tabular-nums">
                    {kind === 'debit' ? '+' : '−'}
                    {m(doc.amount)}
                </span>
            </p>
            <p className="text-[12px] text-[#6b7280]">
                {t('قبل الضريبة')} <span dir="ltr">{m(doc.net)}</span> · {t('الضريبة')} <span dir="ltr">{m(doc.tax)}</span> · {t('بتاريخ')} <span dir="ltr">{date ?? '—'}</span>
            </p>
            <ul className="mt-1 space-y-0.5 text-[12px] text-[#4b4b4b]">
                {doc.lines.map((l, i) => (
                    <li key={i} className="flex justify-between gap-2">
                        <bdi>{l.description}</bdi>
                        <span dir="ltr" className="tabular-nums">
                            {m(l.total)}
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    );
}

function Row({ label, value, strong = false }: { label: string; value: string; strong?: boolean }) {
    return (
        <div className="flex items-center justify-between gap-3">
            <dt className="text-[#6b7280]">{label}</dt>
            <dd className={cn('tabular-nums', strong && 'font-bold text-[#111]')} dir="ltr">
                {value}
            </dd>
        </div>
    );
}
