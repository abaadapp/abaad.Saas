import { useEffect, useState } from 'react';
import { Search, Trash2 } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';

export interface PickedItem {
    product_id: number;
    name: string;
    sku: string | null;
    quantity: number;
    /** الرصيدُ المتاح في الفرع المصدر — `null` حين لا مصدرَ مختار */
    available: number | null;
}

interface Found {
    id: number;
    name: string;
    sku: string | null;
    available: number | null;
    total: number;
}

interface Props {
    items: PickedItem[];
    onChange: (items: PickedItem[]) => void;
    /** الفرعُ الذي يُقرأ رصيدُه — المصدرُ في النقل، والطالبُ في التزويد */
    branchId: number | null;
    availableLabel?: string;
}

/**
 * أسطرُ السند — بحثٌ عن صنفٍ وإضافتُه بكميّته، ورصيدُه في الفرع ظاهرٌ بجانبه.
 *
 * والبحثُ من الخادم (`inventory.transfers.products`): لا تُرسَل قائمةُ الأصناف
 * كلُّها مع الصفحة. والخادمُ يعيد الفحصَ كلَّه عند الحفظ — هذا عرضٌ لا حارس.
 */
export default function BranchItemsPicker({ items, onChange, branchId, availableLabel = 'المتاح في المصدر' }: Props) {
    const t = useTranslate();
    const [q, setQ] = useState('');
    const [found, setFound] = useState<Found[]>([]);

    useEffect(() => {
        const timer = window.setTimeout(() => {
            const params: Record<string, string> = { q };
            if (branchId) params.branch = String(branchId);
            fetch(route('admin.inventory.transfers.products', params), { headers: { Accept: 'application/json' } })
                .then((r) => (r.ok ? r.json() : { products: [] }))
                .then((d) => setFound(d.products ?? []))
                .catch(() => setFound([]));
        }, 250);
        return () => window.clearTimeout(timer);
    }, [q, branchId]);

    // المصدرُ تبدّل: رصيدُ كلّ سطرٍ من الفرع الجديد
    useEffect(() => {
        if (!items.length) return;
        onChange(items.map((i) => ({ ...i, available: found.find((f) => f.id === i.product_id)?.available ?? i.available })));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [found]);

    const add = (p: Found) => {
        if (items.some((i) => i.product_id === p.id)) return;
        onChange([...items, { product_id: p.id, name: p.name, sku: p.sku, quantity: 1, available: p.available }]);
    };

    return (
        <div className="space-y-3">
            <div className="relative">
                <Search className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-[#9ca3af]" />
                <Input
                    value={q}
                    onChange={(e) => setQ(e.target.value)}
                    placeholder={t('ابحث عن منتج بالاسم أو SKU…')}
                    className="ps-9"
                    data-testid="transfer-product-search"
                />
            </div>

            {found.length > 0 && (
                <ul className="max-h-48 overflow-y-auto rounded-lg border border-[#ececec]" data-testid="transfer-product-results">
                    {found.map((p) => (
                        <li key={p.id}>
                            <button
                                type="button"
                                onClick={() => add(p)}
                                disabled={items.some((i) => i.product_id === p.id)}
                                className="flex w-full items-center justify-between gap-3 px-3 py-2 text-start text-[13px] hover:bg-[#fafafa] disabled:opacity-50"
                            >
                                <span className="min-w-0">
                                    <span className="block truncate font-medium">{p.name}</span>
                                    {p.sku && <span className="block text-[11px] text-[#9ca3af]">{p.sku}</span>}
                                </span>
                                <span className="shrink-0 text-[12px] tabular-nums text-[#6b7280]">
                                    {p.available !== null ? `${t(availableLabel)}: ${number(p.available)}` : `${t('الإجمالي')}: ${number(p.total)}`}
                                </span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}

            {items.length === 0 ? (
                <p className="rounded-lg bg-[#fafafa] px-3 py-4 text-center text-[13px] text-[#9ca3af]">{t('لم تُضف منتجات بعد')}</p>
            ) : (
                <ul className="divide-y divide-[#f0f0f0] rounded-lg border border-[#ececec]" data-testid="transfer-lines">
                    {items.map((item, idx) => {
                        const over = item.available !== null && item.quantity > item.available;
                        return (
                            <li key={item.product_id} className="flex flex-wrap items-center gap-3 px-3 py-2.5">
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate text-[13px] font-medium">{item.name}</span>
                                    {item.available !== null && (
                                        <span className={over ? 'text-[11px] text-[#b91c1c]' : 'text-[11px] text-[#6b7280]'}>
                                            {t(availableLabel)}: {number(item.available)}
                                        </span>
                                    )}
                                </span>
                                <Input
                                    type="number"
                                    min={1}
                                    inputMode="numeric"
                                    value={item.quantity}
                                    aria-label={t('الكمية')}
                                    onChange={(e) => {
                                        const next = [...items];
                                        next[idx] = { ...item, quantity: Math.max(1, parseInt(e.target.value || '1', 10) || 1) };
                                        onChange(next);
                                    }}
                                    className="w-24 tabular-nums"
                                />
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon-sm"
                                    aria-label={t('حذف')}
                                    onClick={() => onChange(items.filter((_, i) => i !== idx))}
                                >
                                    <Trash2 />
                                </Button>
                            </li>
                        );
                    })}
                </ul>
            )}
        </div>
    );
}
