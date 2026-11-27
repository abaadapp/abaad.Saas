import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { pageProps } from './setup';

vi.mock('@inertiajs/react', async () => {
    const actual = await vi.importActual<Record<string, unknown>>('@inertiajs/react');

    return { ...actual, usePage: () => ({ props: pageProps, url: '/', component: 'Test' }), Head: () => null };
});

const { default: InvoiceReviewPanel } = await import('@/Pages/Admin/Boutiques/InvoiceReviewPanel');

/**
 * فواتيرُ عملاءٍ فيها صنفُ البوتيك ولم تدخل حسابَه — تُعرض للمراجعة ولا تُحسب.
 *
 * ولا يُرسم القسمُ لمن ردّه الباب، ولا حين لا شيءَ يُراجَع.
 */

const row = (over: Record<string, unknown> = {}) => ({
    id: 1, invoice_id: 7, number: 'CINV-000007', issued_at: '2027-02-10', source: 'manual', description: 'قطعة فضّة',
    quantity: 2, unit_price: 12, discount: 1, amount: 23, payment: 'partial', credited: 0,
    boutique_today: 'بوتيك لمى', evidence: { boutique: 'بوتيك لمى', rate: 20, order: 'INV-000003', at: '2027-02-01' },
    url: '/admin/customer-invoices/7', ...over,
});

const currency = { code: 'OMR', name: 'ريال', symbol: 'ر.ع', rate: 1, is_base: true, active: true } as never;

let asked: string[] = [];
const answer = (status: number, body: unknown) => {
    vi.stubGlobal('fetch', vi.fn(async (url: string) => {
        asked.push(String(url));
        return { ok: status < 400, status, json: async () => body } as Response;
    }));
};

beforeEach(() => {
    asked = [];
    Object.assign(pageProps, { translations: {}, context: {}, errors: {} });
    vi.stubGlobal('route', (name: string, params: Record<string, unknown>) => `/${name}?${new URLSearchParams(params as Record<string, string>)}`);
});
afterEach(() => vi.unstubAllGlobals());

describe('فواتيرُ خارج حساب البوتيك', () => {
    it('لا يُرسم لمن ردّه الباب', async () => {
        answer(403, {});
        render(<InvoiceReviewPanel boutiqueId={3} currency={currency} />);
        await waitFor(() => expect(asked.length).toBe(1));
        expect(screen.queryByTestId('boutique-invoice-review')).toBeNull();
    });

    it('ولا حين لا شيءَ يُراجَع', async () => {
        answer(200, { summary: { lines: 0, invoices: 0, amount: 0 }, rows: [], page: 1, last_page: 1 });
        render(<InvoiceReviewPanel boutiqueId={3} currency={currency} />);
        await waitFor(() => expect(asked.length).toBe(1));
        expect(screen.queryByTestId('boutique-invoice-review')).toBeNull();
    });

    it('يقول كم بندًا ويعرضها عند الطلب — بلا مستحقٍّ ولا عمولة', async () => {
        answer(200, { summary: { lines: 1, invoices: 1, amount: 23 }, rows: [row()], page: 1, last_page: 1 });
        render(<InvoiceReviewPanel boutiqueId={3} currency={currency} />);
        const panel = await screen.findByTestId('boutique-invoice-review');
        expect(panel.textContent).toContain('للمراجعة فقط');
        expect(screen.queryByText('CINV-000007')).toBeNull();

        fireEvent.click(screen.getByRole('button', { name: 'عرض البنود' }));
        expect((await screen.findByText('CINV-000007')).getAttribute('href')).toBe('/admin/customer-invoices/7');
        expect(panel.textContent).toContain('بوتيك لمى بنسبة');
        expect(panel.textContent).toContain('مدفوعة جزئيًا');
        expect(panel.textContent).not.toMatch(/عمولة|صافي/);
        // الأولُ للعدد وحده والثاني للصفوف
        expect(asked[0]).toContain('summary=1');
        expect(asked[1]).toContain('page=1');
    });

    it('ورابطُ الفاتورة لمن يملك قسمها، وما لا بيعَ قبله يُقال', async () => {
        answer(200, { summary: { lines: 1, invoices: 1, amount: 23 }, rows: [row({ url: null, evidence: null, boutique_today: null, source: 'whatsapp', credited: 5 })], page: 1, last_page: 1 });
        render(<InvoiceReviewPanel boutiqueId={3} currency={currency} />);
        fireEvent.click(await screen.findByRole('button', { name: 'عرض البنود' }));
        expect((await screen.findByText('CINV-000007')).tagName).toBe('SPAN');
        const text = screen.getByTestId('boutique-invoice-review').textContent ?? '';
        expect(text).toContain('لا بيع محفوظ قبلها');
        expect(text).toContain('لا بوتيك');
        expect(text).toContain('طلب واتساب');
        expect(text).toContain('لا يُعرف أي بند رُدّ');
    });
});
