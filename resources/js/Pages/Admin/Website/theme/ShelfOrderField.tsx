import { useState } from 'react';
import { ArrowDown, ArrowUp, CircleAlert, EyeOff, ImageOff, Save } from 'lucide-react';

import { Button } from '@/Components/ui/button';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * ترتيبُ المنتجات في رفّ المتجر — «كل المنتجات» وكلُّ فئةٍ بقائمتها (`Store\ShelfOrder`).
 *
 * ═══ قوائمُ منفصلة ═══
 *
 * ترتيبُ «كل المنتجات» لا يُبدّل ترتيبَ فئة، ولا العكس. وقائمةٌ لم تُرتَّب
 * تبقى أبجديّةً كما كانت — وهي أوّلُ ما يُرتَّب منه حين يختار «يدوي».
 *
 * ═══ والسهمان لا السحب ═══
 *
 * كأخواته في المحرّر: يعملان بالإصبع وبلوحة المفاتيح وبالقارئ الصوتيّ، وكلٌّ
 * بلمسةٍ كاملة (٤٤ بكسل). والترتيبُ في نموذج المحرّر فتُريه المعاينةُ قبل
 * الحفظ، والحفظُ بزرّه.
 *
 * ولا تصل إلّا لمتجرٍ في قائمتها (`shelfOrder` من الخادم).
 */
export interface OrderProduct {
    id: number;
    name: string;
    image: string | null;
    category_id: number | null;
    /** معروضٌ الآن — والمخفيُّ يصل إن كان له موضعٌ في قائمة، فيبقى موضعُه */
    shown: boolean;
}

export interface ShelfOrderSeed {
    /** القوائمُ كما في المسوّدة — نصُّ JSON */
    value: string;
    /** بترتيب الرفّ الأبجديّ نفسِه */
    products: OrderProduct[];
}

export interface ShelfOrderState {
    all: number[];
    categories: Record<string, number[]>;
}

/** «كل المنتجات» في المنتقي — والفئاتُ بمعرّفها */
const ALL = 'all';

const ids = (raw: unknown): number[] =>
    Array.isArray(raw) ? [...new Set(raw.filter((v): v is number => Number.isInteger(v) && v > 0))] : [];

/** المحفوظُ مقروءًا — وما لا يُفهم يُقرأ فراغًا */
export function parseShelfOrder(json: string): ShelfOrderState {
    let data: unknown = null;
    try {
        data = JSON.parse(json || '{}');
    } catch {
        data = null;
    }
    const d = data && typeof data === 'object' && ! Array.isArray(data) ? (data as Record<string, unknown>) : {};
    const cats = d.categories && typeof d.categories === 'object' && ! Array.isArray(d.categories) ? (d.categories as Record<string, unknown>) : {};

    return {
        all: ids(d.all),
        categories: Object.fromEntries(Object.entries(cats).map(([k, v]) => [k, ids(v)]).filter(([k, v]) => /^[1-9]\d*$/.test(k as string) && (v as number[]).length > 0)),
    };
}

/**
 * بصيغة الخادم نفسِها (`ShelfOrder::validated`): القائمةُ الفارغة لا تُكتب،
 * والفئاتُ بمعرّفها تصاعديًّا — فحفظُ ما لم يتغيّر نصٌّ مطابقٌ لما حُفظ.
 */
export function serializeShelfOrder(o: ShelfOrderState): string {
    const out: Record<string, unknown> = {};
    if (o.all.length > 0) out.all = o.all;

    const cats = Object.keys(o.categories)
        .filter((k) => o.categories[k].length > 0)
        .sort((a, b) => Number(a) - Number(b));
    if (cats.length > 0) out.categories = Object.fromEntries(cats.map((k) => [k, o.categories[k]]));

    return Object.keys(out).length === 0 ? '' : JSON.stringify(out);
}

/**
 * الرفُّ كما يراه الزبون: المرتَّبُ بموضعه، ثمّ ما لم يُرتَّب بترتيب إضافته
 * (`ShelfOrder::sort`) — أو الأبجديّةُ كما وصلت إن لم تُرتَّب القائمة.
 */
export function arrange(members: OrderProduct[], list: number[] | null): OrderProduct[] {
    if (list === null || list.length === 0) return members;

    const at = new Map(list.map((id, i) => [id, i]));

    return [...members].sort((a, b) => (at.get(a.id) ?? list.length + a.id) - (at.get(b.id) ?? list.length + b.id));
}

/** أصنافُ قائمةٍ: المعروضُ منها، والمخفيُّ الذي له موضعٌ فيها */
export function membersOf(products: OrderProduct[], list: string, saved: number[]): OrderProduct[] {
    const placed = new Set(saved);

    return products.filter(
        (p) => (list === ALL || p.category_id === Number(list)) && (p.shown || placed.has(p.id)),
    );
}

export default function ShelfOrderPanel({
    seed,
    value,
    onChange,
    onSave,
    saving,
    dirty,
    error,
    categories,
}: {
    seed: ShelfOrderSeed;
    value: string;
    onChange: (value: string) => void;
    onSave: () => void;
    saving: boolean;
    dirty: boolean;
    error?: string;
    categories: { id: number; name: string }[];
}) {
    const t = useTranslate();
    const [list, setList] = useState<string>(ALL);

    const order = parseShelfOrder(value);
    const stored = list === ALL ? order.all : (order.categories[list] ?? []);
    const manual = stored.length > 0;
    const members = membersOf(seed.products, list, stored);
    const shownOrder = arrange(members, manual ? stored : null);

    // والفئاتُ التي فيها ما يُرتَّب — بترتيب الموقع كما وصلت
    const lists = categories.filter((c) => seed.products.some((p) => p.category_id === c.id));

    const write = (next: number[]) =>
        onChange(
            serializeShelfOrder(
                list === ALL ? { ...order, all: next } : { ...order, categories: { ...order.categories, [list]: next } },
            ),
        );

    const move = (i: number, by: number) => {
        const j = i + by;
        if (j < 0 || j >= shownOrder.length) return;

        const next = shownOrder.map((p) => p.id);
        [next[i], next[j]] = [next[j], next[i]];
        write(next);
    };

    const iconButton = 'flex size-11 shrink-0 items-center justify-center rounded-[8px] text-[#6b7280] enabled:hover:bg-[#f3f4f6] disabled:opacity-30';

    return (
        <div data-testid="shelf-order" className="mt-5 space-y-3 border-t border-[var(--ui-border,#e8e8e8)] pt-4">
            <div>
                <p className="text-[13px] font-semibold text-[#111]">{t('ترتيب المنتجات في المتجر')}</p>
                <p className="mt-1 text-[12px] leading-relaxed text-[#6b7280]">
                    {t('لكل قائمة ترتيبها: ترتيب «كل المنتجات» لا يغيّر ترتيب الفئات، ولا العكس. والمنتج الجديد يظهر في آخر القائمة حتى ترتّبه.')}
                </p>
            </div>

            <label className="block">
                <span className="mb-1 block text-[12px] font-medium text-[#374151]">{t('القائمة')}</span>
                <select
                    value={list}
                    onChange={(e) => setList(e.target.value)}
                    data-testid="shelf-order-list"
                    className="h-11 w-full rounded-[10px] bg-white px-3 text-[13px] ring-1 ring-[var(--ui-border,#e8e8e8)]"
                >
                    <option value={ALL}>{t('كل المنتجات')}</option>
                    {lists.map((c) => (
                        <option key={c.id} value={String(c.id)}>
                            {c.name}
                        </option>
                    ))}
                </select>
            </label>

            <fieldset data-testid="shelf-order-mode" className="grid gap-2 sm:grid-cols-2">
                <legend className="sr-only">{t('طريقة الترتيب')}</legend>
                {([
                    [false, 'أبجدي — كما هو الآن'],
                    [true, 'ترتيب يدوي'],
                ] as const).map(([isManual, label]) => (
                    <label
                        key={label}
                        className={cn(
                            'flex min-h-11 cursor-pointer items-center gap-2.5 rounded-[10px] bg-white px-3 py-2 text-[13px] ring-1',
                            manual === isManual ? 'text-[#111] ring-[#111]' : 'text-[#374151] ring-[var(--ui-border,#e8e8e8)]',
                        )}
                    >
                        <input
                            type="radio"
                            name="shelf-order-mode"
                            checked={manual === isManual}
                            disabled={members.length === 0}
                            // ومن ينتقل إلى اليدويّ يبدأ بما يراه الزبونُ الآن — لا بصفحةٍ بيضاء
                            onChange={() => write(isManual ? members.map((p) => p.id) : [])}
                            className="size-4 accent-[#111]"
                        />
                        {t(label)}
                    </label>
                ))}
            </fieldset>

            {members.length === 0 ? (
                <p className="rounded-[10px] bg-[#f9fafb] px-3 py-3 text-[12px] text-[#6b7280]">{t('لا منتجات معروضة في هذه القائمة.')}</p>
            ) : (
                <ol
                    data-testid="shelf-order-items"
                    className="max-h-[420px] divide-y divide-[var(--ui-border,#e8e8e8)] overflow-y-auto rounded-[10px] bg-white ring-1 ring-[var(--ui-border,#e8e8e8)]"
                >
                    {shownOrder.map((p, i) => (
                        <li key={p.id} data-testid={`shelf-order-${p.id}`} className="flex items-center gap-2 px-2 py-1.5">
                            <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-[#111] text-[11px] font-semibold text-white">
                                {i + 1}
                            </span>
                            {p.image ? (
                                <img src={p.image} alt="" loading="lazy" className="size-10 shrink-0 rounded-lg object-cover" />
                            ) : (
                                <span className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-[#f3f4f6] text-[#c4c4c4]">
                                    <ImageOff className="size-4" />
                                </span>
                            )}
                            <span className="min-w-0 flex-1">
                                <span className={cn('block truncate text-[13px]', p.shown ? 'text-[#111]' : 'text-[#9ca3af]')}>{p.name}</span>
                                {! p.shown && (
                                    <span className="flex items-center gap-1 text-[11px] text-[#9ca3af]">
                                        <EyeOff className="size-3" />
                                        {t('مخفيّ الآن — يبقى في مكانه')}
                                    </span>
                                )}
                            </span>
                            {manual && (
                                <>
                                    <button
                                        type="button"
                                        aria-label={`${t('تحريك لأعلى')} ${p.name}`}
                                        disabled={i === 0}
                                        onClick={() => move(i, -1)}
                                        className={iconButton}
                                    >
                                        <ArrowUp className="size-4" />
                                    </button>
                                    <button
                                        type="button"
                                        aria-label={`${t('تحريك لأسفل')} ${p.name}`}
                                        disabled={i === shownOrder.length - 1}
                                        onClick={() => move(i, 1)}
                                        className={iconButton}
                                    >
                                        <ArrowDown className="size-4" />
                                    </button>
                                </>
                            )}
                        </li>
                    ))}
                </ol>
            )}

            {error && (
                <p role="alert" data-testid="shelf-order-error" className="flex items-start gap-1.5 text-[12px] text-[#b91c1c]">
                    <CircleAlert className="mt-0.5 size-3.5 shrink-0" />
                    {error}
                </p>
            )}

            <div className="flex items-center justify-end gap-3">
                {dirty && <span className="text-[12px] text-[#b45309]">{t('لم يُحفظ بعد')}</span>}
                <Button type="button" size="sm" data-testid="shelf-order-save" loading={saving} disabled={! dirty} onClick={onSave}>
                    <Save />
                    {t('احفظ ترتيب المنتجات')}
                </Button>
            </div>
        </div>
    );
}
