import { useMemo, useState } from 'react';
import { router } from '@inertiajs/react';
import { Check, Plus, Trash2 } from 'lucide-react';
import Field, { Select } from '@/Components/Field';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { money, number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { Currency } from '@/types';

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

export interface CompositionData {
    variants: Variant[];
    recipe: RecipeBlock;
    components: { value: number; label: string; cost: number; quantity: number }[];
    addons: { value: number; label: string; price: number; active: boolean; inventory_product_id: number | null }[];
    addon_ids: number[];
}

interface Props {
    productId: number;
    data: CompositionData;
    currency: Currency;
}

/**
 * تركيب المنتج: مقاساتُه ووصفتُه وإضافاتُه.
 *
 * قسمٌ يُضاف إلى شاشة المنتج لا شاشةٌ جديدة — بنفس البطاقات والحقول
 * والأزرار. وكلّ فعلٍ هنا يذهب إلى مساره وحده بدل أن يُحمَّل على حفظ
 * المنتج: من يضيف مقاسًا لا يريد أن يحفظ سعر المنتج ووصفه معه، ومن يغيّر
 * وصفًا لا يريد أن يفقد مقاسًا كتبه ولم يحفظه.
 *
 * ولا نموذج داخل نموذج: شاشة المنتج نفسها `<form>`، وتعشيشُ نموذجٍ فيها
 * لا يصحّ في HTML — فالإرسال هنا بأزرارٍ تنادي المسار مباشرة.
 */
export default function Composition({ productId, data, currency }: Props) {
    const t = useTranslate();
    const m = (v: number) => money(v, currency);

    /** المقاس الذي تُعرض وصفته — و«لا مقاس» تعني وصفة المنتج نفسه */
    const [scope, setScope] = useState<string>('');
    const [addingVariant, setAddingVariant] = useState(false);
    const [variantDraft, setVariantDraft] = useState({ name: '', name_en: '', price: '', sku: '' });
    const [componentDraft, setComponentDraft] = useState({ component_product_id: '', quantity: '', wastage_percent: '' });
    const [addonIds, setAddonIds] = useState<number[]>(data.addon_ids);

    const variant = useMemo(
        () => data.variants.find((v) => String(v.id) === scope),
        [data.variants, scope],
    );

    const block = variant ? variant.recipe : data.recipe;

    const reload = { preserveScroll: true, preserveState: false as const };

    const addVariant = () =>
        router.post(route('admin.products.variants.store', productId), {
            name: variantDraft.name,
            name_en: variantDraft.name_en || null,
            price: variantDraft.price,
            sku: variantDraft.sku || null,
        }, {
            ...reload,
            onSuccess: () => {
                setAddingVariant(false);
                setVariantDraft({ name: '', name_en: '', price: '', sku: '' });
            },
        });

    const addComponent = () =>
        router.post(route('admin.products.recipe.store', productId), {
            component_product_id: componentDraft.component_product_id,
            variant_id: scope || null,
            quantity: componentDraft.quantity,
            wastage_percent: componentDraft.wastage_percent || 0,
        }, {
            ...reload,
            onSuccess: () => setComponentDraft({ component_product_id: '', quantity: '', wastage_percent: '' }),
        });

    return (
        <div className="space-y-6">
            {/* ------------------------------ المقاسات ------------------------------ */}
            <Card className="p-6">
                <div className="mb-1 flex items-center justify-between gap-3">
                    <h3 className="font-bold text-[#111]">{t('المقاسات')}</h3>
                    <Button type="button" variant="outline" size="sm" onClick={() => setAddingVariant((v) => !v)}>
                        <Plus />
                        {t('إضافة مقاس')}
                    </Button>
                </div>
                <p className="mb-4 text-[13px] text-[#6b7280]">
                    {t('منتجٌ بلا مقاسات يُباع بسعره كما هو. ومن له مقاسات يأتي سعره من المقاس المختار في نقطة البيع.')}
                </p>

                {addingVariant && (
                    <div className="mb-4 grid grid-cols-1 gap-3 rounded-[12px] bg-[#fafafa] p-4 sm:grid-cols-4">
                        <Field label="الاسم" required>
                            <Input
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

                {data.variants.length === 0 ? (
                    <p className="py-6 text-center text-[13px] text-[#9ca3af]">{t('لا مقاسات — منتج بسيط')}</p>
                ) : (
                    <ul className="space-y-2">
                        {data.variants.map((v) => (
                            <li
                                key={v.id}
                                className="flex flex-wrap items-center justify-between gap-3 rounded-[10px] border border-[#eee] px-3 py-2"
                            >
                                <span className="flex items-center gap-2">
                                    <span className="font-medium text-[#111]">{v.name}</span>
                                    {!v.active && <Badge variant="neutral">{t('غير مفعّل')}</Badge>}
                                    {v.recipe.items.length > 0 && (
                                        <Badge variant="info">
                                            {t(':n مكوّن', { n: String(v.recipe.items.length) })}
                                        </Badge>
                                    )}
                                </span>
                                <span className="flex items-center gap-3">
                                    <span className="font-semibold tabular-nums text-[#111]">{m(v.price)}</span>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        aria-label={t('حذف المقاس')}
                                        onClick={() =>
                                            router.delete(route('admin.products.variants.destroy', [productId, v.id]), reload)
                                        }
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

                {data.variants.length > 0 && (
                    <div className="mb-4 max-w-xs">
                        <Field label="وصفة">
                            <Select
                                value={scope}
                                onChange={(e) => setScope(e.target.value)}
                                options={[
                                    { value: '', label: t('المنتج (الأساس)') },
                                    ...data.variants.map((v) => ({ value: String(v.id), label: v.name })),
                                ]}
                            />
                        </Field>
                        {/* الموروث يُعلَّم كي لا يظنّ من يعدّله أنه يعدّل هذا المقاس وحده */}
                        {variant && block.items.some((i) => i.inherited) && (
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

                {block.items.length === 0 ? (
                    <p className="py-6 text-center text-[13px] text-[#9ca3af]">{t('لا مكوّنات بعد')}</p>
                ) : (
                    <ul className="space-y-2">
                        {block.items.map((i) => (
                            <li
                                key={i.id}
                                className={cn(
                                    'flex flex-wrap items-center justify-between gap-3 rounded-[10px] border px-3 py-2',
                                    i.inherited ? 'border-dashed border-[#e5e7eb] bg-[#fafafa]' : 'border-[#eee]',
                                )}
                            >
                                <span className="flex items-center gap-2">
                                    <span className="font-medium text-[#111]">{i.component}</span>
                                    {i.wastage_percent > 0 && (
                                        <Badge variant="warning">
                                            {t('فاقد')} {number(i.wastage_percent)}%
                                        </Badge>
                                    )}
                                    {i.inherited && <Badge variant="neutral">{t('موروث')}</Badge>}
                                </span>
                                <span className="flex items-center gap-3 text-[13px]">
                                    <span className="tabular-nums text-[#4b4b4b]">×{number(i.quantity)}</span>
                                    <span className="tabular-nums text-[#6b7280]">{m(i.line_cost)}</span>
                                    {!i.inherited && (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            aria-label={t('حذف المكوّن')}
                                            onClick={() =>
                                                router.delete(route('admin.products.recipe.destroy', [productId, i.id]), reload)
                                            }
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
                    التقرير يفترقان عند أوّل تقريبٍ مختلف */}
                {block.items.length > 0 && (
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
                )}
            </Card>

            {/* ------------------------------ الإضافات ------------------------------ */}
            <Card className="p-6">
                <h3 className="mb-1 font-bold text-[#111]">{t('الإضافات المسموحة')}</h3>
                <p className="mb-4 text-[13px] text-[#6b7280]">
                    {t('بلا اختيار تظهر إضافات المتجر كلّها مع هذا المنتج — وهو السلوك السابق. واختيار بعضها يقصرها عليه.')}
                </p>

                {data.addons.length === 0 ? (
                    <p className="py-6 text-center text-[13px] text-[#9ca3af]">{t('لا إضافات في المتجر بعد')}</p>
                ) : (
                    <>
                        <div className="mb-4 flex flex-wrap gap-2">
                            {data.addons.map((a) => {
                                const on = addonIds.includes(a.value);

                                return (
                                    <button
                                        key={a.value}
                                        type="button"
                                        onClick={() =>
                                            setAddonIds(on ? addonIds.filter((i) => i !== a.value) : [...addonIds, a.value])
                                        }
                                        className={cn(
                                            'rounded-[10px] border px-3 py-2 text-[13px] transition-colors',
                                            on
                                                ? 'border-[#111] bg-[#111] text-white'
                                                : 'border-[#e8e8e8] text-[#4b4b4b] hover:bg-[#f7f7f5]',
                                        )}
                                    >
                                        {a.label}
                                        <span className="ms-2 text-[12px] opacity-70">{m(a.price)}</span>
                                        {a.inventory_product_id && (
                                            <span className="ms-1 text-[12px] opacity-70">·{t('مخزون')}</span>
                                        )}
                                    </button>
                                );
                            })}
                        </div>

                        <Button
                            type="button"
                            onClick={() =>
                                router.put(route('admin.products.addons.sync', productId), { addon_ids: addonIds }, reload)
                            }
                        >
                            <Check />
                            {t('حفظ الإضافات')}
                        </Button>
                    </>
                )}
            </Card>
        </div>
    );
}
