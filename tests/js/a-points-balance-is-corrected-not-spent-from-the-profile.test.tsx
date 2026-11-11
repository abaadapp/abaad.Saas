import { afterEach, describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { pageProps } from './setup';

vi.mock('@/Layouts/AdminLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <>{children}</> }));

/**
 * ملفُّ العميل يصحّح الرصيد ولا يصرف النقاط.
 *
 * كان زرُّ «صرف النقاط» ظاهرًا لكلّ من يفتح الملفّ، ينقص الرصيدَ كلَّه ويقول
 * «خصم كذا» بلا خصمٍ في أيّ ورقة. وصار «تصحيح رصيد النقاط»: لمن مُنح الفعلَ
 * باسمه (`customerPoints.adjust`)، بنوعٍ وعددٍ وسببٍ إلزاميّ — والاستبدالُ مقابل
 * خصمٍ بابُه الصندوقُ وفاتورةُ العميل.
 */

const draw = async (mayActions: string[]) => {
    const Show = (await import('@/Pages/Admin/Customers/Show')).default;
    Object.assign(pageProps, {
        auth: { abilities: ['customers'], mayActions },
        customer: { id: 7, name: 'مريم', phone: '9', customer_type: 'فرد', points: 500 },
        orders: [], addresses: [], branches: [],
        customerFlags: { alerts: true, blocking: true },
        paymentLinks: { ready: false, can: false, can_cancel: false, settings_url: null, links: [] },
        context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 } },
    });

    return render(<Show />);
};

afterEach(() => {
    for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
});

describe('تصحيحُ رصيد النقاط من ملف العميل', () => {
    it('لا «صرف نقاط» — ولا زرَّ تصحيحٍ لمن لا يملك فعلَه', async () => {
        await draw([]);

        expect(screen.queryByRole('button', { name: /صرف النقاط/ })).toBeNull();
        expect(screen.queryByRole('button', { name: /تصحيح رصيد النقاط/ })).toBeNull();
    });

    it('ولمن مُنحه: نوعٌ وعددٌ وسبب — ويقول إنّه لا يُنتج خصمًا', async () => {
        await draw(['customerPoints.adjust']);

        await userEvent.click(screen.getByRole('button', { name: /تصحيح رصيد النقاط/ }));

        const form = screen.getByTestId('points-adjust');
        expect(form).toHaveTextContent('لا يُنتج خصمًا ولا مالًا');
        expect(form).toHaveTextContent('نوع التصحيح');
        expect(form).toHaveTextContent('عدد النقاط');
        expect(form).toHaveTextContent('السبب');
        expect(screen.getByRole('button', { name: 'حفظ التصحيح' })).toBeInTheDocument();
    });
});
