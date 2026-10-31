import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import DocumentPanel from '@/Components/DocumentPanel';
import ExportMenu from '@/Components/ExportMenu';
import { downloadFile, EXPECTED_BUSINESS_PARAM, expectBusiness, withFilters } from '@/lib/exportLink';
import { pageProps } from './setup';

/**
 * كلُّ رابطِ ملفٍّ يحمل نشاطَ الصفحة التي رُسم فيها — والتبويبُ القديم يُقال له «حدّث».
 *
 * النشاطُ من `auth.user.businessId` في خصائص الصفحة وحدها — لا من عنوانها ولا
 * من تخزين المتصفّح. والخادمُ يقارنه بالجلسة ويقف (`TenantExportGuard`)،
 * فتقرأ القائمةُ صفحتَه في إطارها وتقول السببَ لا «تعذّر التصدير».
 */

const open = () => fireEvent.pointerDown(screen.getByTestId('export-menu'), { button: 0, ctrlKey: false });
const asBusiness = (id: number | null) =>
    Object.assign(pageProps, { auth: { abilities: [], mayActions: [], user: id ? { id: 1, businessId: id } : null } });

beforeEach(() => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    window.history.replaceState({}, '', '/admin/orders?status=open&page=2');
});

afterEach(() => {
    vi.useRealTimers();
    asBusiness(null);
    document.querySelectorAll('iframe').forEach((f) => f.remove());
});

/** ما يحمّله الإطارُ الخفيّ — مستندٌ يُوضع فيه، كما يحمّل المتصفّحُ ردَّ الخادم */
function respond(frame: HTMLIFrameElement, html: string) {
    const doc = document.implementation.createHTMLDocument('');
    doc.body.innerHTML = html;
    Object.defineProperty(frame, 'contentDocument', { configurable: true, value: doc });
    frame.dispatchEvent(new Event('load'));
}

describe('a file link carries the business of its page', () => {
    it('adds the page business, replacing any value the URL already had', () => {
        expect(expectBusiness('/admin/reports/pdf?range=month', 7)).toBe(`/admin/reports/pdf?range=month&${EXPECTED_BUSINESS_PARAM}=7`);
        expect(expectBusiness(`/x?${EXPECTED_BUSINESS_PARAM}=9`, 7)).toBe(`/x?${EXPECTED_BUSINESS_PARAM}=7`);
        // ولوحةُ المنصّة لا نشاطَ لها — لا يُضاف شيء
        expect(expectBusiness('/super-admin/reports/pdf', null)).toBe('/super-admin/reports/pdf');
    });

    it('never inherits an expectation from the address bar', () => {
        window.history.replaceState({}, '', `/admin/orders?status=open&${EXPECTED_BUSINESS_PARAM}=99`);

        const url = withFilters(expectBusiness('/admin/orders/export-xlsx', 7));
        const params = new URL(url, 'http://x').searchParams;
        expect(params.getAll(EXPECTED_BUSINESS_PARAM)).toEqual(['7']);
        expect(params.get('status')).toBe('open');

        // ورابطٌ بلا نشاطٍ لا يأخذه من العنوان أيضًا
        expect(new URL(withFilters('/admin/orders/export-xlsx'), 'http://x').searchParams.has(EXPECTED_BUSINESS_PARAM)).toBe(false);
    });

    it('puts the page business on every export menu item', async () => {
        asBusiness(7);
        render(<ExportMenu xlsx="/admin/reports/xlsx" pdf="/admin/reports/pdf" csv="/admin/export/reports" importXlsx="/admin/products/export/xlsx" />);
        open();

        for (const label of ['تصدير كملف إكسل', 'تصدير كملف PDF', 'تصدير كملف CSV', 'تصدير للاستيراد (Excel)']) {
            const href = (await screen.findByText(label)).closest('a')!.getAttribute('href')!;
            const params = new URL(href, 'http://x').searchParams;
            expect(params.get(EXPECTED_BUSINESS_PARAM), label).toBe('7');
            expect(params.get('status'), label).toBe('open');
        }
    });

    it('says the session changed when the server refuses a stale tab — not «try again»', async () => {
        asBusiness(7);
        render(<ExportMenu xlsx="/admin/reports/xlsx" />);
        open();
        fireEvent.click(await screen.findByText('تصدير كملف إكسل'));

        const frame = document.querySelector('iframe')!;
        expect(new URL(frame.src).searchParams.get(EXPECTED_BUSINESS_PARAM)).toBe('7');

        // ما يردّ به الخادمُ للتبويب القديم: صفحةٌ بعلامتها، لا ملفّ ولا كعكة
        await act(async () => {
            respond(frame, '<main data-stale-export="stale">…</main>');
        });

        // والقائمةُ ما زالت مفتوحةً فوقها (`aria-hidden` على ما سواها) — فيُقرأ التنبيهُ بعلامته
        await vi.waitFor(() => expect(document.querySelector('[data-export-failure]')).not.toBeNull());
        const alert = document.querySelector('[data-export-failure]')!;
        expect(alert.getAttribute('role')).toBe('alert');
        expect(alert.getAttribute('data-export-failure')).toBe('stale');
        expect(alert.textContent).toContain('تغيّرت جلسة النشاط منذ فتح هذه الصفحة');
    });

    it('downloadFile names the stale page — and nothing else', async () => {
        const stale = downloadFile('/admin/reports/xlsx');
        respond(document.querySelector('iframe')!, '<main data-stale-export="stale">…</main>');
        await expect(stale).resolves.toBe('stale');

        document.querySelectorAll('iframe').forEach((f) => f.remove());
        const other = downloadFile('/admin/reports/xlsx');
        respond(document.querySelector('iframe')!, '<h1>403</h1>');
        await expect(other).resolves.toBe('failed');
    });

    it('keeps the generic failure for any other error page', async () => {
        asBusiness(7);
        render(<ExportMenu xlsx="/admin/reports/xlsx" />);
        open();
        fireEvent.click(await screen.findByText('تصدير كملف إكسل'));

        const frame = document.querySelector('iframe')!;
        await act(async () => {
            respond(frame, '<h1>500</h1>');
        });

        await vi.waitFor(() => expect(document.querySelector('[data-export-failure]')).not.toBeNull());
        expect(document.querySelector('[data-export-failure]')!.getAttribute('data-export-failure')).toBe('failed');
    });

    it('puts the page business on a document panel’s download and print links', async () => {
        asBusiness(7);
        const fetch = vi.fn(async () => new Response('%PDF-1.4', { status: 200, headers: { 'content-type': 'application/pdf' } }));
        vi.stubGlobal('fetch', fetch);
        URL.createObjectURL = vi.fn(() => 'blob:doc');
        URL.revokeObjectURL = vi.fn();
        const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {});
        render(<DocumentPanel html="<p>ورقة</p>" url="/admin/orders/INV-000204/pdf" filename="INV-000204.pdf" label="فاتورة" />);

        // الطباعةُ رابط، والتحميلُ زرٌّ يجلب الملفّ — وكلاهما يحمل نشاطَ الصفحة
        const links = [...document.querySelectorAll('a[href]')].map((a) => a.getAttribute('href')!);
        expect(links.length).toBeGreaterThanOrEqual(1);
        fireEvent.click(screen.getByRole('button', { name: /تحميل/ }));
        await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(1));
        const fetched = String(fetch.mock.calls[0][0]);

        for (const href of [...links, fetched]) {
            expect(new URL(href, 'http://x').searchParams.get(EXPECTED_BUSINESS_PARAM)).toBe('7');
        }
        click.mockRestore();
    });
});
