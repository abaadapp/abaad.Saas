import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { pageProps } from './setup';

vi.mock('@/Layouts/AdminLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <div>{children}</div> }));
vi.mock('@inertiajs/react', async () => {
    const actual = await vi.importActual<Record<string, unknown>>('@inertiajs/react');

    return {
        ...actual,
        usePage: () => ({ props: pageProps, url: '/', component: 'Test' }),
        router: { get: vi.fn(), post: vi.fn(), delete: vi.fn(), visit: vi.fn(), on: vi.fn() },
        Head: () => null,
    };
});

const { default: ProductShow } = await import('@/Pages/Admin/Products/Show');

/**
 * صفحةُ المنتج لمن يفتح المنتجاتِ وحدها في متجرٍ يؤوي بوتيكات.
 *
 * الخادمُ لا يُرسل حركاتِه ولا ما بيع منه (`BoutiqueAccess::hidesSales`)، والشاشةُ
 * ترسم ما وصل: لا جدولَ حركةٍ فارغًا يُقرأ «لم يُبَع شيء».
 */
const show = (over: Record<string, unknown>) => {
    Object.assign(pageProps, {
        translations: {}, errors: {}, context: { currency: { code: 'OMR', name: 'ريال', symbol: 'ر.ع', rate: 1, is_base: true, active: true } },
        product: { id: 1, name: 'قطعة فضّة', sku: 'S', barcode: '', cat: 'فضّة', price: 7, cost: 1, qty: 5, alert: 1, active: true, discount: 0, tax: 5, stock_status: 'متوفر', image: '' },
        thumbs: [], description: '', stats: [{ label: 'الكمية المتوفرة', value: '5 قطعة', icon: 'package', color: 'primary' }], movements: null, ...over,
    });

    return render(<ProductShow />);
};

describe('صفحةُ المنتج', () => {
    it('بلا حركاتٍ لمن لا يقرأها', () => {
        show({});
        expect(screen.queryByText('حركة المخزون')).toBeNull();
        expect(screen.queryByText('إجمالي المبيعات')).toBeNull();
        expect(screen.queryByText('هامش الربح')).toBeNull();
        expect(screen.getAllByText('قطعة فضّة').length).toBeGreaterThan(0);
    });

    it('وكما كانت لمن يقرؤها — ولو فارغة', () => {
        show({ movements: [] });
        expect(screen.getByText('حركة المخزون')).toBeTruthy();
        expect(screen.getByText('لا توجد حركات لهذا المنتج بعد')).toBeTruthy();
    });
});
