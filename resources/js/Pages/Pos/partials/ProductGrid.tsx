import { memo } from 'react';
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
}

/**
 * شبكةُ أصناف الصندوق — تُرسم حين تتغيّر الأصنافُ المعروضة وحدها.
 *
 * كانت في صفحة الصندوق نفسِها، فكلُّ حرفٍ في بحث العميل وكلُّ بندٍ في السلّة
 * يعيد رسمَ البطاقات كلِّها: ألفا بطاقةٍ في متجرٍ بألفي صنف — ٣٤ ملّيثانية للحرف
 * على حاسوب، وأكثر من ١٣٠ على لوحيٍّ بطيء. فصارت مكوّنًا محفوظًا (`memo`):
 * الأصنافُ مرجعٌ ثابت (`useMemo`) والنقرةُ مرجعٌ ثابت (`onPick`)، والبطاقةُ هي هي.
 */
function ProductGrid<P extends GridProduct>({ products, currency, onPick }: Props<P>) {
    const t = useTranslate();
    const money = (v: number) => fmtMoney(v, currency);

    return (
        <>
        {products.map((p) => (
            // والوصفُ زرٌّ أخٌ للبطاقة لا داخلها: زرٌّ في زرٍّ لا يُضغط وحده
            <div key={p.id} className="relative">
                <button
                    type="button"
                    onClick={() =>
                        onPick(p)
                    }
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
                            <p className="text-sm font-bold text-gray-900">{money(p.price)}</p>
                            <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-gray-900 transition-colors group-hover:bg-gray-900 group-hover:text-white">
                                <Plus className="size-4" />
                            </span>
                        </div>
                    </div>
                </button>
                <ProductDescriptionButton name={p.label} text={p.pos_description} />
            </div>
        ))}
        </>
    );
}

export default memo(ProductGrid) as typeof ProductGrid;
