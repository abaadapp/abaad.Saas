import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { useTranslate } from '@/lib/i18n';
import { CostLines, type Classified, type CostEstimate } from '@/Pages/Platform/Businesses/partials/WhatsAppBillingReview';

/** كلفةُ رقم أبعاد المشترك كما تُعرف — انظر `App\Support\WhatsAppPrices::sharedView`. ولا مبلغَ من عندنا */
export interface SharedCost {
    linked: boolean;
    days: number;
    estimate: CostEstimate | null;
    classified: Classified;
    by_category: { label: string; n: number }[];
    by_shop: { business: string; n: number }[];
}

/**
 * ما قد يُحتسب على أبعاد من رقمها المشترك — تصنيفُ Meta لكلّ رسالة، وتقديرُها بزرّ.
 *
 * عرضٌ لا غير: لا يبدّل سعرًا ولا حصّةً ولا رصيدًا.
 */
export default function WhatsAppSharedCost({ cost }: { cost: SharedCost }) {
    const t = useTranslate();
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    if (!cost.linked) return null;

    const read = () =>
        router.post(route('super-admin.whatsapp.shared-cost'), {}, {
            preserveScroll: true,
            onStart: () => setBusy(true),
            onFinish: () => setBusy(false),
            onSuccess: () => setError(null),
            onError: (e) => setError(Object.values(e as Record<string, string>)[0] ?? null),
        });

    return (
        <Card className="mb-6 p-6" data-testid="wa-shared-cost">
            <div className="mb-1 flex flex-wrap items-center justify-between gap-3">
                <h3 className="text-[18px] font-bold text-[#111]">{t('كلفة رقم أبعاد المشترك')}</h3>
                <Button type="button" variant="outline" size="sm" loading={busy} onClick={read} data-testid="wa-shared-read">{t('اقرأ تقدير الكلفة')}</Button>
            </div>
            <p className="mb-3 text-[13px] leading-relaxed text-[#6b7280]">
                {t('رسائل هذا الرقم تُحتسب على حساب أبعاد في Meta. منذ أكتوبر 2026 صارت الإشعارات داخل نافذة 24 ساعة محتسبة، وكذلك الردود بعد الألف المجانية الشهرية للرقم.')}
            </p>
            <CostLines estimate={cost.estimate} classified={cost.classified} />
            {cost.by_category.length > 0 && (
                <p className="mt-2 text-[12px] text-[#374151]" data-testid="wa-shared-categories">
                    {t('المحتسبة بالفئة')}: {cost.by_category.map((c) => `${c.label} ${c.n}`).join(' · ')}
                </p>
            )}
            {cost.by_shop.length > 0 && (
                <ul className="mt-2 space-y-0.5 text-[12px] text-[#374151]" data-testid="wa-shared-shops">
                    {cost.by_shop.map((s, i) => <li key={i}>{s.business}: <bdi>{s.n}</bdi></li>)}
                </ul>
            )}
            {error && <p className="mt-2 text-[12px] text-[#b91c1c]" data-testid="wa-shared-error">{error}</p>}
        </Card>
    );
}
