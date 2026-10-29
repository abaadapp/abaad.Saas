import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { router } from '@inertiajs/react';
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { pageProps } from './setup';

/**
 * تعديلُ المسودّة وتصحيحُ الفاتورة الصادرة — من جهة الشاشة.
 *
 * ═══ ما يُحرس ═══
 *
 *   - البندُ القائمُ يُرسَل بمفتاحه، وإضافةُ الكتالوج القائمةُ لا يُكتب اسمُها ولا
 *     ثمنُها، وحذفُ الصنف يحذف إضافاتِه معه.
 *   - الصادرةُ: بياناتُها المطبوعة مقفلة — العميلُ والتاريخُ والمراجعُ ووصفُ البند
 *     ونسبةُ ضريبته — ويُعدَّل البنودُ والملاحظاتُ الداخليّة.
 *   - لا اعتمادَ قبل المعاينة، ولا بعدها بلا سببٍ وأساس، ولا حين تمنع الخطّة؛
 *     وتغييرُ النموذج بعد المعاينة يطلب معاينةً جديدة.
 *   - المعاينةُ تسمّي المستندين بمبلغيهما وتقول الفائضَ رصيدًا بلا ردّ مال.
 *   - المولودةُ من طلبٍ: بنودُها مقفلةٌ ولا تُرسَل.
 *   - صفحةُ الفاتورة: «تعديل» للمسودّة و«تصحيح» للصادرة، والإشعاراتُ المدينة،
 *     والمطلوبُ بعد المستندات، والسجلُّ بمستنداته لمن يقرؤه.
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
    { key: 'i11', item_id: 11, parent_key: null, product_id: 3, addon_id: null, description: 'بوكيه', quantity: 1, unit_price: 100, discount: 0, tax_rate: 5, line_total: 105, details: ['ملاحظة: أحمر'] },
    { key: 'i12', item_id: 12, parent_key: 'i11', product_id: null, addon_id: 4, description: 'شوكولاتة', quantity: 1, unit_price: 3, discount: 0, tax_rate: 5, line_total: 3.15, details: [] },
];

const invoice = (over: Record<string, unknown> = {}) => ({
    id: 9, number: 'CINV-000009', status: 'صادرة', state: 'مدفوعة', kind: 'paid', revision: 2, from_order: false, orders: [],
    customer_id: 7, customer: 'وزارة الثقافة', issued_at: '2026-10-20', due_at: null, payment_terms_days: null, branch_id: null,
    po_number: null, contract_number: null, external_reference: null, department: null, cost_center: null, attention_to: null,
    notes: null, internal_notes: null, subtotal: 103, discount_total: 0, tax_total: 5.15, total: 108.15,
    issued_total: 108.15, effective_total: 108.15, paid: 108.15, credited: 0, debited: 0, outstanding: 0, items, ...over,
});

const credit = { amount: 21, tax: 1, net: 20, lines: [{ description: 'بوكيه', change: 'changed', total: 21, tax: 1 }] };

const plan = (over: Record<string, unknown> = {}) => ({
    changed: true, kind: 'paid', changes: [], lines: [{ change: 'changed', key: 'i11', before: { description: 'بوكيه', quantity: 1, unit_price: 100, discount: 0, tax_rate: 5 }, after: { description: 'بوكيه', quantity: 1, unit_price: 80, discount: 0, tax_rate: 5 } }],
    money: { total_before: 108.15, total_after: 87.15, tax_before: 5.15, tax_after: 4.15, difference: -21, tax_difference: -1, paid: 108.15, credited: 0, debited: 0, outstanding_before: 0, outstanding_after: 0, customer_credit: 21 },
    documents: { credit, debit: null },
    stock: { moves: false, why: 'الفاتورة اليدوية لا تُخرج بضاعة من المخزون ولا تُقيّد تكلفة — فتعديل كمياتها لا يحرّك المخزون.' },
    period: { note_date: '2026-10-28', prior: false, closed_through: null, filed_through: null },
    needs_reason: true, needs_basis: true, missing: [], blockers: [], ...over,
});

const reset = (over: Record<string, unknown> = {}) => {
    for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    Object.assign(pageProps, {
        translations: {}, context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 } },
        auth: { abilities: [], mayActions: [] }, flash: { toast: null, status: null }, errors: {},
        invoice: invoice(), customers: [{ id: 7, name: 'وزارة الثقافة', phone: null, payment_terms_days: null }, { id: 8, name: 'وزارة الصحة', phone: null, payment_terms_days: null }],
        branches: [], products: [], addons: [], tax_rate: 5, tax_rates: [5], tax_inclusive: false, today: '2026-10-28',
        edit_token: 'tok-edit-1', bases: [{ value: 'error', label: 'تصحيح خطأ في الفاتورة' }, { value: 'return', label: 'إرجاع أو إلغاء جزئي — مسار مستقل لم يُفتح بعد', disabled: true }],
        may: { correct_period: false, owner: false, replace: false },
        ...over,
    });
};

const answer = (body: unknown, ok = true) => {
    globalThis.fetch = vi.fn(async () => new Response(JSON.stringify(body), { status: ok ? 200 : 422 })) as never;
};

/** قائمةُ الاختيار (Radix): تُفتح ثمّ يُضغط خيارُها */
const choose = async (user: ReturnType<typeof userEvent.setup>, trigger: HTMLElement, name: string) => {
    await user.click(trigger);
    await user.click(await screen.findByRole('option', { name }));
};

const chooseBasis = async (user: ReturnType<typeof userEvent.setup>) => {
    await choose(user, document.getElementById('basis') as HTMLElement, 'تصحيح خطأ في الفاتورة');
};

beforeAll(() => {
    // ‏والقائمةُ المنسدلة تسأل عن مؤشّرٍ لا يملكه jsdom — يُعطى جسدًا فارغًا لا يُحتال عليه في الكود
    Element.prototype.hasPointerCapture = () => false;
    Element.prototype.setPointerCapture = () => {};
    Element.prototype.releasePointerCapture = () => {};
});

beforeEach(() => {
    reset();
    vi.mocked(router.put).mockClear();
    vi.mocked(router.post).mockClear();
    vi.mocked(router.visit).mockClear();
    answer(plan());
});

describe('بنودُ النموذج', () => {
    it('القائمُ بمفتاحه، وإضافةُ الكتالوج القائمةُ مقفلةُ الاسم والثمن', () => {
        const lines = fromItems(items, true);
        expect(lines.map((l) => [l.server_key, l.locked, l.locked_text])).toEqual([['i11', false, true], ['i12', true, true]]);

        const sent = linesPayload(lines);
        expect(sent[0]).toMatchObject({ key: 'i11', item_id: 11, product_id: null, addon_id: null, quantity: '1', unit_price: '100' });
        expect(sent[1]).toMatchObject({ key: 'i12', addon_id: null });
    });

    it('حذفُ الصنف يحذف إضافاتِه — وحذفُ الإضافة لا يمسّ صنفَها', () => {
        const lines = fromItems(items);
        expect(removeLine(lines, 'i11')).toEqual([]);
        expect(removeLine(lines, 'i12').map((l) => l.server_key)).toEqual(['i11']);
    });

    it('البندُ الجديدُ يُرسَل بلا مفتاح وبصنفه', () => {
        const fresh = { key: 'n1', server_key: null, item_id: null, parent_key: null, product_id: '3', addon_id: '', description: 'بوكيه', quantity: '2', unit_price: '20', discount: '', tax_rate: '', locked: false, locked_text: false, details: [] };
        expect(linesPayload([fresh])[0]).toMatchObject({ key: null, item_id: null, product_id: '3', addon_id: null, discount: '0', tax_rate: null });
    });
});

describe('تصحيحُ الفاتورة الصادرة', () => {
    it('بياناتُها المطبوعة مقفلة — والبنودُ والملاحظاتُ الداخليّة تُعدَّل', () => {
        render(<CustomerInvoiceEdit />);

        expect(screen.getByTestId('edit-issued-note')).toHaveTextContent('تبقى كما صدرت');
        expect(screen.getByTestId('edit-printed')).toHaveTextContent('وزارة الثقافة');
        expect(document.getElementById('customer')).toBeNull();
        expect(screen.queryByTestId('edit-issued-at')).toBeNull();
        for (const row of screen.getAllByTestId('edit-line')) {
            expect(within(row).getByTestId('edit-description')).toBeDisabled();
            expect(within(row).getByTestId('edit-tax')).toBeDisabled();
            expect(within(row).getByTestId('edit-qty')).toBeEnabled();
        }
        expect(screen.getByTestId('edit-internal-notes')).toBeEnabled();
    });

    it('لا اعتمادَ قبل المعاينة — والمعاينةُ تسمّي الإشعارَ وتقول الفائضَ رصيدًا بلا ردّ مال', async () => {
        const user = userEvent.setup();
        render(<CustomerInvoiceEdit />);

        expect(screen.getByTestId('edit-save')).toBeDisabled();
        expect(screen.getByTestId('edit-paid-note')).toBeInTheDocument();

        await user.click(screen.getByTestId('edit-preview'));
        await waitFor(() => expect(screen.getByTestId('plan')).toBeInTheDocument());

        const call = vi.mocked(globalThis.fetch).mock.calls[0];
        expect(call[0]).toBe('/admin.customerInvoices.editPreview/9');
        const body = JSON.parse(String((call[1] as RequestInit).body));
        expect(body.items.map((i: { key: string }) => i.key)).toEqual(['i11', 'i12']);
        expect(body.expected_revision).toBe(2);
        expect(body.edit_token).toBe('tok-edit-1');

        expect(screen.getByTestId('plan-credit')).toHaveTextContent('لا يُرد مال تلقائيًا');
        expect(screen.getByTestId('plan-documents')).toHaveTextContent('إشعار دائن');
        expect(screen.queryByTestId('plan-doc-debit')).toBeNull();
        expect(screen.getByTestId('plan-ledger')).toHaveTextContent('قيد الفاتورة الأصلي لا يُمس');

        // والسببُ والأساسُ مطلوبان
        expect(screen.getByTestId('edit-save')).toBeDisabled();
        await user.type(screen.getByTestId('edit-reason'), 'خطأ في السعر');
        expect(screen.getByTestId('edit-save')).toBeDisabled();
        await chooseBasis(user);
        // والأساسُ جزءٌ من النموذج: معاينةٌ جديدة
        await user.click(screen.getByTestId('edit-preview'));
        await waitFor(() => expect(screen.getByTestId('edit-save')).toBeEnabled());

        await user.click(screen.getByTestId('edit-save'));
        expect(router.put).toHaveBeenCalledTimes(1);
        const [url, payload] = vi.mocked(router.put).mock.calls[0];
        expect(url).toBe('/admin.customerInvoices.update/9');
        expect(payload).toMatchObject({ reason: 'خطأ في السعر', basis: 'error', expected_revision: 2, edit_token: 'tok-edit-1' });
    });

    it('زيادةٌ ونقصٌ معًا: المستندان كلٌّ بمبلغه', async () => {
        answer(plan({ documents: { credit, debit: { amount: 31.5, tax: 1.5, net: 30, lines: [{ description: 'تغليف', change: 'added', total: 31.5, tax: 1.5 }] } } }));
        const user = userEvent.setup();
        render(<CustomerInvoiceEdit />);
        await user.click(screen.getByTestId('edit-preview'));

        expect(await screen.findByTestId('plan-doc-debit')).toHaveTextContent('+');
        expect(screen.getByTestId('plan-doc-debit')).toHaveTextContent('تغليف');
        expect(screen.getByTestId('plan-doc-credit')).toHaveTextContent('−');
    });

    it('تغييرُ النموذج بعد المعاينة يطلب معاينةً جديدة', async () => {
        const user = userEvent.setup();
        render(<CustomerInvoiceEdit />);
        await user.type(screen.getByTestId('edit-reason'), 'تصحيح');
        await chooseBasis(user);
        await user.click(screen.getByTestId('edit-preview'));
        await waitFor(() => expect(screen.getByTestId('edit-save')).toBeEnabled());

        const qty = within(screen.getAllByTestId('edit-line')[0]).getByTestId('edit-qty');
        await user.clear(qty);
        await user.type(qty, '3');

        expect(screen.getByTestId('edit-save')).toBeDisabled();
        expect(screen.getByTestId('plan-empty')).toHaveTextContent('تغيّر النموذج بعد المعاينة');
    });

    it('ما يمنع يُقال، ولا يُعتمد معه', async () => {
        answer(plan({ blockers: ['على الفاتورة رابطُ دفعٍ لم يُدفع بعد'] }));
        const user = userEvent.setup();
        render(<CustomerInvoiceEdit />);
        await user.type(screen.getByTestId('edit-reason'), 'تصحيح');
        await chooseBasis(user);
        await user.click(screen.getByTestId('edit-preview'));

        await waitFor(() => expect(screen.getByTestId('plan-blockers')).toHaveTextContent('رابطُ دفعٍ'));
        expect(screen.getByTestId('edit-save')).toBeDisabled();
    });

    it('الفترةُ السابقة تُقال بصلاحيتها', async () => {
        answer(plan({ period: { note_date: '2026-10-28', prior: true, closed_through: '2026-09-30', filed_through: null }, missing: ['تصحيح فاتورة عميل من فترة سابقة أو مقفلة'] }));
        const user = userEvent.setup();
        render(<CustomerInvoiceEdit />);
        await user.click(screen.getByTestId('edit-preview'));

        expect(await screen.findByTestId('plan-prior')).toHaveTextContent('بتاريخ اليوم');
        expect(screen.getByTestId('plan-missing')).toHaveTextContent('فترة سابقة');
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

    it('عميلٌ آخر: «إلغاء وإصدار بديلة» لمن يملكه وحده', async () => {
        const user = userEvent.setup();
        const { unmount } = render(<CustomerInvoiceEdit />);
        expect(screen.queryByTestId('edit-replace')).toBeNull();
        unmount();

        reset({ invoice: invoice({ kind: 'issued', paid: 0, state: 'غير مدفوعة' }), may: { correct_period: false, owner: true, replace: true } });
        render(<CustomerInvoiceEdit />);
        const card = screen.getByTestId('edit-replace');
        expect(screen.getByTestId('edit-replace-submit')).toBeDisabled();

        await choose(user, within(card).getByRole('combobox'), 'وزارة الصحة');
        await user.type(screen.getByTestId('edit-replace-reason'), 'العميل الصحيح');
        await user.click(screen.getByTestId('edit-replace-submit'));

        expect(router.post).toHaveBeenCalledWith('/admin.customerInvoices.replace/9', { customer_id: '8', reason: 'العميل الصحيح' }, expect.anything());
    });
});

describe('تعديلُ المسودّة', () => {
    it('رأسُها ووصفُ بنودها يُعدَّلان، ولا أساسَ ولا سببَ مطلوبان', async () => {
        answer(plan({ kind: 'draft', needs_reason: false, needs_basis: false, documents: { credit: null, debit: null }, money: { ...plan().money, paid: 0, customer_credit: 0 } }));
        reset({ invoice: invoice({ status: 'مسودة', number: null, kind: 'draft', paid: 0, state: 'مسودة' }), tax_rates: null });
        const user = userEvent.setup();
        render(<CustomerInvoiceEdit />);

        expect(document.getElementById('customer')).not.toBeNull();
        expect(screen.queryByTestId('edit-issued-note')).toBeNull();
        expect(document.getElementById('basis')).toBeNull();
        expect(within(screen.getAllByTestId('edit-line')[0]).getByTestId('edit-description')).toBeEnabled();

        await user.click(screen.getByTestId('edit-preview'));
        await waitFor(() => expect(screen.getByTestId('edit-save')).toBeEnabled());
        expect(screen.queryByTestId('plan-documents')).toBeNull();
    });
});

describe('صفحةُ الفاتورة', () => {
    const shown = {
        id: 3, number: 'CINV-000003', customer: 'وزارة الثقافة', customer_id: 7, status: 'صادرة', state: 'غير مدفوعة',
        issued_at: '2026-10-19', due_at: null, total: 105, paid: 0, outstanding: 136.5, days_overdue: 0, revision: 1,
        po_number: null, contract_number: null, external_reference: null, department: null, cost_center: null, attention_to: null,
        subtotal: 100, discount_total: 0, tax_total: 5, notes: null, internal_notes: null, may_read_attachments: false,
        attachments: [], orders: [], cancellation_reason: null,
        items: [{ description: 'باقة', quantity: 2, unit_price: 50, discount: 0, tax_rate: 5, line_total: 105 }],
        payments: [], credit_notes: [], debit_notes: [{ id: 5, number: 'DN-000001', amount: 31.5, reason: 'تغليف', at: '2026-10-20', revision: 1 }],
        debited: 31.5, effective_total: 136.5, replaces: null, replaced_by: [],
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

    it('الزرُّ لمن يملكه وحده', () => {
        showPage();
        expect(screen.queryByTestId('edit-invoice')).toBeNull();
    });

    it('ومن يملكه يُفتح له — «تصحيح» للصادرة', async () => {
        const user = userEvent.setup();
        showPage({ mayEdit: true });
        expect(screen.getByTestId('edit-invoice')).toHaveTextContent('تصحيح خطأ في الفاتورة');
        await user.click(screen.getByTestId('edit-invoice'));
        expect(router.visit).toHaveBeenCalledWith('/admin.customerInvoices.edit/3');
    });

    it('و«تعديل» للمسودّة', () => {
        showPage({ mayEdit: true, invoice: { ...shown, status: 'مسودة', number: null, state: 'مسودة', debit_notes: [], effective_total: 105 } });
        expect(screen.getByTestId('edit-invoice')).toHaveTextContent('تعديل الفاتورة');
    });

    it('الإشعاراتُ المدينة تُفتح، والمطلوبُ بعدها تحت الإجماليّ كما صدر', () => {
        showPage();
        expect(screen.getByTestId('invoice-debit-notes')).toHaveTextContent('DN-000001');
        expect(screen.getByText('المطلوب بعد مستندات التصحيح')).toBeInTheDocument();
    });

    it('السجلُّ لمن يقرؤه: السببُ والأساسُ والمستندات', () => {
        showPage({
            revisions: [{
                revision: 1, kind: 'issued', basis: 'تصحيح خطأ في الفاتورة', reason: 'تغليف ناقص', at: '2026-10-20 10:00', by: 'المالك',
                total_before: 105, total_after: 136.5, tax_before: 5, tax_after: 6.5, paid_at_edit: 0,
                fields: [], lines: [], customer_credit: 0,
                documents: [{ kind: 'debit', number: 'DN-000001', amount: 31.5, url: '/admin/customer-invoices/debit-notes/5' }],
            }],
        });

        const card = screen.getByTestId('invoice-revisions');
        expect(card).toHaveTextContent('تغليف ناقص');
        expect(card).toHaveTextContent('تصحيح خطأ في الفاتورة');
        expect(within(card).getByTestId('invoice-revision-documents')).toHaveTextContent('DN-000001');
    });

    it('ولمن لا يقرؤه: لا شيء', () => {
        showPage({ revisions: null });
        expect(screen.queryByTestId('invoice-revisions')).toBeNull();
    });
});

describe('شاشةُ التصحيح على الهاتف', () => {
    it('كلُّ حقلٍ في البند بعنوانه — بطاقةٌ لا جدولٌ في تمريرٍ أفقيّ يُخفي السعر', () => {
        render(<CustomerInvoiceEdit />);

        expect(document.querySelector('table')).toBeNull();
        expect(document.querySelector('[class*="min-w-[640px]"]')).toBeNull();

        const line = screen.getAllByTestId('edit-line')[0];
        expect(line.className).toContain('grid-cols-2');
        for (const [label, id] of [['البيان', 'edit-description'], ['الكمية', 'edit-qty'], ['السعر', 'edit-price'], ['الخصم', 'edit-discount'], ['الضريبة %', 'edit-tax']]) {
            expect(within(line).getByLabelText(label)).toBe(within(line).getByTestId(id));
        }
    });

    it('الشريطُ السفليّ يعاين ويعتمد، ويقول في سطرٍ ما سيصدر', async () => {
        const user = userEvent.setup();
        render(<CustomerInvoiceEdit />);

        const bar = screen.getByTestId('edit-actions-bar');
        expect(bar.className).toContain('xl:hidden');
        expect(screen.getByTestId('edit-preview').className).toContain('xl:inline-flex');
        expect(screen.getByTestId('edit-actions-status')).toHaveTextContent('عاين قبل الاعتماد');
        expect(screen.getByTestId('edit-save-bar')).toBeDisabled();

        await user.type(screen.getByTestId('edit-reason'), 'خطأ في السعر');
        await chooseBasis(user);
        await user.click(screen.getByTestId('edit-preview-bar'));
        await waitFor(() => expect(screen.getByTestId('edit-save-bar')).toBeEnabled());
        expect(screen.getByTestId('edit-actions-status')).toHaveTextContent('إشعار دائن −21.000');

        await user.click(screen.getByTestId('edit-save-bar'));
        expect(router.put).toHaveBeenCalledTimes(1);
        expect(vi.mocked(router.put).mock.calls[0][1]).toMatchObject({ reason: 'خطأ في السعر', basis: 'error', edit_token: 'tok-edit-1' });
    });

    it('ما يمنعه الخادم يمنعه الشريط — لا اعتمادَ بمانع', async () => {
        answer(plan({ blockers: ['الفترة مُقرّة — بانتظار قرار المحاسب'] }));
        const user = userEvent.setup();
        render(<CustomerInvoiceEdit />);

        await user.type(screen.getByTestId('edit-reason'), 'خطأ');
        await chooseBasis(user);
        await user.click(screen.getByTestId('edit-preview-bar'));
        await waitFor(() => expect(screen.getByTestId('plan-blockers')).toBeInTheDocument());
        expect(screen.getByTestId('edit-actions-status')).toHaveTextContent('لا يُعتمد');
        expect(screen.getByTestId('edit-save-bar')).toBeDisabled();
    });

    it('يغيب الشريطُ ولوحةُ المفاتيح مفتوحة — ويعود حين تُغلق', async () => {
        const user = userEvent.setup();
        render(<CustomerInvoiceEdit />);

        const bar = screen.getByTestId('edit-actions-bar');
        expect(bar.className).not.toMatch(/(^|\s)hidden(\s|$)/);
        await user.click(within(screen.getAllByTestId('edit-line')[0]).getByTestId('edit-price'));
        expect(bar.className).toMatch(/(^|\s)hidden(\s|$)/);
        await user.click(screen.getByTestId('edit-actions-status'));
        expect(bar.className).not.toMatch(/(^|\s)hidden(\s|$)/);
    });
});

describe('الإرجاعُ مسارٌ مستقلّ، وما يمنع يُقال عند الفتح', () => {
    it('أساسُ «الإرجاع» مقفلٌ في القائمة — وتصحيحُ الخطأ وحده يُختار', async () => {
        const user = userEvent.setup();
        render(<CustomerInvoiceEdit />);

        await user.click(document.getElementById('basis') as HTMLElement);
        expect(await screen.findByRole('option', { name: /إرجاع أو إلغاء جزئي/ })).toHaveAttribute('aria-disabled', 'true');
        expect(screen.getByRole('option', { name: 'تصحيح خطأ في الفاتورة' })).not.toHaveAttribute('aria-disabled', 'true');
    });

    it('رابطٌ معلّقٌ أو فترةٌ مُقرّة تُقال قبل أيّ تعديل — والمولودةُ من طلبٍ ببطاقتها وحدها', () => {
        reset({ standing: { link: 'على الفاتورة رابطُ دفعٍ لم يُدفع بعد', filed: 'الفاتورة في فترة ضريبية قُدّم إقرارها', order: 'مولودة من طلب' } });
        render(<CustomerInvoiceEdit />);

        expect(screen.getByTestId('edit-standing')).toHaveTextContent('تصحيح المبلغ ممنوع الآن');
        expect(screen.getByTestId('edit-standing-link')).toBeInTheDocument();
        expect(screen.getByTestId('edit-standing-filed')).toBeInTheDocument();
        expect(screen.queryByTestId('edit-standing-order')).toBeNull();
    });

    it('ولا بطاقةَ حين لا يمنع شيء', () => {
        reset({ standing: {} });
        render(<CustomerInvoiceEdit />);
        expect(screen.queryByTestId('edit-standing')).toBeNull();
    });
});
