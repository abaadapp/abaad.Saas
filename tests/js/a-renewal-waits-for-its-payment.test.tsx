import { fireEvent, render, screen } from '@testing-library/react';
import { router } from '@inertiajs/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { RenewalPanel, type Renewal } from '@/Pages/Platform/Businesses/Show';
import { pageProps } from './setup';

/**
 * التجديدُ في لوحة المنصّة يُصدر فاتورة، ولا يقول «جُدّد» قبل سدادها.
 *
 * ═══ وما يُحرَس ═══
 *
 * أنّ الدورات الثلاث تُعرض بأسعارها، وأنّ التأكيد يقول إنّ المدّة لا تبدأ
 * قبل السداد، وأنّ الأزرار تُطفأ ما دام تجديدٌ ينتظر — ويُقال أيُّ فاتورةٍ
 * وبكم — وأنّ «إلغاء الفاتورة» لا يظهر إلّا لفاتورة ذلك التجديد.
 *
 * والخادمُ يرفض الإصدارَ الثاني والسدادَ المكرّر على أيّ حال
 * (`ARenewalStartsOnlyOnceItsInvoiceIsPaidTest`) — فهذا راحةٌ لمن يضغط.
 */
const currency = { code: 'OMR', symbol: 'ر.ع.', decimals: 3 } as never;

const renewal = (over: Partial<Renewal> = {}): Renewal => ({
    monthly: 29.9,
    semiannual: 179.4,
    yearly: 299,
    endsAt: '2026-12-31',
    status: 'نشط',
    pending: null,
    unpaid: [],
    ...over,
});

const show = (r: Renewal) => {
    Object.assign(pageProps, { translations: {} });
    render(<RenewalPanel businessId={7} renewal={r} currency={currency} />);
};

afterEach(() => {
    vi.restoreAllMocks();
    vi.mocked(router.post).mockClear();
});

describe('تجديدُ الاشتراك ينتظر سدادَ فاتورته', () => {
    it('الدوراتُ الثلاث بأسعارها، ونصفُ السنة ستّةُ أشهر', () => {
        show(renewal());

        expect(screen.getByRole('button', { name: /جدّد شهرًا/ })).toHaveTextContent('29.900');
        expect(screen.getByRole('button', { name: /جدّد ستّة أشهر/ })).toHaveTextContent('179.400');
        expect(screen.getByRole('button', { name: /جدّد سنة/ })).toHaveTextContent('299.000');
    });

    it('والتأكيدُ يقول إنّ الاشتراك لا يُمدَّد قبل السداد — ثمّ يُرسل الدورة', () => {
        const ask = vi.spyOn(window, 'confirm').mockReturnValue(true);
        show(renewal());

        fireEvent.click(screen.getByRole('button', { name: /جدّد ستّة أشهر/ }));

        expect(ask.mock.calls[0][0]).toContain('لن يتم تمديد الاشتراك إلا بعد تسجيل السداد');
        expect(ask.mock.calls[0][0]).toContain('179.400');
        expect(router.post).toHaveBeenCalledTimes(1);
        const [url, body] = vi.mocked(router.post).mock.calls[0] as [string, { cycle: string }];
        expect(url).toContain('super-admin.businesses.renew');
        expect(body).toEqual({ cycle: 'semiannual' });
    });

    it('ومن رفض التأكيد لا يُصدر شيئًا', () => {
        vi.spyOn(window, 'confirm').mockReturnValue(false);
        show(renewal());

        fireEvent.click(screen.getByRole('button', { name: /جدّد سنة/ }));

        expect(router.post).not.toHaveBeenCalled();
    });

    it('وما دام تجديدٌ ينتظر: الأزرارُ مطفأة، والفاتورةُ ومبلغُها ظاهران', () => {
        show(
            renewal({
                pending: { invoice_id: 41, number: 'INV-P-0041', cycle: 'semiannual', amount: 179.4, issued: '2026-10-23' },
                unpaid: [
                    { id: 41, number: 'INV-P-0041', amount: 179.4, date: '2026-10-23', kind: 'اشتراك' },
                    { id: 40, number: 'INV-P-0040', amount: 5, date: '2026-10-20', kind: 'رسائل' },
                ],
            }),
        );

        for (const name of [/جدّد شهرًا/, /جدّد ستّة أشهر/, /جدّد سنة/]) {
            expect(screen.getByRole('button', { name })).toBeDisabled();
        }
        expect(screen.getAllByText('بانتظار السداد').length).toBeGreaterThan(0);
        expect(screen.getAllByText('INV-P-0041').length).toBeGreaterThan(0);
        expect(screen.getByText('لن يتم تمديد الاشتراك إلا بعد تسجيل السداد.')).toBeInTheDocument();

        // سدادٌ لكلّ فاتورة، وإلغاءٌ لفاتورة التجديد وحدها
        expect(screen.getAllByRole('button', { name: 'تسجيل السداد' })).toHaveLength(2);
        expect(screen.getAllByRole('button', { name: 'إلغاء الفاتورة' })).toHaveLength(1);
    });

    it('والسدادُ والإلغاءُ يقصدان فاتورتَهما بعينها', () => {
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        show(
            renewal({
                pending: { invoice_id: 41, number: 'INV-P-0041', cycle: 'monthly', amount: 29.9, issued: '2026-10-23' },
                unpaid: [{ id: 41, number: 'INV-P-0041', amount: 29.9, date: '2026-10-23', kind: 'اشتراك' }],
            }),
        );

        fireEvent.click(screen.getByRole('button', { name: 'تسجيل السداد' }));
        fireEvent.click(screen.getByRole('button', { name: 'إلغاء الفاتورة' }));

        const urls = vi.mocked(router.post).mock.calls.map((c) => String(c[0]));
        expect(urls[0]).toContain('super-admin.invoices.pay');
        expect(urls[0]).toContain('41');
        expect(urls[1]).toContain('super-admin.invoices.cancel');
        expect(urls[1]).toContain('41');
    });

    it('وبلا تجديدٍ معلّق لا شارةَ انتظار، والأزرارُ مفتوحة', () => {
        show(renewal());

        expect(screen.queryByText('بانتظار السداد')).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: /جدّد شهرًا/ })).toBeEnabled();
    });
});
