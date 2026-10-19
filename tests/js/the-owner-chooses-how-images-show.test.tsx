import { fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { router as core } from '@inertiajs/core';
import { router } from '@inertiajs/react';

import { pageProps } from './setup';

vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

import StoreImageField, { fitOf, needsShrink, scaled, shapeOf } from '@/Components/StoreImageField';
import type { EditorRow } from '@/Pages/Admin/Website/ThemeEditor';
import CatalogFitField from '@/Pages/Admin/Website/theme/CatalogFitField';

const { default: ThemeEditor } = await import('@/Pages/Admin/Website/ThemeEditor');

/**
 * طريقةُ عرض الصور في المحرّر — «الصورة كاملة» أو «ملء الإطار» — ومعاينتُها.
 *
 * ═══ وما يُحرَس ═══
 *
 *  • الصورةُ تُرى في إطار موضعها بنسبته وبطريقة عرضها، وما رُفع قبلُ يبقى
 *    «ملء الإطار» كما كان، وكلُّ صورةٍ تُرفع من جديد تبدأ «كاملة».
 *  • والكبيرةُ تُصغَّر بنسبتها ولا تُكبَّر الصغيرة، وما يتجاوز الحدَّ ولا يُصغَّر
 *    يُقال حدُّه قبل أن يُرسل.
 *  • وصورُ المنتجات والفئات يُختار عرضُها من صفّ «عرض الصور» بصور منتجاته هو،
 *    وطريقةُ صورة الواجهة تُحفظ معها في صفّها.
 *
 * وقواعدُ الخادم والمعالجة: `RibbonImagesShowWholeWhenAskedTest`.
 */

const reset = (extra: Record<string, unknown> = {}) => {
    for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    Object.assign(pageProps, { translations: {}, ...extra });
};

afterEach(() => vi.restoreAllMocks());

describe('حسابُ المقاس والشكل', () => {
    it('الفراغُ «ملء الإطار» — كما كانت الصور', () => {
        expect(fitOf('')).toBe('cover');
        expect(fitOf(undefined)).toBe('cover');
        expect(fitOf('contain')).toBe('contain');
        expect(fitOf('stretch')).toBe('cover');
    });

    it('يُصغَّر الكبيرُ بنسبته ولا يُكبَّر الصغير', () => {
        expect(scaled(6000, 4000)).toEqual({ w: 2560, h: 1707 });
        expect(scaled(1000, 3000)).toEqual({ w: 853, h: 2560 });
        expect(scaled(800, 600)).toEqual({ w: 800, h: 600 });
        expect(shapeOf(800, 800)).toBe('square');
        expect(shapeOf(1000, 3000)).toBe('portrait');
        expect(shapeOf(3000, 1000)).toBe('landscape');
    });

    it('ويُصغَّر في المتصفّح ما تجاوز الحدَّ من صيغةٍ يعيد كتابتها وحده', () => {
        const big = 11 * 1024 * 1024;
        const max = 10 * 1024 * 1024;

        expect(needsShrink({ size: big, type: 'image/jpeg' }, max)).toBe(true);
        expect(needsShrink({ size: big, type: 'image/png' }, max)).toBe(true);
        expect(needsShrink({ size: 1024, type: 'image/jpeg' }, max)).toBe(false);
        // وHEIC لا يفكّه كلُّ متصفّح — يُرسَل كما هو ويقول الخادمُ ما يقبل
        expect(needsShrink({ size: big, type: 'image/heic' }, max)).toBe(false);
    });
});

describe('حقلُ الصورة وطريقةُ عرضها', () => {
    it('ما رُفع قبلُ «ملء الإطار» في إطار موضعه — والاختيارُ يُرى فورًا', () => {
        reset();
        const onFit = vi.fn();
        render(<StoreImageField name="hero" label="صورة الواجهة" value="/storage/website/1/hero.jpg" onChange={() => {}} fit="" onFitChange={onFit} ratio="5 / 4" />);

        expect(screen.getByTestId('hero-frame').style.aspectRatio).toBe('5 / 4');
        expect(screen.getByTestId('hero-frame').querySelector('img')!.style.objectFit).toBe('cover');
        expect(screen.getByTestId('hero-fit-cover').getAttribute('aria-checked')).toBe('true');

        fireEvent.click(screen.getByTestId('hero-fit-contain'));
        expect(onFit).toHaveBeenCalledWith('contain');
    });

    it('وكلُّ صورةٍ تُرفع من جديد تبدأ «كاملة» — بأبعادها وشكلها', () => {
        reset();
        const onChange = vi.fn();
        const onFit = vi.fn();
        vi.mocked(router.post).mockImplementation(((_url: string, _data: unknown, options: { onSuccess?: (page: unknown) => void; onFinish?: () => void }) => {
            options.onSuccess?.({ props: { flash: { uploaded: '/storage/website/1/new.jpg', uploaded_meta: { w: 1000, h: 3000 } } } });
            options.onFinish?.();
        }) as never);

        const { rerender } = render(<StoreImageField name="hero" label="صورة الواجهة" value="" onChange={onChange} fit="" onFitChange={onFit} />);
        const input = document.querySelector('input[type=file]') as HTMLInputElement;
        fireEvent.change(input, { target: { files: [new File(['x'], 'p.jpg', { type: 'image/jpeg' })] } });

        expect(onChange).toHaveBeenCalledWith('/storage/website/1/new.jpg');
        expect(onFit).toHaveBeenCalledWith('contain');

        rerender(<StoreImageField name="hero" label="صورة الواجهة" value="/storage/website/1/new.jpg" onChange={onChange} fit="contain" onFitChange={onFit} />);
        expect(screen.getByTestId('hero-meta').textContent).toContain('1000 × 3000');
        expect(screen.getByTestId('hero-meta').textContent).toContain('طوليّة');
    });

    it('وما تجاوز الحدَّ ولا يُصغَّر يُقال حدُّه — ولا يُرسَل', () => {
        reset({ uploadMax: 2 * 1024 * 1024 });
        render(<StoreImageField label="صورة" value="" onChange={() => {}} />);

        const input = document.querySelector('input[type=file]') as HTMLInputElement;
        const heic = new File([new Uint8Array(3 * 1024 * 1024)], 'IMG_1.HEIC', { type: 'image/heic' });
        fireEvent.change(input, { target: { files: [heic] } });

        expect(router.post).not.toHaveBeenCalled();
        expect(screen.getByText('الصورة أكبر من 2 ميغابايت — صغّرها ثمّ ارفعها.')).toBeTruthy();
    });

    it('وحقلٌ لا يُسأل عن طريقته لا يعرض اختيارها', () => {
        reset();
        render(<StoreImageField name="logo" label="صورة" value="/storage/website/1/a.jpg" onChange={() => {}} />);

        expect(screen.queryByTestId('logo-fit')).toBeNull();
    });
});

describe('«عرض الصور» — صور المنتجات والفئات', () => {
    it('خياران بصور منتجاته هو — والمختارُ يُرسل قيمتَه', () => {
        reset();
        const onChange = vi.fn();
        render(
            <CatalogFitField
                label="صور المنتجات والفئات"
                value=""
                products={[{ id: 1, name: 'ورد', image: '/storage/a.jpg' }, { id: 2, name: 'بلا صورة', image: null }]}
                onChange={onChange}
            />,
        );

        expect(screen.getByTestId('catalogfit-cover').getAttribute('aria-checked')).toBe('true');
        // وصورةٌ زينةٌ لا تُقرأ (`alt=""`) — منتجٌ بلا صورةٍ لا يُعرض فيها
        const samples = screen.getByTestId('catalogfit-contain').querySelectorAll('img');
        expect(samples).toHaveLength(1);
        expect(samples[0].style.objectFit).toBe('contain');
        expect(screen.getByTestId('catalogfit-cover').querySelector('img')!.style.objectFit).toBe('cover');

        fireEvent.click(screen.getByTestId('catalogfit-contain'));
        expect(onChange).toHaveBeenCalledWith('contain');
    });
});

describe('وطريقةُ صورة الواجهة تُحفظ معها في صفّها', () => {
    it('«احفظ» يُرسل الصورةَ وطريقتَها', () => {
        reset();
        const sent: { data: Record<string, unknown> }[] = [];
        vi.spyOn(core, 'post').mockImplementation(((_u: string, data: Record<string, unknown>, options: { onFinish?: () => void }) => {
            sent.push({ data });
            options.onFinish?.();
        }) as never);

        const rows: EditorRow[] = [{
            key: 'hero', label: 'الواجهة', hint: '', fixed: true, on: true, silent: null, source: null,
            fields: [{ key: 'store_hero_image', kind: 'image', label: 'صورة الواجهة', fit: 'store_hero_image_fit', ratio: '5/4' }],
        }];

        render(
            <ThemeEditor
                theme="ribbon"
                site={{ name: 'RIBBON', published: true, url: 'https://ribbon.abaadapp.om', slug: 'ribbon', host: 'abaadapp.om' }}
                publishing={null}
                pages={[]}
                rows={rows}
                values={{ store_hero_image: '/storage/website/1/hero.jpg', store_hero_image_fit: '' }}
                order={[]}
                maxFeatured={4}
                products={[]}
            />,
        );

        fireEvent.click(screen.getByText('الواجهة'));
        fireEvent.click(screen.getByTestId('store_hero_image-fit-contain'));
        fireEvent.click(within(screen.getByTestId('row-hero')).getByRole('button', { name: 'احفظ' }));

        expect(sent[0].data).toEqual({ store_hero_image: '/storage/website/1/hero.jpg', store_hero_image_fit: 'contain' });
    });
});
