import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { router } from '@inertiajs/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { pageProps } from './setup';

/*
 * منتقي النظام (Radix) لا يُفتح في jsdom — يُستبدل بقائمةٍ أصليّة بالعقد نفسه،
 * كما في `a-period-is-chosen-on-the-screen-and-carried-by-the-file`.
 */
vi.mock('@/Components/Field', async () => {
    const actual = await vi.importActual<Record<string, unknown>>('@/Components/Field');

    return {
        ...actual,
        Select: ({
            options,
            value,
            onChange,
            'aria-label': label,
        }: {
            options: { label: string; value: string | number }[];
            value?: string | number | null;
            onChange?: (e: { target: { value: string; name: string } }) => void;
            'aria-label'?: string;
        }) => (
            <select aria-label={label} value={value == null ? '' : String(value)} onChange={(e) => onChange?.({ target: { value: e.target.value, name: '' } })}>
                {options.map((o) => (
                    <option key={o.value} value={o.value}>
                        {o.label}
                    </option>
                ))}
            </select>
        ),
    };
});

vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

vi.mock('@/hooks/useLiveFeed', () => ({ default: () => ({ data: null, updatedAt: null }) }));

const { default: ReportScreen } = await import('@/Components/ReportScreen');
const { default: ReportsSales } = await import('@/Pages/Admin/Reports/Sales');
const { default: ReportsCustomers } = await import('@/Pages/Admin/Reports/Customers');
const { default: ReportsPayments } = await import('@/Pages/Admin/Reports/Payments');
const { default: ReportsStaff } = await import('@/Pages/Admin/Reports/Staff');

/**
 * الفترةُ بجوار «تصدير» — ما يُعرض هو ما يُنزَّل.
 *
 * ═══ ما يُحرس ═══
 *
 * ١) في رأس كلّ تقريرٍ زمنيّ زرٌّ يقول الفترةَ المعروضة باسمها من الخادم
 *    (`period.label`) — بجوار «تصدير» في الحاوية نفسِها، وقبله في RTL.
 * ٢) يفتح النافذةَ الوحيدة (`PeriodControls`) — ولا «اختيار فترة» ثانٍ في
 *    الجسم، والأزرارُ السريعة باقيةٌ فيه.
 * ٣) الاختيارُ منه يُبقي المرشّحات الأخرى (الحالة، الفرع، القناة، البوتيك).
 * ٤) تقريرٌ بلا فترة بلا زرّ، والرأسُ يلتفّ على الجوال.
 */

const EVERYTHING = {
    presets: ['today', 'week', 'month', 'year', 'all'],
    previous_month: true, month: true, month_range: true, year: true, custom: true, all: true,
};

const state = (over: Record<string, unknown>) => ({
    kind: 'month', range: null, label: '', params: {}, from: null, to: null,
    capabilities: EVERYTHING, years: [2026, 2025, 2024], ...over,
});

const ALL = state({ kind: 'all', label: 'كل الفترات', params: { period: 'all' } });
const SEPTEMBER = state({ kind: 'month', label: 'سبتمبر 2025', params: { period: 'month', month: '2025-09' }, from: '2025-09-01', to: '2025-09-30' });
const YEAR = state({ kind: 'year', label: '2024', params: { period: 'year', year: '2024' }, from: '2024-01-01', to: '2024-12-31' });
const RANGE = state({
    kind: 'month_range', label: 'نوفمبر 2024 – فبراير 2025',
    params: { period: 'month_range', month_from: '2024-11', month_to: '2025-02' }, from: '2024-11-01', to: '2025-02-28',
});
const THIS_MONTH = state({ kind: 'month', range: 'month', label: 'هذا الشهر (2026-10-01 — 2026-10-10)', params: { range: 'month' }, from: '2026-10-01', to: '2026-10-10' });

const reset = (over: Record<string, unknown>) => {
    for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    Object.assign(pageProps, {
        translations: {}, context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 } },
        // ومن يقرأ التقرير هنا يصدّره: التصديرُ فعلٌ باسمه (`report.export`) منذ فُصلت أفعالُ الأقسام
        auth: { abilities: ['reports'], mayActions: ['report.export'], planFeatures: { reports_advanced: true } }, ...over,
    });
};

const report = (period: unknown, filters: Record<string, string | null> = { status: 'paid', branch_id: '2' }) => {
    reset({ period });

    return render(
        <ReportScreen title="تقرير الطلبات" reportKey="orders" subtitle="" range={null} rangeLabel="الفترة" filters={filters} stats={[]}>
            <div />
        </ReportScreen>,
    );
};

/** آخرُ ما أُرسل إلى الخادم */
const sent = () => {
    const calls = vi.mocked(router.get).mock.calls;

    return calls[calls.length - 1] as unknown as [string, Record<string, string>];
};

beforeEach(() => {
    vi.mocked(router.get).mockClear();
    window.history.replaceState({}, '', '/admin/reports/orders');
});

afterEach(() => cleanup());

describe('زرُّ الفترة في الرأس يقولها باسمها', () => {
    it.each([
        ['كل الفترات', ALL],
        ['سبتمبر 2025', SEPTEMBER],
        ['2024', YEAR],
        ['نوفمبر 2024 – فبراير 2025', RANGE],
        ['هذا الشهر (2026-10-01 — 2026-10-10)', THIS_MONTH],
    ])('«الفترة: %s» — من `period.label` لا من حسابٍ في المتصفّح', (label, period) => {
        report(period);

        const button = screen.getByTestId('period-button');
        expect(button).toHaveTextContent(`الفترة: ${label}`);
        expect(button).toHaveAccessibleName(`تغيير فترة التقرير — الفترة: ${label}`);
        expect(button).toHaveAttribute('title', 'تغيير فترة التقرير');
    });

    it('بجوار «تصدير» في حاويةٍ واحدة — والفترةُ أوّلًا (يمينَه في RTL)', () => {
        report(SEPTEMBER);

        const button = screen.getByTestId('period-button');
        const exportMenu = screen.getByTestId('export-menu');
        expect(button.parentElement).toBe(exportMenu.parentElement);
        expect(button.compareDocumentPosition(exportMenu) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
        // والتصديرُ لا يحمل فترةً في قائمته — `withFilters` تحملها من الرابط كما كانت
        expect(exportMenu).toHaveTextContent('تصدير');
    });
});

describe('نافذةٌ واحدة وبابٌ واحد إليها', () => {
    it('الزرُّ يفتح نافذةَ المنتقي على الفترة المعروضة', () => {
        report(SEPTEMBER);
        expect(screen.queryByRole('dialog')).toBeNull();

        fireEvent.click(screen.getByTestId('period-button'));

        const dialog = screen.getByRole('dialog');
        expect(within(dialog).getByRole('radio', { name: 'شهر محدد' })).toBeChecked();
        expect(within(dialog).getByRole('combobox', { name: 'الشهر — السنة' })).toHaveValue('2025');
    });

    it('ولا «اختيار فترة» ثانٍ في الجسم — والأزرارُ السريعة واسمُ الفترة ومسحُها باقية', () => {
        report(SEPTEMBER);

        expect(screen.queryByTestId('period-open')).toBeNull();
        expect(screen.queryByRole('button', { name: 'اختيار فترة' })).toBeNull();
        expect(screen.getAllByTestId('period-button')).toHaveLength(1);

        const body = screen.getByTestId('period-controls');
        for (const tab of ['اليوم', 'الأسبوع', 'الشهر', 'السنة', 'الكل']) {
            expect(within(body).getByRole('tab', { name: tab })).toBeInTheDocument();
        }
        expect(within(body).getByTestId('period-active')).toHaveTextContent('الفترة: سبتمبر 2025');
        expect(within(body).getByTestId('period-clear')).toBeInTheDocument();
    });

    it('والاختيارُ من الرأس يُبقي المرشّحات الأخرى — ويمحو مفاتيحَ الفترة القديمة', () => {
        report(SEPTEMBER, { status: 'paid', branch_id: '2', period: 'month', month: '2025-09', page: '4' });

        fireEvent.click(screen.getByTestId('period-button'));
        fireEvent.click(screen.getByRole('radio', { name: 'سنة كاملة' }));
        fireEvent.change(screen.getByRole('combobox', { name: 'السنة' }), { target: { value: '2024' } });
        fireEvent.click(screen.getByTestId('period-apply'));

        expect(sent()).toEqual(['/admin/reports/orders', { status: 'paid', branch_id: '2', period: 'year', year: '2024' }, expect.anything()]);
        expect(screen.queryByRole('dialog')).toBeNull();
    });

    it('والزرُّ السريع في الجسم يعمل كما كان', () => {
        report(SEPTEMBER);

        fireEvent.click(screen.getByRole('tab', { name: 'السنة' }));
        expect(sent()[1]).toEqual({ status: 'paid', branch_id: '2', range: 'year' });
    });
});

describe('تقريرٌ بلا فترة بلا زرّ', () => {
    it('الرصيدُ الحالي والمواسم: «تصدير» وحده', () => {
        report(null);

        expect(screen.queryByTestId('period-button')).toBeNull();
        expect(screen.queryByTestId('period-controls')).toBeNull();
        expect(screen.getByTestId('export-menu')).toBeInTheDocument();
    });
});

describe('على الجوال', () => {
    it('الزوجُ يلتفّ ولا يخرج من الشاشة — والاسمُ الطويل يُقصّ داخل الزرّ', () => {
        report(THIS_MONTH);

        const button = screen.getByTestId('period-button');
        expect(button.parentElement).toHaveClass('flex-wrap');
        expect(button).toHaveClass('max-w-full');
        expect(within(button).getByText(/^الفترة:/)).toHaveClass('truncate', 'min-w-0');
    });
});

describe('الصفحاتُ المخصّصة', () => {
    it('«ملخّص المبيعات»: الزرُّ بجوار «تصدير»، والقناةُ والبوتيك يبقيان مع الفترة', () => {
        window.history.replaceState({}, '', '/admin/reports/sales');
        reset({
            summary: {
                sales: 50, cogs: 20, profit: 30, profit_kind: 'net', expenses: 100, tax: 0,
                products: 3, inventory_alerts: 0, employees: 1, customers: 2, payment_methods: 1,
            },
            salesSeries: { labels: ['1'], full: ['1'], data: [50], counts: [2], range: null },
            range: null,
            paymentDistribution: { labels: ['نقدي'], series: [50] },
            topSellingProducts: [],
            channel: 'website',
            channels: [{ value: 'website', label: 'الموقع الإلكتروني' }],
            boutique: { kind: 'boutique', id: 7, name: 'بوتيك' },
            period: SEPTEMBER,
        });
        render(<ReportsSales />);

        const button = screen.getByTestId('period-button');
        expect(button).toHaveTextContent('الفترة: سبتمبر 2025');
        expect(button.parentElement).toBe(screen.getByTestId('export-menu').parentElement);
        expect(screen.queryByTestId('period-open')).toBeNull();

        fireEvent.click(button);
        fireEvent.click(screen.getByRole('radio', { name: 'كل الفترات' }));
        fireEvent.click(screen.getByTestId('period-apply'));

        expect(sent()[0]).toBe('/admin/reports/sales');
        expect(sent()[1]).toEqual({ channel: 'website', boutique: '7', period: 'all' });
    });

    it('«العملاء الأكثر إنفاقًا»: الزرُّ نفسُه والنافذةُ نفسُها', () => {
        reset({ rows: [], limit: 500, summary: { total: 0, customers: 0, orders: 0, average: 0 }, rangeLabel: '', period: YEAR });
        render(<ReportsCustomers />);

        expect(screen.getByTestId('period-button')).toHaveTextContent('الفترة: 2024');
        expect(screen.queryByTestId('period-open')).toBeNull();

        fireEvent.click(screen.getByTestId('period-button'));
        expect(within(screen.getByRole('dialog')).getByRole('radio', { name: 'سنة كاملة' })).toBeChecked();
    });

    it.each([
        ['وسائل الدفع', () => <ReportsPayments />, { rows: [], summary: { total: 0, count: 0, active: 0, topName: null, topTotal: 0 } }],
        ['أداء الموظفين', () => <ReportsStaff />, { rows: [], summary: { total: 0, staff: 0, sellers: 0, average: 0, topName: null, topSales: 0 } }],
    ])('«%s»: الزرُّ بجوار «تصدير» ويفتح النافذةَ الوحيدة', (_name, page, props) => {
        reset({ ...props, rangeLabel: '', period: RANGE });
        render(page());

        const button = screen.getByTestId('period-button');
        expect(button).toHaveTextContent('الفترة: نوفمبر 2024 – فبراير 2025');
        expect(button.parentElement).toBe(screen.getByTestId('export-menu').parentElement);
        expect(screen.queryByTestId('period-open')).toBeNull();

        fireEvent.click(button);
        expect(within(screen.getByRole('dialog')).getByRole('radio', { name: 'نطاق أشهر' })).toBeChecked();
    });
});
