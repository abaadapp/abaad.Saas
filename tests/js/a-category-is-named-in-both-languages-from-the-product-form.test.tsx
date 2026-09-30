import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { router } from '@inertiajs/react';

import ProductForm from '@/Pages/Admin/Products/partials/ProductForm';
import { pageProps } from './setup';

/**
 * القسمُ باسمين من شاشة المنتج — إضافةً وتسمية.
 *
 * ═══ ما يُحرس ═══
 *
 * كان «إضافة قسم» يُرسل العربيَّ وحده، فيبقى الموقعُ الإنجليزيّ يعرض القسمَ
 * بحروفه العربيّة. فصار الحقلان معًا، ويُرسَلان معًا. والقسمُ المختار يُعاد
 * تسميتُه في صفّه (`PATCH` بمعرّفه) — لا قسمٌ جديد.
 *
 * وما كان يُحرس قبله باقٍ: لا تنقّلَ ولا حفظَ للمنتج، و«إدخال» يحفظ القسم،
 * و«Esc» يُلغي.
 */

const categories = [
    { id: 4, name: 'هدايا', name_en: 'Gifts', products: 2, icon: '', color: '' },
    { id: 5, name: 'ورود', name_en: null, products: 1, icon: '', color: '' },
];

const draw = (product?: Record<string, unknown>) => {
    Object.assign(pageProps, { context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 } } });

    return render(<ProductForm categories={categories as never} currencyLabel="ر.ع" product={product as never} composition={null} />);
};

const answer = (body: unknown, ok = true) =>
    vi.fn(async () => ({ ok, status: ok ? 200 : 422, json: async () => body }));

const sent = (fetch: ReturnType<typeof vi.fn>) => {
    const [url, init] = fetch.mock.calls[0] as unknown as [string, RequestInit];

    return { url, method: init.method, body: JSON.parse(String(init.body)) };
};

describe('القسمُ باسمين من شاشة المنتج', () => {
    beforeEach(() => vi.mocked(router.visit).mockClear());
    afterEach(() => vi.unstubAllGlobals());

    it('«إضافة قسم» يفتح حقلين — العربيّ والإنجليزيّ', () => {
        draw();
        fireEvent.click(screen.getByRole('button', { name: 'إضافة قسم' }));

        expect(screen.getByLabelText('اسم القسم بالعربية')).toBeInTheDocument();
        expect(screen.getByLabelText('اسم القسم بالإنجليزية')).toHaveAttribute('dir', 'ltr');
        expect(screen.getByLabelText('اسم القسم بالإنجليزية')).toHaveAttribute('placeholder', 'Category name in English');
    });

    it('ويُرسل الاسمين — و«إدخال» يحفظ القسمَ لا المنتج، ويُختار القسمُ الجديد', async () => {
        const fetch = answer({ ok: true, category: { id: 9, name: 'الإضافات الخاصة', name_en: 'Special Add-ons' } });
        vi.stubGlobal('fetch', fetch);
        draw();

        fireEvent.change(screen.getByPlaceholderText('مثال: باقة ورد أحمر'), { target: { value: 'باقة لم تُحفظ' } });
        fireEvent.click(screen.getByRole('button', { name: 'إضافة قسم' }));
        fireEvent.change(screen.getByLabelText('اسم القسم بالعربية'), { target: { value: 'الإضافات الخاصة' } });
        fireEvent.change(screen.getByLabelText('اسم القسم بالإنجليزية'), { target: { value: 'Special Add-ons' } });
        // و`false` = مُنع الحدث: «إدخال» في حقلٍ داخل النموذج كان يُرسل المنتجَ نصفَ مكتوب
        expect(fireEvent.keyDown(screen.getByLabelText('اسم القسم بالإنجليزية'), { key: 'Enter' })).toBe(false);

        await waitFor(() => expect(screen.queryByTestId('category-draft')).toBeNull());

        expect(fetch).toHaveBeenCalledTimes(1);
        expect(sent(fetch)).toEqual({
            url: '/admin.products.categories.store',
            method: 'POST',
            body: { name: 'الإضافات الخاصة', name_en: 'Special Add-ons' },
        });
        // والقسمُ الجديد هو المختار، والمنتجُ لم يُرسَل ولم تُغادَر الشاشة
        expect(screen.getByRole('combobox')).toHaveTextContent('الإضافات الخاصة');
        expect(router.visit).not.toHaveBeenCalled();
        expect(screen.getByPlaceholderText('مثال: باقة ورد أحمر')).toHaveValue('باقة لم تُحفظ');
    });

    it('والإنجليزيُّ اختياريّ — يُرسَل `null` لا نصًّا فارغًا', async () => {
        const fetch = answer({ ok: true, category: { id: 10, name: 'نباتات', name_en: null } });
        vi.stubGlobal('fetch', fetch);
        draw();

        fireEvent.click(screen.getByRole('button', { name: 'إضافة قسم' }));
        fireEvent.change(screen.getByLabelText('اسم القسم بالعربية'), { target: { value: '  نباتات ' } });
        fireEvent.click(screen.getByRole('button', { name: 'حفظ القسم' }));

        await waitFor(() => expect(fetch).toHaveBeenCalledTimes(1));
        expect(sent(fetch).body).toEqual({ name: 'نباتات', name_en: null });
    });

    it('و«Esc» يُلغي بلا طلب', () => {
        const fetch = answer({});
        vi.stubGlobal('fetch', fetch);
        draw();

        fireEvent.click(screen.getByRole('button', { name: 'إضافة قسم' }));
        fireEvent.change(screen.getByLabelText('اسم القسم بالعربية'), { target: { value: 'شيءٌ' } });
        fireEvent.keyDown(screen.getByLabelText('اسم القسم بالعربية'), { key: 'Escape' });

        expect(screen.queryByTestId('category-draft')).toBeNull();
        expect(fetch).not.toHaveBeenCalled();
    });

    it('والقسمُ المختار يُسمّى في صفّه — بمعرّفه، والحقلان مملوءان بما فيه', async () => {
        const fetch = answer({ ok: true, category: { id: 4, name: 'هدايا', name_en: 'Presents' } });
        vi.stubGlobal('fetch', fetch);
        draw({ id: 41, name: 'باقة', cat: 'هدايا', price: 20, cost: 8, qty: 5, alert: 1, active: true, tax: null, discount: 0 });

        fireEvent.click(screen.getByRole('button', { name: 'تعديل اسم القسم' }));
        expect(screen.getByLabelText('اسم القسم بالعربية')).toHaveValue('هدايا');
        expect(screen.getByLabelText('اسم القسم بالإنجليزية')).toHaveValue('Gifts');

        fireEvent.change(screen.getByLabelText('اسم القسم بالإنجليزية'), { target: { value: 'Presents' } });
        fireEvent.click(screen.getByRole('button', { name: 'حفظ القسم' }));

        await waitFor(() => expect(screen.queryByTestId('category-draft')).toBeNull());
        expect(sent(fetch)).toEqual({
            url: '/admin.products.categories.update/4',
            method: 'PATCH',
            body: { name: 'هدايا', name_en: 'Presents' },
        });
        expect(screen.getByRole('combobox')).toHaveTextContent('هدايا');
    });

    it('ولا زرَّ تسميةٍ قبل أن يُختار قسم', () => {
        draw();

        expect(screen.queryByRole('button', { name: 'تعديل اسم القسم' })).toBeNull();
    });

    it('ورفضُ الخادم يُقال بنصّه — ويبقى الحقلان', async () => {
        vi.stubGlobal('fetch', answer({ errors: { name: ['يوجد قسمٌ بهذا الاسم.'] } }, false));
        draw();

        fireEvent.click(screen.getByRole('button', { name: 'إضافة قسم' }));
        fireEvent.change(screen.getByLabelText('اسم القسم بالعربية'), { target: { value: 'هدايا' } });
        fireEvent.click(screen.getByRole('button', { name: 'حفظ القسم' }));

        expect(await screen.findByText('يوجد قسمٌ بهذا الاسم.')).toBeInTheDocument();
        expect(screen.getByTestId('category-draft')).toBeInTheDocument();
    });

    it('وحقلا اسم المنتج يقولان لغتَهما', () => {
        draw();

        expect(screen.getByText('اسم المنتج بالعربية')).toBeInTheDocument();
        expect(screen.getByText('اسم المنتج بالإنجليزية')).toBeInTheDocument();
    });
});
