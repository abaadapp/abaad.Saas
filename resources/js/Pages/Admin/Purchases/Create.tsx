import { useEffect, useMemo, useRef, useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { Check, ChevronDown, Package, Paperclip, Plus, Send, Sparkles, Trash2, Upload, UserPlus, X } from 'lucide-react';
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
import { currencyLabel, money } from '@/lib/format';
import { fold } from '@/lib/pages';
import { baseQuantity, lineTotal, purchaseTotals } from '@/lib/purchase-totals';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { Currency, PageProps } from '@/types';
import type { Branch, Product, Supplier } from '@/types/models';

interface ReorderRow {
    name: string;
    sku: string;
    suggested: number;
    cost: number;
}

interface Line {
    product_id: string;
    name: string;
    purchase_unit: string;
    units_per_purchase_unit: string;
    qty: string;
    cost: string;
}

interface Props {
    suppliers: Supplier[];
    products: Product[];
    /** وحداتُ الشراء: ما اشترى به المتجرُ أوّلًا، ثمّ المقترَحات — ورأسُها افتراضيُّ الحقل */
    units: string[];
    reorderSuggestions: ReorderRow[];
    branches: Branch[];
    currentBranchId: number | null;
    fromReorder: boolean;
    today: string;
    /** نسبةُ ضريبة المتجر — صفرٌ إن أُطفئت. والخادمُ يعيد قراءتها على كل حفظ */
    taxRate: number;
    /** مفتاحُ هذه الصفحة: ضغطتان عليه أمرٌ واحد لا أمران */
    formToken: string;
    /** مورّدٌ أُضيف من نافذة هذه الشاشة — يُختار فور العودة */
    newSupplierId: number | null;
}

/**
 * سطرٌ جديد — بوحدةِ شرائه مكتوبةً سلفًا.
 *
 * كان الحقلُ يفتح فارغًا فيُحفظ البندُ بلا وحدة، ثمّ يُقرأ في الأمر «—»:
 * كم صندوقًا طُلب وكم حبّة؟ لا أحدَ يعرف. والافتراضُ ما يشتري به المتجرُ
 * أكثرَ ما يشتري — لا كلمةً مكتوبةً في الشاشة تُخالف عادتَه.
 */
const blank = (unit: string): Line => ({
    product_id: '',
    name: '',
    purchase_unit: unit,
    units_per_purchase_unit: '1',
    qty: '1',
    cost: '',
});

/**
 * أمرُ شراءٍ جديد.
 *
 * ═══ وهو نيّةُ شراءٍ لا حدثٌ ماليّ ═══
 *
 * لا يزيد رصيدًا ولا يُنشئ ذمّةً ولا يكتب قيدًا. البضاعةُ تدخل الرفَّ باعتماد
 * الاستلام، والذمّةُ تنشأ باعتماد سند المورّد. وهذه الشاشةُ لا تعرض شيئًا
 * يوهم بغير ذلك — ولذلك رُفع «إيصال الدفع» منها: الدفعُ آخرُ الدورة لا أوّلُها.
 *
 * ═══ والحسابُ هنا معاينة ═══
 *
 * الخادمُ يعيد حساب كلّ رقم من البنود ومن إعدادات المتجر، ويُهمل ما تُرسله
 * الشاشة من إجماليّات. والصيغةُ واحدةٌ في الموضعين — `PurchaseOrderTotals`
 * و`lib/purchase-totals` — واختبارٌ يقابلهما رقمًا برقم.
 */
export default function PurchaseCreate() {
    const { suppliers, products, reorderSuggestions, branches, currentBranchId, fromReorder, today, taxRate, formToken, newSupplierId, units: knownUnits, context } =
        usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const currency = context!.currency;
    const m = (v: number) => money(v, currency);

    /*
     * وسطرٌ فارغٌ جاهزٌ عند الفتح لا صندوقٌ خالٍ.
     *
     * كانت الشاشة تفتح بلا صفٍّ البتّة: صندوقٌ يقول «لا توجد أصناف مضافة
     * بعد» وزرُّ الإضافة بعيدٌ في رأس البطاقة. فلا حقلَ سعرٍ يُرى أصلًا —
     * ومن جاء ليكتب سعرًا يبحث عنه في الملخّص حيث «قيمة الأصناف» رقمٌ محسوب
     * لا يُكتب فيه شيء. والسعرُ كان مفتوحًا طوال الوقت خلف ضغطةٍ لم تُرَ.
     *
     * وشاشةُ فاتورة العميل تفتح بسطرٍ كذلك — فلا تفترق شاشتا إنشاءٍ في
     * النظام الواحد.
     */
    /*
     * ووحدةٌ يكتبها التاجرُ هنا تبقى معه إلى آخر السطور.
     *
     * من اشترى «شتلة» في البند الأوّل يجدها في قائمة الثاني بلا إعادة كتابة،
     * ومن حفظ الأمر وجدها في انتظاره في الأمر التالي — لأنّها تُقرأ حينها
     * ممّا اشترى به فعلًا (انظر `PurchaseUnits`)، لا من قائمةٍ في الشاشة.
     */
    const [units, setUnits] = useState<string[]>(knownUnits);
    const addUnit = (unit: string) =>
        setUnits((prev) => (prev.includes(unit) ? prev : [unit, ...prev]));
    /** ما يفتح به السطرُ الجديد حقلَ وحدته — رأسُ القائمة */
    const defaultUnit = () => units[0] ?? '';

    const [lines, setLines] = useState<Line[]>(() =>
        fromReorder && reorderSuggestions.length
            ? reorderSuggestions.map((r) => {
                  const p = products.find((x) => x.sku === r.sku);

                  return {
                      ...blank(knownUnits[0] ?? ''),
                      product_id: p ? String(p.id) : '',
                      name: r.name,
                      cost: String(r.cost),
                      qty: String(r.suggested),
                  };
              })
            : [blank(knownUnits[0] ?? '')],
    );

    const form = useForm({
        supplier_id: '',
        branch_id: currentBranchId ? String(currentBranchId) : '',
        ordered_at: today,
        expected_delivery_at: '',
        supplier_reference: '',
        supplier_discount: '',
        shipping_cost: '',
        // ونسبةُ الضريبة تبدأ من نسبة المتجر ثمّ تتبع الورقة — انظر الملخّص
        tax_rate: String(taxRate),
        notes: '',
        attachment: null as File | null,
        form_token: formToken,
        draft: false,
    });

    const totals = useMemo(
        () =>
            purchaseTotals({
                items: lines.map((l) => ({ cost: l.cost, quantity: l.qty })),
                discount: form.data.supplier_discount,
                shipping: form.data.shipping_cost,
                taxRate: Number(form.data.tax_rate) || 0,
            }),
        [lines, form.data.supplier_discount, form.data.shipping_cost, form.data.tax_rate],
    );

    const [addingSupplier, setAddingSupplier] = useState(false);

    /*
     * ومورّدٌ أُضيف من النافذة يُختار وحدَه.
     *
     * الصفحةُ تُعاد بعد الحفظ ومعها القائمةُ الجديدة، وحالةُ النموذج محفوظة
     * — فلا يبقى إلّا أن يُقال أيُّهم. ومن لا يُختار له يظنّ الحفظَ لم يقع.
     */
    useEffect(() => {
        if (newSupplierId) {
            form.setData('supplier_id', String(newSupplierId));
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [newSupplierId]);

    const setLine = (i: number, patch: Partial<Line>) =>
        setLines((prev) => prev.map((l, x) => (x === i ? { ...l, ...patch } : l)));

    const addLine = () => setLines((prev) => [...prev, blank(defaultUnit())]);
    const dropLine = (i: number) => setLines((prev) => prev.filter((_, x) => x !== i));

    /** ملءُ الأصناف المقترَحة — من بيانات إعادة الطلب القائمة لا من اختراع */
    const suggest = () =>
        setLines((prev) => {
            const have = new Set(prev.map((l) => l.product_id).filter(Boolean));
            const added = reorderSuggestions
                .map((r) => ({ r, p: products.find((x) => x.sku === r.sku) }))
                .filter(({ p }) => p && !have.has(String(p.id)))
                .map(({ r, p }) => ({
                    ...blank(defaultUnit()),
                    product_id: String(p!.id),
                    name: r.name,
                    cost: String(r.cost),
                    qty: String(Math.max(1, Math.round(r.suggested))),
                }));

            return [...prev.filter((l) => l.product_id || l.name.trim()), ...added];
        });

    const submit = (draft: boolean) => {
        const items = lines
            .filter((l) => (Number(l.qty) || 0) > 0 && (l.product_id || l.name.trim()))
            .map((l) => ({
                product_id: l.product_id || null,
                name: l.name,
                purchase_unit: l.purchase_unit || null,
                units_per_purchase_unit: Number(l.units_per_purchase_unit) || 1,
                cost: Number(l.cost) || 0,
                quantity: Number(l.qty) || 0,
            }));

        /*
         * ولا تُرسل إجماليّات: الخادمُ يحسبها من البنود ومن إعدادات المتجر.
         * وما يُرسل منها يُهمل هناك على أيّ حال — فإرسالُه يوهم بأنّه يُقرأ.
         */
        form.transform((data) => ({ ...data, draft, items }));
        form.post(route('admin.purchases.store'), { forceFormData: true });
    };

    const err = (key: string) => (form.errors as Record<string, string | undefined>)[key];
    // ‏وأخطاءُ البنود تصل بمفاتيح `items.0.quantity` — تُجمع لتُعرض فوق الجدول
    const itemErrors = Object.entries(form.errors as Record<string, string>)
        .filter(([k]) => k.startsWith('items'))
        .map(([, v]) => v);

    return (
        <AdminLayout title="أمر شراء جديد">
            <BackLink
                routeName="admin.purchases.orders"
                href={route('admin.purchases.orders')}
                label="أوامر الشراء"
            />

            <PageHeader
                title="أمر شراء جديد"
                subtitle={t('إنشاء أمر شراء من مورد لتجهيز الأصناف وإدارة المخزون')}
            />

            <div className="grid grid-cols-1 gap-5 xl:grid-cols-12">
                {/* ═══════════ الجانب الأكبر: التفاصيل والأصناف ═══════════ */}
                <div className="space-y-5 xl:col-span-8">
                    <Card className="p-5">
                        <h2 className="mb-4 text-[15px] font-bold text-[#111]">{t('تفاصيل أمر الشراء')}</h2>

                        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                            {/*
                                والمورّدُ يُضاف من هنا لا من شاشةٍ أخرى.

                                كان يُقال «يُضافون من صفحة الموردين» — فمن كتب
                                خمسةَ أصنافٍ ثمّ اكتشف أنّ المورّد غيرُ مسجَّل
                                يخرج من الشاشة ويفقد ما كتب. والبابُ نفسُه
                                (`suppliers.store`) لا نسخةٌ ثانية منه.
                            */}
                            <Field label="المورد" required error={err('supplier_id')}>
                                {suppliers.length > 0 && (
                                    <Select
                                        value={form.data.supplier_id}
                                        onChange={(e) => form.setData('supplier_id', e.target.value)}
                                        placeholder={t('اختر المورد')}
                                        options={suppliers.map((s) => ({
                                            value: String(s.id),
                                            label: s.label ?? s.name,
                                        }))}
                                    />
                                )}

                                <button
                                    type="button"
                                    className="mt-1.5 flex items-center gap-1 text-[12px] font-medium text-[#6d28d9] hover:underline"
                                    onClick={() => setAddingSupplier(true)}
                                >
                                    <UserPlus className="size-3.5" />
                                    {suppliers.length === 0 ? t('لا موردين بعد — أضف الأول') : t('إضافة مورد جديد')}
                                </button>
                            </Field>

                            <Field label="الفرع" required error={err('branch_id')}>
                                <Select
                                    value={form.data.branch_id}
                                    onChange={(e) => form.setData('branch_id', e.target.value)}
                                    placeholder={t('اختر الفرع')}
                                    options={branches.map((b) => ({ value: String(b.id), label: b.name }))}
                                />
                            </Field>

                            <Field label="تاريخ الطلب" required error={err('ordered_at')}>
                                <Input
                                    type="date"
                                    value={form.data.ordered_at}
                                    onChange={(e) => form.setData('ordered_at', e.target.value)}
                                />
                            </Field>
                        </div>

                        <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
                            <Field
                                label="تاريخ الوصول المتوقع"
                                hint="وعدُ المورّد — لا تاريخُ وصولٍ وقع"
                                error={err('expected_delivery_at')}
                            >
                                <Input
                                    type="date"
                                    value={form.data.expected_delivery_at}
                                    min={form.data.ordered_at}
                                    onChange={(e) => form.setData('expected_delivery_at', e.target.value)}
                                />
                            </Field>

                            <Field label="رقم مرجع المورد" error={err('supplier_reference')}>
                                <Input
                                    value={form.data.supplier_reference}
                                    onChange={(e) => form.setData('supplier_reference', e.target.value)}
                                    placeholder={t('مثل رقم العرض أو الفاتورة')}
                                />
                            </Field>

                            {/*
                                وعملةُ الشراء تُعرض ولا تُختار.
                                المتجرُ بعملةٍ أساسٍ واحدة والدفترُ يقيّد بها،
                                فاختيارُ عملةٍ ثانية هنا يعني قيدًا لا يعرف
                                النظامُ كيف يحوّله — ورقمٌ يُعرض ولا يُحاسَب.
                            */}
                            <Field label="عملة الشراء" hint="عملةُ المتجر — تُضبط من الإعدادات">
                                <Input value={`${currencyLabel(currency)} — ${currency.name ?? ''}`} readOnly disabled />
                            </Field>
                        </div>
                    </Card>

                    {/* ═══════════ الأصناف ═══════════ */}
                    <Card className="p-5">
                        <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
                            <h2 className="text-[15px] font-bold text-[#111]">{t('الأصناف')}</h2>

                            <div className="flex flex-wrap items-center gap-2">
                                <Button type="button" variant="outline" size="sm" onClick={addLine}>
                                    <Plus />
                                    {t('إضافة صنف')}
                                </Button>
                                {/*
                                    والاقتراحُ من بيانات إعادة الطلب القائمة —
                                    ما نزل تحت حدّ التنبيه وكم يُقترح. ومن لا
                                    اقتراحَ له يراه مُعطَّلًا: زرٌّ لا يُدير شيئًا
                                    أسوأ من غياب الزرّ.
                                */}
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    disabled={reorderSuggestions.length === 0}
                                    title={
                                        reorderSuggestions.length === 0
                                            ? t('لا أصناف تحت حدّ التنبيه الآن')
                                            : undefined
                                    }
                                    onClick={suggest}
                                >
                                    <Sparkles />
                                    {t('اقتراح الكميات')}
                                </Button>
                            </div>
                        </div>

                        {itemErrors.length > 0 && (
                            <ul className="mb-3 space-y-1 rounded-[10px] bg-[#fef2f2] p-3 text-[12px] text-[#b91c1c]">
                                {itemErrors.map((e) => (
                                    <li key={e}>• {e}</li>
                                ))}
                            </ul>
                        )}

                        {lines.length === 0 ? (
                            <div className="rounded-[12px] border border-dashed border-[var(--ui-border,#e8e8e8)] py-14 text-center">
                                <Package className="mx-auto size-10 text-[#d4d4d8]" />
                                <p className="mt-3 text-[14px] font-medium text-[#4b4b4b]">
                                    {t('لا توجد أصناف مضافة بعد')}
                                </p>
                                <p className="mt-1 text-[12px] text-[#9ca3af]">
                                    {t('ابدأ بإضافة الأصناف إلى أمر الشراء')}
                                </p>
                                {/* والزرُّ حيث تقع العين لا في رأس البطاقة وحده */}
                                <Button type="button" variant="outline" size="sm" className="mt-3" onClick={addLine}>
                                    <Plus />
                                    {t('إضافة صنف')}
                                </Button>
                            </div>
                        ) : (
                            <ItemRows
                                lines={lines}
                                products={products}
                                units={units}
                                currency={currency}
                                onChange={setLine}
                                onRemove={dropLine}
                                onAddUnit={addUnit}
                            />
                        )}
                    </Card>

                    {/* ═══════════ الأزرار ═══════════ */}
                    <Card className="flex flex-wrap items-center justify-end gap-2 p-4">
                        {/*
                            و«إلغاء» وجهةٌ مكتوبةٌ باسمها لا `history.back()`.

                            الرجوعُ إلى ما كان قبلُ أيًّا كان: صفحةَ نموذجٍ
                            أُعيد التوجيه منها، أو موقعًا خارج النظام، أو لا
                            شيء إن فُتحت الصفحة من رابطٍ محفوظ.
                        */}
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => router.visit(route('admin.purchases.orders'))}
                        >
                            {t('إلغاء')}
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            disabled={form.processing}
                            onClick={() => submit(true)}
                        >
                            {t('حفظ كمسودة')}
                        </Button>
                        {/*
                            و«إصدار» لا «إرسال للمورّد».
                            لا مرسِلَ في النظام يبعث الأمر إلى المورّد — لا بريدًا
                            ولا واتساب. وزرٌّ يقول «أُرسل» عمّا لم يُرسَل يجعل
                            التاجر ينتظر ردًّا على رسالةٍ لم تخرج.
                        */}
                        <Button type="button" disabled={form.processing} onClick={() => submit(false)}>
                            <Send />
                            {t('إصدار أمر الشراء')}
                        </Button>
                    </Card>
                </div>

                {/* ═══════════ الجانب الأصغر: الملخّص والملاحظات والمرفق ═══════════ */}
                <div className="space-y-5 xl:col-span-4">
                    <Card className="p-5">
                        <h2 className="mb-4 text-[15px] font-bold text-[#111]">{t('ملخص أمر الشراء')}</h2>

                        <dl className="space-y-3 text-[13px]">
                            {/*
                                و«قيمة الأصناف» تقول من أين جاءت.

                                رقمٌ محسوبٌ بلا مصدرٍ مكتوب يجعل من أراد
                                تغييرَه يبحث عن صندوقٍ يكتب فيه هنا — والسعرُ
                                مفتوحٌ في جدول الأصناف لا هنا.
                            */}
                            <div className="flex items-start justify-between gap-3">
                                <div>
                                    <dt className="text-[#71717a]">{t('قيمة الأصناف')}</dt>
                                    <p className="mt-0.5 text-[11px] text-[#9ca3af]">
                                        {t('مجموع (تكلفة الوحدة × الكمية) — تُكتب في جدول الأصناف')}
                                    </p>
                                </div>
                                <dd className="tabular-nums font-medium text-[#111]">{m(totals.items_subtotal)}</dd>
                            </div>

                            <div className="flex items-center justify-between gap-3">
                                <Label htmlFor="po-discount">{t('خصم المورد')}</Label>
                                <Input
                                    id="po-discount"
                                    type="number"
                                    step="0.001"
                                    min="0"
                                    inputMode="decimal"
                                    className="max-w-[140px] text-end"
                                    placeholder="0.000"
                                    value={form.data.supplier_discount}
                                    onChange={(e) => form.setData('supplier_discount', e.target.value)}
                                />
                            </div>
                            {err('supplier_discount') && (
                                <p className="text-[12px] text-[#b91c1c]">{err('supplier_discount')}</p>
                            )}

                            <div className="flex items-center justify-between gap-3">
                                <Label htmlFor="po-shipping">{t('تكلفة الشحن')}</Label>
                                <Input
                                    id="po-shipping"
                                    type="number"
                                    step="0.001"
                                    min="0"
                                    inputMode="decimal"
                                    className="max-w-[140px] text-end"
                                    placeholder="0.000"
                                    value={form.data.shipping_cost}
                                    onChange={(e) => form.setData('shipping_cost', e.target.value)}
                                />
                            </div>

                            {/*
                                ═══ ونسبةُ الضريبة تُكتب على الورقة ═══

                                كانت تُقرأ من إعدادات المتجر جبرًا، والتعليقُ
                                هنا كان يقول إنّ اختيارها «يجعل الوعاء الضريبيّ
                                يتبع من يكتب الورقة». وذلك صحيحٌ في **البيع** —
                                نسبتُنا سياستُنا. أمّا في **الشراء** فالضريبةُ
                                ليست سياستَنا أصلًا: هي ما يفرضه المورّد، ومورّدٌ
                                غير مسجَّلٍ ضريبيًّا لا يفرض شيئًا.

                                وأثرُه تجاوز الورقة: `SupplierInvoices::match`
                                تقابل إجماليَّ السند بإجماليّ الأمر — فمتجرٌ
                                ضريبتُه مُطفأة يشتري ممّن يفرضها كان **يُمنع**
                                سندُه بمقدار الضريبة بالضبط.

                                وتبدأ من نسبة المتجر: من لا يعرف يترك ما كان.
                            */}
                            <div className="flex items-center justify-between gap-3">
                                <Label htmlFor="po-tax-rate">{t('الضريبة (%)')}</Label>
                                <div className="flex items-center gap-2">
                                    <Input
                                        id="po-tax-rate"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        max="100"
                                        inputMode="decimal"
                                        className="max-w-[90px] text-end"
                                        value={form.data.tax_rate}
                                        onChange={(e) => form.setData('tax_rate', e.target.value)}
                                    />
                                    <span className="min-w-[80px] text-end tabular-nums font-medium text-[#111]">
                                        {m(totals.tax)}
                                    </span>
                                </div>
                            </div>
                            {err('tax_rate') && <p className="text-[12px] text-[#b91c1c]">{err('tax_rate')}</p>}
                            {Number(form.data.tax_rate) !== taxRate && (
                                <p className="text-[11px] text-[#b45309]">
                                    {t('نسبة مختلفة عن نسبة المتجر')} ({taxRate}%)
                                </p>
                            )}

                            <div className="mt-2 flex items-center justify-between rounded-[10px] bg-[#f5f3ff] px-3 py-2.5">
                                <dt className="text-[14px] font-bold text-[#111]">{t('الإجمالي')}</dt>
                                <dd className="text-[16px] font-bold tabular-nums text-[#5b21b6]">
                                    {m(totals.total)}
                                </dd>
                            </div>
                        </dl>
                    </Card>

                    <Card className="p-5">
                        <h2 className="mb-3 text-[15px] font-bold text-[#111]">{t('ملاحظات على الأمر')}</h2>
                        <Textarea
                            rows={3}
                            value={form.data.notes}
                            onChange={(e) => form.setData('notes', e.target.value)}
                            placeholder={t('أضف أي ملاحظات داخلية حول أمر الشراء...')}
                        />
                    </Card>

                    <Card className="p-5">
                        <h2 className="mb-1 text-[15px] font-bold text-[#111]">{t('مرفقات أمر الشراء')}</h2>
                        {/*
                            و«إيصال الدفع» رُفع من هذه الشاشة.
                            أمرُ الشراء نيّةُ شراء، والدفعُ آخرُ الدورة: أمرٌ ←
                            استلامٌ ← اعتمادٌ ← سندُ مورّد ← اعتمادٌ ← سدادٌ ←
                            إثباتُه. وطلبُ إثبات الدفع في أوّلها يجعل التاجر
                            يدفع قبل أن يصل شيء.
                        */}
                        <p className="mb-3 text-[12px] text-[#9ca3af]">
                            {t('مثل عرض سعر المورد أو مستند الطلب')}
                        </p>
                        <AttachmentBox
                            file={form.data.attachment}
                            error={err('attachment')}
                            onPick={(f) => form.setData('attachment', f)}
                        />
                    </Card>
                </div>
            </div>

            <SupplierDialog open={addingSupplier} onOpenChange={setAddingSupplier} />
        </AdminLayout>
    );
}

/* ───────────────────────── قطعٌ صغيرة ───────────────────────── */

/**
 * مورّدٌ جديد من داخل شاشة أمر الشراء.
 *
 * ولا يُحال إلى شاشة الموردين: من كتب خمسةَ أصنافٍ ثمّ اكتشف أنّ المورّد
 * غيرُ مسجَّل كان يفقد ما كتب. والبابُ هو `suppliers.store` نفسُه — لا نسخةٌ
 * ثانية منه — والعودةُ إلى الصفحة نفسها، والمورّدُ الجديد يُختار وحده.
 *
 * والاسمُ وحدَه مطلوب: باقي الحقول تُكمَّل من شاشة الموردين متى شاء. ونموذجُ
 * تسجيلٍ كاملٌ في نافذةٍ منبثقة يجعل التاجر يخرج ليكمله فيفقد ورقتَه.
 */
function SupplierDialog({ open, onOpenChange }: { open: boolean; onOpenChange: (v: boolean) => void }) {
    const t = useTranslate();
    const form = useForm({ name: '', phone: '', contact_person: '' });

    const save = () =>
        form.post(route('admin.suppliers.store'), {
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
                    <DialogTitle>{t('مورد جديد')}</DialogTitle>
                    <DialogDescription>
                        {t('يُحفظ في قائمة الموردين ويُختار في هذا الأمر فورًا.')}
                    </DialogDescription>
                </DialogHeader>

                {/*
                    والحشوُ على الجسم لا على النافذة.

                    `DialogHeader` و`DialogFooter` يحملان `p-5` و`DialogContent`
                    لا حشوَ فيه — فجسمٌ يُكتب بلا `px-5 pb-5` تلتصق حقولُه
                    بحافّتَي النافذة. وهي القاعدةُ في كلّ نوافذ النظام.
                */}
                <div className="space-y-4 px-5 pb-5">
                    <Field label="اسم المورد" required error={form.errors.name}>
                        <Input
                            value={form.data.name}
                            onChange={(e) => form.setData('name', e.target.value)}
                            placeholder={t('مثل: مشتل الباطنة')}
                        />
                    </Field>
                    <Field label="الهاتف" error={form.errors.phone}>
                        <Input
                            value={form.data.phone}
                            onChange={(e) => form.setData('phone', e.target.value)}
                            placeholder="9xxxxxxx"
                        />
                    </Field>
                    <Field label="جهة الاتصال" error={form.errors.contact_person}>
                        <Input
                            value={form.data.contact_person}
                            onChange={(e) => form.setData('contact_person', e.target.value)}
                        />
                    </Field>
                </div>

                <DialogFooter>
                    <Button variant="outline" onClick={() => onOpenChange(false)}>
                        {t('إلغاء')}
                    </Button>
                    <Button disabled={form.processing || form.data.name.trim() === ''} onClick={save}>
                        {t('حفظ المورد')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/**
 * بنودُ الأمر — جدولٌ على الحاسوب وبطاقاتٌ على الجوّال.
 *
 * وستّةُ حقولٍ في صفٍّ واحد على شاشة هاتفٍ تصير أعمدةً بعرض إصبعين لا
 * تُقرأ ولا تُكتب. فتُقلب البطاقةُ رأسيًّا وتبقى الحقول بحجمها.
 */
export function ItemRows({
    lines,
    products,
    units,
    currency,
    onChange,
    onRemove,
    onAddUnit,
}: {
    lines: Line[];
    products: Product[];
    units: string[];
    currency: Currency;
    onChange: (i: number, patch: Partial<Line>) => void;
    onRemove: (i: number) => void;
    onAddUnit: (unit: string) => void;
}) {
    const t = useTranslate();
    const m = (v: number) => money(v, currency);

    /** اختيارُ منتجٍ يملأ اسمَه وتكلفتَه — والصنفُ غيرُ المسجّل يُكتب اسمُه */
    const pick = (i: number, id: string) => {
        const p = products.find((x) => String(x.id) === id);

        onChange(i, {
            product_id: id,
            name: p ? p.name : '',
            cost: p ? String(p.cost) : lines[i].cost,
        });
    };

    return (
        <div className="space-y-3">
            {/* رؤوسُ الأعمدة — على الحاسوب وحده */}
            <div className="hidden gap-2 px-1 text-[12px] text-[#71717a] lg:grid lg:grid-cols-[2fr_1fr_1fr_1fr_1fr_1fr_auto]">
                <span>{t('الصنف')}</span>
                <span>{t('وحدة الشراء')}</span>
                <span>{t('محتوى الوحدة')}</span>
                <span>{t('الكمية')}</span>
                <span>{t('تكلفة الوحدة')}</span>
                <span>{t('الإجمالي')}</span>
                <span className="w-8" />
            </div>

            {lines.map((line, i) => {
                const base = baseQuantity(line.qty, line.units_per_purchase_unit);
                const asBase = Number(line.units_per_purchase_unit) > 1;

                return (
                    <div
                        key={i}
                        className="grid grid-cols-1 gap-2 rounded-[12px] border border-[var(--ui-border,#e8e8e8)] p-3 sm:grid-cols-2 lg:grid-cols-[2fr_1fr_1fr_1fr_1fr_1fr_auto] lg:items-center lg:border-0 lg:p-1"
                    >
                        <div className="min-w-0">
                            <MobileLabel>{t('الصنف')}</MobileLabel>
                            <ProductPicker
                                value={line.product_id}
                                name={line.name}
                                products={products}
                                onPick={(id) => pick(i, id)}
                                onName={(name) => onChange(i, { name })}
                            />
                        </div>

                        <div>
                            <MobileLabel>{t('وحدة الشراء')}</MobileLabel>
                            <UnitPicker
                                value={line.purchase_unit}
                                units={units}
                                onPick={(u) => onChange(i, { purchase_unit: u })}
                                onAdd={onAddUnit}
                            />
                        </div>

                        <div>
                            <MobileLabel>{t('محتوى الوحدة')}</MobileLabel>
                            <Input
                                type="number"
                                min="0.001"
                                step="0.001"
                                inputMode="decimal"
                                value={line.units_per_purchase_unit}
                                onChange={(e) => onChange(i, { units_per_purchase_unit: e.target.value })}
                                aria-label={t('محتوى الوحدة')}
                            />
                        </div>

                        <div>
                            <MobileLabel>{t('الكمية')}</MobileLabel>
                            <Input
                                type="number"
                                min="1"
                                step="1"
                                inputMode="numeric"
                                value={line.qty}
                                onChange={(e) => onChange(i, { qty: e.target.value })}
                                aria-label={t('الكمية')}
                            />
                        </div>

                        <div>
                            <MobileLabel>{t('تكلفة الوحدة')}</MobileLabel>
                            <Input
                                type="number"
                                min="0"
                                step="0.001"
                                inputMode="decimal"
                                value={line.cost}
                                onChange={(e) => onChange(i, { cost: e.target.value })}
                                placeholder="0.000"
                                aria-label={t('تكلفة الوحدة')}
                            />
                        </div>

                        <div className="flex items-center justify-between lg:block">
                            <MobileLabel>{t('الإجمالي')}</MobileLabel>
                            <span className="tabular-nums font-medium text-[#111]">
                                {m(lineTotal(line.cost, line.qty))}
                            </span>
                        </div>

                        <div className="flex items-center justify-end">
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon-sm"
                                aria-label={t('حذف الصنف')}
                                onClick={() => onRemove(i)}
                            >
                                <Trash2 className="text-[#b91c1c]" />
                            </Button>
                        </div>

                        {/*
                            وما يدخل الرفَّ يُقال قبل الحفظ لا بعده.
                            خمسُ ربطاتٍ في العشرين مئةُ حبّة — ومن لا يراها
                            مكتوبةً يكتشفها في الجرد.
                        */}
                        {asBase && (
                            <p className="text-[11px] text-[#6b7280] sm:col-span-2 lg:col-span-7">
                                {t('يدخل المخزون: :n :unit', {
                                    n: base,
                                    unit: t('وحدة تخزين'),
                                })}
                            </p>
                        )}
                    </div>
                );
            })}
        </div>
    );
}

function MobileLabel({ children }: { children: React.ReactNode }) {
    return <span className="mb-1 block text-[11px] text-[#9ca3af] lg:hidden">{children}</span>;
}

/**
 * وحدةُ الشراء — قائمةٌ مرسومةٌ في الصفحة، وبابٌ لما ليس فيها.
 *
 * ═══ ولماذا لا `datalist` ═══
 *
 * كان الحقلُ نصًّا حرًّا بقائمةٍ يرسمها نظامُ التشغيل: نافذةٌ داكنةٌ ضيّقة
 * تطفو فوق الحقل فتحجبه، لا تحترم عرضَه ولا خطَّه ولا اتجاهَ الواجهة، ولا
 * سهمَ فيها يقول إنّ ثمّةَ قائمةً أصلًا — فمن لم يعرف أنّها هناك كتب بيده
 * أو ترك الحقلَ فارغًا. وهي القائمةُ نفسُها التي رُفعت من مرشّحات التقارير.
 *
 * ═══ والقائمةُ لا تحدّ ═══
 *
 * ما يُكتب ولا يُطابق شيئًا يُعرض زرًّا يقول «أضف "كذا"» — فتُضاف الوحدةُ
 * إلى قائمة السطور كلِّها في هذه الشاشة، وتعود في الأمر التالي لأنّ القائمة
 * تُقرأ ممّا اشترى به المتجرُ فعلًا. ووحدةٌ تُكتب مرّتين بيدٍ تصير وحدتين.
 */
export function UnitPicker({
    value,
    units,
    onPick,
    onAdd,
}: {
    value: string;
    units: string[];
    onPick: (unit: string) => void;
    onAdd: (unit: string) => void;
}) {
    const t = useTranslate();
    const [open, setOpen] = useState(false);
    const [q, setQ] = useState('');
    const box = useRef<HTMLDivElement>(null);
    const search = useRef<HTMLInputElement>(null);

    useEffect(() => {
        const away = (e: MouseEvent) => {
            if (box.current && !box.current.contains(e.target as Node)) setOpen(false);
        };
        document.addEventListener('mousedown', away);

        return () => document.removeEventListener('mousedown', away);
    }, []);

    // ‏والمطابقة تُهمل الهمزةَ وشكلَ الرقم — انظر `fold`
    const typed = q.trim();
    const hits = useMemo(() => {
        const needle = fold(typed);

        return needle === '' ? units : units.filter((u) => fold(u).includes(needle));
    }, [units, typed]);

    const exact = hits.some((u) => fold(u) === fold(typed));

    // ‏وما يصلها مقلَّمٌ سلفًا: `typed` تقصّ المسافات، وما في القائمة نظيفٌ من مصدره
    const commit = (unit: string) => {
        onAdd(unit);
        onPick(unit);
        setQ('');
        setOpen(false);
    };

    return (
        <div ref={box} className="relative">
            <button
                type="button"
                aria-label={t('وحدة الشراء')}
                aria-expanded={open}
                onClick={() => {
                    setOpen((v) => !v);
                    setQ('');
                    // ‏والكتابةُ تبدأ فورًا: من فتحها ليكتب وحدةً جديدة لا يبحث عن الحقل
                    window.setTimeout(() => search.current?.focus(), 0);
                }}
                className={cn(
                    'flex h-10 w-full items-center justify-between gap-2 rounded-[10px] pointer-coarse:h-11',
                    'border border-[var(--ui-border,#e8e8e8)] bg-white px-3 text-start text-sm text-[#111]',
                    'transition-[border-color,box-shadow] outline-none',
                    'focus:border-[#d1d5db] focus:shadow-[0_0_0_3px_rgba(0,0,0,0.05)]',
                )}
            >
                <span className={cn('min-w-0 truncate', value === '' && 'text-[#9ca3af]')}>
                    {value === '' ? t('اختر الوحدة') : value}
                </span>
                <ChevronDown className="size-4 shrink-0 text-[#6b7280]" />
            </button>

            {open && (
                <div className="absolute z-20 mt-1 w-full min-w-[11rem] rounded-[10px] border border-[var(--ui-border,#e8e8e8)] bg-white p-1 shadow-lg">
                    <Input
                        ref={search}
                        value={q}
                        onChange={(e) => setQ(e.target.value)}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') {
                                e.preventDefault();
                                // ‏وحقلُ بحثٍ فارغٌ لا يختار شيئًا: يُغلق ويترك الوحدةَ كما هي
                                if (typed === '') setOpen(false);
                                else commit(hits[0] ?? typed);
                            }

                            if (e.key === 'Escape') {
                                e.preventDefault();
                                setOpen(false);
                            }
                        }}
                        placeholder={t('ابحث أو اكتب وحدة جديدة')}
                        aria-label={t('ابحث أو اكتب وحدة جديدة')}
                        className="h-9 text-[13px]"
                    />

                    <div className="mt-1 max-h-48 overflow-y-auto">
                        {hits.map((u) => (
                            <button
                                key={u}
                                type="button"
                                className="flex w-full items-center justify-between gap-2 rounded-[8px] px-2 py-1.5 text-start text-[13px] hover:bg-[#f7f7f5]"
                                onClick={() => commit(u)}
                            >
                                <span className="min-w-0 truncate">{u}</span>
                                {u === value && <Check className="size-4 shrink-0 text-[#5b21b6]" />}
                            </button>
                        ))}
                    </div>

                    {typed !== '' && !exact && (
                        <button
                            type="button"
                            className="mt-1 flex w-full items-center gap-1.5 rounded-[8px] border-t border-[var(--ui-border,#e8e8e8)] px-2 py-2 text-start text-[13px] text-[#5b21b6]"
                            onClick={() => commit(typed)}
                        >
                            <Plus className="size-4 shrink-0" />
                            <span className="min-w-0 truncate">{t('أضف «:unit»', { unit: typed })}</span>
                        </button>
                    )}
                </div>
            )}
        </div>
    );
}

/**
 * الصنف: من الكتالوج أو غيرُ مسجَّل.
 *
 * والثاني يبقى: شحنُ مورّدٍ يُفوتَر ولا يُخزَّن، وخدمةٌ تُشترى — كلاهما بندٌ
 * في الأمر بلا صنفٍ في المخزون. ومنعُه يدفع التاجر إلى اختراع أصنافٍ وهميّة.
 */
function ProductPicker({
    value,
    name,
    products,
    onPick,
    onName,
}: {
    value: string;
    name: string;
    products: Product[];
    onPick: (id: string) => void;
    onName: (name: string) => void;
}) {
    const t = useTranslate();
    const [manual, setManual] = useState(false);
    const [q, setQ] = useState('');
    const [open, setOpen] = useState(false);
    const box = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const away = (e: MouseEvent) => {
            if (box.current && !box.current.contains(e.target as Node)) setOpen(false);
        };
        document.addEventListener('mousedown', away);

        return () => document.removeEventListener('mousedown', away);
    }, []);

    const picked = products.find((p) => String(p.id) === value);

    // ‏والمطابقة تُهمل الهمزةَ وشكلَ الرقم وتقرأ الرمز — انظر `fold`
    const hits = useMemo(() => {
        const needle = fold(q);

        if (needle === '') return products.slice(0, 20);

        return products
            .filter((p) => fold(p.name).includes(needle) || fold(p.sku ?? '').includes(needle))
            .slice(0, 20);
    }, [products, q]);

    if (manual || (!picked && name)) {
        return (
            <div className="flex items-center gap-1">
                <Input
                    value={name}
                    onChange={(e) => onName(e.target.value)}
                    placeholder={t('اسم الصنف غير المسجل')}
                    aria-label={t('اسم الصنف غير المسجل')}
                />
                <Button
                    type="button"
                    variant="ghost"
                    size="icon-sm"
                    aria-label={t('العودة إلى الكتالوج')}
                    onClick={() => {
                        setManual(false);
                        onName('');
                    }}
                >
                    <X />
                </Button>
            </div>
        );
    }

    return (
        <div ref={box} className="relative">
            <Input
                value={picked ? picked.name : q}
                onChange={(e) => {
                    setQ(e.target.value);
                    setOpen(true);
                    if (picked) onPick('');
                }}
                onFocus={() => setOpen(true)}
                placeholder={t('ابحث عن منتج...')}
                aria-label={t('الصنف')}
            />

            {open && (
                <div className="absolute z-20 mt-1 max-h-56 w-full overflow-y-auto rounded-[10px] border border-[var(--ui-border,#e8e8e8)] bg-white p-1 shadow-lg">
                    {hits.map((p) => (
                        <button
                            key={p.id}
                            type="button"
                            className="flex w-full items-center justify-between gap-2 rounded-[8px] px-2 py-1.5 text-start text-[13px] hover:bg-[#f7f7f5]"
                            onClick={() => {
                                onPick(String(p.id));
                                setQ('');
                                setOpen(false);
                            }}
                        >
                            <span className="min-w-0 truncate">{p.name}</span>
                            {p.sku && (
                                <span dir="ltr" className="shrink-0 font-mono text-[11px] text-[#9ca3af]">
                                    {p.sku}
                                </span>
                            )}
                        </button>
                    ))}

                    <button
                        type="button"
                        className="mt-1 flex w-full items-center gap-1.5 rounded-[8px] border-t border-[var(--ui-border,#e8e8e8)] px-2 py-2 text-start text-[13px] text-[#5b21b6]"
                        onClick={() => {
                            setManual(true);
                            setOpen(false);
                            onPick('');
                        }}
                    >
                        <Plus className="size-4" />
                        {t('صنف غير مسجل')}
                    </button>
                </div>
            )}
        </div>
    );
}

/** مرفقُ الأمر — ملفٌّ واحد يكفي: عرضُ سعرٍ أو مستندُ طلب */
function AttachmentBox({
    file,
    error,
    onPick,
}: {
    file: File | null;
    error?: string;
    onPick: (f: File | null) => void;
}) {
    const t = useTranslate();
    const input = useRef<HTMLInputElement>(null);

    return (
        <div className="rounded-[12px] border border-dashed border-[var(--ui-border,#e8e8e8)] p-4 text-center">
            {file ? (
                <div className="flex items-center justify-between gap-2 text-start">
                    <span className="flex min-w-0 items-center gap-2 text-[13px] text-[#4b4b4b]">
                        <Paperclip className="size-4 shrink-0 text-[#9ca3af]" />
                        <span className="truncate">{file.name}</span>
                    </span>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon-sm"
                        aria-label={t('إزالة المرفق')}
                        onClick={() => {
                            onPick(null);
                            if (input.current) input.current.value = '';
                        }}
                    >
                        <X />
                    </Button>
                </div>
            ) : (
                <>
                    <Upload className="mx-auto size-6 text-[#a78bfa]" />
                    <p className="mt-2 text-[13px] font-medium text-[#4b4b4b]">{t('رفع ملف')}</p>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="mt-2"
                        onClick={() => input.current?.click()}
                    >
                        {t('اختيار ملف')}
                    </Button>
                </>
            )}

            <input
                ref={input}
                type="file"
                hidden
                aria-label={t('مرفق أمر الشراء')}
                accept=".jpg,.jpeg,.png,.pdf,.webp,.heic"
                onChange={(e) => onPick(e.target.files?.[0] ?? null)}
            />

            {error && <p className="mt-2 text-[12px] text-[#b91c1c]">{error}</p>}

            <p className="mt-3 text-[11px] text-[#9ca3af]">
                {t('الأنواع: JPG، PNG، PDF، WEBP، HEIC — والحد الأقصى 10 ميجابايت')}
            </p>
        </div>
    );
}
