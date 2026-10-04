import { cleanup, render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { pageProps } from './setup';

vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

const { default: Summary, countKey } = await import('@/Pages/Admin/Finance/Summary');

/**
 * «التحصيل والسداد في المدة» في الملخّص المالي — المبلغان وعددُ العمليات.
 *
 * وما يحسبه الخادمُ منهما (ما يُعدّ وما لا يُعدّ، والفترة، والعزل) حارسُه
 * `TheSummaryCountsMoneyCollectedAndPaidInItsPeriodTest`. وهذا يحرس العرض:
 * العنوانَ مفصولًا، والاسمين، والعملةَ عملةَ المتجر، والصفرَ، وصيغةَ العدد،
 * والإنجليزيّة.
 */

const EN: Record<string, string> = {
    'التحصيل والسداد في المدة': 'Collections and payments during the period',
    'تحصيلات العملاء': 'Customer collections',
    'مدفوعات الموردين': 'Supplier payments',
    'عملية تحصيل واحدة': '1 collection transaction',
    'عمليتا تحصيل': '2 collection transactions',
    ':n عمليات تحصيل': ':n collection transactions',
    ':n عمليةَ تحصيل': ':n collection transactions',
    'عملية سداد واحدة': '1 payment transaction',
    'عمليتا سداد': '2 payment transactions',
    ':n عمليات سداد': ':n payment transactions',
    ':n عمليةَ سداد': ':n payment transactions',
    'مبالغ تم تحصيلها من العملاء أو سدادها للموردين فعليًا خلال المدة. لا تدخل هذه الأرقام مرة أخرى في احتساب صافي الربح.':
        'Amounts actually collected from customers or paid to suppliers during the period. These figures are not counted again when calculating net profit.',
};

const draw = (
    settlements: { collections: { amount: number; count: number }; supplier_payments: { amount: number; count: number } },
    over: Record<string, unknown> = {},
) => {
    cleanup();
    for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    Object.assign(pageProps, {
        translations: {},
        auth: { abilities: ['finance'], mayActions: [] },
        context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 } },
        range: 'month',
        cash: 0,
        bank: 0,
        accounts: [],
        period: { sales: 1000, tax: 50, cogs: 600, gross_profit: 350, expenses: 200, profit: 150, in: 0, out: 0, transfers: 0 },
        settlements,
        dues: { invoices: 0, expenses: 0, payroll: 0, total: 0, overdue: 0, overdue_count: 0, overdue_amount: 0, due_soon_amount: 0 },
        pending_invoices: { count: 0, total: 0 },
        receivables: { total: 0, uninvoiced: 0, invoiced: 0, net: 0, overdue: 0, due_soon: 0, credit: 0, invoices: 0, customers: 0 },
        ...over,
    });

    return render(<Summary />);
};

describe('«التحصيل والسداد في المدة»', () => {
    it('قسمٌ مفصولٌ بعنوانه تحت «حركة المال في المدة» — باسمَيه ومبلغَيه وعدد عملياتهما', () => {
        draw({ collections: { amount: 300, count: 1 }, supplier_payments: { amount: 1250.5, count: 4 } });

        const section = screen.getByTestId('period-settlements');
        expect(within(section).getByRole('heading', { name: 'التحصيل والسداد في المدة' })).toBeTruthy();

        const collections = within(screen.getByTestId('settlement-collections'));
        expect(collections.getByText('تحصيلات العملاء')).toBeTruthy();
        expect(screen.getByTestId('settlement-collections')).toHaveTextContent('300.000');
        expect(collections.getByText('عملية تحصيل واحدة')).toBeTruthy();

        const payments = within(screen.getByTestId('settlement-supplier-payments'));
        expect(payments.getByText('مدفوعات الموردين')).toBeTruthy();
        expect(screen.getByTestId('settlement-supplier-payments')).toHaveTextContent('1,250.500');
        expect(payments.getByText('4 عمليات سداد')).toBeTruthy();

        expect(section).toHaveTextContent('لا تدخل هذه الأرقام مرة أخرى في احتساب صافي الربح.');

        // وبعد «حركة المال» لا داخلها — ونتيجةُ المدة فوقهما كما هي
        const money = screen.getByText('حركة المال في المدة');
        expect(money.compareDocumentPosition(section) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
        expect(within(screen.getByTestId('period-result')).queryByText('تحصيلات العملاء')).toBeNull();
    });

    it('والصفرُ صفرٌ يُكتب — لا فراغ', () => {
        draw({ collections: { amount: 0, count: 0 }, supplier_payments: { amount: 0, count: 0 } });

        expect(screen.getByTestId('settlement-collections')).toHaveTextContent('0.000');
        expect(screen.getByTestId('settlement-collections')).toHaveTextContent('0 عمليات تحصيل');
        expect(screen.getByTestId('settlement-supplier-payments')).toHaveTextContent('0.000');
    });

    it('والعملةُ عملةُ المتجر — لا ريالٌ مكتوب', () => {
        draw(
            { collections: { amount: 12.5, count: 2 }, supplier_payments: { amount: 7, count: 11 } },
            { context: { currency: { code: 'AED', symbol: 'د.إ', decimals: 2 } } },
        );

        expect(screen.getByTestId('settlement-collections')).toHaveTextContent('12.50');
        expect(screen.getByTestId('settlement-collections')).toHaveTextContent('د.إ');
        expect(screen.getByTestId('settlement-collections')).not.toHaveTextContent('ر.ع');
        expect(screen.getByTestId('settlement-collections')).toHaveTextContent('عمليتا تحصيل');
        expect(screen.getByTestId('settlement-supplier-payments')).toHaveTextContent('11 عمليةَ سداد');
    });

    it('وبالإنجليزيّة كلُّه — العنوانُ والاسمان والعددُ والسطر', () => {
        draw({ collections: { amount: 300, count: 1 }, supplier_payments: { amount: 50, count: 3 } }, { translations: EN });

        const section = screen.getByTestId('period-settlements');
        expect(section).toHaveTextContent('Collections and payments during the period');
        expect(section).toHaveTextContent('Customer collections');
        expect(section).toHaveTextContent('Supplier payments');
        expect(section).toHaveTextContent('1 collection transaction');
        expect(section).toHaveTextContent('3 payment transactions');
        expect(section).toHaveTextContent('These figures are not counted again when calculating net profit.');
        expect(section.textContent).not.toMatch(/[؀-ۿ]{3,}/);
    });
});

describe('صيغةُ العدد', () => {
    const F = { one: 'one', two: 'two', few: 'few', many: 'many' };

    it('واحدٌ واثنان صيغتان، ومن ثلاثةٍ إلى عشرةٍ جمع، وما فوقها مفرد — والصفرُ جمع', () => {
        expect([0, 1, 2, 3, 10, 11, 99, 100, 103, 110, 111].map((n) => countKey(n, F))).toEqual([
            'few', 'one', 'two', 'few', 'few', 'many', 'many', 'many', 'few', 'few', 'many',
        ]);
    });
});
