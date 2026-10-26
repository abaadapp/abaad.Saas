import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { router } from '@inertiajs/react';

import ReturnsPanel, { type BoutiqueReturnsPart, type ReturnNote } from '@/Pages/Admin/Boutiques/ReturnsPanel';
import { AwaitingReturns, CancelWithPieces } from '@/Pages/Admin/Orders/BoutiquePieces';

/**
 * مرتجعُ قطعة بوتيكٍ بعد ورقتها — الشاشةُ تسأل وتعرض، والحسمُ في الخادم.
 *
 * والخادمُ حارسُ ما وراءها: `ABoutiqueReturnAfterItsSheetIsANoteTest`.
 */

const currency = { code: 'OMR', symbol: 'ر.ع', decimals: 3 } as never;

const note = (o: Partial<ReturnNote> = {}): ReturnNote => ({
    id: 7, order: 'INV-9', sheet: 'BQ-2027-02-1', name: 'عطر لمى', quantity: 2, amount: 80,
    condition: 'sellable', status: 'review', basis: null, terms_ref: null, decision_note: null,
    sold_at: '2027-02-10', returned_at: '2027-03-10', booked_at: null, remaining: 0, ...o,
});

const part = (o: Partial<BoutiqueReturnsPart> = {}): BoutiqueReturnsPart => ({
    notes: [note()], open: 0, review: 1, targets: [], can_decide: true, ...o,
});

const draw = (p: BoutiqueReturnsPart, terms: string | null = null) =>
    render(<ReturnsPanel boutiqueId={3} terms={terms} termsRef={terms ? 'اتفاقية التوريد — البند 4' : null} part={p} currency={currency} />);

beforeEach(() => vi.mocked(router.post).mockClear());
afterEach(cleanup);

describe('مرتجعاتُ البوتيك بعد الورقة', () => {
    it('تقول إنّ شرط المرتجع غير موثّق وتعرض الإشعار بحاله', () => {
        draw(part());

        expect(screen.getByTestId('boutique-returns-terms-read').textContent).toContain('غير موثّق');
        expect(screen.getByText('بانتظار قرار محاسبي')).toBeTruthy();
        expect(screen.getByText(/عادت سليمة/)).toBeTruthy();
    });

    it('وتقرأ الشرطَ الموثَّق بمرجعه', () => {
        draw(part({ notes: [note({ status: 'reduced' })], review: 0 }), 'sellable');

        expect(screen.getByTestId('boutique-returns-terms-read').textContent).toContain('اتفاقية التوريد — البند 4');
        expect(screen.queryByTestId('return-on-boutique')).toBeNull();
    });

    it('قرارُ المحاسب يُرسل الإشعارَ ومَن يتحمّل وسببَه', async () => {
        draw(part());

        await userEvent.type(screen.getByLabelText('سبب القرار'), 'وافق البوتيك كتابة');
        await userEvent.click(screen.getByTestId('return-on-shop'));

        expect(router.post).toHaveBeenCalledWith(
            '/admin.boutiques.returns.review/3',
            { note: 7, bearer: 'shop', why: 'وافق البوتيك كتابة' },
            expect.anything(),
        );
    });

    it('ومن لا يملك القرار لا يرى بابَه', () => {
        draw(part({ can_decide: false }));

        expect(screen.queryByTestId('return-on-boutique')).toBeNull();
        expect(screen.queryByTestId('return-on-shop')).toBeNull();
    });

    it('الاستلامُ يُرسل المبلغَ والطريقةَ والمرجع، والخصمُ يُرسل الورقة', async () => {
        draw(part({
            notes: [note({ status: 'claim', remaining: 80 })], open: 80, review: 0,
            targets: [{ id: 12, number: 'BQ-2027-03-1', payable: 80 }],
        }), 'all');

        fireEvent.change(screen.getByLabelText('المبلغ المستلم'), { target: { value: '50' } });
        fireEvent.change(screen.getByLabelText('مرجع التحويل أو السند'), { target: { value: 'TR-501' } });
        await userEvent.click(screen.getByTestId('return-receipt'));
        await userEvent.click(screen.getByTestId('return-offset'));

        expect(router.post).toHaveBeenCalledWith(
            '/admin.boutiques.returns.receipt/3', { amount: 50, method: 'تحويل', reference: 'TR-501' }, expect.anything(),
        );
        expect(router.post).toHaveBeenCalledWith('/admin.boutiques.returns.offset/3', { settlement: 12 }, expect.anything());
    });
});

describe('إلغاءُ طلبٍ فيه بندٌ حملته ورقة', () => {
    it('يسأل كم عاد سليمًا وكم تالفًا ويقول ما لم يُستلم', async () => {
        const confirm = vi.fn();
        render(<CancelWithPieces open pieces={[]} lines={[{ id: 41, name: 'عطر لمى', quantity: 3, sheet: 'BQ-1' }]}
            onClose={() => {}} onConfirm={confirm} />);

        fireEvent.change(screen.getByLabelText('عادت سليمة'), { target: { value: '2' } });
        expect(screen.getByTestId('cancel-sheet-line-open').textContent).toContain('1');

        fireEvent.change(screen.getByLabelText('تالفة أو لم تعد'), { target: { value: '5' } });
        expect(screen.getByTestId('cancel-sheet-line-open').textContent).toContain('0');

        await userEvent.click(screen.getByTestId('cancel-confirm'));
        expect(confirm).toHaveBeenCalledWith({}, { 41: { sellable: 2, damaged: 1 } });
    });

    it('وبعده تُسجَّل القطعُ التي لم تُستلم', async () => {
        render(<AwaitingReturns orderNumber="INV-9" notes={[{ id: 7, name: 'عطر لمى', quantity: 2, sheet: 'BQ-1' }]} />);

        fireEvent.change(screen.getByLabelText('عادت سليمة'), { target: { value: '1' } });
        fireEvent.change(screen.getByLabelText('تالفة أو لم تعد'), { target: { value: '1' } });
        await userEvent.click(screen.getByText('سجّل الاستلام'));

        expect(router.post).toHaveBeenCalledWith(
            '/admin.orders.boutique-returns/INV-9', { note: 7, sellable: 1, damaged: 1 }, expect.anything(),
        );
    });
});
