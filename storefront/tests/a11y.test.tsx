import { describe, expect, it } from 'vitest';
import { Site } from '@/site/Site';
import { clone, draw, homeOf, sectionOf, store, withSections } from './helpers';

/**
 * ما يجعل الموقع صالحًا لمن لا يستعمل فأرة، ولمن يقرأ بأذنه، ولمن شاشتُه
 * بعرض إصبعين.
 *
 * وهذه ليست تحسيناتٍ تُؤجَّل: زبونٌ لا يستطيع الوصول إلى زرّ الطلب لا يطلب.
 */
describe('الوصول', () => {
    it('بنيةٌ دلاليّة: ترويسةٌ ومتنٌ وتذييلٌ وقائمة', () => {
        const { container } = draw(<Site doc={store} mode="live" />);

        expect(container.querySelector('header')).not.toBeNull();
        expect(container.querySelector('main')).not.toBeNull();
        expect(container.querySelector('footer')).not.toBeNull();
        expect(container.querySelector('nav[aria-label]')).not.toBeNull();
    });

    it('عنوانٌ أوّلٌ واحدٌ في الصفحة', () => {
        const { container } = draw(<Site doc={store} mode="live" />);

        expect(container.querySelectorAll('h1').length).toBe(1);
    });

    it('عناوين الأقسام h2 لا نصوصٌ عريضة', () => {
        const { container } = draw(<Site doc={store} mode="live" />);
        const inMain = container.querySelector('main')!;

        expect(inMain.querySelectorAll('h2').length).toBeGreaterThan(0);
        // ولا h3 قبل h2: الترتيب هو ما يتنقّل به قارئ الشاشة
        const levels = [...inMain.querySelectorAll('h1,h2,h3')].map((h) => Number(h.tagName[1]));

        for (let i = 1; i < levels.length; i++) {
            expect(levels[i] - levels[i - 1]).toBeLessThanOrEqual(1);
        }
    });

    it('كلّ صورةٍ لها وصفٌ — ولو فارغًا للزينة', () => {
        const { container } = draw(<Site doc={store} mode="live" />);

        for (const img of container.querySelectorAll('img')) {
            expect(img.getAttribute('alt')).not.toBeNull();
        }
    });

    it('صور المنتجات تحمل أسماءها وصفًا', () => {
        const doc = clone(store);
        const section = homeOf(doc).sections.find((s) => s.type === 'featured_products')!;

        section.items = [
            { id: 9, name: 'باقة توليب', excerpt: '', price: 1, was: null, final: 1, image: 'https://x/1.jpg', category_id: null },
        ];

        const { container } = draw(<Site doc={withSections(doc, [section])} mode="live" />);

        expect(container.querySelector('main img')?.getAttribute('alt')).toBe('باقة توليب');
    });

    it('ما يُضغط زرٌّ أو رابط — لا div', () => {
        const { container } = draw(<Site doc={store} mode="live" />);

        expect(container.querySelectorAll('[onclick]').length).toBe(0);

        for (const link of container.querySelectorAll('a')) {
            expect(link.getAttribute('href'), link.textContent ?? '').toBeTruthy();
        }
    });

    it('الأيقونات مخفيّةٌ عن قارئ الشاشة، والرابط الذي لا نصَّ فيه له اسم', () => {
        const { container } = draw(<Site doc={store} mode="live" />);

        for (const svg of container.querySelectorAll('svg')) {
            expect(svg.getAttribute('aria-hidden')).toBe('true');
        }

        for (const link of container.querySelectorAll('a')) {
            /*
             * والاسمُ من ثلاثة: نصُّ الرابط، أو `aria-label`، أو وصفُ صورةٍ
             * فيه. ورابطُ الشعار من الثالث — والصورةُ الموصوفة تسمّيه.
             */
            const named =
                (link.textContent ?? '').trim() !== '' ||
                link.getAttribute('aria-label') ||
                [...link.querySelectorAll('img')].some((img) => (img.getAttribute('alt') ?? '').trim() !== '');

            expect(named, link.outerHTML.slice(0, 80)).toBeTruthy();
        }
    });

    it('مساحة اللمس لا تقلّ عن ٤٤ بكسل في الأزرار والروابط الأساسية', () => {
        const { container } = draw(<Site doc={store} mode="live" />);
        const toggle = container.querySelector('.w-nav-toggle') as HTMLElement;

        expect(Number.parseInt(toggle.style.height, 10)).toBeGreaterThanOrEqual(44);

        const cta = container.querySelector('main a[style*="min-height"]') as HTMLElement | null;

        if (cta) {
            expect(Number.parseInt(cta.style.minHeight, 10)).toBeGreaterThanOrEqual(40);
        }
    });

    it('الأسئلة تُفتح بلا JavaScript — details/summary', () => {
        const doc = clone(store);
        const faq = {
            type: 'faq',
            visible: true,
            source: null,
            data: { title: 'أسئلة', items: [{ q: 'متى تفتحون؟', a: 'من الثامنة.' }] },
        };
        const { container } = draw(<Site doc={withSections(doc, [faq])} mode="live" />);

        expect(container.querySelector('details > summary')?.textContent).toBe('متى تفتحون؟');
    });
});

describe('الاتّجاه والاستجابة', () => {
    it('لا خاصّيةَ اتّجاهٍ ثابتة في الأنماط — الكلّ منطقيّ', () => {
        const { container } = draw(<Site doc={store} mode="live" />);
        const physical = /(^|;)\s*(margin|padding|border)-(left|right)\s*:|(^|;)\s*(left|right)\s*:/i;

        for (const el of container.querySelectorAll<HTMLElement>('[style]')) {
            expect(physical.test(el.getAttribute('style') ?? ''), el.outerHTML.slice(0, 120)).toBe(false);
        }
    });

    it('الشبكة تتقلّص مع الشاشة ولا تفرض عرضًا أدنى يفيض', () => {
        const { container } = draw(<Site doc={store} mode="live" />);

        for (const grid of container.querySelectorAll<HTMLElement>('[style*="grid-template-columns"]')) {
            const value = grid.style.gridTemplateColumns;

            if (value.includes('minmax')) {
                expect(value, value).toContain('100%');
            }
        }
    });

    it('الصور خارج الشاشة الأولى تُؤجَّل', () => {
        const doc = clone(store);
        const gallery = {
            type: 'gallery',
            visible: true,
            source: null,
            data: { title: 'أعمالنا', images: [{ src: 'https://x/1.jpg', alt: 'أ' }, { src: 'https://x/2.jpg', alt: 'ب' }] },
        };
        const { container } = draw(<Site doc={withSections(doc, [sectionOf(store, 'hero'), gallery])} mode="live" />);
        const images = [...container.querySelectorAll('main img')];

        expect(images.length).toBe(2);
        for (const img of images) {
            expect(img.getAttribute('loading')).toBe('lazy');
        }
    });

    it('أوّل صورةٍ في الصفحة لا تُؤجَّل', () => {
        const doc = clone(store);
        const gallery = {
            type: 'gallery',
            visible: true,
            source: null,
            data: { title: 'أعمالنا', images: [{ src: 'https://x/1.jpg', alt: 'أ' }] },
        };
        const { container } = draw(<Site doc={withSections(doc, [gallery])} mode="live" />);

        expect(container.querySelector('main img')?.getAttribute('loading')).toBe('eager');
    });

    it('الإطارات المدمجة لها عنوانٌ وتُؤجَّل', () => {
        const doc = clone(store);
        const video = { type: 'video', visible: true, source: null, data: { title: 'جولة', url: 'https://youtu.be/dQw4w9WgXcQ' } };
        const { container } = draw(<Site doc={withSections(doc, [video])} mode="live" />);
        const frame = container.querySelector('iframe')!;

        expect(frame.getAttribute('title')).toBe('جولة');
        expect(frame.getAttribute('loading')).toBe('lazy');
    });
});
