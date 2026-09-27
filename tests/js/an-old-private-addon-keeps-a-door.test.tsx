import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import ProductForm from '@/Pages/Admin/Products/partials/ProductForm';
import { pageProps } from './setup';

/**
 * الإضافةُ المملوكةُ لمنتج — بابُها في «المعلومات الأساسية»، ولا بابَ لإنشائها.
 *
 * ═══ الحال التي أنشأت هذا الملفّ ═══
 *
 * زال قسمُ التركيب وكان بابَها الوحيد، وبقي في الإنتاج صفوفٌ كتبها قبل زواله:
 * تُعرض في الكاشير ولا يملك التاجرُ تصحيحَ سعرها ولا إطفاءها. فأُعيد بابُ
 * التعديل وحدَه، في شاشة مالكها.
 *
 * ═══ وما يُحرَس هنا ═══
 *
 * أنّ الحقلَ لا يُعرض إلّا لمن له صفوف: منتجٌ بلا إضافةٍ خاصّةٍ لا يرى حقلًا
 * فارغًا يسأل عن بابٍ لا وجود له، وشاشةُ الإنشاء كذلك.
 *
 * وأنّه بابُ تعديلٍ لا بابُ تحويل: لا يُعرض للمملوكة مدًى يُبدَّل، ولا يُعرض
 * في حقلها زرُّ إنشاء. (والخادمُ يردّ ذلك ردًّا صريحًا — انظر
 * `APrivateAddonIsEditedNotBornTest`.)
 *
 * وأنّ إضافةَ منتجٍ آخر لا تُعرض في شاشة هذا: الخادمُ لا يرسلها، والشاشةُ
 * تشترط ثانيًا.
 */

const currency = { code: 'OMR', symbol: 'ر.ع', decimals: 3 };

const shopAddon = {
    value: 7, label: 'تغليف', price: 1, active: true, private: false,
    scope: 'all', inventory_product_id: null,
};

/** صفٌّ كتبه القسمُ الذي زال: مملوكٌ للمنتج 41 */
const ownAddon = (over: Record<string, unknown> = {}) => ({
    value: 12, label: 'شريط ذهبي', price: 0.5, active: true, private: true,
    product_id: 41, scope: 'product', inventory_product_id: null, ...over,
});

const payload = (addons: Record<string, unknown>[]) => ({
    addons,
    stock_items: [{ value: 3, label: 'ورق', quantity: 50 }],
    products: [{ value: 9, label: 'باقة' }],
});

const saved = (id: number) => ({
    id, name: 'باقة ورد', price: 20, cost: 8, qty: 5, alert: 1, active: true, tax: null, discount: 0,
});

const form = (product: Record<string, unknown> | undefined, addons: Record<string, unknown>[]) => {
    Object.assign(pageProps, { context: { currency } });

    return render(
        <ProductForm
            categories={[]}
            currencyLabel="ر.ع"
            product={product as never}
            composition={payload(addons) as never}
        />,
    );
};

describe('حقلُ إضافات هذا المنتج', () => {
    it('يُعرض لمالكها وحدَه', () => {
        form(saved(41), [shopAddon, ownAddon()]);

        expect(screen.getByText('إضافات خاصّة بهذا المنتج')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /شريط ذهبي/ })).toBeInTheDocument();
    });

    /*
     * وهذا موضعُ الخطأ المحتمل: شرطٌ على `private` وحدَه يعرض إضافةَ الجار
     * في شاشة هذا المنتج — فيعدّل سعرَ ما ليس له.
     */
    it('ولا يُعرض في شاشة منتجٍ آخر', () => {
        form(saved(8), [shopAddon, ownAddon()]);

        expect(screen.queryByText('إضافات خاصّة بهذا المنتج')).toBeNull();
        expect(screen.queryByText('شريط ذهبي')).toBeNull();
    });

    it('ولا في شاشة الإنشاء', () => {
        form(undefined, [shopAddon, ownAddon()]);

        expect(screen.queryByText('إضافات خاصّة بهذا المنتج')).toBeNull();
    });

    it('ولا يُعرض لمنتجٍ لا صفوفَ له — حقلٌ فارغٌ يسأل عن بابٍ لا وجود له', () => {
        form(saved(41), [shopAddon]);

        expect(screen.queryByText('إضافات خاصّة بهذا المنتج')).toBeNull();
        expect(screen.getByText('تغليف')).toBeInTheDocument();
    });

    /* ولا تُحسب مرّتين: هي في حقلها لا في حقل إضافات المتجر */
    it('ولا تظهر ضمن إضافات المتجر', () => {
        form(saved(41), [shopAddon, ownAddon()]);

        const shopField = screen.getByText('إضافات مع كلّ المنتجات').closest('div');

        expect(shopField?.textContent).toContain('تغليف');
        expect(shopField?.textContent).not.toContain('شريط ذهبي');
    });

    it('والمعطّلةُ تُقال معطّلة', () => {
        form(saved(41), [shopAddon, ownAddon({ active: false })]);

        expect(screen.getByRole('button', { name: /معطّلة/ })).toBeInTheDocument();
    });
});

describe('ونافذتُها بابُ تعديلٍ لا بابُ تحويل', () => {
    const open = (over: Record<string, unknown> = {}) => {
        form(saved(41), [shopAddon, ownAddon(over)]);
        fireEvent.click(screen.getByRole('button', { name: /شريط ذهبي/ }));
    };

    it('لا مدًى يُبدَّل — ويُقال لمن تُعرض', () => {
        open();

        expect(screen.getByText('هذا المنتج وحده — ولا يُغيَّر مداها.')).toBeInTheDocument();
        expect(screen.queryByText('جميع المنتجات')).toBeNull();
        expect(screen.queryByText('منتجات محددة')).toBeNull();
    });

    it('ويُعرض بابُ التعطيل', () => {
        open();

        expect(screen.getByText('هل تُعرض في الكاشير؟')).toBeInTheDocument();
        expect(screen.getByText('مفعّلة')).toBeInTheDocument();
        expect(screen.getByText('معطّلة')).toBeInTheDocument();
    });

    /* وإضافةُ المتجر تبقى كما كانت: مداها يُختار، ولا بابَ تعطيلٍ لها هنا */
    it('وإضافةُ المتجر يبقى لها مداها', () => {
        form(saved(41), [shopAddon, ownAddon()]);
        fireEvent.click(screen.getByRole('button', { name: /تغليف/ }));

        expect(screen.getByText('جميع المنتجات')).toBeInTheDocument();
        expect(screen.getByText('منتجات محددة')).toBeInTheDocument();
        expect(screen.queryByText('هذا المنتج وحده — ولا يُغيَّر مداها.')).toBeNull();
    });

    it('ولا بابَ إنشاءٍ لواحدةٍ جديدة', () => {
        form(saved(41), [shopAddon, ownAddon()]);
        fireEvent.click(screen.getByRole('button', { name: 'إضافة جديدة' }));

        expect(screen.getByText('جميع المنتجات')).toBeInTheDocument();
        expect(screen.queryByText('هذا المنتج فقط')).toBeNull();
        expect(screen.queryByText('هذا المنتج وحده — ولا يُغيَّر مداها.')).toBeNull();
    });
});
