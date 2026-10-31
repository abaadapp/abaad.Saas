import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import PdfDownloadButton from '@/Components/PdfDownloadButton';

// والجوابُ يُبنى بـBlob هذه البيئة لا بـResponse من Node: Blob الذي تعيده Response
// على Node 22 (CI) ليس Blob المتصفّح المزعوم، فيُقرأ «ليس ملفًّا»
const pdfAnswer = () =>
    ({
        ok: true,
        status: 200,
        headers: new Headers({ 'content-type': 'application/pdf' }),
        blob: async () => new Blob(['%PDF-1.4'], { type: 'application/pdf' }),
    }) as unknown as Response;

const toasts = { error: vi.fn() };
vi.mock('sonner', () => ({ toast: { error: (...a: unknown[]) => toasts.error(...a) } }));

/**
 * «تحميل» يحفظ PDF أو يقول لماذا لم يحفظ — ولا يحفظ صفحةَ خطأ باسم PDF.
 *
 * يومَ سقط توليدُ الـPDF على الخادم (mpdf بلا مجلّدٍ مؤقّتٍ يكتبه) حفظ رابطُ
 * `download` صفحةَ الخطأ 500 على iPhone باسم «CINV-000049.pdf»، ففُتحت سوداء
 * ولم يُقَل للتاجر شيء.
 */

const URL_ = '/admin/customer-invoices/78/pdf';
const NAME = 'CINV-000049.pdf';

let clicks: { href: string; download: string }[];

function respond(body: string, status: number, type: string) {
    vi.stubGlobal('fetch', vi.fn(async () => new Response(body, { status, headers: { 'content-type': type } })));
}

// وjsdom بلا `maxTouchPoints` — فيُعرَّفان على المتصفّح المزعوم مباشرةً
function setUserAgent(ua: string, touch = 0) {
    Object.defineProperty(navigator, 'userAgent', { value: ua, configurable: true });
    Object.defineProperty(navigator, 'maxTouchPoints', { value: touch, configurable: true });
}

beforeEach(() => {
    clicks = [];
    toasts.error.mockReset();
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) {
        clicks.push({ href: this.getAttribute('href') ?? '', download: this.download });
    });
    URL.createObjectURL = vi.fn(() => 'blob:pdf-1');
    URL.revokeObjectURL = vi.fn();
    setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/130');
});

afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

async function press() {
    render(<PdfDownloadButton url={URL_} filename={NAME} />);
    await userEvent.click(screen.getByRole('button', { name: 'تحميل' }));
}

describe('تحميل ورقة المستند', () => {
    it('يحفظ الـPDF باسمه حين يردّ الخادم PDF', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => pdfAnswer()));
        await press();

        await waitFor(() => expect(clicks).toEqual([{ href: 'blob:pdf-1', download: NAME }]));
        expect(toasts.error).not.toHaveBeenCalled();
    });

    it('لا يحفظ صفحةَ الخطأ 500 باسم PDF، ويقول إنّ التحميل تعذّر', async () => {
        respond('<html>500 | Server Error</html>', 500, 'text/html; charset=UTF-8');
        await press();

        await waitFor(() => expect(toasts.error).toHaveBeenCalledWith('تعذّر تحميل الملف — لم يُحفظ شيء. حاول مرة أخرى'));
        expect(clicks).toEqual([]);
    });

    it('صفحةُ الدخول بعد انتهاء الجلسة ليست ملفًّا — يقول ذلك ولا يحفظها', async () => {
        respond('<html>login</html>', 200, 'text/html; charset=UTF-8');
        await press();

        await waitFor(() => expect(toasts.error).toHaveBeenCalledWith('انتهت الجلسة — سجّل الدخول من جديد'));
        expect(clicks).toEqual([]);
    });

    it('انقطاعُ الاتصال يُقال ولا يُحفظ شيء', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => Promise.reject(new TypeError('Failed to fetch'))));
        await press();

        await waitFor(() => expect(toasts.error).toHaveBeenCalledWith('تعذّر تحميل الملف — لم يُحفظ شيء. حاول مرة أخرى'));
        expect(clicks).toEqual([]);
    });

    it('الضغطُ المتكرّر أثناء التنزيل طلبٌ واحد لا أكثر', async () => {
        let release!: () => void;
        const fetch = vi.fn(
            () =>
                new Promise<Response>((resolve) => {
                    release = () => resolve(pdfAnswer());
                }),
        );
        vi.stubGlobal('fetch', fetch);

        render(<PdfDownloadButton url={URL_} filename={NAME} />);
        const button = screen.getByRole('button', { name: 'تحميل' });
        await userEvent.click(button);
        await userEvent.click(button);
        await userEvent.click(button);

        expect(fetch).toHaveBeenCalledTimes(1);
        expect(button).toBeDisabled();

        release();
        await waitFor(() => expect(clicks).toHaveLength(1));
        await waitFor(() => expect(button).not.toBeDisabled());
    });

    it('على iPhone يُحفظ من رابط الخادم بعد أن ثبت أنّه PDF — لا من blob', async () => {
        setUserAgent('Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) CriOS/130 Mobile/15E148');
        vi.stubGlobal('fetch', vi.fn(async () => pdfAnswer()));
        await press();

        await waitFor(() => expect(clicks).toEqual([{ href: URL_, download: NAME }]));
        expect(URL.createObjectURL).not.toHaveBeenCalled();
    });
});
