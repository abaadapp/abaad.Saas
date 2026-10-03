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
        dues: {
            invoices: 250, expenses: 120, payroll: 80, total: 450,
            overdue: 2, overdue_count: 2, overdue_amount: 175, due_soon_amount: 60,
        },
        pending_invoices: { count: 3, total: 620 },
        receivables: {
            total: 90, uninvoiced: 25, invoiced: 65, net: 90,
            overdue: 40, due_soon: 30, credit: 0, invoices: 2, customers: 2,
        },
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

    it('ومتى: مبلغُ المتأخّر وعددُه، وما يستحقّ خلال سبعة أيام', () => {
        const timing = within(screen.getByTestId('dues-timing'));

        expect(timing.getByText('المتأخر (2)').nextSibling).toHaveTextContent('175');
        expect(timing.getByText('يستحق خلال 7 أيام').nextSibling).toHaveTextContent('60');
    });

    it('ولا يُذكر المعلَّقُ حين لا يوجد', () => {
        draw({ pending_invoices: { count: 0, total: 0 } });

        expect(screen.queryByTestId('pending-invoices')).toBeNull();
    });
});

describe('«لك الآن» تفصّل إجماليّها', () => {
    const receivables = (over: Record<string, number> = {}) => ({
        total: 90, uninvoiced: 25, invoiced: 65, net: 90,
        overdue: 40, due_soon: 30, credit: 0, invoices: 7, customers: 4,
        ...over,
    });

    it('فواتير العملاء منفصلةً عن البيعات الآجلة غير المفوترة', () => {
        draw({ receivables: receivables() });
        const r = within(screen.getByTestId('receivables-breakdown'));

        expect(r.getByText('فواتير العملاء').nextSibling).toHaveTextContent('65');
        expect(r.getByText('مبيعات آجلة لم تُفوتر').nextSibling).toHaveTextContent('25');
        expect(screen.getByText('إجمالي ذمم العملاء')).toBeInTheDocument();
        expect(screen.getByTestId('receivables-count')).toHaveTextContent('7 فواتير على 4 عملاء');
    });

    it('المتأخّر ويستحقّ قريبًا', () => {
        draw({ receivables: receivables() });
        const r = within(screen.getByTestId('receivables-timing'));

        expect(r.getByText('المتأخر').nextSibling).toHaveTextContent('40');
        expect(r.getByText('يستحق قريبًا').nextSibling).toHaveTextContent('30');
    });

    it('والرصيدُ الدائن سطرٌ مطروحٌ باسمه، والصافي بعده — لا داخل الإجماليّ', () => {
        draw({ receivables: receivables({ credit: 15, net: 75 }) });
        const c = within(screen.getByTestId('receivables-credit'));

        expect(c.getByText('رصيد دائن للعملاء').nextSibling).toHaveTextContent('15');
        expect(c.getByText('صافي لك').nextSibling).toHaveTextContent('75');
        // والرقمُ الكبير يبقى الإجماليَّ، لا الصافي
        expect(screen.getByText('إجمالي ذمم العملاء').previousSibling).toHaveTextContent('90');
    });

    it('ولا رصيدَ دائنًا ولا «صافي» يُذكران والرصيدُ صفر', () => {
        draw({ receivables: receivables() });

        expect(screen.queryByTestId('receivables-credit')).toBeNull();
        expect(screen.queryByText('صافي لك')).toBeNull();
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
