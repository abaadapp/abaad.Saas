import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import MovementsPanel, { CATEGORY, KIND, type Movement, type MovementsPage } from '@/Pages/Admin/Boutiques/MovementsPanel';

/* قائمةُ Radix لا تُختار في jsdom — فتُرسم قائمةً أصليّة بالعقد نفسِه */
vi.mock('@/Components/Field', async () => {
    const actual = await vi.importActual<Record<string, unknown>>('@/Components/Field');

    return {
        ...actual,
        Select: ({ options, value, onChange, 'aria-label': label }: {
            options: { label: string; value: string | number }[];
            value?: string | number | null;
            onChange?: (e: { target: { value: string; name: string } }) => void;
            'aria-label'?: string;
        }) => (
            <select aria-label={label} value={value ?? ''} onChange={(e) => onChange?.({ target: { value: e.target.value, name: '' } })}>
                {options.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
            </select>
        ),
    };
});

/**
 * «حركات البوتيك» — كلُّ حركةٍ بمستندها وقيدها، وأثرُها ورصيدُها من الخادم.
 *
 * والحسبةُ في الخادم (`BoutiqueMovements`)، يحرسها `ABoutiqueMovementNamesItsDocumentsTest`.
 */
const currency = { id: 1, code: 'OMR', name: 'ريال عماني', symbol: 'ر.ع', rate: 1, is_base: true, active: true, decimals: 3 } as never;

const row = (over: Partial<Movement> = {}): Movement => ({
    key: 'recorded:1', kind: 'recorded', category: 'dues', state: null, at: '2027-02-10 10:00', name: 'خاتم', quantity: 1, amount: 9.6, effect: 9.6, balance: 9.6,
    order: 'INV-000011', sheet: null, expense: null, entry: null, transaction: null, why: null, who: null,
    order_url: '/admin/orders/INV-000011', entry_url: null, transaction_url: null, ...over,
});

const page = (rows: Movement[], over: Partial<MovementsPage> = {}): MovementsPage => ({
    rows, page: 1, last_page: 1, total: rows.length, per_page: 25, opening: 3, closing: 12.6,
    balance: { unsettled: 12.6, pieces_unsettled: 0, sheets_unpaid: 0, pieces_unpaid: 0, credit: 0, claims: 0, net: 12.6 }, groups: {},
    categories: {
        cash: { count: 0, effect: 0, in: 0, out: 0 }, dues: { count: 1, effect: 9.6, in: 9.6, out: 0 },
        expense: { count: 0, effect: 0, in: 0, out: 0 }, non_cash: { count: 0, effect: 0, in: 0, out: 0 },
    }, ...over,
});

let calls: string[] = [];
let respond: (url: string) => unknown;

beforeEach(() => {
    calls = [];
    respond = () => page([row()]);
    vi.stubGlobal('fetch', vi.fn(async (url: string) => {
        calls.push(url);
        const body = respond(url);

        return { ok: body !== null, status: body === null ? 500 : 200, json: async () => body } as Response;
    }));
});

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

const open = async (body?: MovementsPage) => {
    if (body) respond = () => body;
    render(<MovementsPanel boutiqueId={5} period="2027-02" currency={currency} />);
    await waitFor(() => expect(calls).toHaveLength(1));
};

describe('حركات البوتيك', () => {
    it('المستحقُّ بفاتورته وأثره ورصيده، ويقول إنّه يُقيَّد مع ورقته', async () => {
        await open();
        const line = (await screen.findByText(KIND.recorded)).closest('tr')!;
        expect(within(line).getByText('INV-000011').closest('a')).toHaveAttribute('href', '/admin/orders/INV-000011');
        expect(line.textContent).toContain('+');
        expect(line.textContent).toContain('9.600');
        expect(within(line).getByText('يُقيَّد مع ورقته')).toBeInTheDocument();
    });

    it('السدادُ بقيده وحركته الماليّة وأثره سالب', async () => {
        await open(page([row({ key: 'pieces_paid:3', kind: 'pieces_paid', category: 'cash', name: 'BQ-2027-02-1', quantity: null, amount: -14.67, effect: -14.67, balance: 0, order: null,
            order_url: null, sheet: 'BQ-2027-02-1', entry: 'JV-9', entry_url: '/admin/finance/journal?q=JV-9', transaction: 'TRX-000004', transaction_url: '/admin/finance/transactions?q=TRX-000004' })]));
        const line = (await screen.findByText(KIND.pieces_paid)).closest('tr')!;
        expect(line.textContent).toContain('−');
        expect(within(line).getByText('TRX-000004').closest('a')).toHaveAttribute('href', '/admin/finance/transactions?q=TRX-000004');
        expect(within(line).getByText(/JV-9/).closest('a')).toHaveAttribute('href', '/admin/finance/journal?q=JV-9');
        expect(within(line).queryByText('يُقيَّد مع ورقته')).toBeNull();
    });

    it('من لا يملك الماليّة يقرأ الرقمَ بلا باب', async () => {
        await open(page([row({ kind: 'sheet', key: 'sheet:2', category: 'expense', order: null, order_url: null, sheet: 'BQ-1', entry: 'JV-3', effect: 0, amount: 9.6 })]));
        const line = (await screen.findByText(KIND.sheet)).closest('tr')!;
        expect(within(line).getByText(/JV-3/).closest('a')).toBeNull();
        // صدورُ الورقة لا يغيّر ما للبوتيك
        expect(within(line).getByText('—')).toBeInTheDocument();
    });

    it('حالُ المرتجع تُقال: من يتحمّله', async () => {
        await open(page([row({ kind: 'decided', key: 'decided:2', category: 'expense', state: 'shop', effect: 0, why: 'تلف عند المتجر' })]));
        expect(await screen.findByText('على المتجر')).toBeInTheDocument();
        expect(screen.getByText('تلف عند المتجر')).toBeInTheDocument();
    });

    it('المُرشِّحُ والصفحةُ طلبان للخادم، والمُرشِّحُ يعود إلى الأولى', async () => {
        // `route` هنا يكتب القيمَ بترتيبها: البوتيك، الشهر، الصفحة، المُرشِّح
        respond = (url) => page([row()], { page: url.includes('/2027-02/2') ? 2 : 1, last_page: 2, total: 30 });
        render(<MovementsPanel boutiqueId={5} period="2027-02" currency={currency} />);
        fireEvent.click(await screen.findByRole('button', { name: 'التالي' }));
        await waitFor(() => expect(calls.at(-1)).toMatch(/2027-02\/2$/));
        fireEvent.change(screen.getByLabelText('نوع الحركة'), { target: { value: 'payments' } });
        await waitFor(() => expect(calls.at(-1)).toMatch(/2027-02\/1\/payments$/));
    });

    it('الرصيدُ قبل الشهر وآخرَه والآن من الخادم', async () => {
        await open();
        expect(await screen.findByText('قبل هذا الشهر')).toBeInTheDocument();
        const panel = screen.getByTestId('boutique-register');
        for (const v of ['3.000', '12.600']) expect(panel.textContent).toContain(v);
    });

    it('شهرٌ بلا حركة، وخادمٌ لم يجب: يُقال', async () => {
        await open(page([], { total: 0 }));
        expect(await screen.findByText('لا حركات في هذا الشهر')).toBeInTheDocument();
        cleanup();
        calls = [];
        respond = () => null;
        render(<MovementsPanel boutiqueId={5} period="2027-02" currency={currency} />);
        expect(await screen.findByText(/تعذّر تحميل الحركات/)).toBeInTheDocument();
    });

    it('كلُّ سطرٍ يقول صنفَه: مالٌ تحرّك، أو مستحقٌّ بلا مال، أو مصروف، أو مقاصّة', async () => {
        await open(page([
            row(),
            row({ key: 'paid:4', kind: 'paid', category: 'cash', amount: -9.6, effect: -9.6, order: null, order_url: null, sheet: 'BQ-1' }),
            row({ key: 'offset:5', kind: 'offset', category: 'non_cash', amount: 2, effect: 0, order: null, order_url: null, sheet: 'BQ-1' }),
            row({ key: 'sheet:6', kind: 'sheet', category: 'expense', amount: 9.6, effect: 0, order: null, order_url: null, sheet: 'BQ-1' }),
        ]));
        for (const [kind, cat] of [['recorded', 'dues'], ['paid', 'cash'], ['offset', 'non_cash'], ['sheet', 'expense']] as const) {
            const line = (await screen.findByText(KIND[kind])).closest('tr')!;
            expect(within(line).getByText(CATEGORY[cat])).toHaveAttribute('data-category', cat);
        }
    });

    it('مجموعُ كلّ صنفٍ وحده، والمالُ داخلٌ وخارج — من الخادم', async () => {
        await open(page([row()], {
            categories: {
                cash: { count: 3, effect: -4.1, in: 5.5, out: 9.6 }, dues: { count: 2, effect: 21.6, in: 21.6, out: 0 },
                expense: { count: 1, effect: 0, in: 0, out: 0 }, non_cash: { count: 2, effect: -2, in: 0, out: 2 },
            },
        }));
        const strip = await screen.findByTestId('movement-categories');
        const cash = strip.querySelector('[data-category="cash"]')!;
        expect(cash.textContent).toContain('5.500');
        expect(cash.textContent).toContain('9.600');
        expect(strip.querySelector('[data-category="dues"]')!.textContent).toContain('+');
        expect(strip.querySelector('[data-category="dues"]')!.textContent).toContain('21.600');
        expect(strip.querySelector('[data-category="non_cash"]')!.textContent).toContain('−');
    });
});
