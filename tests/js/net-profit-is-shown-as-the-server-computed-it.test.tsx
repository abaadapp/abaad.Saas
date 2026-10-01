import { fireEvent, render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { useState } from 'react';

import { pageProps } from './setup';

vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

const { default: ReportsProfit } = await import('@/Pages/Admin/Reports/Profit');
const { default: ScopeFields, EMPTY_SCOPE, scopeReady } = await import('@/Pages/Admin/Expenses/ScopeFields');

/**
 * صافي الربح كما حسبه الخادم — والمصروفُ بنطاقه.
 *
 * ═══ ما يُحرس ═══
 *
 * ١) الشاشةُ لا تحسب: أرقامٌ متعمَّدةُ التناقض تُعرض كما وصلت. ورقمٌ يُحسب في
 *    المتصفّح يفترق يومًا عن ملفّه المصدَّر.
 * ٢) ربحُ الفرع يقول ما لم يطرحه: العامُّ غيرُ الموزّع يُكتب بمبلغه تحت
 *    المعادلة — وللنشاط كلِّه لا يُكتب، فهو من مصروفاته.
 * ٣) الموزَّعُ لا يُحفظ ومتبقّيه غيرُ صفر — بأجزاء الألف لا بالكسر.
 */

const currency = { code: 'OMR', symbol: 'ر.ع', decimals: 3 };

const figures = (over: Record<string, unknown> = {}) => ({
    sales: 1000, tax: 50, net_revenue: 950, cogs: 600, gross_profit: 350, expenses: 200, net_profit: 150, margin: 15.8,
    ...over,
});

const draw = (over: Record<string, unknown> = {}) => {
    for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    Object.assign(pageProps, {
        translations: {},
        context: { currency },
        auth: { abilities: ['reports'], mayActions: [], features: ['reports_advanced'] },
        summary: { ...figures(), scope_name: 'النشاط بالكامل', unallocated_count: 0 },
        unallocated: null,
        scope: { kind: 'business', branch_id: null, name: 'النشاط بالكامل' },
        comparison: [
            { key: 'net_profit', current: 150, previous: 100, diff: 50 },
            { key: 'margin', current: 15.8, previous: 12.3, diff: 3.5 },
        ],
        series: { labels: ['1', '2'], net_revenue: [500, 450], gross_profit: [200, 150], net_profit: [100, 50] },
        rows: [
            { key: '2027-03-01', period: 'الاثنين ١', ...figures({ net_profit: 100 }) },
            { key: '2027-03-02', period: 'الثلاثاء ٢', ...figures({ net_profit: 50 }) },
        ],
        filters: { range: 'month', branch_id: null },
        options: { branches: [{ value: '7', label: 'مسقط' }] },
        truncated: null,
        range: 'month',
        rangeLabel: 'هذا الشهر',
        ...over,
    });

    return render(<ReportsProfit />);
};

describe('تقريرُ صافي الربح', () => {
    it('يعرض ما حسبه الخادم ولا يُعيد حسابَه — ولو تناقضت الأرقام', () => {
        // صافي ربحٍ لا يساوي فرقَ ما فوقه: إن حُسب في المتصفّح ظهر ٣٥٠ − ٢٠٠
        draw({
            summary: { ...figures({ net_profit: 999.5, margin: 77.7 }), scope_name: 'النشاط بالكامل', unallocated_count: 0 },
            comparison: null,
            rows: [],
        });

        expect(screen.getAllByText(/999\.500/).length).toBeGreaterThan(0);
        expect(screen.getAllByText('77.7%').length).toBeGreaterThan(0);
        expect(screen.queryByText(/^150\.000/)).toBeNull();
    });

    it('والإجماليُّ صفُّ الملخّص — لا مجموعُ الصفوف في المتصفّح', () => {
        draw({ summary: { ...figures({ net_profit: 123.456 }), scope_name: 'النشاط بالكامل', unallocated_count: 0 } });

        const table = screen.getByTestId('profit-rows');
        const total = within(table).getByText('الإجمالي').closest('tr')!;
        expect(total).toHaveTextContent('123.456');
    });

    it('النشاطُ كلُّه: معادلتُه، ولا كلمةَ عن غير الموزّع', () => {
        draw();

        const formula = screen.getByTestId('profit-formula');
        expect(formula).toHaveTextContent('المبيعات');
        expect(formula).toHaveTextContent('المصروفات المدفوعة');
        expect(screen.queryByTestId('profit-unallocated-note')).toBeNull();
        expect(screen.queryByText('المصروفات العامة غير الموزعة')).toBeNull();
    });

    it('الفرع: معادلتُه، وغيرُ الموزّع بمبلغه تحتها وفي بطاقة — لا مطروحًا', () => {
        draw({
            summary: { ...figures(), scope_name: 'فرع مسقط', unallocated: 1000, unallocated_count: 2 },
            unallocated: { amount: 1000, count: 2 },
            scope: { kind: 'branch', branch_id: 7, name: 'فرع مسقط' },
            filters: { range: 'month', branch_id: '7' },
        });

        expect(screen.getByTestId('profit-formula')).toHaveTextContent('مصروفات الفرع المنسوبة إليه');
        const note = screen.getByTestId('profit-unallocated-note');
        expect(note).toHaveTextContent('صافي ربح الفرع يخصم المصروفات المخصصة لهذا الفرع فقط');
        expect(note).toHaveTextContent('1,000.000');
        expect(screen.getByText('المصروفات العامة غير الموزعة')).toBeInTheDocument();
        expect(screen.getByText('مصروفات الفرع')).toBeInTheDocument();
    });

    it('والفرعُ بلا عامٍّ غيرِ موزّع لا يُنبَّه عنه', () => {
        draw({
            unallocated: { amount: 0, count: 0 },
            scope: { kind: 'branch', branch_id: 7, name: 'فرع مسقط' },
        });

        expect(screen.queryByTestId('profit-unallocated-note')).toBeNull();
    });

    it('المقارنة: الهامشُ بنقاطٍ مئويّة', () => {
        draw().unmount();
        draw();
        expect(screen.getByTestId('profit-comparison')).toHaveTextContent('+3.5 نقطة مئوية');
    });

    it('ولا مقارنةَ في «كل الفترات»', () => {
        draw({ comparison: null, range: 'all' });
        expect(screen.queryByTestId('profit-comparison')).toBeNull();
    });

    it('ومنحنى الثلاث بأسمائها — والخسارةُ تحت الصفر لا خارج الإطار', () => {
        draw({ series: { labels: ['1', '2'], net_revenue: [100, 50], gross_profit: [20, -10], net_profit: [-40, -90] } });

        const chart = screen.getByTestId('profit-chart');
        expect(chart).toHaveTextContent('صافي الإيرادات');
        expect(chart).toHaveTextContent('مجمل الربح');
        expect(chart).toHaveTextContent('صافي الربح');
        for (const path of chart.querySelectorAll('path')) {
            for (const n of (path.getAttribute('d') ?? '').match(/-?\d+(\.\d+)?/g) ?? []) {
                expect(Number(n)).toBeGreaterThanOrEqual(0);
            }
        }
    });
});

describe('نطاقُ المصروف', () => {
    const Harness = ({ amount }: { amount: string }) => {
        const [v, setV] = useState(EMPTY_SCOPE);

        return (
            <>
                <ScopeFields
                    value={v}
                    onChange={setV}
                    amount={amount}
                    branches={[{ id: 1, name: 'مسقط' }, { id: 2, name: 'صحار' }]}
                    currency={currency}
                />
                <output data-testid="ready">{String(scopeReady(v, amount))}</output>
            </>
        );
    };

    it('يبدأ «النشاط بالكامل» — ولا يتبع فرعَ الجلسة', () => {
        render(<Harness amount="1000" />);

        expect(screen.getByRole('radio', { name: 'النشاط بالكامل' })).toHaveAttribute('aria-checked', 'true');
        expect(screen.getByTestId('ready')).toHaveTextContent('true');
    });

    it('الموزَّع: المتبقّي يُعرض، ولا يُحفظ حتى يصير صفرًا', () => {
        render(<Harness amount="1000" />);
        fireEvent.click(screen.getByRole('radio', { name: 'موزع على عدة فروع' }));

        expect(screen.getByTestId('allocation-remaining')).toHaveTextContent('1,000.000');
        expect(screen.getByTestId('ready')).toHaveTextContent('false');

        const amounts = () => screen.getAllByLabelText('المبلغ الموزع');
        fireEvent.change(amounts()[0], { target: { value: '600' } });
        expect(screen.getByTestId('allocation-remaining')).toHaveTextContent('400.000');
        expect(screen.getByTestId('ready')).toHaveTextContent('false');
    });

    it('وأجزاءُ الألف لا الكسر: ٠٫١ + ٠٫٢ = ٠٫٣', () => {
        const value = {
            scope: 'split' as const,
            branch_id: '',
            allocations: [
                { branch_id: '1', amount: '0.1' },
                { branch_id: '2', amount: '0.2' },
            ],
        };

        expect(scopeReady(value, '0.3')).toBe(true);
        expect(scopeReady(value, '0.31')).toBe(false);
        expect(scopeReady({ ...value, allocations: [{ branch_id: '', amount: '0.3' }] }, '0.3')).toBe(false);
        expect(scopeReady({ scope: 'branch', branch_id: '', allocations: [] }, '5')).toBe(false);
        expect(scopeReady({ scope: 'branch', branch_id: '2', allocations: [] }, '5')).toBe(true);
    });
});
