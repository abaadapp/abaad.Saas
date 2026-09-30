import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { pageProps } from './setup';

vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));
vi.mock('@/Layouts/PlatformLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));
// البطاقاتُ تُقرأ هنا بقيمها — ترتيبُها وحفظُه ليسا ممّا يُختبر
vi.mock('@/Components/StatGrid', () => ({
    default: ({ stats }: { stats: { label: string; value: string }[] }) => (
        <ul data-testid="stats">
            {stats.map((s) => (
                <li key={s.label}>{`${s.label}: ${s.value}`}</li>
            ))}
        </ul>
    ),
}));

const { default: BarChart } = await import('@/Components/charts/BarChart');
const { default: AreaChart } = await import('@/Components/charts/AreaChart');
const { default: AdminDashboard } = await import('@/Pages/Admin/Dashboard');
const { default: PlatformDashboard } = await import('@/Pages/Platform/Dashboard');

/**
 * الرسمُ يتحرّك مع البطاقات — ولا يقول عن الغد صفرًا.
 *
 * ═══ العطب الذي وُضع له ═══
 *
 * نبضةُ اللوحتين كانت تُبدِّل البطاقات وحدها (`useLiveStats`)، والرسمُ تحتها
 * يبقى على لحظة فتح الصفحة: بيعةٌ أو فاتورةٌ تُسدَّد ترفع الرقمَ فوق ولا تمسّ
 * العمودَ تحت. فصارت النبضةُ لقطةً كاملة تُبدَّل كلُّها (`useLiveFeed`).
 */

const MONTHS = ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];

/** سنةٌ حتى سبتمبر: ما مضى أرقام، وما لم يأتِ `null` */
const year = (september: number) => [...Array(8).fill(0), september, null, null, null] as (number | null)[];

const pulse = (body: unknown) =>
    vi.fn(async () => ({ ok: true, status: 200, json: async () => body }));

/** نبضةٌ واحدة: تمضي المدّةُ ويُنتظر ردُّ الخادم */
const beat = async (ms: number) => {
    await act(async () => {
        await vi.advanceTimersByTimeAsync(ms);
    });
};

/** يلمس عمودَ الشهر في المنحنى ويردّ سطرَ التلميح تحته — نصّه موزَّعٌ على عُقَد */
const touch = (container: HTMLElement, month: number) => {
    const hit = container.querySelectorAll('rect.cursor-pointer')[month];
    fireEvent.click(hit);

    return hit.closest('svg')!.parentElement!.querySelector(':scope > p')!;
};

const reset = (props: Record<string, unknown>) => {
    for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    Object.assign(pageProps, { translations: {} }, props);
};

describe('الرسومُ لا تقول عن الغد صفرًا', () => {
    it('وشريطُ شهرٍ لم يأتِ يقول «لم يأتِ بعد» — لا «٠» ولا نسبة', () => {
        render(<BarChart labels={['سبتمبر', 'أكتوبر']} series={[3, null]} />);

        const october = screen.getByText('أكتوبر').closest('[data-future]');
        expect(october).not.toBeNull();
        expect(october).toHaveTextContent('لم يأتِ بعد');
        expect(october).not.toHaveTextContent('0');

        // والنسبةُ تُقسم على ما قيس وحده: سبتمبر كلُّ المعلوم
        expect(screen.getByText('سبتمبر').closest('.group')).toHaveTextContent('(100%)');
    });

    it('ولمسُ شهرٍ لم يأتِ في المنحنى يقول «لم يأتِ بعد» لا قيمة', () => {
        const { container } = render(<AreaChart labels={MONTHS} data={year(5)} format={(v) => `${v} ر.ع`} />);

        expect(touch(container, 9)).toHaveTextContent('لم يأتِ بعد');
        expect(touch(container, 9)).not.toHaveTextContent('0 ر.ع');
        expect(touch(container, 8)).toHaveTextContent('5 ر.ع');
    });
});

describe('لوحةُ التاجر تُحدِّث رسميها مع بطاقاتها', () => {
    beforeEach(() => vi.useFakeTimers());
    afterEach(() => {
        vi.useRealTimers();
        vi.unstubAllGlobals();
    });

    const initial = {
        stats: [{ label: 'مبيعات اليوم', value: '10', icon: 'wallet', color: 'primary' }],
        statCatalog: [],
        salesSeries: { labels: MONTHS, full: MONTHS, data: year(10), counts: year(1) },
        paymentDistribution: { labels: ['نقدي'], series: [10] },
        recentOrders: [],
        topProducts: [],
        topEmployees: [],
    };

    it('بيعةٌ والصفحةُ مفتوحة تصل الرسمين — بلا تنقّلٍ ولا إعادة تحميل', async () => {
        reset({ ...initial, context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 }, businessName: 'متجري' } });

        const fetch = pulse({
            stats: [{ label: 'مبيعات اليوم', value: '35', icon: 'wallet', color: 'primary' }],
            salesSeries: { labels: MONTHS, full: MONTHS, data: year(35), counts: year(2) },
            paymentDistribution: { labels: ['بطاقة (فيزا)', 'نقدي'], series: [25, 10] },
            updated_at: '10:00:15',
        });
        vi.stubGlobal('fetch', fetch);

        const { container } = render(<AdminDashboard />);

        expect(screen.queryByText('بطاقة (فيزا)')).toBeNull();

        await beat(15000);

        // من بابٍ واحد: البطاقاتُ والرسمان في طلبٍ واحد
        expect(fetch).toHaveBeenCalledTimes(1);
        expect((fetch.mock.calls[0] as unknown as [string])[0]).toBe('/admin.dashboard.stats');

        expect(screen.getByTestId('stats')).toHaveTextContent('مبيعات اليوم: 35');
        expect(screen.getByText('بطاقة (فيزا)')).toBeInTheDocument();

        // عمودُ سبتمبر نفسُه تحرّك — لا البطاقةُ وحدها
        expect(touch(container, 8)).toHaveTextContent('35');
    });

    it('ولا يسأل واللسانُ مخفيّ', async () => {
        reset({ ...initial, context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 } } });
        const fetch = pulse({});
        vi.stubGlobal('fetch', fetch);
        Object.defineProperty(document, 'hidden', { configurable: true, get: () => true });

        render(<AdminDashboard />);
        await beat(45000);

        expect(fetch).not.toHaveBeenCalled();
        Object.defineProperty(document, 'hidden', { configurable: true, get: () => false });
    });
});

describe('لوحةُ المنصّة تُحدِّث «الإيرادات الشهرية» و«نمو الشركات»', () => {
    beforeEach(() => vi.useFakeTimers());
    afterEach(() => {
        vi.useRealTimers();
        vi.unstubAllGlobals();
    });

    it('فاتورةٌ تُسدَّد وشركةٌ تُسجَّل والصفحةُ مفتوحة تظهران في الرسمين', async () => {
        reset({
            stats: [{ label: 'إجمالي الشركات', value: '4', icon: 'building-2', color: 'primary' }],
            revenueSeries: { labels: MONTHS, data: year(100) },
            growthSeries: { labels: MONTHS, data: year(1) },
            latestBusinesses: [],
            activities: [],
            expiringSubscriptions: [],
            currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 },
        });

        const fetch = pulse({
            stats: [{ label: 'إجمالي الشركات', value: '5', icon: 'building-2', color: 'primary' }],
            revenueSeries: { labels: MONTHS, data: year(142) },
            growthSeries: { labels: MONTHS, data: year(2) },
            updated_at: '10:00:15',
        });
        vi.stubGlobal('fetch', fetch);

        const { container } = render(<PlatformDashboard />);
        const september = () => screen.getAllByText('سبتمبر').map((n) => n.closest('.group')).find(Boolean)!;

        expect(september()).toHaveTextContent('سبتمبر1(100%)');

        await beat(15000);

        expect((fetch.mock.calls[0] as unknown as [string])[0]).toBe('/super-admin.dashboard.stats');
        expect(screen.getByTestId('stats')).toHaveTextContent('إجمالي الشركات: 5');

        // «نمو الشركات»: سبتمبر صار ٢ — وأكتوبر ما زال لم يأتِ
        expect(september()).toHaveTextContent('سبتمبر2(100%)');
        expect(screen.getAllByText('أكتوبر').map((n) => n.closest('[data-future]')).find(Boolean)).toHaveTextContent('لم يأتِ بعد');

        // «الإيرادات الشهرية»: عمودُ سبتمبر نفسُه تحرّك
        expect(touch(container, 8)).toHaveTextContent('142');
    });
});
