import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import ReceiptPreviewButton from '@/Pages/Pos/partials/ReceiptPreview';

// والجوابُ يُبنى بـBlob هذه البيئة لا بـResponse من Node: Blob الذي تعيده Response
// على Node 22 (CI) ليس Blob المتصفّح المزعوم، فيُقرأ «ليس ملفًّا»
const pdfAnswer = () =>
    ({
        ok: true,
        status: 200,
        headers: new Headers({ 'content-type': 'application/pdf' }),
        blob: async () => new Blob(['%PDF-1.4'], { type: 'application/pdf' }),
    }) as unknown as Response;

const error = vi.fn();
vi.mock('sonner', () => ({ toast: { error: (...a: unknown[]) => error(...a) } }));

/**
 * الفاتورةُ تُرى فوق شاشة البيع — ولا يُغادَر إليها.
 *
 * ═══ ما كان يقع ═══
 *
 * لم يكن في نقطة البيع فعلٌ إلّا «طباعة الفاتورة»، وهو يفتح ملفَّ PDF في
 * لسانٍ آخر. وعلى الآيباد والهاتف يملأ قارئُ PDF الشاشةَ ولا شريطَ ألسنةٍ
 * يُرى — فتختفي شاشةُ البيع، ويقف الكاشير أمام ورقةٍ لا يعرف كيف يرجع
 * منها والزبون واقف.
 *
 * ═══ ولمَ يُقاس في متصفّح ═══
 *
 * «لا يُغادر الشاشة» دعوى سلوكٍ لا دعوى مصدر: قراءةُ `onClick` في ملفّ لا
 * تُثبت أنّ النافذة تُفتح، ولا أنّ الإغلاق يُبقي ما تحتها، ولا أنّ فشلَ
 * الطلب لا يُخرج الكاشيرَ من صندوقه.
 */
describe('معاينة فاتورة نقطة البيع', () => {
    const PAPER = { html: '<html><body><h1>INV-1042</h1></body></html>', size: '80mm' };

    /** شاشةٌ تحت النافذة — تُقاس بقاؤها بعد الفتح والإغلاق */
    const screenUnder = (
        <>
            <p>سلة البيع</p>
            <ReceiptPreviewButton number="INV-1042" />
        </>
    );

    let fetchMock: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        error.mockClear();
        fetchMock = vi.fn().mockResolvedValue({ ok: true, json: async () => PAPER });
        vi.stubGlobal('fetch', fetchMock);
        vi.stubGlobal('open', vi.fn());
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    const openPreview = async () => {
        const user = userEvent.setup();
        await user.click(screen.getByRole('button', { name: /معاينة الفاتورة/ }));

        return { user, dialog: await screen.findByRole('dialog') };
    };

    it('تفتح ورقةَ الفاتورة فوق الشاشة بعد بيعةٍ جديدة', async () => {
        render(screenUnder);

        const { dialog } = await openPreview();

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls[0][0]).toContain('pos.receipt.paper');
        expect(fetchMock.mock.calls[0][0]).toContain('INV-1042');

        // الورقةُ نفسُها مرسومةً — لا ملفُّ PDF في إطار
        const frames = Array.from(dialog.querySelectorAll('iframe'));
        expect(frames.length).toBeGreaterThan(0);
        expect(frames.every((f) => f.getAttribute('srcdoc') === PAPER.html)).toBe(true);
        expect(dialog.querySelector('iframe[src*="pdf"]')).toBeNull();
    });

    it('وشاشةُ البيع تبقى تحتها — ولا ملاحةَ إلى الملفّ', async () => {
        render(screenUnder);

        const before = window.location.href;
        await openPreview();

        expect(screen.getByText('سلة البيع')).toBeInTheDocument();
        expect(window.location.href).toBe(before);
        // ولا لسانٌ يُفتح بمجرّد المعاينة — الطباعةُ وحدها تفعل
        expect(window.open).not.toHaveBeenCalled();
    });

    it('والإغلاق يعيده إلى الشاشة نفسِها بلا إعادة تحميل', async () => {
        render(screenUnder);

        const { user, dialog } = await openPreview();
        await user.click(within(dialog).getByRole('button', { name: /Close/i }));

        await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());

        expect(screen.getByText('سلة البيع')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /معاينة الفاتورة/ })).toBeInTheDocument();
    });

    it('وتُفتح مرّةً ثانيةً بلا سؤال الخادم من جديد', async () => {
        render(screenUnder);

        const { user, dialog } = await openPreview();
        await user.click(within(dialog).getByRole('button', { name: /Close/i }));
        await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());

        await user.click(screen.getByRole('button', { name: /معاينة الفاتورة/ }));

        expect(await screen.findByRole('dialog')).toBeInTheDocument();
        // نصُّ الورقة لا يتبدّل بين فتحتين — فلا يُرسم مرّتين على الخادم
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    /*
     * والأفعالُ الثلاثة تبقى مفروزة.
     *
     * معاينةٌ تُري ولا تُنزل، وطباعةٌ تطلب حوارَ الطباعة، وتحميلٌ يحفظه. وزرُّ
     * المعاينة الذي يُنزل ملفًّا يجعل الكاشير يجمع في «التنزيلات» ورقةً عن
     * كلّ زبونٍ سأل «أصحيحةٌ فاتورتي؟».
     *
     * و«طباعة» زرٌّ لا رابطٌ إلى لسان: تُطبع الفاتورةُ في مكانها والمعاينةُ
     * مفتوحة — انظر `the-till-prints-without-leaving-the-page`.
     */
    it('وتفرّق معاينةً وطباعةً وتحميلًا — ولا تُنزل شيئًا بنفسها', async () => {
        render(screenUnder);

        const trigger = screen.getByRole('button', { name: /معاينة الفاتورة/ });
        expect(trigger).not.toHaveAttribute('download');
        expect(trigger).not.toHaveAttribute('href');

        const { dialog } = await openPreview();

        const print = within(dialog).getByRole('button', { name: /طباعة/ });
        expect(print).not.toHaveAttribute('href');
        expect(print).not.toHaveAttribute('download');
        expect(within(dialog).queryByRole('link', { name: /^طباعة$/ })).toBeNull();

        // والتحميلُ زرٌّ لا يُنزل شيئًا حتى يُضغط، ثمّ يجلب الملفَّ ويقرؤه قبل حفظه
        const download = within(dialog).getByRole('button', { name: /تحميل/ });
        expect(download).not.toHaveAttribute('href');
        expect(fetchMock.mock.calls.some((c) => String(c[0]).includes('pos.receipt.pdf'))).toBe(false);

        fetchMock.mockResolvedValue(pdfAnswer());
        URL.createObjectURL = vi.fn(() => 'blob:receipt');
        URL.revokeObjectURL = vi.fn();
        const saved: string[] = [];
        const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) {
            saved.push(this.download);
        });
        await userEvent.click(download);
        await waitFor(() => expect(saved).toEqual(['INV-1042.pdf']));
        expect(String(fetchMock.mock.calls.at(-1)![0])).toContain('pos.receipt.pdf');
        click.mockRestore();
    });

    it('وفشلُ الطلب يُقال في مكانه ولا يُخرج الكاشير من صندوقه', async () => {
        fetchMock.mockResolvedValue({ ok: false, status: 500, json: async () => ({}) });
        render(screenUnder);

        const user = userEvent.setup();
        await user.click(screen.getByRole('button', { name: /معاينة الفاتورة/ }));

        await waitFor(() => expect(error).toHaveBeenCalledTimes(1));

        expect(screen.queryByRole('dialog')).toBeNull();
        expect(screen.getByText('سلة البيع')).toBeInTheDocument();
        // ولا رمزُ حالةٍ ولا مسارُ خادمٍ في وجه الكاشير
        expect(String(error.mock.calls[0][0])).not.toMatch(/500|http|\/pos\//i);
        // والزرُّ يعود قابلًا للضغط — لا يبقى معلَّقًا على دوّارة
        expect(screen.getByRole('button', { name: /معاينة الفاتورة/ })).toBeEnabled();
    });

    /*
     * والطلبُ المعلَّق يُلغى.
     *
     * الكاشير يضغط «معاينة» ثمّ «طلب جديد» قبل أن يصل الردّ — فلو تُرك
     * لَفتح النافذةَ فوق سلّةٍ جديدة بعد أن غادرها.
     */
    it('ويُلغى الطلبُ المعلَّق حين تُغلق الشاشة قبل وصوله', async () => {
        let release: (v: unknown) => void = () => {};
        fetchMock.mockImplementation(
            (_url: string, init: { signal: AbortSignal }) =>
                new Promise((resolve) => {
                    release = () => resolve({ ok: true, json: async () => PAPER });
                    init.signal.addEventListener('abort', () => resolve(Promise.reject(new Error('aborted'))));
                }),
        );

        const { unmount } = render(screenUnder);

        const user = userEvent.setup();
        await user.click(screen.getByRole('button', { name: /معاينة الفاتورة/ }));

        const signal = fetchMock.mock.calls[0][1].signal as AbortSignal;
        expect(signal.aborted).toBe(false);

        unmount();

        expect(signal.aborted).toBe(true);
        release(null);
    });
});
