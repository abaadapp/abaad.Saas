import { fireEvent, render } from '@testing-library/react';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { Site } from '@/Pages/Admin/Website/preview/renderer/Site';
import type { DocProduct, SiteDocument, Tokens } from '@/Pages/Admin/Website/preview/renderer/types';

/**
 * المعاينةُ في لوحة التاجر ترسم ما يرسمه موقعُه — السطرُ الأخير والبحثُ بالاسمين.
 *
 * نسخةُ العارض هنا منسوخةٌ من `storefront/src/site` (`RendererParityTest`
 * يحرس تطابقَ الحروف). وهذا يحرس السلوكَ نفسَه من طرف المعاينة، بالمستند
 * الذي يولّده أبعاد نفسُه (`storefront/tests/fixtures/store.json`).
 */

const fixture = JSON.parse(
    readFileSync(path.resolve(__dirname, '../../storefront/tests/fixtures/store.json'), 'utf8'),
) as SiteDocument;

const clone = (): SiteDocument => JSON.parse(JSON.stringify(fixture)) as SiteDocument;

describe('المعاينة: السطرُ الأخير في التذييل', () => {
    beforeEach(() => {
        vi.useFakeTimers({ toFake: ['Date'] });
        vi.setSystemTime(new Date('2031-05-04T10:00:00Z'));
    });
    afterEach(() => vi.useRealTimers());

    it.each(['columns', 'minimal', 'brand', 'split'])('%s: «© السنة الاسم» وسطرُ التعريف — أو الحقوقُ المكتوبة مرّةً واحدة', (footer) => {
        const doc = clone();
        doc.tokens = { ...doc.tokens, footer } as Tokens;
        doc.brand = { ...doc.brand!, name: 'Acme Flowers', tagline: 'Flowers for every moment' };
        const slot = doc.globals.find((g) => g.type === 'footer')!;

        slot.data = { ...slot.data, copyright: '' };
        let el = render(<Site doc={doc} mode="edit" />).container.querySelector('.w-site > footer')!;
        expect(el.querySelectorAll('[data-footer-meta]')).toHaveLength(1);
        expect(el.querySelector('[data-footer-rights]')?.textContent).toBe('© 2031 Acme Flowers');
        expect(el.querySelector('[data-footer-tagline]')?.textContent).toBe('Flowers for every moment');

        slot.data = { ...slot.data, copyright: 'جميع الحقوق محفوظة — Acme Flowers' };
        doc.brand = { ...doc.brand!, tagline: '' };
        el = render(<Site doc={doc} mode="edit" />).container.querySelector('.w-site > footer')!;
        expect(el.querySelector('[data-footer-rights]')?.textContent).toBe('جميع الحقوق محفوظة — Acme Flowers');
        expect(el.textContent!.split('جميع الحقوق محفوظة').length - 1).toBe(1);
        expect(el.querySelector('[data-footer-tagline]')).toBeNull();
    });
});

describe('المعاينة: البحثُ بالاسمين', () => {
    it('«Flower» على الموقع العربيّ يجد الباقةَ باسمها المعروض', () => {
        const doc = clone();
        const base = doc.data!.products![0];
        const items: DocProduct[] = [
            { ...base, id: 501, name: 'باقة ورد وردية', other_name: 'Pink Flower Bouquet', excerpt: '' },
            { ...base, id: 502, name: 'شمعة', other_name: 'Candle', excerpt: '' },
        ];
        const home = doc.pages.find((p) => p.is_home) ?? doc.pages[0];
        home.sections = [{ type: 'product_catalog', visible: true, source: 'products', data: { title: 'الكلّ' }, items }];
        doc.pages = [home];

        const { container } = render(<Site doc={doc} mode="live" />);
        fireEvent.change(container.querySelector('main input[type="search"]') as HTMLInputElement, { target: { value: 'Flower' } });

        expect([...container.querySelectorAll('main article h3')].map((h) => h.textContent)).toEqual(['باقة ورد وردية']);
    });
});
