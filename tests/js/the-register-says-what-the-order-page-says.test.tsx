import { render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import type { PayState } from '@/Components/OrderPaymentState';

vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

const { default: Sales } = await import('@/Pages/Admin/CustomerInvoices/Sales');
type SaleRow = import('@/Pages/Admin/CustomerInvoices/Sales').SaleRow;

/**
 * سجلُّ المبيعات يكتب على شارة السداد ما تكتبه صفحةُ الطلب — من `pay`.
 *
 * عمودُ `pay_state` حالُ القاعدة كما يرشّح بها السجلّ، والشارةُ من `pay`: علامةُ ما رُدّ،
 * والشيكُ تحت التحصيل باسمه، والمقبوضُ فعلًا والباقي — أو الباقي على الفاتورة حين
 * يكون نصيبُ الطلب منها تقديرًا. وصفٌّ بلا `pay` يبقى كما كان.
 */

const pay = (over: Partial<PayState> = {}): PayState => ({
    state: 'مدفوع', label: 'مدفوع', marker: null, marker_on: null, cheque_marker: null, estimated: false,
    paid: 25, received: 25, due: 0, credited: 0, returned: 0, refunded: 0, cheque_pending: 0, customer_credit: 0,
    invoice: { id: 9, number: 'CI-9', orders: 1, total: 25, paid: 25, received: 25, cheque_pending: 0, credited: 0, due: 0 },
    ...over,
});

const base: SaleRow = {
    key: 'order:1', kind: 'order', id: 1, number: 'WEB-1', type: 'طلب موقع', source: 'website', source_label: 'الموقع',
    customer: 'مريم', seller_kind: 'website', seller: null, seller_id: null, account: null, branch: null,
    date: '2027-02-10 10:00', amount: 25, credited: null, pay_state: 'مدفوع', cover: { id: 9, number: 'CI-9' }, prep: null, prep_stage: 'none',
    status: 'مكتمل', cancelled: false, prep_id: null,
};

const props = (rows: SaleRow[]) => ({
    rows,
    pagination: { current_page: 1, last_page: 1, per_page: 20, from: 1, to: rows.length, total: rows.length, prev_page_url: null, next_page_url: null },
    filters: {},
    source: null as 'pos' | 'website' | null,
    totals: { count: rows.length, sales: 25, collected: 25, outstanding: 0, cancelled: 0 },
    sellers: [],
    paymentStates: ['مدفوع', 'مدفوع جزئيًا', 'غير مدفوع', 'ملغى'],
    prepStages: { none: 'بلا تجهيز' },
    branch: null,
    mayPrepare: false,
    unknownCount: null as number | null,
});

const rowOf = (number: string) => screen.getByText(number).closest('tr') as HTMLElement;

describe('شارةُ السداد في السجلّ', () => {
    it('ما سُدّ بشيكٍ لم يُصرف يُقال باسمه — لا «مدفوع» — ومبلغُ الورقة تحته', () => {
        render(<Sales {...props([{ ...base, pay: pay({ label: 'مسدَّد بشيك تحت التحصيل', received: 0, cheque_pending: 25 }) }])} />);

        const row = rowOf('WEB-1');
        expect(within(row).getByTestId('pay-state-label')).toHaveTextContent('مسدَّد بشيك تحت التحصيل');
        expect(within(row).getByTestId('register-pay-amounts')).toHaveTextContent('25');
    });

    it('ما قُبض بعضُه: المقبوضُ فعلًا والباقي', () => {
        render(<Sales {...props([{ ...base, pay_state: 'مدفوع جزئيًا', pay: pay({ state: 'مدفوع جزئيًا', label: 'مدفوع جزئيًا', paid: 10, received: 10, due: 15 }) }])} />);

        const amounts = within(rowOf('WEB-1')).getByTestId('register-pay-amounts');
        expect(amounts).toHaveTextContent('المقبوض فعلًا');
        expect(amounts).toHaveTextContent('10');
        expect(amounts).toHaveTextContent('15');
    });

    it('نصيبٌ مقدَّر من فاتورةٍ لطلبين: الباقي على الفاتورة، ولا مقبوضَ للطلب', () => {
        const shared = pay({
            state: 'مدفوع جزئيًا', label: 'مدفوع جزئيًا', estimated: true, paid: null, received: null, due: null, credited: null, returned: null, cheque_pending: null,
            invoice: { id: 9, number: 'CI-9', orders: 2, total: 50, paid: 30, received: 30, cheque_pending: 0, credited: 0, due: 20 },
        });
        render(<Sales {...props([{ ...base, pay_state: 'مدفوع جزئيًا', pay: shared }])} />);

        const amounts = within(rowOf('WEB-1')).getByTestId('register-pay-amounts');
        expect(amounts).toHaveTextContent('المتبقّي على الفاتورة');
        expect(amounts).toHaveTextContent('20');
        expect(amounts).not.toHaveTextContent('المقبوض فعلًا');
    });

    it('وعلامةُ ما رُدّ بجانب الحال', () => {
        render(<Sales {...props([{ ...base, pay: pay({ marker: 'مسترد جزئيًا', marker_on: 'order', refunded: 5 }) }])} />);

        expect(within(rowOf('WEB-1')).getByTestId('pay-state-marker')).toHaveTextContent('مسترد جزئيًا');
    });

    it('وصفٌّ بلا `pay` يبقى كما كان', () => {
        render(<Sales {...props([{ ...base, pay_state: 'غير مدفوع' }])} />);

        const row = rowOf('WEB-1');
        expect(row).toHaveTextContent('غير مدفوع');
        expect(within(row).queryByTestId('pay-state')).toBeNull();
    });
});
