import { ArrowDown, ArrowUp, ImageOff, Plus, X } from 'lucide-react';

import Field from '@/Components/Field';
import { useTranslate } from '@/lib/i18n';

/** صنفٌ يُختار — من المعروض في متجره وحده، كما يرسله المحرّر */
export interface ListProduct {
    id: number;
    name: string;
    image: string | null;
}

/** «12,7,30» ← [12, 7, 30] — معرّفاتٌ موجبةٌ بترتيبها */
export function parseIds(value: string): number[] {
    return value
        .split(',')
        .map((v) => Number(v.trim()))
        .filter((v, i, all) => Number.isInteger(v) && v > 0 && all.indexOf(v) === i);
}

/**
 * ما يُحفظ — المختارُ ممّا يُعرض الآن وحده.
 *
 * صنفٌ أُخفي بعد اختياره لا يُرسَل: الخادمُ يردّ الحفظَ كلَّه من أجله
 * (`Store\ProductList::validated`)، وصاحبُ المحلّ لا يراه في القائمة ليُزيله.
 */
export function keepShown(value: string, products: ListProduct[]): string {
    return parseIds(value)
        .filter((id) => products.some((p) => p.id === id))
        .join(',');
}

function Thumb({ image }: { image: string | null }) {
    return image ? (
        <img src={image} alt="" loading="lazy" className="size-10 shrink-0 rounded-lg object-cover" />
    ) : (
        <span className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-[#f3f4f6] text-[#c4c4c4]">
            <ImageOff className="size-4" />
        </span>
    );
}

/**
 * أصنافٌ يختارها بيده ويرتّبها — «اختيارات RIBBON» و«أضف مع طلبك».
 *
 * وشكلُها شكلُ «وصل حديثًا» اليدويّ نفسُه (`CatalogTools`): المختارُ مرقّمًا
 * بسهمَيه وزرِّ إزالته، وتحته ما يُضاف. فلا يكتب صاحبُ المحلّ رقمًا بيده،
 * ولا يتعلّم شاشتين لعملٍ واحد.
 */
export default function ProductListField({
    name,
    label,
    hint,
    value,
    products,
    max,
    onChange,
    error,
}: {
    /** مفتاحُ الحقل — منه تُبنى أسماءُ الاختبار */
    name: string;
    label: string;
    hint?: string;
    value: string;
    products: ListProduct[];
    max: number;
    onChange: (value: string) => void;
    error?: string;
}) {
    const t = useTranslate();
    const ids = parseIds(value);
    const byId = new Map(products.map((p) => [p.id, p]));
    const chosen = ids.map((id) => byId.get(id)).filter((p): p is ListProduct => p !== undefined);
    const rest = products.filter((p) => ! ids.includes(p.id));
    const full = chosen.length >= max;

    const write = (next: number[]) => onChange(next.join(','));

    const move = (i: number, by: number) => {
        const j = i + by;
        if (j < 0 || j >= chosen.length) return;

        const next = chosen.map((p) => p.id);
        [next[i], next[j]] = [next[j], next[i]];
        write(next);
    };

    const iconButton = 'flex size-11 shrink-0 items-center justify-center rounded-[8px] text-[#6b7280] enabled:hover:bg-[#f3f4f6] disabled:opacity-30';

    return (
        <Field label={label} hint={hint} error={error}>
            <div className="space-y-3" data-testid={`list-${name}`}>
                <p className="text-[12px] leading-relaxed text-[#6b7280]">
                    {t('اختر حتى :max منتجات ورتّبها كما تريد أن تظهر.', { max: String(max) })}
                </p>

                {chosen.length === 0 ? (
                    <p data-testid={`list-${name}-none`} className="rounded-[10px] bg-[#f9fafb] px-3 py-3 text-[12px] text-[#6b7280]">
                        {t('لم تختر منتجًا بعد.')}
                    </p>
                ) : (
                    <ol data-testid={`list-${name}-chosen`} className="divide-y divide-[var(--ui-border,#e8e8e8)] overflow-hidden rounded-[10px] bg-white ring-1 ring-[var(--ui-border,#e8e8e8)]">
                        {chosen.map((p, i) => (
                            <li key={p.id} data-testid={`list-${name}-chosen-${p.id}`} className="flex items-center gap-2 px-2 py-1.5">
                                <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-[#111] text-[11px] font-semibold text-white">
                                    {i + 1}
                                </span>
                                <Thumb image={p.image} />
                                <span className="min-w-0 flex-1 truncate text-[13px] text-[#111]">{p.name}</span>
                                <button type="button" aria-label={`${t('تحريك لأعلى')} ${p.name}`} disabled={i === 0} onClick={() => move(i, -1)} className={iconButton}>
                                    <ArrowUp className="size-4" />
                                </button>
                                <button type="button" aria-label={`${t('تحريك لأسفل')} ${p.name}`} disabled={i === chosen.length - 1} onClick={() => move(i, 1)} className={iconButton}>
                                    <ArrowDown className="size-4" />
                                </button>
                                <button
                                    type="button"
                                    aria-label={`${t('إزالة')} ${p.name}`}
                                    onClick={() => write(chosen.filter((c) => c.id !== p.id).map((c) => c.id))}
                                    className={iconButton}
                                >
                                    <X className="size-4" />
                                </button>
                            </li>
                        ))}
                    </ol>
                )}

                {products.length === 0 ? (
                    <p className="text-[13px] text-[#6b7280]">{t('لا صنفَ معروضًا في متجرك بعد.')}</p>
                ) : rest.length > 0 && (
                    <div className="space-y-1.5">
                        <p className="text-[12px] font-medium text-[#374151]">
                            {full ? t('بلغت الحدّ — أزل منتجًا لتضيف غيره.') : t('منتجات يمكن إضافتها')}
                        </p>
                        <ul data-testid={`list-${name}-rest`} className="max-h-[260px] divide-y divide-[var(--ui-border,#e8e8e8)] overflow-y-auto rounded-[10px] bg-white ring-1 ring-[var(--ui-border,#e8e8e8)]">
                            {rest.map((p) => (
                                <li key={p.id} data-testid={`list-${name}-rest-${p.id}`} className="flex items-center gap-2 px-2 py-1.5">
                                    <Thumb image={p.image} />
                                    <span className="min-w-0 flex-1 truncate text-[13px] text-[#111]">{p.name}</span>
                                    <button
                                        type="button"
                                        aria-label={`${t('إضافة')} ${p.name}`}
                                        disabled={full}
                                        onClick={() => write([...chosen.map((c) => c.id), p.id])}
                                        className={iconButton}
                                    >
                                        <Plus className="size-4" />
                                    </button>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
            </div>
        </Field>
    );
}
