import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { router } from '@inertiajs/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { pageProps } from './setup';

/*
 * منتقي النظام (Radix) لا يُفتح في jsdom — يُستبدل بقائمةٍ أصليّة بالعقد نفسه
 * (`value` و`onChange` و`options` و`aria-label`)، كما في اختبارات الحقول.
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

const { default: PeriodControls, withPeriod, draftParams } = await import('@/Components/PeriodControls');
const { default: ReportScreen } = await import('@/Components/ReportScreen');
const { withFilters } = await import('@/lib/exportLink');
const { default: useLiveFeed } = await import('@/hooks/useLiveFeed');

type State = Parameters<typeof PeriodControls>[0]['period'];

const EVERYTHING = {
    presets: ['today', 'week', 'month', 'year', 'all'],
    previous_month: true, month: true, month_range: true, year: true, custom: true, all: true,
};

const period = (over: Partial<State> = {}): State => ({
    kind: 'month', range: 'month', label: 'هذا الشهر (2026-10-01 — 2026-10-10)',
    params: { range: 'month' }, from: '2026-10-01', to: '2026-10-10',
    capabilities: EVERYTHING, years: [2026, 2025, 2024, 2023, 2022],
    ...over,
});

const SEPTEMBER = period({
    kind: 'month', range: null, label: 'سبتمبر 2025',
    params: { period: 'month', month: '2025-09' }, from: '2025-09-01', to: '2025-09-30',
});

/** آخرُ ما أُرسل إلى الخادم — المسارُ ومعاملاتُه */
const sent = () => {
    const calls = vi.mocked(router.get).mock.calls;

    return calls[calls.length - 1] as unknown as [string, Record<string, string>];
};

const draw = (state: State, params: Record<string, string | null> = { channel: 'website', boutique: '7', page: '3' }) =>
    render(<PeriodControls period={state} params={params} />);

const open = () => fireEvent.click(screen.getByTestId('period-open'));
const pick = (kind: string) => fireEvent.click(screen.getByRole('radio', { name: kind }));
const apply = () => fireEvent.click(screen.getByTestId('period-apply'));

beforeEach(() => {
    vi.mocked(router.get).mockClear();
    window.history.replaceState({}, '', '/admin/reports/sales');
});

afterEach(() => cleanup());

describe('الأزرارُ السريعة كما كانت', () => {
    it('تُضاء للزرّ المختار، و«السنة» تُرسل `range=year` ومعها القناةُ والبوتيك — بلا رقم صفحة', () => {
        draw(period());

        expect(screen.getByRole('tab', { name: 'الشهر' })).toHaveAttribute('aria-selected', 'true');
        fireEvent.click(screen.getByRole('tab', { name: 'السنة' }));

        expect(sent()[1]).toEqual({ channel: 'website', boutique: '7', range: 'year' });
    });

    it('وشهرٌ مضى لا يُضيء «الشهر» — ويُقال باسمه ومعه «مسح الفترة»', () => {
        draw(SEPTEMBER);

        for (const tab of screen.getAllByRole('tab')) {
            expect(tab).toHaveAttribute('aria-selected', 'false');
        }
        expect(screen.getByTestId('period-active')).toHaveTextContent('الفترة: سبتمبر 2025');

        fireEvent.click(screen.getByTestId('period-clear'));
        // مسحُ الفترة يعيد الافتراضيّ — ويُبقي ما سواها
        expect(sent()[1]).toEqual({ channel: 'website', boutique: '7' });
    });

    it('والزرُّ السريع يمحو مفاتيحَ الفترة السابقة كلَّها', () => {
        draw(SEPTEMBER, { period: 'month', month: '2025-09', from: '2024-01-01', to: '2024-02-01', year: '2023', status: 'paid' });

        fireEvent.click(screen.getByRole('tab', { name: 'الكل' }));

        expect(sent()[1]).toEqual({ status: 'paid', range: 'all' });
    });
});

describe('«اختيار فترة» — كلُّ نوعٍ رابطُه', () => {
    it('شهرٌ بعينه بسنته: سبتمبر 2025', () => {
        draw(period());
        open();
        pick('شهر محدد');
        const box = screen.getByTestId('period-month');
        fireEvent.change(within(box).getByRole('combobox', { name: 'الشهر — الشهر' }), { target: { value: '9' } });
        fireEvent.change(within(box).getByRole('combobox', { name: 'الشهر — السنة' }), { target: { value: '2025' } });
        apply();

        expect(sent()).toEqual(['/admin/reports/sales', { channel: 'website', boutique: '7', period: 'month', month: '2025-09' }, expect.anything()]);
    });

    it('والسنواتُ من أوّل حركةٍ — لا قائمةٌ قصيرة', () => {
        draw(period());
        open();
        pick('سنة كاملة');
        const years = within(screen.getByTestId('period-year')).getByRole('combobox', { name: 'السنة' });

        expect([...(years as HTMLSelectElement).options].map((o) => o.value)).toEqual(['2026', '2025', '2024', '2023', '2022']);
        fireEvent.change(years, { target: { value: '2022' } });
        apply();
        expect(sent()[1]).toEqual({ channel: 'website', boutique: '7', period: 'year', year: '2022' });
    });

    it('نطاقُ أشهرٍ عبر السنة — ورفضُ بدايةٍ بعد نهاية قبل أن يُرسل شيء', () => {
        draw(period());
        open();
        pick('نطاق أشهر');
        const from = screen.getByTestId('period-month-from');
        const to = screen.getByTestId('period-month-to');
        fireEvent.change(within(from).getByRole('combobox', { name: 'من شهر — الشهر' }), { target: { value: '11' } });
        fireEvent.change(within(from).getByRole('combobox', { name: 'من شهر — السنة' }), { target: { value: '2025' } });
        fireEvent.change(within(to).getByRole('combobox', { name: 'إلى شهر — الشهر' }), { target: { value: '2' } });
        fireEvent.change(within(to).getByRole('combobox', { name: 'إلى شهر — السنة' }), { target: { value: '2025' } });
        apply();

        expect(screen.getByTestId('period-error')).toHaveTextContent('شهر البداية بعد شهر النهاية.');
        expect(router.get).not.toHaveBeenCalled();

        fireEvent.change(within(from).getByRole('combobox', { name: 'من شهر — السنة' }), { target: { value: '2024' } });
        apply();
        expect(sent()[1]).toEqual({ channel: 'website', boutique: '7', period: 'month_range', month_from: '2024-11', month_to: '2025-02' });
    });

    it('فترةٌ مخصّصة بتاريخين — والبدايةُ بعد النهاية تُقال', () => {
        draw(period());
        open();
        pick('فترة مخصصة');
        const box = screen.getByTestId('period-custom');
        const [from, to] = within(box).getAllByDisplayValue(/.*/) as HTMLInputElement[];
        fireEvent.change(from, { target: { value: '2026-01-03' } });
        fireEvent.change(to, { target: { value: '2025-12-28' } });
        apply();
        expect(screen.getByTestId('period-error')).toHaveTextContent('تاريخ البداية بعد تاريخ النهاية.');

        fireEvent.change(from, { target: { value: '2025-12-28' } });
        fireEvent.change(to, { target: { value: '2026-01-03' } });
        apply();
        expect(sent()[1]).toEqual({ channel: 'website', boutique: '7', period: 'custom', from: '2025-12-28', to: '2026-01-03' });
    });

    it('الشهرُ السابق و«كل الفترات»', () => {
        draw(period());
        open();
        pick('الشهر السابق');
        apply();
        expect(sent()[1]).toEqual({ channel: 'website', boutique: '7', period: 'previous_month' });

        open();
        pick('كل الفترات');
        apply();
        expect(sent()[1]).toEqual({ channel: 'website', boutique: '7', period: 'all' });
    });

    it('والنافذةُ تُفتح على الفترة المعروضة', () => {
        draw(SEPTEMBER);
        open();

        expect(screen.getByRole('radio', { name: 'شهر محدد' })).toBeChecked();
        const box = screen.getByTestId('period-month');
        expect(within(box).getByRole('combobox', { name: 'الشهر — الشهر' })).toHaveValue('9');
        expect(within(box).getByRole('combobox', { name: 'الشهر — السنة' })).toHaveValue('2025');
    });

    it('وما لا يقبله التقرير لا يُعرض: بلا «كل الفترات» ولا زرِّها', () => {
        draw(period({ capabilities: { ...EVERYTHING, presets: ['today', 'week', 'month', 'year'], all: false } }));

        expect(screen.queryByRole('tab', { name: 'الكل' })).toBeNull();
        open();
        expect(screen.queryByRole('radio', { name: 'كل الفترات' })).toBeNull();
    });
});

describe('والملفُّ يحمل ما في الرابط', () => {
    it('`withFilters` تُلحق مفاتيحَ الفترة والمرشّحاتِ بالتصدير — وتترك رقمَ الصفحة', () => {
        window.history.replaceState({}, '', '/admin/reports/orders?period=month&month=2025-09&branch_id=2&status=paid&page=4&per_page=25');

        const url = new URL(withFilters('/admin/reports/orders/export/xlsx'), 'https://x.test');

        expect(Object.fromEntries(url.searchParams)).toEqual({ period: 'month', month: '2025-09', branch_id: '2', status: 'paid' });
    });

    it('`withPeriod` تُبقي المرشّحات وتمحو مفاتيحَ الفترة القديمة', () => {
        expect(withPeriod({ q: 'ورد', from: '2025-01-01', to: '2025-01-31', range: 'year', page: '2', empty: '' }, { period: 'year', year: '2024' }))
            .toEqual({ q: 'ورد', period: 'year', year: '2024' });
    });

    it('`draftParams` تكتب الشهرَ بسنته دائمًا — يناير 2025 غيرُ يناير 2026', () => {
        const base = { fromMonth: [2025, 1] as [number, number], toMonth: [2025, 1] as [number, number], year: 2025, from: '', to: '' };

        expect(draftParams({ ...base, kind: 'month', month: [2025, 1] })).toEqual({ params: { period: 'month', month: '2025-01' } });
        expect(draftParams({ ...base, kind: 'month', month: [2026, 1] })).toEqual({ params: { period: 'month', month: '2026-01' } });
    });
});

describe('في شاشة التقرير', () => {
    const reset = (over: Record<string, unknown>) => {
        for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
        Object.assign(pageProps, {
            translations: {}, context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 } },
            auth: { abilities: ['reports'], mayActions: [], planFeatures: { reports_advanced: true } }, ...over,
        });
    };

    const report = (range: string | null) =>
        render(
            <ReportScreen title="تقرير" reportKey="orders" subtitle="" range={range as never} rangeLabel="الفترة" filters={{ status: 'paid' }} stats={[]}>
                <div />
            </ReportScreen>,
        );

    it('تقريرٌ له فترة: المنتقي فوقه', () => {
        reset({ period: SEPTEMBER });
        report(null);

        expect(screen.getByTestId('period-controls')).toBeInTheDocument();
        expect(screen.getByTestId('period-active')).toHaveTextContent('سبتمبر 2025');
    });

    it('والرصيدُ الحالي بلا منتقٍ — لا يُوهَم أنّ له تاريخًا', () => {
        reset({ period: null });
        report(null);

        expect(screen.queryByTestId('period-controls')).toBeNull();
        expect(screen.queryByTestId('period-open')).toBeNull();
    });
});

describe('على الجوال', () => {
    it('الأزرارُ و«اختيار فترة» في صفٍّ يلتفّ — لا يخرج من الشاشة', () => {
        draw(period());

        expect(screen.getByTestId('period-open').parentElement).toHaveClass('flex-wrap');
    });
});

describe('والتحديثُ الحيّ للأزرار السريعة وحدها', () => {
    const Probe = ({ live }: { live: boolean }) => {
        useLiveFeed('/admin/reports/feed?period=month&month=2025-09', 1000, live);

        return null;
    };

    it('فترةٌ مضت لا تُستطلع — فلا يقلبها تحديثٌ إلى الشهر الجاري', () => {
        vi.useFakeTimers();
        const fetcher = vi.fn(() => Promise.resolve(new Response('{}')));
        vi.stubGlobal('fetch', fetcher);

        try {
            render(<Probe live={false} />);
            vi.advanceTimersByTime(5000);
            expect(fetcher).not.toHaveBeenCalled();

            cleanup();
            render(<Probe live />);
            vi.advanceTimersByTime(1500);
            expect(fetcher).toHaveBeenCalledTimes(1);
        } finally {
            vi.useRealTimers();
            vi.unstubAllGlobals();
        }
    });
});
