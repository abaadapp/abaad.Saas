import { render, screen, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { PayStateBadges, PayStateFacts, type PayState } from '@/Components/OrderPaymentState';
import PrepCard from '@/Pages/Admin/Preparation/partials/PrepCard';
import type { PrepOrder } from '@/Pages/Admin/Preparation/partials/types';

/**
 * الطلبُ يقول ما تقوله تحصيلاتُه — الشارةُ وما يُحصَّل من `pay_state`، لا من الحقل.
 *
 * `payment_status` يُكتب يومَ البيع: طلبُ الدفع عند الاستلام يبقى فيه «غير مدفوع»
 * بعد أن قُبضت فاتورتُه. فالطاولةُ لا تطلب مالَ طلبٍ دُفع، وتطلب الباقي لا الإجماليّ.
 */

const pay = (over: Partial<PayState> = {}): PayState => ({
    state: 'مدفوع',
    label: 'مدفوع',
    marker: null,
    paid: 25,
    due: 0,
    credited: 0,
    returned: 0,
    refunded: 0,
    cheque_pending: 0,
    customer_credit: 0,
    invoice: { id: 7, number: 'CINV-7' },
    ...over,
});

const order = (over: Partial<PrepOrder> = {}): PrepOrder => ({
    number: 'WEB-9',
    status: 'جاهز',
    customer: 'مريم',
    customer_phone: '96899110001',
    total: 25,
    payment_status: 'غير مدفوع',
    payment_method: 'نقدي',
    fulfillment: 'pickup',
    scheduled_for: '2027-02-11 10:00',
    scheduled: { date: '2027-02-11', time: '10:00', day: 'today', minutes_left: 120 },
    overdue: false,
    recipient: null,
    recipient_phone: null,
    address: null,
    occasion: null,
    card_message: null,
    sender: null,
    hide_sender: false,
    delivery_notes: null,
    internal_notes: null,
    order_notes: null,
    branch: null,
    items: [{ id: 1, name: 'مزهرية', qty: 1, price: 15, total: 15, note: null, image: null, addons: [] }],
    next: ['تم الاستلام'],
    checks: {},
    ...over,
});

const noop = () => {};
const m = (v: number) => v.toFixed(3);

describe('حالُ السداد على الطاولة', () => {
    it('طلبٌ قُبضت فاتورتُه يُقرأ «مدفوع» ولا يُطلب منه شيء — والحقلُ ما زال «غير مدفوع»', () => {
        render(<PrepCard order={order({ pay_state: pay() })} ageMs={0} onOpen={noop} onMove={noop} />);

        const money = screen.getByTestId('prep-money');
        expect(within(money).getByTestId('pay-state-label')).toHaveTextContent('مدفوع');
        expect(money).not.toHaveTextContent('غير مدفوع');
        expect(within(money).queryByTestId('prep-collect')).toBeNull();
    });

    it('ما قُبض بعضُه: يُحصَّل الباقي لا الإجماليّ', () => {
        render(<PrepCard order={order({ pay_state: pay({ state: 'مدفوع جزئيًا', label: 'مدفوع جزئيًا', paid: 10, due: 15 }) })} ageMs={0} onOpen={noop} onMove={noop} />);

        const money = screen.getByTestId('prep-money');
        expect(within(money).getByTestId('pay-state-label')).toHaveTextContent('مدفوع جزئيًا');
        expect(within(money).getByTestId('prep-collect')).toHaveTextContent('15.000');
    });

    it('والملغى لا يُطلب منه مال', () => {
        render(<PrepCard order={order({ pay_state: pay({ state: 'ملغى', label: 'ملغى', paid: 0, due: 0, invoice: null }) })} ageMs={0} onOpen={noop} onMove={noop} />);

        expect(screen.getByTestId('pay-state-label')).toHaveTextContent('ملغى');
        expect(screen.queryByTestId('prep-collect')).toBeNull();
    });

    it('وبطاقةٌ بلا `pay_state` تبقى كما كانت', () => {
        render(<PrepCard order={order()} ageMs={0} onOpen={noop} onMove={noop} />);

        expect(screen.getByTestId('prep-money')).toHaveTextContent('غير مدفوع');
        expect(screen.queryByTestId('pay-state')).toBeNull();
    });
});

describe('الشارةُ وما تحتها', () => {
    it('ما رُدّ بعضُه علامةٌ بجانب الحال، وما رُدّ كلُّه اسمُه', () => {
        const { rerender } = render(<PayStateBadges pay={pay({ marker: 'مرتجع جزئيًا', returned: 10, paid: 13 })} />);
        expect(screen.getByTestId('pay-state-label')).toHaveTextContent('مدفوع');
        expect(screen.getByTestId('pay-state-marker')).toHaveTextContent('مرتجع جزئيًا');

        rerender(<PayStateBadges pay={pay({ label: 'مرتجع', returned: 25, paid: 0 })} />);
        expect(screen.getByTestId('pay-state-label')).toHaveTextContent('مرتجع');
        expect(screen.queryByTestId('pay-state-marker')).toBeNull();
    });

    it('المقبوضُ والباقي والشيكُ تحت التحصيل والفاتورةُ التي يُحصَّل عليها', () => {
        render(<PayStateFacts pay={pay({ state: 'مدفوع جزئيًا', label: 'مدفوع جزئيًا', paid: 10, due: 15, cheque_pending: 10 })} m={m} />);

        expect(screen.getByTestId('pay-state-paid')).toHaveTextContent('10.000');
        expect(screen.getByTestId('pay-state-due')).toHaveTextContent('15.000');
        expect(screen.getByTestId('pay-state-cheque')).toHaveTextContent('10.000');
        expect(screen.getByTestId('pay-state-invoice')).toHaveTextContent('CINV-7');
    });

    it('طلبٌ ملغى قُبضت فاتورتُه: المالُ رصيدٌ للعميل يُقال', () => {
        render(<PayStateFacts pay={pay({ state: 'ملغى', label: 'ملغى', paid: 0, due: 0, customer_credit: 25, invoice: null })} m={m} />);

        expect(screen.getByTestId('pay-state-credit')).toHaveTextContent('25.000');
        expect(screen.queryByTestId('pay-state-paid')).toBeNull();
    });
});
