import { render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it } from 'vitest';

import { pageProps } from './setup';

const { default: PaymentLinksCard } = await import('@/Components/PaymentLinks');

type Row = import('@/Components/PaymentLinks').PaymentLinkRow;

/**
 * رابطُ تجربةٍ دُفع — يُرى «تم الدفع» بوسم «تجربة»، ويُقال إنّه لم يُسجَّل
 * تحصيلًا ولا قيدًا. والرابطُ الحيُّ المدفوع يقود إلى تحصيله ولا يُقال عنه ذلك.
 */

const row = (over: Partial<Row> = {}): Row => ({
    id: 1, reference: 'pay-abc', customer: { id: 7, name: 'وزارة الثقافة' }, invoice: { id: 3, number: 'INV-000003' },
    description: null, amount: 105, currency: 'OMR', status: 'paid', is_live: false, url: 'https://paymob.link/abc123',
    created_at: '2026-10-25 10:00', expires_at: '2026-11-01 23:59', paid_at: '2026-10-25 10:05', payment: null, error: null, review: false,
    ...over,
});

const panel = (links: Row[]) => ({ ready: true, can: true, can_cancel: true, settings_url: null, links });

beforeEach(() => {
    for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    Object.assign(pageProps, {
        translations: {}, context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 } },
        auth: { abilities: ['finance'], mayActions: [] }, flash: { toast: null, status: null },
    });
});

describe('دفعةُ تجربة على رابط', () => {
    it('تُرى مدفوعةً بوسم «تجربة» — ويُقال إنّها لم تُسجَّل تحصيلًا ولا قيدًا', () => {
        render(<PaymentLinksCard panel={panel([row()])} customerId={7} invoiceId={3} outstanding={105} title="روابط الدفع" action="إنشاء رابط دفع" />);

        const r = screen.getByTestId('payment-link-row');
        expect(within(r).getByText('تجربة')).toBeInTheDocument();
        expect(within(r).getByTestId('payment-link-test-paid')).toHaveTextContent('دفعة تجربة — لم تُسجَّل تحصيلًا ولا قيدًا');
        expect(within(r).queryByText(/التحصيل/)).toBeNull();
    });

    it('والحيُّ المدفوع يقود إلى تحصيله — بلا جملة التجربة', () => {
        render(<PaymentLinksCard panel={panel([row({ is_live: true, payment: { id: 9, number: 'RCPT-000009' } })])} customerId={7} invoiceId={3} outstanding={0} title="روابط الدفع" action="إنشاء رابط دفع" />);

        const r = screen.getByTestId('payment-link-row');
        expect(within(r).getByText(/RCPT-000009/)).toBeInTheDocument();
        expect(within(r).queryByTestId('payment-link-test-paid')).toBeNull();
        expect(within(r).queryByText('تجربة')).toBeNull();
    });

    it('ورابطُ تجربةٍ ينتظر الدفع لا يُقال عنه شيءٌ بعد', () => {
        render(<PaymentLinksCard panel={panel([row({ status: 'pending', paid_at: null })])} customerId={7} invoiceId={3} outstanding={105} title="روابط الدفع" action="إنشاء رابط دفع" />);

        expect(screen.queryByTestId('payment-link-test-paid')).toBeNull();
    });
});
