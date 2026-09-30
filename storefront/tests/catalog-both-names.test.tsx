import { fireEvent } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { Site } from '@/site/Site';
import { clone, draw, store, withSections } from './helpers';
import type { DocProduct, DocSection } from '@/site/types';

/**
 * الصنفُ نفسُه بأيّ اسمَيه — في كتالوج الموقع المبنيّ.
 *
 * الخادمُ يُرسل الاسمَ بلغة الموقع (`name`) والاسمَ الآخر (`other_name`)
 * ليُبحث به ولا يُرسم. فزبونُ الموقع العربيّ يكتب «Flower» فيجد الباقة،
 * وزبونُ الإنجليزيّ يكتب «ورد» فيجدها — ببطاقةٍ واحدة ومعرّفٍ واحد.
 */

const bouquet = (name: string, other: string | null): DocProduct => ({
    ...clone(store).data!.products![0],
    id: 501,
    name,
    other_name: other,
    excerpt: '',
});

const shelf = (items: DocProduct[]) => {
    const section: DocSection = {
        type: 'product_catalog',
        visible: true,
        source: 'products',
        data: { title: 'كلّ المنتجات', columns: '4', layout: 'auto' },
        items,
    };

    return draw(<Site doc={withSections(clone(store), [section])} mode="live" />).container;
};

const search = (container: HTMLElement, q: string) => {
    fireEvent.change(container.querySelector('main input[type="search"]') as HTMLInputElement, { target: { value: q } });

    return [...container.querySelectorAll('main article h3')].map((h) => h.textContent);
};

describe('البحثُ بالاسمين', () => {
    it.each([
        ['الموقع العربيّ', 'باقة ورد وردية', 'Pink Flower Bouquet'],
        ['الموقع الإنجليزيّ', 'Pink Flower Bouquet', 'باقة ورد وردية'],
    ])('%s: «ورد» و«Flower» يجدان البطاقةَ نفسَها — باسمها المعروض', (_, shown, other) => {
        const items = [bouquet(shown, other), { ...bouquet('شمعة', 'Candle'), id: 502 }];

        expect(search(shelf(items), 'ورد')).toEqual([shown]);
        expect(search(shelf(items), 'flower')).toEqual([shown]);
    });

    it('والاسمُ الآخر لا يُرسم — يُبحث به وحسب', () => {
        const container = shelf([bouquet('باقة ورد وردية', 'Pink Flower Bouquet')]);

        expect(container.textContent).toContain('باقة ورد وردية');
        expect(container.textContent).not.toContain('Pink Flower Bouquet');
    });

    it('ومستندٌ قديم بلا اسمٍ آخر يبحث كما كان', () => {
        const legacy = bouquet('باقة ورد وردية', null);
        delete (legacy as Partial<DocProduct>).other_name;

        expect(search(shelf([legacy]), 'ورد')).toEqual(['باقة ورد وردية']);
        expect(search(shelf([legacy]), 'Flower')).toEqual([]);
    });
});
