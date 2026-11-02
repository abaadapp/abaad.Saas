import { render } from '@testing-library/react';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { describe, expect, it } from 'vitest';

import { WIDTH_PX, type LayoutTokens } from '@/Pages/Admin/Website/preview/renderer/layout';
import { cardSizes, gridOf, SPACING } from '@/Pages/Admin/Website/preview/renderer/ProductCard';
import { Site } from '@/Pages/Admin/Website/preview/renderer/Site';
import type { DocProduct, SiteDocument } from '@/Pages/Admin/Website/preview/renderer/types';

/**
 * بطاقةُ موقع المنشئ تقول للمتصفّح عرضَها — فيختار من أحجام صورتها ما يكفي شاشته.
 *
 * بطاقةٌ بعرض الهاتف على شاشة 3x تحتاج نحو ألفِ بكسل، والمصغّرةُ 640: كانت
 * تُرسم وحدها فتتشوّش التفاصيلُ الدقيقة. و`sizes` يُحسب من الشبكة (`Grid`):
 * `auto-fit` بأعمدةٍ لا تضيق عن `min`، داخل عرض القالب، بحشوةٍ وفجوةٍ تتبدّلان
 * بالكثافة. فيُقارَن هنا بما يرسمه CSS نفسُه لكلّ كثافةٍ وعرضٍ وعدد — تقديرٌ
 * أصغرُ من البطاقة يجلب صورةً مشوّشة، فلا يُقبل ولو ببكسل.
 */

/**
 * حشوةُ القسم الأفقيّة وفجوةُ الشبكة كما يكتبها `site.css` نفسُه — دون 640
 * ومن 640، وما لم يُكتب في الثاني موروثٌ من الأوّل.
 */
function spacingFromCss(): Record<string, { pad: number; gap: number }[]> {
    const css = readFileSync(path.resolve(__dirname, '../../storefront/src/site/site.css'), 'utf8');
    const wide = css.indexOf('@media (min-width: 640px)');
    const read = (part: string, density: string) => {
        const sel = density === 'balanced' ? /\.w-site \{([^}]*)\}/ : new RegExp(`\\.w-site\\[data-density='${density}'\\] \\{([^}]*)\\}`);
        const body = part.match(sel)?.[1] ?? '';
        return {
            pad: body.match(/--w-pad: \d+px (\d+)px;/)?.[1],
            gap: body.match(/--w-gap: (\d+)px;/)?.[1],
        };
    };
    const out: Record<string, { pad: number; gap: number }[]> = {};
    for (const density of ['balanced', 'compact', 'spacious']) {
        const small = read(css.slice(0, wide), density);
        const large = read(css.slice(wide), density);
        out[density] = [
            { pad: Number(small.pad), gap: Number(small.gap) },
            { pad: Number(large.pad ?? small.pad), gap: Number(large.gap ?? small.gap) },
        ];
    }
    return out;
}

/** عرضُ البطاقة كما يرسمه CSS — `Grid` و`.w-grid-editorial` */
function actual(vw: number, g: { columns: number; min: number }, width: LayoutTokens['width'], center: boolean, count: number, first: boolean, d: { pad: number; gap: number }): number {
    const content = WIDTH_PX[width] === '100%' ? Infinity : parseInt(WIDTH_PX[width], 10);
    let box = Math.min(vw - 2 * d.pad, content);
    if (center) box = Math.min(box, Math.max(1, Math.min(g.columns, 6)) * (g.min + 60));
    const fit = box < g.min ? 1 : Math.floor((box + d.gap) / (g.min + d.gap));
    const n = Math.max(1, Math.min(fit, count));
    const card = (box - (n - 1) * d.gap) / n;
    return first && vw >= 760 && n >= 2 ? 2 * card + d.gap : card;
}

/** ما يقوله `sizes` لعرض شاشةٍ — أوّلُ شرطٍ يصدق، كما يقرؤه المتصفّح */
function claimed(sizes: string, vw: number): number {
    for (const part of sizes.split(', ')) {
        const m = part.match(/^\(min-width: (\d+)px\) (.+)$/);
        if (m && vw < Number(m[1])) continue;
        const v = m ? m[2] : part;
        const px = v.match(/^(\d+)px$/);
        if (px) return Number(px[1]);
        const calc = v.match(/^calc\(([\d.]+)vw ([-+]) (\d+)px\)$/);
        if (!calc) throw new Error('شكلٌ لا يُقرأ: ' + v);
        return (Number(calc[1]) * vw) / 100 + (calc[2] === '-' ? -1 : 1) * Number(calc[3]);
    }
    throw new Error('لا شرطَ افتراضيّ في ' + sizes);
}

describe('cardSizes: عرضُ البطاقة لا يُقدَّر أصغرَ ممّا يُرسم', () => {
    it('حشوةُ كلّ كثافةٍ وفجوتُها هي ما في site.css', () => {
        expect(SPACING).toEqual(spacingFromCss());
    });

    const shapes: LayoutTokens['grid'][] = ['classic', 'dense', 'large', 'editorial'];
    const widths = Object.keys(WIDTH_PX) as LayoutTokens['width'][];
    const spacing = Object.entries(spacingFromCss());

    /*
     * ‏كلُّ عيّنةٍ تُفحص — قرابةُ مليونٍ لكلّ شكل — والمخالفاتُ تُجمع ثمّ تُعرض معًا:
     * `expect` لكلّ عيّنةٍ تجاوز مهلةَ الاختبار على خادم CI دون أن يُضيف فحصًا.
     */
    it.each(shapes)('%s: كلُّ كثافةٍ وعرضٍ وعددٍ وشاشة من 280 إلى 2560', (shape) => {
        let worst = 0;
        const smaller: string[] = [];
        for (const width of widths)
            for (const columns of [2, 3, 4, 6])
                for (const center of [false, true])
                    for (const count of [1, 2, 3, 4, 8, 24]) {
                        const g = gridOf(shape, columns);
                        for (const first of shape === 'editorial' ? [false, true] : [false])
                            for (const [density, steps] of spacing) {
                                const sizes = cardSizes(g, { width, density: density as LayoutTokens['density'] }, center, count, first ? 2 : 1);
                                for (let vw = 280; vw <= 2560; vw += 2) {
                                    const real = actual(vw, g, width, center, count, first, steps[vw < 640 ? 0 : 1]);
                                    const said = claimed(sizes, vw);
                                    if (said < real - 1e-6) smaller.push(`${shape} ${width} ${density} cols=${columns} center=${center} n=${count} first=${first} vw=${vw}: ${said} < ${real} ← ${sizes}`);
                                    worst = Math.max(worst, said / real);
                                }
                            }
                    }
        expect(smaller.slice(0, 5)).toEqual([]);
        // ‏ولا يزيد عليه إلّا تقريبُ الكسر — فلا يجلب الصورةَ الكبيرة بلا حاجة
        expect(worst).toBeLessThan(1.02);
    }, 30_000);

    it('بطاقةُ هاتفٍ عرضُه 390 في قالبٍ عاديّ: عرضُ الشاشة إلّا الحشوة — فشاشةُ 3x تطلب ألفَ بكسل', () => {
        const sizes = cardSizes(gridOf('classic', 4), { width: 'normal', density: 'balanced' }, false, 8);
        expect(claimed(sizes, 390)).toBe(390 - 36);
        expect(claimed(sizes, 390) * 3).toBeGreaterThan(640);
        // ‏والحاسوبُ بأربعة أعمدةٍ في 1120: بطاقةٌ دون 300 — فتكفيه المصغّرة
        expect(claimed(sizes, 1366)).toBeLessThan(300);
    });
});

const fixture = JSON.parse(readFileSync(path.resolve(__dirname, '../../storefront/tests/fixtures/store.json'), 'utf8')) as SiteDocument;

describe('بطاقةُ المنتج ترسم أحجامَها', () => {
    const page = (items: DocProduct[]) => {
        const doc = JSON.parse(JSON.stringify(fixture)) as SiteDocument;
        const home = doc.pages.find((p) => p.is_home) ?? doc.pages[0];
        home.sections = [{ type: 'product_catalog', visible: true, source: 'products', data: { title: 'الكلّ' }, items }];
        return render(<Site doc={doc} mode="live" />).container;
    };

    it('srcset من المستند وsizes من الشبكة — والمصغّرةُ src لمتصفّحٍ لا يقرؤهما', () => {
        const base = fixture.data!.products![0];
        const el = page([
            { ...base, id: 601, thumb: 'https://x.test/a.jpg.w640.webp', srcset: 'https://x.test/a.jpg.w640.webp 640w, https://x.test/a.jpg.w1600.webp 1600w' },
            { ...base, id: 602, image: 'https://x.test/b.jpg', thumb: 'https://x.test/b.jpg', srcset: null },
        ]);
        const both = el.querySelector('img[src="https://x.test/a.jpg.w640.webp"]')!;
        expect(both.getAttribute('srcset')).toBe('https://x.test/a.jpg.w640.webp 640w, https://x.test/a.jpg.w1600.webp 1600w');
        // ‏وعرضُها من شبكة القسم: بعرض الشاشة إلّا الحشوة على الهاتف، وأضيقُ بأعمدةٍ أكثر
        expect(both.getAttribute('sizes')).toMatch(/^\(min-width: \d+px\) .+, calc\(100vw - \d+px\)$/);

        const bare = el.querySelector('img[src="https://x.test/b.jpg"]')!;
        expect(bare.hasAttribute('srcset')).toBe(false);
        expect(bare.hasAttribute('sizes')).toBe(false);
    });
});
