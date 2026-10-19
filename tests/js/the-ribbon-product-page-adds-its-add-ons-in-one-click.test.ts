import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * «أضف مع طلبك» — ضغطةٌ واحدةٌ تُدخل الصنفَ وما اختير معه، كلٌّ بكمّيّته.
 *
 * ═══ ما يُحرس ═══
 *
 *   - الصنفُ بمقاسه وكمّيّته، وكلُّ إضافةٍ مختارةٍ بندًا **بكمّيّتها هي** —
 *     عدّادٌ لكلّ بطاقة، لا تُضرب في كمّيّة الصنف ولا يُضرب الصنفُ فيها.
 *     واختيارُ البطاقة يبدأ بواحد، و«−» عند الواحد يتركها. وما لم يُختر لا يدخل.
 *   - وإضافةٌ لها مقاساتٌ لا يُختار لها مقاس: إن لم يختر الزبون لا يدخل شيء،
 *     ولا الصنفُ نفسُه، ويُقال له عند البطاقة.
 *   - والبنودُ تُسلَّم معًا إلى `RB.addMany` — كلُّها أو لا شيء — بصيغة السلّة
 *     `{id, variant_id, qty}`. ومن رُدّت إضافتُه يبقى اختيارُه كما هو.
 *   - والمجموعُ في الصفحة من أسعارٍ كتبها الخادم، ويُطوى ما دام سعرٌ لم يُعرف.
 *   - وضغطتان متتاليتان تُضيفان مرّةً واحدة.
 *
 * والمكتبةُ تُضمَّن في `store/ribbon/product.blade.php` كما هي، فيُقرأ القالبُ
 * هنا ليُعرف أنّ ما تبحث عنه مرسومٌ فيه. وما يرسمه الخادمُ لكلّ متجر في
 * `TheRibbonProductPageOffersAddOnsBeforeTheCartTest`، وما يكتبه الطلبُ من
 * الكمّيّات في `TheRibbonAddsEachExtraWithItsOwnQuantityTest`.
 */

type Line = { id: number; variant_id: number | null; qty: number; note?: string };
type Pick = { id: number; needs: boolean; variant: number | null; gift?: boolean; note?: string; qty?: number };
type Main = { id: number; variant_id: number | null; qty: number };
type Added = { ok: boolean; message?: string };

type Api = {
    mount: (box: Element, opts: {
        button: HTMLButtonElement;
        main: () => Main | null;
        addAll: (lines: Line[]) => Added;
        done: () => void;
        missing: (cards: HTMLElement[]) => void;
        refused?: (message: string) => void;
        format?: (amount: number) => string;
        mainAmount?: () => number | null;
        mainQty?: () => number;
        total?: HTMLElement | null;
        max?: number;
        wait?: number;
    }) => { repaint: () => void };
    compose: (main: Main, picks: Pick[]) => { ok: boolean; lines: Line[]; missing: Pick[] };
    money: (spec: { d: number; symbol: string; before: boolean }) => (amount: number) => string;
};

/** بنودُ كلّ نداءٍ لـ`addAll` بصيغة `[id, variant, qty, note?]` — كما كانت تُقرأ */
function flat(addAll: ReturnType<typeof vi.fn>): (number | string | null)[][] {
    return addAll.mock.calls.flatMap((call) =>
        (call[0] as Line[]).map((l) => (l.note ? [l.id, l.variant_id, l.qty, l.note] : [l.id, l.variant_id, l.qty])),
    );
}

const LIB = resolve(__dirname, '../../resources/js/store/ribbon-upsells.js');
const VIEW = resolve(__dirname, '../../resources/views/store/ribbon/product.blade.php');

const api = () => (window as unknown as { RBUpsells: Api }).RBUpsells;

beforeAll(() => {
    // كما تُضمَّن في الصفحة: نصٌّ داخل `<script>` لا وحدةٌ تُستورد
    new Function(readFileSync(LIB, 'utf8'))();
});

const BALLOON = 11, CHOC = 12, CARD = 13;
const SMALL = 121, LARGE = 122;

function stepper() {
    return `<div data-rb-up-qtybox hidden>
                    <button type="button" data-rb-up-dec>−</button><span data-rb-up-count>0</span><button type="button" data-rb-up-inc>+</button>
                    <span data-rb-up-line></span>
                </div>`;
}

function page(main: Main = { id: 1, variant_id: 5, qty: 3 }, answer: Added = { ok: true }) {
    document.body.innerHTML = `
        <div data-rb-upsells>
            <div class="rb-up" data-rb-up="${BALLOON}" data-qty="0" data-amount="1.5">
                <button type="button" data-rb-up-pick aria-pressed="false">بالون</button>
                ${stepper()}
            </div>
            <div class="rb-up" data-rb-up="${CHOC}" data-qty="0" data-needs-variant>
                <button type="button" data-rb-up-pick aria-pressed="false">شوكولاتة</button>
                ${stepper()}
                <div data-rb-up-sizes hidden>
                    <button type="button" data-rb-up-size="${SMALL}" data-amount="2" aria-pressed="false">صغيرة</button>
                    <button type="button" data-rb-up-size="${LARGE}" data-amount="3.25" aria-pressed="false">كبيرة</button>
                </div>
                <p data-rb-up-need hidden>اختر خيارًا</p>
            </div>
            <div class="rb-up" data-rb-up="${CARD}" data-qty="0" data-amount="0.5">
                <button type="button" data-rb-up-pick aria-pressed="false">كرت</button>
                ${stepper()}
            </div>
        </div>
        <p data-rb-up-total hidden><strong data-rb-up-total-text></strong></p>
        <button type="button" data-rb-add>أضف إلى السلة</button>`;

    const box = document.querySelector('[data-rb-upsells]')!;
    const button = document.querySelector<HTMLButtonElement>('[data-rb-add]')!;
    const total = document.querySelector<HTMLElement>('[data-rb-up-total]')!;
    const addAll = vi.fn(() => answer);
    const done = vi.fn();
    const missing = vi.fn();
    const refused = vi.fn();
    const format = api().money({ d: 3, symbol: 'ر.ع', before: false });
    const mounted = api().mount(box, {
        button, main: () => main, addAll, done, missing, refused, wait: 1500,
        format, mainAmount: () => 10, mainQty: () => main.qty, total, max: 50,
    });

    const card = (id: number) => box.querySelector<HTMLElement>(`[data-rb-up="${id}"]`)!;

    return {
        addAll, done, missing, refused, button, card, total, mounted,
        calls: () => flat(addAll),
        pick: (id: number) => card(id).querySelector<HTMLButtonElement>('[data-rb-up-pick]')!.click(),
        inc: (id: number, times = 1) => { for (let i = 0; i < times; i++) card(id).querySelector<HTMLButtonElement>('[data-rb-up-inc]')!.click(); },
        dec: (id: number, times = 1) => { for (let i = 0; i < times; i++) card(id).querySelector<HTMLButtonElement>('[data-rb-up-dec]')!.click(); },
        count: (id: number) => card(id).querySelector('[data-rb-up-count]')!.textContent,
        line: (id: number) => card(id).querySelector('[data-rb-up-line]')!.textContent,
        qtyBox: (id: number) => card(id).querySelector<HTMLElement>('[data-rb-up-qtybox]')!,
        size: (id: number) => box.querySelector<HTMLButtonElement>(`[data-rb-up-size="${id}"]`)!.click(),
        submit: () => button.click(),
        need: () => card(CHOC).querySelector<HTMLElement>('[data-rb-up-need]')!,
        sizes: () => card(CHOC).querySelector<HTMLElement>('[data-rb-up-sizes]')!,
        totalText: () => total.querySelector('[data-rb-up-total-text]')!.textContent,
    };
}

afterEach(() => {
    vi.useRealTimers();
});

describe('ما يدخل السلّة', () => {
    it('بلا إضافاتٍ مختارة: الصنفُ وحده بمقاسه وكمّيّته', () => {
        const p = page();
        p.submit();
        expect(p.calls()).toEqual([[1, 5, 3]]);
        expect(p.done).toHaveBeenCalledTimes(1);
    });

    it('الإضافةُ تُختار بواحدٍ — لا بكمّيّة الصنف', () => {
        const p = page();
        p.pick(BALLOON);
        p.submit();
        expect(p.calls()).toEqual([[1, 5, 3], [BALLOON, null, 1]]);
    });

    it('وذاتُ المقاس تدخل بالمقاس الذي اختاره الزبون', () => {
        const p = page();
        p.pick(CHOC);
        p.size(LARGE);
        p.submit();
        expect(p.calls()).toEqual([[1, 5, 3], [CHOC, LARGE, 1]]);
    });

    it('وما لم يُختر لا يدخل — ولا ما اختير ثمّ تُرك', () => {
        const p = page();
        p.pick(BALLOON);
        p.pick(CARD);
        p.pick(CARD);
        p.submit();
        expect(p.calls().map((c) => c[0])).toEqual([1, BALLOON]);
    });

    it('والصنفُ بلا مقاس يدخل بلا مقاس', () => {
        const p = page({ id: 7, variant_id: null, qty: 1 });
        p.pick(BALLOON);
        p.submit();
        expect(p.calls()).toEqual([[7, null, 1], [BALLOON, null, 1]]);
    });

    it('والبنودُ بصيغة السلّة وحدها — لا ثمنَ ولا اسم', () => {
        const r = api().compose({ id: 1, variant_id: 5, qty: 2 }, [
            { id: BALLOON, needs: false, variant: null },
            { id: CHOC, needs: true, variant: SMALL },
        ]);
        expect(r.ok).toBe(true);
        expect(r.lines).toEqual([
            { id: 1, variant_id: 5, qty: 2 },
            { id: BALLOON, variant_id: null, qty: 1 },
            { id: CHOC, variant_id: SMALL, qty: 1 },
        ]);
    });
});

describe('المقاسُ لا يُختار بالنيابة عن الزبون', () => {
    it('المقاساتُ مطويّةٌ حتّى تُختار الإضافة، ولا مقاسَ مختارٌ حين تنفتح', () => {
        const p = page();
        expect(p.sizes().hidden).toBe(true);
        p.pick(CHOC);
        expect(p.sizes().hidden).toBe(false);
        expect(p.card(CHOC).getAttribute('data-variant')).toBeNull();
        expect(p.sizes().querySelectorAll('[aria-pressed="true"]')).toHaveLength(0);
    });

    it('إضافةٌ بلا مقاس تمنع الضغطةَ كلَّها وتقول أيّها', () => {
        const p = page();
        p.pick(BALLOON);
        p.pick(CHOC);
        p.submit();
        expect(p.addAll).not.toHaveBeenCalled();
        expect(p.done).not.toHaveBeenCalled();
        expect(p.missing).toHaveBeenCalledTimes(1);
        // وتُسمّى البطاقةُ التي ينقصها — لتُساق الصفحةُ إليها
        expect(p.missing).toHaveBeenCalledWith([p.card(CHOC)]);
        expect(p.need().hidden).toBe(false);
        // والاختيارُ باقٍ كما تركه
        expect(p.card(BALLOON).classList.contains('on')).toBe(true);

        p.size(SMALL);
        expect(p.need().hidden).toBe(true);
        p.submit();
        expect(p.calls()).toEqual([[1, 5, 3], [BALLOON, null, 1], [CHOC, SMALL, 1]]);
    });

    it('ومن ترك الإضافةَ ثمّ عاد إليها يختار المقاسَ من جديد', () => {
        const p = page();
        p.pick(CHOC);
        p.size(LARGE);
        p.pick(CHOC);
        p.pick(CHOC);
        p.submit();
        expect(p.addAll).not.toHaveBeenCalled();
        expect(p.missing).toHaveBeenCalledTimes(1);
    });
});

describe('ضغطةٌ واحدة تُضيف مرّةً واحدة', () => {
    it('الضغطةُ الثانية قبل أن يعود الزرّ لا تُضيف شيئًا', () => {
        vi.useFakeTimers();
        const p = page();
        p.pick(BALLOON);
        p.submit();
        p.button.dispatchEvent(new MouseEvent('click'));
        expect(p.addAll).toHaveBeenCalledTimes(1);
        expect(p.calls()).toHaveLength(2);
        expect(p.button.disabled).toBe(true);

        vi.advanceTimersByTime(1500);
        expect(p.button.disabled).toBe(false);
    });

    it('وما دخل السلّة لا يبقى مختارًا فتُعيده ضغطةٌ تالية', () => {
        vi.useFakeTimers();
        const p = page();
        p.pick(BALLOON);
        p.pick(CHOC);
        p.size(SMALL);
        p.submit();
        expect(p.card(BALLOON).classList.contains('on')).toBe(false);
        expect(p.card(CHOC).getAttribute('data-variant')).toBeNull();
        expect(p.sizes().hidden).toBe(true);

        vi.advanceTimersByTime(1500);
        p.addAll.mockClear();
        p.submit();
        expect(p.calls()).toEqual([[1, 5, 3]]);
    });
});

describe('القالبُ يرسم ما تبحث عنه المكتبة', () => {
    const view = readFileSync(VIEW, 'utf8');

    it('يضمّنها من ملفّها ويربطها بزرّ السلّة و`RB.addMany` — كلُّها أو لا شيء', () => {
        expect(view).toContain("resource_path('js/store/ribbon-upsells.js')");
        expect(view).toMatch(/RBUpsells\.mount\([\s\S]*?addAll: function \(lines\) \{[\s\S]*?RB\.addMany\(lines\)/);
        expect(view).not.toMatch(/RBUpsells\.mount\([\s\S]*?add: RB\.add,/);
    });

    it.each([
        'data-rb-up="', 'data-rb-up-pick', 'data-rb-up-sizes', 'data-rb-up-size="', 'data-rb-up-need', 'data-needs-variant',
        'data-rb-up-qtybox', 'data-rb-up-inc', 'data-rb-up-dec', 'data-rb-up-count', 'data-rb-up-line', 'data-rb-up-total',
        "data-amount=\"{{ $u['price'] }}\"", "data-amount=\"{{ $s['price'] }}\"",
    ])(
        'فيه %s',
        (attr) => {
            expect(view).toContain(attr);
        },
    );
});

/*
 * ═══ كرتُ الهدية إضافةً ═══
 *
 * بطاقتُه (`data-gift-card`) تفتح خانةَ نصّه حين تُختار، والنصُّ يدخل مع بنده
 * وحده — الوسيطُ الرابع لـ`RB.add` كما في صفحة الكرت. ولا يدخل بلا نصّ.
 */
const GIFT = 14;

function giftPage(main: Main = { id: 1, variant_id: 5, qty: 2 }) {
    document.body.innerHTML = `
        <div data-rb-upsells>
            <div class="rb-up" data-rb-up="${BALLOON}" data-qty="0" data-amount="1">
                <button type="button" data-rb-up-pick aria-pressed="false">بالون</button>
                ${stepper()}
            </div>
            <div class="rb-up" data-rb-up="${GIFT}" data-qty="0" data-amount="1" data-gift-card>
                <button type="button" data-rb-up-pick aria-pressed="false">كرت هدية</button>
                ${stepper()}
                <div data-rb-up-note-box hidden>
                    <textarea data-rb-up-note></textarea>
                </div>
            </div>
        </div>
        <button type="button" data-rb-add>أضف إلى السلة</button>`;

    const box = document.querySelector('[data-rb-upsells]')!;
    const button = document.querySelector<HTMLButtonElement>('[data-rb-add]')!;
    const addAll = vi.fn(() => ({ ok: true }));
    const done = vi.fn();
    const missing = vi.fn();
    api().mount(box, { button, main: () => main, addAll, done, missing, wait: 0 });

    const card = (id: number) => box.querySelector<HTMLElement>(`[data-rb-up="${id}"]`)!;
    const text = () => card(GIFT).querySelector<HTMLTextAreaElement>('[data-rb-up-note]')!;

    return {
        addAll, done, missing, card, text,
        calls: () => flat(addAll),
        pick: (id: number) => card(id).querySelector<HTMLButtonElement>('[data-rb-up-pick]')!.click(),
        inc: (id: number) => card(id).querySelector<HTMLButtonElement>('[data-rb-up-inc]')!.click(),
        submit: () => button.click(),
        write: (v: string) => {
            text().value = v;
            text().dispatchEvent(new Event('input', { bubbles: true }));
        },
        noteBox: () => card(GIFT).querySelector<HTMLElement>('[data-rb-up-note-box]')!,
    };
}

describe('كرتُ الهدية في «أضف مع طلبك»', () => {
    it('خانةُ النصّ مطويّةٌ حتّى يُختار الكرت', () => {
        const p = giftPage();
        expect(p.noteBox().hidden).toBe(true);

        p.pick(GIFT);
        expect(p.noteBox().hidden).toBe(false);
    });

    it('يدخل بنصّه على بنده وحده — لا على الصنف ولا على إضافةٍ أخرى', () => {
        const p = giftPage();
        p.pick(BALLOON);
        p.pick(GIFT);
        p.write('  كل عام وأنت بخير  ');
        p.submit();

        expect(p.calls()).toEqual([
            [1, 5, 2],
            [BALLOON, null, 1],
            [GIFT, null, 1, 'كل عام وأنت بخير'],
        ]);
    });

    it('وبلا نصٍّ يدخل الكرتُ كما هو — الرسالةُ اختياريّة ولا تُعدّ نقصًا', () => {
        const p = giftPage();
        p.pick(GIFT);
        p.write('   ');
        p.submit();

        expect(p.missing).not.toHaveBeenCalled();
        expect(p.calls()).toEqual([
            [1, 5, 2],
            // بلا وسيطٍ رابع: لا `note` فارغٌ يُكتب في السلّة
            [GIFT, null, 1],
        ]);
        expect(p.done).toHaveBeenCalled();
    });

    it('والمقاسُ الناقص يمنع وحده — والكرتُ بلا رسالةٍ ليس نقصًا', () => {
        const r = api().compose({ id: 1, variant_id: null, qty: 1 }, [
            { id: BALLOON, needs: true, variant: null, gift: false, note: '' },
            { id: GIFT, needs: false, variant: null, gift: true, note: '' },
        ]);

        expect(r.ok).toBe(false);
        expect(r.missing.map((m: { id: number }) => m.id)).toEqual([BALLOON]);

        const ok = api().compose({ id: 1, variant_id: null, qty: 1 }, [
            { id: BALLOON, needs: true, variant: 9, gift: false, note: '' },
            { id: GIFT, needs: false, variant: null, gift: true, note: '' },
        ]);
        expect(ok.ok).toBe(true);
        expect(ok.lines).toEqual([
            { id: 1, variant_id: null, qty: 1 },
            { id: BALLOON, variant_id: 9, qty: 1 },
            { id: GIFT, variant_id: null, qty: 1 },
        ]);
    });

    it('ومن ترك الكرتَ تُطوى خانتُه ويُمحى نصُّه — ولا يدخل', () => {
        const p = giftPage();
        p.pick(GIFT);
        p.write('رسالة');
        p.submit();
        p.addAll.mockClear();

        // ما دخل السلّة تُرك اختيارُه: الخانةُ مطويّةٌ فارغة
        expect(p.noteBox().hidden).toBe(true);
        expect(p.text().value).toBe('');

        p.pick(GIFT);
        p.write('أخرى');
        p.pick(GIFT);
        expect(p.noteBox().hidden).toBe(true);
        expect(p.text().value).toBe('');
    });

    it('والبنودُ: النصُّ مفتاحٌ في بند الكرت وحده', () => {
        const r = api().compose({ id: 1, variant_id: null, qty: 1 }, [
            { id: BALLOON, needs: false, variant: null, gift: false, note: '' },
            { id: GIFT, needs: false, variant: null, gift: true, note: 'مبروك' },
        ]);

        expect(r.lines).toEqual([
            { id: 1, variant_id: null, qty: 1 },
            { id: BALLOON, variant_id: null, qty: 1 },
            { id: GIFT, variant_id: null, qty: 1, note: 'مبروك' },
        ]);
    });

    it('والقالبُ يرسم بطاقةَ الكرت بخانتها', () => {
        const view = readFileSync(VIEW, 'utf8');

        for (const attr of ['data-gift-card', 'data-rb-up-note-box hidden', 'data-rb-up-note ', "$t['giftCardMessage']"]) {
            expect(view).toContain(attr);
        }
        // ولا خطأَ «اكتب الرسالة» تحت الخانة — الرسالةُ اختياريّة
        expect(view).not.toContain('data-rb-up-note-err');
        expect(view).not.toContain('giftCardNeeded');
    });
});

/*
 * ═══ ولكلّ إضافةٍ عدّادُها ═══
 *
 * علبتا شوكولاتة وثلاثةُ بالونات وكرتٌ واحد — في ضغطةٍ واحدة، وكمّيّةُ الباقة
 * كما اختارها. وكان كلُّ بندٍ يدخل بواحدٍ ثابت.
 */
describe('كمّيّةٌ مستقلّةٌ لكلّ إضافة', () => {
    it('عدّةُ إضافاتٍ بكمّيّاتٍ مختلفة في ضغطةٍ واحدة — والصنفُ بكمّيّته', () => {
        const p = page({ id: 1, variant_id: 5, qty: 1 });
        p.pick(CHOC); p.size(SMALL); p.inc(CHOC);
        p.pick(BALLOON); p.inc(BALLOON, 2);
        p.pick(CARD);
        p.submit();

        expect(p.addAll).toHaveBeenCalledTimes(1);
        expect(p.calls()).toEqual([[1, 5, 1], [BALLOON, null, 3], [CHOC, SMALL, 2], [CARD, null, 1]]);
    });

    it('إضافةٌ واحدة ×٥', () => {
        const p = page({ id: 1, variant_id: 5, qty: 2 });
        p.pick(BALLOON); p.inc(BALLOON, 4);
        p.submit();
        expect(p.calls()).toEqual([[1, 5, 2], [BALLOON, null, 5]]);
    });

    it('الاختيارُ يبدأ بواحد، والعدّادُ يظهر معه ويُطوى بالترك', () => {
        const p = page();
        expect(p.qtyBox(BALLOON).hidden).toBe(true);
        expect(p.card(BALLOON).getAttribute('data-qty')).toBe('0');

        p.pick(BALLOON);
        expect(p.count(BALLOON)).toBe('1');
        expect(p.qtyBox(BALLOON).hidden).toBe(false);
        expect(p.card(BALLOON).querySelector('[data-rb-up-pick]')!.getAttribute('aria-pressed')).toBe('true');
    });

    it('و«−» عند الواحد يُعيدها صفرًا فلا تدخل', () => {
        const p = page();
        p.pick(BALLOON); p.inc(BALLOON);
        p.dec(BALLOON, 2);
        expect(p.card(BALLOON).classList.contains('on')).toBe(false);
        expect(p.qtyBox(BALLOON).hidden).toBe(true);

        p.submit();
        expect(p.calls()).toEqual([[1, 5, 3]]);
    });

    it('والعدّادُ يمسّ بطاقتَه وحدها — لا أخواتِها ولا كمّيّةَ الصنف', () => {
        const p = page({ id: 1, variant_id: 5, qty: 4 });
        p.pick(BALLOON); p.pick(CARD);
        p.inc(BALLOON, 2);
        expect(p.count(BALLOON)).toBe('3');
        expect(p.count(CARD)).toBe('1');

        p.submit();
        expect(p.calls()).toEqual([[1, 5, 4], [BALLOON, null, 3], [CARD, null, 1]]);
    });

    it('والمقاسُ لا يُنسي الكمّيّة — ولا الكمّيّةُ المقاس', () => {
        const p = page();
        p.pick(CHOC); p.inc(CHOC, 2);
        p.size(LARGE);
        p.size(SMALL);
        expect(p.count(CHOC)).toBe('3');
        p.inc(CHOC);
        expect(p.card(CHOC).getAttribute('data-variant')).toBe(String(SMALL));

        p.submit();
        expect(p.calls()).toEqual([[1, 5, 3], [CHOC, SMALL, 4]]);
    });

    it('ولا تتجاوز الكمّيّةُ حدَّ السلّة — و«+» يُقفل عنده', () => {
        const p = page();
        p.pick(BALLOON); p.inc(BALLOON, 60);
        expect(p.count(BALLOON)).toBe('50');
        expect(p.card(BALLOON).querySelector<HTMLButtonElement>('[data-rb-up-inc]')!.disabled).toBe(true);
    });

    it('وإضافةٌ ينقصها مقاسٌ تمنع الكلَّ ولو كانت كمّيّتُها ثلاثًا', () => {
        const p = page();
        p.pick(BALLOON); p.inc(BALLOON);
        p.pick(CHOC); p.inc(CHOC, 2);
        p.submit();
        expect(p.addAll).not.toHaveBeenCalled();
        expect(p.missing).toHaveBeenCalledWith([p.card(CHOC)]);
        // والكمّيّاتُ كما تركها
        expect(p.count(BALLOON)).toBe('2');
        expect(p.count(CHOC)).toBe('3');
    });

    it('وما دخل السلّة يعود صفرًا كلُّه', () => {
        vi.useFakeTimers();
        const p = page();
        p.pick(BALLOON); p.inc(BALLOON, 2);
        p.submit();
        expect(p.card(BALLOON).getAttribute('data-qty')).toBe('0');
        expect(p.count(BALLOON)).toBe('0');
    });
});

describe('كلُّها أو لا شيء', () => {
    it('سلّةٌ رُدّت لا يدخل منها شيء — ويبقى الاختيارُ ليُعدَّل، والزرُّ يعود', () => {
        const p = page({ id: 1, variant_id: 5, qty: 1 }, { ok: false, message: 'أقصى كمية 50' });
        p.pick(BALLOON); p.inc(BALLOON, 2);
        p.submit();

        expect(p.addAll).toHaveBeenCalledTimes(1);
        expect(p.done).not.toHaveBeenCalled();
        expect(p.refused).toHaveBeenCalledWith('أقصى كمية 50');
        expect(p.count(BALLOON)).toBe('3');
        expect(p.button.disabled).toBe(false);
    });
});

describe('المجموعُ في الصفحة — من أسعار الخادم المكتوبة', () => {
    it('سطرُ كلّ إضافةٍ كمّيّتُها في سعر وحدتها', () => {
        const p = page();
        p.pick(BALLOON); p.inc(BALLOON, 2);
        expect(p.line(BALLOON)).toBe('3 × 1.500 ر.ع = 4.500 ر.ع');
    });

    it('والمجموعُ الصنفُ بكمّيّته وما اختير معه', () => {
        const p = page({ id: 1, variant_id: 5, qty: 2 });
        expect(p.total.hidden).toBe(false);
        expect(p.totalText()).toBe('20.000 ر.ع');

        p.pick(BALLOON); p.inc(BALLOON);
        p.pick(CARD);
        expect(p.totalText()).toBe('23.500 ر.ع');
    });

    it('ويُطوى المجموعُ ما دام مقاسٌ لم يُختر — لا رقمٌ أقلُّ ممّا سيدفع', () => {
        const p = page({ id: 1, variant_id: 5, qty: 1 });
        p.pick(CHOC);
        expect(p.total.hidden).toBe(true);

        p.size(LARGE); p.inc(CHOC);
        expect(p.total.hidden).toBe(false);
        expect(p.totalText()).toBe('16.500 ر.ع');
        expect(p.line(CHOC)).toBe('2 × 3.250 ر.ع = 6.500 ر.ع');
    });

    it('وكمّيّةُ الصنف تُعيد رسمَه حين تتبدّل', () => {
        const main = { id: 1, variant_id: 5, qty: 1 };
        const p = page(main);
        main.qty = 3;
        p.mounted.repaint();
        expect(p.totalText()).toBe('30.000 ر.ع');
    });
});

/*
 * ═══ وكتابةُ المبلغ كما يكتبه `Money::format` ═══
 *
 * منازلُ العملة ما لم تُخفِ رقمًا (`Money::shown`)، والرمزُ قبله أو بعده.
 */
describe('RBUpsells.money', () => {
    it.each([
        [{ d: 3, symbol: 'ر.ع', before: false }, 12.5, '12.500 ر.ع'],
        [{ d: 2, symbol: 'د.إ', before: false }, 5.25, '5.25 د.إ'],
        // لا تُحذف منزلةٌ فيها رقم
        [{ d: 2, symbol: 'د.إ', before: false }, 12.894, '12.894 د.إ'],
        [{ d: 2, symbol: 'د.إ', before: false }, 12.89, '12.89 د.إ'],
        [{ d: 0, symbol: 'ر.س', before: false }, 7, '7 ر.س'],
        [{ d: 0, symbol: 'ر.س', before: false }, 7.5, '7.5 ر.س'],
        [{ d: 2, symbol: '$', before: true }, 1234.5, '$ 1,234.50'],
        [{ d: 4, symbol: 'ر.ع', before: false }, 1.25, '1.2500 ر.ع'],
        // والكسورُ العائمة لا تُخطئ الألف: 0.1 + 0.2
        [{ d: 3, symbol: 'ر.ع', before: false }, 0.1 + 0.2, '0.300 ر.ع'],
    ])('%o · %s ← %s', (spec, value, out) => {
        expect(api().money(spec)(value)).toBe(out);
    });
});
