import { useEffect, useMemo, useState } from 'react';
import { RotateCcw, Search, X } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input, Textarea } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { Addon, Product } from '@/types/models';
import type { CustomCart, CustomComponent } from '@/hooks/usePosCart';

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

interface Props {
    open: boolean;
    /** القالبُ المختار — شكلُ هذه النافذة كلِّه يُقرأ منه */
    template: PosTemplate | null;
    /** الموادّ تُختار من أصناف المتجر — لا كتالوجَ ثانٍ ولا مخزونَ ثانٍ */
    products: Product[];
    addons: Addon[];
    money: (value: number) => string;
    /** يصل حين يُعدَّل بندٌ قائم في السلّة — فتعود الاختيارات كما تُركت */
    initial?: CustomCart | null;
    onClose: () => void;
    onConfirm: (custom: CustomCart, addons: { addon_id: number; name: string; price: number; qty: number }[]) => void;
}

/**
 * الطلبُ المخصَّص — طلبٌ يُركَّب على الطاولة، بالشكل الذي كتبه صاحبُ النشاط.
 *
 * ═══ ولمَ نافذةٌ واحدة لا صفحاتٌ ═══
 *
 * الزبون واقفٌ أمام الكاشير. وأقسامٌ في نافذةٍ تُمرَّر أسرعُ من شاشاتٍ
 * ينتقل بينها — ومن ينتقل ينسى ما اختار في الأولى.
 *
 * ═══ ولا حقلَ مكتوبٌ هنا ═══
 *
 * كانت النافذةُ تعرف «ألوان الورد» و«لون التغليف» و«ملاحظات المنسّق» —
 * ثلاثةَ حقولٍ مكتوبةٍ في الشاشة. فمن يبيع العطر يجدها ولا يجد ما يعنيه،
 * ومن أراد سؤالًا رابعًا لم يكن له سبيل.
 *
 * فالحقولُ تُقرأ من القالب: تسميتُها وترتيبُها ونوعُها وإلزامُها. والشاشةُ
 * تعرف ستّةَ أنواعٍ من الحقول ولا تعرف معنى واحدٍ منها.
 *
 * ═══ وما هنا عرضٌ لا حساب ═══
 *
 * التكلفةُ تُعرض هنا لتساعد الموظّف على التسعير، و**تُحسب في الخادم** من
 * `products.cost`. فما يُرسَل منها لا يُقرأ، وما يُحفظ هو ما حسبه الخادم.
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
    onClose,
    onConfirm,
}: Props) {
    const t = useTranslate();

    const [mode, setMode] = useState<string>('value');
    const [baseValue, setBaseValue] = useState('');
    const [budget, setBudget] = useState('');
    /** إجاباتُ الحقول بمعرّفاتها — والشكلُ يتبع نوعَ الحقل */
    const [values, setValues] = useState<Record<number, unknown>>({});
    const [picked, setPicked] = useState<CustomComponent[]>([]);
    const [addonQty, setAddonQty] = useState<Record<number, number>>({});
    const [search, setSearch] = useState('');

    /**
     * هل فُتحت قائمةُ المخزون؟ — تُفتح بضغطةٍ على الحقل لا بالكتابة وحدَها.
     *
     * الكاشيرُ لا يحفظ أسماءَ الدلاء. وحقلُ بحثٍ لا يُظهر شيئًا حتى يُكتب فيه
     * يُلزمه أن يعرف ما يبحث عنه قبل أن يبحث.
     */
    const [browsing, setBrowsing] = useState(false);

    /*
     * تُهيَّأ عند كلّ فتحة — لا مرّةً واحدة.
     *
     * بلا هذا يفتح الكاشير النافذةَ للطلب التالي فيجد موادَّ الطلب السابق
     * فيها، فيضيفها بلا انتباه: طلبٌ يخرج بموادّ طلبٍ آخر.
     */
    useEffect(() => {
        if (!open || !template) return;

        const fallback = template.default_mode ?? template.modes[0] ?? 'value';
        const resumed = initial?.mode && template.modes.includes(initial.mode) ? initial.mode : fallback;

        setMode(resumed);
        setBaseValue(initial?.base_value != null ? String(initial.base_value) : '');
        setBudget(initial?.mode === 'budget' ? String(initial.price) : '');
        setValues(
            Object.fromEntries((initial?.fields ?? []).map((f) => [f.field_id, f.value])),
        );
        setPicked(initial?.components ?? []);
        setAddonQty({});
        setSearch('');
        setBrowsing(false);
    }, [open, initial, template]);

    /** الإضافاتُ العامّة وحدَها: الطلبُ المخصَّص بلا منتجٍ يأذن بغيرها */
    const freeAddons = useMemo(
        () => (template?.allow_addons ? addons.filter((a) => a.active) : []),
        [addons, template],
    );

    const addonsTotal = useMemo(
        () =>
            Object.entries(addonQty).reduce((sum, [id, qty]) => {
                const a = freeAddons.find((x) => x.id === Number(id));

                return sum + (a ? a.price * qty : 0);
            }, 0),
        [addonQty, freeAddons],
    );

    const materialCost = useMemo(
        () =>
            picked.reduce((sum, c) => {
                const p = products.find((x) => x.id === c.product_id);

                return sum + (p ? p.cost * c.quantity : 0);
            }, 0),
        [picked, products],
    );

    /*
     * ═══ السعرُ الذي يدفعه الزبون ═══
     *
     * في «قيمة أساسية + إضافات» يجمعهما. وفي «السعر النهائي» هو الرقمُ
     * الذي قاله الزبون وحدَه — والإضافاتُ داخلَه لا فوقَه.
     *
     * والخادمُ يحسبها بالقاعدة نفسِها عند الدفع؛ وهذا عرضٌ لا مصدر.
     */
    const baseNum = Number(baseValue) || 0;
    const budgetNum = Number(budget) || 0;
    const sellingPrice = mode === 'budget' ? budgetNum : baseNum + addonsTotal;

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
                .map((c, i) => (i === idx ? { ...c, quantity: Math.round((c.quantity + delta) * 1000) / 1000 } : c))
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

    /** ما يمنع الإضافة — يُقال، ولا يُترك الزرُّ معطَّلًا بلا سبب */
    const blocked = sellingPrice <= 0
        ? t('أدخل سعر البيع.')
        : template?.allow_components && picked.length === 0
            ? t('اختر المكونات.')
            : missing
                ? t('«:name» مطلوب.', { name: missing.label })
                : null;

    const confirm = () => {
        if (blocked || !template) return;

        onConfirm(
            {
                template_id: template.id,
                template_name: template.name,
                mode,
                price: Math.round(sellingPrice * 1000) / 1000,
                base_value: mode === 'value' ? baseNum : null,
                fields: (template.fields ?? [])
                    .filter((f) => answered(f))
                    .map((f) => ({ field_id: f.id, value: values[f.id] })),
                components: picked,
                material_cost: Math.round(materialCost * 1000) / 1000,
            },
            Object.entries(addonQty).map(([id, qty]) => {
                const a = freeAddons.find((x) => x.id === Number(id))!;

                return { addon_id: a.id, name: a.label, price: a.price, qty };
            }),
        );
    };

    if (!open || !template) return null;

    const modeLabel = (key: string) =>
        key === 'budget' ? t('السعر النهائي') : t('قيمة أساسية + إضافات');

    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            {/* السقفُ والتمرير كما في `ItemOptionsDialog` — لا شكلَ جديد */}
            <DialogContent className="flex max-w-lg flex-col">
                <DialogHeader className="shrink-0 px-5 pt-5">
                    {/* واسمُ القالب لا اسمٌ مكتوبٌ في الشاشة */}
                    <DialogTitle>{template.name}</DialogTitle>
                    <DialogDescription>{t('ركّب الطلب واختر مواده من المخزون')}</DialogDescription>
                </DialogHeader>

                <div className="min-h-0 flex-1 space-y-5 overflow-y-auto px-5 pb-4">
                    {/* ١ · التسعير — الأوضاعُ من القالب لا من قائمةٍ في الشاشة */}
                    <div>
                        {template.modes.length > 1 && (
                            <>
                                <Label className="mb-2 block" required>
                                    {t('طريقة التسعير')}
                                </Label>
                                <div className="grid grid-cols-2 gap-2">
                                    {template.modes.map((key) => (
                                        <button
                                            key={key}
                                            type="button"
                                            onClick={() => setMode(key)}
                                            className={cn(
                                                'rounded-[12px] border px-3 py-3 text-start text-sm font-medium transition-colors',
                                                mode === key
                                                    ? 'border-gray-900 bg-gray-900 text-white'
                                                    : 'border-gray-200 bg-white text-gray-700 hover:bg-gray-50',
                                            )}
                                        >
                                            {modeLabel(key)}
                                        </button>
                                    ))}
                                </div>
                            </>
                        )}

                        <div className={cn(template.modes.length > 1 && 'mt-3')}>
                            {mode === 'value' ? (
                                <>
                                    {/* والتسميةُ من القالب: «قيمة الورد» أو «قيمة الطلب» أو ما شاء */}
                                    <Label className="mb-1.5 block" required>
                                        {template.base_label}
                                    </Label>
                                    <Input
                                        type="number"
                                        inputMode="decimal"
                                        step="0.001"
                                        min="0"
                                        value={baseValue}
                                        onChange={(e) => setBaseValue(e.target.value)}
                                    />
                                </>
                            ) : (
                                <>
                                    <Label className="mb-1.5 block" required>
                                        {t('السعر النهائي')}
                                    </Label>
                                    <Input
                                        type="number"
                                        inputMode="decimal"
                                        step="0.001"
                                        min="0"
                                        value={budget}
                                        onChange={(e) => setBudget(e.target.value)}
                                    />
                                    {/* وعدٌ يُقال قبل أن يُكتشف: الإضافةُ لا ترفع ما قاله الزبون */}
                                    <p className="mt-1.5 text-xs text-gray-500">
                                        {t('الإضافات لا تزيد هذا المبلغ — تدخل ضمنه')}
                                    </p>
                                </>
                            )}
                        </div>
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

                    {/* ٣ · المكوّنات — من أصناف المتجر لا من قائمةٍ تُكتب */}
                    {template.allow_components && (
                        <div>
                            <Label className="mb-2 block" required>
                                {t('المكونات')}
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
                                            </span>
                                            {/*
                                              * زرٌّ واحد لا زرّان.
                                              *
                                              * كانا «ورد» و«التغليف» — نوعان يعرفهما محلُّ ورد
                                              * وحده، وكان أحدُهما يقرّر صامتًا أتعود المادّةُ
                                              * إلى الرفّ أم لا. فصار الاختيارُ واحدًا،
                                              * والإرجاعُ مقبضًا يُقال على الصفّ أدناه.
                                              */}
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
                                                {/* في «السعر النهائي» لا ثمنَ يُضاف — فلا يُعرض ثمنٌ يُوهم */}
                                                {mode === 'value' && (
                                                    <span className="ms-2 text-xs font-bold text-[#7c3aed]">
                                                        +{money(a.price)}
                                                    </span>
                                                )}
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

                    {/* ٥ · الملخّص — يُقرأ قبل الإضافة لا بعدها */}
                    <div className="rounded-[12px] bg-gray-50 p-3 text-sm">
                        <div className="flex items-center justify-between font-bold text-gray-900">
                            <span>{t('سعر البيع')}</span>
                            <span className="tabular-nums">{money(sellingPrice)}</span>
                        </div>
                        <div className="mt-1 flex items-center justify-between text-xs text-gray-500">
                            <span>{t('تكلفة المواد')}</span>
                            <span className="tabular-nums">{money(materialCost)}</span>
                        </div>
                        {picked.length > 0 && (
                            <p className="mt-2 text-xs text-gray-500">
                                {t('المكونات')}: {picked.map((c) => `${c.name} ×${c.quantity}`).join(' · ')}
                            </p>
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
