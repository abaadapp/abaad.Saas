import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * «إضافات اختيارية» في RIBBON — تُحمل على بند الصنف نفسِه، وهي من هويّته.
 *
 * ═══ ما يُحرس ═══
 *
 *   - بندان من الصنف نفسِه بإضافاتٍ مختلفة بندان — لا تُدمج اختياراتُهما.
 *     والإضافاتُ نفسُها بأيّ ترتيبٍ تزيد الكمّيّة. وبندٌ بلا إضافاتٍ بندُ ما قبلها.
 *   - التعديلُ والحذفُ على البند بإضافاته، لا على أخيه.
 *   - عدّادُ الإضافة يبدأ بصفر ويقف عند حدّه، وما نفد رفُّه لا يُعدّ. وما
 *     اختير يُسلَّم مرتّبًا بالمعرّف، و«إجمالي البند» ثمنُ الصنف في كمّيّته
 *     وثمنُ كلّ إضافةٍ في كمّيّتها هي مرّةً — بالألف صحيحًا.
 *   - والصفحةُ تُحمّل الإضافاتِ على بند الصنف (`line.addons`)، والسلّةُ تحذف
 *     البندَ بإضافاته.
 *
 * والتسعيرُ نفسُه في `AnAddonIsSoldWhereItsOwnerOpensItTest`: الأسعارُ هنا
 * للعرض، والخادمُ يسعّر من القاعدة.
 */

type Addon = { id: number; qty: number };
type Item = { id: number; variant_id: number | null; qty: number; note?: string; addons?: Addon[] };
type Lines = {
    same: (it: Item, id: number, v: number | null, note?: string | null, addons?: Addon[]) => boolean;
    add: (items: Item[], id: number, v: number | null, qty: number, note?: string | null, addons?: Addon[]) => Item[];
    set: (items: Item[], id: number, v: number | null, qty: number, note?: string | null, addons?: Addon[]) => Item[];
    merge: (items: Item[], lines: Item[], limits?: { qty?: number; lines?: number }) => { ok: boolean; items?: Item[]; reason?: string };
};
type Counter = { chosen: () => Addon[]; total: () => number; clear: () => void; repaint: () => void };
type Addons = {
    mount: (box: Element | null, opts: {
        mainAmount?: () => number | null;
        mainQty?: () => number;
        format?: (n: number) => string;
        total?: HTMLElement | null;
        max?: number;
        changed?: () => void;
    }) => Counter;
};

const JS = (f: string) => resolve(__dirname, '../../resources/js/store', f);
const VIEW = (f: string) => resolve(__dirname, '../../resources/views/store/ribbon', f);
const w = () => window as unknown as { RBLines: Lines; RBAddons: Addons };

beforeAll(() => {
    // كما تُضمَّن في الصفحة: نصٌّ داخل `<script>` لا وحدةٌ تُستورد
    for (const f of ['ribbon-cart-lines.js', 'ribbon-addons.js']) {
        new Function(readFileSync(JS(f), 'utf8'))();
    }
});

describe('هويّةُ البند بإضافاته', () => {
    it('باقةٌ بشوكولاتة وباقةٌ بلا شيء بندان، والإضافاتُ نفسُها بأيّ ترتيبٍ تزيد الكمّيّة', () => {
        const L = w().RBLines;
        let items: Item[] = [];
        items = L.add(items, 7, null, 1, null, [{ id: 3, qty: 1 }, { id: 9, qty: 2 }]);
        items = L.add(items, 7, null, 1);
        items = L.add(items, 7, null, 2, null, [{ id: 9, qty: 2 }, { id: 3, qty: 1 }]);

        expect(items).toEqual([
            { id: 7, variant_id: null, qty: 3, addons: [{ id: 3, qty: 1 }, { id: 9, qty: 2 }] },
            { id: 7, variant_id: null, qty: 1 },
        ]);
    });

    it('كمّيّةُ الإضافة من الهويّة كذلك — شوكولاتة ×١ غيرُ شوكولاتة ×٢', () => {
        const L = w().RBLines;
        let items: Item[] = [];
        items = L.add(items, 7, null, 1, null, [{ id: 3, qty: 1 }]);
        items = L.add(items, 7, null, 1, null, [{ id: 3, qty: 2 }]);

        expect(items).toHaveLength(2);
    });

    it('ما لا يصحّ من الإضافات يُطرح، وبندٌ بلا إضافاتٍ صحيحة بندُ ما قبلها', () => {
        const L = w().RBLines;
        const items = L.add([], 7, null, 1, null, [{ id: 0, qty: 1 }, { id: 3, qty: 0 }]);

        expect(items).toEqual([{ id: 7, variant_id: null, qty: 1 }]);
        expect(L.same(items[0], 7, null, '', [])).toBe(true);
    });

    it('التعديلُ والحذفُ على البند بإضافاته لا على أخيه', () => {
        const L = w().RBLines;
        let items: Item[] = [];
        items = L.add(items, 7, null, 1, null, [{ id: 3, qty: 1 }]);
        items = L.add(items, 7, null, 1);

        items = L.set(items, 7, null, 4, null, [{ id: 3, qty: 1 }]);
        expect(items.map((i) => i.qty)).toEqual([4, 1]);

        items = L.set(items, 7, null, 0, null, [{ id: 3, qty: 1 }]);
        expect(items).toEqual([{ id: 7, variant_id: null, qty: 1 }]);
    });

    it('والبنودُ معًا تُدمج بإضافاتها — وحدُّ الكمّيّة على البند بإضافاته', () => {
        const L = w().RBLines;
        const start = L.add([], 7, null, 49, null, [{ id: 3, qty: 1 }]);

        // بندٌ آخر بلا إضافات لا يُحسب على كمّيّة أخيه
        expect(L.merge(start, [{ id: 7, variant_id: null, qty: 2 }], { qty: 50 }).ok).toBe(true);
        expect(L.merge(start, [{ id: 7, variant_id: null, qty: 2, addons: [{ id: 3, qty: 1 }] }], { qty: 50 }).reason).toBe('qty');
    });
});

describe('عدّاداتُ «إضافات اختيارية»', () => {
    function page(opts: { amount?: number | null; qty?: number; max?: number } = {}) {
        document.body.innerHTML = `
            <div data-rb-addons>
                <div data-rb-addon="9" data-qty="0" data-amount="1">
                    <button data-rb-addon-dec></button><span data-rb-addon-count>0</span><button data-rb-addon-inc></button>
                </div>
                <div data-rb-addon="3" data-qty="0" data-amount="2.5">
                    <button data-rb-addon-dec></button><span data-rb-addon-count>0</span><button data-rb-addon-inc></button>
                </div>
                <div data-rb-addon="5" data-qty="0" data-amount="4" data-soldout>
                    <button data-rb-addon-inc></button>
                </div>
            </div>
            <p data-rb-line-total hidden><strong data-rb-line-total-text></strong></p>`;
        const changed = vi.fn();
        const counter = w().RBAddons.mount(document.querySelector('[data-rb-addons]'), {
            mainAmount: () => (opts.amount === undefined ? 20 : opts.amount),
            mainQty: () => opts.qty ?? 1,
            format: (n) => n.toFixed(3),
            total: document.querySelector<HTMLElement>('[data-rb-line-total]'),
            max: opts.max ?? 20,
            changed,
        });
        const click = (id: number, which: 'inc' | 'dec') =>
            document.querySelector<HTMLElement>(`[data-rb-addon="${id}"] [data-rb-addon-${which}]`)!.click();
        const total = document.querySelector<HTMLElement>('[data-rb-line-total]')!;

        return { counter, click, total, changed };
    }

    it('يبدأ بصفر، ويُسلّم ما اختير مرتّبًا بالمعرّف، و«−» عند الصفر لا ينقص', () => {
        const { counter, click, total } = page();
        expect(counter.chosen()).toEqual([]);
        expect(total.hidden).toBe(true);

        click(9, 'inc');
        click(3, 'inc');
        click(3, 'inc');
        click(9, 'dec');
        click(9, 'dec');

        expect(counter.chosen()).toEqual([{ id: 3, qty: 2 }]);
        expect(document.querySelector('[data-rb-addon="3"] [data-rb-addon-count]')!.textContent).toBe('2');
        expect(document.querySelector<HTMLButtonElement>('[data-rb-addon="9"] [data-rb-addon-dec]')!.disabled).toBe(true);
    });

    it('«إجمالي البند» ثمنُ الصنف في كمّيّته وثمنُ الإضافة في كمّيّتها هي مرّةً', () => {
        const { counter, click, total } = page({ amount: 20, qty: 2 });
        click(3, 'inc');
        click(9, 'inc');

        // ٢٠ × ٢ + ٢٫٥ + ١ — لا تُضرب الإضافةُ في كمّيّة الصنف
        expect(counter.total()).toBe(3.5);
        expect(total.hidden).toBe(false);
        expect(total.textContent).toContain('43.500');
    });

    it('بالألف صحيحًا لا بكسورٍ عائمة', () => {
        document.body.innerHTML = `<div data-rb-addons>
            <div data-rb-addon="1" data-qty="0" data-amount="0.1"><button data-rb-addon-inc></button></div>
            <div data-rb-addon="2" data-qty="0" data-amount="0.2"><button data-rb-addon-inc></button></div></div>`;
        const c = w().RBAddons.mount(document.querySelector('[data-rb-addons]'), {});
        document.querySelectorAll<HTMLElement>('[data-rb-addon-inc]').forEach((b) => b.click());

        expect(c.total()).toBe(0.3);
    });

    it('ما نفد رفُّه لا يُعدّ، والعدّادُ يقف عند حدّه', () => {
        const { counter, click } = page({ max: 2 });
        click(5, 'inc');
        click(9, 'inc');
        click(9, 'inc');
        click(9, 'inc');

        expect(counter.chosen()).toEqual([{ id: 9, qty: 2 }]);
        expect(document.querySelector<HTMLButtonElement>('[data-rb-addon="9"] [data-rb-addon-inc]')!.disabled).toBe(true);
    });

    it('يُطوى المجموعُ ما دام سعرُ الصنف لم يُعرف، ويُفرَّغ العدّادُ بعد الإضافة', () => {
        const { counter, click, total, changed } = page({ amount: null });
        click(9, 'inc');
        expect(total.hidden).toBe(true);
        expect(changed).toHaveBeenCalled();

        counter.clear();
        expect(counter.chosen()).toEqual([]);
    });

    it('صفحةٌ بلا قسم إضافاتٍ لا تُحمّل بندَها شيئًا', () => {
        const c = w().RBAddons.mount(null, {});
        expect(c.chosen()).toEqual([]);
        expect(c.total()).toBe(0);
    });
});

describe('موصولةٌ في الصفحة والسلّة', () => {
    it('الصفحةُ تُحمّل الإضافاتِ على بند الصنف، وتُدخلها مجموعَ «أضف مع طلبك»', () => {
        const product = readFileSync(VIEW('product.blade.php'), 'utf8');
        expect(product).toContain("@include('store.ribbon._addons'");
        expect(product).toContain('if (picked.length) line.addons = picked;');
        expect(product).toContain('extra: function () { return addons ? addons.total() : 0; },');
        expect(product).toContain('RB.add(id, variant, qty, text || null, chosenAddons())');

        const partial = readFileSync(VIEW('_addons.blade.php'), 'utf8');
        expect(partial).toContain("resource_path('js/store/ribbon-addons.js')");
        expect(partial).toContain("$t['addonsHint']");
    });

    it('السلّةُ والإتمامُ يرسمان الإضافاتِ تحت بندها', () => {
        const cart = readFileSync(VIEW('cart.blade.php'), 'utf8');
        expect(cart).toContain('data-testid="rb-cart-addons"');
        expect(cart).toContain('RBLines.same(it, id, v, note, addons)');

        const checkout = readFileSync(VIEW('checkout.blade.php'), 'utf8');
        expect(checkout).toContain('data-testid="rb-summary-addons"');

        const layout = readFileSync(VIEW('layout.blade.php'), 'utf8');
        expect(layout).toContain('if (addons && addons.length) line.addons = addons;');
    });
});
