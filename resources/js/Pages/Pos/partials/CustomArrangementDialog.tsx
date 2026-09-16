import { useEffect, useMemo, useState } from 'react';
import { Search, X } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { Addon, Product } from '@/types/models';
import type { CustomCart, CustomComponent } from '@/hooks/usePosCart';

interface Props {
    open: boolean;
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
 * الطلبُ المخصَّص — باقةٌ تُركَّب على الطاولة.
 *
 * ═══ ولمَ نافذةٌ واحدة لا صفحاتٌ ═══
 *
 * الزبون واقفٌ أمام الكاشير. وستُّ أقسامٍ في نافذةٍ تُمرَّر أسرعُ من أربع
 * شاشاتٍ ينتقل بينها — ومن ينتقل ينسى ما اختار في الأولى.
 *
 * والترتيبُ ترتيبُ الكلام كما يقع: يقول الزبون ميزانيّته، ثمّ يختار الورد،
 * ثمّ التغليف، ثمّ الإضافات، ثمّ يُملي رسالة الكرت.
 *
 * ═══ وما هنا عرضٌ لا حساب ═══
 *
 * التكلفةُ تُعرض هنا لتساعد الموظّف على التسعير، و**تُحسب في الخادم** من
 * `products.cost` — كما يُحسب سعرُ كلّ بندٍ آخر. فما يُرسَل منها لا يُقرأ،
 * وما يُحفظ هو ما حسبه الخادم.
 *
 * ولا يُخصم من الرفّ شيءٌ ما دامت النافذةُ مفتوحة ولا ما دام البندُ في
 * السلّة: الخصمُ في `completeSale` وحدَه.
 */
export default function CustomArrangementDialog({
    open,
    products,
    addons,
    money,
    initial,
    onClose,
    onConfirm,
}: Props) {
    const t = useTranslate();

    const [mode, setMode] = useState<'value' | 'budget'>('value');
    const [flowerValue, setFlowerValue] = useState('');
    const [budget, setBudget] = useState('');
    const [colors, setColors] = useState<string[]>([]);
    const [packagingLabel, setPackagingLabel] = useState('');
    const [floristNotes, setFloristNotes] = useState('');
    const [picked, setPicked] = useState<CustomComponent[]>([]);
    const [addonQty, setAddonQty] = useState<Record<number, number>>({});
    const [search, setSearch] = useState('');

    /*
     * تُهيَّأ عند كلّ فتحة — لا مرّةً واحدة.
     *
     * بلا هذا يفتح الكاشير النافذةَ للطلب التالي فيجد ورد الطلب السابق فيها،
     * فيضيفه بلا انتباه: باقةٌ تخرج بموادّ باقةٍ أخرى.
     */
    useEffect(() => {
        if (!open) return;
        setMode(initial?.mode ?? 'value');
        setFlowerValue(initial?.flower_value != null ? String(initial.flower_value) : '');
        setBudget(initial?.mode === 'budget' ? String(initial.price) : '');
        setColors(initial?.colors ?? []);
        setPackagingLabel(initial?.packaging_label ?? '');
        setFloristNotes(initial?.florist_notes ?? '');
        setPicked(initial?.components ?? []);
        setAddonQty({});
        setSearch('');
    }, [open, initial]);

    /** الإضافاتُ العامّة وحدَها: الطلبُ المخصَّص بلا منتجٍ يأذن بغيرها */
    const freeAddons = useMemo(() => addons.filter((a) => a.active), [addons]);

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

                return sum + (p ? p.cost * c.qty : 0);
            }, 0),
        [picked, products],
    );

    /*
     * ═══ السعرُ الذي يدفعه الزبون ═══
     *
     * في «قيمة الورد + الإضافات» يجمعهما. وفي «ميزانية نهائية» هو الرقمُ
     * الذي قاله الزبون وحدَه — والإضافاتُ داخلَه لا فوقَه.
     *
     * والخادمُ يحسبها بالقاعدة نفسِها عند الدفع؛ وهذا عرضٌ لا مصدر.
     */
    const flowerNum = Number(flowerValue) || 0;
    const budgetNum = Number(budget) || 0;
    const sellingPrice = mode === 'budget' ? budgetNum : flowerNum + addonsTotal;

    const results = useMemo(() => {
        const q = search.trim().toLowerCase();
        if (q === '') return [] as Product[];

        return products
            .filter((p) => p.active && (p.label.toLowerCase().includes(q) || p.sku?.toLowerCase().includes(q)))
            .slice(0, 8);
    }, [search, products]);

    const addComponent = (p: Product, kind: 'flower' | 'packaging') => {
        setPicked((prev) => {
            const at = prev.findIndex((c) => c.product_id === p.id && c.kind === kind);
            if (at >= 0) {
                const next = [...prev];
                next[at] = { ...next[at], qty: next[at].qty + 1 };

                return next;
            }

            return [...prev, { product_id: p.id, name: p.label, kind, qty: 1 }];
        });
        setSearch('');
    };

    const bump = (idx: number, delta: number) =>
        setPicked((prev) =>
            prev
                .map((c, i) => (i === idx ? { ...c, qty: Math.round((c.qty + delta) * 1000) / 1000 } : c))
                .filter((c) => c.qty > 0),
        );

    const bumpAddon = (id: number, delta: number) =>
        setAddonQty((prev) => {
            const next = { ...prev };
            const v = (next[id] ?? 0) + delta;
            if (v <= 0) delete next[id];
            else next[id] = v;

            return next;
        });

    const toggleColor = (name: string) =>
        setColors((prev) => (prev.includes(name) ? prev.filter((c) => c !== name) : [...prev, name]));

    /** ما يمنع الإضافة — يُقال، ولا يُترك الزرُّ معطَّلًا بلا سبب */
    const blocked = sellingPrice <= 0 ? t('أدخل سعر البيع.') : picked.length === 0 ? t('اختر المكونات.') : null;

    const confirm = () => {
        if (blocked) return;
        onConfirm(
            {
                mode,
                price: Math.round(sellingPrice * 1000) / 1000,
                flower_value: mode === 'value' ? flowerNum : null,
                colors,
                packaging_label: packagingLabel.trim() || null,
                florist_notes: floristNotes.trim() || null,
                components: picked,
                material_cost: Math.round(materialCost * 1000) / 1000,
            },
            Object.entries(addonQty).map(([id, qty]) => {
                const a = freeAddons.find((x) => x.id === Number(id))!;

                return { addon_id: a.id, name: a.label, price: a.price, qty };
            }),
        );
    };

    if (!open) return null;

    const flowers = picked.filter((c) => c.kind === 'flower');
    const packaging = picked.filter((c) => c.kind === 'packaging');

    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            {/* السقفُ والتمرير كما في `ItemOptionsDialog` — لا شكلَ جديد */}
            <DialogContent className="flex max-w-lg flex-col">
                <DialogHeader className="shrink-0 px-5 pt-5">
                    <DialogTitle>{t('طلب مخصص')}</DialogTitle>
                    <DialogDescription>{t('ركّب الباقة واختر موادها من المخزون')}</DialogDescription>
                </DialogHeader>

                <div className="min-h-0 flex-1 space-y-5 overflow-y-auto px-5 pb-4">
                    {/* ١ · التسعير */}
                    <div>
                        <Label className="mb-2 block" required>
                            {t('طريقة التسعير')}
                        </Label>
                        <div className="grid grid-cols-2 gap-2">
                            {(
                                [
                                    ['value', t('قيمة الورد + الإضافات')],
                                    ['budget', t('ميزانية نهائية للطلب')],
                                ] as const
                            ).map(([key, label]) => (
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
                                    {label}
                                </button>
                            ))}
                        </div>

                        <div className="mt-3">
                            {mode === 'value' ? (
                                <>
                                    <Label className="mb-1.5 block" required>
                                        {t('قيمة الورد')}
                                    </Label>
                                    <Input
                                        type="number"
                                        inputMode="decimal"
                                        step="0.001"
                                        min="0"
                                        value={flowerValue}
                                        onChange={(e) => setFlowerValue(e.target.value)}
                                    />
                                </>
                            ) : (
                                <>
                                    <Label className="mb-1.5 block" required>
                                        {t('ميزانية نهائية للطلب')}
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

                    {/* ٢ · ألوان الورد — تفضيلٌ يُكتب، والموادُّ أدناه هي ما يُخصم */}
                    <div>
                        <Label className="mb-2 block">{t('ألوان الورد')}</Label>
                        <div className="flex flex-wrap gap-2">
                            {[t('أبيض'), t('وردي'), t('أحمر'), t('أصفر')].map((c) => (
                                <button
                                    key={c}
                                    type="button"
                                    onClick={() => toggleColor(c)}
                                    className={cn(
                                        'rounded-[10px] border px-3 py-1.5 text-sm transition-colors',
                                        colors.includes(c)
                                            ? 'border-gray-900 bg-gray-900 text-white'
                                            : 'border-gray-200 bg-white text-gray-700 hover:bg-gray-50',
                                    )}
                                >
                                    {c}
                                </button>
                            ))}
                        </div>
                    </div>

                    {/* ٣ و٤ · المكوّنات والتغليف — من أصناف المتجر لا من قائمةٍ تُكتب */}
                    <div>
                        <Label className="mb-2 block" required>
                            {t('المكونات')}
                        </Label>
                        <div className="relative">
                            <Search className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-gray-400" />
                            <Input
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder={t('ابحث عن صنف من المخزون')}
                                className="ps-9"
                            />
                        </div>

                        {results.length > 0 && (
                            <ul className="mt-2 space-y-1 rounded-[12px] border border-gray-100 p-2">
                                {results.map((p) => (
                                    <li key={p.id} className="flex items-center justify-between gap-2">
                                        <span className="min-w-0 truncate text-sm text-gray-700">
                                            {p.label}
                                            <span className="ms-2 text-xs text-gray-400">
                                                {t('المتوفر')}: {p.qty}
                                            </span>
                                        </span>
                                        <span className="flex shrink-0 gap-1">
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                onClick={() => addComponent(p, 'flower')}
                                            >
                                                {t('ورد')}
                                            </Button>
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                onClick={() => addComponent(p, 'packaging')}
                                            >
                                                {t('التغليف')}
                                            </Button>
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}

                        {picked.length > 0 && (
                            <ul className="mt-3 space-y-2">
                                {picked.map((c, idx) => (
                                    <li
                                        key={`${c.product_id}-${c.kind}`}
                                        className="flex items-center justify-between gap-3 rounded-[12px] border border-gray-100 px-3 py-2"
                                    >
                                        <span className="min-w-0 text-sm text-gray-700">
                                            <span className="truncate">{c.name}</span>
                                            <span className="ms-2 text-xs text-gray-400">
                                                {c.kind === 'packaging' ? t('التغليف') : t('ورد')}
                                            </span>
                                        </span>
                                        <span className="flex shrink-0 items-center gap-2">
                                            <Button type="button" size="sm" variant="outline" onClick={() => bump(idx, -1)}>
                                                −
                                            </Button>
                                            <span className="w-8 text-center text-sm font-bold tabular-nums">{c.qty}</span>
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

                        <div className="mt-3">
                            <Label className="mb-1.5 block">{t('لون التغليف')}</Label>
                            <Input
                                value={packagingLabel}
                                onChange={(e) => setPackagingLabel(e.target.value)}
                                placeholder={t('كيس أسود')}
                            />
                        </div>
                    </div>

                    {/* ٥ · الإضافات — من نظام الإضافات القائم */}
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
                                                {/* في «الميزانية النهائيّة» لا ثمنَ يُضاف — فلا يُعرض ثمنٌ يُوهم */}
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

                    {/* ٦ · ملاحظاتُ المنسّق — داخليّة، لا تُطبع على فاتورة العميل */}
                    <div>
                        <Label className="mb-1.5 block">{t('ملاحظات المنسق')}</Label>
                        <Input
                            value={floristNotes}
                            onChange={(e) => setFloristNotes(e.target.value)}
                            placeholder={t('الأبيض أكثر من الوردي')}
                        />
                        <p className="mt-1.5 text-xs text-gray-500">{t('تظهر في لوحة التجهيز ولا تظهر في الفاتورة')}</p>
                    </div>

                    {/* ٧ · الملخّص — يُقرأ قبل الإضافة لا بعدها */}
                    <div className="rounded-[12px] bg-gray-50 p-3 text-sm">
                        <div className="flex items-center justify-between font-bold text-gray-900">
                            <span>{t('سعر البيع')}</span>
                            <span className="tabular-nums">{money(sellingPrice)}</span>
                        </div>
                        <div className="mt-1 flex items-center justify-between text-xs text-gray-500">
                            <span>{t('تكلفة المواد')}</span>
                            <span className="tabular-nums">{money(materialCost)}</span>
                        </div>
                        {flowers.length > 0 && (
                            <p className="mt-2 text-xs text-gray-500">
                                {t('المكونات')}: {flowers.map((c) => `${c.name} ×${c.qty}`).join(' · ')}
                            </p>
                        )}
                        {packaging.length > 0 && (
                            <p className="mt-1 text-xs text-gray-500">
                                {t('التغليف')}: {packaging.map((c) => `${c.name} ×${c.qty}`).join(' · ')}
                            </p>
                        )}
                    </div>
                </div>

                <div className="shrink-0 border-t border-gray-100 px-5 py-4">
                    {blocked && <p className="mb-2 text-xs text-[#b45309]">{blocked}</p>}
                    <Button type="button" className="w-full" disabled={blocked !== null} onClick={confirm}>
                        {initial ? t('حفظ التعديل') : t('إضافة للسلة')}
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}
