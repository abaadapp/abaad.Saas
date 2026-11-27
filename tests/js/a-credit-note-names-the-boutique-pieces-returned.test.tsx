import { useState } from 'react';
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { pageProps } from './setup';

/**
 * إشعارُ الدائن على فاتورةٍ فيها قطعُ بوتيك: يُسأل لماذا، وما رُدّ من قطعهم.
 *
 * ═══ ما يُحرس ═══
 *
 *   - فاتورةٌ بلا بوتيك: سببٌ ورمزُ نموذجٍ كأيّ إشعار، ولا قطع.
 *   - «إرجاع» يفتح القطع، و«خصم» أو «تصحيح خطأ» لا يفتحها ويقول إنّه على المتجر — سببٌ واحدٌ للفاتورة كلّها.
 *   - حالُ القطعة (سليمة/تالفة) لما حملته ورقةٌ وحده.
 *   - المبلغُ يُقترح من ثمن ما رُدّ ما لم يُكتب بيد.
 *   - حصصُ البوتيكات بطاقةٌ لمن وصلته، وتجاوزُ الخصم يُقال.
 */

const sent: { url: string; data: Record<string, unknown> }[] = [];
const cleared: string[] = [];

vi.mock('@inertiajs/react', async () => {
    const actual = await vi.importActual<Record<string, unknown>>('@inertiajs/react');

    const useForm = (initial: Record<string, unknown>) => {
        const [data, setData] = useState(initial);

        return {
            data,
            errors: {} as Record<string, string>,
            processing: false,
            setData: (key: unknown, value?: unknown) =>
                setData((d) => (typeof key === 'function' ? (key as (x: typeof d) => typeof d)(d) : { ...d, [key as string]: value })),
            post: (url: string) => sent.push({ url, data }),
            reset: () => setData(initial),
            clearErrors: (field: string) => cleared.push(field),
            transform: () => {},
        };
    };

    return {
        ...actual,
        usePage: () => ({ props: current, url: '/', component: 'Test' }),
        router: { visit: vi.fn(), get: vi.fn(), post: vi.fn(), reload: vi.fn(), on: vi.fn() },
        Head: () => null,
        Link: ({ children }: { children: React.ReactNode }) => <a>{children}</a>,
        useForm,
    };
});

vi.mock('@/Components/Field', async () => {
    const actual = await vi.importActual<Record<string, unknown>>('@/Components/Field');

    return {
        ...actual,
        Select: ({ id, options, value, onChange }: {
            id?: string;
            options: { label: string; value: string | number }[];
            value?: string | number | null;
            onChange?: (e: { target: { value: string; name: string } }) => void;
        }) => (
            <select data-testid={id ? `${id}-input` : undefined} value={value ?? ''} onChange={(e) => onChange?.({ target: { value: e.target.value, name: '' } })}>
                <option value="" />
                {options.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
            </select>
        ),
    };
});

vi.mock('@/Layouts/AdminLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <div>{children}</div> }));
vi.mock('@/Components/PageHeader', () => ({ default: ({ actions, children }: { actions?: React.ReactNode; children?: React.ReactNode }) => <div>{actions}{children}</div> }));
vi.mock('@/Components/PaperFrame', () => ({ default: () => null }));
vi.mock('@/Components/PaymentLinks', () => ({
    default: () => null, invoiceLinkView: () => ({ current: null, retry: null }), InvoicePaymentLink: () => null, PaymentLinkRetry: () => null,
}));
vi.mock('@/Components/DocumentPanel', () => ({ default: () => null, DocumentAside: () => null }));

let current: Record<string, unknown> = {};
const Show = (await import('@/Pages/Admin/CustomerInvoices/Show')).default;

const invoice = {
    id: 31, number: 'CINV-000031', status: 'صادرة', state: 'مدفوعة', customer: 'شركة الورد', customer_id: 5, issued_at: '2027-02-10', due_at: null,
    total: 42, subtotal: 42, tax_total: 0, discount_total: 0, paid: 42, outstanding: 0, loyalty_points: 0, loyalty_discount: 0, currency: 'OMR',
    items: [], payments: [], credit_notes: [], attachments: [], orders: [], notes: null, internal_notes: null, cancellation_reason: null, days_overdue: 0,
};
const pieces = [
    { key: 'i7', item_id: 7, name: 'قطعة فضّة', boutique: 'بوتيك لمى', left: 2, each: 6, on_sheet: false },
    { key: 'i8', item_id: 8, name: 'عطر', boutique: 'بوتيك ريم', left: 1, each: 10, on_sheet: true },
];
// قطعةٌ داخل باقة: يُسأل عنها عادت أم لا، ولا يُقترح بها مبلغ
const bundled = { key: 'p41', item_id: 9, name: 'قطعة فضّة', boutique: 'بوتيك لمى', left: 3, each: 0, on_sheet: true, piece: true, parent: 'باقة فضّة' };
const share = (over: Record<string, unknown> = {}) => ({
    id: 1, item_id: 7, boutique_id: 5, boutique: 'بوتيك لمى', name: 'قطعة فضّة', quantity: 2, returned: 0, unit_price: 6,
    gross: 12, discount: 0, rate: 20, commission: 2.4, net: 9.6, under_list: null, state: 'awaiting', sheet: null, ...over,
});

function mount(over: Record<string, unknown> = {}) {
    current = {
        ...pageProps, invoice, may: { issue: true, cancel: true, credit_note: true, pay: true }, bank_accounts: [], methods: ['نقدي'], bank_methods: [],
        paper: { html: '', size: 'a4' }, paymentLinks: { links: [], enabled: false }, paymentLinkFailure: null, preparation: null, loyalty: null, collection: null,
        mayOpenReceipts: false, boutiquePieces: [], boutiqueShares: [], flash: {}, ...over,
    };
    render(<Show />);
}

const openNote = () => fireEvent.click(screen.getAllByRole('button').find((b) => /إشعار دائن/.test(b.textContent ?? ''))!);
const choose = (value: string) => fireEvent.change(screen.getByTestId('credit-note-basis-input'), { target: { value } });
const save = () => fireEvent.click(screen.getByRole('button', { name: 'حفظ الإشعار' }));

beforeEach(() => { sent.length = 0; });
afterEach(() => cleanup());

describe('إشعارُ الدائن وقطعُ البوتيك', () => {
    it('فاتورةٌ بلا بوتيك: سببٌ ورمزُ نموذجٍ كأيّ إشعار، ولا قطع', () => {
        mount();
        openNote();
        expect(screen.queryByTestId('credit-boutique')).toBeNull();
        save();
        expect(Object.keys(sent[0].data).sort()).toEqual(['amount', 'basis', 'reason', 'tax_amount', 'token']);
    });

    it('يُسأل لماذا الإشعار، والخصمُ لا يفتح القطع ويقول إنّه على المتجر', () => {
        mount({ boutiquePieces: pieces });
        openNote();
        const box = screen.getByTestId('credit-boutique');
        expect(screen.queryAllByTestId('credit-piece')).toHaveLength(0);
        choose('discount');
        // والجوابُ يمحو رسالةَ السؤال — لا تبقى فوق ما اختير
        expect(cleared).toContain('basis');
        expect(screen.queryAllByTestId('credit-piece')).toHaveLength(0);
        expect(box.textContent).toContain('الخصم وتصحيح الخطأ على المتجر');
        save();
        expect(sent[0].data.basis).toBe('discount');
    });

    it('«إرجاع» يفتح القطع، وحالُ القطعة لما حملته ورقةٌ وحده، والمبلغُ يُقترح من ثمنها', () => {
        mount({ boutiquePieces: pieces });
        openNote();
        choose('return');
        const rows = screen.getAllByTestId('credit-piece');
        expect(rows).toHaveLength(2);
        expect(within(rows[0]).queryByLabelText('عاد سليمًا')).toBeNull();
        expect(within(rows[1]).getByLabelText('عاد سليمًا')).toBeTruthy();

        fireEvent.change(screen.getByLabelText('رُدّ من قطعة فضّة'), { target: { value: '1' } });
        fireEvent.change(screen.getByLabelText('رُدّ من عطر'), { target: { value: '1' } });
        fireEvent.change(within(rows[1]).getByLabelText('عاد سليمًا'), { target: { value: '1' } });
        save();
        expect(sent[0].url).toBe('/admin/customer-invoices/31/credit-note');
        expect(sent[0].data).toMatchObject({ basis: 'return', amount: '16', pieces: { i7: { quantity: '1' }, i8: { quantity: '1', sellable: '1' } } });
    });

    it('قطعةُ الباقة يُسأل عنها كم عاد وكم لن يعود، باسم باقتها، ولا يتغيّر بها المبلغ', () => {
        mount({ boutiquePieces: [...pieces, bundled] });
        openNote();
        choose('return');
        const row = screen.getByTestId('credit-bundle-piece');
        expect(row.textContent).toContain('باقة فضّة');
        fireEvent.change(within(row).getByLabelText('رُدّ من قطعة فضّة'), { target: { value: '2' } });
        fireEvent.change(within(row).getByLabelText('عادت إلى الرف'), { target: { value: '1' } });
        fireEvent.change(within(row).getByLabelText('تالف أو لن يعود'), { target: { value: '1' } });
        save();
        expect(sent[0].data).toMatchObject({ basis: 'return', amount: '42', pieces: { p41: { quantity: '2', sellable: '1', damaged: '1' } } });
    });

    it('والمبلغُ المكتوب بيدٍ لا يُبدَّل', () => {
        mount({ boutiquePieces: pieces });
        openNote();
        choose('return');
        fireEvent.change(screen.getByLabelText(/^المبلغ/), { target: { value: '5' } });
        fireEvent.change(screen.getByLabelText('رُدّ من قطعة فضّة'), { target: { value: '2' } });
        save();
        expect(sent[0].data.amount).toBe('5');
    });

    it('حصصُ البوتيكات بطاقةٌ بحالها ورقم ورقتها، ولا تُرسم لمن لم تصله', () => {
        mount();
        expect(screen.queryByTestId('boutique-shares')).toBeNull();
        cleanup();
        mount({ boutiqueShares: [share(), share({ id: 2, boutique: 'بوتيك ريم', name: 'عطر', commission: 1, net: 9, state: 'issued', sheet: 'BQ-2027-02-1' })] });
        const card = screen.getByTestId('boutique-shares').textContent ?? '';
        for (const text of ['بانتظار التحصيل', '9.600', 'BQ-2027-02-1', 'في ورقة لم تُسدَّد', '18.600', '3.400']) expect(card).toContain(text);
        expect(screen.queryByTestId('boutique-shares-over')).toBeNull();
    });

    it('وخصمٌ جاوز حصّةَ المتجر يُقال، والملغاةُ لا تُجمع', () => {
        mount({ boutiqueShares: [share({ discount: 5 }), share({ id: 3, state: 'void', net: 100, commission: 50 })] });
        expect(screen.getByTestId('boutique-shop-share').textContent).toContain('2.600');
        expect(screen.getByTestId('boutique-shares-over')).toBeTruthy();
        expect(screen.getByTestId('boutique-shop-share').textContent).not.toContain('109');
    });
});
