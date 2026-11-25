import { fireEvent, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { pageProps } from './setup';

vi.mock('@/Layouts/AdminLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <div>{children}</div> }));

const { router } = await import('@inertiajs/react');
const { default: HoldsPanel } = await import('@/Pages/Admin/Boutiques/HoldsPanel');
const { default: ExpensesIndex } = await import('@/Pages/Admin/Expenses/Index');
const { default: Dues } = await import('@/Pages/Admin/Finance/Dues');

type Hold = Parameters<typeof HoldsPanel>[0]['holds'][number];
const currency = { code: 'OMR', symbol: 'ر.ع', decimals: 3 } as never;
const hold = (over: Partial<Hold> = {}): Hold => ({
    id: 4, sheet: 'BQ-2027-02-1', invoice_id: 9, invoice: 'CINV-9', name: 'قطعة', amount: 9.6, held: 9.6, why: 'أُلغي سند القبض P-1', at: '2027-02-10 10:00',
    state: 'held', owed: 0, closed_as: null, closed_at: null, note: null, paid_amount: null, paid_at: null, ...over,
});
const posts = () => (router.post as unknown as { mock: { calls: [string, Record<string, unknown>][] } }).mock.calls.map(([url, data]) => ({ url, data }));

beforeEach(() => {
    for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    Object.assign(pageProps, { translations: {}, context: { currency }, auth: { abilities: ['expenses', 'finance'], mayActions: [] } });
    (router.post as unknown as { mockClear: () => void }).mockClear();
});

describe('تحصيلٌ أُلغي بعد صدور الورقة', () => {
    it('لا لوحةَ حين لا شيء', () => {
        const { container } = render(<HoldsPanel boutiqueId={1} holds={[]} currency={currency} />);
        expect(container).toBeEmptyDOMElement();
    });

    it('المعلَّقُ يُفرج عنه بسببٍ مكتوب — ولا يُرسَل بلا سبب', async () => {
        render(<HoldsPanel boutiqueId={1} holds={[hold()]} currency={currency} canPay />);
        const row = screen.getByTestId('hold-4');
        expect(row).toHaveTextContent('معلّق — لا يُسدَّد مع الورقة');
        expect(row).toHaveTextContent('9.600');
        const button = within(row).getByRole('button', { name: 'أفرج عن السداد' });
        expect(button).toBeDisabled();
        await userEvent.type(within(row).getByLabelText('سبب القرار'), 'ضمنه العميل');
        fireEvent.click(button);
        expect(posts()).toEqual([{ url: '/admin.boutiques.holds.release/1/4', data: { why: 'ضمنه العميل' } }]);
    });

    it('من لا يملك السداد يقرأ ولا يُفرج ولا يسدّد', () => {
        render(<HoldsPanel boutiqueId={1} canPay={false} currency={currency} holds={[hold(), hold({ id: 5, state: 'payable', closed_at: 'x', owed: 9.6 })]} />);
        expect(screen.queryByRole('button', { name: 'أفرج عن السداد' })).toBeNull();
        expect(screen.queryByRole('button', { name: /^سدّد/ })).toBeNull();
        expect(screen.getByTestId('hold-5')).toHaveTextContent('فُكّ تعليقه — ينتظر السداد');
    });

    it('ما سُدّد قبل تحصيله يُراجَع ولا يُفرج عنه', async () => {
        render(<HoldsPanel boutiqueId={1} holds={[hold({ state: 'paid_before', held: 0 })]} currency={currency} canPay={false} />);
        const row = screen.getByTestId('hold-4');
        expect(row).toHaveTextContent('سُدّد قبل تحصيله — للمراجعة');
        await userEvent.type(within(row).getByLabelText('سبب القرار'), 'يُخصم لاحقًا');
        fireEvent.click(within(row).getByRole('button', { name: 'تمت المراجعة' }));
        expect(posts().at(-1)?.url).toBe('/admin.boutiques.holds.review/1/4');
        expect(screen.queryByRole('button', { name: 'أفرج عن السداد' })).toBeNull();
    });

    it('ما فُكّ تعليقُه يُسدَّد بطريقته', () => {
        render(<HoldsPanel boutiqueId={1} holds={[hold({ state: 'payable', closed_as: 'recollected', closed_at: 'x', owed: 9.6 })]} currency={currency} canPay />);
        fireEvent.change(screen.getByTestId('hold-4').querySelector('select')!, { target: { value: 'تحويل' } });
        fireEvent.click(screen.getByRole('button', { name: /سدّد 9\.600/ }));
        expect(posts().at(-1)).toEqual({ url: '/admin.boutiques.holds.pay/1/4', data: { method: 'تحويل' } });
    });

    it('المحسومُ سجلٌّ يُقرأ: سببُه ومبلغُه بلا زرّ', () => {
        render(<HoldsPanel boutiqueId={1} holds={[hold({ state: 'paid', closed_as: 'released', closed_at: 'x', note: 'قرار 3', paid_amount: 9.6, paid_at: '2027-02-12' })]} currency={currency} canPay />);
        const row = screen.getByTestId('hold-4');
        expect(row).toHaveTextContent('السبب: قرار 3');
        expect(row).toHaveTextContent('سُدّد 9.600');
        expect(within(row).queryByRole('button')).toBeNull();
    });
});

describe('سؤالُ السداد والمبالغ المستحقة', () => {
    it('نافذةُ السداد تقول ما يخرج الآن وما يبقى معلَّقًا — ولا تقوله لمصروفٍ عاديّ', async () => {
        const row = {
            id: 7, reference: 'BQ-2027-02-1', due_date: null, type: 'تسوية بوتيكات', amount: 19.2, status: 'غير مدفوع', payable: true, hold: { held: 9.6, now: 9.6 }, method: 'نقدي', attachment: null,
            attachment_name: null, description: '', scope: 'business', branch_id: null, branch: null, allocations: [], correction: { crosses: false, entry_date: null, status: null, original_allowed: false, closed_through: null },
        };
        Object.assign(pageProps, {
            expenses: [row, { ...row, id: 8, reference: 'EXP-1', type: 'إيجار', hold: null }], pagination: { current_page: 1, last_page: 1, from: 1, to: 2, total: 2, prev_page_url: null, next_page_url: null }, types: [], accountOptions: [],
            filters: { q: null, type: null, status: null, tab: null }, sorts: [], totalAmount: 0, totalCount: 2, unpaidAmount: 0, unpaidCount: 2, dueSoonCount: 0, overdueCount: 0, month: '', monthTotal: null, monthUnpaid: null, monthCount: null, months: ['2027-02'], today: '2027-02-10', branches: [],
        });
        const user = userEvent.setup();
        render(<ExpensesIndex />);
        await user.click(screen.getAllByRole('button', { name: 'إجراءات' })[0]);
        await user.click(await screen.findByRole('menuitem', { name: /تسجيل السداد/ }));
        expect(screen.getByTestId('pay-hold')).toHaveTextContent('يُسدَّد الآن 9.600');
        expect(screen.getByTestId('pay-hold')).toHaveTextContent('معلّق 9.600');
        await user.click(within(screen.getByTestId('pay-dialog')).getByRole('button', { name: 'إلغاء' }));
        await user.click(screen.getAllByRole('button', { name: 'إجراءات' })[1]);
        await user.click(await screen.findByRole('menuitem', { name: /تسجيل السداد/ }));
        expect(screen.queryByTestId('pay-hold')).toBeNull();
    });

    it('المعلَّقُ من ورقةٍ سُدّدت دونه في «المبالغ المستحقة»', () => {
        Object.assign(pageProps, { expenses: [], invoices: [], payroll: [], totals: { expenses: 9.6, invoices: 0, payroll: 0, total: 9.6, overdue: 0 },
            holds: [{ id: 4, sheet: 'BQ-2027-02-1', boutique: 'لمى', name: 'قطعة', amount: 9.6, held: true }] });
        render(<Dues />);
        const card = screen.getByTestId('dues-holds');
        expect(card).toHaveTextContent('معلّق للمراجعة');
        expect(card).toHaveTextContent('BQ-2027-02-1');
        expect(screen.queryByText('لا شيء مستحقّ الآن')).toBeNull();
    });
});
