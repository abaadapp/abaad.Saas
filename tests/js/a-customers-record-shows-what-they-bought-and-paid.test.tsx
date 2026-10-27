import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import CustomersIndex from '@/Pages/Admin/Customers/Index';
import CustomerShow from '@/Pages/Admin/Customers/Show';
import { pageProps } from './setup';

vi.mock('@/Layouts/AdminLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <div>{children}</div> }));

/**
 * سجلُّ العميل في الشاشة — ما اشتراه وما دفعه، كلٌّ في سجلّه.
 *
 *   - القائمةُ تقول عددَ المشتريات وإجماليَّها وآخرَها برقمه وقيمته، والاسمُ يفتح السجلّ؛
 *   - سجلُّ المشتريات: المصدر، وحالُ الدفع، والفاتورةُ التي تغطّي الطلب في صفّه؛
 *   - سجلُّ التحصيلات: الوسيلة، والمستندُ، والرابطُ المستقلّ رصيدٌ لا شراء.
 *
 * والخادمُ في `ACustomersRecordHoldsWhatTheyBoughtAndWhatTheyPaidTest`.
 */

const base = {
    translations: {},
    context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 } },
    auth: { abilities: [], mayActions: [], user: { businessId: 3 } },
};

const customer = {
    id: 41, name: 'وزارة الثقافة', name_en: null, label: 'وزارة الثقافة', phone: '91234567', email: null,
    points: 0, language: 'ar', address: null, notes: null, branch_id: null, orders: 2, total_spent: 130,
    last_order: '2027-02-10',
    last_purchase: { kind: 'invoice', id: 7, number: 'CINV-000007', amount: 100, date: '2027-02-10' },
};

const purchase = (over: Record<string, unknown>) => ({
    key: 'order:1', kind: 'order', id: 1, number: 'INV-1', type: 'بيع نقطة البيع', source: 'pos', source_label: 'نقطة البيع',
    date: '2027-02-09 12:00', amount: 30, credited: null, pay_state: 'مدفوع', cover: null, status: 'مكتمل', cancelled: false, ...over,
});

beforeEach(() => {
    Object.assign(pageProps, base);
});

afterEach(() => {
    Object.assign(pageProps, { translations: {}, context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 } }, auth: { abilities: [], mayActions: [] } });
});

describe('قائمةُ العملاء', () => {
    it('تقول آخرَ شراءٍ برقمه وقيمته، والاسمُ يفتح السجلّ', () => {
        Object.assign(pageProps, base, {
            customers: [customer], filters: {}, sorts: ['orders', 'total_spent', 'last_order'], stats: [], branches: [],
            currentBranchId: null, unlanguaged: 0,
            pagination: { current_page: 1, last_page: 1, per_page: 10, total: 1, from: 1, to: 1 },
        });
        render(<CustomersIndex />);

        expect(screen.getByText('عدد المشتريات')).toBeInTheDocument();
        expect(screen.getByText('آخر شراء')).toBeInTheDocument();

        const last = screen.getByTestId('customer-last-purchase');
        expect(within(last).getByRole('link', { name: 'CINV-000007' })).toHaveAttribute('href', expect.stringContaining('customerInvoices.show/7'));
        expect(last.textContent).toContain('100.000');

        expect(screen.getByTestId('customer-open')).toHaveAttribute('href', expect.stringContaining('customers.show/41'));
    });
});

describe('ملفُّ العميل', () => {
    const show = () => {
        Object.assign(pageProps, base, {
            customer, addresses: [], branches: [],
            customerFlags: { alerts: false, blocking: false, birthdays: false },
            history: [
                purchase({ key: 'invoice:7', kind: 'invoice', id: 7, number: 'CINV-000007', type: 'فاتورة عميل', source: 'invoice', source_label: 'فاتورة عميل', date: '2027-02-10', amount: 100, credited: 10, pay_state: 'مدفوع جزئيًا' }),
                purchase({ key: 'order:3', id: 3, number: 'WEB-3', source: 'website', source_label: 'الموقع الإلكتروني', amount: 40, pay_state: 'غير مدفوع', cover: { id: 8, number: 'CINV-000008' } }),
                purchase({ key: 'order:2', id: 2, number: 'INV-2', amount: 25, status: 'ملغي', cancelled: true, pay_state: 'ملغى' }),
            ],
            historyTotals: { count: 3, sales: 130, collected: 40, outstanding: 90, cancelled: 1 },
            collections: [
                {
                    key: 'payment:5', kind: 'payment', id: 5, number: 'RCPT-000005', date: '2027-02-10', method: 'Paymob', amount: 40,
                    state: 'مقبوض', counted: true, pending: false, void: false, reference: 'tx-1', link: 'pay-abc',
                    invoices: [{ id: 7, number: 'CINV-000007', amount: 40, orders: [] }], order: null, credit: 0,
                },
                {
                    key: 'payment:6', kind: 'payment', id: 6, number: 'RCPT-000006', date: '2027-02-09', method: 'Paymob', amount: 25,
                    state: 'مقبوض', counted: true, pending: false, void: false, reference: 'tx-2', link: 'pay-free', invoices: [], order: null, credit: 25,
                },
                {
                    key: 'payment:9', kind: 'payment', id: 9, number: 'RCPT-000009', date: '2027-02-08', method: 'شيك', amount: 30,
                    state: 'تحت التحصيل', counted: false, pending: true, void: false, reference: null, link: null,
                    invoices: [{ id: 7, number: 'CINV-000007', amount: 30, orders: [] }], order: null, credit: 0,
                },
            ],
            collectionTotals: { received: 65, pending: 30 },
        });

        return render(<CustomerShow />);
    };

    it('سجلُّ المشتريات: المصدرُ وحالُ الدفع، والطلبُ المفوتَر برقم فاتورته، والملغى مشطوب', () => {
        show();

        expect(screen.getByText('عدد المشتريات')).toBeInTheDocument();
        const rows = screen.getAllByTestId('customer-purchase-row');
        expect(rows).toHaveLength(3);

        expect(rows[0].textContent).toContain('فاتورة مستقلة');
        expect(rows[0].textContent).toContain('مدفوع جزئيًا');
        expect(rows[0].textContent).toContain('إشعار دائن');
        expect(within(rows[1]).getByRole('link', { name: 'CINV-000008' })).toHaveAttribute('href', expect.stringContaining('customerInvoices.show/8'));
        expect(rows[1].textContent).toContain('الموقع الإلكتروني');
        expect(rows[2].querySelector('.line-through')).not.toBeNull();

        expect(screen.getByTestId('purchase-totals').textContent).toContain('130.000');
    });

    it('سجلُّ التحصيلات: Paymob على فاتورته، والرابطُ المستقلّ رصيدٌ للعميل', async () => {
        show();
        await userEvent.setup().click(screen.getByRole('tab', { name: /سجل التحصيلات/ }));

        const rows = screen.getAllByTestId('customer-collection-row');
        expect(rows).toHaveLength(3);
        expect(within(rows[0]).getByRole('link', { name: 'CINV-000007' })).toBeInTheDocument();
        expect(rows[0].textContent).toContain('pay-abc');
        expect(rows[1].textContent).toContain('رصيد للعميل');
        expect(screen.getByTestId('collection-totals').textContent).toContain('65.000');

        // والشيكُ تحت التحصيل بحاله — لا مشطوبٌ كالملغى، ولا مجموعٌ إلى المقبوض
        expect(rows[2].textContent).toContain('تحت التحصيل');
        expect(rows[2].querySelector('.line-through')).toBeNull();
        expect(screen.getByTestId('collection-pending').textContent).toContain('30.000');
    });
});
