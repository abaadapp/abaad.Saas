import { ArrowDown, ArrowUp, Lock, RotateCcw } from 'lucide-react';

import Field from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { useTranslate } from '@/lib/i18n';
import type { StoreCollection } from './CollectionsField';

/**
 * ترتيبُ صفّ خيارات المتجر — «كل المنتجات» و«الأكثر مبيعًا» ومجموعاتُه وفئاتُه.
 *
 * ═══ ما يُرتَّب هو ما يظهر ═══
 *
 * القائمةُ تُبنى ممّا في الشاشة الآن: المجموعاتُ الظاهرةُ المأذونُ لها في الصفّ
 * (`in_row`)، والفئاتُ المختارةُ في «رأس المتجر» التي فيها منتجٌ معروض — فلا
 * يُرتَّب زرٌّ لا يُرسم. والقيمةُ رموزٌ بترتيبها (`all,best,col:eid,cat:12`)،
 * يقرؤها الخادمُ بصرامة (`Store\StoreHeader::validatedRow`).
 *
 * و«كل المنتجات» لا يُحذف: بابُ البضاعة كلِّها يبقى في الصفّ أينما وُضع. ومن لم
 * يرتّب يبقى صفُّه كما كان — والفراغُ هو ذلك.
 */

export interface RowItem {
    token: string;
    label: string;
}

/**
 * أزرارُ الصفّ بترتيبها كما سيراها الزائر — قاعدةُ `StoreHeader::ordered` نفسُها:
 * المحفوظُ بترتيبه، وما لم يُذكر بعده بترتيبه الأصليّ، و«كل المنتجات» دائمًا.
 */
export function rowItems(
    value: string,
    collections: StoreCollection[],
    shortcutIds: number[],
    categories: { id: number; name: string; shown?: number }[],
    labels: { all: string; best: string },
): RowItem[] {
    const base: RowItem[] = [
        { token: 'all', label: labels.all },
        { token: 'best', label: labels.best },
        ...collections
            .filter((c) => c.visible && c.in_row)
            .map((c) => ({ token: `col:${c.slug}`, label: c.title_ar || c.title_en || c.slug })),
        ...shortcutIds
            .map((id) => categories.find((c) => c.id === id && (c.shown ?? 1) > 0))
            .filter((c): c is { id: number; name: string; shown?: number } => !! c)
            .map((c) => ({ token: `cat:${c.id}`, label: c.name })),
    ];

    const tokens = value.split(',').map((x) => x.trim()).filter(Boolean);

    if (! tokens.length) return base;
    if (! tokens.includes('all')) tokens.unshift('all');

    const rank = (token: string, i: number) => {
        const at = tokens.indexOf(token);

        return at >= 0 ? at : tokens.length + i;
    };

    return base
        .map((item, i) => ({ item, at: rank(item.token, i) }))
        .sort((a, b) => a.at - b.at)
        .map(({ item }) => item);
}

export default function ShopRowField({
    label,
    hint,
    value,
    collections,
    shortcutIds,
    categories,
    onChange,
    error,
}: {
    label: string;
    hint?: string;
    value: string;
    /** المجموعاتُ كما في الشاشة الآن — ولو لم تُحفظ بعد */
    collections: StoreCollection[];
    /** والفئاتُ المختارةُ في «رأس المتجر» بترتيبها */
    shortcutIds: number[];
    categories: { id: number; name: string; shown?: number }[];
    onChange: (value: string) => void;
    error?: string;
}) {
    const t = useTranslate();
    const items = rowItems(value, collections, shortcutIds, categories, { all: t('كل المنتجات'), best: t('الأكثر مبيعًا') });

    const move = (i: number, by: number) => {
        const j = i + by;
        if (j < 0 || j >= items.length) return;

        const next = [...items];
        [next[i], next[j]] = [next[j], next[i]];
        onChange(next.map((x) => x.token).join(','));
    };

    const iconButton = 'flex size-10 shrink-0 items-center justify-center rounded-[8px] text-[#6b7280] enabled:hover:bg-[#f3f4f6] disabled:opacity-30';

    return (
        <Field label={label} hint={hint} error={error}>
            <div className="space-y-2" data-testid="shoprow">
                <ol className="divide-y divide-[var(--ui-border,#e8e8e8)] overflow-hidden rounded-[12px] bg-white ring-1 ring-[var(--ui-border,#e8e8e8)]">
                    {items.map((item, i) => (
                        <li key={item.token} className="flex items-center gap-1.5 px-2 py-1" data-testid={`shoprow-${item.token}`}>
                            <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-[#f3f4f6] text-[11px] font-semibold text-[#111]">
                                {i + 1}
                            </span>
                            <span className="min-w-0 flex-1 truncate px-1 text-[13px] text-[#111]">{item.label}</span>
                            {item.token === 'all' && (
                                <span className="flex items-center gap-1 text-[11px] text-[#9ca3af]" title={t('يبقى دائمًا')}>
                                    <Lock className="size-3" />
                                    {t('يبقى دائمًا')}
                                </span>
                            )}
                            <button type="button" aria-label={`${t('تحريك لأعلى')} ${item.label}`} disabled={i === 0} onClick={() => move(i, -1)} className={iconButton}>
                                <ArrowUp className="size-4" />
                            </button>
                            <button type="button" aria-label={`${t('تحريك لأسفل')} ${item.label}`} disabled={i === items.length - 1} onClick={() => move(i, 1)} className={iconButton}>
                                <ArrowDown className="size-4" />
                            </button>
                        </li>
                    ))}
                </ol>
                <p className="text-[12px] leading-relaxed text-[#6b7280]">
                    {t('المجموعةُ المخفيّة، أو التي أطفأتَ ظهورها في الصفّ، لا تُرتَّب هنا — والفئاتُ تُختار من «رأس المتجر».')}
                </p>
                {value !== '' && (
                    <Button type="button" variant="ghost" size="sm" onClick={() => onChange('')} data-testid="shoprow-reset">
                        <RotateCcw />
                        {t('عُد إلى الترتيب الأصليّ')}
                    </Button>
                )}
            </div>
        </Field>
    );
}
