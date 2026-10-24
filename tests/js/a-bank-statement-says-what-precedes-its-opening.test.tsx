import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { pageProps } from './setup';

vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

const { BeforeOpeningNotice } = await import('@/Pages/Admin/Finance/Statement');

/**
 * كشفُ البنك يقول ما سبق رصيدَه الافتتاحيّ — ولا يخفيه.
 *
 * الكشفُ يُقرأ من الدفتر بعد تاريخ الرصيد الافتتاحيّ، فما قبله لا يُعدّ.
 * ومتجرٌ أدخل رصيدَه الافتتاحيّ بعد حركاتٍ سبقته (كحال RIBBON: ٦٠٠ في ١٢
 * أكتوبر وحركاتٌ منذ سبتمبر) كان يقرأ رصيدًا غيرَ رصيد دفتره بلا سبب. فيُقال
 * العددُ والصافي ورصيدُ الدفتر.
 */
const statement = (over: Record<string, unknown> = {}) => ({
    opening: 600,
    rows: [],
    closing: 2434.68,
    before_opening: { count: 12, net: -563.232 },
    ledger_balance: 1871.448,
    ...over,
});

beforeEach(() => {
    Object.assign(pageProps, { translations: {} });
});

describe('ما سبق الرصيدَ الافتتاحيّ', () => {
    it('يُقال عددُه وصافيه ورصيدُ الدفتر', () => {
        render(<BeforeOpeningNotice statement={statement()} format={(v) => v.toFixed(3)} />);

        const note = screen.getByTestId('before-opening-notice');
        expect(note).toHaveTextContent('12 حركة');
        expect(note).toHaveTextContent('-563.232');
        expect(note).toHaveTextContent('1871.448');
    });

    it('ولا يُقال شيءٌ حين لا شيءَ قبله', () => {
        render(
            <BeforeOpeningNotice
                statement={statement({ before_opening: { count: 0, net: 0 }, ledger_balance: 2434.68 })}
                format={(v) => v.toFixed(3)}
            />,
        );

        expect(screen.queryByTestId('before-opening-notice')).not.toBeInTheDocument();
    });

    /** وحسابٌ رصيدُه الافتتاحيّ غيرُ مقيَّدٍ في الدفتر يختم كشفُه بغير رصيده — فيُقال الرقمان */
    it('ويُقال الرقمان حين يختم الكشف بغير رصيد الدفتر ولا شيء قبله', () => {
        render(
            <BeforeOpeningNotice
                statement={statement({ before_opening: { count: 0, net: 0 }, closing: 12500, ledger_balance: 0 })}
                format={(v) => v.toFixed(3)}
            />,
        );

        const note = screen.getByTestId('before-opening-notice');
        expect(note).toHaveTextContent('12500.000');
        expect(note).toHaveTextContent('غير مقيَّد');
    });
});
