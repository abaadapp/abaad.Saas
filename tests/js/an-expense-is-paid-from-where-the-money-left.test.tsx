import { useState } from 'react';
import { fireEvent, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { pageProps } from './setup';

/** ما أرسلته نماذجُ الصفحة — `useForm` يُبدَّل بما يسجّل ولا يرسل */
const posted: { url: string; data: Record<string, unknown> }[] = [];

vi.mock('@inertiajs/react', async () => {
    const actual = await vi.importActual<Record<string, unknown>>('@inertiajs/react');

    const useForm = (initial: Record<string, unknown>) => {
        const [data, setState] = useState(initial);
        const setData = (key: unknown, value?: unknown) =>
            setState((d) =>
                typeof key === 'function'
                    ? (key as (d: Record<string, unknown>) => Record<string, unknown>)(d)
                    : typeof key === 'object'
                      ? (key as Record<string, unknown>)
                      : { ...d, [key as string]: value },
            );

        return {
            data,
            errors: {} as Record<string, string>,
            processing: false,
            setData,
            post: (url: string) => posted.push({ url, data }),
            put: () => {},
            delete: () => {},
            reset: () => {},
            clearErrors: () => {},
            transform: () => {},
        };
    };

    return {
        ...actual,
        usePage: () => ({ props: pageProps, url: '/', component: 'Test' }),
        Head: () => null,
        useForm,
    };
});

vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

const page = await import('@/Pages/Admin/Expenses/Index');
const ExpensesIndex = page.default;

/**
 * «تسجيل السداد» يسأل من أين خرج المال — ويُرسل الجواب.
 *
 * كان الزرُّ يرسل طلبًا فارغًا، فمصروفُ ورقة البوتيك (يولد «نقدي») يُرحَّل
 * من الصندوق ولو حُوّل من البنك. فالسؤالُ يُفتح على ما كُتب، والجوابُ
 * يصل الخادمَ في `method`.
 */
const row = {
    id: 7,
    reference: 'BST-0001',
    due_date: '2027-03-09',
    type: 'تسوية بوتيكات',
    amount: 80,
    status: 'غير مدفوع',
    method: 'نقدي',
    attachment: null,
    attachment_name: null,
    description: 'تسوية بوتيك لمى عن 2027-02',
    scope: 'business',
    branch_id: null,
    branch: null,
    allocations: [],
    correction: { crosses: false, entry_date: null, status: null, original_allowed: false, closed_through: null },
};

beforeEach(() => {
    Object.assign(pageProps, {
        translations: {},
        expenses: [row],
        pagination: { current_page: 1, last_page: 1, from: 1, to: 1, total: 1, prev_page_url: null, next_page_url: null },
        types: [],
        accountOptions: [],
        filters: { q: null, type: null, status: null, tab: null },
        sorts: [],
        totalAmount: 80,
        totalCount: 1,
        unpaidAmount: 80,
        unpaidCount: 1,
        dueSoonCount: 0,
        overdueCount: 0,
        month: '',
        monthTotal: null,
        monthUnpaid: null,
        monthCount: null,
        months: ['2027-03'],
        today: '2027-03-02',
        branches: [{ id: 1, name: 'الرئيسي' }],
    });
});

describe('تسجيلُ سدادِ مصروف', () => {
    it('يُفتح على الطريقة المكتوبة ويُرسل ما اختير', async () => {
        posted.length = 0;
        const user = userEvent.setup();
        render(<ExpensesIndex />);

        await user.click(screen.getByRole('button', { name: 'إجراءات' }));
        await user.click(await screen.findByRole('menuitem', { name: /تسجيل السداد/ }));
        const dialog = screen.getByTestId('pay-dialog');

        const select = dialog.querySelector('select')!;
        expect(select).toHaveValue('نقدي');
        expect(within(dialog).getByText('النقد يُقيَّد على الصندوق، والبطاقة والتحويل على البنك.')).toBeInTheDocument();

        fireEvent.change(select, { target: { value: 'تحويل بنكي' } });
        await user.click(within(dialog).getByRole('button', { name: /تسجيل السداد/ }));

        expect(posted).toEqual([{ url: '/admin.expenses.paid/7', data: { method: 'تحويل بنكي' } }]);
    });

    it('يُفتح على النقد حين لا تُعرف الطريقة المكتوبة', () => {
        expect(page.payMethodFor('تحويل بنكي')).toBe('تحويل بنكي');
        expect(page.payMethodFor('بطاقة')).toBe('بطاقة');
        expect(page.payMethodFor(null)).toBe('نقدي');
        expect(page.payMethodFor('قيد داخلي')).toBe('نقدي');
        expect(page.PAY_METHODS.map((o) => o.value)).toEqual(['نقدي', 'بطاقة', 'تحويل بنكي']);
    });
});
