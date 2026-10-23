import { describe, expect, it, vi } from 'vitest';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import CustomerShow from '@/Pages/Admin/Customers/Show';
import InvoiceLoyaltyPanel from '@/Pages/Admin/CustomerInvoices/partials/InvoiceLoyaltyPanel';
import { pageProps } from './setup';

vi.mock('@/Layouts/AdminLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <div>{children}</div> }));

/**
 * نقاطُ سداد الفاتورة برابط — كما يراها التاجر.
 *
 *   - في الفاتورة: كلُّ حركةٍ بنقاطها وتحصيلها والرصيدِ بعدها، والعكسُ يُقال
 *     عكسًا. وقبل الحسم «تُحسب خلال دقائق»، ولبرنامجٍ أُطفئ بعدها «متوقّف».
 *   - في ملفّ العميل: تبويبُ «نقاط الولاء» بمصدر كلِّ حركة — والفاتورةُ رابطٌ إليها.
 *
 * وما يحسبه الخادمُ ويحرسه: `AnInvoicePaidByLinkEarnsItsPointsOnceTest`.
 */

const row = (over = {}) => ({ id: 1, type: 'earn' as const, points: 50, balance_after: 170, payment: 'CP-000012', at: null, ...over });

describe('نقاطُ الولاء في الفاتورة', () => {
    it('كلُّ حركةٍ بنقاطها وتحصيلها والرصيد بعدها — والعكسُ يُقال', () => {
        render(<InvoiceLoyaltyPanel loyalty={{ on: true, waiting: false, rows: [row(), row({ id: 2, type: 'redeem', points: -50, balance_after: 120 })] }} />);

        const rows = screen.getAllByTestId('invoice-loyalty-row');
        expect(rows).toHaveLength(2);
        expect(rows[0].textContent).toContain('+50 نقطة');
        expect(rows[0].textContent).toContain('CP-000012');
        expect(rows[0].textContent).toContain('الرصيد بعدها 170');
        expect(rows[1].textContent).toContain('-50 نقطة');
        expect(rows[1].textContent).toContain('عكس تحصيلٍ ملغى');
        expect(screen.queryByText(/متوقّف/)).toBeNull();
    });

    it('قبل الحسم: تُحسب خلال دقائق — وبلا رابطٍ مدفوع: متى تُكسب', () => {
        const { unmount } = render(<InvoiceLoyaltyPanel loyalty={{ on: true, waiting: true, rows: [] }} />);
        expect(screen.getByTestId('invoice-loyalty').textContent).toContain('تُحسب النقاط خلال دقائق');
        unmount();

        render(<InvoiceLoyaltyPanel loyalty={{ on: true, waiting: false, rows: [] }} />);
        expect(screen.getByTestId('invoice-loyalty').textContent).toContain('حين يسدّد الفاتورة برابط الدفع');
    });

    it('وبرنامجٌ أُطفئ بعدها: الحركاتُ تبقى، ولا وعدَ بنقاطٍ جديدة', () => {
        render(<InvoiceLoyaltyPanel loyalty={{ on: false, waiting: false, rows: [row()] }} />);

        expect(screen.getByTestId('invoice-loyalty-row').textContent).toContain('+50 نقطة');
        expect(screen.getByText('برنامج الولاء متوقّف الآن — لا تُكسب نقاط جديدة.')).toBeInTheDocument();
    });
});

describe('سجلُّ نقاط العميل', () => {
    const show = (pointHistory: unknown[]) => {
        Object.assign(pageProps, {
            translations: {},
            context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 } },
            auth: { abilities: [], mayActions: [], user: { businessId: 3 } },
            customer: {
                id: 41, name: 'شركة الضيافة', name_en: null, label: 'شركة الضيافة', phone: '91234567', email: null,
                points: 170, language: 'ar', address: null, notes: null, branch_id: null, orders: 0, total_spent: 0, last_order: '—',
            },
            orders: [], addresses: [], branches: [],
            customerFlags: { alerts: false, blocking: false, birthdays: false },
            pointHistory,
        });

        return render(<CustomerShow />);
    };

    it('تبويبُ «نقاط الولاء» بمصدر كلّ حركة — والفاتورةُ رابطٌ إليها', async () => {
        show([
            { id: 9, type: 'earn', points: 50, balance_after: 170, note: 'اكتساب من سداد الفاتورة', at: '2026-10-25 10:05', invoice: { id: 7, number: 'INV-000007' }, order: null },
            { id: 8, type: 'earn', points: 120, balance_after: 120, note: 'اكتساب من الشراء', at: '2026-10-20 18:00', invoice: null, order: 'S-0031' },
        ]);

        await userEvent.setup().click(screen.getByRole('tab', { name: /نقاط الولاء/ }));

        const rows = screen.getAllByTestId('customer-point-row');
        expect(rows).toHaveLength(2);
        expect(rows[0].textContent).toContain('+50');
        expect(rows[0].textContent).toContain('170');
        expect(within(rows[0]).getByRole('link', { name: 'INV-000007' }).getAttribute('href')).toBe('/admin.customerInvoices.show/7');
        expect(rows[1].textContent).toContain('S-0031');
    });

    it('وعميلٌ بلا حركات: يُقال ذلك', async () => {
        show([]);

        await userEvent.setup().click(screen.getByRole('tab', { name: /نقاط الولاء/ }));

        expect(screen.getByText('لا حركات نقاط لهذا العميل بعد')).toBeInTheDocument();
    });
});
