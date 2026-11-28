import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { router } from '@inertiajs/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import InvoiceCorrectionsSwitch, { type InvoiceCorrectionsState } from '@/Pages/Platform/Settings/partials/InvoiceCorrectionsSwitch';

/*
 * مفتاحُ إيقاف تعديل فواتير العملاء في شاشة مدير المنصّة — خطّةُ الرجوع.
 *
 * يقول حالَه، ويسأل قبل أن يبدّل، ويرسل ما يُطلب وحده إلى بابه — ولا يُرسل إن تراجع.
 */
const state = (over: Partial<InvoiceCorrectionsState> = {}): InvoiceCorrectionsState => ({
    on: true, readable: true, changed_at: null, history: [], ...over,
});

describe('مفتاحُ إيقاف تعديل فواتير العملاء', () => {
    afterEach(() => vi.mocked(router.post).mockClear());

    it('مشغَّلًا: يقول إنّه يعمل، والإيقافُ يُسأل عنه ثمّ يُرسل «مطفأ» إلى بابه', async () => {
        render(<InvoiceCorrectionsSwitch state={state()} />);
        expect(screen.getByTestId('invoice-corrections-state')).toHaveTextContent('يعمل الآن');

        fireEvent.click(screen.getByTestId('invoice-corrections-flip'));
        const dialog = await screen.findByRole('dialog');
        expect(dialog.textContent).toContain('وما صدر من فواتير وإشعارات يبقى كما هو');
        fireEvent.click(within(dialog).getByRole('button', { name: 'إيقاف' }));

        await waitFor(() => expect(router.post).toHaveBeenCalled());
        const [url, body] = vi.mocked(router.post).mock.calls.at(-1)!;
        expect(String(url)).toContain('super-admin.invoice-corrections.update');
        expect(body).toEqual({ enabled: false });
    });

    it('ومن تراجع لا يُرسل شيئًا', async () => {
        render(<InvoiceCorrectionsSwitch state={state()} />);
        fireEvent.click(screen.getByTestId('invoice-corrections-flip'));
        const dialog = await screen.findByRole('dialog');
        fireEvent.click(within(dialog).getByRole('button', { name: 'إلغاء' }));
        await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
        expect(router.post).not.toHaveBeenCalled();
    });

    it('موقوفًا: يقوله، والتشغيلُ يُرسل «مشغّل»، وآخرُ التغييرات بأسماء من بدّلها', async () => {
        render(<InvoiceCorrectionsSwitch state={state({ on: false, changed_at: '2026-11-28T10:00:00+04:00', history: [{ at: '2026-11-28T10:00:00+04:00', by: 'مدير المنصّة', from: true, to: false }] })} />);
        expect(screen.getByTestId('invoice-corrections-state')).toHaveTextContent('موقوف الآن');
        expect(screen.getByTestId('invoice-corrections-history')).toHaveTextContent('مدير المنصّة');

        fireEvent.click(screen.getByTestId('invoice-corrections-flip'));
        fireEvent.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'تشغيل' }));
        await waitFor(() => expect(router.post).toHaveBeenCalled());
        expect(vi.mocked(router.post).mock.calls.at(-1)![1]).toEqual({ enabled: true });
    });

    it('ومفتاحٌ لا يُقرأ يقول إنّ التعديل موقوف حتى يُقرأ', () => {
        render(<InvoiceCorrectionsSwitch state={state({ on: false, readable: false })} />);
        expect(screen.getByTestId('invoice-corrections-unreadable')).toBeInTheDocument();
    });
});
