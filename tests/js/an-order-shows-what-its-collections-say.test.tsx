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

    it('فاتورةٌ تجمعه بغيره ونصيبُه تقدير: لا «يُحصَّل» عنه — يُقال الباقي على الفاتورة', () => {
        const shared = pay({
            state: 'مدفوع جزئيًا', label: 'مدفوع جزئيًا', estimated: true, paid: null, received: null, due: null, credited: null, returned: null, cheque_pending: null,
            invoice: { id: 7, number: 'CINV-7', orders: 2, total: 50, paid: 30, received: 30, cheque_pending: 0, credited: 0, due: 20 },
        });
        render(<PrepCard order={order({ pay_state: shared })} ageMs={0} onOpen={noop} onMove={noop} />);

        const money = screen.getByTestId('prep-money');
        expect(within(money).queryByTestId('prep-collect')).toBeNull();
        expect(within(money).getByTestId('prep-collect-invoice')).toHaveTextContent('CINV-7');
        expect(within(money).getByTestId('prep-collect-invoice')).toHaveTextContent('20.000');
    });

    it('وما سُدّ بشيكٍ لم يُصرف لا يُطلب من المندوب', () => {
        render(<PrepCard order={order({ pay_state: pay({ label: 'مسدَّد بشيك تحت التحصيل', paid: 25, received: 0, cheque_pending: 25 }) })} ageMs={0} onOpen={noop} onMove={noop} />);

        expect(screen.getByTestId('pay-state-label')).toHaveTextContent('مسدَّد بشيك تحت التحصيل');
        expect(screen.queryByTestId('prep-collect')).toBeNull();
        expect(screen.queryByTestId('prep-collect-invoice')).toBeNull();
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

    it('المقبوضُ فعلًا والشيكُ تحت التحصيل سطران — والباقي والفاتورةُ التي يُحصَّل عليها', () => {
        render(<PayStateFacts pay={pay({ state: 'مدفوع جزئيًا', label: 'مدفوع جزئيًا', paid: 20, received: 10, due: 5, cheque_pending: 10 })} m={m} />);

        // ما وصل وحده «مقبوض فعلًا» — والورقةُ التي لم تُصرف سطرٌ باسمها لا تُجمع معه
        expect(screen.getByTestId('pay-state-paid')).toHaveTextContent('10.000');
        expect(screen.getByTestId('pay-state-paid')).toHaveTextContent('المقبوض فعلًا');
        expect(screen.getByTestId('pay-state-cheque')).toHaveTextContent('10.000');
        expect(screen.getByTestId('pay-state-cheque')).toHaveTextContent('لم يُصرف');
        expect(screen.getByTestId('pay-state-due')).toHaveTextContent('5.000');
        expect(screen.getByTestId('pay-state-invoice')).toHaveTextContent('CINV-7');
    });

    it('ما سُدّ كلُّه بشيكٍ لم يُصرف: لا «مدفوع» ولا مقبوضٌ فعلًا', () => {
        const p = pay({ label: 'مسدَّد بشيك تحت التحصيل', paid: 25, received: 0, cheque_pending: 25 });
        const { container } = render(
            <>
                <PayStateBadges pay={p} />
                <PayStateFacts pay={p} m={m} />
            </>,
        );

        expect(screen.getByTestId('pay-state-label')).toHaveTextContent('مسدَّد بشيك تحت التحصيل');
        expect(screen.queryByTestId('pay-state-paid')).toBeNull();
        expect(screen.getByTestId('pay-state-cheque')).toHaveTextContent('25.000');
        expect(container).not.toHaveTextContent(/^مدفوع$/);
    });

    it('وما سُدّ بعضُه بشيك: علامةُ الشيك بجانب «مدفوع جزئيًا»', () => {
        render(<PayStateBadges pay={pay({ state: 'مدفوع جزئيًا', label: 'مدفوع جزئيًا', cheque_marker: 'شيك تحت التحصيل', paid: 10, received: 0, due: 15, cheque_pending: 10 })} />);

        expect(screen.getByTestId('pay-state-label')).toHaveTextContent('مدفوع جزئيًا');
        expect(screen.getByTestId('pay-state-cheque')).toHaveTextContent('شيك تحت التحصيل');
    });

    it('فاتورةٌ لطلبين ونصيبُ الطلب تقدير: تُقال الفاتورةُ بأرقامها، ولا يُنسب للطلب مال', () => {
        const shared = pay({
            state: 'مدفوع جزئيًا', label: 'مدفوع جزئيًا', estimated: true, paid: null, received: null, due: null, credited: null, returned: null, cheque_pending: null,
            invoice: { id: 7, number: 'CINV-7', orders: 2, total: 50, paid: 30, received: 20, cheque_pending: 10, credited: 0, due: 20 },
        });
        render(<PayStateFacts pay={shared} m={m} />);

        expect(screen.queryByTestId('pay-state-paid')).toBeNull();
        expect(screen.queryByTestId('pay-state-due')).toBeNull();
        expect(screen.getByTestId('pay-state-shared')).toHaveTextContent('2');
        expect(screen.getByTestId('pay-state-invoice-received')).toHaveTextContent('20.000');
        expect(screen.getByTestId('pay-state-invoice-cheque')).toHaveTextContent('10.000');
        expect(screen.getByTestId('pay-state-invoice-due')).toHaveTextContent('20.000');
    });

    it('وعلامةُ إرجاعٍ على فاتورةٍ تجمعه بغيره تُقال «على الفاتورة»', () => {
        render(<PayStateBadges pay={pay({ marker: 'مرتجع جزئيًا', marker_on: 'invoice', estimated: true, paid: null, due: null })} />);

        expect(screen.getByTestId('pay-state-marker')).toHaveTextContent('مرتجع جزئيًا');
        expect(screen.getByTestId('pay-state-marker')).toHaveTextContent('على الفاتورة');
    });

    it('طلبٌ ملغى قُبضت فاتورتُه: المالُ رصيدٌ للعميل يُقال', () => {
        render(<PayStateFacts pay={pay({ state: 'ملغى', label: 'ملغى', paid: 0, due: 0, customer_credit: 25, invoice: null })} m={m} />);

        expect(screen.getByTestId('pay-state-credit')).toHaveTextContent('25.000');
        expect(screen.queryByTestId('pay-state-paid')).toBeNull();
    });
});
