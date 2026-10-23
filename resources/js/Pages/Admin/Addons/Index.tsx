import { useState } from 'react';
import { usePage } from '@inertiajs/react';
import { Globe, Plus, Puzzle, Store } from 'lucide-react';
import AdminLayout from '@/Layouts/AdminLayout';
import PageHeader from '@/Components/PageHeader';
import SectionTabs, { PRODUCT_TABS } from '@/Components/SectionTabs';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { csrfHeaders } from '@/lib/csrf';
import { currencyLabel, money } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';
import AddonDialog from '@/Pages/Admin/Products/partials/AddonDialog';
import { addonScope, type AddonOption, type BoutiqueLinks, type StockItem } from '@/Pages/Admin/Products/partials/addons';

interface Props {
    addons: AddonOption[];
    stockItems: StockItem[];
    products: { value: number; label: string }[];
    boutiques: { value: number; label: string; active?: boolean }[];
    boutiqueLinks: BoutiqueLinks;
}

type Filter = 'all' | 'general' | 'selected' | 'boutique' | 'inactive';

/**
 * الإضافات — قائمتُها كلُّها في مكانٍ واحد، لكلّ متجر.
 *
 * لكلّ إضافةٍ اسمُها ومصدرُها (المتجر أو بوتيك) وسعرُها للعميل ونطاقُها
 * وقناتُها وحالتُها، وبابا التعديل والإيقاف. والحفظُ بالنافذة نفسِها التي
 * في شاشة المنتج (`AddonDialog`) — لا نموذجَ ثانٍ يفترق عنها.
 *
 * والإيقافُ بابٌ مستقلّ (`admin.addons.active`): عمودٌ واحد، لا يُرسَل معه
 * مدى الإضافة فيُقرأ غيابُه «مع الجميع».
 */
export default function AddonsIndex() {
    const { addons: initial, stockItems, products, boutiques, boutiqueLinks, context } = usePage<PageProps<Props>>().props;
    const t = useTranslate();
    const currency = context!.currency;

    const [addons, setAddons] = useState<AddonOption[]>(initial);
    const [open, setOpen] = useState<AddonOption | null | undefined>(undefined);
    const [filter, setFilter] = useState<Filter>('all');
    const [busy, setBusy] = useState<number | null>(null);
    const [error, setError] = useState<string | null>(null);

    const upsert = (saved: AddonOption) =>
        setAddons((prev) => {
            const at = prev.findIndex((a) => a.value === saved.value);
            if (at === -1) return [...prev, saved];
            const next = [...prev];
            next[at] = { ...prev[at], ...saved };

            return next;
        });

    const toggle = async (a: AddonOption) => {
        setBusy(a.value);
        setError(null);
        try {
            const res = await fetch(route('admin.addons.active', a.value), {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...csrfHeaders() },
                body: JSON.stringify({ active: !a.active }),
            });
            const payload = await res.json();
            if (!res.ok) {
                setError(payload?.message ?? t('تعذّر حفظ الإضافة'));

                return;
            }
            upsert(payload.addon);
        } catch {
            setError(t('تعذّر الاتصال بالخادم'));
        } finally {
            setBusy(null);
        }
    };

    const matches = (a: AddonOption) => {
        switch (filter) {
            case 'general': return addonScope(a) === 'all';
            case 'selected': return addonScope(a) !== 'all';
            case 'boutique': return a.source === 'boutique';
            case 'inactive': return !a.active;
            default: return true;
        }
    };
    const shown = addons.filter(matches);

    const filters: { key: Filter; label: string; hidden?: boolean }[] = [
        { key: 'all', label: 'الكل' },
        { key: 'general', label: 'العامة' },
        { key: 'selected', label: 'المخصصة' },
        { key: 'boutique', label: 'من بوتيك', hidden: !boutiqueLinks.hosts },
        { key: 'inactive', label: 'موقوفة' },
    ];

    return (
        <AdminLayout title="الإضافات">
            <PageHeader
                title="الإضافات"
                subtitle={t('ما يُضاف إلى المنتج عند البيع — عامة مع كل المنتجات أو مخصصة لبعضها، في نقطة البيع أو الموقع أو كليهما.')}
                actions={
                    <Button onClick={() => setOpen(null)}>
                        <Plus />
                        {t('إضافة جديدة')}
                    </Button>
                }
            />

            <SectionTabs tabs={PRODUCT_TABS} current="admin.addons.index" />

            <div className="mb-4 flex flex-wrap gap-1.5" role="group" aria-label={t('عرض')}>
                {filters.filter((f) => !f.hidden).map((f) => (
                    <button
                        key={f.key}
                        type="button"
                        aria-pressed={filter === f.key}
                        onClick={() => setFilter(f.key)}
                        className={cn(
                            'rounded-full px-3 py-1.5 text-[12.5px] font-medium transition-colors',
                            filter === f.key ? 'bg-[#111] text-white' : 'bg-[#f2f2f0] text-[#4b4b4b] hover:bg-[#e9e9e6]',
                        )}
                    >
                        {t(f.label)}
                    </button>
                ))}
            </div>

            {error && <p className="mb-3 text-[13px] text-[#b91c1c]" role="alert">{error}</p>}

            {shown.length === 0 ? (
                <Card className="p-10 text-center">
                    <Puzzle className="mx-auto size-9 text-[#d4d4d8]" />
                    <p className="mt-3 text-[14px] font-medium text-[#4b4b4b]">{t(addons.length ? 'لا إضافات بهذا العرض' : 'لا إضافات بعد')}</p>
                    <p className="mt-1 text-[12.5px] text-[#9ca3af]">
                        {t('أنشئ إضافة مثل «تغليف هدية» أو «بطاقة إهداء»، وحدد مع أي المنتجات تظهر وأين تُباع.')}
                    </p>
                </Card>
            ) : (
                <div className="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3" data-testid="addons-list">
                    {shown.map((a) => (
                        <AddonCard
                            key={a.value}
                            addon={a}
                            money={(v) => money(v, currency)}
                            busy={busy === a.value}
                            onEdit={() => setOpen(a)}
                            onToggle={() => void toggle(a)}
                        />
                    ))}
                </div>
            )}

            {open !== undefined && (
                <AddonDialog
                    addon={open}
                    stockItems={stockItems}
                    products={products}
                    boutiques={boutiques}
                    boutiqueLinks={boutiqueLinks}
                    currencyLabel={currencyLabel(currency)}
                    onClose={() => setOpen(undefined)}
                    onSaved={upsert}
                />
            )}
        </AdminLayout>
    );
}

export function AddonCard({ addon: a, money: m, busy, onEdit, onToggle }: {
    addon: AddonOption;
    money: (v: number) => string;
    busy: boolean;
    onEdit: () => void;
    onToggle: () => void;
}) {
    const t = useTranslate();
    const scope = addonScope(a);
    const pos = a.active && a.pos_enabled !== false;
    const web = a.active && a.web_enabled === true;

    return (
        <Card className={cn('flex flex-col gap-3 p-4', !a.active && 'opacity-70')} data-testid="addon-card">
            <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                    <h3 className="truncate text-[15px] font-bold text-[#111]">{a.label}</h3>
                    {a.name_en && <p className="truncate text-[12px] text-[#71717a]" dir="ltr">{a.name_en}</p>}
                </div>
                <Badge variant={a.active ? 'success' : 'neutral'}>{t(a.active ? 'نشطة' : 'موقوفة')}</Badge>
            </div>

            <div className="flex flex-wrap items-baseline gap-x-4 gap-y-1 text-[12.5px] text-[#4b4b4b]">
                <span className="text-[15px] font-semibold tabular-nums text-[#111]">{m(a.price)}</span>
                <span data-testid="addon-card-scope">
                    {scope === 'all'
                        ? t('عامة — مع كل المنتجات')
                        : scope === 'selected'
                            ? t('مخصصة — :n منتج', { n: String(a.product_ids?.length ?? 0) })
                            : t('خاصة بـ:name', { name: a.owner_name ?? '' })}
                </span>
            </div>

            {/* مخصّصةٌ فُكّ آخرُ منتجاتها: محفوظةٌ ولا تُعرض مع شيء — يُقال لئلّا يُظنّ أنّها تُباع */}
            {scope === 'selected' && (a.product_ids?.length ?? 0) === 0 && (
                <p className="text-[12px] text-[#b45309]" data-testid="addon-card-orphan">{t('لا تظهر مع أي منتج — اربطها بمنتج أو اجعلها عامة.')}</p>
            )}

            <p className="text-[12.5px] text-[#4b4b4b]" data-testid="addon-card-source">
                {a.source === 'boutique'
                    ? t('من بوتيك :boutique: :product × :n', { boutique: a.boutique_name ?? '', product: a.stock_name ?? '', n: String(a.inventory_quantity ?? 1) })
                    : a.source === 'stock'
                        ? t('تخصم من المخزون: :product × :n', { product: a.stock_name ?? '', n: String(a.inventory_quantity ?? 1) })
                        : t('من المتجر — خدمة أو رسوم')}
                {a.source === 'boutique' && a.boutique_due != null && (
                    <span className="block text-[12px] text-[#9ca3af]">
                        {t('مستحق البوتيك عن كل إضافة: :due', { due: m(a.boutique_due) })}
                    </span>
                )}
            </p>

            {/* القناتان كما تُباعان فعلًا — والموقوفةُ لا تُباع في واحدةٍ منهما */}
            <div className="flex flex-wrap gap-1.5 text-[11.5px]" data-testid="addon-card-channels">
                <span className={cn('inline-flex items-center gap-1 rounded-full px-2 py-0.5', pos ? 'bg-[#f0fdf4] text-[#15803d]' : 'bg-[#f4f4f5] text-[#a1a1aa] line-through')}>
                    <Store className="size-3" /> {t('نقطة البيع')}
                </span>
                <span className={cn('inline-flex items-center gap-1 rounded-full px-2 py-0.5', web ? 'bg-[#f0fdf4] text-[#15803d]' : 'bg-[#f4f4f5] text-[#a1a1aa] line-through')}>
                    <Globe className="size-3" /> {t('الموقع الإلكتروني')}
                </span>
            </div>

            <div className="mt-auto flex gap-2">
                <Button size="sm" className="flex-1" onClick={onEdit}>{t('تعديل')}</Button>
                <Button size="sm" variant="outline" className="flex-1" disabled={busy} onClick={onToggle} data-testid="addon-card-toggle">
                    {t(a.active ? 'إيقاف' : 'تفعيل')}
                </Button>
            </div>
        </Card>
    );
}
