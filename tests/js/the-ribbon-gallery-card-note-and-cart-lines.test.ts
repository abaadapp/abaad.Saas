import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * صفحةُ صنف RIBBON وسلّتُه — المعرض، ونصُّ كرت الهدية، وهويّةُ البند.
 *
 * ═══ ما يُحرس ═══
 *
 *   - المصغّرةُ تُبدّل الصورةَ الكبيرة وتُعلَّم مختارةً، بالضغط وبالأسهم.
 *   - كرتُ الهدية لا يُعطى نصًّا فارغًا: يُقال تحت الخانة ويُنقل إليها المؤشّر.
 *   - كرتان بنصّين بندان في السلّة، والنصُّ نفسُه يزيد الكمّيّة، وتعديلُ
 *     بندٍ أو حذفُه لا يمسّ الآخر.
 *
 * والمكتباتُ تُضمَّن في القوالب كما هي، فيُقرأ القالبُ هنا ليُعرف أنّها
 * موصولة. وما يرسمه الخادمُ ويحرسه في `TheRibbonProductPageShowsItsWholeGalleryTest`
 * و`ASaudsGiftCardIsAProductWithItsMessageTest`.
 */

type Item = { id: number; variant_id: number | null; qty: number; note?: string };

type Gallery = { mount: (row: HTMLElement, main: HTMLImageElement) => { pick: (i: number) => void } | null };
type CardNote = { mount: (text: HTMLTextAreaElement) => { take: () => string } };
type Lines = {
    same: (it: Item, id: number, v: number | null, note?: string | null) => boolean;
    add: (items: Item[], id: number, v: number | null, qty: number, note?: string | null) => Item[];
    set: (items: Item[], id: number, v: number | null, qty: number, note?: string | null) => Item[];
};

const JS = (f: string) => resolve(__dirname, '../../resources/js/store', f);
const VIEW = (f: string) => resolve(__dirname, '../../resources/views/store/ribbon', f);

const w = () => window as unknown as { RBGallery: Gallery; RBCardNote: CardNote; RBLines: Lines };

beforeAll(() => {
    // كما تُضمَّن في الصفحة: نصٌّ داخل `<script>` لا وحدةٌ تُستورد
    for (const f of ['ribbon-gallery.js', 'ribbon-card-note.js', 'ribbon-cart-lines.js']) {
        new Function(readFileSync(JS(f), 'utf8'))();
    }
});

describe('معرضُ صفحة الصنف', () => {
    function page(dir = 'rtl') {
        document.documentElement.dir = dir;
        document.body.innerHTML = `
            <img data-rb-main src="a.jpg">
            <div data-rb-thumbs style="direction:${dir}">
                <button type="button" class="rb-thumb is-on" data-src="a.jpg" aria-pressed="true"></button>
                <button type="button" class="rb-thumb" data-src="b.jpg" aria-pressed="false"></button>
                <button type="button" class="rb-thumb" data-src="c.jpg" aria-pressed="false"></button>
            </div>`;
        const main = document.querySelector<HTMLImageElement>('[data-rb-main]')!;
        const thumbs = [...document.querySelectorAll<HTMLButtonElement>('.rb-thumb')];
        w().RBGallery.mount(document.querySelector<HTMLElement>('[data-rb-thumbs]')!, main);
        return { main, thumbs };
    }

    it('يُبدّل الصورةَ الكبيرة بالضغط ويُعلّم المختارةَ وحدها', () => {
        const { main, thumbs } = page();
        thumbs[2].click();

        expect(main.getAttribute('src')).toBe('c.jpg');
        expect(thumbs.map((t) => t.classList.contains('is-on'))).toEqual([false, false, true]);
        expect(thumbs.map((t) => t.getAttribute('aria-pressed'))).toEqual(['false', 'false', 'true']);
    });

    it('ينتقل بالأسهم بترتيب العرض — والعربيّةُ من اليمين', () => {
        const { main, thumbs } = page('rtl');
        thumbs[0].focus();
        thumbs[0].dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowLeft', bubbles: true }));

        expect(main.getAttribute('src')).toBe('b.jpg');
        expect(document.activeElement).toBe(thumbs[1]);

        thumbs[1].dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }));
        expect(main.getAttribute('src')).toBe('a.jpg');
    });

    it('موصولٌ في صفحة الصنف لا في الرفّ', () => {
        const product = readFileSync(VIEW('product.blade.php'), 'utf8');
        expect(product).toContain("resource_path('js/store/ribbon-gallery.js')");
        expect(product).toContain('@if (count($gallery) > 1)');
        expect(readFileSync(VIEW('_card.blade.php'), 'utf8')).not.toContain('rb-thumb');
    });
});

describe('نصُّ كرت الهدية على صفحته — اختياريّ', () => {
    function page() {
        document.body.innerHTML = `<textarea data-rb-card-note></textarea>`;
        const text = document.querySelector<HTMLTextAreaElement>('[data-rb-card-note]')!;
        text.scrollIntoView = vi.fn();
        return { text, note: w().RBCardNote.mount(text) };
    }

    it('الفارغُ يُعطى فراغًا ولا يمنع شيئًا — لا تنبيهَ ولا نقلَ مؤشّر', () => {
        const { text, note } = page();
        text.value = '   ';

        expect(note.take()).toBe('');
        expect(document.activeElement).not.toBe(text);
        expect(text.scrollIntoView).not.toHaveBeenCalled();
    });

    it('المكتوبُ يُعطى مقصوصَ الأطراف', () => {
        const { text, note } = page();
        text.value = '  Happy birthday  ';

        expect(note.take()).toBe('Happy birthday');
    });

    it('صفحةُ الكرت تُضيفه إلى السلّة بنصّه أو بلاه', () => {
        const file = readFileSync(VIEW('product.blade.php'), 'utf8');
        /*
            ويُقرأ فرعُ صفحة الكرت وحده: ملاحظةُ المنتج (`ribbon-notes.js`) قد تردّ
            نصًّا غيرَ إنجليزيّ في صفحة صنفٍ عاديّ — ورسالةُ الكرت لا تُردّ أبدًا.
        */
        const start = file.indexOf("@elseif ($product['gift_card'])");
        const product = file.slice(start, file.indexOf('@else\n', start));
        expect(start).toBeGreaterThan(-1);
        expect(product).not.toContain('if (text === null) return;');
        expect(product).not.toContain('RBNotes');
        expect(product).toContain('if (text) RB.add(id, variant, qty, text); else RB.add(id, variant, qty);');
        expect(file).not.toContain('data-rb-card-note-err');
    });

    it('وكرتان بلا رسالةٍ بندٌ واحد، وكرتٌ برسالةٍ بندٌ آخر', () => {
        const L = w().RBLines;
        let items: Item[] = [];
        items = L.add(items, 7, null, 1);
        items = L.add(items, 7, null, 1);
        items = L.add(items, 7, null, 1, 'مبروك');

        expect(items).toEqual([
            { id: 7, variant_id: null, qty: 2 },
            { id: 7, variant_id: null, qty: 1, note: 'مبروك' },
        ]);
    });
});

describe('بنودُ السلّة', () => {
    it('كرتان بنصّين بندان، والنصُّ نفسُه يزيد الكمّيّة', () => {
        const L = w().RBLines;
        let items: Item[] = [];
        items = L.add(items, 7, null, 1, 'For Mum');
        items = L.add(items, 7, null, 1, 'For Dad');
        items = L.add(items, 7, null, 2, 'For Mum');

        expect(items).toEqual([
            { id: 7, variant_id: null, qty: 3, note: 'For Mum' },
            { id: 7, variant_id: null, qty: 1, note: 'For Dad' },
        ]);
    });

    it('تعديلُ بندٍ أو حذفُه لا يمسّ أخاه، وما لا نصَّ له على حاله', () => {
        const L = w().RBLines;
        let items: Item[] = [];
        items = L.add(items, 3, null, 1);
        items = L.add(items, 3, null, 1);
        items = L.add(items, 7, null, 1, 'For Mum');
        items = L.add(items, 7, null, 1, 'For Dad');

        expect(items[0]).toEqual({ id: 3, variant_id: null, qty: 2 });

        items = L.set(items, 7, null, 4, 'For Dad');
        expect(items.map((i) => [i.note ?? '', i.qty])).toEqual([['', 2], ['For Mum', 1], ['For Dad', 4]]);

        items = L.set(items, 7, null, 0, 'For Mum');
        expect(items.map((i) => i.note ?? '')).toEqual(['', 'For Dad']);
    });

    it('السلّةُ تقرأ منه، وتعرض نصَّ الكرت وتعدّل البندَ بنصّه', () => {
        const layout = readFileSync(VIEW('layout.blade.php'), 'utf8');
        expect(layout).toContain("resource_path('js/store/ribbon-cart-lines.js')");
        expect(layout).toContain('write(RBLines.add(read(), id, variantId, qty, note));');

        const cart = readFileSync(VIEW('cart.blade.php'), 'utf8');
        expect(cart).toContain('T.cartCardMessage');
        expect(cart).toContain('RB.set(id, v, 0, note)');
    });
});
