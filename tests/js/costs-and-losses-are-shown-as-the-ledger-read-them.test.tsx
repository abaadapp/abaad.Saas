import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { pageProps } from './setup';

vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

const { default: ReportsCosts, pctText } = await import('@/Pages/Admin/Reports/Costs');

/**
 * التكاليف والخسائر كما قرأها الخادم من الدفتر.
 *
 * ═══ ما يُحرس ═══
 *
 * ١) الشاشةُ لا تحسب: أرقامٌ متعمَّدةُ التناقض تُعرض كما وصلت.
 * ٢) نسبةٌ بلا سابقٍ موجب تُكتب «—» — لا Infinity ولا NaN — والسالبُ يُكتب سالبًا.
 * ٣) ملاحظةُ الفرع ومصروفاتُ خارج الدفتر تُقال حين تقع وحدها.
 * ٤) سطورُ الصفّ تُطلب بمرشّحات الشاشة نفسِها وبحسابه وفئته.
 */

const currency = { code: 'OMR', symbol: 'ر.ع', decimals: 3 };

const change = (delta: number, pct: number | null, share: number | null = null) => ({ delta, change_pct: pct, share });

const draw = (over: Record<string, unknown> = {}) => {
    for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    Object.assign(pageProps, {
        translations: {},
        context: { currency },
        auth: { abilities: ['reports', 'finance'], mayActions: [], features: ['reports_advanced'] },
        // الإجماليُّ عمدًا لا يساوي مجموعَ البطاقات: الشاشةُ تعرض ولا تجمع
        summary: { total: 999, cost_of_sales: 10, operating: 20, losses: 30, other: 40 },
        previousSummary: { total: 0, cost_of_sales: 0, operating: 0, losses: 0, other: 0 },
        comparison: [
            { key: 'total', current: 999, previous: 0, ...change(999, null) },
            { key: 'cost_of_sales', current: 10, previous: 0, ...change(10, null) },
        ],
        categories: [
            {
                key: 'operating', label: 'مصروفات التشغيل', current: -40, previous: 40, ...change(-80, -200, null),
                rows: [{ category: 'operating', account_id: 55, code: '5500', account: 'الكهرباء والماء', current: -40, previous: 40, ...change(-80, -200, null) }],
            },
            { key: 'other', label: 'مصروفات وخسائر أخرى', current: 0, previous: 0, ...change(0, null, null), rows: [] },
        ],
        scope: {
            from: '2026-09-01', to: '2026-09-30', branch_id: null, category: null, name: 'النشاط بالكامل',
            restricted: false, previous: { from: '2026-08-02', to: '2026-08-31' },
        },
        reconciliation: { unposted_count: 0, unposted_amount: 0, unpaid_count: 0, unpaid_amount: 0 },
        filters: { from: '2026-09-01', to: '2026-09-30', branch_id: null, category: null },
        options: { branches: [{ value: '7', label: 'مسقط' }], categories: [{ value: 'operating', label: 'مصروفات التشغيل' }] },
        ...over,
    });

    return render(<ReportsCosts />);
};

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('التكاليف والخسائر', () => {
    it('تعرض أرقامَ الخادم كما وصلت ولا تجمع', () => {
        draw();

        const cards = screen.getByTestId('costs-cards');
        expect(cards).toHaveTextContent('999.000');
        expect(cards).toHaveTextContent('40.000');
        expect(screen.getByTestId('costs-total')).toHaveTextContent('999.000');
        expect(screen.getByTestId('costs-period')).toHaveTextContent('2026-08-02 → 2026-08-31');
    });

    it('نسبةٌ بلا سابقٍ موجب «—» والسالبُ سالب', () => {
        draw();

        expect(pctText(null)).toBe('—');
        expect(pctText(-200)).toBe('-200.0%');
        expect(document.body.textContent).not.toMatch(/Infinity|NaN/);

        const row = screen.getAllByTestId('costs-account')[0];
        expect(row).toHaveTextContent('-200.0%');
        expect(row).toHaveTextContent('-40.000');
        expect(within(screen.getByTestId('costs-comparison')).getAllByText('—').length).toBeGreaterThan(0);
        // والفئةُ بلا حسابات لا تُرسم صفًّا فارغًا
        expect(screen.queryByTestId('costs-category-other')).toBeNull();
    });

    it('ملاحظةُ الفرع ومصروفاتُ خارج الدفتر حين تقع وحدها', () => {
        draw();
        expect(screen.queryByTestId('costs-branch-note')).toBeNull();
        expect(screen.queryByTestId('costs-unposted')).toBeNull();

        draw({
            scope: {
                from: '2026-09-01', to: '2026-09-30', branch_id: 7, category: null, name: 'فرع مسقط',
                restricted: false, previous: { from: '2026-08-02', to: '2026-08-31' },
            },
            reconciliation: { unposted_count: 2, unposted_amount: 70, unpaid_count: 0, unpaid_amount: 0 },
        });
        expect(screen.getByTestId('costs-branch-note')).toBeInTheDocument();
        expect(screen.getByTestId('costs-unposted')).toHaveTextContent('70.000');
    });

    it('سطورُ الصفّ تُطلب بمرشّحات الشاشة وبحسابه وفئته', async () => {
        const fetch = vi.fn(async () => ({
            ok: true,
            json: async () => ({
                total: -40, count: 1, page: 1, last_page: 1,
                lines: [{ id: 1, date: '2026-09-05', number: 'JV-000001', description: 'عكس: مصروف', memo: null, branch: null,
                    account: 'الكهرباء والماء', code: '5500', category: 'مصروفات التشغيل', net: -40, source: 'عكس مصروف', reference: 'Expense #3', reversal: true }],
            }),
        }));
        vi.stubGlobal('fetch', fetch);

        draw({ filters: { from: '2026-09-01', to: '2026-09-30', branch_id: '7', category: null } });
        fireEvent.click(screen.getAllByTestId('costs-account')[0]);

        await waitFor(() => expect(screen.getByTestId('costs-drill')).toBeInTheDocument());
        const url = String((fetch.mock.calls[0] as unknown[])[0]);
        expect(url).toContain('admin.reports.costs.lines');
        for (const part of ['operating', '2026-09-01', '2026-09-30', '7', '55']) {
            expect(url).toContain(part);
        }
        expect(screen.getByTestId('costs-drill')).toHaveTextContent('-40.000');
    });
});
