import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { router } from '@inertiajs/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { pageProps } from './setup';

/**
 * تعديلُ فاتورة العميل — من جهة الشاشة.
 *
 * ═══ ما يُحرس ═══
 *
 *   - البندُ القائمُ يُرسَل بمعرّفه، وإضافةُ الكتالوج القائمةُ لا يُكتب اسمُها ولا
 *     ثمنُها، وحذفُ الصنف يحذف إضافاتِه معه.
 *   - لا حفظَ قبل المعاينة، ولا بعدها حين تمنع الخطّة أو يغيب السبب، وتغييرُ
 *     النموذج بعد المعاينة يطلب معاينةً جديدة.
 *   - المعاينةُ تقول الفائضَ رصيدًا للعميل بلا ردّ مال، وتسأل عن موضع التصحيح
 *     وتعطّل «تاريخ القيد» لمن لا يملكه.
 *   - المولودةُ من طلبٍ: بنودُها مقفلةٌ ولا تُرسَل.
 *   - صفحةُ الفاتورة: الزرُّ لمن يملكه وحده، والسجلُّ لمن يقرؤه.
 *
 * والخادمُ يحرس ما يقع (`AnInvoiceIsCorrectedAfterItIsWrittenTest`).
 */

vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));
vi.mock('@/Components/PaperFrame', () => ({ default: () => null }));

const Edit = await import('@/Pages/Admin/CustomerInvoices/Edit');
const Show = (await import('@/Pages/Admin/CustomerInvoices/Show')).default;
const { default: CustomerInvoiceEdit, fromItems, removeLine, linesPayload } = Edit;

const items = [
    { item_id: 11, parent_item_id: null, product_id: 3, addon_id: null, description: 'بوكيه', quantity: 1, unit_price: 100, discount: 0, tax_rate: 5, line_total: 105, details: ['ملاحظة: أحمر'] },
    { item_id: 12, parent_item_id: 11, product_id: null, addon_id: 4, description: 'شوكولاتة', quantity: 1, unit_price: 3, discount: 0, tax_rate: 5, line_total: 3.15, details: [] },
];

const invoice = (over: Record<string, unknown> = {}) => ({
    id: 9, number: 'CINV-000009', status: 'صادرة', state: 'مدفوعة', kind: 'paid', revision: 2, from_order: false, orders: [],
    customer_id: 7, issued_at: '2026-10-20', due_at: null, payment_terms_days: null, branch_id: null,
    po_number: null, contract_number: null, external_reference: null, department: null, cost_center: null, attention_to: null,
    notes: null, internal_notes: null, subtotal: 103, discount_total: 0, tax_total: 5.15, total: 108.15,
    paid: 108.15, credited: 0, outstanding: 0, items, ...over,
});

const plan = (over: Record<string, unknown> = {}) => ({
    changed: true, kind: 'paid', changes: [], lines: [{ change: 'changed', id: 11, before: { description: 'بوكيه', quantity: 1, unit_price: 100, discount: 0, tax_rate: 5 }, after: { description: 'بوكيه', quantity: 1, unit_price: 80, discount: 0, tax_rate: 5 } }],
    money: { total_before: 108.15, total_after: 87.15, tax_before: 5.15, tax_after: 4.15, difference: -21, tax_difference: -1, paid: 108.15, credited: 0, outstanding_before: 0, outstanding_after: 0, customer_credit: 21 },
    stock: { moves: false, why: 'الفاتورة اليدوية لا تُخرج بضاعة من المخزون ولا تُقيّد تكلفة — فتعديل كمياتها لا يحرّك المخزون.' },
    ledger: { repost: true, live_entry_date: '2026-10-20' },
    period: { needs_choice: false, mode: 'same', reversal_date: '2026-10-20', entry_date: '2026-10-20', closed_through: null, original_allowed: false },
    needs_reason: true, missing: [], blockers: [], ...over,
});

const reset = (over: Record<string, unknown> = {}) => {
    for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    Object.assign(pageProps, {
        translations: {}, context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 } },
        auth: { abilities: [], mayActions: [] }, flash: { toast: null, status: null }, errors: {},
        invoice: invoice(), customers: [{ id: 7, name: 'وزارة الثقافة', phone: null, payment_terms_days: null }],
        branches: [], products: [], addons: [], tax_rate: 5, tax_inclusive: false, today: '2026-10-28',
        edit_token: 'tok-edit-1', may: { correct_period: false, owner: false },
        ...over,
    });
};

const answer = (body: unknown, ok = true) => {
    globalThis.fetch = vi.fn(async () => new Response(JSON.stringify(body), { status: ok ? 200 : 422 })) as never;
};

beforeEach(() => {
    reset();
    vi.mocked(router.put).mockClear();
    vi.mocked(router.visit).mockClear();
    answer(plan());
});

describe('بنودُ النموذج', () => {
    it('القائمُ بمعرّفه، وإضافةُ الكتالوج القائمةُ مقفلةُ الاسم والثمن', () => {
        const lines = fromItems(items);
        expect(lines.map((l) => [l.item_id, l.locked])).toEqual([[11, false], [12, true]]);

        const sent = linesPayload(lines);
        expect(sent[0]).toMatchObject({ item_id: 11, product_id: null, addon_id: null, quantity: '1', unit_price: '100' });
        expect(sent[1]).toMatchObject({ item_id: 12, addon_id: null });
    });

    it('حذفُ الصنف يحذف إضافاتِه — وحذفُ الإضافة لا يمسّ صنفَها', () => {
        const lines = fromItems(items);
        expect(removeLine(lines, 'i11')).toEqual([]);
        expect(removeLine(lines, 'i12').map((l) => l.item_id)).toEqual([11]);
    });

    it('البندُ الجديدُ يُرسَل بلا معرّف وبصنفه', () => {
        const fresh = { key: 'n1', item_id: null, parent_item_id: null, product_id: '3', addon_id: '', description: 'بوكيه', quantity: '2', unit_price: '20', discount: '', tax_rate: '', locked: false, details: [] };
        expect(linesPayload([fresh])[0]).toMatchObject({ item_id: null, product_id: '3', addon_id: null, discount: '0', tax_rate: null });
    });
});

describe('شاشةُ التعديل', () => {
    it('لا حفظَ قبل المعاينة — والمعاينةُ تقول الفائضَ رصيدًا بلا ردّ مال', async () => {
        const user = userEvent.setup();
        render(<CustomerInvoiceEdit />);

        expect(screen.getByTestId('edit-save')).toBeDisabled();
        expect(screen.getByTestId('edit-paid-note')).toBeInTheDocument();

        await user.click(screen.getByTestId('edit-preview'));
        await waitFor(() => expect(screen.getByTestId('plan')).toBeInTheDocument());

        const call = vi.mocked(globalThis.fetch).mock.calls[0];
        expect(call[0]).toBe('/admin.customerInvoices.editPreview/9');
        const body = JSON.parse(String((call[1] as RequestInit).body));
        expect(body.items.map((i: { item_id: number }) => i.item_id)).toEqual([11, 12]);
        expect(body.expected_revision).toBe(2);
        expect(body.edit_token).toBe('tok-edit-1');

        expect(screen.getByTestId('plan-credit')).toHaveTextContent('لا يُرد مال تلقائيًا');
        expect(screen.getByTestId('plan-stock')).toHaveTextContent('لا يحرّك المخزون');

        // والسببُ مطلوبٌ للصادرة والمدفوعة
        expect(screen.getByTestId('edit-save')).toBeDisabled();
        await user.type(screen.getByTestId('edit-reason'), 'خصم متفق عليه');
        expect(screen.getByTestId('edit-save')).toBeEnabled();

        await user.click(screen.getByTestId('edit-save'));
        expect(router.put).toHaveBeenCalledTimes(1);
        const [url, payload] = vi.mocked(router.put).mock.calls[0];
        expect(url).toBe('/admin.customerInvoices.update/9');
        expect(payload).toMatchObject({ reason: 'خصم متفق عليه', expected_revision: 2, edit_token: 'tok-edit-1' });
    });

    it('تغييرُ النموذج بعد المعاينة يطلب معاينةً جديدة', async () => {
        const user = userEvent.setup();
        render(<CustomerInvoiceEdit />);
        await user.type(screen.getByTestId('edit-reason'), 'تصحيح');
        await user.click(screen.getByTestId('edit-preview'));
        await waitFor(() => expect(screen.getByTestId('edit-save')).toBeEnabled());

        const qty = within(screen.getAllByTestId('edit-line')[0]).getByTestId('edit-qty');
        await user.clear(qty);
        await user.type(qty, '3');

        expect(screen.getByTestId('edit-save')).toBeDisabled();
        expect(screen.getByTestId('plan-empty')).toHaveTextContent('تغيّر النموذج بعد المعاينة');
    });

    it('ما يمنع يُقال، ولا يُحفظ معه', async () => {
        answer(plan({ blockers: ['على الفاتورة رابطُ دفعٍ لم يُدفع بعد'] }));
        const user = userEvent.setup();
        render(<CustomerInvoiceEdit />);
        await user.type(screen.getByTestId('edit-reason'), 'تصحيح');
        await user.click(screen.getByTestId('edit-preview'));

        await waitFor(() => expect(screen.getByTestId('plan-blockers')).toHaveTextContent('رابطُ دفعٍ'));
        expect(screen.getByTestId('edit-save')).toBeDisabled();
    });

    it('الفترةُ السابقة تُسأل — و«تاريخ القيد» معطَّلٌ لمن لا يملكه', async () => {
        answer(plan({ period: { needs_choice: true, mode: null, reversal_date: null, entry_date: null, closed_through: '2026-09-30', original_allowed: false } }));
        const user = userEvent.setup();
        render(<CustomerInvoiceEdit />);
        await user.type(screen.getByTestId('edit-reason'), 'تصحيح');
        await user.click(screen.getByTestId('edit-preview'));

        const period = await screen.findByTestId('plan-period');
        const [current, original] = within(period).getAllByRole('radio');
        expect(original).toBeDisabled();
        expect(screen.getByTestId('edit-save')).toBeDisabled();

        await user.click(current);
        // اختيارٌ جديد = نموذجٌ جديد: يُعاين قبل أن يُحفظ
        expect(screen.getByTestId('edit-save')).toBeDisabled();
    });

    it('المولودةُ من طلب: بنودُها مقفلةٌ ولا تُرسَل', async () => {
        reset({ invoice: invoice({ from_order: true, kind: 'issued', paid: 0 }) });
        const user = userEvent.setup();
        render(<CustomerInvoiceEdit />);

        expect(screen.getByTestId('edit-from-order')).toBeInTheDocument();
        expect(screen.queryByTestId('edit-add-line')).toBeNull();
        for (const row of screen.getAllByTestId('edit-line')) {
            for (const id of ['edit-qty', 'edit-price', 'edit-discount', 'edit-tax']) {
                expect(within(row).getByTestId(id)).toBeDisabled();
            }
        }

        await user.click(screen.getByTestId('edit-preview'));
        const body = JSON.parse(String((vi.mocked(globalThis.fetch).mock.calls[0][1] as RequestInit).body));
        expect(body.items).toBeNull();
    });
});

describe('صفحةُ الفاتورة', () => {
    const shown = {
        id: 3, number: 'CINV-000003', customer: 'وزارة الثقافة', customer_id: 7, status: 'صادرة', state: 'غير مدفوعة',
        issued_at: '2026-10-19', due_at: null, total: 105, paid: 0, outstanding: 105, days_overdue: 0, revision: 1,
        po_number: null, contract_number: null, external_reference: null, department: null, cost_center: null, attention_to: null,
        subtotal: 100, discount_total: 0, tax_total: 5, notes: null, internal_notes: null, may_read_attachments: false,
        attachments: [], orders: [], cancellation_reason: null,
        items: [{ description: 'باقة', quantity: 2, unit_price: 50, discount: 0, tax_rate: 5, line_total: 105 }],
        payments: [], credit_notes: [],
    };

    const showPage = (over: Record<string, unknown> = {}) => {
        Object.assign(pageProps, {
            invoice: shown, may: { issue: true, cancel: true, credit_note: true, pay: false, payment_link: false },
            bank_accounts: [], methods: ['نقدي'], bank_methods: [], paper: { html: '', size: 'A4' },
            paymentLinks: { ready: false, can: false, can_cancel: false, settings_url: null, links: [] },
            paymentLinkFailure: null, preparation: null, mayEdit: false, revisions: null,
            ...over,
        });
        render(<Show />);
    };

    it('زرُّ التعديل لمن يملكه وحده — ويفتح شاشته', async () => {
        showPage();
        expect(screen.queryByTestId('edit-invoice')).toBeNull();
    });

    it('ومن يملكه يُفتح له', async () => {
        const user = userEvent.setup();
        showPage({ mayEdit: true });
        await user.click(screen.getByTestId('edit-invoice'));
        expect(router.visit).toHaveBeenCalledWith('/admin.customerInvoices.edit/3');
    });

    it('السجلُّ لمن يقرؤه: السببُ والمبلغان ومن عدّل', () => {
        showPage({
            revisions: [{
                revision: 1, kind: 'issued', reason: 'تصحيح الكمية', at: '2026-10-20 10:00', by: 'المالك',
                total_before: 52.5, total_after: 105, tax_before: 2.5, tax_after: 5, paid_at_edit: 0,
                period_mode: 'same', reversal_date: '2026-10-19', entry_date: '2026-10-19', reversal_entry_id: 4, entry_id: 5,
                fields: [], lines: [], customer_credit: 0,
            }],
        });

        const card = screen.getByTestId('invoice-revisions');
        expect(card).toHaveTextContent('تصحيح الكمية');
        expect(card).toHaveTextContent('المالك');
        expect(within(card).getAllByTestId('invoice-revision')).toHaveLength(1);
    });

    it('ولمن لا يقرؤه: لا شيء', () => {
        showPage({ revisions: null });
        expect(screen.queryByTestId('invoice-revisions')).toBeNull();
    });
});
