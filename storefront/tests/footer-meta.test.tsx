import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { Site } from '@/site/Site';
import { clone, draw, store } from './helpers';
import type { SiteDocument, Tokens } from '@/site/types';

/**
 * السطرُ الأخير في التذييل — لكلّ موقع، بأشكاله الأربعة.
 *
 * ═══ ما يُحرس ═══
 *
 * الحقوقُ كما كتبها التاجر بحروفها، وإلّا «© السنة اسم المتجر» بسنة العرض.
 * وسطرُ التعريف (`brand.tagline`) إن كتبه، ولا شيءَ مكانه إن لم يكتبه. وكلُّ
 * ذلك مرّةً واحدة في كلّ شكل، من بيانات المتجر نفسِه — لا نصٌّ مكتوبٌ هنا
 * لمحلٍّ بعينه.
 */

const LAYOUTS = ['columns', 'minimal', 'brand', 'split'] as const;

function shop(opts: {
    footer: (typeof LAYOUTS)[number];
    name?: string;
    tagline?: string;
    copyright?: string;
    dir?: 'rtl' | 'ltr';
}): SiteDocument {
    const doc = clone(store);

    doc.tokens = { ...doc.tokens, footer: opts.footer } as Tokens;
    doc.brand = { ...doc.brand!, name: opts.name ?? 'Acme Flowers', tagline: opts.tagline ?? '' };
    doc.dir = opts.dir ?? 'rtl';
    doc.locale = doc.dir === 'ltr' ? 'en' : 'ar';

    for (const g of doc.globals) {
        if (g.type === 'footer') g.data = { ...g.data, copyright: opts.copyright ?? '' };
    }

    return doc;
}

const footerOf = (doc: SiteDocument) => {
    const { container } = draw(<Site doc={doc} mode="live" />);

    return { root: container.querySelector('.w-site')!, footer: container.querySelector('.w-site > footer')! };
};

describe('السطرُ الأخير في التذييل', () => {
    beforeEach(() => {
        vi.useFakeTimers({ toFake: ['Date'] });
        vi.setSystemTime(new Date('2031-05-04T10:00:00Z'));
    });
    afterEach(() => vi.useRealTimers());

    it.each(LAYOUTS)('%s: بلا حقوقٍ مكتوبة — «© السنة الاسم» وسطرُ التعريف، مرّةً واحدة', (footer) => {
        const { footer: el } = footerOf(shop({ footer, tagline: 'Flowers for every moment' }));

        expect(el.querySelectorAll('[data-footer-meta]')).toHaveLength(1);
        expect(el.querySelector('[data-footer-rights]')?.textContent).toBe('© 2031 Acme Flowers');
        expect(el.querySelector('[data-footer-tagline]')?.textContent).toBe('Flowers for every moment');
    });

    it.each(LAYOUTS)('%s: الحقوقُ المكتوبة بحروفها ومرّةً واحدة — ولا «©» مخترعة بجانبها', (footer) => {
        const { footer: el } = footerOf(shop({ footer, copyright: 'جميع الحقوق محفوظة — Acme Flowers' }));

        expect(el.querySelector('[data-footer-rights]')!.textContent).toBe('جميع الحقوق محفوظة — Acme Flowers');
        expect(el.textContent!.split('جميع الحقوق محفوظة').length - 1).toBe(1);
        expect(el.textContent).not.toContain('© 2031');
    });

    it.each(LAYOUTS)('%s: بلا سطر تعريف لا يُرسم مكانُه', (footer) => {
        const { footer: el } = footerOf(shop({ footer, tagline: '   ' }));

        expect(el.querySelector('[data-footer-tagline]')).toBeNull();
        expect(el.querySelector('[data-footer-rights]')?.textContent).toBe('© 2031 Acme Flowers');
    });

    it('والسنةُ سنةُ العرض — لا سنةٌ مكتوبة', () => {
        vi.setSystemTime(new Date('2032-01-02T10:00:00Z'));

        const { footer: el } = footerOf(shop({ footer: 'columns' }));

        expect(el.querySelector('[data-footer-rights]')?.textContent).toBe('© 2032 Acme Flowers');
    });

    it('ومتجرٌ لا يُذيَّل ببيانات غيره', () => {
        const a = footerOf(shop({ footer: 'split', name: 'Acme Flowers', tagline: 'Flowers for every moment' })).footer;
        const aText = a.textContent;
        const b = footerOf(shop({ footer: 'split', name: 'Bloom Muscat', tagline: 'Plants and pots' })).footer;

        expect(aText).not.toContain('Bloom Muscat');
        expect(aText).not.toContain('Plants and pots');
        expect(b.textContent).toContain('© 2031 Bloom Muscat');
        expect(b.textContent).not.toContain('Acme Flowers');
    });

    /*
     * والاتّجاهُ من الصفحة لا من التذييل: السطرُ `flex` بلا يمينٍ ولا يسار،
     * فالحقوقُ في أوّله وسطرُ التعريف في آخره أيًّا كان الاتّجاه.
     */
    it.each(['rtl', 'ltr'] as const)('%s: الاتّجاهُ من الصفحة، والسطرُ بلا يمينٍ ولا يسار', (dir) => {
        const { root, footer: el } = footerOf(shop({ footer: 'columns', tagline: 'Flowers for every moment', dir }));
        const meta = el.querySelector<HTMLElement>('[data-footer-meta]')!;

        expect(root.getAttribute('dir')).toBe(dir);
        expect(meta.style.display).toBe('flex');
        expect(meta.style.justifyContent).toBe('space-between');
        expect(meta.getAttribute('style')).not.toMatch(/\b(left|right)\b/);
        // الحقوقُ أوّلًا في ترتيب الوسم — والمتصفّحُ يضعها في أوّل السطر باتّجاهه
        expect(meta.firstElementChild).toBe(el.querySelector('[data-footer-rights]'));
    });
});
