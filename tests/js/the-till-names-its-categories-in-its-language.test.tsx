import { fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { pageProps } from './setup';
import { ADDONS_TAB } from '@/Pages/Pos/partials/PosAddons';

vi.mock('@/Layouts/PosLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <div>{children}</div> }));
vi.mock('@/hooks/useLiveStock', () => ({ default: (_url: string, p: unknown[]) => ({ products: p, updatedAt: null }) }));

/**
 * صفُّ الأقسام في الصندوق — يُقرأ بلغة الواجهة، ويُرشِّح بما كان يُرشِّح به.
 *
 * ═══ وما يُحرَس ═══
 *
 * الخادمُ يرسل لكلّ قسمٍ قيمةً (الاسمُ كما كتبه التاجر) وتسميةً (بلغة الواجهة
 * — `CategoryName`). والشاشةُ ترسم التسميةَ وتُرشِّح بالقيمة: فترجمةُ
 * «منتجات» إلى «Products» لا تُفرغ القسمَ من أصنافه.
 *
 * وتبويبُ إضافات النظام اسمُه «إضافات المنتجات» لا «الإضافات»: متجرٌ له قسمُ
 * منتجاتٍ حقيقيٌّ اسمُه «الإضافات» كان يرى بعد الترجمة زرّين «Add-ons».
 *
 * وما يصل من الخادم — أيُّ تسميةٍ لأيّ قسم — حارسُه
 * `TheTillAndTheSiteNameCategoriesInTheirLanguageTest`.
 */

const product = (id: number, name: string, cat: string) => ({
    id, name, name_en: null, label: name, cat, price: 5, cost: 1, qty: 10,
    sku: null, barcode: null, image: null, stock_status: 'متوفر', tracks_stock: true,
    active: true, published: true, alert: 0, tax: 0,
});

const ENGLISH = { 'الكل': 'All', 'الإضافات': 'Add-ons', 'إضافات المنتجات': 'Product add-ons' };

const draw = (translations: Record<string, string>, categories: { value: string; label: string }[]) => {
    Object.assign(pageProps, {
        translations,
        products: [product(1, 'باقة جوري', 'منتجات'), product(2, 'شريط ذهبي', 'الإضافات'), product(3, 'عرض الجمعة', 'عروض')],
        categories,
        seasons: [],
        customers: [],
        addons: [{ id: 7, name: 'تغليف', name_en: null, label: 'تغليف', price: 1.5, icon: '🎀', active: true }],
        addonsLayout: 'section',
        coupons: [],
        resumeCart: null,
        settings: {},
        orderOptions: undefined,
        customOrder: { templates: [] },
        context: { currency: { code: 'OMR', name: 'ريال', symbol: 'ر.ع', rate: 1, is_base: true, active: true, decimals: 3 } },
    });
    localStorage.clear();
};

const open = async () => {
    const { default: PosIndex } = await import('@/Pages/Pos/Index');

    return render(<PosIndex />);
};

/** صفُّ الأقسام — الصفُّ الذي فيه تبويبُ الإضافات */
const row = () => screen.getByTestId('pos-addons-tab').parentElement as HTMLElement;

describe('صفُّ الأقسام بالإنجليزيّة', () => {
    beforeEach(() => {
        for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];

        draw(ENGLISH, [
            { value: 'الكل', label: 'All' },
            { value: 'منتجات', label: 'Products' },
            { value: 'الإضافات', label: 'Add-ons' },
            { value: 'عروض', label: 'Offers' },
        ]);
    });

    it('يرسم التسمية — والقسمُ الحقيقيّ وتبويبُ النظام باسمين مختلفين', async () => {
        await open();

        const names = within(row()).getAllByRole('button').map((b) => b.textContent?.trim());

        expect(names).toEqual(['All', 'Products', 'Add-ons', 'Offers', 'Product add-ons']);
        // ولا زرّان بالاسم نفسِه
        expect(new Set(names).size).toBe(names.length);
        expect(screen.getByTestId('pos-addons-tab')).toHaveTextContent('Product add-ons');
    });

    it('«Products» يُرشِّح بالقيمة العربيّة — أصنافُ «منتجات» وحدها', async () => {
        await open();

        fireEvent.click(within(row()).getByRole('button', { name: 'Products' }));

        expect(screen.getByText('باقة جوري')).toBeInTheDocument();
        expect(screen.queryByText('شريط ذهبي')).toBeNull();
        expect(screen.queryByText('عرض الجمعة')).toBeNull();
        expect(screen.queryByTestId('pos-addons-section')).toBeNull();
    });

    it('وقسمُ «Add-ons» الحقيقيّ يعرض منتجاتِه — لا إضافاتِ النظام', async () => {
        await open();

        fireEvent.click(within(row()).getByRole('button', { name: 'Add-ons' }));

        expect(screen.getByText('شريط ذهبي')).toBeInTheDocument();
        expect(screen.queryByText('باقة جوري')).toBeNull();
        expect(screen.queryByTestId('pos-addons-section')).toBeNull();
    });

    it('و«Product add-ons» يعرض الإضافاتِ وحدها — كما كان تبويبُ النظام', async () => {
        await open();

        fireEvent.click(screen.getByTestId('pos-addons-tab'));

        const section = screen.getByTestId('pos-addons-section');
        expect(within(section).getByText('تغليف')).toBeInTheDocument();
        expect(screen.queryByText('باقة جوري')).toBeNull();
        expect(screen.queryByText('شريط ذهبي')).toBeNull();
        // وقيمتُه الداخليّة لم تتبدّل
        expect(ADDONS_TAB).toBe('__addons__');
    });
});

describe('صفُّ الأقسام بالعربيّة', () => {
    beforeEach(() => {
        for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];

        draw({}, [
            { value: 'الكل', label: 'الكل' },
            { value: 'منتجات', label: 'منتجات' },
            { value: 'الإضافات', label: 'الإضافات' },
        ]);
    });

    it('كما كان — والتبويبُ «إضافات المنتجات» لا يختلط بقسم «الإضافات»', async () => {
        await open();

        const names = within(row()).getAllByRole('button').map((b) => b.textContent?.trim());

        expect(names).toEqual(['الكل', 'منتجات', 'الإضافات', 'إضافات المنتجات']);

        fireEvent.click(within(row()).getByRole('button', { name: 'منتجات' }));
        expect(screen.getByText('باقة جوري')).toBeInTheDocument();
        expect(screen.queryByText('شريط ذهبي')).toBeNull();
    });
});
