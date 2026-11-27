import { useRef, useState } from 'react';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { pageProps } from './setup';

/**
 * الإشعارُ الدائنُ يسمّي الطلبَ الذي كان عنه — من جهة الشاشة.
 *
 * ═══ ما يُحرس ═══
 *
 *   - إشعارُ الإرجاع وحده يعرض «الطلب المُرجَع» على ورقةٍ تجمع طلبات، وبما بقي
 *     من كلّ طلب؛ ويُرسل الطلبَ المختار. وغيرُ الإرجاع لا يعرضه ولا يرسله.
 *   - الإشعارُ المسمّى يقول طلبَه ومن سمّاه ومتى ولماذا.
 *   - الإشعارُ اليدويّ بلا طلب يُسمّى بعده — لمن يملك إصدارَ الإشعار، بطلبٍ
 *     وسبب، إلى بابه هو. وإشعارُ إلغاء الطلب لا يُسمّى.
 *
 * والخادمُ يحرس ما يقع (`ACreditNoteNamesTheOrderItWasForTest`).
 */

const sent: { url: string; payload: Record<string, unknown> }[] = [];

vi.mock('@inertiajs/react', async () => {
    const actual = await vi.importActual<Record<string, unknown>>('@inertiajs/react');

    const useForm = (initial: Record<string, unknown>) => {
        const [data, setData] = useState(initial);
        const shape = useRef<(d: Record<string, unknown>) => Record<string, unknown>>((d) => d);
        const send = (url: string) => {
            sent.push({ url, payload: shape.current({ ...data }) });
        };

        return {
            data,
            errors: {},
            processing: false,
            setData: (key: unknown, value?: unknown) =>
                setData((d) =>
                    typeof key === 'function'
                        ? (key as (d: Record<string, unknown>) => Record<string, unknown>)(d)
                        : typeof key === 'string'
                          ? { ...d, [key]: value }
                          : { ...d, ...(key as Record<string, unknown>) },
                ),
            transform: (fn: (d: Record<string, unknown>) => Record<string, unknown>) => {
                shape.current = fn;
            },
            post: send,
            put: send,
            reset: () => setData(initial),
            clearErrors: () => {},
            setError: () => {},
        };
    };

    return {
        ...actual,
        usePage: () => ({ props: pageProps, url: '/', component: 'Test' }),
        router: { visit: vi.fn(), get: vi.fn(), post: vi.fn(), delete: vi.fn(), reload: vi.fn(), on: vi.fn() },
        Head: () => null,
        useForm,
    };
});

vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));
vi.mock('@/Components/PaperFrame', () => ({ default: () => null }));

/* قائمةُ Radix لا تُفتح في jsdom — فتقوم مقامها قائمةٌ أصليّة بالخيارات نفسِها */
vi.mock('@/Components/Field', async () => {
    const actual = await vi.importActual<Record<string, unknown>>('@/Components/Field');

    return {
        ...actual,
        Select: ({ id, value, onChange, options, placeholder }: {
            id: string; value: string; placeholder?: string;
            onChange: (e: { target: { value: string } }) => void;
            options: { value: string; label: string }[];
        }) => (
            <select data-testid={`${id}-input`} value={value} onChange={(e) => onChange({ target: { value: e.target.value } })}>
                <option value="">{placeholder}</option>
                {options.map((o) => (
                    <option key={o.value} value={o.value}>{o.label}</option>
                ))}
            </select>
        ),
    };
});

const Show = (await import('@/Pages/Admin/CustomerInvoices/Show')).default;

const invoice = {
    id: 3, number: 'CINV-000003', customer: 'شركة الشهر', customer_id: 7, status: 'صادرة', state: 'صادرة',
    issued_at: '2026-11-01', due_at: null, total: 200, paid: 0, outstanding: 200, days_overdue: 0,
    po_number: null, contract_number: null, external_reference: null, department: null, cost_center: null, attention_to: null,
    subtotal: 200, discount_total: 0, tax_total: 0, notes: null, internal_notes: null, may_read_attachments: false,
    attachments: [], orders: ['INV-000001', 'INV-000002'], cancellation_reason: null,
    items: [{ description: 'باقة', quantity: 20, unit_price: 10, discount: 0, tax_rate: 0, line_total: 200 }],
    payments: [], credit_notes: [] as unknown[],
    link_orders: [
        { id: 11, number: 'INV-000001', room: 80, room_tax: 0 },
        { id: 12, number: 'INV-000002', room: 120, room_tax: 0 },
    ],
};

const may = { issue: true, cancel: true, credit_note: true, credit_note_link: true, pay: false, payment_link: false };

const showPage = (over: Record<string, unknown> = {}) => {
    Object.assign(pageProps, {
        invoice, may, bank_accounts: [], methods: ['نقدي'], bank_methods: [], paper: { html: '', size: 'A4' },
        paymentLinks: { ready: false, can: false, can_cancel: false, settings_url: null, links: [] },
        paymentLinkFailure: null, preparation: null,
        ...over,
    });
    render(<Show />);

    return userEvent.setup();
};

beforeAll(() => {
    globalThis.fetch = vi.fn(async () => new Response(JSON.stringify({ html: '' }))) as never;
});

beforeEach(() => {
    sent.length = 0;
    for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    Object.assign(pageProps, {
        translations: {}, context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 } },
        auth: { abilities: [], mayActions: [] }, flash: { toast: null, status: null }, errors: {},
    });
});

describe('«الطلب المُرجَع» في نموذج الإشعار', () => {
    it('إشعارُ الإرجاع يعرض طلباتِ الورقة بما بقي منها ويرسل المختار', async () => {
        const user = showPage();
        await user.click(screen.getByRole('button', { name: /إشعار دائن/ }));

        expect(screen.queryByTestId('credit-note-order')).toBeNull();
        await user.selectOptions(screen.getByTestId('credit-note-basis-input'), 'return');

        const order = screen.getByTestId('credit-note-order-input');
        expect(within(order).getByRole('option', { name: /INV-000001 — بقي/ })).toHaveTextContent('80');
        expect(within(order).getByRole('option', { name: /INV-000002 — بقي/ })).toHaveTextContent('120');
        await user.selectOptions(order, '12');
        await user.type(screen.getByPlaceholderText('مثال: رُدّت باقتان'), 'رُدّت');
        await user.click(screen.getByRole('button', { name: 'حفظ الإشعار' }));

        expect(sent).toHaveLength(1);
        expect(sent[0].url).toBe('/admin/customer-invoices/3/credit-note');
        expect(sent[0].payload).toMatchObject({ basis: 'return', order_id: '12', reason: 'رُدّت' });
    });

    it('وغيرُ الإرجاع لا يعرضه — ويُمحى ما اختير إن تغيّر السبب', async () => {
        const user = showPage();
        await user.click(screen.getByRole('button', { name: /إشعار دائن/ }));

        await user.selectOptions(screen.getByTestId('credit-note-basis-input'), 'return');
        await user.selectOptions(screen.getByTestId('credit-note-order-input'), '11');
        await user.selectOptions(screen.getByTestId('credit-note-basis-input'), 'discount');

        expect(screen.queryByTestId('credit-note-order')).toBeNull();
        await user.click(screen.getByRole('button', { name: 'حفظ الإشعار' }));
        expect(sent[0].payload).toMatchObject({ basis: 'discount', order_id: '' });
    });

    it('وورقةٌ لا تجمع طلبات لا تعرضه', async () => {
        const user = showPage({ invoice: { ...invoice, orders: [], link_orders: [] } });
        await user.click(screen.getByRole('button', { name: /إشعار دائن/ }));
        await user.selectOptions(screen.getByTestId('credit-note-basis-input'), 'return');

        expect(screen.queryByTestId('credit-note-order')).toBeNull();
    });
});

describe('الإشعاراتُ على الورقة وطلباتُها', () => {
    const named = {
        id: 21, number: 'CN-000001', amount: 50, reason: 'رُدّت', at: '2026-11-02', manual: true,
        order: { number: 'INV-000001', by: 'المحاسب', at: '2026-11-03', reason: 'تحقّقتُ من سند الاستلام' },
    };
    const unnamed = { id: 22, number: 'CN-000002', amount: 10, reason: 'خصم', at: '2026-11-02', manual: true, order: null };
    const byCancel = { id: 23, number: 'CN-000003', amount: 40, reason: 'إلغاء الطلب', at: '2026-11-04', manual: false, order: null };

    it('المسمّى يقول طلبَه ومن سمّاه ومتى ولماذا', () => {
        showPage({ invoice: { ...invoice, credit_notes: [named] } });

        const line = screen.getByTestId('credit-note-order-21');
        for (const text of ['عن الطلب INV-000001', 'المحاسب', '2026-11-03', 'تحقّقتُ من سند الاستلام']) {
            expect(line).toHaveTextContent(text);
        }
        expect(screen.queryByTestId('name-credit-note-order-21')).toBeNull();
    });

    it('واليدويُّ بلا طلب يُسمّى بطلبٍ وسبب — إلى بابه', async () => {
        const user = showPage({ invoice: { ...invoice, credit_notes: [unnamed, byCancel] } });

        // وإشعارُ إلغاء الطلب طلبُه معروف — لا يُسمّى
        expect(screen.queryByTestId('name-credit-note-order-23')).toBeNull();

        await user.click(screen.getByTestId('name-credit-note-order-22'));
        const form = screen.getByTestId('name-credit-note-order-form-22');
        const save = within(form).getByRole('button', { name: 'حفظ' });
        expect(save).toBeDisabled();

        await user.selectOptions(screen.getByTestId('credit-note-22-order-input'), '11');
        expect(save, 'بلا سببٍ لا يُحفظ').toBeDisabled();
        await user.type(within(form).getByPlaceholderText('مثال: رُدّت بضاعة هذا الطلب'), 'سند الاستلام');
        await user.click(save);

        expect(sent).toEqual([{ url: '/admin.customerInvoices.creditNotes.order/22', payload: { order_id: '11', reason: 'سند الاستلام' } }]);
    });

    it('ومن يملك إصدارَ الإشعار وحده لا يرى «تسمية الطلب» — فعلٌ آخر', () => {
        showPage({ invoice: { ...invoice, credit_notes: [unnamed] }, may: { ...may, credit_note: true, credit_note_link: false } });

        expect(screen.queryByTestId('name-credit-note-order-22')).toBeNull();
    });

    it('ولا تُعرض حين لا طلبَ يسعه', () => {
        showPage({ invoice: { ...invoice, credit_notes: [unnamed], link_orders: [] } });

        expect(screen.queryByTestId('name-credit-note-order-22')).toBeNull();
    });
});
