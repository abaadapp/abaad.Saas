import { readFileSync } from 'node:fs';
import path from 'node:path';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { router } from '@inertiajs/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import CorrectionRequests, { type CorrectionRequest } from '@/Pages/Admin/CustomerInvoices/partials/CorrectionRequests';

/**
 * الاعتمادُ الثنائيّ من جهة الشاشة — ما يُرى من الطلب ومن يضغط ماذا.
 *
 * ═══ ما يُحرس ═══
 *
 *   - المعتمدُ يرى «اعتماد» و«رفض» لطلبِ غيره، والرفضُ لا يُرسل بلا سبب.
 *   - مقدّمُ الطلب يرى «سحب» لا «اعتماد».
 *   - ما بُتّ فيه يُقرأ ولا يُضغط.
 *   - التصحيحُ يقول ما يصدر عند الاعتماد، والاستبدالُ من أيّ عميلٍ إلى أيّ عميل وبديلتَه.
 *   - وصاحبُ النشاط بلا معتمدٍ غيره يُقال له قبل الإشعال.
 *
 * والخادمُ يحرس ما يقع (`AFinancialCorrectionWaitsForASecondPersonTest`).
 */

const request = (over: Partial<CorrectionRequest> = {}): CorrectionRequest => ({
    id: 7,
    kind: 'customer_invoice_edit',
    status: 'pending',
    reason: 'تصحيح الكمية حسب أمر الشراء',
    invoice: { id: 3, number: 'CINV-000003' },
    summary: {
        kind: 'issued',
        number: 'CINV-000003',
        money: { total_before: 105, total_after: 126, difference: 21, tax_before: 5, tax_after: 6, paid: 0, outstanding_after: 126, customer_credit: 0 },
        changes: [],
        documents: { credit: null, debit: { amount: 21, tax: 1 } },
        note_date: '2025-09-20',
    },
    replacement: null,
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
        const { unmount } = render(<CorrectionRequests requests={[request({ may_decide: true })]} m={m} />);

        await userEvent.click(screen.getByTestId('correction-approve'));
        expect(router.post).toHaveBeenCalledWith('/admin.customerInvoices.corrections.approve/7', {}, expect.anything());
        // والزرّان يُعطَّلان حتى يعود الطلب — فلا يُرسل الاعتمادُ مرّتين
        expect((screen.getByTestId('correction-approve') as HTMLButtonElement).disabled).toBe(true);
        unmount();

        render(<CorrectionRequests requests={[request({ may_decide: true })]} m={m} />);
        await userEvent.click(screen.getByTestId('correction-reject'));
        const confirm = screen.getByTestId('correction-reject-confirm') as HTMLButtonElement;
        expect(confirm.disabled).toBe(true);

        await userEvent.type(screen.getByTestId('correction-reject-reason'), 'الكمية صحيحة');
        await userEvent.click(confirm);
        expect(router.post).toHaveBeenLastCalledWith('/admin.customerInvoices.corrections.reject/7', { reason: 'الكمية صحيحة' }, expect.anything());
    });

    it('مقدّمُ الطلب لا يرى «اعتماد» — يرى «سحب»', async () => {
        render(<CorrectionRequests requests={[request({ may_withdraw: true })]} m={m} />);

        expect(screen.queryByTestId('correction-approve')).toBeNull();
        expect(screen.queryByTestId('correction-reject')).toBeNull();
        await userEvent.click(screen.getByTestId('correction-withdraw'));
        expect(router.post).toHaveBeenCalledWith('/admin.customerInvoices.corrections.withdraw/7', {}, expect.anything());
    });

    it('ومن لا يبتّ ولا يسحب يُقال له إنّه ينتظر غيرَ مقدّمه', () => {
        render(<CorrectionRequests requests={[request()]} m={m} />);

        expect(screen.queryByTestId('correction-approve')).toBeNull();
        expect(screen.queryByTestId('correction-withdraw')).toBeNull();
        expect(screen.getByTestId('correction-7')).toHaveTextContent('غير مقدّمه');
    });

    it('ما بُتّ فيه يُقرأ ولا يُضغط', () => {
        render(<CorrectionRequests requests={[request({ status: 'stale', decided_by: 'منى', decided_at: '2025-09-21 09:00', may_decide: true })]} m={m} />);

        expect(screen.getByTestId('correction-status')).toHaveTextContent('تغيّرت الفاتورة');
        expect(screen.getByTestId('correction-decision')).toHaveTextContent('منى');
        expect(screen.queryByTestId('correction-approve')).toBeNull();
    });

    it('التصحيحُ يقول ما يصدر عند الاعتماد', () => {
        render(<CorrectionRequests requests={[request()]} m={m} />);

        const docs = screen.getByTestId('correction-documents');
        expect(docs).toHaveTextContent('إشعار مدين');
        expect(docs).toHaveTextContent('21.000');
        expect(docs).not.toHaveTextContent('إشعار دائن');
    });

    it('والاستبدالُ يقول من أيّ عميلٍ إلى أيّ عميل — وبديلتَه بعد الاعتماد', () => {
        const replace = request({
            kind: 'customer_invoice_replace',
            summary: { kind: 'customer_invoice_replace', number: 'CINV-000003', customer_before: 'وزارة الثقافة', customer_after: 'وزارة الصحة', total: 105 },
        });
        const { unmount } = render(<CorrectionRequests requests={[replace]} m={m} />);

        const line = screen.getByTestId('correction-replace');
        for (const text of ['وزارة الثقافة', 'وزارة الصحة', '105.000']) {
            expect(line).toHaveTextContent(text);
        }
        expect(screen.queryByTestId('correction-documents')).toBeNull();
        unmount();

        render(<CorrectionRequests requests={[{ ...replace, status: 'approved', decided_by: 'منى', decided_at: '2025-09-21 09:00', replacement: { id: 9 } }]} m={m} />);
        expect(screen.getByTestId('correction-replacement')).toHaveAttribute('href', '/admin.customerInvoices.show/9');
    });
});

describe('صاحبُ النشاط بلا معتمدٍ غيره', () => {
    it('يُقال له قبل الإشعال لا بعده — من عدّ الخادم لمن يعتمد غيرَه', () => {
        const src = readFileSync(path.resolve(__dirname, '../../resources/js/Pages/Admin/Settings/Index.tsx'), 'utf8');
        const warning = src.indexOf('data-testid="dual-approval-alone"');
        const condition = src.lastIndexOf('{dualApproval?.owner &&', warning);

        expect(warning, 'التنبيه غائب').toBeGreaterThan(-1);
        const guard = src.slice(condition, warning);
        expect(guard).toContain('dualApproval?.others ?? 0) === 0');
        // ولا ينتظر المفتاحَ مُشعلًا — من يقرؤه بعد الإشعال يقرؤه بعد أن ردّه الخادم
        expect(guard).not.toContain('form.data.dual_approval_corrections');
    });

    it('ومن ليس صاحبَ النشاط لا يغيّره من الشاشة', () => {
        const src = readFileSync(path.resolve(__dirname, '../../resources/js/Pages/Admin/Settings/Index.tsx'), 'utf8');

        expect(src).toContain("onChange={(v) => dualApproval?.owner && form.setData('dual_approval_corrections', v)}");
    });
});
