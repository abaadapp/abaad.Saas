import { render, screen, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import PrepCard from '@/Pages/Admin/Preparation/partials/PrepCard';
import PrepDetails from '@/Pages/Admin/Preparation/partials/PrepDetails';
import type { PrepOrder } from '@/Pages/Admin/Preparation/partials/types';

/**
 * من يجهّز يسلّم — فيعرف من البطاقة ما يجمع، وممّن، وكم يُحصَّل.
 *
 * ═══ ما يُحرس ═══
 *
 * ١) البطاقةُ تحمل أسماءَ الأصناف (أربعةً، وما زاد عددُه)، وهاتفَ صاحب الطلب
 *    رابطَ اتّصال، والإجماليَّ وحالَ الدفع ووسيلتَه.
 * ٢) التفاصيلُ تحمل إجماليَّ كلّ سطرٍ وملخّصَ ما يُحصَّل.
 * ٣) والطلبُ بلا هاتفٍ ولا مبلغ (حمولةٌ قديمة) لا يرسم صناديقَ فارغة.
 */

const order = (over: Partial<PrepOrder> = {}): PrepOrder => ({
    number: 'INV-9',
    status: 'جاهز',
    customer: 'سارة',
    customer_phone: '96891234567',
    total: 37.5,
    payment_status: 'غير مدفوع',
    payment_method: 'نقدي',
    fulfillment: 'pickup',
    scheduled_for: '2027-02-03 10:00',
    scheduled: { date: '2027-02-03', time: '10:00', day: 'today', minutes_left: 120 },
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
    items: [
        { id: 1, name: 'باقة جوري', qty: 2, price: 12.5, total: 25, note: null, image: null, addons: [] },
        { id: 2, name: 'دبدوب', qty: 1, price: 12.5, total: 12.5, note: null, image: null, addons: [] },
    ],
    next: ['تم الاستلام'],
    checks: {},
    ...over,
});

const noop = () => {};

describe('بطاقةُ الطاولة', () => {
    it('تحمل الأصنافَ والهاتفَ وما يُحصَّل', () => {
        render(<PrepCard order={order()} ageMs={0} onOpen={noop} onMove={noop} />);

        const items = screen.getByTestId('prep-items');
        expect(items).toHaveTextContent('باقة جوري');
        expect(items).toHaveTextContent('×2');
        expect(items).toHaveTextContent('دبدوب');

        const phone = screen.getByTestId('prep-customer-phone');
        expect(phone).toHaveAttribute('href', 'tel:96891234567');

        const moneyRow = screen.getByTestId('prep-money');
        expect(moneyRow).toHaveTextContent('37.500');
        expect(moneyRow).toHaveTextContent('غير مدفوع');
        expect(moneyRow).toHaveTextContent('نقدي');
    });

    it('أربعةُ أصنافٍ تكفي البطاقة — وما زاد عددُه', () => {
        const many = Array.from({ length: 6 }, (_, k) => ({
            id: k + 1, name: `صنف ${k + 1}`, qty: 1, price: 1, total: 1, note: null, image: null, addons: [],
        }));
        render(<PrepCard order={order({ items: many })} ageMs={0} onOpen={noop} onMove={noop} />);

        const items = screen.getByTestId('prep-items');
        expect(within(items).getAllByRole('listitem')).toHaveLength(5);
        expect(items).toHaveTextContent('صنف 4');
        expect(items).not.toHaveTextContent('صنف 5');
        expect(items).toHaveTextContent('+ 2 أصناف أخرى');
    });

    it('وبلا هاتفٍ ولا مبلغٍ لا صناديقَ فارغة', () => {
        render(
            <PrepCard
                order={order({ customer_phone: null, total: undefined, payment_status: undefined })}
                ageMs={0}
                onOpen={noop}
                onMove={noop}
            />,
        );

        expect(screen.queryByTestId('prep-customer-phone')).toBeNull();
        expect(screen.queryByTestId('prep-money')).toBeNull();
    });
});

describe('تفاصيلُ التجهيز', () => {
    it('تحمل إجماليَّ كلّ سطرٍ وملخّصَ ما يُحصَّل والهاتف', () => {
        render(
            <PrepDetails
                order={order()}
                gone={false}
                onClose={noop}
                onMove={noop}
                onToggle={noop}
                deliveryNoteUrl={() => '/x'}
                timelineUrl={() => '/y'}
            />,
        );

        const details = screen.getByTestId('prep-details');
        expect(details).toHaveTextContent('25.000');
        expect(details).toHaveTextContent('12.500');

        const summary = screen.getByTestId('prep-details-money');
        expect(summary).toHaveTextContent('الإجمالي');
        expect(summary).toHaveTextContent('37.500');
        expect(summary).toHaveTextContent('غير مدفوع');

        expect(screen.getByTestId('prep-details-customer-phone')).toHaveAttribute('href', 'tel:96891234567');
    });
});
