import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { router as core } from '@inertiajs/core';

import { pageProps } from './setup';

vi.mock('@/Layouts/AdminLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <div>{children}</div> }));

const { default: ProductForm } = await import('@/Pages/Admin/Products/partials/ProductForm');

/**
 * «حالة المنتج في الموقع الإلكتروني» — متوفر، أو نفدت الكمية، أو مخفي.
 *
 * ═══ ما يُحرس ═══
 *
 *   - تظهر الخاناتُ الثلاث لمن يرسم موقعُه الشارة وحده (`websiteStatus`)، وغيرُه
 *     يبقى على مفتاح «متاح للبيع في المتجر الإلكتروني» كما كان.
 *   - في التعديل تُحفظ الحالُ وحدها بطلبها (`admin.products.websiteStatus`) لا
 *     بحفظ النموذج — فلا تُكتب الكمّيّةُ معها — ويحمل النموذجُ بعدها ما ردّه الخادم.
 *   - ورفضُ الخادم يُقال، والاختيارُ يبقى كما كان.
 *   - وفي الإضافة تُرسل مع المنتج الجديد.
 *
 * وما يحرسه الخادم: `ASoldOutProductIsShownButNotSoldTest`.
 */

const PRODUCT = {
    id: 9, name: 'باقة', name_en: null, label: 'باقة', cat: '—', price: 20, cost: 8, qty: 4,
    sku: 'X-1', barcode: '1', image: null, stock_status: 'متوفر', active: true, alert: 1, tax: 0, discount: 0,
    published: true,
};

let sent: Record<string, unknown>[] = [];
let calls: { url: string; init: RequestInit }[] = [];

function answer(status: number, body: unknown) {
    vi.stubGlobal('fetch', vi.fn(async (url: string, init: RequestInit) => {
        calls.push({ url, init });

        return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
    }));
}

describe('حالة المنتج في الموقع الإلكتروني', () => {
    beforeEach(() => {
        sent = [];
        calls = [];
        vi.spyOn(core, 'post').mockImplementation(((_url: string, data: Record<string, unknown>) => {
            sent.push(data);
        }) as never);
        Object.assign(pageProps, { context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 } } });
    });

    afterEach(() => {
        vi.restoreAllMocks();
        vi.unstubAllGlobals();
    });

    const draw = (product: Record<string, unknown> | null, websiteStatus: 'available' | 'sold_out' | 'hidden' | null) =>
        render(<ProductForm categories={[]} currencyLabel="ر.ع" product={product as never} websiteStatus={websiteStatus} />);
    const radio = (key: string) => screen.getByTestId(`website-status-${key}`);
    const save = async () => {
        await act(async () => {
            fireEvent.submit(document.querySelector('form')!);
        });
    };

    it('لا تظهر لمن لا يرسم موقعُه الشارة — ويبقى مفتاحُ الموقع كما كان', () => {
        draw(PRODUCT, null);

        expect(screen.queryByTestId('product-website-status')).toBeNull();
        expect(screen.getByRole('switch', { name: 'متاح للبيع في المتجر الإلكتروني' })).toBeTruthy();
    });

    it('تُفتح على الحال المحفوظة — بثلاث خاناتٍ تُلمس', () => {
        draw({ ...PRODUCT, website_sold_out: true }, 'sold_out');

        expect(screen.getByRole('radiogroup', { name: 'حالة المنتج في الموقع الإلكتروني' })).toBeTruthy();
        expect(radio('sold_out').getAttribute('aria-checked')).toBe('true');
        expect(radio('available').getAttribute('aria-checked')).toBe('false');
        expect(screen.getByTestId('product-website-status-says').textContent).toContain('«نفدت الكمية»');
        expect(screen.queryByRole('switch', { name: 'متاح للبيع في المتجر الإلكتروني' })).toBeNull();
    });

    it('في التعديل تُحفظ الحالُ وحدها — والنموذجُ يحمل ما ردّه الخادم', async () => {
        answer(200, { ok: true, status: 'sold_out', published: true, website_sold_out: true });
        draw(PRODUCT, 'available');

        await act(async () => {
            fireEvent.click(radio('sold_out'));
        });

        expect(calls).toHaveLength(1);
        expect(calls[0].url).toBe('/admin.products.websiteStatus/9');
        expect(calls[0].init.method).toBe('PATCH');
        expect(JSON.parse(String(calls[0].init.body))).toEqual({ status: 'sold_out' });
        expect(radio('sold_out').getAttribute('aria-checked')).toBe('true');
        expect(sent).toHaveLength(0);

        await save();
        expect(sent[0]).toMatchObject({ published: true, website_sold_out: true });
    });

    it('ورفضُ الخادم يُقال — والاختيارُ يبقى كما كان', async () => {
        answer(500, { message: 'Server Error' });
        draw(PRODUCT, 'available');

        await act(async () => {
            fireEvent.click(radio('hidden'));
        });

        expect(screen.getByTestId('product-website-status-error').textContent).toBeTruthy();
        expect(radio('available').getAttribute('aria-checked')).toBe('true');
    });

    it('وفي الإضافة تُكتب في النموذج — بلا طلبٍ قبل أن يوجد المنتج', () => {
        answer(200, {});
        draw(null, 'available');

        fireEvent.click(radio('sold_out'));
        expect(radio('sold_out').getAttribute('aria-checked')).toBe('true');
        expect(screen.getByTestId('product-channels-summary').textContent).toBe('يُباع في نقطة البيع والمتجر الإلكتروني.');

        // والمخفيُّ `published` نفسُه — يقوله سطرُ القنوات
        fireEvent.click(radio('hidden'));
        expect(radio('hidden').getAttribute('aria-checked')).toBe('true');
        expect(screen.getByTestId('product-channels-summary').textContent).toBe('يُباع في نقطة البيع فقط — مخفي من المتجر الإلكتروني.');
        expect(calls).toHaveLength(0);
    });
});
