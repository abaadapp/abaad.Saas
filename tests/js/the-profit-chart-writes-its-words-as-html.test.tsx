import { cleanup, fireEvent, render } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';

import MultiLineChart, { axisIndexes } from '@/Components/charts/MultiLineChart';

/**
 * رسمُ «صافي الربح» يكتب كلماته HTML داخل foreignObject — لا SVG <text>.
 *
 * WebKit (Safari وآيباد) لا يُشكّل العربيّة داخل <text>: أسماءُ الأيّام
 * والأشهر تخرج مفكّكةً معكوسة، و«ر.ع» تنقلب حول الرقم. والنصُّ في DOM
 * سليمٌ على كلّ محرّك، فيُحرس هنا الشكلُ الذي يمنع ذلك: لا <text>،
 * والزمنُ باتّجاه القراءة، والترتيبُ ترتيبُ الخادم، والتسمياتُ لا تتراكب.
 */

const WIDTH = 720;

const MONTHS = ['يناير 2026', 'فبراير 2026', 'مارس 2026', 'أبريل 2026', 'مايو 2026', 'يونيو 2026',
    'يوليو 2026', 'أغسطس 2026', 'سبتمبر 2026', 'أكتوبر 2026', 'نوفمبر 2026', 'ديسمبر 2026'];
const DAYS = Array.from({ length: 31 }, (_, i) => `الخميس ${i + 1} أكتوبر`);

const draw = (labels: string[], dir: 'rtl' | 'ltr') => {
    cleanup();
    document.documentElement.dir = dir;
    const data = labels.map((_, i) => (i % 3 === 0 ? -5 : i * 10));

    return render(
        <MultiLineChart
            labels={labels}
            series={[{ key: 'net_profit', label: 'صافي الربح', data }]}
            format={(v) => `${v} ر.ع`}
        />,
    );
};

/** تسمياتُ المحور الأفقيّ بموضعها وعرضها ونصّها — بترتيب الرسم */
const xLabels = (c: HTMLElement) =>
    Array.from(c.querySelectorAll<SVGForeignObjectElement>('foreignObject[data-axis="x"]')).map((fo) => ({
        i: Number(fo.getAttribute('data-index')),
        x: Number(fo.getAttribute('x')),
        w: Number(fo.getAttribute('width')),
        text: fo.textContent,
        dir: fo.querySelector('div')!.getAttribute('dir'),
    }));

/** أوّلُ نقطةٍ في المسار — `M<x>,<y>` */
const firstX = (c: HTMLElement) => Number(/^M([\d.-]+),/.exec(c.querySelector('path')!.getAttribute('d')!)![1]);
const lastX = (c: HTMLElement) => {
    const d = c.querySelector('path')!.getAttribute('d')!;

    return Number(/L([\d.-]+),[\d.-]+$/.exec(d)![1]);
};

afterEach(() => {
    document.documentElement.dir = 'rtl';
});

describe('لا SVG <text> — الكلماتُ HTML', () => {
    it.each(['rtl', 'ltr'] as const)('%s: لا <text>، والمحوران في foreignObject', (dir) => {
        const { container } = draw(MONTHS, dir);

        expect(container.querySelectorAll('text')).toHaveLength(0);
        expect(container.querySelectorAll('foreignObject[data-axis="x"] > div').length).toBeGreaterThan(0);
        expect(container.querySelectorAll('foreignObject[data-axis="y"] > div')).toHaveLength(5);
    });

    it('أرقامُ المحور الرأسيّ وعملتُها LTR في الصفحة العربيّة', () => {
        const { container } = draw(MONTHS, 'rtl');

        for (const div of container.querySelectorAll('foreignObject[data-axis="y"] > div')) {
            expect(div.getAttribute('dir')).toBe('ltr');
            expect(div.textContent).toMatch(/^-?\d+(\.\d+)? ر\.ع$/);
        }
    });
});

describe('الزمنُ باتّجاه القراءة', () => {
    it('العربيّة: أوّلُ نقطةٍ يمينًا، والمحورُ الرأسيّ يمينًا', () => {
        const { container } = draw(MONTHS, 'rtl');

        expect(firstX(container)).toBeGreaterThan(lastX(container));
        const y = Number(container.querySelector('foreignObject[data-axis="y"]')!.getAttribute('x'));
        expect(y).toBeGreaterThan(firstX(container) - 1);
    });

    it('الإنجليزيّة: أوّلُ نقطةٍ يسارًا، والمحورُ الرأسيّ يسارًا', () => {
        const { container } = draw(MONTHS.map((_, i) => `M${i + 1}`), 'ltr');

        expect(firstX(container)).toBeLessThan(lastX(container));
        expect(Number(container.querySelector('foreignObject[data-axis="y"]')!.getAttribute('x'))).toBe(0);
    });
});

describe('الترتيبُ ترتيبُ الخادم — والتسمياتُ لا تتراكب', () => {
    it.each(['rtl', 'ltr'] as const)('السنة (%s): الأشهرُ بترتيبها، وآخرُها مكتوب', (dir) => {
        const { container } = draw(MONTHS, dir);
        const shown = xLabels(container);

        // النصُّ نصُّ الخادم بموضعه — لا عكسَ ولا تكرار
        expect(shown.map((l) => l.text)).toEqual(shown.map((l) => MONTHS[l.i]));
        expect(shown.map((l) => l.i)).toEqual([...shown.map((l) => l.i)].sort((a, b) => a - b));
        expect(shown.at(-1)!.text).toBe('ديسمبر 2026');

        // والموضعُ يتبع الاتّجاه: يمينًا فيسارًا في العربيّة
        const xs = shown.map((l) => l.x);
        const sorted = [...xs].sort((a, b) => (dir === 'rtl' ? b - a : a - b));
        expect(xs).toEqual(sorted);
    });

    it.each(['rtl', 'ltr'] as const)('الشهر (%s): الأيّامُ بلا تكرار ولا تراكب، وداخل الرسم', (dir) => {
        const { container } = draw(DAYS, dir);
        const shown = xLabels(container);

        expect(new Set(shown.map((l) => l.i)).size).toBe(shown.length);
        expect(shown.length).toBeLessThanOrEqual(9);
        expect(shown[0].text).toBe(DAYS[0]);
        expect(shown.at(-1)!.text).toBe(DAYS[30]);

        const boxes = [...shown].sort((a, b) => a.x - b.x);
        for (const b of boxes) {
            expect(b.x).toBeGreaterThanOrEqual(0);
            expect(b.x + b.w).toBeLessThanOrEqual(WIDTH + 0.001);
            expect(b.dir).toBe('auto');
        }
        for (let k = 1; k < boxes.length; k++) {
            expect(boxes[k].x).toBeGreaterThanOrEqual(boxes[k - 1].x + boxes[k - 1].w - 0.001);
        }
    });

    it('اختيارُ التسميات: الأخيرةُ تحلّ محلَّ سابقتها إن ضاق ما بينهما', () => {
        expect(axisIndexes(12, 2)).toEqual([0, 2, 4, 6, 8, 11]);
        expect(axisIndexes(31, 4)).toEqual([0, 4, 8, 12, 16, 20, 24, 30]);
        expect(axisIndexes(9, 2)).toEqual([0, 2, 4, 6, 8]);
        expect(axisIndexes(1, 1)).toEqual([0]);
        expect(axisIndexes(2, 1)).toEqual([0, 1]);
    });
});

describe('التلميحُ والنقاطُ كما كانت', () => {
    it('الضغطُ على عمودٍ يرسم خطَّ التعقّب والنقطةَ والتلميح بتسمية نقطته', () => {
        const { container, getByTestId } = draw(MONTHS, 'rtl');

        fireEvent.click(container.querySelectorAll('rect')[3]);

        expect(container.querySelectorAll('circle')).toHaveLength(1);
        expect(getByTestId('profit-chart-tip')).toHaveTextContent('أبريل 2026');
    });
});
