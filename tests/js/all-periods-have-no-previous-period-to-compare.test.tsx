import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { pageProps } from './setup';

vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));
vi.mock('@/Components/charts/BarChart', () => ({ default: () => null }));

const { default: ReportsCosts } = await import('@/Pages/Admin/Reports/Costs');
const { default: WasteAnalytics } = await import('@/Pages/Admin/Reports/Waste');

/**
 * «كل الفترات» في التكاليف والهالك — بلا مقارنةٍ مختلَقة.
 *
 * ═══ ما يُحرس ═══
 *
 * ١) السابقُ `null` من الخادم يُكتب «لا توجد مقارنة للفترة السابقة» وشرطاتٍ —
 *    لا أصفارٌ تُقرأ «لم يُصرف شيءٌ قبلها» ولا اتجاهٌ على البطاقات.
 * ٢) سطورُ الصفّ تُطلب بفترة الشاشة (`period=all`) لا بحدّين فارغين يسقطان
 *    في الخادم إلى الشهر الجاري.
 */

const currency = { code: 'OMR', symbol: 'ر.ع', decimals: 3 };

const ALL = {
    kind: 'all', range: null, label: 'كل الفترات', params: { period: 'all' }, from: null, to: null,
    capabilities: { presets: ['today', 'week', 'month', 'year'], previous_month: true, month: true, month_range: true, year: true, custom: true, all: true },
    years: [2026, 2025, 2024],
};

const reset = (props: Record<string, unknown>) => {
    for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    Object.assign(pageProps, {
        translations: {},
        context: { currency },
        auth: { abilities: ['reports', 'finance'], mayActions: [], features: ['reports_advanced'] },
        ...props,
    });
};

const none = { delta: null, change_pct: null };

const drawCosts = () => {
    reset({
        summary: { total: 35, cost_of_sales: 0, operating: 35, losses: 0, other: 0 },
        previousSummary: null,
        comparison: [
            { key: 'total', current: 35, previous: null, ...none },
            { key: 'operating', current: 35, previous: null, ...none },
        ],
        categories: [
            {
                key: 'operating', label: 'مصروفات التشغيل', current: 35, previous: null, ...none, share: 100,
                rows: [{ category: 'operating', account_id: 55, code: '5300', account: 'الإيجار', current: 35, previous: null, ...none, share: 100 }],
            },
        ],
        scope: { from: null, to: null, branch_id: 7, category: null, name: 'فرع مسقط', restricted: false, previous: null },
        reconciliation: { unposted_count: 0, unposted_amount: 0, unpaid_count: 0, unpaid_amount: 0 },
        filters: { from: null, to: null, branch_id: '7', category: null },
        options: { branches: [{ value: '7', label: 'مسقط' }], categories: [] },
        period: ALL,
    });

    return render(<ReportsCosts />);
};

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('التكاليف في «كل الفترات»', () => {
    it('لا مقارنةَ بفترةٍ سابقة — جملةٌ وشرطاتٌ لا أصفار', () => {
        drawCosts();

        expect(screen.getByTestId('costs-period')).toHaveTextContent('كل الفترات');
        expect(screen.getByTestId('costs-period')).toHaveTextContent('لا توجد مقارنة للفترة السابقة');
        expect(screen.getByTestId('costs-no-comparison')).toBeInTheDocument();
        expect(screen.getByTestId('costs-driver')).toHaveTextContent('لا توجد مقارنة للفترة السابقة');

        const row = screen.getAllByTestId('costs-account')[0];
        // [الحساب، هذه المدّة، الحصّة، السابقة، الفرق، التغيّر]
        const cells = within(row).getAllByRole('cell').map((c) => c.textContent);
        expect(cells.slice(3)).toEqual(['—', '—', '—']);
        expect(within(screen.getByTestId('costs-total')).getAllByRole('cell').map((c) => c.textContent).slice(3)).toEqual(['—', '—', '—']);

        // ولا اتجاهَ على البطاقات: لا «زادت» ولا «جديد في هذه الفترة»
        expect(screen.getByTestId('costs-cards')).not.toHaveTextContent('الفترة السابقة');
        expect(screen.getByTestId('costs-cards')).not.toHaveTextContent('جديد في هذه الفترة');
        expect(document.body.textContent).not.toMatch(/NaN|Infinity|null/);
    });

    it('سطورُ الصفّ بفترة الشاشة — `period=all` والفرع', async () => {
        const fetch = vi.fn(async () => ({
            ok: true,
            json: async () => ({ total: 35, count: 2, page: 1, last_page: 1, lines: [] }),
        }));
        vi.stubGlobal('fetch', fetch);

        drawCosts();
        fireEvent.click(screen.getAllByTestId('costs-account')[0]);

        await waitFor(() => expect(fetch).toHaveBeenCalled());
        const url = String((fetch.mock.calls[0] as unknown[])[0]);
        // `route()` في الاختبار تصفّ القيم: الفئةُ والصفحةُ والفترةُ والفرعُ والحساب — بلا حدّين
        expect(url).toBe('/admin.reports.costs.lines/operating/1/all/7/55');
    });
});

describe('الهالك في «كل الفترات»', () => {
    it('بطاقةُ المدّة السابقة شرطةٌ تقول لماذا — ولا نسبةَ على القيمة', () => {
        reset({
            totals: { count: 2, quantity: 7, value: 7 },
            previous: null,
            change: null,
            byProduct: [], byCategory: [], byBranch: [], byReason: [],
            overTime: [{ label: '2022-05', value: 4, quantity: 4 }],
            versusConsumption: [], insights: [], suspicious: [],
            filters: { from: null, to: null, branch_id: '7', category_id: null, product_id: null, reason: null },
            period: ALL,
            options: { branches: [], categories: [], products: [], reasons: [] },
        });
        render(<WasteAnalytics />);

        const card = screen.getByText('المدّة السابقة').closest('div')!.parentElement!;
        expect(card).toHaveTextContent('—');
        expect(document.body).toHaveTextContent('لا توجد مقارنة للفترة السابقة');
        expect(document.body.textContent).not.toMatch(/NaN|Infinity|null/);
    });
});
