import { useState } from 'react';
import { ArrowDown, ArrowUp, ChevronDown, Eye, EyeOff, Plus, Trash2 } from 'lucide-react';

import Field from '@/Components/Field';
import StoreImageField from '@/Components/StoreImageField';
import Toggle from '@/Components/Toggle';
import { Button } from '@/Components/ui/button';
import { Input, Textarea } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import ProductListField, { type ListProduct } from './ProductListField';

/**
 * مجموعاتُ المتجر في محرّر الواجهة — رفوفٌ يختار أصنافَها بيده، غيرُ الفئات.
 *
 * ═══ والقيمةُ نصٌّ كسائر حقول المحرّر ═══
 *
 * القائمةُ كلُّها تُكتب في مفتاحٍ واحد (`store_collections`) نصَّ JSON بترتيبها،
 * وتُحفظ بزرّ الصفّ وتُعايَن قبل الحفظ كأخواتها. والخادمُ يقرأ ما يصل بصرامة
 * (`Store\StoreCollections::validated`): أصنافُ متجره المعروضةُ وحدها، وصورُ
 * مجلّده وحدها، ورابطٌ مختصرٌ فريد.
 *
 * والصنفُ يبقى في فئته: المجموعةُ عرضٌ لا تصنيف، والصنفُ في أكثر من مجموعةٍ
 * إن اختير لها.
 */

export interface StoreCollection {
    slug: string;
    title_ar: string;
    title_en: string;
    desc_ar: string;
    desc_en: string;
    image: string;
    /** «كاملة» أو «ملء الإطار» — وغيابُها «ملء الإطار» كما كانت */
    fit?: 'contain' | 'cover';
    visible: boolean;
    in_row: boolean;
    products: number[];
}

const SLUG = /^[a-z0-9](?:[a-z0-9-]{0,38}[a-z0-9])?$/;

/** النصُّ المحفوظ ← قائمة — وما لا يُقرأ يسقط ولا يُسقط الشاشة */
export function parseCollections(raw: string | undefined): StoreCollection[] {
    if (! raw) return [];

    let list: unknown;
    try {
        list = JSON.parse(raw);
    } catch {
        return [];
    }

    if (! Array.isArray(list)) return [];

    return list
        .filter((c): c is Record<string, unknown> => !! c && typeof c === 'object')
        .map((c) => ({
            slug: String(c.slug ?? ''),
            title_ar: String(c.title_ar ?? ''),
            title_en: String(c.title_en ?? ''),
            desc_ar: String(c.desc_ar ?? ''),
            desc_en: String(c.desc_en ?? ''),
            image: String(c.image ?? ''),
            ...(c.fit === 'contain' || c.fit === 'cover' ? { fit: c.fit } : {}),
            visible: c.visible !== false,
            in_row: c.in_row !== false,
            products: Array.isArray(c.products)
                ? c.products.map(Number).filter((v, i, all) => Number.isInteger(v) && v > 0 && all.indexOf(v) === i)
                : [],
        }));
}

/** القائمة ← نصٌّ يُحفظ. والفراغُ «لا مجموعات» */
export function serializeCollections(list: StoreCollection[]): string {
    return list.length ? JSON.stringify(list) : '';
}

/**
 * ما يُرسَل أوّلَ ما تُفتح الشاشة — أصنافُ كلّ مجموعةٍ ممّا يُعرض الآن وحده.
 *
 * صنفٌ أُخفي بعد اختياره يُردّ الحفظُ كلُّه من أجله، وصاحبُ المحلّ لا يراه
 * في القائمة ليُزيله — قاعدةُ `keepShown` نفسُها.
 */
export function keepShownCollections(raw: string | undefined, products: ListProduct[]): string {
    const shown = new Set(products.map((p) => p.id));
    const list = parseCollections(raw);

    if (! list.length) return raw ?? '';

    return serializeCollections(list.map((c) => ({ ...c, products: c.products.filter((id) => shown.has(id)) })));
}

/** اسمٌ إنجليزيّ ← رابطٌ مختصر: حروفٌ صغيرةٌ وأرقامٌ وشرطة */
export function slugify(text: string): string {
    return text
        .toLowerCase()
        .normalize('NFKD')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 40)
        .replace(/-+$/g, '');
}

/** رابطٌ مختصرٌ لم يُؤخذ — من الاسم إن كان، وإلّا حرفٌ ورقمٌ عشوائيّان */
export function freshSlug(taken: string[], seed = ''): string {
    let base = slugify(seed);
    if (! SLUG.test(base)) base = 'c-' + Math.random().toString(36).slice(2, 8);

    let slug = base;
    for (let n = 2; taken.includes(slug); n++) slug = `${base.slice(0, 36)}-${n}`;

    return slug;
}

export function validSlug(slug: string): boolean {
    return SLUG.test(slug);
}

function blank(taken: string[]): StoreCollection {
    return {
        slug: freshSlug(taken), title_ar: '', title_en: '', desc_ar: '', desc_en: '',
        image: '', visible: true, in_row: true, products: [],
    };
}

export default function CollectionsField({
    name,
    label,
    hint,
    value,
    products,
    max,
    maxProducts,
    onChange,
    onPreview,
    error,
}: {
    name: string;
    label: string;
    hint?: string;
    value: string;
    products: ListProduct[];
    max: number;
    maxProducts: number;
    onChange: (value: string) => void;
    /** يعاين رفَّ المجموعة في إطار المعاينة — قبل الحفظ */
    onPreview?: (slug: string) => void;
    error?: string;
}) {
    const t = useTranslate();
    const list = parseCollections(value);
    // والمفتوحُ بموضعه لا برابطه: الرابطُ يُكتب في الصفّ نفسِه، وقد يتكرّر لحظةً
    const [open, setOpen] = useState<number | null>(null);
    const [doomed, setDoomed] = useState<number | null>(null);

    const write = (next: StoreCollection[]) => onChange(serializeCollections(next));
    const patch = (at: number, changes: Partial<StoreCollection>) =>
        write(list.map((c, k) => (k === at ? { ...c, ...changes } : c)));

    const move = (i: number, by: number) => {
        const j = i + by;
        if (j < 0 || j >= list.length) return;

        const next = [...list];
        [next[i], next[j]] = [next[j], next[i]];
        write(next);
        setOpen(open === i ? j : open === j ? i : open);
        setDoomed(null);
    };

    const add = () => {
        write([...list, blank(list.map((c) => c.slug))]);
        setOpen(list.length);
    };

    const remove = (at: number) => {
        write(list.filter((_, k) => k !== at));
        setDoomed(null);
        setOpen(null);
    };

    const iconButton = 'flex size-10 shrink-0 items-center justify-center rounded-[8px] text-[#6b7280] enabled:hover:bg-[#f3f4f6] disabled:opacity-30';

    return (
        <Field label={label} hint={hint} error={error}>
            <div className="space-y-3" data-testid={`collections-${name}`}>
                {list.length === 0 ? (
                    <p data-testid="collections-none" className="rounded-[10px] bg-[#f9fafb] px-3 py-3 text-[12px] leading-relaxed text-[#6b7280]">
                        {t('لا مجموعات بعد — أنشئ مجموعةً واختر منتجاتها من أيّ فئة، ويبقى كلُّ منتجٍ في فئته.')}
                    </p>
                ) : (
                    <ol className="divide-y divide-[var(--ui-border,#e8e8e8)] overflow-hidden rounded-[12px] bg-white ring-1 ring-[var(--ui-border,#e8e8e8)]">
                        {list.map((c, i) => {
                            const expanded = open === i;
                            const title = c.title_ar || c.title_en;
                            const slugOk = validSlug(c.slug) && list.filter((x) => x.slug === c.slug).length === 1;

                            return (
                                <li key={i} data-testid={`collection-${i}`} className="bg-white">
                                    <div className="flex items-center gap-1.5 px-2 py-1.5">
                                        <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-[#111] text-[11px] font-semibold text-white">
                                            {i + 1}
                                        </span>
                                        <button
                                            type="button"
                                            onClick={() => setOpen(expanded ? null : i)}
                                            aria-expanded={expanded}
                                            className="min-w-0 flex-1 px-1 text-start"
                                        >
                                            <span className={cn('block truncate text-[13px] font-medium', c.visible ? 'text-[#111]' : 'text-[#9ca3af]')}>
                                                {title || t('مجموعة بلا اسم')}
                                            </span>
                                            <span className="block truncate text-[11px] text-[#9ca3af]" dir="ltr">
                                                /shop?collection={c.slug} · {t(':n منتجات', { n: String(c.products.length) })}
                                            </span>
                                        </button>
                                        <button
                                            type="button"
                                            role="switch"
                                            aria-checked={c.visible}
                                            aria-label={`${t('ظاهرة لزبائنك')} ${title}`}
                                            data-testid={`collection-${i}-visible`}
                                            onClick={() => patch(i, { visible: ! c.visible })}
                                            className={iconButton}
                                        >
                                            {c.visible ? <Eye className="size-4" /> : <EyeOff className="size-4" />}
                                        </button>
                                        <button type="button" aria-label={`${t('تحريك لأعلى')} ${title}`} disabled={i === 0} onClick={() => move(i, -1)} className={iconButton}>
                                            <ArrowUp className="size-4" />
                                        </button>
                                        <button type="button" aria-label={`${t('تحريك لأسفل')} ${title}`} disabled={i === list.length - 1} onClick={() => move(i, 1)} className={iconButton}>
                                            <ArrowDown className="size-4" />
                                        </button>
                                        <ChevronDown className={cn('size-4 shrink-0 text-[#d1d5db] transition-transform', expanded && 'rotate-180')} />
                                    </div>

                                    {expanded && (
                                        <div className="space-y-4 border-t border-[var(--ui-border,#e8e8e8)] bg-[#fafafa] px-3 py-4">
                                            <Field label="اسم المجموعة — العربية">
                                                <Input
                                                    value={c.title_ar}
                                                    maxLength={60}
                                                    onChange={(e) => patch(i, { title_ar: e.target.value })}
                                                    aria-label={t('اسم المجموعة — العربية')}
                                                    data-testid={`collection-${i}-title-ar`}
                                                />
                                            </Field>
                                            <Field label="Collection name — English" hint="للصفحة الإنجليزيّة — وبلا اسمٍ إنجليزيّ يظهر الاسمُ العربيّ">
                                                <Input
                                                    dir="ltr"
                                                    value={c.title_en}
                                                    maxLength={60}
                                                    onChange={(e) => patch(i, { title_en: e.target.value })}
                                                    aria-label="Collection name — English"
                                                    data-testid={`collection-${i}-title-en`}
                                                />
                                            </Field>
                                            <Field label="الوصف — العربية" hint="اختياري — تحت اسم المجموعة في صفحتها">
                                                <Textarea
                                                    rows={3}
                                                    value={c.desc_ar}
                                                    maxLength={300}
                                                    onChange={(e) => patch(i, { desc_ar: e.target.value })}
                                                    aria-label={t('الوصف — العربية')}
                                                />
                                            </Field>
                                            <Field label="Description — English" hint="اختياري — ولا يُعرض الوصفُ العربيّ في الصفحة الإنجليزيّة">
                                                <Textarea
                                                    rows={3}
                                                    dir="ltr"
                                                    value={c.desc_en}
                                                    maxLength={300}
                                                    onChange={(e) => patch(i, { desc_en: e.target.value })}
                                                    aria-label="Description — English"
                                                />
                                            </Field>
                                            <StoreImageField
                                                name={`collection-${i}-image`}
                                                label="صورة المجموعة"
                                                hint="اختيارية — في رأس صفحة المجموعة، وفي بطاقات الشريط المصوّر"
                                                value={c.image}
                                                onChange={(url) => patch(i, { image: url })}
                                                fit={c.fit}
                                                onFitChange={(fit) => patch(i, { fit })}
                                                ratio="21 / 8"
                                            />
                                            <Toggle
                                                on={c.in_row}
                                                onChange={(v) => patch(i, { in_row: v })}
                                                label="تظهر في صفّ خيارات المتجر"
                                                hint="بعد «كل المنتجات» و«الأكثر مبيعًا» — أطفئه لتصلها من الشريط المصوّر وحده"
                                            />
                                            <Field
                                                label="رابط المجموعة"
                                                hint="حروفٌ إنجليزية صغيرة وأرقام وشرطة — وتغييره يُبطل الروابط التي شاركتها"
                                                error={slugOk ? undefined : t('رابطٌ غير صالح أو مكرّر')}
                                            >
                                                <div className="flex items-center gap-2" dir="ltr">
                                                    <span className="shrink-0 text-[12px] text-[#6b7280]">/shop?collection=</span>
                                                    <Input
                                                        dir="ltr"
                                                        value={c.slug}
                                                        maxLength={40}
                                                        onChange={(e) => patch(i, { slug: e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, '') })}
                                                        aria-label={t('رابط المجموعة')}
                                                        data-testid={`collection-${i}-slug`}
                                                    />
                                                </div>
                                            </Field>
                                            <ProductListField
                                                name={`collection-${i}`}
                                                label="منتجات المجموعة"
                                                hint="من أيّ فئة، وبترتيبك — والمنتجُ يبقى في فئته ويظهر في أكثر من مجموعة"
                                                value={c.products.join(',')}
                                                products={products}
                                                max={maxProducts}
                                                onChange={(v) => patch(i, { products: v.split(',').map(Number).filter((n) => n > 0) })}
                                            />
                                            <div className="flex flex-wrap items-center justify-between gap-2">
                                                {onPreview && (
                                                    <Button type="button" variant="ghost" size="sm" onClick={() => onPreview(c.slug)} data-testid={`collection-${i}-preview`}>
                                                        <Eye />
                                                        {t('عاين صفحة المجموعة')}
                                                    </Button>
                                                )}
                                                {doomed === i ? (
                                                    <span className="flex items-center gap-2">
                                                        <Button
                                                            type="button"
                                                            variant="danger"
                                                            size="sm"
                                                            data-testid={`collection-${i}-delete-confirm`}
                                                            onClick={() => remove(i)}
                                                        >
                                                            {t('تأكيد الحذف')}
                                                        </Button>
                                                        <Button type="button" variant="ghost" size="sm" onClick={() => setDoomed(null)}>
                                                            {t('تراجع')}
                                                        </Button>
                                                    </span>
                                                ) : (
                                                    <Button type="button" variant="ghost" size="sm" data-testid={`collection-${i}-delete`} onClick={() => setDoomed(i)}>
                                                        <Trash2 />
                                                        {t('احذف المجموعة')}
                                                    </Button>
                                                )}
                                            </div>
                                        </div>
                                    )}
                                </li>
                            );
                        })}
                    </ol>
                )}

                <Button type="button" variant="outline" size="sm" disabled={list.length >= max} onClick={add} data-testid="collections-add">
                    <Plus />
                    {list.length >= max ? t('بلغت الحدّ — :max مجموعة', { max: String(max) }) : t('مجموعة جديدة')}
                </Button>
            </div>
        </Field>
    );
}
