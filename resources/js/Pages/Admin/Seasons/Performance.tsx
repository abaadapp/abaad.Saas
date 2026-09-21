import { router } from '@inertiajs/react';
import { BarChart3, Info } from 'lucide-react';
import StatCard from '@/Components/StatCard';
import { Card } from '@/Components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { money, number } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { Currency } from '@/types';

export interface SeasonPerformance {
    summary: { sales: number; cogs: number; gross_profit: number; margin: number; orders: number; units: number };
    channels: { key: string; label: string; sales: number; orders: number; gross_profit: number }[];
    top: { product_id: number | null; name: string; units: number; sales: number; gross_profit: number }[];
    /** بيعاتٌ من أصناف الموسم في مدّته لم تُنسب إليه — عدًّا لا مالًا */
    unattributed: { orders: number; units: number };
    /** القناةُ المرشَّحة — أو `null` لكلّها */
    channel: string | null;
}

/**
 * أداءُ الموسم — ما نُسب إليه من بيعات، بأرقام الدفتر لا بأرقامٍ ثانية.
 *
 * ═══ ما يُرسم وما لا يُرسم ═══
 *
 * مجملُ الربح لا صافيه: الصافي يحتاج مصروفاتٍ منسوبةً للموسم ولا نسبةَ لها
 * بعد، فلا يُكتب رقمٌ باسمه. وقنواتُ البيع ما وُجد منها: «الموقع: ٠» يوهم
 * أنّ الموقع يبيع ويُحصى وهو اليومَ لا يُنشئ طلبًا.
 *
 * والمبالغُ بأسعار البنود كما بيعت قبل خصومات الفاتورة — قاعدةُ «ربحيّة
 * المنتجات» نفسُها — ويُقال ذلك تحت البطاقات لا يُترك للظنّ.
 */
export default function Performance({ seasonId, data, currency }: { seasonId: number; data: SeasonPerformance; currency: Currency }) {
    const t = useTranslate();
    const m = (v: number) => money(v, currency);
    const s = data.summary;

    const pickChannel = (key: string | null) =>
        router.get(route('admin.seasons.show', seasonId), key ? { channel: key } : {}, { preserveScroll: true, preserveState: true });

    return (
        <section aria-labelledby="season-performance" data-testid="season-performance" className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 id="season-performance" className="flex items-center gap-1.5 text-[14px] font-bold text-[#111]">
                    <BarChart3 className="size-4" /> {t('أداء الموسم')}
                </h2>

                {/* والقناةُ تُرشَّح من الشريط — لا نموذجَ تقاريرَ كامل */}
                {data.channels.length > 0 && (
                    <div role="group" aria-label={t('قناة البيع')} className="flex flex-wrap gap-1.5">
                        {[{ key: null as string | null, label: t('كل القنوات') }, ...data.channels].map((c) => (
                            <button
                                key={c.key ?? 'all'}
                                type="button"
                                aria-pressed={data.channel === c.key}
                                onClick={() => pickChannel(c.key)}
                                className={cn(
                                    'rounded-full px-3 py-1 text-[12px] font-medium transition-colors',
                                    data.channel === c.key ? 'bg-[#111] text-white' : 'border border-[var(--ui-border,#e8e8e8)] text-[#4b4b4b] hover:bg-[#fafafa]',
                                )}
                            >
                                {c.label}
                            </button>
                        ))}
                    </div>
                )}
            </div>

            <div className="grid grid-cols-2 gap-3 lg:grid-cols-3">
                <StatCard stat={{ label: 'إجمالي المبيعات', value: m(s.sales), icon: 'banknote', color: 'primary' }} index={0} />
                <StatCard stat={{ label: 'تكلفة البضاعة المباعة', value: m(s.cogs), icon: 'boxes', color: 'warning' }} index={1} />
                <StatCard stat={{ label: 'مجمل الربح', value: m(s.gross_profit), icon: 'trending-up', color: s.gross_profit >= 0 ? 'success' : 'danger' }} index={2} />
                <StatCard stat={{ label: 'هامش مجمل الربح', value: `${number(s.margin, 1)}%`, icon: 'percent', color: 'info' }} index={3} />
                <StatCard stat={{ label: 'عدد الطلبات', value: number(s.orders), icon: 'receipt', color: 'secondary' }} index={4} />
                <StatCard stat={{ label: 'الكمية المباعة', value: number(s.units), icon: 'package', color: 'secondary' }} index={5} />
            </div>

            <p className="flex items-start gap-1.5 text-[12px] text-[#71717a]">
                <Info className="mt-0.5 size-3.5 shrink-0" />
                <span>
                    {t('تُنسب البيعة للموسم حين يختاره الكاشير في الصندوق. المبالغ بأسعار البنود كما بيعت قبل خصومات الفاتورة، والتكلفة بلقطتها يوم البيع — كما في ربحية المنتجات.')}
                    {' '}
                    {t('صافي ربح الموسم يحتاج ربط المصروفات بالموسم.')}
                </span>
            </p>

            {data.unattributed.orders > 0 && (
                <p data-testid="season-unattributed" className="rounded-[10px] bg-[#fffbeb] px-3 py-2 text-[12px] text-[#92400e]">
                    {t('بيعات لمنتجات الموسم في مدّته لم تُنسب إليه: :orders طلب (:units قطعة) — بيعت من شاشة «الكل» ولا تُحتسب هنا.', {
                        orders: data.unattributed.orders, units: data.unattributed.units,
                    })}
                </p>
            )}

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                {/* ═══ حسب القناة — ما وُجد منها ═══ */}
                <Card className="p-4">
                    <h3 className="mb-2 text-[13px] font-bold text-[#111]">{t('المبيعات حسب القناة')}</h3>
                    {data.channels.length === 0 ? (
                        <p className="py-6 text-center text-[13px] text-[#9ca3af]">{t('لا مبيعات منسوبة لهذا الموسم بعد.')}</p>
                    ) : (
                        <Table data-testid="season-channels">
                            <TableHeader>
                                <TableRow>
                                    <TableHead>{t('قناة البيع')}</TableHead>
                                    <TableHead className="text-end">{t('إجمالي المبيعات')}</TableHead>
                                    <TableHead className="text-end">{t('عدد الطلبات')}</TableHead>
                                    <TableHead className="text-end">{t('مجمل الربح')}</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {data.channels.map((c) => (
                                    <TableRow key={c.key}>
                                        <TableCell className="font-medium">{c.label}</TableCell>
                                        <TableCell className="text-end tabular-nums">{m(c.sales)}</TableCell>
                                        <TableCell className="text-end tabular-nums">{number(c.orders)}</TableCell>
                                        <TableCell className={cn('text-end tabular-nums', c.gross_profit < 0 && 'text-[#b91c1c]')}>{m(c.gross_profit)}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </Card>

                {/* ═══ أفضل منتجات الموسم — بالاسم كما بيع ═══ */}
                <Card className="p-4">
                    <h3 className="mb-2 text-[13px] font-bold text-[#111]">{t('أفضل منتجات الموسم')}</h3>
                    {data.top.length === 0 ? (
                        <p className="py-6 text-center text-[13px] text-[#9ca3af]">{t('لا مبيعات منسوبة لهذا الموسم بعد.')}</p>
                    ) : (
                        <Table data-testid="season-top">
                            <TableHeader>
                                <TableRow>
                                    <TableHead>{t('المنتج')}</TableHead>
                                    <TableHead className="text-end">{t('الكمية المباعة')}</TableHead>
                                    <TableHead className="text-end">{t('إجمالي المبيعات')}</TableHead>
                                    <TableHead className="text-end">{t('مجمل الربح')}</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {data.top.map((p, i) => (
                                    <TableRow key={`${p.product_id ?? 'x'}-${p.name}-${i}`}>
                                        <TableCell className="font-medium" dir="auto">{p.name}</TableCell>
                                        <TableCell className="text-end tabular-nums">{number(p.units)}</TableCell>
                                        <TableCell className="text-end tabular-nums">{m(p.sales)}</TableCell>
                                        <TableCell className={cn('text-end tabular-nums', p.gross_profit < 0 && 'text-[#b91c1c]')}>{m(p.gross_profit)}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </Card>
            </div>
        </section>
    );
}
