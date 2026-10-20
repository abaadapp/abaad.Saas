import { useMemo, useState } from 'react';
import { ArrowDown, ArrowUp, Check, EyeOff, ImageOff, PackageSearch, Plus, Search, X } from 'lucide-react';

import Field from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { ListProduct } from './ProductListField';

/**
 * اختيارُ المنتجات بصورها وأسمائها — لمجموعات المتجر ولصفحات المنتجات.
 *
 * ═══ ولمَ نافذةٌ بدل القائمة الطويلة ═══
 *
 * كانت المجموعةُ تعرض المنتجاتِ كلَّها في صندوقٍ بارتفاع مئتين وستّين بكسلًا،
 * بلا بحث، بالاسم العربيّ وحده، وتُضاف واحدًا واحدًا. ومتجرٌ بمئتي باقةٍ يمرّر
 * صاحبُه ذلك الصندوقَ بإصبعه داخل صفحةٍ تتمرّر هي أيضًا — فيظنّ أنّه لا يستطيع
 * الإضافة. فصارت نافذةً تملأ الشاشة: بحثٌ بالعربيّة والإنجليزيّة، وصورٌ كبيرة،
 * واختيارُ عدّةٍ معًا ثمّ «إضافة المختارة».
 *
 * ═══ والمختارُ لا يضيع ═══
 *
 * منتجٌ أخفاه صاحبُه بعد اختياره يبقى في قائمته موسومًا «مخفيّ الآن» — لا
 * يُسقَط حين تُفتح الشاشة ولا مع خطأ تحميل. ويُزال بيده إن أراد.
 */

/** نصٌّ يُبحث فيه — بلا تشكيلٍ ولا تطويل، والهمزاتُ والتاءُ والياءُ موحّدة */
export function normalize(text: string): string {
    return text
        .toLowerCase()
        .replace(/[ً-ٰٟـ]/g, '')
        .replace(/[أإآٱ]/g, 'ا')
        .replace(/ى/g, 'ي')
        .replace(/ة/g, 'ه')
        .replace(/ؤ/g, 'و')
        .replace(/ئ/g, 'ي')
        .trim();
}

/** أيطابق المنتجُ ما كُتب؟ — كلُّ كلمةٍ في الاسمين أو الفئة، بأيّ ترتيب */
export function matches(p: ListProduct, query: string): boolean {
    const hay = normalize([p.name, p.name_en ?? '', p.category ?? ''].join(' '));

    return normalize(query).split(/\s+/).filter(Boolean).every((term) => hay.includes(term));
}

function Thumb({ image, size = 'size-10' }: { image: string | null; size?: string }) {
    const [broken, setBroken] = useState(false);

    return image && ! broken ? (
        <img src={image} alt="" loading="lazy" onError={() => setBroken(true)} className={cn(size, 'shrink-0 rounded-lg bg-[#f3f4f6] object-cover')} />
    ) : (
        <span className={cn(size, 'flex shrink-0 items-center justify-center rounded-lg bg-[#f3f4f6] text-[#c4c4c4]')}>
            <ImageOff className="size-4" />
        </span>
    );
}

/**
 * النافذة — المعروضُ في متجره وحده، والمختارُ سلفًا موسومٌ «مضاف».
 */
export function ProductPicker({
    open,
    onOpenChange,
    products,
    chosen,
    max,
    onAdd,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    products: ListProduct[];
    chosen: number[];
    max: number;
    onAdd: (ids: number[]) => void;
}) {
    const t = useTranslate();
    const [query, setQuery] = useState('');
    const [picked, setPicked] = useState<number[]>([]);

    const found = useMemo(() => (query.trim() === '' ? products : products.filter((p) => matches(p, query))), [products, query]);
    const room = Math.max(0, max - chosen.length);
    const full = picked.length >= room;

    const close = (next: boolean) => {
        onOpenChange(next);

        if (! next) {
            setPicked([]);
            setQuery('');
        }
    };

    const toggle = (id: number) =>
        setPicked((now) => (now.includes(id) ? now.filter((x) => x !== id) : full ? now : [...now, id]));

    return (
        <Dialog open={open} onOpenChange={close}>
            <DialogContent className="max-w-2xl p-0" data-testid="picker">
                <DialogHeader className="space-y-3 border-b border-[var(--ui-border,#e8e8e8)] px-4 pb-3 pt-4">
                    <DialogTitle>{t('اختيار المنتجات')}</DialogTitle>
                    <div className="relative">
                        <Search className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-[#9ca3af]" />
                        <Input
                            autoFocus
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                            placeholder={t('ابحث بالاسم العربي أو الإنجليزي')}
                            aria-label={t('ابحث بالاسم العربي أو الإنجليزي')}
                            className="ps-9"
                            data-testid="picker-search"
                        />
                    </div>
                    <p className="text-[12px] text-[#6b7280]" data-testid="picker-room">
                        {room === 0
                            ? t('بلغت الحدّ — أزل منتجًا لتضيف غيره.')
                            : t('اختر حتى :n منتجات — اضغط المنتج لتختاره.', { n: String(room) })}
                    </p>
                </DialogHeader>

                <div className="max-h-[min(60dvh,520px)] overflow-y-auto overscroll-contain px-4 py-3">
                    {products.length === 0 ? (
                        <p data-testid="picker-empty" className="flex flex-col items-center gap-2 py-10 text-center text-[13px] leading-relaxed text-[#6b7280]">
                            <PackageSearch className="size-8 text-[#c4c4c4]" />
                            {t('لا منتجات معروضة في متجرك بعد — اعرض منتجًا من شاشة الأصناف ثمّ عُد إلى هنا.')}
                        </p>
                    ) : found.length === 0 ? (
                        <p data-testid="picker-nomatch" className="py-10 text-center text-[13px] text-[#6b7280]">
                            {t('لا منتج يطابق «:q» — جرّب كلمةً أقصر أو الاسمَ بلغته الأخرى.', { q: query.trim() })}
                        </p>
                    ) : (
                        <ul className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                            {found.map((p) => {
                                const added = chosen.includes(p.id);
                                const on = picked.includes(p.id);

                                return (
                                    <li key={p.id}>
                                        <button
                                            type="button"
                                            disabled={added || (full && ! on)}
                                            aria-pressed={on}
                                            onClick={() => toggle(p.id)}
                                            data-testid={`picker-item-${p.id}`}
                                            className={cn(
                                                'relative flex h-full w-full flex-col gap-1.5 rounded-[12px] p-2 text-start ring-1 transition',
                                                on ? 'bg-[#f0fdf4] ring-2 ring-[#16a34a]' : 'bg-white ring-[var(--ui-border,#e8e8e8)] enabled:hover:ring-[#9ca3af]',
                                                (added || (full && ! on)) && 'opacity-50',
                                            )}
                                        >
                                            <Thumb image={p.image} size="aspect-square w-full" />
                                            <span className="line-clamp-2 text-[13px] font-medium text-[#111]">{p.name}</span>
                                            {p.name_en && p.name_en !== p.name && (
                                                <span className="line-clamp-1 text-[11px] text-[#6b7280]" dir="ltr">{p.name_en}</span>
                                            )}
                                            {p.category && <span className="line-clamp-1 text-[11px] text-[#9ca3af]">{p.category}</span>}
                                            {(on || added) && (
                                                <span className="absolute end-3 top-3 flex items-center gap-1 rounded-full bg-[#16a34a] px-2 py-0.5 text-[11px] font-medium text-white">
                                                    <Check className="size-3" />
                                                    {added ? t('مضاف') : t('مختار')}
                                                </span>
                                            )}
                                        </button>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </div>

                <div className="flex flex-wrap items-center justify-end gap-2 border-t border-[var(--ui-border,#e8e8e8)] px-4 py-3">
                    <Button type="button" variant="ghost" onClick={() => close(false)}>
                        {t('إلغاء')}
                    </Button>
                    <Button
                        type="button"
                        disabled={picked.length === 0}
                        onClick={() => {
                            onAdd(picked);
                            close(false);
                        }}
                        data-testid="picker-add"
                    >
                        <Plus />
                        {picked.length === 0 ? t('إضافة المختارة') : t('إضافة المختارة (:n)', { n: String(picked.length) })}
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}

/**
 * المنتجاتُ المختارة بترتيبها — صورةٌ واسم، وسهمان، وإزالة، وزرُّ «اختيار المنتجات».
 *
 * والمخفيُّ الآن يُرسم من `hidden` موسومًا، والمحذوفُ من متجره يُقال إنّه حُذف —
 * ولا يُسقط أيٌّ منهما بلا يده.
 */
export default function ChosenProducts({
    name,
    label,
    hint,
    ids,
    products,
    hidden = [],
    max,
    onChange,
    error,
}: {
    /** مفتاحُ الحقل — منه تُبنى أسماءُ الاختبار */
    name: string;
    label: string;
    hint?: string;
    ids: number[];
    /** المعروضُ في متجره — منه يُختار */
    products: ListProduct[];
    /** ما اختاره ثمّ أخفاه — يُعرض ولا يُختار */
    hidden?: ListProduct[];
    max: number;
    onChange: (ids: number[]) => void;
    error?: string;
}) {
    const t = useTranslate();
    const [picking, setPicking] = useState(false);
    const known = useMemo(() => new Map([...hidden, ...products].map((p) => [p.id, p])), [products, hidden]);
    const shown = useMemo(() => new Set(products.map((p) => p.id)), [products]);

    const move = (i: number, by: number) => {
        const j = i + by;
        if (j < 0 || j >= ids.length) return;

        const next = [...ids];
        [next[i], next[j]] = [next[j], next[i]];
        onChange(next);
    };

    const iconButton = 'flex size-10 shrink-0 items-center justify-center rounded-[8px] text-[#6b7280] enabled:hover:bg-[#f3f4f6] disabled:opacity-30';

    return (
        <Field label={label} hint={hint} error={error}>
            <div className="space-y-2" data-testid={`chosen-${name}`}>
                {ids.length === 0 ? (
                    <p data-testid={`chosen-${name}-none`} className="rounded-[10px] bg-[#f9fafb] px-3 py-3 text-[12px] text-[#6b7280]">
                        {t('لم تختر منتجًا بعد — اضغط «اختيار المنتجات».')}
                    </p>
                ) : (
                    <ol className="divide-y divide-[var(--ui-border,#e8e8e8)] overflow-hidden rounded-[10px] bg-white ring-1 ring-[var(--ui-border,#e8e8e8)]">
                        {ids.map((id, i) => {
                            const p = known.get(id);
                            const label = p ? p.name : t('منتج محذوف');

                            return (
                                <li key={id} data-testid={`chosen-${name}-${id}`} className="flex items-center gap-2 px-2 py-1.5">
                                    <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-[#111] text-[11px] font-semibold text-white">{i + 1}</span>
                                    <Thumb image={p?.image ?? null} />
                                    <span className="min-w-0 flex-1">
                                        <span className={cn('block truncate text-[13px]', shown.has(id) ? 'text-[#111]' : 'text-[#9ca3af]')}>{label}</span>
                                        {! shown.has(id) && (
                                            <span className="flex items-center gap-1 text-[11px] text-[#b45309]" data-testid={`chosen-${name}-${id}-hidden`}>
                                                <EyeOff className="size-3" />
                                                {p ? t('مخفيّ الآن — لا يظهر لزبائنك حتى تعرضه') : t('لم يعد في متجرك — أزله')}
                                            </span>
                                        )}
                                    </span>
                                    <button type="button" aria-label={`${t('تحريك لأعلى')} ${label}`} disabled={i === 0} onClick={() => move(i, -1)} className={iconButton}>
                                        <ArrowUp className="size-4" />
                                    </button>
                                    <button type="button" aria-label={`${t('تحريك لأسفل')} ${label}`} disabled={i === ids.length - 1} onClick={() => move(i, 1)} className={iconButton}>
                                        <ArrowDown className="size-4" />
                                    </button>
                                    <button type="button" aria-label={`${t('إزالة')} ${label}`} data-testid={`chosen-${name}-${id}-remove`} onClick={() => onChange(ids.filter((x) => x !== id))} className={iconButton}>
                                        <X className="size-4" />
                                    </button>
                                </li>
                            );
                        })}
                    </ol>
                )}

                <Button type="button" variant="outline" size="sm" onClick={() => setPicking(true)} disabled={ids.length >= max} data-testid={`chosen-${name}-pick`}>
                    <Plus />
                    {ids.length >= max ? t('بلغت الحدّ — :max منتجًا', { max: String(max) }) : t('اختيار المنتجات')}
                </Button>

                <ProductPicker
                    open={picking}
                    onOpenChange={setPicking}
                    products={products}
                    chosen={ids}
                    max={max}
                    onAdd={(more) => onChange([...ids, ...more.filter((x) => ! ids.includes(x))].slice(0, max))}
                />
            </div>
        </Field>
    );
}
