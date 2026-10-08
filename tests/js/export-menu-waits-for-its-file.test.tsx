import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import ExportMenu from '@/Components/ExportMenu';
import { downloadFile, withFilters } from '@/lib/exportLink';

/**
 * زرُّ التصدير ينتظر ملفَّه — ولا يُنزّله مرّتين.
 *
 * كان رابطًا عاريًا: يُضغط فلا يقول شيئًا، فيُضغط ثانيةً والملفُّ الكبير
 * ما زال يُبنى. وصار يقول «جاري التصدير…» ويُعطَّل حتى يردّ الخادمُ بكعكة
 * الرمز الذي أرسله (`Workbook::signal`)، وما في الرابط من مرشِّحاتٍ يصل
 * الملفَّ كما كان (`withFilters`).
 */

const open = () => fireEvent.pointerDown(screen.getByTestId('export-menu'), { button: 0, ctrlKey: false });

beforeEach(() => {
    window.history.replaceState({}, '', '/admin/orders?status=%D9%85%D9%83%D8%AA%D9%85%D9%84&page=3');
    vi.useFakeTimers({ shouldAdvanceTime: true });
});

afterEach(() => {
    vi.useRealTimers();
    document.cookie = 'download_token=; Max-Age=0; path=/';
    document.querySelectorAll('iframe').forEach((f) => f.remove());
});

describe('زرُّ التصدير', () => {
    it('يحمل مرشِّحات الشاشة ولا يحمل رقم الصفحة', () => {
        const url = withFilters('/admin/orders/export-xlsx');

        expect(url).toContain('status=');
        expect(url).not.toContain('page=');
    });

    it('يقول «جاري التصدير…» ويُعطَّل حتى يبدأ الملفّ — ثمّ يعود', async () => {
        render(<ExportMenu xlsx="/admin/orders/export-xlsx" />);
        open();
        fireEvent.click(await screen.findByText('تصدير كملف إكسل'));

        const button = screen.getByTestId('export-menu');
        expect(button).toBeDisabled();
        expect(button.textContent).toContain('جاري التصدير');

        // الإطارُ الخفيّ طلب الملفَّ بمرشِّحات الشاشة ورمزٍ يُعاد كعكة
        const frame = document.querySelector('iframe')!;
        const src = new URL(frame.src);
        expect(src.searchParams.get('status')).toBe('مكتمل');
        const token = src.searchParams.get('download_token')!;
        expect(token).toMatch(/^[a-z0-9]{6,40}$/);

        // نقرةٌ ثانية والملفُّ لم يبدأ: لا طلبَ ثانٍ
        fireEvent.click(button);
        expect(document.querySelectorAll('iframe')).toHaveLength(1);

        document.cookie = `download_token=${token}; path=/`;
        await act(async () => {
            vi.advanceTimersByTime(400);
        });

        expect(screen.getByTestId('export-menu')).not.toBeDisabled();
        expect(screen.getByTestId('export-menu').textContent).not.toContain('جاري التصدير');
    });

    it('ملفُّ الاستيراد بندٌ باسمه لا «Excel» ثانٍ', async () => {
        render(<ExportMenu xlsx="/admin/products/xlsx" importXlsx="/admin/products/export/xlsx" />);
        open();

        expect(await screen.findByText('تصدير كملف إكسل')).toBeTruthy();
        expect(screen.getByText('تصدير للاستيراد (Excel)')).toBeTruthy();
    });

    it('صفحةُ خطأٍ تُحمَّل في الإطار تُقال ولا تستبدل الشاشة', async () => {
        const pending = downloadFile('/admin/orders/export-xlsx');
        const frame = document.querySelector('iframe')!;
        frame.dispatchEvent(new Event('load'));

        await expect(pending).resolves.toBe('failed');
    });
});
