import { useState } from 'react';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { router } from '@inertiajs/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import PermissionGroups, { type PermissionGroup } from '@/Pages/Admin/Employees/partials/PermissionGroups';
import CorrectionRequests, { type CorrectionRequest } from '@/Pages/Admin/CustomerInvoices/partials/CorrectionRequests';
import ExportMenu from '@/Components/ExportMenu';
import { pageProps } from './setup';

/**
 * شاشةُ الصلاحيات: كلُّ قسمٍ وتحته ما يُفعل فيه — ومن أين جاءت كلُّ صلاحية.
 *
 * والخادمُ هو الحارس (`EveryOperationIsGrantedByItsOwnNameTest`)؛ وهذا يحرس
 * أنّ الشاشة تقول ما سيقوله: «الكل» يمنح ما يملك الفاعلُ منحَه وحده، و«لا
 * شيء» لا يرفع القسمَ الذي يفتح مجموعةً أخرى، والتخصيصُ يُقرأ فرقًا عن الوظيفة.
 */

const GROUPS: PermissionGroup[] = [
    { key: 'customers', label: 'العملاء', view: 'customers', opens_with: null, actions: ['customer.create', 'customer.delete', 'customer.export'] },
    { key: 'orders', label: 'المبيعات', view: 'orders', opens_with: null, actions: ['order.export'] },
    {
        key: 'customerInvoices',
        label: 'فواتير العملاء',
        view: null,
        opens_with: 'orders',
        actions: ['customerInvoice.editPaid', 'financialCorrection.approve'],
    },
];
const SECTIONS = { customers: 'العملاء', orders: 'المبيعات' };
const ACTIONS = {
    'customer.create': 'إضافة عميل',
    'customer.delete': 'حذف عميل',
    'customer.export': 'تصدير العملاء',
    'order.export': 'تصدير المبيعات',
    'customerInvoice.editPaid': 'تعديل مدفوعة',
    'financialCorrection.approve': 'اعتماد تصحيح مالي',
};

function Harness({
    initial,
    roleGrants = [],
    manual = true,
    live = true,
    grantable,
}: {
    initial: string[];
    roleGrants?: string[];
    manual?: boolean;
    live?: boolean;
    grantable?: string[];
}) {
    const [shown, setShown] = useState(initial);

    return (
        <>
            <PermissionGroups
                groups={GROUPS}
                sections={SECTIONS}
                actions={ACTIONS}
                hints={{ 'customer.delete': 'الحذف يخفي العميل ويُستعاد.' }}
                shown={shown}
                roleGrants={roleGrants}
                manual={manual}
                live={live}
                mayGrant={(k) => !grantable || grantable.includes(k)}
                onChange={setShown}
            />
            <output data-testid="list">{[...shown].sort().join(',')}</output>
        </>
    );
}

const list = () => screen.getByTestId('list').textContent;
const box = (key: string) => screen.getByTestId(`perm-${key}`) as HTMLInputElement;

describe('صلاحياتُ كلّ قسم', () => {
    it('«عرض» القسم صفٌّ أوّل، وتحته عملياتُه بأسمائها', () => {
        render(<Harness initial={['customers']} />);

        const group = within(screen.getByTestId('perm-group-customers'));
        expect(group.getByText('عرض: العملاء')).toBeTruthy();
        expect(group.getByText('إضافة عميل')).toBeTruthy();
        expect(group.getByTestId('action-hint-customer.delete').textContent).toContain('يخفي العميل');
        // والعرضُ وحده لا يؤشّر الإضافةَ ولا الحذف
        expect(box('customers').checked).toBe(true);
        expect(box('customer.create').checked).toBe(false);
        expect(box('customer.delete').checked).toBe(false);
    });

    it('«الكل» يمنح القسمَ وعملياته، و«لا شيء» يرفعها كلَّها', async () => {
        render(<Harness initial={[]} />);

        await userEvent.click(screen.getByTestId('perm-all-customers'));
        expect(list()).toBe('customer.create,customer.delete,customer.export,customers');

        await userEvent.click(screen.getByTestId('perm-none-customers'));
        expect(list()).toBe('');
    });

    it('«الكل» لا يمنح ما لا يملك الفاعلُ منحَه — ويبقى مربّعُه معطّلًا', async () => {
        render(<Harness initial={[]} grantable={['customers', 'customer.create']} />);

        await userEvent.click(screen.getByTestId('perm-all-customers'));
        expect(list()).toBe('customer.create,customers');
        expect(box('customer.delete').disabled).toBe(true);
    });

    it('مجموعةٌ يفتحها قسمٌ آخر: «الكل» يفتحه معها، و«لا شيء» لا يرفعه عن غيرها', async () => {
        render(<Harness initial={['orders', 'order.export']} />);

        await userEvent.click(screen.getByTestId('perm-all-customerInvoices'));
        expect(list()).toBe('customerInvoice.editPaid,financialCorrection.approve,order.export,orders');

        await userEvent.click(screen.getByTestId('perm-none-customerInvoices'));
        expect(list()).toBe('order.export,orders');
    });

    it('فعلٌ مؤشَّرٌ وقسمُه مغلق يُقال إنّه لا يعمل', () => {
        render(<Harness initial={['customer.create']} />);

        expect(screen.getByTestId('perm-stranded-customers').textContent).toContain('لا تعمل');
        expect(screen.queryByTestId('perm-stranded-orders')).toBeNull();
    });

    it('التخصيصُ يُقرأ فرقًا عن الوظيفة: ما أُضيف وما نُزع', () => {
        render(<Harness initial={['customers', 'customer.delete']} roleGrants={['customers', 'customer.create']} />);

        expect(screen.getByTestId('perm-badge-customer.delete').textContent).toBe('أُضيفت يدويًا');
        expect(screen.getByTestId('perm-badge-customer.create').textContent).toBe('نُزعت عن الوظيفة');
        expect(screen.queryByTestId('perm-badge-customers')).toBeNull();
    });

    it('واتّباعُ الوظيفة يعرضها «من الوظيفة» بلا مقابض', () => {
        render(<Harness initial={['customers', 'customer.create']} roleGrants={['customers', 'customer.create']} manual={false} live={false} />);

        expect(screen.getByTestId('perm-badge-customer.create').textContent).toBe('من الوظيفة');
        expect(box('customer.create').disabled).toBe(true);
        expect(screen.queryByTestId('perm-all-customers')).toBeNull();
    });

    it('«الصلاحيات الفعلية» تُخفي ما لم يُمنح، والعدّادُ يقول كم يملك', async () => {
        render(<Harness initial={['customers', 'customer.export']} />);

        expect(screen.getByTestId('perm-summary').textContent).toContain('2');
        await userEvent.click(screen.getByTestId('perm-only-granted'));

        expect(screen.queryByTestId('perm-customer.create')).toBeNull();
        expect(screen.getByTestId('perm-customer.export')).toBeTruthy();
        expect(screen.queryByTestId('perm-group-customerInvoices')).toBeNull();
    });
});

const pending = (over: Partial<CorrectionRequest> = {}): CorrectionRequest => ({
    id: 7,
    status: 'pending',
    reason: 'تصحيح الكمية',
    invoice: { id: 3, number: 'INV-0003' },
    summary: {
        kind: 'issued',
        number: 'INV-0003',
        money: { total_before: 105, total_after: 126, difference: 21, tax_before: 5, tax_after: 6, paid: 0, outstanding_after: 126, customer_credit: 0 },
        changes: [],
        customer_changed: false,
        period: { mode: 'same', reversal_date: '2025-09-10', entry_date: '2025-09-10' },
    },
    requested_by: 'سالم',
    requested_at: '2025-09-20 12:00',
    decided_by: null,
    decided_at: null,
    decision_note: null,
    revision: null,
    may_decide: false,
    may_withdraw: false,
    ...over,
});

const m = (v: number) => v.toFixed(3);

describe('طلبُ التصحيح الماليّ', () => {
    afterEach(() => vi.mocked(router.post).mockClear());

    it('المعتمدُ يرى «اعتماد» و«رفض»، والرفضُ لا يُرسل بلا سبب', async () => {
        const { unmount } = render(<CorrectionRequests requests={[pending({ may_decide: true })]} m={m} />);

        await userEvent.click(screen.getByTestId('correction-approve'));
        expect(router.post).toHaveBeenCalledWith('/admin.customerInvoices.corrections.approve/7', {}, expect.anything());
        // والزرّان يُعطَّلان حتى يعود الطلب — فلا يُرسل الاعتمادُ مرّتين
        expect((screen.getByTestId('correction-approve') as HTMLButtonElement).disabled).toBe(true);
        unmount();

        render(<CorrectionRequests requests={[pending({ may_decide: true })]} m={m} />);
        await userEvent.click(screen.getByTestId('correction-reject'));
        const confirm = screen.getByTestId('correction-reject-confirm') as HTMLButtonElement;
        expect(confirm.disabled).toBe(true);

        await userEvent.type(screen.getByTestId('correction-reject-reason'), 'الكمية صحيحة');
        await userEvent.click(confirm);
        expect(router.post).toHaveBeenLastCalledWith('/admin.customerInvoices.corrections.reject/7', { reason: 'الكمية صحيحة' }, expect.anything());
    });

    it('مقدّمُ الطلب لا يرى «اعتماد» — يرى «سحب»', () => {
        render(<CorrectionRequests requests={[pending({ may_withdraw: true })]} m={m} />);

        expect(screen.queryByTestId('correction-approve')).toBeNull();
        expect(screen.getByTestId('correction-withdraw')).toBeTruthy();
    });

    it('ما بُتّ فيه يُقرأ ولا يُضغط', () => {
        render(<CorrectionRequests requests={[pending({ status: 'stale', decided_by: 'منى', decided_at: '2025-09-21 09:00', may_decide: true })]} m={m} />);

        expect(screen.getByTestId('correction-status').textContent).toContain('تغيّرت الفاتورة');
        expect(screen.getByTestId('correction-decision').textContent).toContain('منى');
        expect(screen.queryByTestId('correction-approve')).toBeNull();
    });
});

describe('قائمةُ التصدير', () => {
    afterEach(() => {
        (pageProps.auth as Record<string, unknown>).mayActions = [];
    });

    it('تُخفى عمّن يفتح القسمَ ولا يملك تصديرَه، وتظهر لمن يملكه', () => {
        const { unmount } = render(<ExportMenu action="customer.export" csv="/x.csv" />);
        expect(screen.queryByText('تصدير')).toBeNull();
        unmount();

        (pageProps.auth as Record<string, unknown>).mayActions = ['customer.export'];
        render(<ExportMenu action="customer.export" csv="/x.csv" />);
        expect(screen.getByText('تصدير')).toBeTruthy();
    });
});
