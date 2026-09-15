import { describe, expect, it } from 'vitest';
import { Site } from '@/site/Site';
import { KNOWN_TYPES } from '@/site/blocks';
import { clone, draw, homeOf, sectionOf, store, profile, withSections } from './helpers';
import type { DocSection } from '@/site/types';

/**
 * رسمُ الموقع — ما يراه الزائر.
 *
 * والمستند حقيقيٌّ مولَّدٌ من أبعاد، فالاختبار يسأل ما يسأله زبونٌ فتح
 * النطاق: أرى اسم المتجر؟ أرى منتجاتِه بأسعارها؟ أستطيع أن أطلب؟
 */
describe('الموقع يُرسم من المستند', () => {
    it('١) موقعٌ فيه واجهة رئيسية وحدها يعرض عنوانها', () => {
        const hero = sectionOf(store, 'hero');
        const doc = withSections(store, [hero]);
        const { container } = draw(<Site doc={doc} mode="live" />);

        expect(container.querySelector('h1')?.textContent).toBe(hero.data.title);
        expect(container.querySelectorAll('section').length).toBeGreaterThan(0);
    });

    it('٢) موقعٌ كاملٌ بكلّ أقسامه يرسمها كلَّها بلا انهيار', () => {
        const { container } = draw(<Site doc={store} mode="live" />);
        const home = homeOf(store);

        expect(container.querySelector('header')).not.toBeNull();
        expect(container.querySelector('footer')).not.toBeNull();
        expect(container.textContent).toContain(store.brand!.name);
        expect(home.sections.length).toBeGreaterThan(4);
    });

    it('٣) قسمٌ مخفيٌّ لا يظهر للزائر ويظهر باهتًا للتاجر', () => {
        const doc = clone(store);
        const home = homeOf(doc);
        const hidden = home.sections.find((s) => s.type === 'benefits')!;

        hidden.visible = false;
        const title = String(hidden.data.title);

        expect(draw(<Site doc={doc} mode="live" />).container.textContent).not.toContain(title);
        expect(draw(<Site doc={doc} mode="edit" />).container.textContent).toContain(title);
    });

    it('٤) نوعٌ لا يعرفه العارض يُتخطّى ولا يكسر الصفحة', () => {
        const unknown: DocSection = {
            type: 'holographic_showroom_2099',
            visible: true,
            source: null,
            data: { title: 'قسمٌ من المستقبل' },
        };
        const doc = withSections(store, [sectionOf(store, 'hero'), unknown]);
        const { container } = draw(<Site doc={doc} mode="live" />);

        expect(container.querySelector('h1')).not.toBeNull();
        expect(container.textContent).not.toContain('قسمٌ من المستقبل');
    });

    it('٥) موقعٌ بلا شعار يعرض اسم المتجر نصًّا', () => {
        const doc = clone(store);

        doc.brand!.logo = null;
        const { container } = draw(<Site doc={doc} mode="live" />);

        expect(container.querySelector('header img')).toBeNull();
        expect(container.querySelector('header')?.textContent).toContain(doc.brand!.name);
    });

    it('٦) موقعٌ بلا صورٍ يرسم مكانها ولا ينهار التخطيط', () => {
        const doc = clone(store);

        doc.brand!.logo = null;

        for (const page of doc.pages) {
            for (const section of page.sections) {
                if ('image' in section.data) section.data.image = '';
                for (const item of (section.items ?? []) as { image?: string | null }[]) {
                    if ('image' in item) item.image = null;
                }
            }
        }

        const { container } = draw(<Site doc={doc} mode="live" />);

        expect(container.querySelectorAll('img').length).toBe(0);
        expect(container.querySelector('main')).not.toBeNull();
    });

    it('٧) الصفحة عربيّةٌ من اليمين إلى اليسار', () => {
        const { container } = draw(<Site doc={store} mode="live" />);
        const root = container.querySelector('.w-site') as HTMLElement;

        expect(root.getAttribute('dir')).toBe('rtl');
        expect(root.getAttribute('lang')).toBe('ar');
    });

    it('٨) قائمة الجوال زرٌّ يقول أمفتوحةٌ هي أم لا', () => {
        const { container } = draw(<Site doc={store} mode="live" />);
        const toggle = container.querySelector('.w-nav-toggle') as HTMLButtonElement;

        expect(toggle).not.toBeNull();
        expect(toggle.getAttribute('aria-expanded')).toBe('false');
        expect(toggle.tagName).toBe('BUTTON');
    });

    it('٩) موقعٌ بلا منتجاتٍ لا يرسم شريطًا فارغًا للزائر', () => {
        const doc = clone(store);

        for (const page of doc.pages) {
            for (const section of page.sections) {
                if (section.source === 'products') section.items = [];
            }
        }

        const products = homeOf(doc).sections.find((s) => s.type === 'featured_products')!;
        const title = String(products.data.title);

        expect(draw(<Site doc={doc} mode="live" />).container.textContent).not.toContain(title);
        // وفي المعاينة يبقى ويُقال ما ينقصه
        expect(draw(<Site doc={doc} mode="edit" />).container.textContent).toContain(title);
    });

    it('١٠) موقعٌ تعريفيٌّ بلا تجارة يُرسم كما هو', () => {
        const { container } = draw(<Site doc={profile} mode="live" />);

        expect(container.querySelector('header')).not.toBeNull();
        expect(container.textContent).toContain(profile.brand!.name);
    });

    it('١١) الألوان والخطّ تصل متغيّراتِ CSS لا ألوانًا مكتوبة', () => {
        const { container } = draw(<Site doc={store} mode="live" />);
        const root = container.querySelector('.w-site') as HTMLElement;

        expect(root.style.getPropertyValue('--w-primary')).toBe(store.tokens.primary);
        expect(root.style.getPropertyValue('--w-bg')).toBe(store.tokens.background);
        expect(root.style.getPropertyValue('--w-font')).toContain('sans-serif');
    });

    it('١٢) الأسعار بعملة المتجر ومنازلها', () => {
        const { container } = draw(<Site doc={store} mode="live" />);

        // ر.ع بثلاث منازل — كما في `currency` في المستند
        expect(container.textContent).toMatch(/\d+\.\d{3}\s*ر\.ع/);
    });

    it('١٣) الخصم يُعرض سعرين: الجديد والمشطوب', () => {
        const doc = clone(store);
        const section = homeOf(doc).sections.find((s) => s.type === 'featured_products')!;

        section.items = [
            { id: 1, name: 'باقة', excerpt: '', price: 20, was: 20, final: 16, image: null, category_id: null },
        ];

        const { container } = draw(<Site doc={doc} mode="live" />);
        const struck = container.querySelector('span[style*="line-through"]');

        expect(struck?.textContent).toContain('20.000');
        expect(container.textContent).toContain('16.000');
    });

    it('١٤) كلّ نوعٍ في مكتبة أبعاد له رسمٌ في العارض', () => {
        const types = new Set<string>();

        for (const doc of [store, profile]) {
            for (const page of doc.pages) {
                for (const section of page.sections) types.add(section.type);
            }
        }

        for (const type of types) {
            expect(KNOWN_TYPES, `النوع ${type} بلا رسم`).toContain(type);
        }
    });

    it('١٥) لا زرَّ ولا أيقونةَ لا تعمل في الترويسة', () => {
        const doc = clone(store);
        const header = doc.globals.find((g) => g.slot === 'header')!;

        header.data.show_cart = true;
        header.data.show_search = true;

        const { container } = draw(<Site doc={doc} mode="live" />);
        const head = container.querySelector('header')!;

        for (const link of head.querySelectorAll('a')) {
            expect(link.getAttribute('href')).toBeTruthy();
        }

        for (const button of head.querySelectorAll('button')) {
            expect(button.getAttribute('aria-label')).toBeTruthy();
        }
    });

    it('١٦) الروابط روابطُ في الموقع ولا تنقل في المعاينة', () => {
        const live = draw(<Site doc={store} mode="live" />).container;
        const edit = draw(<Site doc={store} mode="edit" />).container;

        expect(live.querySelector('header a')?.getAttribute('href')).toBeTruthy();
        expect(edit.querySelector('header a')?.getAttribute('href')).toBeNull();
        expect(edit.querySelector('header a')?.getAttribute('aria-disabled')).toBe('true');
    });
});
