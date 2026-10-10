import { cleanup, render, screen, within } from '@testing-library/react';
import { route as ziggy } from 'ziggy-js';
import { afterAll, beforeAll, describe, expect, it, vi } from 'vitest';

import { pageProps } from './setup';

vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

const { default: Summary } = await import('@/Pages/Admin/Finance/Summary');

/**
 * «عرض التفاصيل» في «ما جرى في المدة» يفتح تقرير صافي الربح بالفترة نفسِها.
 *
 * و`route()` هنا Ziggy الحقيقيّة لا بديلُ `setup.ts`: ذاك يُلصق المعاملات
 * مسارًا، وما يُحرس هنا هو الرابطُ كما يخرج — مسارُه واستعلامُه.
 */
const ZIGGY = {
    url: 'https://abaad.test',
    port: null,
    defaults: {},
    routes: {
        'admin.reports.profit': { uri: 'admin/reports/profit', methods: ['GET', 'HEAD'] },
    },
};

const realRoute = globalThis.route;

beforeAll(() => {
    // والتقريرُ وحده من Ziggy — وتبويباتُ القسم من بديل `setup.ts` كما في غيره
    globalThis.route = ((name?: string, params?: Record<string, unknown>) =>
        name !== undefined && name in ZIGGY.routes
            ? ziggy(name, params as never, false, ZIGGY as never)
            : (realRoute as (...a: unknown[]) => unknown)(name, params)) as never;
});

afterAll(() => {
    globalThis.route = realRoute;
});

const PERIOD = { sales: 1000, tax: 50, cogs: 600, gross_profit: 350, expenses: 200, profit: 150, in: 0, out: 0, transfers: 0 };

const draw = (range: string, abilities: string[]) => {
    cleanup();
    for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    Object.assign(pageProps, {
        translations: {},
        auth: { abilities, mayActions: [] },
        context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 } },
        range,
        cash: 100,
        bank: 200,
        accounts: [],
        period: PERIOD,
        settlements: { collections: { amount: 0, count: 0 }, supplier_payments: { amount: 0, count: 0 } },
        dues: {
            invoices: 0, expenses: 0, payroll: 0, total: 0,
            overdue: 0, overdue_count: 0, overdue_amount: 0, due_soon_amount: 0,
        },
        pending_invoices: { count: 0, total: 0 },
        receivables: {
            total: 0, uninvoiced: 0, invoiced: 0, net: 0,
            overdue: 0, due_soon: 0, credit: 0, invoices: 0, customers: 0,
        },
    });

    return render(<Summary />);
};

/** الرابطُ مفكّكًا: مسارُه ومعاملاتُه */
const destination = () => {
    const url = new URL(screen.getByRole('link', { name: 'عرض التفاصيل' }).getAttribute('href') ?? '', 'https://abaad.test');

    return { path: url.pathname, params: Object.fromEntries(url.searchParams) };
};

describe('«عرض التفاصيل» يحمل الفترة إلى تقرير صافي الربح', () => {
    it.each(['today', 'week', 'month', 'year', 'all'])('الفترة %s تنتقل حرفيًّا — ولا شيء غيرها', (range) => {
        draw(range, ['finance', 'reports']);

        // والفترةُ وحدها: الملخّصُ للنشاط كلِّه، فلا `branch_id`
        expect(destination()).toEqual({ path: '/admin/reports/profit', params: { range } });
    });

    it('رابطٌ حقيقيّ يُبلغ بلوحة المفاتيح — لا عنوانٌ يُضغط', () => {
        draw('month', ['finance', 'reports']);

        const link = screen.getByTestId('period-details');
        expect(link.tagName).toBe('A');
        expect(link).toHaveAccessibleName('عرض التفاصيل');
        // والعنوانُ يبقى عنوانًا
        expect(screen.getByRole('heading', { name: 'ما جرى في المدة' }).closest('a')).toBeNull();
    });

    it('والسهمُ يتبع اتّجاه الواجهة، ولا يُقرأ', () => {
        draw('month', ['finance', 'reports']);

        const arrow = screen.getByTestId('period-details').querySelector('svg');
        expect(arrow).toHaveClass('rtl:rotate-180');
        expect(arrow).toHaveAttribute('aria-hidden', 'true');
    });
});

describe('ولا زرَّ يقود إلى 403', () => {
    it('من يملك «المالية» وحدها لا يرى الزرّ', () => {
        draw('year', ['finance']);

        expect(screen.queryByTestId('period-details')).toBeNull();
        expect(screen.queryByText('عرض التفاصيل')).toBeNull();
        // والملخّصُ نفسُه كما هو
        expect(screen.getByRole('heading', { name: 'ما جرى في المدة' })).toBeInTheDocument();
    });

    it('ومن يملك «التقارير» يراه', () => {
        draw('year', ['finance', 'reports']);

        expect(screen.getByTestId('period-details')).toBeInTheDocument();
    });
});

describe('والبطاقاتُ الستّ كما كانت', () => {
    it('أرقامُها من `period` نفسِه، ولا واحدةَ منها رابط', () => {
        draw('month', ['finance', 'reports']);

        const cards = screen.getByTestId('period-result');
        for (const [label, value] of [
            ['المبيعات', '1,000'], ['ضريبة المبيعات', '50'], ['تكلفة البضاعة المباعة', '600'],
            ['مجمل الربح', '350'], ['المصروفات التشغيلية', '200'], ['صافي الربح', '150'],
        ]) {
            expect(within(cards).getByText(label).parentElement).toHaveTextContent(value);
        }
        expect(within(cards).queryAllByRole('link')).toHaveLength(0);
    });
});
