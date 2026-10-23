import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { router as core } from '@inertiajs/core';

import { pageProps } from './setup';
import { DESCRIPTION_SUMMARY, descriptionShows } from '@/Pages/Admin/Products/partials/channels';
import ProductDescriptionButton from '@/Pages/Pos/partials/ProductDescriptionButton';

const { default: ProductForm } = await import('@/Pages/Admin/Products/partials/ProductForm');

/**
 * وصفُ الصنف بلغتين، ويُعرض حيث يختار صاحبُه — كما تعرضه الشاشات.
 *
 * ═══ ما يُحرس ═══
 *
 *   - القاعدةُ الواحدة (`descriptionShows`): مفتاحا الوصف غيرُ قناتَي البيع،
 *     والقناةُ التي لا تبيع لا يظهر فيها وصف.
 *   - نموذجُ المنتج: مجموعةٌ عربيّة وأخرى إنجليزيّة، وقسمٌ مستقلّ «أين يظهر
 *     وصف المنتج؟» يُفتح بما حُفظ ويُرسل كما اختير — ومفتاحُ البيع لا يحرّكه.
 *   - الصندوق: زرُّ الوصف لا يُرسم بلا نصّ، ويفتح نافذةً لا تُضيف إلى السلّة.
 *
 * وما يحرسه الخادم: `AProductDescribesItselfInTwoLanguagesWhereItsOwnerChoosesTest`.
 */

describe('descriptionShows — أين يظهر الوصفُ فعلًا', () => {
    const sells = { active: true, pos_enabled: true, published: true };

    it('أربعُ حالاتٍ والصنفُ يُباع في القناتين', () => {
        expect(descriptionShows({ ...sells, description_pos_enabled: true, description_web_enabled: true })).toBe('both');
        expect(descriptionShows({ ...sells, description_pos_enabled: true, description_web_enabled: false })).toBe('pos');
        expect(descriptionShows({ ...sells, description_pos_enabled: false, description_web_enabled: true })).toBe('online');
        expect(descriptionShows({ ...sells, description_pos_enabled: false, description_web_enabled: false })).toBe('none');
    });

    it('والقناةُ التي لا تبيع لا يظهر فيها وصف — والموقوفُ لا وصفَ له', () => {
        const both = { description_pos_enabled: true, description_web_enabled: true };
        expect(descriptionShows({ ...both, active: true, pos_enabled: false, published: true })).toBe('online');
        expect(descriptionShows({ ...both, active: true, pos_enabled: true, published: false })).toBe('pos');
        expect(descriptionShows({ ...both, active: false, pos_enabled: true, published: true })).toBe('none');
    });

    it('والغائبُ كما كان: في الموقع نعم، وفي الصندوق لا', () => {
        expect(descriptionShows(sells)).toBe('online');
        expect(DESCRIPTION_SUMMARY.online).toBe('الوصف يظهر للعميل في الموقع فقط.');
    });
});

const PRODUCT = {
    id: 9, name: 'باقة ورد فاخرة', name_en: 'Luxury Rose Bouquet', label: 'باقة ورد فاخرة', cat: '—', price: 20, cost: 8, qty: 4,
    sku: 'X-1', barcode: '1', image: null, stock_status: 'متوفر', active: true, alert: 1, tax: 0, discount: 0,
    pos_enabled: true, published: true, description_pos_enabled: false, description_web_enabled: true,
};

let sent: Record<string, unknown>[] = [];

describe('الوصفان ومفتاحاهما في نموذج المنتج', () => {
    beforeEach(() => {
        sent = [];
        vi.spyOn(core, 'post').mockImplementation(((_url: string, data: Record<string, unknown>) => {
            sent.push(data);
        }) as never);
        Object.assign(pageProps, { context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 } } });
    });

    afterEach(() => vi.restoreAllMocks());

    const draw = (product: Record<string, unknown> | null = PRODUCT, description = '', descriptionEn = '') =>
        render(
            <ProductForm
                categories={[]}
                currencyLabel="ر.ع"
                product={product as never}
                description={description}
                descriptionEn={descriptionEn}
            />,
        );
    const toggle = (name: string) => screen.getByRole('switch', { name });
    const summary = () => screen.getByTestId('product-description-summary').textContent;
    const save = async () => {
        await act(async () => {
            fireEvent.submit(document.querySelector('form')!);
        });
    };

    it('كلُّ لغةٍ في مجموعتها — ويُفتح التعديلُ بالوصفين المحفوظين', () => {
        draw(PRODUCT, 'ورد أحمر', 'Red roses');

        const ar = screen.getByTestId('product-info-ar');
        const en = screen.getByTestId('product-info-en');
        expect(ar.querySelector('textarea')!.value).toBe('ورد أحمر');
        expect(en.querySelector('textarea')!.value).toBe('Red roses');
        expect(en.querySelector('textarea')!.getAttribute('dir')).toBe('ltr');
        expect(en.querySelector('input')!.value).toBe('Luxury Rose Bouquet');
    });

    it('صنفٌ جديد: وصفُه في الموقع وحده', () => {
        draw(null);

        expect(toggle('إظهار وصف المنتج في نقطة البيع (POS)').getAttribute('aria-checked')).toBe('false');
        expect(toggle('إظهار وصف المنتج في الموقع الإلكتروني').getAttribute('aria-checked')).toBe('true');
        expect(summary()).toBe('الوصف يظهر للعميل في الموقع فقط.');
    });

    it('مثالُ سعود: يُباع في القناتين ووصفُه في الموقع وحده — ثمّ يُفتح للكاشير', async () => {
        draw(PRODUCT, 'ورد أحمر');
        expect(summary()).toBe('الوصف يظهر للعميل في الموقع فقط.');

        fireEvent.click(toggle('إظهار وصف المنتج في نقطة البيع (POS)'));
        expect(summary()).toBe('الوصف يظهر للكاشير في نقطة البيع وللعميل في الموقع.');

        await save();
        expect(sent.at(-1)).toMatchObject({
            pos_enabled: true, published: true, description_pos_enabled: true, description_web_enabled: true,
            description: 'ورد أحمر', description_en: '',
        });
    });

    it('إغلاقُ البيع في الصندوق لا يحرّك مفتاحَ وصفه — يبقى محفوظًا ويقول لماذا لا يظهر', async () => {
        draw({ ...PRODUCT, description_pos_enabled: true });
        fireEvent.click(toggle('متاح للبيع في نقطة البيع (POS)'));

        expect(toggle('إظهار وصف المنتج في نقطة البيع (POS)').getAttribute('aria-checked')).toBe('true');
        expect(screen.getByText('محفوظ — ولن يظهر ما دام المنتج لا يُباع في نقطة البيع')).toBeTruthy();
        expect(summary()).toBe('الوصف يظهر للعميل في الموقع فقط.');

        await save();
        expect(sent.at(-1)).toMatchObject({ pos_enabled: false, description_pos_enabled: true });
    });

    it('وإطفاءُ وصف الموقع لا يُطفئ بيعَه', async () => {
        draw(PRODUCT);
        fireEvent.click(toggle('إظهار وصف المنتج في الموقع الإلكتروني'));
        expect(toggle('متاح للبيع في المتجر الإلكتروني').getAttribute('aria-checked')).toBe('true');
        expect(summary()).toBe('الوصف مخفي في القناتين.');

        await save();
        expect(sent.at(-1)).toMatchObject({ published: true, description_web_enabled: false });
    });
});

describe('زرُّ الوصف في الصندوق', () => {
    it('لا يُرسم بلا نصّ', () => {
        render(<ProductDescriptionButton name="باقة" text={null} />);
        expect(screen.queryByTestId('pos-product-description')).toBeNull();
    });

    it('يفتح نافذةً بالوصف واسمِ الصنف', () => {
        render(<ProductDescriptionButton name="باقة ورد فاخرة" text={'ورد أحمر\nبلفّة حرير'} />);
        fireEvent.click(screen.getByTestId('pos-product-description'));

        const dialog = screen.getByTestId('pos-product-description-dialog');
        expect(dialog.textContent).toContain('باقة ورد فاخرة');
        expect(dialog.textContent).toContain('بلفّة حرير');
    });
});
