import { fireEvent, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { pageProps } from './setup';

vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

const ExpensesIndex = (await import('@/Pages/Admin/Expenses/Index')).default;

/**
 * تغييرُ نطاق مصروفٍ من شهرٍ مضى — يُسأل عنه حين يتبدّل فرعُ القيد، ولا تنهار الشاشة.
 *
 * ═══ ما ظهر في متصفّحٍ حقيقيّ ═══
 *
 * اختيارُ «فرع محدد» ثمّ فرعٍ لمصروفٍ من سبتمبر أسقط الشاشة كلَّها:
 * «Cannot read properties of undefined (reading 'trim')». نموذجُ النطاق يحمل
 * اختيارَ تصحيح الفترة وسببَه، وحقولُ النطاق كانت **تستبدل** النموذجَ بنفسها
 * (`setData(v)`) فتُسقطهما — ثمّ يقرأ `correctionReady` سببًا لا وجود له.
 *
 * ولم يره أيُّ اختبار: المكوّنُ يُختبر وحده، والنافذةُ لم تُركَّب قطّ مع
 * `useForm` الحقيقيّ. فهنا تُركَّب الصفحةُ نفسُها بـ`useForm` كما هو.
 */
const crossing = { crosses: true, entry_date: '2026-09-20', status: 'open', original_allowed: true, closed_through: '2026-08-31' };

const row = {
    id: 3,
    reference: 'EXP-1003',
    due_date: null,
    type: 'صيانة سبتمبر',
    amount: 80,
    status: 'مدفوع',
    attachment: null,
    attachment_name: null,
    description: null,
    scope: 'business',
    branch_id: null,
    branch: null,
    allocations: [],
    correction: crossing,
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
        unpaidAmount: 0,
        unpaidCount: 0,
        dueSoonCount: 0,
        overdueCount: 0,
        month: '',
        monthTotal: null,
        monthUnpaid: null,
        monthCount: null,
        months: ['2026-10', '2026-09'],
        today: '2026-10-23',
        branches: [
            { id: 1, name: 'الرئيسي' },
            { id: 2, name: 'صحار' },
        ],
    });
});

describe('نافذةُ نطاق مصروفٍ من شهرٍ مضى', () => {
    it('تسأل حين يتبدّل فرعُ القيد، ولا تنهار', async () => {
        const user = userEvent.setup();
        render(<ExpensesIndex />);

        await user.click(screen.getByRole('button', { name: 'إجراءات' }));
        await user.click(await screen.findByRole('menuitem', { name: /نطاق المصروف/ }));
        const dialog = screen.getByRole('dialog');
        expect(within(dialog).queryByTestId('period-correction')).not.toBeInTheDocument();

        await user.click(within(dialog).getByRole('radio', { name: 'فرع محدد' }));
        fireEvent.change(dialog.querySelector('select')!, { target: { value: '2' } });

        // كانت الشاشةُ تنهار هنا
        expect(within(dialog).getByTestId('period-correction')).toBeInTheDocument();
        const save = within(dialog).getByRole('button', { name: /حفظ النطاق/ });
        expect(save).toBeDisabled();

        await user.click(within(dialog).getByLabelText(/في الفترة الحاليّة/));
        fireEvent.change(within(dialog).getByRole('textbox'), { target: { value: 'نُقل إلى صحار' } });
        expect(save).toBeEnabled();

        // وتبديلُ الفرع بعد كتابة السبب لا يمحو ما كُتب — الاستبدالُ كان يُسقطه
        fireEvent.change(dialog.querySelector('select')!, { target: { value: '1' } });
        expect(within(dialog).getByRole('textbox')).toHaveValue('نُقل إلى صحار');
        expect(within(dialog).getByLabelText(/في الفترة الحاليّة/)).toBeChecked();
        expect(save).toBeEnabled();

        // والعودةُ إلى النشاط كلِّه لا تبدّل فرعَ القيد — فلا سؤال
        await user.click(within(dialog).getByRole('radio', { name: 'النشاط بالكامل' }));
        expect(within(dialog).queryByTestId('period-correction')).not.toBeInTheDocument();
    });

    /** وتبديلُ نوع النطاق بعد كتابة السبب — والقيدُ ما زال يتبدّل — لا يمحو ما كُتب */
    it('يحفظ الاختيارَ والسببَ حين يتبدّل نوعُ النطاق', async () => {
        Object.assign(pageProps, { expenses: [{ ...row, scope: 'branch', branch_id: 1, branch: 'الرئيسي' }] });
        const user = userEvent.setup();
        render(<ExpensesIndex />);

        await user.click(screen.getByRole('button', { name: 'إجراءات' }));
        await user.click(await screen.findByRole('menuitem', { name: /نطاق المصروف/ }));
        const dialog = screen.getByRole('dialog');

        await user.click(within(dialog).getByRole('radio', { name: 'النشاط بالكامل' }));
        await user.click(within(dialog).getByLabelText(/في الفترة الحاليّة/));
        fireEvent.change(within(within(dialog).getByTestId('period-correction')).getByRole('textbox'), { target: { value: 'للنشاط كلِّه' } });

        await user.click(within(dialog).getByRole('radio', { name: 'موزع على عدة فروع' }));

        const asked = within(dialog).getByTestId('period-correction');
        expect(within(asked).getByRole('textbox')).toHaveValue('للنشاط كلِّه');
        expect(within(dialog).getByLabelText(/في الفترة الحاليّة/)).toBeChecked();
    });
});
