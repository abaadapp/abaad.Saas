import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import ProductForm from '@/Pages/Admin/Products/partials/ProductForm';
import { pageProps } from './setup';

/**
 * مفتاحُ «ربط المنتج بالمخزون» في نموذج المنتج.
 *
 * مضاءٌ افتراضًا (كلُّ ما سبقه كان بضاعة)، وحين يُطفأ تختفي الكميّةُ
 * وحدُّ التنبيه — لا معنى لهما — ويُقال ما يترتّب على الإطفاء.
 */
const form = (product?: Record<string, unknown>) => {
    Object.assign(pageProps, { context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 } } });

    return render(
        <ProductForm
            categories={[]}
            currencyLabel="ر.ع"
            product={product as never}
        />,
    );
};

const openStockTab = () => fireEvent.click(screen.getByRole('tab', { name: 'المخزون' }));

describe('ربطُ المنتج بالمخزون', () => {
    it('مضاءٌ افتراضًا والكميّةُ ظاهرة', () => {
        form();
        openStockTab();

        expect(screen.getByRole('switch', { name: 'ربط المنتج بالمخزون' })).toHaveAttribute('aria-checked', 'true');
        expect(screen.getByText('الكمية المتوفرة')).toBeInTheDocument();
        expect(screen.getByText('حد التنبيه')).toBeInTheDocument();
    });

    it('إطفاؤه يُخفي الكميّةَ وحدَّها ويقول ما يترتّب', () => {
        form();
        openStockTab();

        fireEvent.click(screen.getByRole('switch', { name: 'ربط المنتج بالمخزون' }));

        expect(screen.queryByText('الكمية المتوفرة')).toBeNull();
        expect(screen.queryByText('حد التنبيه')).toBeNull();
        expect(screen.getByText(/لا يُخصم ولا ينفد ولا يُنبَّه/)).toBeInTheDocument();
    });

    it('ومنتجٌ محفوظٌ بلا ربطٍ يُفتح مطفأً', () => {
        form({ id: 1, name: 'رسم توصيل', price: 2, cost: 0, qty: 0, alert: 10, active: true, tracks_stock: false, tax: null, discount: 0 });
        openStockTab();

        expect(screen.getByRole('switch', { name: 'ربط المنتج بالمخزون' })).toHaveAttribute('aria-checked', 'false');
        expect(screen.queryByText('الكمية المتوفرة')).toBeNull();
    });
});
