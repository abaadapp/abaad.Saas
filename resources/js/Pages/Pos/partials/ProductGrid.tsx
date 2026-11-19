import { Fragment, memo, useCallback, useLayoutEffect, useRef, useState, type ReactNode, type RefObject } from 'react';
import { Plus } from 'lucide-react';
import { Badge } from '@/Components/ui/badge';
import ProductDescriptionButton from '@/Pages/Pos/partials/ProductDescriptionButton';
import { money as fmtMoney } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { Currency } from '@/types';

/** ما تقرؤه البطاقة من الصنف — والصندوقُ يمرّر صنفَه كاملًا */
export interface GridProduct {
    id: number;
    label: string;
    price: number;
    /** ‏ما يُباع به بعد خصم الصنف — وغيابُه `price` (شبكةٌ لا خصمَ فيها) */
    sale_price?: number;
    image?: string | null;
    thumb?: string | null;
    qty?: number | null;
    tracks_stock?: boolean;
    stock_status?: string;
    pos_description?: string | null;
}

interface Props<P extends GridProduct> {
    products: P[];
    currency: Currency;
    onPick: (product: P) => void;
    /** ‏ما يسبق الأصنافَ في أوّل خانة («تخصيص الطلب») — عنصرٌ ثابتُ المرجع */
    leading?: ReactNode;
    /** ‏العنصرُ الذي يُمرَّر فيه — به تُعرف الصفوفُ الظاهرة */
    scrollRef?: RefObject<HTMLElement | null>;
}

/**
 * ‏من هذا العدد تُرسم البطاقاتُ الظاهرة وحدها، وما دونه كلُّه كما كان.
 *
 * متاجرُ اليوم أصنافُها دون المئتين (أكبرُها 120): لا يتغيّر عندها شيء. ومن
 * المئتين تصير الصفحةُ آلافَ العناصر، وكلُّ إضافةٍ للسلّة تُعيد حسابَ تخطيطها.
 */
export const WINDOW_FROM = 200;
/** ‏صفوفٌ تُرسم فوق الظاهر وتحته — فلا يرى المُمرِّرُ بياضًا */
const OVERSCAN_ROWS = 3;
/** ‏وقبل أن يُقاس شيء: صفوفٌ تملأ أطولَ شاشة */
const FIRST_ROWS = 10;

const GRID = 'grid grid-cols-2 gap-3 lg:grid-cols-3 xl:grid-cols-4';

/**
 * ‏بطاقةُ صنف — محفوظةٌ وحدها: يمرّر الكاشيرُ فتُضاف بطاقاتٌ ولا يُعاد رسمُ الظاهر.
 */
const Card = memo(function Card<P extends GridProduct>({ p, currency, onPick }: { p: P; currency: Currency; onPick: (product: P) => void }) {
    const t = useTranslate();
    const money = (v: number) => fmtMoney(v, currency);

    return (
        // والوصفُ زرٌّ أخٌ للبطاقة لا داخلها: زرٌّ في زرٍّ لا يُضغط وحده
        <div className="relative" data-pos-card="">
            <button
                type="button"
                onClick={() => onPick(p)}
                className="group size-full select-none overflow-hidden rounded-2xl border border-gray-100 bg-white text-start shadow-sm transition-[border-color,box-shadow] hover:border-gray-300 hover:shadow-md"
            >
                <div className="relative aspect-square overflow-hidden bg-gray-50">
                    {p.image ? (
                        <img
                            src={p.thumb || p.image}
                            alt={p.label}
                            loading="lazy"
                            decoding="async"
                            className="size-full object-cover transition-transform duration-300 group-hover:scale-105"
                        />
                    ) : (
                        <span className="flex size-full items-center justify-center text-3xl">📦</span>
                    )}
                    <span className="absolute end-2 top-2">
                        <Badge status={p.stock_status} />
                    </span>
                </div>
                <div className="p-3">
                    <h3 className="truncate text-sm font-semibold text-gray-800">{p.label}</h3>
                    <p className="mt-0.5 text-xs text-gray-400">
                        {p.tracks_stock === false ? t('غير مرتبط بالمخزون') : `${t('المتوفر:')} ${p.qty}`}
                    </p>
                    <div className="mt-2 flex items-center justify-between gap-1">
                        {/* ‏ما يُقبض — وقبل الخصم مشطوبًا حين يكون */}
                        <p className="text-sm font-bold text-gray-900">
                            {money(p.sale_price ?? p.price)}
                            {p.sale_price !== undefined && p.sale_price < p.price && (
                                <span className="ms-1 text-xs font-normal text-gray-400 line-through">{money(p.price)}</span>
                            )}
                        </p>
                        <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-gray-900 transition-colors group-hover:bg-gray-900 group-hover:text-white">
                            <Plus className="size-4" />
                        </span>
                    </div>
                </div>
            </button>
            <ProductDescriptionButton name={p.label} text={p.pos_description} />
        </div>
    );
}) as <P extends GridProduct>(props: { p: P; currency: Currency; onPick: (product: P) => void }) => ReactNode;

/**
 * شبكةُ أصناف الصندوق — تُرسم حين تتغيّر الأصنافُ المعروضة وحدها.
 *
 * كانت في صفحة الصندوق نفسِها، فكلُّ حرفٍ في بحث العميل وكلُّ بندٍ في السلّة
 * يعيد رسمَ البطاقات كلِّها: ألفا بطاقةٍ في متجرٍ بألفي صنف — ٣٤ ملّيثانية للحرف
 * على حاسوب، وأكثر من ١٣٠ على لوحيٍّ بطيء. فصارت مكوّنًا محفوظًا (`memo`):
 * الأصنافُ مرجعٌ ثابت (`useMemo`) والنقرةُ مرجعٌ ثابت (`onPick`)، والبطاقةُ هي هي.
 *
 * ═══ ومن المئتين: الصفوفُ الظاهرة وحدها ═══
 *
 * عشرةُ آلاف بطاقةٍ في الصفحة تعني عشرات آلاف العناصر: فتحُ الصندوق يرسمها كلَّها،
 * ومسحُ البحث يُعيد رسمَها، وكلُّ إضافةٍ للسلّة تُعيد حسابَ تخطيطها. على جهازٍ
 * أبطأ بأربع مرّات: الإضافةُ ثانيةٌ ونصف، ومسحُ البحث أربعُ ثوان.
 *
 * فتُرسم الصفوفُ التي في الإطار وثلاثةٌ فوقها وتحتها، ويقوم مقامَ الباقي فراغان
 * بطول صفوفه (يملآن الصفَّ كلَّه في الشبكة نفسِها) — فيبقى شريطُ التمرير بطول
 * القائمة كلِّها، والترتيبُ والأعمدةُ والاتّجاهُ كما هي. والأعمدةُ وطولُ الصفّ
 * يُقاسان من المرسوم لا يُفترضان: تتغيّر الشاشةُ فيُقاسان من جديد.
 *
 * والبحثُ والماسحُ والأقسامُ لا تقرأ الشبكة: تقرأ الأصناف — فلا يتغيّر فيها شيء.
 */
function ProductGrid<P extends GridProduct>({ products, currency, onPick, leading = null, scrollRef }: Props<P>) {
    const gridRef = useRef<HTMLDivElement>(null);
    const lead = leading ? 1 : 0;
    const total = products.length + lead;
    const windowed = !!scrollRef && products.length >= WINDOW_FROM;

    const [cols, setCols] = useState(4);
    const [rowH, setRowH] = useState(0);
    const [gap, setGap] = useState(0);
    const [rows, setRows] = useState<[number, number]>([0, FIRST_ROWS]);

    /** ‏الأعمدةُ من الشبكة نفسِها، وطولُ الصفّ بين بطاقتين في صفّين متتاليين */
    const measure = useCallback(() => {
        const grid = gridRef.current;
        if (!grid) return;
        const style = window.getComputedStyle(grid);
        const tracks = style.gridTemplateColumns.split(' ').filter((x) => x !== '' && x !== 'none').length;
        const c = Math.max(1, tracks);
        const cards = grid.querySelectorAll<HTMLElement>('[data-pos-card]');
        const h = cards.length > c ? cards[cards.length - 1].offsetTop - cards[cards.length - 1 - c].offsetTop : 0;
        setCols((was) => (was === c ? was : c));
        setGap((was) => {
            const g = parseFloat(style.rowGap) || 0;
            return Math.abs(was - g) < 0.5 ? was : g;
        });
        if (h > 0) setRowH((was) => (Math.abs(was - h) < 0.5 ? was : h));
    }, []);

    /** ‏أيُّ الصفوف في الإطار الآن */
    const place = useCallback(() => {
        const grid = gridRef.current;
        const scroller = scrollRef?.current;
        if (!grid || !scroller || rowH <= 0) return;
        const top = grid.getBoundingClientRect().top - scroller.getBoundingClientRect().top + scroller.scrollTop;
        const from = scroller.scrollTop - top;
        const first = Math.max(0, Math.floor(from / rowH) - OVERSCAN_ROWS);
        const last = Math.max(first + 1, Math.ceil((from + scroller.clientHeight) / rowH) + OVERSCAN_ROWS);
        setRows((was) => (was[0] === first && was[1] === last ? was : [first, last]));
    }, [scrollRef, rowH]);

    // ‏يُقاس قبل الرسم، كلّما تغيّر المرسوم — والقياسُ لا يكتب ما لم يتغيّر
    useLayoutEffect(() => {
        if (windowed) measure();
    }, [windowed, measure, rows, total, cols]);

    // ‏ويتبع التمريرَ وتغيّرَ الإطار — بإطارٍ واحدٍ لكلّ رسم
    useLayoutEffect(() => {
        if (!windowed) return;
        const scroller = scrollRef?.current;
        const grid = gridRef.current;
        if (!scroller || !grid) return;
        let frame = 0;
        const tick = () => {
            frame = 0;
            measure();
            place();
        };
        const later = () => {
            if (!frame) frame = typeof requestAnimationFrame === 'function' ? requestAnimationFrame(tick) : window.setTimeout(tick, 16);
        };
        place();
        scroller.addEventListener('scroll', later, { passive: true });
        const watcher = typeof ResizeObserver === 'undefined' ? null : new ResizeObserver(later);
        watcher?.observe(scroller);
        watcher?.observe(grid);

        return () => {
            scroller.removeEventListener('scroll', later);
            watcher?.disconnect();
            if (frame && typeof cancelAnimationFrame === 'function') cancelAnimationFrame(frame);
        };
    }, [windowed, scrollRef, measure, place, total]);

    if (!windowed) {
        return (
            <div ref={gridRef} className={GRID}>
                {leading}
                {products.map((p) => (
                    <Card key={p.id} p={p} currency={currency} onPick={onPick} />
                ))}
            </div>
        );
    }

    const allRows = Math.ceil(total / cols);
    const shown = Math.max(1, rows[1] - rows[0]);
    // ‏قائمةٌ قصُرت (بحث) والإطارُ تحت آخرها: يُرسم آخرُها لا فراغ
    const first = Math.max(0, Math.min(rows[0], allRows - shown));
    const last = Math.min(allRows, first + shown);
    const cells: ReactNode[] = [];
    for (let i = first * cols; i < Math.min(total, last * cols); i++) {
        cells.push(i < lead ? <Fragment key="leading">{leading}</Fragment> : <Card key={products[i - lead].id} p={products[i - lead]} currency={currency} onPick={onPick} />);
    }
    const spacer = (n: number) => Math.max(0, n * rowH - gap);

    return (
        <div ref={gridRef} className={GRID} data-pos-window={`${first}-${last}/${allRows}`}>
            {rowH > 0 && first > 0 && <div aria-hidden="true" style={{ gridColumn: '1 / -1', height: spacer(first) }} />}
            {cells}
            {rowH > 0 && last < allRows && <div aria-hidden="true" style={{ gridColumn: '1 / -1', height: spacer(allRows - last) }} />}
        </div>
    );
}

export default memo(ProductGrid) as typeof ProductGrid;
