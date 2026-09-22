import { useEffect, useMemo, useState } from 'react';
import { RotateCcw, Search, X } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input, Textarea } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { Addon, Product } from '@/types/models';
import type { CartAddon, CustomCart, CustomComponent } from '@/hooks/usePosCart';

/** خيارُ حقلٍ كما يصل من الخادم — مُترجَمٌ أصلًا، فالشاشةُ تعرض ولا تختار */
export interface PosFieldOption {
    id: number;
    label: string;
}

export interface PosField {
    id: number;
    label: string;
    type: string;
    required: boolean;
    internal: boolean;
    options: PosFieldOption[];
}

export interface PosTemplate {
    id: number;
    name: string;
    modes: string[];
    default_mode: string | null;
    base_label: string;
    allow_components: boolean;
    allow_addons: boolean;
    restockable_default: boolean;
    fields: PosField[];
}

/** أطولُ ما تقبله `items.*.note` في الخادم — والحقلُ يقف عنده لا بعده */
export const NOTE_MAX = 255;

/** أزرارُ الزيادة السريعة على السعر النهائيّ — بعملة المتجر */
export const PRICE_STEPS = [1, 5, 10];

interface Props {
    open: boolean;
    /** القالبُ المختار — حقولُه وإذنُه بالموادّ والإضافات يُقرأ منه */
    template: PosTemplate | null;
    /** الموادّ تُختار من أصناف المتجر — لا كتالوجَ ثانٍ ولا مخزونَ ثانٍ */
    products: Product[];
    addons: Addon[];
    money: (value: number) => string;
    /** يصل حين يُعدَّل بندٌ قائم في السلّة — فتعود الاختيارات كما تُركت */
    initial?: CustomCart | null;
    /** تفاصيلُ البند وإضافاتُه كما في السلّة — تعود معه عند التعديل */
    initialNote?: string | null;
    initialAddons?: CartAddon[] | null;
    onClose: () => void;
    onConfirm: (custom: CustomCart, addons: CartAddon[], note: string) => void;
}

const round3 = (n: number) => Math.round(n * 1000) / 1000;

/**
 * تخصيصُ الطلب — نافذةٌ واحدةٌ يكتب فيها الكاشير ما يريده الزبون ويقول السعر.
 *
 * ═══ ثلاثُ خطواتٍ لا أكثر ═══
 *
 *   ١ · التفاصيل — نصٌّ حرّ: ما قاله الزبون كما قاله. يُحفظ ملاحظةً على
 *       البند فيقرؤه المنسّق في لوحة التجهيز ويُطبع على الفاتورة.
 *   ٢ · الموادّ والإضافات — اختياريّة. من اختارها خُصمت من الرفّ وحُسبت
 *       تكلفتُها، ومن لم يخترها باع بالسعر وحدَه.
 *   ٣ · السعر النهائيّ — يُحسب من الموادّ والإضافات ويُعرض، ثمّ يزيده
 *       الكاشير بأزرارٍ أو يكتبه بيده. وما كُتب بيده لا تُعيد الشاشةُ
 *       حسابَه من تحته.
 *
 * ═══ ولمَ زالت «طريقة التسعير» من الشاشة ═══
 *
 * كان الكاشير يُسأل أوّلًا: «قيمة أساسية + إضافات» أم «سعر نهائي»؟ — سؤالٌ
 * محاسبيٌّ لا يعرف جوابَه من يقف أمام الزبون. والزبونُ يدفع رقمًا واحدًا.
 * فالشاشةُ تسأل عن هذا الرقم وحدَه، وتُخبر الخادمَ بالوضع الذي يقبله
 * القالب: «سعرٌ نهائيّ» إن أذن به، وإلّا «قيمةٌ أساسيّة» هي الرقمُ ناقصَ
 * الإضافات — فيدفع الزبون في الحالين ما قرأه على الشاشة.
 *
 * ═══ وما هنا عرضٌ لا حساب ═══
 *
 * السعرُ المحسوب اقتراحٌ من أسعار بيع الموادّ، والتكلفةُ تُعرض لتُعين على
 * التسعير و**تُحسب في الخادم** من `products.cost`. فما يُرسَل منها لا
 * يُقرأ، وما يُحفظ هو ما حسبه الخادم.
 *
 * ولا يُخصم من الرفّ شيءٌ ما دامت النافذةُ مفتوحة ولا ما دام البندُ في
 * السلّة: الخصمُ في `completeSale` وحدَه.
 */
export default function CustomArrangementDialog({
    open,
    template,
    products,
    addons,
    money,
    initial,
    initialNote,
    initialAddons,
    onClose,
    onConfirm,
}: Props) {
    const t = useTranslate();

    const [note, setNote] = useState('');
    /** إجاباتُ الحقول بمعرّفاتها — والشكلُ يتبع نوعَ الحقل */
    const [values, setValues] = useState<Record<number, unknown>>({});
    const [picked, setPicked] = useState<CustomComponent[]>([]);
    const [addonQty, setAddonQty] = useState<Record<number, number>>({});
    const [search, setSearch] = useState('');
    /** ما كتبه الكاشير في السعر — و`touched` تقول إن كان قد كتب أصلًا */
    const [priceText, setPriceText] = useState('');
    const [touched, setTouched] = useState(false);

    /**
     * هل فُتحت قائمةُ المخزون؟ — تُفتح بضغطةٍ على الحقل لا بالكتابة وحدَها.
     *
     * الكاشيرُ لا يحفظ أسماءَ الدلاء. وحقلُ بحثٍ لا يُظهر شيئًا حتى يُكتب فيه
     * يُلزمه أن يعرف ما يبحث عنه قبل أن يبحث.
     */
    const [browsing, setBrowsing] = useState(false);

    /** الإضافاتُ العامّة وحدَها: الطلبُ المخصَّص بلا منتجٍ يأذن بغيرها */
    const freeAddons = useMemo(
        () => (template?.allow_addons ? addons.filter((a) => a.active) : []),
        [addons, template],
    );

    /*
     * تُهيَّأ عند كلّ فتحة — لا مرّةً واحدة.
     *
     * بلا هذا يفتح الكاشير النافذةَ للطلب التالي فيجد موادَّ الطلب السابق
     * فيها، فيضيفها بلا انتباه: طلبٌ يخرج بموادّ طلبٍ آخر.
     *
     * والتعديلُ يعود بالسعر الذي كان يدفعه الزبون: في وضع «قيمة أساسيّة»
     * كان يدفع القيمةَ والإضافات، فيُعرض مجموعُهما لا القيمةُ وحدَها.
     */
    useEffect(() => {
        if (!open || !template) return;

        const chosen = Object.fromEntries((initialAddons ?? []).map((a) => [a.addon_id, a.qty]));
        const paidAddons = (initialAddons ?? []).reduce((s, a) => s + a.price * a.qty, 0);

        setNote(initialNote ?? '');
        setValues(Object.fromEntries((initial?.fields ?? []).map((f) => [f.field_id, f.value])));
        setPicked(initial?.components ?? []);
        setAddonQty(chosen);
        setSearch('');
        setBrowsing(false);
        setPriceText(initial ? String(round3(initial.mode === 'value' ? initial.price + paidAddons : initial.price)) : '');
        setTouched(initial != null);
    }, [open, initial, initialNote, initialAddons, template]);

    const addonsTotal = useMemo(
        () =>
            Object.entries(addonQty).reduce((sum, [id, qty]) => {
                const a = freeAddons.find((x) => x.id === Number(id));

                return sum + (a ? a.price * qty : 0);
            }, 0),
        [addonQty, freeAddons],
    );

    const unitPrice = (c: CustomComponent) => Number(products.find((x) => x.id === c.product_id)?.price) || 0;

    const materialCost = useMemo(
        () =>
            picked.reduce((sum, c) => {
                const p = products.find((x) => x.id === c.product_id);

                return sum + (p ? p.cost * c.quantity : 0);
            }, 0),
        [picked, products],
    );

    /*
     * ═══ السعرُ المحسوب والسعرُ النهائيّ ═══
     *
     * المحسوبُ من أسعار بيع الموادّ وأثمان الإضافات — يُعرض ليُبنى عليه.
     * والنهائيُّ هو المحسوبُ ما لم يلمسه الكاشير، وما كتبه إذا كتب.
     *
     * والخادمُ يقرأ النهائيَّ وحدَه، ولا يُعيد حسابَه من الموادّ: الموادُّ
     * للرفّ والتكلفة، والسعرُ للفاتورة — ولا يُستنبط أحدُهما من الآخر.
     */
    const suggested = round3(picked.reduce((s, c) => s + unitPrice(c) * c.quantity, 0) + addonsTotal);
    const final = touched ? round3(Number(priceText) || 0) : suggested;

    const setFinal = (n: number) => {
        setTouched(true);
        setPriceText(String(round3(Math.max(0, n))));
    };

    /*
     * ما يُعرض تحت الحقل: نتيجةُ البحث، أو المخزونُ كلُّه حين لا بحث.
     *
     * والمُرشِّحُ واحدٌ في الحالتين — `p.active` — لأنّه المُرشِّحُ نفسُه الذي
     * يطبّقه `CustomArrangement::components` في الخادم.
     */
    const results = useMemo(() => {
        const q = search.trim().toLowerCase();
        const stock = products.filter((p) => p.active);

        if (q === '') return stock.slice(0, 40);

        return stock
            .filter((p) => p.label.toLowerCase().includes(q) || p.sku?.toLowerCase().includes(q))
            .slice(0, 8);
    }, [search, products]);

    const addComponent = (p: Product) => {
        setPicked((prev) => {
            const at = prev.findIndex((c) => c.product_id === p.id);
            if (at >= 0) {
                const next = [...prev];
                next[at] = { ...next[at], quantity: next[at].quantity + 1 };

                return next;
            }

            /*
             * والسياسةُ تُكتب مع المادّة لا تُخمَّن يوم الإلغاء.
             *
             * افتراضُها من القالب — صاحبُ النشاط يعرف أتعود موادُّه أم لا —
             * ويُصحّحها الموظّف لهذه المادّة بعينها إن شاء.
             */
            return [...prev, { product_id: p.id, name: p.label, quantity: 1, restockable: template?.restockable_default ?? false }];
        });
        setSearch('');
    };

    const bump = (idx: number, delta: number) =>
        setPicked((prev) =>
            prev
                .map((c, i) => (i === idx ? { ...c, quantity: round3(c.quantity + delta) } : c))
                .filter((c) => c.quantity > 0),
        );

    const bumpAddon = (id: number, delta: number) =>
        setAddonQty((prev) => {
            const next = { ...prev };
            const v = (next[id] ?? 0) + delta;
            if (v <= 0) delete next[id];
            else next[id] = v;

            return next;
        });

    /** أجابَ الحقلُ أم لا — للإلزام، ولا يعرف النوعَ إلّا هنا */
    const answered = (f: PosField): boolean => {
        const v = values[f.id];
        if (f.type === 'checkbox') return v === true;
        if (f.type === 'select' || f.type === 'multi_select') return Array.isArray(v) && v.length > 0;

        return String(v ?? '').trim() !== '';
    };

    const missing = (template?.fields ?? []).find((f) => f.required && !answered(f));

    /*
     * الوضعُ الذي يُقال للخادم — من القالب لا من الشاشة.
     *
     * «سعرٌ نهائيّ» متى أذن به القالب: الرقمُ الذي قرأه الزبون هو ما يدفع،
     * والإضافاتُ داخلَه. وإلّا «قيمةٌ أساسيّة» هي الرقمُ ناقصَ الإضافات —
     * فيجمعهما الخادمُ إلى الرقم نفسِه.
     */
    const budget = template?.modes.includes('budget') ?? true;
    const sentPrice = budget ? final : round3(final - addonsTotal);

    /** ما يمنع الإضافة — يُقال، ولا يُترك الزرُّ معطَّلًا بلا سبب */
    const blocked = final <= 0
        ? t('أدخل السعر النهائي.')
        : sentPrice <= 0
            ? t('السعر النهائي لا يغطّي الإضافات.')
            : missing
                ? t('«:name» مطلوب.', { name: missing.label })
                : null;

    const confirm = () => {
        if (blocked || !template) return;

        onConfirm(
            {
                template_id: template.id,
                template_name: template.name,
                mode: budget ? 'budget' : 'value',
                price: sentPrice,
                base_value: budget ? null : sentPrice,
                fields: (template.fields ?? [])
                    .filter((f) => answered(f))
                    .map((f) => ({ field_id: f.id, value: values[f.id] })),
                components: picked,
                material_cost: round3(materialCost),
            },
            Object.entries(addonQty).map(([id, qty]) => {
                const a = freeAddons.find((x) => x.id === Number(id))!;

                return { addon_id: a.id, name: a.label, price: a.price, qty };
            }),
            note.trim(),
        );
    };

    if (!open || !template) return null;

    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            {/* السقفُ والتمرير كما في `ItemOptionsDialog` — لا شكلَ جديد */}
            <DialogContent className="flex max-w-lg flex-col">
                <DialogHeader className="shrink-0 px-5 pt-5">
                    {/* واسمُ القالب لا اسمٌ مكتوبٌ في الشاشة */}
                    <DialogTitle>{template.name}</DialogTitle>
                    <DialogDescription>{t('اكتب ما يريده الزبون، وأضف المواد إن شئت، ثم حدّد السعر النهائي')}</DialogDescription>
                </DialogHeader>

                <div className="min-h-0 flex-1 space-y-5 overflow-y-auto px-5 pb-4">
                    {/* ١ · التفاصيل — ما قاله الزبون كما قاله */}
                    <div>
                        <Label className="mb-1.5 block">{t('تفاصيل الطلب')}</Label>
                        <Textarea
                            value={note}
                            maxLength={NOTE_MAX}
                            onChange={(e) => setNote(e.target.value)}
                            placeholder={t('مثال: ٢٠ وردة حمراء، تغليف أسود، شريطة ذهبية')}
                            className="min-h-20"
                        />
                        <p className="mt-1 text-end text-[11px] tabular-nums text-gray-400">
                            {note.length}/{NOTE_MAX}
                        </p>
                    </div>

                    {/* ٢ · حقولُ القالب — الشاشةُ تعرف النوعَ ولا تعرف المعنى */}
                    {template.fields.map((f) => (
                        <div key={f.id}>
                            <Label className="mb-2 block" required={f.required}>
                                {f.label}
                            </Label>
                            <FieldControl
                                field={f}
                                value={values[f.id]}
                                onChange={(v) => setValues((prev) => ({ ...prev, [f.id]: v }))}
                            />
                            {f.internal && (
                                <p className="mt-1.5 text-xs text-gray-500">
                                    {t('تظهر في لوحة التجهيز ولا تظهر في الفاتورة')}
                                </p>
                            )}
                        </div>
                    ))}

                    {/* ٣ · الموادّ — من أصناف المتجر لا من قائمةٍ تُكتب، واختياريّة */}
                    {template.allow_components && (
                        <div>
                            <Label className="mb-2 block">
                                {t('المواد من المخزون')}
                                <span className="ms-1 text-xs font-normal text-gray-400">({t('اختياري')})</span>
                            </Label>
                            <div className="relative">
                                <Search className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-gray-400" />
                                <Input
                                    value={search}
                                    /*
                                     * الضغطةُ تُبدّل الحال: تفتح المغلقَ وتُغلق المفتوح.
                                     *
                                     * و`onClick` لا `onFocus`: الاثنان معًا يُلغي أحدُهما
                                     * الآخر — يفتحه التركيزُ ثمّ تُغلقه الضغطةُ نفسُها،
                                     * فلا تنكشف القائمةُ أبدًا.
                                     */
                                    onClick={() => setBrowsing((v) => !v)}
                                    // والكتابةُ تفتح دائمًا: من كتب يطلب جوابًا
                                    onChange={(e) => {
                                        setSearch(e.target.value);
                                        setBrowsing(true);
                                    }}
                                    placeholder={t('ابحث عن صنف من المخزون')}
                                    className="ps-9"
                                />
                            </div>

                            {browsing && results.length > 0 && (
                                <ul className="mt-2 max-h-56 space-y-1 overflow-y-auto rounded-[12px] border border-gray-100 p-2">
                                    {results.map((p) => (
                                        <li key={p.id} className="flex items-center justify-between gap-2">
                                            <span className="min-w-0 truncate text-sm text-gray-700">
                                                {p.label}
                                                <span className="ms-2 text-xs text-gray-400">
                                                    {t('المتوفر')}: {p.qty}
                                                </span>
                                                {Number(p.price) > 0 && (
                                                    <span className="ms-2 text-xs font-bold text-gray-500">{money(p.price)}</span>
                                                )}
                                            </span>
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                className="shrink-0"
                                                onClick={() => addComponent(p)}
                                            >
                                                {t('إضافة')}
                                            </Button>
                                        </li>
                                    ))}
                                </ul>
                            )}

                            {picked.length > 0 && (
                                <ul className="mt-3 space-y-2">
                                    {picked.map((c, idx) => (
                                        <li
                                            key={c.product_id}
                                            className="flex items-center justify-between gap-3 rounded-[12px] border border-gray-100 px-3 py-2"
                                        >
                                            <span className="min-w-0 text-sm text-gray-700">
                                                <span className="truncate">{c.name}</span>
                                                {/* ثمنُ الصفّ — ما يدخل في السعر المحسوب */}
                                                {unitPrice(c) > 0 && (
                                                    <span className="ms-2 text-xs tabular-nums text-gray-400" data-testid="component-price">
                                                        {money(round3(unitPrice(c) * c.quantity))}
                                                    </span>
                                                )}
                                                {/* ومقبضُ الإرجاع يقول حالَه، فلا يُقرأ الصفُّ ناقصًا */}
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        setPicked((prev) =>
                                                            prev.map((x, i) => (i === idx ? { ...x, restockable: !x.restockable } : x)),
                                                        )
                                                    }
                                                    className={cn(
                                                        'ms-2 inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] transition-colors',
                                                        c.restockable
                                                            ? 'bg-[#ecfdf5] text-[#047857]'
                                                            : 'bg-gray-100 text-gray-400',
                                                    )}
                                                    title={t('يعود للمخزون عند الإلغاء')}
                                                >
                                                    <RotateCcw className="size-3" />
                                                    {c.restockable ? t('يعود') : t('لا يعود')}
                                                </button>
                                            </span>
                                            <span className="flex shrink-0 items-center gap-2">
                                                <Button type="button" size="sm" variant="outline" onClick={() => bump(idx, -1)}>
                                                    −
                                                </Button>
                                                <span className="w-8 text-center text-sm font-bold tabular-nums">{c.quantity}</span>
                                                <Button type="button" size="sm" variant="outline" onClick={() => bump(idx, 1)}>
                                                    +
                                                </Button>
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() => setPicked((prev) => prev.filter((_, i) => i !== idx))}
                                                >
                                                    <X className="size-4" />
                                                </Button>
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    )}

                    {/* ٤ · الإضافات — من نظام الإضافات القائم */}
                    {freeAddons.length > 0 && (
                        <div>
                            <Label className="mb-2 block">{t('الإضافات')}</Label>
                            <ul className="space-y-2">
                                {freeAddons.map((a) => {
                                    const qty = addonQty[a.id] ?? 0;

                                    return (
                                        <li
                                            key={a.id}
                                            className="flex items-center justify-between gap-3 rounded-[12px] border border-gray-100 px-3 py-2"
                                        >
                                            <span className="min-w-0 text-sm text-gray-700">
                                                <span className="truncate">{a.label}</span>
                                                {/* ما تزيده على السعر المحسوب — والنهائيُّ بيد الكاشير */}
                                                <span className="ms-2 text-xs font-bold text-[#7c3aed]">
                                                    +{money(a.price)}
                                                </span>
                                            </span>
                                            <span className="flex shrink-0 items-center gap-2">
                                                <Button type="button" size="sm" variant="outline" onClick={() => bumpAddon(a.id, -1)}>
                                                    −
                                                </Button>
                                                <span className="w-6 text-center text-sm font-bold tabular-nums">{qty}</span>
                                                <Button type="button" size="sm" variant="outline" onClick={() => bumpAddon(a.id, 1)}>
                                                    +
                                                </Button>
                                            </span>
                                        </li>
                                    );
                                })}
                            </ul>
                        </div>
                    )}

                    {/* ٥ · السعر — يُحسب ويُعرض، ثمّ يزيده الكاشير أو يكتبه */}
                    <div className="rounded-[12px] bg-gray-50 p-3">
                        {suggested > 0 && (
                            <div className="mb-2 flex items-center justify-between text-xs text-gray-500">
                                <span>{t('المحسوب من المواد والإضافات')}</span>
                                <span className="tabular-nums" data-testid="suggested-price">{money(suggested)}</span>
                            </div>
                        )}
                        <Label className="mb-1.5 block" required>
                            {t('السعر النهائي')}
                        </Label>
                        <Input
                            type="number"
                            inputMode="decimal"
                            step="0.001"
                            min="0"
                            value={touched ? priceText : suggested > 0 ? String(suggested) : ''}
                            onChange={(e) => {
                                setTouched(true);
                                setPriceText(e.target.value);
                            }}
                            className="h-12 text-lg font-bold tabular-nums"
                            aria-label={t('السعر النهائي')}
                        />
                        <div className="mt-2 flex flex-wrap items-center gap-2">
                            {PRICE_STEPS.map((n) => (
                                <button
                                    key={n}
                                    type="button"
                                    onClick={() => setFinal(final + n)}
                                    className="rounded-full border border-gray-200 bg-white px-3 py-1 text-xs font-bold text-gray-700 transition-colors hover:bg-gray-100"
                                >
                                    +{n}
                                </button>
                            ))}
                            {/* وعودةٌ إلى المحسوب حين يكون ثمّ محسوبٌ وقد خرج الكاشير عنه */}
                            {touched && suggested > 0 && final !== suggested && (
                                <button
                                    type="button"
                                    onClick={() => setTouched(false)}
                                    className="rounded-full px-3 py-1 text-xs font-medium text-gray-500 underline-offset-2 hover:underline"
                                >
                                    {t('= المحسوب')}
                                </button>
                            )}
                        </div>
                        <div className="mt-3 flex items-center justify-between border-t border-gray-200 pt-2 text-sm font-bold text-gray-900">
                            <span>{t('يدفع الزبون')}</span>
                            <span className="tabular-nums" data-testid="final-price">{money(final)}</span>
                        </div>
                        {picked.length > 0 && (
                            <div className="mt-1 flex items-center justify-between text-xs text-gray-500">
                                <span>{t('تكلفة المواد')}</span>
                                <span className="tabular-nums">{money(materialCost)}</span>
                            </div>
                        )}
                    </div>
                </div>

                <div className="shrink-0 border-t border-gray-100 px-5 py-4">
                    {blocked && <p className="mb-2 text-xs text-[#b45309]">{blocked}</p>}
                    <Button type="button" className="w-full" disabled={blocked !== null} onClick={confirm}>
                        {t('إضافة للسلة')}
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}

/**
 * مقبضُ حقلٍ واحد بحسب نوعه — ستّةُ أنواعٍ لا أكثر.
 *
 * والشاشةُ لا تعرف ماذا يسأل الحقل: تعرف أنّه «اختيارٌ واحد» فترسم أزرارًا،
 * و«نصٌّ طويل» فترسم مربّعًا. والمعنى عند التاجر وحده.
 */
function FieldControl({
    field,
    value,
    onChange,
}: {
    field: PosField;
    value: unknown;
    onChange: (v: unknown) => void;
}) {
    const t = useTranslate();
    const chosen = Array.isArray(value) ? (value as number[]) : [];

    const chip = (on: boolean) =>
        cn(
            'rounded-[10px] border px-3 py-1.5 text-sm transition-colors',
            on ? 'border-gray-900 bg-gray-900 text-white' : 'border-gray-200 bg-white text-gray-700 hover:bg-gray-50',
        );

    if (field.type === 'select' || field.type === 'multi_select') {
        const single = field.type === 'select';

        return (
            <div className="flex flex-wrap gap-2">
                {field.options.map((o) => {
                    const on = chosen.includes(o.id);

                    return (
                        <button
                            key={o.id}
                            type="button"
                            onClick={() =>
                                onChange(
                                    single
                                        ? on ? [] : [o.id]
                                        : on ? chosen.filter((x) => x !== o.id) : [...chosen, o.id],
                                )
                            }
                            className={chip(on)}
                        >
                            {o.label}
                        </button>
                    );
                })}
            </div>
        );
    }

    if (field.type === 'checkbox') {
        return (
            <button type="button" onClick={() => onChange(value !== true)} className={chip(value === true)}>
                {value === true ? t('نعم') : t('لا')}
            </button>
        );
    }

    if (field.type === 'long_text') {
        return (
            <Textarea
                value={String(value ?? '')}
                onChange={(e) => onChange(e.target.value)}
                className="min-h-16"
            />
        );
    }

    return (
        <Input
            type={field.type === 'number' ? 'number' : 'text'}
            inputMode={field.type === 'number' ? 'decimal' : undefined}
            step={field.type === 'number' ? '0.001' : undefined}
            value={String(value ?? '')}
            onChange={(e) => onChange(e.target.value)}
        />
    );
}
