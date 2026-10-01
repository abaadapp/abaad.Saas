import { cleanup, render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { pageProps } from './setup';

vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

const { default: Summary } = await import('@/Pages/Admin/Finance/Summary');

/**
 * الملخّصُ المالي يُري أجزاءَ رقميه — «عليك الآن» ونتيجةَ المدة.
 *
 * وما يحسبه الخادمُ منها حارسُه `TheFinanceSummaryShowsWhatMakesItsNumbersTest`.
 */

const draw = (over: Record<string, unknown> = {}) => {
    cleanup();
    for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    Object.assign(pageProps, {
        translations: {},
        auth: { abilities: ['finance'], mayActions: [] },
        context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 } },
        range: 'month',
        cash: 100,
        bank: 200,
        accounts: [],
        period: { sales: 1000, tax: 50, cogs: 600, gross_profit: 350, expenses: 200, profit: 150, in: 0, out: 0, transfers: 0 },
        dues: { invoices: 250, expenses: 120, payroll: 80, total: 450, overdue: 0 },
        pending_invoices: { count: 3, total: 620 },
        receivables: { total: 90, overdue: 40, due_soon: 30, credit: 0, invoices: 2, customers: 2 },
        ...over,
    });

    return render(<Summary />);
};

describe('«عليك الآن» تفصّل إجماليّها', () => {
    beforeEach(() => draw());

    it('الفواتير والمصروفات والرواتب — كلٌّ بمبلغه', () => {
        const dues = within(screen.getByTestId('dues-breakdown'));

        expect(dues.getByText('فواتير الموردين').nextSibling).toHaveTextContent('250');
        expect(dues.getByText('مصروفات غير مدفوعة').nextSibling).toHaveTextContent('120');
        expect(dues.getByText('رواتب مستحقة').nextSibling).toHaveTextContent('80');
    });

    it('والمعلَّقُ معلومةٌ بجوارها: عددُه ومجموعُه، وأنّه لا يدخل المستحق', () => {
        const pending = screen.getByTestId('pending-invoices');

        expect(pending).toHaveTextContent('فواتير بانتظار الاعتماد: 3 ·');
        expect(pending).toHaveTextContent('620');
        expect(pending).toHaveTextContent('لا تدخل في المستحق حتى تُعتمد');
    });

    it('ولا يُذكر المعلَّقُ حين لا يوجد', () => {
        draw({ pending_invoices: { count: 0, total: 0 } });

        expect(screen.queryByTestId('pending-invoices')).toBeNull();
    });
});

describe('«لك الآن» تفصّل إجماليّها', () => {
    it('المتأخّر ويستحقّ قريبًا — والرصيدُ الدائن حين يكون', () => {
        draw({ receivables: { total: 90, overdue: 40, due_soon: 30, credit: 15, invoices: 2, customers: 2 } });
        const r = within(screen.getByTestId('receivables-breakdown'));

        expect(r.getByText('المتأخر').nextSibling).toHaveTextContent('40');
        expect(r.getByText('يستحق قريبًا').nextSibling).toHaveTextContent('30');
        expect(r.getByText('رصيد دائن للعملاء').nextSibling).toHaveTextContent('15');
    });

    it('ولا رصيدَ دائنًا يُذكر وهو صفر', () => {
        draw();

        expect(within(screen.getByTestId('receivables-breakdown')).queryByText('رصيد دائن للعملاء')).toBeNull();
    });
});

describe('نتيجةُ المدة خطوةً خطوة', () => {
    beforeEach(() => draw());

    it('ستُّ بطاقات بترتيب الطرح', () => {
        const labels = within(screen.getByTestId('period-result'))
            .getAllByText(/./, { selector: 'p' })
            .map((p) => p.textContent);

        const order = ['المبيعات', 'ضريبة المبيعات', 'تكلفة البضاعة المباعة', 'مجمل الربح', 'المصروفات التشغيلية', 'صافي الربح'];
        expect(labels.filter((l) => order.includes(l ?? ''))).toEqual(order);
    });

    it('وتُقرأ التكلفةُ ومجملُ الربح بقيمهما', () => {
        const card = (label: string) => within(screen.getByTestId('period-result')).getByText(label).closest('div')!.parentElement!;

        expect(card('تكلفة البضاعة المباعة')).toHaveTextContent('600');
        expect(card('مجمل الربح')).toHaveTextContent('350');
    });

    it('والتسميتان القديمتان رحلتا', () => {
        expect(screen.queryByText('ضريبة محصّلة')).toBeNull();
        expect(screen.queryByText('المصروفات')).toBeNull();
    });
});
