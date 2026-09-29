import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * «أضف مع طلبك» — ضغطةٌ واحدةٌ تُدخل الصنفَ وما اختير معه.
 *
 * ═══ ما يُحرس ═══
 *
 *   - الصنفُ بمقاسه وكمّيّته، وكلُّ إضافةٍ مختارةٍ بندًا بكمّيّة ١ — لا تُضرب
 *     في كمّيّة الصنف. وما لم يُختر لا يدخل.
 *   - وإضافةٌ لها مقاساتٌ لا يُختار لها مقاس: إن لم يختر الزبون لا يدخل شيء،
 *     ولا الصنفُ نفسُه، ويُقال له عند البطاقة.
 *   - والبنودُ بصيغة السلّة `{id, variant_id, qty}` عبر `RB.add` نفسِها.
 *   - وضغطتان متتاليتان تُضيفان مرّةً واحدة.
 *
 * والمكتبةُ تُضمَّن في `store/ribbon/product.blade.php` كما هي، فيُقرأ القالبُ
 * هنا ليُعرف أنّ ما تبحث عنه مرسومٌ فيه. وما يرسمه الخادمُ لكلّ متجر في
 * `TheRibbonProductPageOffersAddOnsBeforeTheCartTest`.
 */

type Line = { id: number; variant_id: number | null; qty: number };
type Pick = { id: number; needs: boolean; variant: number | null };
type Main = { id: number; variant_id: number | null; qty: number };

type Api = {
    mount: (box: Element, opts: {
        button: HTMLButtonElement;
        main: () => Main;
        add: (id: number, variantId: number | null, qty: number) => void;
        done: () => void;
        missing: (cards: HTMLElement[]) => void;
        wait?: number;
    }) => void;
    compose: (main: Main, picks: Pick[]) => { ok: boolean; lines: Line[]; missing: Pick[] };
};

const LIB = resolve(__dirname, '../../resources/js/store/ribbon-upsells.js');
const VIEW = resolve(__dirname, '../../resources/views/store/ribbon/product.blade.php');

const api = () => (window as unknown as { RBUpsells: Api }).RBUpsells;

beforeAll(() => {
    // كما تُضمَّن في الصفحة: نصٌّ داخل `<script>` لا وحدةٌ تُستورد
    new Function(readFileSync(LIB, 'utf8'))();
});

const BALLOON = 11, CHOC = 12, CARD = 13;
const SMALL = 121, LARGE = 122;

function page(main: Main = { id: 1, variant_id: 5, qty: 3 }) {
    document.body.innerHTML = `
        <div data-rb-upsells>
            <div class="rb-up" data-rb-up="${BALLOON}">
                <button type="button" data-rb-up-pick aria-pressed="false">بالون</button>
            </div>
            <div class="rb-up" data-rb-up="${CHOC}" data-needs-variant>
                <button type="button" data-rb-up-pick aria-pressed="false">شوكولاتة</button>
                <div data-rb-up-sizes hidden>
                    <button type="button" data-rb-up-size="${SMALL}" aria-pressed="false">صغيرة</button>
                    <button type="button" data-rb-up-size="${LARGE}" aria-pressed="false">كبيرة</button>
                </div>
                <p data-rb-up-need hidden>اختر خيارًا</p>
            </div>
            <div class="rb-up" data-rb-up="${CARD}">
                <button type="button" data-rb-up-pick aria-pressed="false">كرت</button>
            </div>
        </div>
        <button type="button" data-rb-add>أضف إلى السلة</button>`;

    const box = document.querySelector('[data-rb-upsells]')!;
    const button = document.querySelector<HTMLButtonElement>('[data-rb-add]')!;
    const add = vi.fn();
    const done = vi.fn();
    const missing = vi.fn();
    api().mount(box, { button, main: () => main, add, done, missing, wait: 1500 });

    const card = (id: number) => box.querySelector<HTMLElement>(`[data-rb-up="${id}"]`)!;

    return {
        add, done, missing, button, card,
        pick: (id: number) => card(id).querySelector<HTMLButtonElement>('[data-rb-up-pick]')!.click(),
        size: (id: number) => box.querySelector<HTMLButtonElement>(`[data-rb-up-size="${id}"]`)!.click(),
        submit: () => button.click(),
        need: () => card(CHOC).querySelector<HTMLElement>('[data-rb-up-need]')!,
        sizes: () => card(CHOC).querySelector<HTMLElement>('[data-rb-up-sizes]')!,
    };
}

afterEach(() => {
    vi.useRealTimers();
});

describe('ما يدخل السلّة', () => {
    it('بلا إضافاتٍ مختارة: الصنفُ وحده بمقاسه وكمّيّته', () => {
        const p = page();
        p.submit();
        expect(p.add.mock.calls).toEqual([[1, 5, 3]]);
        expect(p.done).toHaveBeenCalledTimes(1);
    });

    it('الإضافةُ البسيطة تدخل بكمّيّة ١ لا بكمّيّة الصنف', () => {
        const p = page();
        p.pick(BALLOON);
        p.submit();
        expect(p.add.mock.calls).toEqual([[1, 5, 3], [BALLOON, null, 1]]);
    });

    it('وذاتُ المقاس تدخل بالمقاس الذي اختاره الزبون', () => {
        const p = page();
        p.pick(CHOC);
        p.size(LARGE);
        p.submit();
        expect(p.add.mock.calls).toEqual([[1, 5, 3], [CHOC, LARGE, 1]]);
    });

    it('وما لم يُختر لا يدخل — ولا ما اختير ثمّ تُرك', () => {
        const p = page();
        p.pick(BALLOON);
        p.pick(CARD);
        p.pick(CARD);
        p.submit();
        expect(p.add.mock.calls.map((c) => c[0])).toEqual([1, BALLOON]);
    });

    it('والصنفُ بلا مقاس يدخل بلا مقاس', () => {
        const p = page({ id: 7, variant_id: null, qty: 1 });
        p.pick(BALLOON);
        p.submit();
        expect(p.add.mock.calls).toEqual([[7, null, 1], [BALLOON, null, 1]]);
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
        expect(p.add).not.toHaveBeenCalled();
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
        expect(p.add.mock.calls).toEqual([[1, 5, 3], [BALLOON, null, 1], [CHOC, SMALL, 1]]);
    });

    it('ومن ترك الإضافةَ ثمّ عاد إليها يختار المقاسَ من جديد', () => {
        const p = page();
        p.pick(CHOC);
        p.size(LARGE);
        p.pick(CHOC);
        p.pick(CHOC);
        p.submit();
        expect(p.add).not.toHaveBeenCalled();
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
        expect(p.add).toHaveBeenCalledTimes(2);
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
        p.add.mockClear();
        p.submit();
        expect(p.add.mock.calls).toEqual([[1, 5, 3]]);
    });
});

describe('القالبُ يرسم ما تبحث عنه المكتبة', () => {
    const view = readFileSync(VIEW, 'utf8');

    it('يضمّنها من ملفّها ويربطها بزرّ السلّة و`RB.add`', () => {
        expect(view).toContain("resource_path('js/store/ribbon-upsells.js')");
        expect(view).toMatch(/RBUpsells\.mount\([\s\S]*?add: RB\.add/);
    });

    it.each(['data-rb-up="', 'data-rb-up-pick', 'data-rb-up-sizes', 'data-rb-up-size="', 'data-rb-up-need', 'data-needs-variant'])(
        'فيه %s',
        (attr) => {
            expect(view).toContain(attr);
        },
    );
});
