import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * سلّةُ RIBBON — ما اختير معًا يُكتب معًا، أو لا يُكتب منه شيء.
 *
 * ═══ ما يُحرس ═══
 *
 *   - البنودُ تُدمج بما في السلّة: البندُ نفسُه (صنفٌ ومقاسٌ ونصّ) تزيد
 *     كمّيّتُه، ومقاسٌ آخر أو نصُّ كرتٍ آخر بندٌ آخر.
 *   - وإضافةٌ تتجاوز حدَّ الكمّيّة أو البنود تُردّ كلُّها، والسلّةُ كما كانت —
 *     بحدَّي الخادم نفسَيهما (`WebCheckout::MAX_QTY` و`MAX_LINES`).
 *   - والقالبُ العامّ يكتب السلّةَ من هذا الباب وحده، مرّةً.
 *
 * وما يُقبل في الخادم بعد ذلك في `TheRibbonAddsEachExtraWithItsOwnQuantityTest`.
 */

type Line = { id: number; variant_id: number | null; qty: number; note?: string };
type LinesApi = {
    merge: (items: Line[], lines: Line[], limits: { qty: number; lines: number }) => { ok: boolean; items?: Line[]; reason?: string; max?: number };
};

const ROOT = resolve(__dirname, '../..');
const LINES = resolve(ROOT, 'resources/js/store/ribbon-cart-lines.js');
const LAYOUT = resolve(ROOT, 'resources/views/store/ribbon/layout.blade.php');

const linesApi = () => (window as unknown as { RBLines: LinesApi }).RBLines;

beforeAll(() => {
    new Function(readFileSync(LINES, 'utf8'))();
});

describe('السلّةُ كلُّها أو لا شيء — بحدَّي الخادم', () => {
    const limits = { qty: 50, lines: 40 };

    it('بنودٌ معًا تُدمج بما في السلّة — والبندُ نفسُه تزيد كمّيّتُه', () => {
        const r = linesApi().merge([{ id: 1, variant_id: 5, qty: 1 }], [
            { id: 1, variant_id: 5, qty: 2 },
            { id: 11, variant_id: null, qty: 3 },
        ], limits);

        expect(r.ok).toBe(true);
        expect(r.items).toEqual([{ id: 1, variant_id: 5, qty: 3 }, { id: 11, variant_id: null, qty: 3 }]);
    });

    it('ومقاسٌ آخرُ أو نصُّ كرتٍ آخر بندٌ آخر — لا يُدمجان', () => {
        const r = linesApi().merge([{ id: 1, variant_id: 5, qty: 1 }], [
            { id: 1, variant_id: 6, qty: 1 },
            { id: 14, variant_id: null, qty: 1, note: 'مبروك' },
            { id: 14, variant_id: null, qty: 1, note: 'شكرًا' },
        ], limits);

        expect(r.items).toHaveLength(4);
    });

    it('وكمّيّةٌ تتجاوز الحدَّ تردّ الإضافةَ كلَّها — والسلّةُ كما كانت', () => {
        const cart = [{ id: 1, variant_id: 5, qty: 49 }];
        const r = linesApi().merge(cart, [
            { id: 1, variant_id: 5, qty: 2 },
            { id: 11, variant_id: null, qty: 1 },
        ], limits);

        expect(r).toEqual({ ok: false, reason: 'qty', max: 50 });
        expect(cart).toEqual([{ id: 1, variant_id: 5, qty: 49 }]);
    });

    it('وبنودٌ تتجاوز الأربعين تُردّ كلُّها', () => {
        const cart = Array.from({ length: 39 }, (_, i) => ({ id: 100 + i, variant_id: null, qty: 1 }));
        const r = linesApi().merge(cart, [{ id: 1, variant_id: null, qty: 1 }, { id: 2, variant_id: null, qty: 1 }], limits);

        expect(r).toEqual({ ok: false, reason: 'lines', max: 40 });
        expect(cart).toHaveLength(39);
    });

    it('والحدُّ يُبلغ ولا يُتجاوز — خمسون تُقبل', () => {
        expect(linesApi().merge([], [{ id: 1, variant_id: null, qty: 50 }], limits).ok).toBe(true);
    });
});

describe('القالبُ العامّ يكتب السلّةَ من هذا الباب', () => {
    const layout = readFileSync(LAYOUT, 'utf8');

    it('والسلّةُ تُكتب مرّةً بحدَّي الخادم', () => {
        expect(layout).toContain('RBLines.merge(read(), lines, LIMITS)');
        expect(layout).toContain('WebCheckout::MAX_QTY');
        expect(layout).toContain('WebCheckout::MAX_LINES');
        expect(layout).toContain('addMany: addMany');
    });
});
