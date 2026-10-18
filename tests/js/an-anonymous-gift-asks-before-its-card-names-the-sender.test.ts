import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * «لا تذكر اسمي» ورسالةُ البطاقة في إتمام RIBBON — السؤالُ والإقرار في الصفحة.
 *
 * ═══ ما يُحرس ═══
 *
 *   - لا إقرارَ قبل السؤال، ولا يُرسَل شيءٌ من تلقاء الصفحة.
 *   - «إرسال الرسالة كما هي» يحفظ رمزَ الخادم ويُعيد الإرسال.
 *   - والرمزُ يُنسى متى تبدّل الاسمُ أو الهاتفُ أو المستلِمُ أو الرسالةُ أو
 *     خيارُ الإخفاء أو الهديّة.
 *   - «تعديل الرسالة» لا يُرسل: يُعيد المؤشّرَ إلى خانة البطاقة، أو يفتح رسائلَ
 *     الكروت في السلّة لتُعدَّل، وتُحفظ في السلّة نفسِها — ولا تُمسّ رسالةٌ لم
 *     يعدّلها صاحبُها.
 *   - وتذكيرُ ملفّ الكرت لمن أخفى اسمَه وأرفق ملفًّا وحده.
 *
 * والمكتبةُ تُضمَّن في `store/ribbon/checkout.blade.php` كما هي، وما يحرسه
 * الخادمُ في `AnAnonymousGiftAsksBeforeItsCardNamesTheSenderTest`.
 */

type Item = { id: number; variant_id?: number | null; qty: number; note?: string };
type Line = { id: number; variant_id: number | null; qty: number; note: string | null; gift_card: boolean };
type Consent = {
    ack: () => string | null;
    warn: (offer: { token: string; source: string }) => void;
    lines: (l: Line[]) => void;
};
type Api = {
    mount: (
        form: HTMLFormElement,
        box: HTMLElement,
        cart: { read: () => Item[]; write: (i: Item[]) => void },
        opts: { onSend?: () => void; onSaved?: () => void; max?: number },
    ) => Consent;
    rewrite: (items: Item[], id: number, variantId: number | null, from: string, to: string) => Item[];
    fileReminder: (s: { gift: boolean; hidden: boolean; card: boolean; way: string }) => boolean;
};

const LIB = resolve(__dirname, '../../resources/js/store/ribbon-card-consent.js');
const VIEW = resolve(__dirname, '../../resources/views/store/ribbon/checkout.blade.php');

beforeAll(() => {
    new Function(readFileSync(LIB, 'utf8'))();
});

const api = () => (window as unknown as { RBCardConsent: Api }).RBCardConsent;

function page(items: Item[] = []) {
    document.body.innerHTML = `
        <form>
            <input name="name" value="Ahmed"><input name="phone" value="99110001">
            <input name="recipient_name" value="Maryam">
            <input type="checkbox" data-rb-gift checked>
            <input type="checkbox" data-rb-hide-sender checked>
            <textarea name="card">مع حبّي، أحمد</textarea>
            <input name="area" value="Khuwair">
            <div data-rb-consent-box hidden>
                <div data-rb-cardlines hidden></div>
                <button type="button" data-rb-consent-save hidden></button>
                <button type="button" data-rb-consent-edit></button>
                <button type="button" data-rb-consent-send></button>
            </div>
        </form>`;

    let stored = items.map((i) => ({ ...i }));
    const cart = { read: () => stored.map((i) => ({ ...i })), write: (i: Item[]) => { stored = i; } };
    const sent = vi.fn();
    const saved = vi.fn();
    const form = document.querySelector('form')!;
    const box = form.querySelector<HTMLElement>('[data-rb-consent-box]')!;
    const consent = api().mount(form, box, cart, { onSend: sent, onSaved: saved, max: 500 });
    const $ = <T extends Element>(s: string) => form.querySelector<T>(s)!;
    const click = (s: string) => $<HTMLButtonElement>(s).click();
    const type = (s: string, v: string) => {
        const el = $<HTMLInputElement>(s);
        el.value = v;
        el.dispatchEvent(new Event('input', { bubbles: true }));
    };

    return { form, box, consent, sent, saved, $, click, type, stored: () => stored };
}

describe('السؤالُ والإقرار', () => {
    it('لا إقرارَ قبل السؤال، والصندوقُ مطويّ', () => {
        const { consent, box, sent } = page();

        expect(consent.ack()).toBeNull();
        expect(box.hidden).toBe(true);
        expect(sent).not.toHaveBeenCalled();
    });

    it('«إرسال الرسالة كما هي» يحفظ رمزَ الخادم ويُعيد الإرسال — والرسالةُ كما هي', () => {
        const { consent, box, sent, click, $ } = page();

        consent.warn({ token: 'tok-1', source: 'card' });
        expect(box.hidden).toBe(false);
        expect(consent.ack()).toBeNull();

        click('[data-rb-consent-send]');

        expect(consent.ack()).toBe('tok-1');
        expect(box.hidden).toBe(true);
        expect(sent).toHaveBeenCalledTimes(1);
        expect($<HTMLTextAreaElement>('[name=card]').value).toBe('مع حبّي، أحمد');
    });

    it.each([
        ['[name=name]', 'Ahmed Salem'],
        ['[name=phone]', '99110002'],
        ['[name=recipient_name]', 'Maryam B'],
        ['[name=card]', 'مع حبّي'],
    ])('وتبدُّلُ %s بعد الإقرار يُنسيه', (field, value) => {
        const { consent, click, type } = page();
        consent.warn({ token: 'tok-1', source: 'card' });
        click('[data-rb-consent-send]');

        type(field, value);

        expect(consent.ack()).toBeNull();
    });

    it.each([['[data-rb-hide-sender]'], ['[data-rb-gift]']])('وتبديلُ %s بعد الإقرار يُنسيه', (box) => {
        const { consent, click, $ } = page();
        consent.warn({ token: 'tok-1', source: 'card' });
        click('[data-rb-consent-send]');

        $<HTMLInputElement>(box).click();

        expect(consent.ack()).toBeNull();
    });

    it('وحقلٌ لا شأنَ له بالهويّة ولا بالرسالة لا يُنسيه', () => {
        const { consent, click, type } = page();
        consent.warn({ token: 'tok-1', source: 'card' });
        click('[data-rb-consent-send]');

        type('[name=area]', 'Azaiba');

        expect(consent.ack()).toBe('tok-1');
    });
});

describe('«تعديل الرسالة»', () => {
    it('يُعيد المؤشّرَ إلى خانة البطاقة ولا يُرسل شيئًا', () => {
        const { consent, box, sent, click, $ } = page();
        consent.warn({ token: 'tok-1', source: 'card' });

        click('[data-rb-consent-edit]');

        expect(box.hidden).toBe(true);
        expect(document.activeElement).toBe($('[name=card]'));
        expect(sent).not.toHaveBeenCalled();
        expect(consent.ack()).toBeNull();
        expect($<HTMLTextAreaElement>('[name=card]').value).toBe('مع حبّي، أحمد');
    });

    it('وكروتُ السلّة تُفتح هنا وتُحفظ في السلّة — والسلّةُ وما سواها كما هما', () => {
        const items: Item[] = [
            { id: 1, variant_id: null, qty: 1 },
            { id: 9, variant_id: null, qty: 2, note: 'From Ahmed' },
            { id: 9, variant_id: null, qty: 1, note: 'Happy birthday' },
        ];
        const { consent, box, sent, saved, click, $, stored } = page(items);
        consent.lines([
            { id: 1, variant_id: null, qty: 1, note: null, gift_card: false },
            { id: 9, variant_id: null, qty: 2, note: 'From Ahmed', gift_card: true },
            { id: 9, variant_id: null, qty: 1, note: 'Happy birthday', gift_card: true },
        ]);
        consent.warn({ token: 'tok-2', source: 'items' });

        click('[data-rb-consent-edit]');

        const editors = box.querySelectorAll<HTMLTextAreaElement>('[data-rb-cardline]');
        expect(box.hidden).toBe(false);
        expect(editors).toHaveLength(2);
        expect(editors[0].value).toBe('From Ahmed');
        expect(editors[0].maxLength).toBe(500);
        expect(document.activeElement).toBe(editors[0]);
        expect($<HTMLButtonElement>('[data-rb-consent-save]').hidden).toBe(false);

        editors[0].value = 'With love';
        click('[data-rb-consent-save]');

        expect(stored()).toEqual([
            { id: 1, variant_id: null, qty: 1 },
            { id: 9, variant_id: null, qty: 2, note: 'With love' },
            { id: 9, variant_id: null, qty: 1, note: 'Happy birthday' },
        ]);
        expect(saved).toHaveBeenCalledTimes(1);
        expect(sent).not.toHaveBeenCalled();
        expect(box.hidden).toBe(true);
    });

    it('وكرتان صار نصُّهما واحدًا بندٌ واحد — كما تجمعهما السلّة', () => {
        expect(api().rewrite(
            [
                { id: 9, variant_id: null, qty: 2, note: 'From Ahmed' },
                { id: 9, variant_id: null, qty: 1, note: 'With love' },
            ],
            9, null, 'From Ahmed', 'With love',
        )).toEqual([{ id: 9, variant_id: null, qty: 3, note: 'With love' }]);

        // وتُفرَّغ الرسالةُ كرتًا بلا رسالة
        expect(api().rewrite([{ id: 9, variant_id: null, qty: 1, note: 'From Ahmed' }], 9, null, 'From Ahmed', ''))
            .toEqual([{ id: 9, variant_id: null, qty: 1 }]);
    });
});

describe('تذكيرُ ملفّ الكرت', () => {
    it('لمن أخفى اسمَه في هديّةٍ وأرفق ملفًّا لكرته — وحده', () => {
        const on = { gift: true, hidden: true, card: true, way: 'file' };

        expect(api().fileReminder(on)).toBe(true);
        expect(api().fileReminder({ ...on, gift: false })).toBe(false);
        expect(api().fileReminder({ ...on, hidden: false })).toBe(false);
        expect(api().fileReminder({ ...on, card: false })).toBe(false);
        expect(api().fileReminder({ ...on, way: 'text' })).toBe(false);
    });
});

describe('الصفحة', () => {
    const view = readFileSync(VIEW, 'utf8');

    it('تضمّ المكتبةَ وتُرسل الإقرارَ وتسأل حين يقول الخادم — لمن فُتح له الطرحُ وحده', () => {
        expect(view).toContain("@if ($cardConsent['on'] ?? false)\n<script>{!! file_get_contents(resource_path('js/store/ribbon-card-consent.js')) !!}</script>");
        expect(view).toContain('if (consent) payload.card_identity_ack = consent.ack();');
        expect(view).toContain('if (r && r.card_identity && consent) { consent.warn(r.card_identity); return; }');
    });
});
