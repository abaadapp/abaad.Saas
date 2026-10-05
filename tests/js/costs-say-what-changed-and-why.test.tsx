import { render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import StatCard, { trendClass } from '@/Components/StatCard';
import { pageProps } from './setup';

vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

const { default: ReportsCosts, biggestDriver, costTrend } = await import('@/Pages/Admin/Reports/Costs');

/**
 * التكاليف والخسائر تقول ما تغيّر ولماذا — بكلامٍ لا بنسبةٍ عارية.
 *
 * ═══ ما يُحرس ═══
 *
 *   · تكلفةٌ زادت: سهمٌ صاعدٌ ومعنًى سيّئ و«زادت X% عن الفترة السابقة».
 *     ونقصت: نازلٌ وحسنٌ و«انخفضت». وتساوت: محايدٌ «لم تتغير».
 *   · ولا سابقَ موجب: «جديد في هذه الفترة» — لا نسبةٌ مخترعة ولا Infinity.
 *   · وأكبرُ سببٍ للتغيّر بالمبلغ لا بالنسبة، وفي جهة الإجماليّ، والحسابُ
 *     قبل الفئة.
 *   · وبطاقةُ «تكاليف التشغيل» تقول ما تجمعه.
 *   · ومن يستعمل `StatCard` بلا `tone` يبقى كما كان.
 *
 * والأرقامُ كلُّها من الخادم — `CostsAndLossesAreReadFromTheLedgerTest`.
 */
const currency = { code: 'OMR', symbol: 'ر.ع', decimals: 3 };
const ident = (s: string, r?: Record<string, string>) =>
    Object.entries(r ?? {}).reduce((out, [k, v]) => out.replace(`:${k}`, v), s);

const row = (account_id: number, account: string, current: number, previous: number, change_pct: number | null) => ({
    category: 'operating', account_id, code: String(account_id), account, current, previous,
    delta: current - previous, change_pct, share: null,
});

const category = (key: string, label: string, rows: ReturnType<typeof row>[]) => {
    const current = rows.reduce((s, r) => s + r.current, 0);
    const previous = rows.reduce((s, r) => s + r.previous, 0);

    return { key, label, current, previous, delta: current - previous, change_pct: null, share: null, rows };
};

const draw = (opts: {
    total: [number, number, number | null];
    operating?: [number, number, number | null];
    categories?: ReturnType<typeof category>[];
    translations?: Record<string, string>;
}) => {
    const [cur, prev, pct] = opts.total;
    const [ocur, oprev, opct] = opts.operating ?? [0, 0, null];

    for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    Object.assign(pageProps, {
        translations: opts.translations ?? {},
        context: { currency },
        auth: { abilities: ['reports', 'finance'], mayActions: [], features: ['reports_advanced'] },
        summary: { total: cur, cost_of_sales: 0, operating: ocur, losses: 0, other: 0 },
        previousSummary: { total: prev, cost_of_sales: 0, operating: oprev, losses: 0, other: 0 },
        comparison: [
            { key: 'total', current: cur, previous: prev, delta: cur - prev, change_pct: pct },
            { key: 'operating', current: ocur, previous: oprev, delta: ocur - oprev, change_pct: opct },
        ],
        categories: opts.categories ?? [],
        scope: {
            from: '2026-09-01', to: '2026-09-30', branch_id: null, category: null, name: 'النشاط بالكامل',
            restricted: false, previous: { from: '2026-08-02', to: '2026-08-31' },
        },
        reconciliation: { unposted_count: 0, unposted_amount: 0, unpaid_count: 0, unpaid_amount: 0 },
        filters: { from: '2026-09-01', to: '2026-09-30', branch_id: null, category: null },
        options: { branches: [], categories: [] },
    });

    return render(<ReportsCosts />);
};

const totalTrend = () => within(screen.getByTestId('costs-cards')).getAllByTestId('stat-trend')[0];

describe('اتجاهُ التكلفة', () => {
    it('١ · زادت: سهمٌ صاعد، ومعنًى سيّئ، و«زادت X%»', () => {
        draw({ total: [118.4, 100, 18.4] });

        const trend = totalTrend();
        expect(trend).toHaveTextContent('زادت 18.4% عن الفترة السابقة');
        expect(trend).toHaveAttribute('data-tone', 'bad');
        expect(trend.className).toContain('text-[#b91c1c]');
        expect(trend.querySelector('.lucide-arrow-up-right')).not.toBeNull();
    });

    it('٢ · انخفضت: سهمٌ نازل، ومعنًى حسن، و«انخفضت X%»', () => {
        draw({ total: [91.8, 100, -8.2] });

        const trend = totalTrend();
        expect(trend).toHaveTextContent('انخفضت 8.2% عن الفترة السابقة');
        expect(trend).toHaveAttribute('data-tone', 'good');
        expect(trend.className).toContain('text-[#047857]');
        expect(trend.querySelector('.lucide-arrow-down-right')).not.toBeNull();
    });

    it('٣ · تساوت: محايدٌ بلا سهم', () => {
        draw({ total: [100, 100, 0] });

        const trend = totalTrend();
        expect(trend).toHaveTextContent('لم تتغير عن الفترة السابقة');
        expect(trend).toHaveAttribute('data-tone', 'neutral');
        expect(trend.querySelector('.lucide-arrow-up-right, .lucide-arrow-down-right')).toBeNull();
    });

    it('٤ · لا سابق: «جديد في هذه الفترة» — بلا نسبةٍ ولا Infinity', () => {
        draw({ total: [50, 0, null] });

        expect(totalTrend()).toHaveTextContent('جديد في هذه الفترة');
        expect(totalTrend().textContent).not.toMatch(/%/);
        expect(document.body.textContent).not.toMatch(/Infinity|NaN/);

        // وما سوى ذلك بلا نسبة يُقال بلا رقم
        expect(costTrend(-5, -10, null, ident)).toMatchObject({ trend: 'لا مقارنة مع الفترة السابقة', tone: 'neutral' });
        expect(costTrend(0, 0, null, ident)).toMatchObject({ tone: 'neutral' });
    });
});

describe('٥ · ومن يستعمل البطاقة بلا معنًى يبقى كما كان', () => {
    it('الصاعدُ أخضر والنازلُ أحمر — بلا `tone`', () => {
        expect(trendClass({ up: true })).toBe('text-[#047857]');
        expect(trendClass({ up: false })).toBe('text-[#b91c1c]');

        pageProps.translations = {};
        const { unmount } = render(<StatCard stat={{ label: 'المبيعات', value: '10', icon: 'wallet', color: 'success', trend: '+5%', up: true }} />);
        const trend = screen.getByTestId('stat-trend');
        expect(trend.className).toContain('text-[#047857]');
        expect(trend.querySelector('.lucide-arrow-up-right')).not.toBeNull();
        expect(screen.queryByTestId('stat-hint')).toBeNull();
        unmount();

        render(<StatCard stat={{ label: 'المرتجعات', value: '3', icon: 'wallet', color: 'danger', trend: '-2%', up: false }} />);
        expect(screen.getByTestId('stat-trend').className).toContain('text-[#b91c1c]');
        expect(screen.getByTestId('stat-trend').querySelector('.lucide-arrow-down-right')).not.toBeNull();
    });
});

describe('أكبرُ سببٍ للتغيّر', () => {
    const marketing = row(61, 'التسويق', 185, 100, 85);
    const rent = row(62, 'الإيجار', 300, 320, -6.3);
    const cogs = row(51, 'تكلفة البضاعة المباعة', 380, 500, -24);

    it('٦ · زاد الإجماليّ: أكبرُ فرقٍ موجب — بالحساب', () => {
        const cats = [category('operating', 'مصروفات التشغيل', [marketing, rent])];
        draw({ total: [485, 420, 15.5], categories: cats });

        expect(screen.getByTestId('costs-driver')).toHaveTextContent('أكبر سبب للزيادة');
        expect(screen.getByTestId('costs-driver-name')).toHaveTextContent('التسويق');
        expect(screen.getByTestId('costs-driver-delta')).toHaveTextContent('+85.000');
        expect(screen.getByTestId('costs-driver-delta')).toHaveAttribute('data-tone', 'bad');
    });

    it('٧ · نقص الإجماليّ: أكبرُ فرقٍ سالبٍ بقيمته', () => {
        const cats = [
            category('cost_of_sales', 'تكلفة المبيعات', [cogs]),
            category('operating', 'مصروفات التشغيل', [marketing, rent]),
        ];
        draw({ total: [865, 920, -6], categories: cats });

        expect(screen.getByTestId('costs-driver')).toHaveTextContent('أكبر سبب للانخفاض');
        expect(screen.getByTestId('costs-driver-name')).toHaveTextContent('تكلفة البضاعة المباعة');
        expect(screen.getByTestId('costs-driver-delta')).toHaveTextContent('-120.000');
        expect(screen.getByTestId('costs-driver-delta')).toHaveAttribute('data-tone', 'good');
    });

    it('٨ · بالمبلغ لا بالنسبة: ٥ ← ٣٠ (+500%) لا يعلو ٥٠٠ ← ٦٥٠', () => {
        const tiny = row(70, 'القرطاسية', 30, 5, 500);
        const big = row(71, 'الرواتب', 650, 500, 30);
        const cats = [category('operating', 'مصروفات التشغيل', [tiny, big])];

        expect(biggestDriver(cats, 680, 505)).toMatchObject({ name: 'الرواتب', delta: 150, up: true });
    });

    it('وحسابٌ سار عكسَ الإجماليّ لا يُسمّى سببَه — ولو كان أكبرَ فرقًا', () => {
        // نقص الإيجارُ ٢٠٠ وزاد التسويقُ ١٥٠ والكهرباءُ ٨٠: الإجماليُّ زاد ٣٠
        const cats = [category('operating', 'مصروفات التشغيل', [
            row(62, 'الإيجار', 100, 300, -66.7),
            row(61, 'التسويق', 250, 100, 150),
            row(63, 'الكهرباء', 180, 100, 80),
        ])];

        expect(biggestDriver(cats, 530, 500)).toMatchObject({ name: 'التسويق', delta: 150, up: true });
    });

    it('والفئةُ حين لا حسابَ في الجهة نفسِها', () => {
        const cats = [{ ...category('other', 'مصروفات وخسائر أخرى', []), delta: 40 }];

        expect(biggestDriver(cats, 140, 100)).toMatchObject({ name: 'مصروفات وخسائر أخرى', category: null });
    });

    it('٩ · لا تغيّرَ في الإجماليّ: لا سببَ يُسمّى', () => {
        const flat = row(80, 'الإيجار', 300, 300, 0);
        const cats = [category('operating', 'مصروفات التشغيل', [flat])];
        const first = draw({ total: [300, 300, 0], categories: cats });

        expect(biggestDriver(cats, 300, 300)).toBeNull();
        expect(screen.getByTestId('costs-driver')).toHaveTextContent('لا يوجد تغير ملحوظ عن الفترة السابقة');
        expect(screen.queryByTestId('costs-driver-name')).toBeNull();
        first.unmount();

        // وتقريرٌ فارغٌ لا سطرَ فيه أصلًا
        draw({ total: [0, 0, null] });
        expect(screen.queryByTestId('costs-driver')).toBeNull();
    });
});

describe('١٠ · بطاقةُ «تكاليف التشغيل»', () => {
    it('اسمُها يسع ما تجمعه، وسطرُها يقوله', () => {
        draw({ total: [100, 100, 0], operating: [60, 50, 20] });

        const cards = screen.getByTestId('costs-cards');
        expect(cards).toHaveTextContent('تكاليف التشغيل');
        expect(within(cards).getByTestId('stat-hint')).toHaveTextContent('تشمل الموظفين والمصروفات التشغيلية والإهلاك');
        // واسمُ الفئة الضيّقة لا يبقى عنوانًا للبطاقة
        expect(within(cards).queryByText('مصروفات التشغيل')).toBeNull();
        expect(screen.getByTestId('costs-comparison')).toHaveTextContent('تكاليف التشغيل');
    });
});

describe('١١ · ١٢ · بالعربيّة وبالإنجليزيّة', () => {
    it('العربيّة', () => {
        expect(costTrend(118.4, 100, 18.4, ident).trend).toBe('زادت 18.4% عن الفترة السابقة');
        expect(costTrend(91.8, 100, -8.2, ident).trend).toBe('انخفضت 8.2% عن الفترة السابقة');
    });

    it('والإنجليزيّة من القاموس نفسِه', () => {
        const en: Record<string, string> = {
            'زادت :pct عن الفترة السابقة': 'Increased :pct from the previous period',
            'أكبر سبب للزيادة': 'Biggest reason for the increase',
            'تكاليف التشغيل': 'Operating costs',
            'تشمل الموظفين والمصروفات التشغيلية والإهلاك': 'Includes employee costs, operating expenses and depreciation',
        };
        const cats = [category('operating', 'Operating expenses', [row(61, 'Marketing', 185, 100, 85)])];
        draw({ total: [185, 100, 85], operating: [185, 100, 85], categories: cats, translations: en });

        expect(totalTrend()).toHaveTextContent('Increased 85.0% from the previous period');
        expect(screen.getByTestId('costs-driver')).toHaveTextContent('Biggest reason for the increase');
        expect(screen.getByTestId('costs-cards')).toHaveTextContent('Operating costs');
        expect(screen.getByTestId('costs-cards')).toHaveTextContent('Includes employee costs, operating expenses and depreciation');
    });
});
