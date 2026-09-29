import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { router as core } from '@inertiajs/core';

import { pageProps } from './setup';
import {
    ADDONS_TAB,
    AddonsBar,
    AddonsSection,
    addonLine,
    addonsLayoutOf,
    addonsView,
    shownAddons,
} from '@/Pages/Pos/partials/PosAddons';
import AddonsLayoutSwitch from '@/Pages/Admin/Products/partials/AddonsLayoutSwitch';
import ProductForm from '@/Pages/Admin/Products/partials/ProductForm';
import type { Addon } from '@/types/models';

/**
 * إضافاتُ المتجر في نقطة البيع — شريطًا أم قسمًا كاملًا.
 *
 * ═══ وأثقلُ ما يُحرَس ═══
 *
 * أنّ الوضعين يبيعان البندَ نفسَه (`addonLine`) — فلا طريقَ بيعٍ ثانٍ. وأنّ
 * «الشريط» — الافتراض — يبقى كما كان. وأنّ تبويبَ «الإضافات» لا يختلط
 * بقسم منتجاتٍ اسمُه «الإضافات».
 */

const ADDONS: Addon[] = [
    { id: 1, name: 'تغليف', name_en: null, label: 'تغليف', price: 1.5, icon: '🎀', active: true },
    { id: 2, name: 'كرت', name_en: null, label: 'كرت', price: 0.5, icon: 'card', active: true },
    { id: 3, name: 'موقوفة', name_en: null, label: 'موقوفة', price: 2, icon: '🎁', active: false },
];

const money = (v: number) => `${v.toFixed(3)} ر.ع`;

describe('طريقةُ العرض', () => {
    it('الغائبُ والقديمُ والفاسدُ «شريط»', () => {
        for (const raw of [undefined, null, '', 'grid', 'BAR', 0]) expect(addonsLayoutOf(raw)).toBe('bar');
        expect(addonsLayoutOf('section')).toBe('section');
        expect(addonsLayoutOf('bar')).toBe('bar');
    });

    it('الشريطُ: شريطٌ والمنتجاتُ كما هي — مهما كان التبويب', () => {
        for (const cat of ['الكل', 'ورود', ADDONS_TAB]) {
            expect(addonsView('bar', cat)).toEqual({ bar: true, section: false, products: true });
        }
    });

    it('القسمُ: «الكل» إضافاتٌ ثمّ منتجات، و«الإضافات» وحدها، والقسمُ منتجاتُه', () => {
        expect(addonsView('section', 'الكل')).toEqual({ bar: false, section: true, products: true });
        expect(addonsView('section', ADDONS_TAB)).toEqual({ bar: false, section: true, products: false });
        expect(addonsView('section', 'ورود')).toEqual({ bar: false, section: false, products: true });
    });

    it('وقسمٌ حقيقيٌّ اسمُه «الإضافات» قسمُ منتجات — لا تبويبُ الإضافات', () => {
        expect(ADDONS_TAB).not.toBe('الإضافات');
        expect(addonsView('section', 'الإضافات')).toEqual({ bar: false, section: false, products: true });
    });

    it('والموقوفةُ لا تُعرض — كما اليوم', () => {
        expect(shownAddons(ADDONS).map((a) => a.id)).toEqual([1, 2]);
    });
});

describe('البندُ الذي يدخل السلّة — واحدٌ للوضعين', () => {
    it('بالحقول التي كان الشريطُ يرسلها حرفًا', () => {
        expect(addonLine(ADDONS[0])).toEqual({ key: 'a1', id: null, addon_id: 1, name: 'تغليف', price: 1.5, icon: '🎀', image: null });
        // ورمزٌ غيرُ إيموجي يصير هديّة — كما كان
        expect(addonLine(ADDONS[1]).icon).toBe('🎁');
    });

    it('الشريطُ يرسم الإضافاتَ بترتيبها ويضيف البند نفسه', () => {
        const onAdd = vi.fn();
        render(<AddonsBar addons={shownAddons(ADDONS)} money={money} onAdd={onAdd} />);

        const bar = screen.getByTestId('pos-addons-bar');
        expect(bar).toHaveTextContent('تغليف');
        expect(bar).toHaveTextContent('1.500 ر.ع');
        expect(bar).not.toHaveTextContent('موقوفة');
        expect(bar.textContent!.indexOf('تغليف')).toBeLessThan(bar.textContent!.indexOf('كرت'));

        fireEvent.click(screen.getByText('تغليف'));
        expect(onAdd).toHaveBeenCalledWith(addonLine(ADDONS[0]));
    });

    it('وبطاقةُ القسم تضيف البندَ نفسه — addon_id والاسم والسعر', () => {
        const onAdd = vi.fn();
        render(<AddonsSection addons={shownAddons(ADDONS)} money={money} onAdd={onAdd} />);

        expect(screen.getByTestId('pos-addons-section')).toHaveTextContent('الإضافات');
        expect(screen.queryByTestId('pos-addon-card-3')).toBeNull();

        fireEvent.click(screen.getByTestId('pos-addon-card-2'));
        expect(onAdd).toHaveBeenCalledWith({ key: 'a2', id: null, addon_id: 2, name: 'كرت', price: 0.5, icon: '🎁', image: null });
    });

    it('وشبكتُه بأعمدة شبكة المنتجات', () => {
        render(<AddonsSection addons={shownAddons(ADDONS)} money={money} onAdd={vi.fn()} />);

        const grid = screen.getByTestId('pos-addon-card-1').parentElement!;
        expect(grid.className).toContain('grid-cols-2');
        expect(grid.className).toContain('lg:grid-cols-3');
        expect(grid.className).toContain('xl:grid-cols-4');
    });

    it('وبلا إضافاتٍ لا شريطَ ولا قسم', () => {
        render(
            <>
                <AddonsBar addons={[]} money={money} onAdd={vi.fn()} />
                <AddonsSection addons={[]} money={money} onAdd={vi.fn()} />
            </>,
        );
        expect(screen.queryByTestId('pos-addons-bar')).toBeNull();
        expect(screen.queryByTestId('pos-addons-section')).toBeNull();
    });
});

describe('مفتاحُ العرض في شاشة المنتج', () => {
    let fetcher: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        fetcher = vi.fn().mockResolvedValue({ ok: true, json: async () => ({ ok: true, layout: 'section' }) });
        vi.stubGlobal('fetch', fetcher);
        Object.assign(pageProps, { context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 } } });
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
    });

    it('لا يُرسم لغير صاحب النشاط', () => {
        render(<AddonsLayoutSwitch initial="bar" canChange={false} />);
        expect(screen.queryByTestId('addons-layout-switch')).toBeNull();
    });

    it('يعرض الخيارين والتلميح — والحاليُّ محدَّد', () => {
        render(<AddonsLayoutSwitch initial="section" canChange />);

        expect(screen.getByText('طريقة عرض الإضافات في نقطة البيع')).toBeInTheDocument();
        expect(screen.getByText('يطبّق على نقطة البيع في جميع أجهزة النشاط.')).toBeInTheDocument();
        expect(screen.getByRole('radio', { name: 'قسم كامل' })).toHaveAttribute('aria-checked', 'true');
        expect(screen.getByRole('radio', { name: 'شريط أفقي' })).toHaveAttribute('aria-checked', 'false');
    });

    it('والتبديلُ يُحفظ بطلبه المستقلّ', async () => {
        render(<AddonsLayoutSwitch initial="bar" canChange />);

        await act(async () => {
            fireEvent.click(screen.getByRole('radio', { name: 'قسم كامل' }));
        });

        expect(fetcher).toHaveBeenCalledOnce();
        const [url, init] = fetcher.mock.calls[0];
        expect(url).toBe('/admin.products.addons.display');
        expect(init.method).toBe('PUT');
        expect(JSON.parse(init.body)).toEqual({ layout: 'section' });
        await waitFor(() => expect(screen.getByRole('radio', { name: 'قسم كامل' })).toHaveAttribute('aria-checked', 'true'));
    });

    it('وما لم يُحفظ يعود إلى ما كان — ويُقال', async () => {
        fetcher.mockResolvedValue({ ok: false, json: async () => ({}) });
        render(<AddonsLayoutSwitch initial="bar" canChange />);

        await act(async () => {
            fireEvent.click(screen.getByRole('radio', { name: 'قسم كامل' }));
        });

        await waitFor(() => expect(screen.getByRole('radio', { name: 'شريط أفقي' })).toHaveAttribute('aria-checked', 'true'));
        expect(screen.getByRole('alert')).toHaveTextContent('تعذّر حفظ طريقة العرض');
    });

    it('ولا يحفظ المنتجَ ولا يُرسل نموذجه', async () => {
        const post = vi.spyOn(core, 'post').mockImplementation((() => {}) as never);

        render(
            <ProductForm
                categories={[]}
                currencyLabel="ر.ع"
                composition={{ addons: [], stock_items: [], products: [] } as never}
                addonsDisplay={{ layout: 'bar', can_change: true }}
            />,
        );

        await act(async () => {
            fireEvent.click(screen.getByRole('radio', { name: 'قسم كامل' }));
        });

        expect(fetcher).toHaveBeenCalledOnce();
        expect(post).not.toHaveBeenCalled();
    });
});
