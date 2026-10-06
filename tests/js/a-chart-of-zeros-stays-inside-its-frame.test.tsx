import { cleanup, render } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import AreaChart from '@/Components/charts/AreaChart';
import MultiLineChart, { lineDomain, yPosition } from '@/Components/charts/MultiLineChart';
import { pageProps } from './setup';

vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

const { default: ReportsProfit } = await import('@/Pages/Admin/Reports/Profit');

/**
 * رسمٌ كلُّه أصفار يبقى في إطاره — ولا يكتب على محوره رقمًا لم يبلغه شيء.
 *
 * ═══ ما وقع على الإنتاج ═══
 *
 * صافي الربح بسلاسله الثلاث أصفارًا: كان المدى `top = bottom = 0` و`span = 1`،
 * فخرجت العلاماتُ ٠٫٢٥ … ١ وموضعُها يُقاس من صفر — إحداثيّاتٌ سالبة فوق
 * الرسم، و`overflow-visible` أخرجها فوق بطاقات الصفحة على Safari.
 *
 * والحسابُ دالّةٌ نقيّة (`lineDomain` و`yPosition`) لأنّ jsdom لا يرسم:
 * الموضعُ يُقاس رقمًا، ويُقرأ معه ما كُتب في DOM.
 */

const H = 280;
const PAD_TOP = 14;
const INNER_H = H - PAD_TOP - 26;

const inFrame = (y: number) => Number.isFinite(y) && y >= PAD_TOP - 1e-9 && y <= PAD_TOP + INNER_H + 1e-9;

afterEach(() => {
    cleanup();
    document.documentElement.dir = 'rtl';
});

describe('مدى المحور — دالّةٌ نقيّة', () => {
    it('كلُّها أصفار: علامةٌ واحدة هي الصفر، على خطّ القاع', () => {
        const d = lineDomain([0, 0, 0]);

        expect(d.ticks).toEqual([0]);
        expect(yPosition(d, 0, INNER_H, PAD_TOP)).toBe(PAD_TOP + INNER_H);
    });

    it('لا قيمَ أصلًا: لا NaN ولا Infinity — الصفرُ وحده', () => {
        const d = lineDomain([]);

        expect(d).toEqual({ top: 0, bottom: 0, ticks: [0] });
        expect(inFrame(yPosition(d, 0, INNER_H, PAD_TOP))).toBe(true);
    });

    it('وما ليس رقمًا لا يُفسد المدى ولا الموضع', () => {
        const d = lineDomain([NaN, Infinity, -Infinity, 0]);

        expect(d.ticks).toEqual([0]);
        expect(inFrame(yPosition(d, NaN, INNER_H, PAD_TOP))).toBe(true);
        expect(inFrame(yPosition(lineDomain([5, NaN]), Infinity, INNER_H, PAD_TOP))).toBe(true);
    });

    const cases: [string, number[]][] = [
        ['موجبٌ كلُّه', [120, 340, 90]],
        ['سالبٌ كلُّه', [-40, -15, -220]],
        ['موجبٌ وسالب', [-300, 0, 1250, 75]],
        ['نقطةٌ واحدة', [42]],
        ['صفرٌ واحد', [0]],
        ['أرقامٌ كبيرة', [1_250_000, 9_999_999]],
        ['كسور', [0.125, 0.004, 0.75]],
    ];

    it.each(cases)('%s: كلُّ علامةٍ داخل المدى، وكلُّ موضعٍ داخل الإطار', (_, values) => {
        const d = lineDomain(values);

        for (const tick of d.ticks) {
            expect(tick).toBeGreaterThanOrEqual(d.bottom);
            expect(tick).toBeLessThanOrEqual(d.top);
            expect(inFrame(yPosition(d, tick, INNER_H, PAD_TOP))).toBe(true);
        }

        for (const v of values) {
            expect(inFrame(yPosition(d, v, INNER_H, PAD_TOP))).toBe(true);
        }

        expect(inFrame(yPosition(d, 0, INNER_H, PAD_TOP))).toBe(true);
    });

    it('السالبُ تحت الصفر، والصفرُ خطُّ القاعدة في المختلط', () => {
        const negative = lineDomain([-40, -15]);
        expect(yPosition(negative, -40, INNER_H, PAD_TOP)).toBeGreaterThan(yPosition(negative, 0, INNER_H, PAD_TOP));
        expect(negative.top).toBe(0);

        const mixed = lineDomain([-300, 1250]);
        const zero = yPosition(mixed, 0, INNER_H, PAD_TOP);
        expect(yPosition(mixed, 1250, INNER_H, PAD_TOP)).toBeLessThan(zero);
        expect(yPosition(mixed, -300, INNER_H, PAD_TOP)).toBeGreaterThan(zero);
        expect(zero).toBeGreaterThan(PAD_TOP);
        expect(zero).toBeLessThan(PAD_TOP + INNER_H);
    });

    it('والموجبُ وحده كما كان: الصفرُ قاعٌ والسقفُ مستدير', () => {
        const d = lineDomain([120, 340, 90]);

        expect(d.bottom).toBe(0);
        expect(d.top).toBe(500);
        expect(d.ticks).toEqual([0, 125, 250, 375, 500]);
    });
});

const axis = (container: HTMLElement) =>
    Array.from(container.querySelectorAll('foreignObject[data-axis="y"]')).map((fo) => ({
        y: Number(fo.getAttribute('y')),
        text: fo.textContent ?? '',
    }));

const fmt = (v: number) => `${v.toFixed(3)} ر.ع`;

describe('الرسمُ المرسوم', () => {
    it.each(['rtl', 'ltr'] as const)('%s: سلاسلُ أصفار — علامةُ صفرٍ واحدة داخل الإطار، ولا ٠٫٢٥ … ١', (dir) => {
        document.documentElement.dir = dir;
        const { container } = render(
            <MultiLineChart
                labels={['1', '2', '3']}
                format={fmt}
                series={[
                    { key: 'a', label: 'صافي الإيرادات', data: [0, 0, 0] },
                    { key: 'b', label: 'مجمل الربح', data: [0, 0, 0] },
                    { key: 'c', label: 'صافي الربح', data: [0, 0, 0] },
                ]}
            />,
        );

        const ticks = axis(container);
        expect(ticks).toHaveLength(1);
        expect(ticks[0].text).toBe('0.000 ر.ع');
        expect(ticks[0].y + 7).toBeLessThanOrEqual(PAD_TOP + INNER_H);
        expect(ticks[0].y).toBeGreaterThanOrEqual(0);

        const text = container.textContent ?? '';
        for (const fake of ['0.250', '0.500', '0.750', '1.000']) {
            expect(text).not.toContain(fake);
        }

        // ولا إحداثيّ يخرج: كلُّ y في الخطوط والمسارات رقمٌ داخل viewBox
        const ys = Array.from(container.querySelectorAll('line')).flatMap((l) => [Number(l.getAttribute('y1')), Number(l.getAttribute('y2'))]);
        const pathYs = Array.from(container.querySelectorAll('path')).flatMap((p) =>
            (p.getAttribute('d') ?? '').split(/[ML]/).filter(Boolean).map((pair) => Number(pair.split(',')[1])),
        );
        for (const y of [...ys, ...pathYs]) {
            expect(Number.isFinite(y)).toBe(true);
            expect(y).toBeGreaterThanOrEqual(0);
            expect(y).toBeLessThanOrEqual(H);
        }

        expect(container.querySelector('svg')!.getAttribute('class')).not.toContain('overflow-visible');
        expect(container.innerHTML).not.toMatch(/NaN|Infinity/);
    });

    it('ولا تسميات: لا شيء مكسور', () => {
        const { container } = render(<MultiLineChart labels={[]} series={[{ key: 'a', label: 'أ', data: [] }]} />);

        expect(container.innerHTML).not.toMatch(/NaN|Infinity/);
        expect(axis(container)).toHaveLength(1);
    });

    it('ورسمٌ حقيقيّ بخسارة: خمسُ علامات، كلُّها في الإطار', () => {
        document.documentElement.dir = 'ltr';
        const { container } = render(
            <MultiLineChart labels={['1', '2', '3']} format={fmt} series={[{ key: 'n', label: 'Net profit', data: [-120, 40, 300] }]} />,
        );

        const ticks = axis(container);
        expect(ticks).toHaveLength(5);
        for (const t of ticks) {
            expect(t.y + 7).toBeGreaterThanOrEqual(PAD_TOP);
            expect(t.y + 7).toBeLessThanOrEqual(PAD_TOP + INNER_H);
        }
    });
});

describe('AreaChart — الصفرُ لا يكتب أرقامًا لم تقع', () => {
    const areaTicks = (container: HTMLElement) =>
        Array.from(container.querySelectorAll('foreignObject')).map((fo) => fo.textContent ?? '').filter((s) => s.includes('ر.ع'));

    it('كلُّها أصفار: علامةُ الصفر وحدها', () => {
        const { container } = render(<AreaChart labels={['1', '2', '3']} data={[0, 0, 0]} format={fmt} />);

        expect(areaTicks(container)).toEqual(['0.000 ر.ع']);
        expect(container.innerHTML).not.toMatch(/NaN|Infinity/);
    });

    it('وبقيمٍ موجبة: أربعُ علامات كما كانت', () => {
        const { container } = render(<AreaChart labels={['1', '2', '3']} data={[10, 40, 25]} format={fmt} />);

        expect(areaTicks(container)).toHaveLength(4);
    });
});

describe('تقريرُ صافي الربح — حالُ الإنتاج', () => {
    it('ثلاثُ سلاسلَ أصفار: الرسمُ يُرسم، ولا «1.000» على محوره', () => {
        for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
        const zero = { sales: 0, tax: 0, net_revenue: 0, cogs: 0, gross_profit: 0, expenses: 0, net_profit: 0, margin: 0 };
        Object.assign(pageProps, {
            translations: {},
            context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 } },
            auth: { abilities: ['reports'], mayActions: [], features: ['reports_advanced'] },
            summary: { ...zero, scope_name: 'النشاط بالكامل', unallocated_count: 0 },
            unallocated: null,
            scope: { kind: 'business', branch_id: null, name: 'النشاط بالكامل' },
            comparison: null,
            series: { labels: ['1', '2', '3', '4'], net_revenue: [0, 0, 0, 0], gross_profit: [0, 0, 0, 0], net_profit: [0, 0, 0, 0] },
            rows: [],
            filters: { range: 'month', branch_id: null },
            options: { branches: [] },
            truncated: null,
            range: 'month',
            rangeLabel: 'هذا الشهر',
        });

        const { getByTestId } = render(<ReportsProfit />);
        const chart = getByTestId('profit-chart');
        const ticks = axis(chart);

        expect(ticks).toHaveLength(1);
        expect(ticks[0].text).toMatch(/^0\.000/);
        expect(chart.textContent).not.toMatch(/0\.250|0\.500|0\.750|1\.000/);
        for (const t of ticks) {
            expect(t.y).toBeGreaterThanOrEqual(0);
        }
    });
});
