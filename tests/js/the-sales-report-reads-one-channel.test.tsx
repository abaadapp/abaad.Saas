import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { pageProps } from './setup';

vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

vi.mock('@/hooks/useLiveFeed', () => ({ default: () => ({ data: null, updatedAt: null }) }));

const { router } = await import('@inertiajs/react');
const { default: ReportsSales } = await import('@/Pages/Admin/Reports/Sales');

/*
 * و`router` مُزيَّفٌ في `setup.ts` بـ`vi.fn()` — فيُقرأ منه ما نُودي به،
 * ولا يُزيَّف هنا ثانيةً: زيفٌ ثانٍ للوحدة نفسِها يمحو `usePage` معه.
 */
const visits = () =>
    (router.get as unknown as { mock: { calls: [string, Record<string, unknown>][] } }).mock.calls.map(
        ([url, data]) => ({ url, data }),
    );

const SUMMARY = {
    sales: 50, cogs: 20, profit: 30, profit_kind: 'net' as const, expenses: 100, tax: 0,
    products: 3, inventory_alerts: 0, employees: 1, customers: 2, payment_methods: 1,
};

const draw = (over: Record<string, unknown> = {}) => {
    for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];

    Object.assign(pageProps, {
        translations: {},
        auth: { abilities: ['reports'], mayActions: [] },
        context: { currency: 'ر.ع' },
        summary: SUMMARY,
        salesSeries: { labels: ['1'], full: ['1'], data: [50], counts: [2], range: 'month' },
        range: 'month',
        paymentDistribution: { labels: ['نقدي'], series: [50] },
        topSellingProducts: [],
        channel: null,
        channels: [
            { value: 'website', label: 'الموقع الإلكتروني' },
            { value: 'pos', label: 'نقطة البيع' },
            { value: 'unknown', label: 'غير محدّدة' },
        ],
        ...over,
    });

    return render(<ReportsSales />);
};

/**
 * «ملخّص المبيعات» يُقرأ لقناةٍ واحدة.
 *
 * ═══ وأثقلُ ما يُحرَس ═══
 *
 * أنّ البطاقة تقول ما فيها: «صافي الربح» للمتجر كلِّه و«مُجمل الربح» لقناةٍ
 * بعينها. والمصروفاتُ تسقط من بطاقات القناة ولا تُعرض صفرًا — صفرٌ يُقرأ
 * «لا مصروف على هذا الباب»، والحقُّ أنّها لا تُنسب إلى بابٍ أصلًا.
 */
describe('مُرشِّحُ القناة في «ملخّص المبيعات»', () => {
    beforeEach(() => {
        vi.mocked(router.get).mockClear();
        for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    });

    it('ترسم القنوات من مصدرها لا من عندها', () => {
        draw();

        const bar = screen.getByTestId('channel-filter');

        expect(bar).toHaveTextContent('كل القنوات');
        expect(screen.getByTestId('channel-website')).toHaveTextContent('الموقع الإلكتروني');
        expect(screen.getByTestId('channel-pos')).toHaveTextContent('نقطة البيع');
        expect(screen.getByTestId('channel-unknown')).toHaveTextContent('غير محدّدة');
    });

    /** والانتقالُ يحمل الفترةَ معه — وإلّا عاد التقرير إلى الشهر عند كلّ تبديل */
    it('والاختيار يحمل الفترة معه', () => {
        draw({ range: 'year' });

        fireEvent.click(screen.getByTestId('channel-website'));

        expect(visits()).toHaveLength(1);
        expect(visits()[0].data).toEqual({ range: 'year', channel: 'website' });
    });

    /** و«كل القنوات» تمحو المُرشِّح من الرابط لا تكتب فيه قيمةً فارغة */
    it('و«كل القنوات» لا تكتب مُرشِّحًا', () => {
        draw({ channel: 'website' });

        fireEvent.click(screen.getByText('كل القنوات'));

        expect(visits()[0].data).toEqual({ range: 'month' });
    });

    it('ولا تُعيد طلبَ القناة المختارة', () => {
        draw({ channel: 'website' });

        fireEvent.click(screen.getByTestId('channel-website'));

        expect(visits()).toHaveLength(0);
    });

    /* ═══════════ وما تقوله البطاقات ═══════════ */

    it('تقول «صافي الربح» للمتجر كلِّه وتعرض مصروفاته', () => {
        draw();

        expect(screen.getByText('صافي الربح')).toBeInTheDocument();
        expect(screen.queryByText('مُجمل الربح')).toBeNull();
        expect(screen.getByText('المصروفات')).toBeInTheDocument();
        expect(screen.queryByTestId('channel-note')).toBeNull();
    });

    /**
     * ولقناةٍ بعينها: «مُجمل الربح»، ولا بطاقةَ مصروفات، وسطرٌ يقول لماذا.
     *
     * فمن قرأ «ربح» بلا هذا قاس قناةً بمقياس متجرٍ كامل — وعلى الرقم يُتّخذ
     * قرارُ إغلاق.
     */
    it('وتقول «مُجمل الربح» لقناةٍ وتُسقط المصروفات وتقول لماذا', () => {
        draw({
            channel: 'website',
            summary: { ...SUMMARY, profit_kind: 'gross', expenses: 0, profit: 30 },
        });

        expect(screen.getByText('مُجمل الربح')).toBeInTheDocument();
        expect(screen.queryByText('صافي الربح')).toBeNull();
        expect(screen.queryByText('المصروفات')).toBeNull();
        expect(screen.getByTestId('channel-note')).toHaveTextContent('تُنفَق على المتجر كلّه');
    });
});
