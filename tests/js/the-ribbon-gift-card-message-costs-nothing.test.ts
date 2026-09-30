import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * رسالةُ كرت الهدية بلا ثمن في إتمام RIBBON — خانةٌ تفتح نصًّا ولا تحرّك مالًا.
 *
 * ═══ ما يُحرس ═══
 *
 *   - خانةُ الكتابة مطويّةٌ حتّى تُختار الخانة، وتُطوى بطيّها ويُمحى ما كُتب.
 *   - والنصُّ يُرسَل في `card` والخانةُ مختارة — وإلّا فلا نصّ.
 *   - ولا تُعيد التسعير (`RB.quote`) ولا تمسّ علامةَ الكرت المدفوع `gift`.
 *
 * والمكتبةُ تُضمَّن في `store/ribbon/checkout.blade.php` كما هي، فيُقرأ
 * القالبُ هنا ليُعرف أنّها موصولة. وما يرسمه الخادمُ ويُحسب في
 * `ASaudsGiftCardMessageIsFreeTest`.
 */

type Api = {
    mount: (toggle: HTMLInputElement, box: HTMLElement, text: HTMLTextAreaElement, preview: HTMLElement | null) => { card: () => string };
};

const LIB = resolve(__dirname, '../../resources/js/store/ribbon-card-message.js');
const VIEW = resolve(__dirname, '../../resources/views/store/ribbon/checkout.blade.php');

const api = () => (window as unknown as { RBCardMessage: Api }).RBCardMessage;

beforeAll(() => {
    // كما تُضمَّن في الصفحة: نصٌّ داخل `<script>` لا وحدةٌ تُستورد
    new Function(readFileSync(LIB, 'utf8'))();
});

function page() {
    const quote = vi.fn();
    (window as unknown as { RB: unknown }).RB = { quote };

    document.body.innerHTML = `
        <label><input type="checkbox" data-rb-cardmsg><span>أضف رسالة على كرت الهدية</span></label>
        <div data-rb-cardmsgbox hidden>
            <textarea name="card" maxlength="500"></textarea>
            <div data-rb-cardmsgpreview></div>
        </div>`;

    const toggle = document.querySelector<HTMLInputElement>('[data-rb-cardmsg]')!;
    const box = document.querySelector<HTMLElement>('[data-rb-cardmsgbox]')!;
    const text = document.querySelector<HTMLTextAreaElement>('[name=card]')!;
    const preview = document.querySelector<HTMLElement>('[data-rb-cardmsgpreview]')!;
    const msg = api().mount(toggle, box, text, preview);

    const check = (on: boolean) => {
        toggle.checked = on;
        toggle.dispatchEvent(new Event('change'));
    };
    const type = (v: string) => {
        text.value = v;
        text.dispatchEvent(new Event('input'));
    };

    return { quote, toggle, box, text, preview, msg, check, type };
}

describe('الخانة', () => {
    it('خانةُ الكتابة مطويّةٌ حتّى تُختار، وتُفتح باختيارها', () => {
        const p = page();
        expect(p.box.hidden).toBe(true);

        p.check(true);
        expect(p.box.hidden).toBe(false);
    });

    it('وما يُكتب يُرى في المعاينة ويُرسَل في `card`', () => {
        const p = page();
        p.check(true);
        p.type('كل عام وأنت بخير');

        expect(p.preview.textContent).toBe('كل عام وأنت بخير');
        expect(p.msg.card()).toBe('كل عام وأنت بخير');
    });

    it('وطيُّها يطوي الخانة ويمحو ما كُتب — فلا يُرسَل نصٌّ خفيّ', () => {
        const p = page();
        p.check(true);
        p.type('نصٌّ تراجع عنه');
        p.check(false);

        expect(p.box.hidden).toBe(true);
        expect(p.text.value).toBe('');
        expect(p.preview.textContent).toBe('');
        expect(p.msg.card()).toBe('');
    });

    it('ونصٌّ في خانةٍ غيرِ مختارة لا يُرسَل', () => {
        const p = page();
        p.text.value = 'نصٌّ بقي من تعبئةٍ تلقائيّة';

        expect(p.msg.card()).toBe('');
    });

    it('ولا تُعيد التسعير — فالإجماليُّ لا يتبدّل بها', () => {
        const p = page();
        p.check(true);
        p.type('مبروك');
        p.check(false);

        expect(p.quote).not.toHaveBeenCalled();
    });
});

describe('القالبُ يضمّنها ويرسل نصَّها وحده', () => {
    const view = readFileSync(VIEW, 'utf8');
    const branch = view.slice(view.indexOf("@elseif ($giftCard['message_only'])"), view.indexOf('@else\n', view.indexOf("@elseif ($giftCard['message_only'])")));

    it('يضمّن المكتبةَ من ملفّها لمن رسالتُه مجّانيّة', () => {
        expect(view).toMatch(/@if \(\$giftCard\['message_only'\]\)\s*<script>\{!! file_get_contents\(resource_path\('js\/store\/ribbon-card-message\.js'\)\) !!\}<\/script>/);
        expect(view).toContain('RBCardMessage.mount(msgToggle');
    });

    it('والفرعُ خانةٌ ونصٌّ — لا ثمنَ ولا ملفَّ ولا علامةَ كرتٍ مدفوع', () => {
        expect(branch).toContain('data-rb-cardmsg');
        expect(branch).toContain('name="card"');
        expect(branch).not.toContain('price_text');
        expect(branch).not.toContain('data-rb-cardprice');
        expect(branch).not.toContain('type="file"');
        expect(branch).not.toContain('data-rb-giftcard');
        expect(branch).not.toContain('cardWayFile');
    });

    it('والحمولةُ تأخذ `card` منها، و`gift_card` لا يرفعه إلّا الكرتُ المدفوع', () => {
        expect(view).toContain("card: cardMsg ? cardMsg.card() :");
        // `gift` لا تُكتب إلّا من خانة الكرت المدفوع
        expect(view.match(/\bgift = [^f]/g)).toEqual(['gift = g']);
        expect(view).toContain('gift = giftToggle.checked');
    });
});
