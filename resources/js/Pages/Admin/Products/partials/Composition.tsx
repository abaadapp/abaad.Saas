import { useMemo, useState } from 'react';
import { router } from '@inertiajs/react';
import { Check, Pencil, Plus, Trash2 } from 'lucide-react';
import Field, { Select } from '@/Components/Field';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { money, number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { Currency } from '@/types';
import AddonDialog from './AddonDialog';

export interface RecipeLine {
    id: number;
    component_product_id: number;
    component: string;
    quantity: number;
    wastage_percent: number;
    unit_cost: number;
    line_cost: number;
    /** موروثٌ من وصفة المنتج لا خاصٌّ بهذا المقاس */
    inherited: boolean;
}

export interface RecipeBlock {
    items: RecipeLine[];
    cost: number;
    price: number;
    margin: number;
    margin_pct: number | null;
}

export interface Variant {
    id: number;
    name: string;
    name_en: string | null;
    sku: string | null;
    barcode: string | null;
    price: number;
    active: boolean;
    sort_order: number;
    recipe: RecipeBlock;
}

export interface AddonOption {
    value: number;
    label: string;
    price: number;
    active: boolean;
    /** خاصّةٌ بهذا المنتج وحده لا بالمتجر */
    private: boolean;
    product_id?: number | null;
    /** مداها: مع الجميع، أو مع منتجاتٍ محدّدة، أو مع مالكها وحده */
    scope?: 'all' | 'selected' | 'product';
    inventory_product_id: number | null;
    /** ما تأكله من ذلك الصنف في كلّ إضافةٍ تُباع — والفراغ واحدة */
    inventory_quantity?: number | null;
    product_ids?: number[];
}

export interface CompositionData {
    variants: Variant[];
    recipe: RecipeBlock;
    components: { value: number; label: string; cost: number; quantity: number }[];
    addons: AddonOption[];
    addon_ids: number[];
    /** الأصناف الصالحة لأن تكون مخزونَ إضافة — بلا ما له وصفة */
    stock_items: { value: number; label: string; quantity: number }[];
    /** منتجات المتجر — لاختيار «تظهر مع: منتجات محدّدة» */
    products: { value: number; label: string }[];
}

/* --------------------------- مسوّدة منتجٍ لم يُحفظ --------------------------- */

export interface DraftVariant { name: string; name_en: string; price: string; sku: string }
export interface DraftLine {
    component_product_id: string;
    quantity: string;
    wastage_percent: string;
    /** موضع المقاس في القائمة، وnull تعني وصفة المنتج الأساس */
    variant_index: number | null;
}
export interface DraftAddon {
    name: string;
    price: string;
    private: boolean;
    inventory_product_id?: number | null;
    inventory_quantity?: number | null;
}

export interface CompositionDraft {
    variants: DraftVariant[];
    recipe: DraftLine[];
    addon_ids: number[];
    new_addons: DraftAddon[];
}

export const emptyDraft = (): CompositionDraft => ({
    variants: [], recipe: [], addon_ids: [], new_addons: [],
});

interface Props {
    /** موجود = منتجٌ محفوظ تُكتب تعديلاته فورًا، غائب = مسوّدة تُحفظ معه */
    productId?: number | null;
    data: CompositionData;
    currency: Currency;
    draft?: CompositionDraft;
    onDraft?: (draft: CompositionDraft) => void;
    /** قائمة الإضافات — يملكها النموذج كي تُرى الإضافةُ الجديدة في القسمين معًا */
    addons: AddonOption[];
    /** تُنشأ أو تُعدَّل — والقائمة يملكها النموذج كي يراها القسمان معًا */
    onAddonSaved: (addon: AddonOption) => void;
}

/**
 * تركيب المنتج: مقاساتُه ووصفتُه وإضافاتُه.
 *
 * قسمٌ يُضاف إلى شاشة المنتج لا شاشةٌ جديدة — بنفس البطاقات والحقول
 * والأزرار.
 *
 * ويعمل في وضعين:
 *
 *   - منتجٌ محفوظ: كلّ فعلٍ يذهب إلى مساره وحده بدل أن يُحمَّل على حفظ
 *     المنتج. من يضيف مقاسًا لا يريد أن يحفظ سعر المنتج ووصفه معه.
 *   - منتجٌ يُكتب الآن: لا معرّف يُعلَّق به شيء، فالتركيب يبقى مسوّدةً في
 *     الشاشة ويُكتب مع المنتج في طلب الحفظ نفسه. وكان القسم يُخفى عند
 *     الإنشاء فيُجبَر التاجر على حفظ الباقة ثم العودة إليها ليقول ممّ
 *     تتركّب — خطوتان لفعلٍ واحد في ذهنه.
 *
 * ولا نموذج داخل نموذج: شاشة المنتج نفسها `<form>`، وتعشيشُ نموذجٍ فيها
 * لا يصحّ في HTML — فالإرسال هنا بأزرارٍ تنادي المسار مباشرة.
 */
export default function Composition({ productId, data, currency, draft, onDraft, addons, onAddonSaved }: Props) {
    const t = useTranslate();
    const m = (v: number) => money(v, currency);

    const drafting = !productId;
    const d = draft ?? emptyDraft();
    const patch = (part: Partial<CompositionDraft>) => onDraft?.({ ...d, ...part });

    /** المقاس الذي تُعرض وصفته — و«لا مقاس» تعني وصفة المنتج نفسه */
    const [scope, setScope] = useState<string>('');
    const [addingVariant, setAddingVariant] = useState(false);
    const [variantDraft, setVariantDraft] = useState({ name: '', name_en: '', price: '', sku: '' });
    const [componentDraft, setComponentDraft] = useState({ component_product_id: '', quantity: '', wastage_percent: '' });

    /*
     * التحديث لا يُعيد بناء الشاشة.
     *
     * كان `preserveState: false` يهدم حالة النموذج كلّها بعد كلّ إضافة:
     * يعود التبويب إلى «المعلومات الأساسية» وينسى المقاس المختار. فمن أضاف
     * مكوّنًا وجد نفسه في شاشةٍ أخرى، وظنّ أنّ إضافة أكثر من مكوّنٍ لا تعمل.
     */
    const reload = { preserveScroll: true, preserveState: true as const };

    const unitCost = useMemo(
        () => Object.fromEntries(data.components.map((c) => [String(c.value), c.cost])),
        [data.components],
    );
    const componentName = useMemo(
        () => Object.fromEntries(data.components.map((c) => [String(c.value), c.label])),
        [data.components],
    );

    /* ------------------------------ المقاسات ------------------------------ */

    interface VariantRow { key: string; name: string; price: number; active: boolean; lines: number; remove: () => void }

    const variantRows: VariantRow[] = drafting
        ? d.variants.map((v, i) => ({
            key: String(i),
            name: v.name,
            price: Number(v.price) || 0,
            active: true,
            lines: d.recipe.filter((r) => r.variant_index === i).length,
            // الوصفة تتبع مقاسها: صفوفٌ تشير إلى مقاسٍ حُذف تصير بلا معنى،
            // وما بعده يزحف موضعًا فتنكسر الإشارة إن لم تُصحَّح
            remove: () => {
                patch({
                    variants: d.variants.filter((_, j) => j !== i),
                    recipe: d.recipe
                        .filter((r) => r.variant_index !== i)
                        .map((r) => (r.variant_index !== null && r.variant_index > i
                            ? { ...r, variant_index: r.variant_index - 1 }
                            : r)),
                });
                setScope('');
            },
        }))
        : data.variants.map((v) => ({
            key: String(v.id),
            name: v.name,
            price: v.price,
            active: v.active,
            lines: v.recipe.items.length,
            remove: () => router.delete(route('admin.products.variants.destroy', [productId, v.id]), reload),
        }));

    const addVariant = () => {
        if (!variantDraft.name.trim()) return;

        if (drafting) {
            patch({ variants: [...d.variants, { ...variantDraft }] });
        } else {
            router.post(route('admin.products.variants.store', productId), {
                name: variantDraft.name,
                name_en: variantDraft.name_en || null,
                price: variantDraft.price,
                sku: variantDraft.sku || null,
            }, reload);
        }

        setAddingVariant(false);
        setVariantDraft({ name: '', name_en: '', price: '', sku: '' });
    };

    /* ------------------------------- الوصفة ------------------------------- */

    interface LineRow {
        key: string;
        component: string;
        quantity: number;
        wastage: number;
        cost: number;
        inherited: boolean;
        remove: () => void;
    }

    const saved = useMemo(
        () => data.variants.find((v) => String(v.id) === scope),
        [data.variants, scope],
    );
    const block = saved ? saved.recipe : data.recipe;

    /** الصفوف المعروضة تحت النطاق المختار — والموروث يُعلَّم لا يُخفى */
    const lineRows: LineRow[] = useMemo(() => {
        if (!drafting) {
            return block.items.map((i) => ({
                key: String(i.id),
                component: i.component,
                quantity: i.quantity,
                wastage: i.wastage_percent,
                cost: i.line_cost,
                inherited: i.inherited,
                remove: () => router.delete(route('admin.products.recipe.destroy', [productId, i.id]), reload),
            }));
        }

        const scopeIndex = scope === '' ? null : Number(scope);
        const indexed = d.recipe.map((r, i) => ({ r, i }));
        const own = indexed.filter((x) => x.r.variant_index === scopeIndex);
        const source = scopeIndex !== null && own.length === 0
            ? indexed.filter((x) => x.r.variant_index === null)
            : own;
        const inherited = source !== own;

        return source.map(({ r, i }) => {
            const qty = Number(r.quantity) || 0;
            const wastage = Number(r.wastage_percent) || 0;

            return {
                key: String(i),
                component: componentName[r.component_product_id] ?? '—',
                quantity: qty,
                wastage,
                cost: Math.round(qty * (1 + wastage / 100) * (unitCost[r.component_product_id] ?? 0) * 1000) / 1000,
                inherited,
                remove: () => patch({ recipe: d.recipe.filter((_, j) => j !== i) }),
            };
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [drafting, block, d.recipe, scope, componentName, unitCost, productId]);

    const draftCost = lineRows.reduce((sum, r) => sum + r.cost, 0);

    const addComponent = () => {
        if (!componentDraft.component_product_id || !componentDraft.quantity) return;

        if (drafting) {
            patch({
                recipe: [...d.recipe, {
                    component_product_id: componentDraft.component_product_id,
                    quantity: componentDraft.quantity,
                    wastage_percent: componentDraft.wastage_percent || '0',
                    variant_index: scope === '' ? null : Number(scope),
                }],
            });
        } else {
            router.post(route('admin.products.recipe.store', productId), {
                component_product_id: componentDraft.component_product_id,
                variant_id: scope || null,
                quantity: componentDraft.quantity,
                wastage_percent: componentDraft.wastage_percent || 0,
            }, reload);
        }

        // الحقول تُفرَغ للمكوّن التالي — وإضافة عدّة مكوّنات هي الحالة الغالبة
        setComponentDraft({ component_product_id: '', quantity: '', wastage_percent: '' });
    };

    /* ------------------------------ الإضافات ------------------------------ */

    /*
     * إضافات هذا المنتج وحده.
     *
     * لا تُعرض هنا إضافةُ متجرٍ ولا تُختار: تلك تظهر مع كلّ منتجٍ يبيعه
     * المحلّ، ومكانُها الصفُّ الأوّل من شاشة المنتج بجانب القسم — حيث
     * تُقرَّر مرّةً للجميع. وعرضُها هنا كان يجعل الشاشة تسأل سؤالًا لا
     * يخصّ هذا المنتج، ويُوهم أنّ إطفاءها هنا يُطفئها عنه وحده.
     *
     * فهذا القسم لما يخصّه: «شريط ذهبي» لباقة الورد لا يراه كيس السماد.
     */
    /*
     * الإضافة تُنشأ وتُعدَّل من نافذةٍ واحدة.
     *
     * وكان الإنشاء هنا حقلين لا أكثر — اسمًا وسعرًا — فلا سبيل إلى قول
     * «هذه تنقص ثلاث ورداتٍ من الرفّ». والتعديل لم يكن له باب أصلًا.
     */
    const [editing, setEditing] = useState<AddonOption | null | undefined>(undefined);

    interface AddonRow {
        key: string;
        label: string;
        price: number;
        stock: boolean;
        each: number | null;
        edit?: () => void;
        remove: () => void;
    }

    interface AddonRow { key: string; label: string; price: number; stock: boolean; remove: () => void }

    const addonRows: AddonRow[] = [
        ...addons.filter((a) => a.private).map((a) => ({
            key: 'a' + a.value,
            label: a.label,
            price: a.price,
            stock: !!a.inventory_product_id,
            each: a.inventory_product_id ? (a.inventory_quantity ?? 1) : null,
            edit: () => setEditing(a),
            remove: () => router.delete(route('admin.products.addons.destroy', [productId, a.value]), reload),
        })),
        // إضافةٌ كُتبت الآن ولم تُحفظ بعد: تُحذف من المسوّدة لا من القاعدة.
        // والعامّة منها تُستثنى — مكانُها «المعلومات الأساسية» حيث كُتبت
        ...d.new_addons
            .map((a, i) => ({ a, i }))
            .filter(({ a }) => a.private)
            .map(({ a, i }) => ({
                key: 'n' + i,
                label: a.name,
                price: Number(a.price) || 0,
                stock: !!a.inventory_product_id,
                each: a.inventory_product_id ? (a.inventory_quantity ?? 1) : null,
                remove: () => patch({ new_addons: d.new_addons.filter((_, j) => j !== i) }),
            })),
    ];

    return (
        <div className="space-y-6">
            {/* ------------------------------ المقاسات ------------------------------ */}
            <Card className="p-6">
                <div className="mb-1 flex items-center justify-between gap-3">
                    <h3 className="font-bold text-[#111]">{t('المقاسات')}</h3>
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        aria-label={t('إضافة مقاس')}
                        title={t('إضافة مقاس')}
                        onClick={() => setAddingVariant((v) => !v)}
                    >
                        <Plus />
                    </Button>
                </div>
                <p className="mb-4 text-[13px] text-[#6b7280]">
                    {t('منتجٌ بلا مقاسات يُباع بسعره كما هو. ومن له مقاسات يأتي سعره من المقاس المختار في نقطة البيع.')}
                </p>

                {addingVariant && (
                    <div className="mb-4 grid grid-cols-1 gap-3 rounded-[12px] bg-[#fafafa] p-4 sm:grid-cols-4">
                        <Field label="الاسم" required>
                            <Input
                                autoFocus
                                value={variantDraft.name}
                                onChange={(e) => setVariantDraft({ ...variantDraft, name: e.target.value })}
                                placeholder={t('وسط')}
                            />
                        </Field>
                        <Field label="الاسم بالإنجليزية">
                            <Input
                                dir="ltr"
                                value={variantDraft.name_en}
                                onChange={(e) => setVariantDraft({ ...variantDraft, name_en: e.target.value })}
                            />
                        </Field>
                        <Field label="السعر" required>
                            <Input
                                type="number"
                                step="0.001"
                                min="0"
                                dir="ltr"
                                value={variantDraft.price}
                                onChange={(e) => setVariantDraft({ ...variantDraft, price: e.target.value })}
                            />
                        </Field>
                        <div className="flex items-end">
                            <Button type="button" className="w-full" onClick={addVariant}>
                                <Check />
                                {t('حفظ')}
                            </Button>
                        </div>
                    </div>
                )}

                {variantRows.length === 0 ? (
                    <p className="py-6 text-center text-[13px] text-[#9ca3af]">{t('لا مقاسات — منتج بسيط')}</p>
                ) : (
                    <ul className="space-y-2">
                        {variantRows.map((v) => (
                            <li
                                key={v.key}
                                className="flex flex-wrap items-center justify-between gap-3 rounded-[10px] border border-[#eee] px-3 py-2"
                            >
                                <span className="flex items-center gap-2">
                                    <span className="font-medium text-[#111]">{v.name}</span>
                                    {!v.active && <Badge variant="neutral">{t('غير مفعّل')}</Badge>}
                                    {v.lines > 0 && (
                                        <Badge variant="info">{t(':n مكوّن', { n: String(v.lines) })}</Badge>
                                    )}
                                </span>
                                <span className="flex items-center gap-3">
                                    <span className="font-semibold tabular-nums text-[#111]">{m(v.price)}</span>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        aria-label={t('حذف المقاس')}
                                        onClick={v.remove}
                                    >
                                        <Trash2 className="size-4 text-[#b91c1c]" />
                                    </Button>
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </Card>

            {/* ------------------------------- الوصفة ------------------------------- */}
            <Card className="p-6">
                <h3 className="mb-1 font-bold text-[#111]">{t('الوصفة')}</h3>
                <p className="mb-4 text-[13px] text-[#6b7280]">
                    {t('ما يُخصم من المخزون عند البيع. ومنتجٌ له وصفة يُخصم مكوّناته لا هو نفسه.')}
                </p>

                {variantRows.length > 0 && (
                    <div className="mb-4 max-w-xs">
                        <Field label="وصفة">
                            <Select
                                value={scope}
                                onChange={(e) => setScope(e.target.value)}
                                options={[
                                    { value: '', label: t('المنتج (الأساس)') },
                                    ...variantRows.map((v) => ({ value: v.key, label: v.name })),
                                ]}
                            />
                        </Field>
                        {/* الموروث يُعلَّم كي لا يظنّ من يعدّله أنه يعدّل هذا المقاس وحده */}
                        {scope !== '' && lineRows.some((i) => i.inherited) && (
                            <p className="mt-2 text-[12px] text-[#9a3412]">
                                {t('هذه مكوّنات المنتج الأساس — أضف مكوّنًا هنا ليصبح لهذا المقاس وصفته الخاصة.')}
                            </p>
                        )}
                    </div>
                )}

                <div className="mb-4 grid grid-cols-1 gap-3 rounded-[12px] bg-[#fafafa] p-4 sm:grid-cols-4">
                    <Field label="المكوّن" required>
                        <Select
                            placeholder="اختر صنفًا"
                            value={componentDraft.component_product_id}
                            onChange={(e) => setComponentDraft({ ...componentDraft, component_product_id: e.target.value })}
                            options={data.components}
                        />
                    </Field>
                    <Field label="الكمية" required hint="تقبل الكسور">
                        <Input
                            type="number"
                            step="0.001"
                            min="0"
                            dir="ltr"
                            value={componentDraft.quantity}
                            onChange={(e) => setComponentDraft({ ...componentDraft, quantity: e.target.value })}
                        />
                    </Field>
                    <Field label="نسبة الفاقد %">
                        <Input
                            type="number"
                            step="0.01"
                            min="0"
                            max="100"
                            dir="ltr"
                            value={componentDraft.wastage_percent}
                            onChange={(e) => setComponentDraft({ ...componentDraft, wastage_percent: e.target.value })}
                        />
                    </Field>
                    <div className="flex items-end">
                        <Button type="button" className="w-full" onClick={addComponent}>
                            <Plus />
                            {t('إضافة مكوّن')}
                        </Button>
                    </div>
                </div>

                {lineRows.length === 0 ? (
                    <p className="py-6 text-center text-[13px] text-[#9ca3af]">{t('لا مكوّنات بعد')}</p>
                ) : (
                    <ul className="space-y-2">
                        {lineRows.map((i) => (
                            <li
                                key={i.key}
                                className={cn(
                                    'flex flex-wrap items-center justify-between gap-3 rounded-[10px] border px-3 py-2',
                                    i.inherited ? 'border-dashed border-[#e5e7eb] bg-[#fafafa]' : 'border-[#eee]',
                                )}
                            >
                                <span className="flex items-center gap-2">
                                    <span className="font-medium text-[#111]">{i.component}</span>
                                    {i.wastage > 0 && (
                                        <Badge variant="warning">
                                            {t('فاقد')} {number(i.wastage)}%
                                        </Badge>
                                    )}
                                    {i.inherited && <Badge variant="neutral">{t('موروث')}</Badge>}
                                </span>
                                <span className="flex items-center gap-3 text-[13px]">
                                    <span className="tabular-nums text-[#4b4b4b]">×{number(i.quantity)}</span>
                                    <span className="tabular-nums text-[#6b7280]">{m(i.cost)}</span>
                                    {!i.inherited && (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            aria-label={t('حذف المكوّن')}
                                            onClick={i.remove}
                                        >
                                            <Trash2 className="size-4 text-[#b91c1c]" />
                                        </Button>
                                    )}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}

                {/* الأرقام مشتقّةٌ في الخادم — هامشٌ يُحسب هنا وهامشٌ يُحسب في
                    التقرير يفترقان عند أوّل تقريبٍ مختلف. وفي المسوّدة لا خادم
                    بعد، فتُعرض التكلفة وحدها لا الهامش. */}
                {lineRows.length > 0 && (drafting ? (
                    <dl className="mt-4 rounded-[12px] bg-[#fafafa] p-4 text-[13px]">
                        <div className="flex justify-between">
                            <dt className="text-[#6b7280]">{t('التكلفة التقديرية')}</dt>
                            <dd className="font-semibold tabular-nums text-[#111]">{m(draftCost)}</dd>
                        </div>
                    </dl>
                ) : (
                    <dl className="mt-4 grid grid-cols-1 gap-2 rounded-[12px] bg-[#fafafa] p-4 text-[13px] sm:grid-cols-3">
                        <div className="flex justify-between sm:block">
                            <dt className="text-[#6b7280]">{t('التكلفة التقديرية')}</dt>
                            <dd className="font-semibold tabular-nums text-[#111]">{m(block.cost)}</dd>
                        </div>
                        <div className="flex justify-between sm:block">
                            <dt className="text-[#6b7280]">{t('سعر البيع')}</dt>
                            <dd className="font-semibold tabular-nums text-[#111]">{m(block.price)}</dd>
                        </div>
                        <div className="flex justify-between sm:block">
                            <dt className="text-[#6b7280]">{t('الهامش')}</dt>
                            <dd
                                className={cn(
                                    'font-semibold tabular-nums',
                                    block.margin < 0 ? 'text-[#b91c1c]' : 'text-[#047857]',
                                )}
                            >
                                {m(block.margin)}
                                {block.margin_pct !== null && (
                                    <span className="ms-1 text-[12px] font-normal text-[#9ca3af]">
                                        {number(block.margin_pct)}%
                                    </span>
                                )}
                            </dd>
                        </div>
                    </dl>
                ))}
            </Card>

            {/* ------------------------------ الإضافات ------------------------------ */}
            <Card className="p-6">
                <div className="mb-1 flex items-center justify-between gap-3">
                    <h3 className="font-bold text-[#111]">{t('إضافات هذا المنتج')}</h3>
                    {/* إنشاءُ إضافةٍ متاحٌ ولو لم تكن في المتجر واحدة — وهي الحال
                        التي يبدأ منها كلّ متجرٍ جديد */}
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        aria-label={t('إضافة جديدة')}
                        title={t('إضافة جديدة')}
                        onClick={() => setEditing(null)}
                    >
                        <Plus />
                    </Button>
                </div>
                <p className="mb-4 text-[13px] text-[#6b7280]">
                    {t('تُعرض مع هذا المنتج وحده. وإضافات المتجر التي تظهر مع الجميع مكانها «المعلومات الأساسية».')}
                </p>

                {addonRows.length === 0 ? (
                    <p className="py-6 text-center text-[13px] text-[#9ca3af]">
                        {t('لا إضافات خاصّة بهذا المنتج')}
                    </p>
                ) : (
                    <ul className="space-y-2">
                        {addonRows.map((a) => (
                            <li
                                key={a.key}
                                className="flex flex-wrap items-center justify-between gap-3 rounded-[10px] border border-[#eee] px-3 py-2"
                            >
                                <span className="flex items-center gap-2">
                                    <span className="font-medium text-[#111]">{a.label}</span>
                                    {/* «تخصم ٣» لا «مخزون»: العدد هو ما يريد أن
                                        يتحقّق منه من ربطها، والوسم وحده لا يقوله */}
                                    {a.stock && (
                                        <Badge variant="info">
                                            {a.each && a.each !== 1
                                                ? `${t('تخصم')} ${number(a.each)}`
                                                : t('مخزون')}
                                        </Badge>
                                    )}
                                </span>
                                <span className="flex items-center gap-3">
                                    <span className="font-semibold tabular-nums text-[#111]">{m(a.price)}</span>
                                    {a.edit && (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            aria-label={t('تعديل الإضافة')}
                                            onClick={a.edit}
                                        >
                                            <Pencil className="size-4 text-[#6b7280]" />
                                        </Button>
                                    )}
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        aria-label={t('حذف الإضافة')}
                                        onClick={a.remove}
                                    >
                                        <Trash2 className="size-4 text-[#b91c1c]" />
                                    </Button>
                                </span>
                            </li>
                        ))}
                    </ul>
                )}

                {editing !== undefined && (
                    <AddonDialog
                        addon={editing}
                        productId={productId ?? null}
                        drafting={drafting}
                        stockItems={data.stock_items ?? []}
                        products={data.products ?? []}
                        onClose={() => setEditing(undefined)}
                        onSaved={(saved) => {
                            // المعرّف صفرًا يعني مسوّدةً لم تُكتب في القاعدة بعد.
                            // وما سواها حُفظ فعلًا — ولو كان المنتج نفسه مسوّدة:
                            // إضافةُ المتجر لا تنتظر منتجًا لتوجد
                            if (saved.value === 0) {
                                patch({
                                    new_addons: [...d.new_addons, {
                                        name: saved.label,
                                        price: String(saved.price),
                                        private: true,
                                        inventory_product_id: saved.inventory_product_id,
                                        inventory_quantity: saved.inventory_quantity ?? null,
                                    }],
                                });

                                return;
                            }

                            onAddonSaved(saved);
                        }}
                    />
                )}
            </Card>
        </div>
    );
}
