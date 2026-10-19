import { useState } from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { pageProps } from './setup';

/**
 * «إلغاء التحصيل» في سند القبض — من جهة الشاشة.
 *
 * ═══ ما يُحرس ═══
 *
 *   - الزرُّ لمن يقبله الخادمُ وحده (`can.cancel`)، ولا يُرسم لسندٍ أُلغي.
 *   - يُرسل السببَ إلى بابِ الإلغاء باسمه — لا إلى بابٍ آخر.
 *
 * والخادمُ يحرس ما يقع (`ACustomerPaymentIsCancelledOnlyByItsOwnPermissionTest`).
 */

const sent: { url: string; data: Record<string, unknown> }[] = [];

vi.mock('@inertiajs/react', async () => {
    const actual = await vi.importActual<Record<string, unknown>>('@inertiajs/react');

    const useForm = (initial: Record<string, unknown>) => {
        const [data, setData] = useState(initial);

        return {
            data,
            errors: {} as Record<string, string>,
            processing: false,
            setData: (key: string, value: unknown) => setData((d) => ({ ...d, [key]: value })),
            post: (url: string) => sent.push({ url, data }),
        };
    };

    return { ...actual, usePage: () => ({ props: pageProps, url: '/', component: 'Test' }), Head: () => null, useForm };
});

vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));
vi.mock('@/Components/PaperFrame', () => ({ default: () => null }));

const ReceiptShow = (await import('@/Pages/Admin/CustomerInvoices/ReceiptShow')).default;

const receipt = (over: Record<string, unknown> = {}) => ({
    id: 42, number: 'RCV-000042', amount: 60, allocated: 60, unallocated: 0, method: 'نقدي', bank: null,
    reference: null, occurred_at: '2026-10-19', notes: null, customer: 'وزارة الثقافة', customer_id: 7,
    cheque_status: null, cheque_due_at: null, cancelled_at: null, cancellation_reason: null,
    allocations: [{ id: 1, amount: 60, invoice_id: 3, invoice: 'CINV-000003' }],
    ...over,
});

const page = (can: boolean, over: Record<string, unknown> = {}) => {
    Object.assign(pageProps, { receipt: receipt(over), can: { cancel: can }, paper: { html: '', size: 'A4', url: '/x.pdf' } });
    render(<ReceiptShow />);
};

describe('إلغاء التحصيل', () => {
    beforeEach(() => {
        sent.length = 0;
    });

    it('لا يُرسم لمن لا يقبله الخادم', () => {
        page(false);

        expect(screen.queryByTestId('cancel-payment')).toBeNull();
        expect(screen.queryByRole('button', { name: 'إلغاء التحصيل' })).toBeNull();
    });

    it('ولا لسندٍ أُلغي — ويُقرأ أنّه ملغى', () => {
        page(true, { cancelled_at: '2026-10-19', cancellation_reason: 'سُجّل مرّتين' });

        expect(screen.queryByTestId('cancel-payment')).toBeNull();
        expect(screen.getByText(/أُلغي هذا السند/)).toBeInTheDocument();
    });

    it('ولمن يملكه: يرسل السببَ إلى بابِ إلغاء التحصيل', async () => {
        page(true);

        await userEvent.type(screen.getByRole('textbox'), 'سُجّل مرّتين');
        await userEvent.click(screen.getByRole('button', { name: 'إلغاء التحصيل' }));

        expect(sent).toEqual([{ url: '/admin.customerPayments.cancel/42', data: { reason: 'سُجّل مرّتين' } }]);
    });
});
