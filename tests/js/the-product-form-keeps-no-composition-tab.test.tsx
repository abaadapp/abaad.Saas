import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import ProductForm from '@/Pages/Admin/Products/partials/ProductForm';
import { pageProps } from './setup';

/**
 * شاشةُ المنتج بعد أن زال «التركيب» — أربعةُ أقسامٍ لا خمسة.
 *
 * ═══ وما يُحرَس هنا ═══
 *
 * أنّ التبويب زال **لكلّ تاجر**: لا شرطَ على شركةٍ ولا باقةٍ ولا نوع نشاط.
 * والشاشةُ واحدةٌ لهم جميعًا، فزوالُه منها زوالٌ عند الكلّ — ويُقاس ذلك
 * بشركتين مختلفتين، وبمنتجٍ تحمل حمولتُه تركيبًا كاملًا.
 *
 * وأنّ ما بقي بقي: إضافاتُ المتجر تُقرأ وتُنشأ من «المعلومات الأساسية»،
 * ومداها يُختار — «جميع المنتجات» أو «منتجات محدّدة». و«هذا المنتج فقط»
 * زال معه: كان يُنشئ إضافةً لا تظهر في قائمة المتجر ولا يديرها إلّا القسمُ
 * الذي زال، فيبقى بابٌ يكتب ما لا تُديره شاشةٌ بعده.
 */

const currency = { code: 'OMR', symbol: 'ر.ع', decimals: 3 };

/** حمولةُ `composition` كما يرسلها الخادم — وفيها ما لا تقرؤه الشاشة */
const payload = (over: Record<string, unknown> = {}) => ({
    addons: [
        { value: 7, label: 'تغليف', price: 1, active: true, private: false, scope: 'all', inventory_product_id: null },
    ],
    stock_items: [{ value: 3, label: 'ورق', quantity: 50 }],
    products: [{ value: 9, label: 'باقة' }],
    /* يرسلها الخادمُ ولا شيءَ في الواجهة يقرؤها — انظر `partials/addons.ts` */
    variants: [{ id: 1, name: 'كبير', price: 5 }],
    recipe: { items: [{ id: 2, component: 'ورد', quantity: 3 }] },
    addon_ids: [7],
    ...over,
});

const form = (product?: Record<string, unknown>, composition: Record<string, unknown> | null = payload()) => {
    Object.assign(pageProps, { context: { currency } });

    return render(
        <ProductForm
            categories={[]}
            currencyLabel="ر.ع"
            product={product as never}
            composition={composition as never}
        />,
    );
};

const tabNames = () => screen.getAllByRole('tab').map((t) => t.textContent?.trim());

describe('أقسامُ شاشة المنتج', () => {
    it('منتجٌ جديد: أربعةُ أقسامٍ ولا تركيب', () => {
        form();

        expect(tabNames()).toEqual(['المعلومات الأساسية', 'التسعير', 'المخزون', 'صور المنتج']);
        expect(screen.queryByRole('tab', { name: 'التركيب' })).toBeNull();
    });

    /*
     * والمنتجُ المحفوظُ الذي **له تركيبٌ فعلًا** لا يُستثنى.
     *
     * وهذا موضعُ الخطأ المحتمل: إخفاءٌ مشروطٌ بخلوّ المنتج يُبقي التبويبَ
     * لمن لديه مقاساتٌ أو وصفة — فلا يكون قد زال عند الجميع.
     */
    it('ومنتجٌ محفوظٌ له مقاساتٌ ووصفةٌ وإضافات: لا تركيب أيضًا', () => {
        form({ id: 41, name: 'باقة ورد', price: 20, cost: 8, qty: 5, alert: 1, active: true, tax: null, discount: 0 });

        expect(tabNames()).toEqual(['المعلومات الأساسية', 'التسعير', 'المخزون', 'صور المنتج']);
        expect(screen.queryByRole('tab', { name: 'التركيب' })).toBeNull();
    });

    /* شركةٌ أخرى، عملةٌ أخرى، حمولةٌ خالية — والنتيجةُ واحدة */
    it('وشركةٌ أخرى بلا أيّ تركيب: أربعةٌ كذلك', () => {
        Object.assign(pageProps, { context: { currency: { code: 'SAR', symbol: 'ر.س', decimals: 2 } } });
        form({ id: 8, name: 'قهوة', price: 3, cost: 1, qty: 2, alert: 1, active: true, tax: null, discount: 0 }, null);

        expect(tabNames()).toEqual(['المعلومات الأساسية', 'التسعير', 'المخزون', 'صور المنتج']);
        expect(screen.queryByRole('tab', { name: 'التركيب' })).toBeNull();
    });

    /* ولا يُشار إليه من قسمٍ آخر: إشارةٌ إلى بابٍ زال أسوأُ من غيابه */
    it('ولا ذكرَ للتركيب في الشاشة كلّها', () => {
        const { container } = form({ id: 41, name: 'باقة', price: 20, cost: 8, qty: 5, alert: 1, active: true, tax: null, discount: 0 });

        expect(container.textContent).not.toMatch(/التركيب/);
        expect(container.textContent).not.toMatch(/مقاسات/);
    });
});

describe('وإضافاتُ المتجر تبقى حيث كانت', () => {
    it('تُقرأ في المعلومات الأساسية', () => {
        form();

        expect(screen.getByText('تغليف')).toBeInTheDocument();
    });

    it('ومداها يُختار: مع الجميع أو مع منتجاتٍ محدّدة — ولا «هذا المنتج فقط»', () => {
        form({ id: 41, name: 'باقة', price: 20, cost: 8, qty: 5, alert: 1, active: true, tax: null, discount: 0 });

        fireEvent.click(screen.getByRole('button', { name: 'إضافة جديدة' }));

        expect(screen.getByText('جميع المنتجات')).toBeInTheDocument();
        expect(screen.getByText('منتجات محددة')).toBeInTheDocument();
        expect(screen.queryByText('هذا المنتج فقط')).toBeNull();
    });
});
