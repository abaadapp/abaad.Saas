import { useEffect, useMemo, useState } from 'react';

/** ما يعيده pos.stock-feed لكل منتج — الكمية وحالتها لا غير */
interface StockRow {
    id: number;
    qty: number;
    stock_status: string;
}

/** الحد الأدنى المطلوب من المنتج حتى يُدمج معه المخزون الحيّ */
interface HasStock {
    id: number;
    qty: number;
    stock_status: string;
}

/**
 * مخزون حيّ لشاشة البيع.
 *
 * الكاشير يرى «المتوفر» صحيحًا بعد بيعه هو (reload جزئي بعد كل طلب)، لكن
 * بيع زميله على جهاز آخر — أو تعديل المخزون من اللوحة، أو استلام أمر شراء —
 * كان يبقى خفيًّا حتى تُحدَّث الصفحة. فيَعِد الزبون بصنف نفد ثم يُرفض عند
 * الدفع. هذا الاستطلاع يُغلق تلك الفجوة.
 *
 * ثلاث قواعد في التصميم:
 * 1. يتوقف عند إخفاء التبويب — لا طلبات لشاشة لا يراها أحد.
 * 2. بيانات الخادم مع كل تنقّل هي المرجع، فتُمسح اللقطة القديمة.
 * 3. المنتج الذي لا يرد في التغذية يبقى بكميته الأخيرة لا بصفر — استجابة
 *    ناقصة يجب ألّا تُظهر المتجر فارغًا.
 */
export default function useLiveStock<T extends HasStock>(
    url: string,
    products: T[],
    intervalMs = 20000,
) {
    const [live, setLive] = useState<Record<number, StockRow> | null>(null);

    // كل تنقّل يجلب منتجات جديدة من الخادم — أحدث مما استطلعناه
    useEffect(() => setLive(null), [products]);

    useEffect(() => {
        let alive = true;

        const tick = async () => {
            if (document.hidden) return;
            try {
                const res = await fetch(url, { headers: { Accept: 'application/json' } });
                if (!res.ok || !alive) return;
                const data: { products?: StockRow[] } = await res.json();
                if (!alive || !Array.isArray(data.products)) return;
                setLive(Object.fromEntries(data.products.map((r) => [r.id, r])));
            } catch {
                // انقطاع عابر — المحاولة التالية بعد الفترة نفسها، واللقطة الأخيرة تبقى
            }
        };

        const id = setInterval(tick, intervalMs);
        return () => {
            alive = false;
            clearInterval(id);
        };
    }, [url, intervalMs]);

    return useMemo(
        () =>
            live === null
                ? products
                : products.map((p) => {
                      const row = live[p.id];
                      return row ? { ...p, qty: row.qty, stock_status: row.stock_status } : p;
                  }),
        [products, live],
    );
}
