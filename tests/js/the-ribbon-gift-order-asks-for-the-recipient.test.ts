import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * «هذا الطلب هدية» في إتمام RIBBON — ما تفتحه الخانة وما يُرسَل معها.
 *
 * ═══ ما يُحرس ═══
 *
 *   - الخانةُ تفتح المستلِمَ والمناسبةَ وإخفاءَ الاسم، وطيُّها يمحو المستلِم
 *     المكتوب ولا يُرسل غير `is_gift: false`.
 *   - «أخرى» وحدها تفتح نصَّ المناسبة.
 *   - ولا طريقةَ موقعٍ تُختار ولا يُخفى العنوان: الهديّةُ تُوصَّل بقسم التوصيل
 *     نفسِه، وسطرٌ يقول ذلك للتوصيل وحده — ولا `recipient_location` يُرسَل.
 *
 * والمكتبةُ تُضمَّن في `store/ribbon/checkout.blade.php` كما هي، وما يحرسه
 * الخادمُ في `AnOrderMayBeAGiftForSomeoneElseTest`.
 */

type Api = {
    mount: (form: HTMLFormElement, toggle: HTMLInputElement) => {
        fulfil: (fulfil: string) => void;
        onChange: (fn: () => void) => void;
        payload: () => Record<string, unknown>;
    };
};

const LIB = resolve(__dirname, '../../resources/js/store/ribbon-gift-order.js');
const VIEW = resolve(__dirname, '../../resources/views/store/ribbon/checkout.blade.php');

beforeAll(() => {
    new Function(readFileSync(LIB, 'utf8'))();
});

function page() {
    document.body.innerHTML = `
        <form>
            <label><input type="checkbox" data-rb-gift></label>
            <div data-rb-giftbox hidden>
                <input name="recipient_name"><input name="recipient_phone">
                <select name="occasion" data-rb-occasion>
                    <option value=""></option><option value="birthday"></option><option value="other"></option>
                </select>
                <div data-rb-occasion-other hidden><input name="occasion_text"></div>
                <input type="checkbox" name="hide_sender" data-rb-hide-sender>
                <p data-rb-gift-address-hint></p>
            </div>
        </form>`;

    const form = document.querySelector('form')!;
    const toggle = form.querySelector<HTMLInputElement>('[data-rb-gift]')!;
    const gift = (window as unknown as { RBGiftOrder: Api }).RBGiftOrder.mount(form, toggle);
    const $ = <T extends Element>(s: string) => form.querySelector<T>(s)!;
    const check = (on: boolean) => {
        toggle.checked = on;
        toggle.dispatchEvent(new Event('change'));
    };

    return { form, toggle, gift, $, check };
}

it('مطويّةٌ حتّى تُختار، ولا يُرسَل من طلبٍ عاديّ إلّا is_gift: false', () => {
    const { gift, $ } = page();

    expect($<HTMLElement>('[data-rb-giftbox]').hidden).toBe(true);
    expect(gift.payload()).toEqual({ is_gift: false });
});

it('تفتح الهديّةَ وتُرسل ما فيها — وطيُّها يمحو المستلِم', () => {
    const { gift, $, check } = page();
    check(true);

    expect($<HTMLElement>('[data-rb-giftbox]').hidden).toBe(false);
    $<HTMLInputElement>('[name=recipient_name]').value = 'Sara';
    $<HTMLSelectElement>('[data-rb-occasion]').value = 'birthday';
    $<HTMLInputElement>('[data-rb-hide-sender]').checked = true;

    // ولا طريقةَ موقع: العنوانُ من قسم التوصيل
    expect(gift.payload()).toEqual({
        is_gift: true, occasion: 'birthday', occasion_text: '', hide_sender: true,
    });

    check(false);
    expect($<HTMLInputElement>('[name=recipient_name]').value).toBe('');
    expect(gift.payload()).toEqual({ is_gift: false });
});

it('«أخرى» وحدها تفتح نصَّ المناسبة، والمناسبةُ الأخرى تمحوه', () => {
    const { gift, $, check } = page();
    check(true);
    const occasion = $<HTMLSelectElement>('[data-rb-occasion]');

    occasion.value = 'other';
    occasion.dispatchEvent(new Event('change'));
    expect($<HTMLElement>('[data-rb-occasion-other]').hidden).toBe(false);
    $<HTMLInputElement>('[name=occasion_text]').value = 'Store opening';
    expect(gift.payload()).toMatchObject({ occasion: 'other', occasion_text: 'Store opening' });

    occasion.value = 'birthday';
    occasion.dispatchEvent(new Event('change'));
    expect($<HTMLElement>('[data-rb-occasion-other]').hidden).toBe(true);
    expect(gift.payload()).toMatchObject({ occasion: 'birthday', occasion_text: '' });
});

it('لا طريقةَ موقعٍ ولا عنوانَ يُخفى — وسطرُ العنوان للتوصيل وحده', () => {
    const { gift, $, check } = page();
    const changed = vi.fn();
    gift.onChange(changed);
    check(true);

    expect(changed).toHaveBeenCalled();
    expect('contacts' in gift).toBe(false);
    expect(gift.payload()).not.toHaveProperty('recipient_location');
    expect($<HTMLElement>('[data-rb-gift-address-hint]').hidden).toBe(false);

    gift.fulfil('pickup');
    expect($<HTMLElement>('[data-rb-gift-address-hint]').hidden).toBe(true);
    expect(gift.payload()).not.toHaveProperty('recipient_location');

    gift.fulfil('delivery');
    expect($<HTMLElement>('[data-rb-gift-address-hint]').hidden).toBe(false);
});

it('موصولةٌ في صفحة الإتمام لمن فُتح له الإهداء، والعنوانُ لا يُخفى بها', () => {
    const view = readFileSync(VIEW, 'utf8');

    expect(view).toContain("resource_path('js/store/ribbon-gift-order.js')");
    expect(view).toContain("@if ($giftOrder['on'] ?? false)");
    expect(view).not.toContain('giftOrder.contacts');
    expect(view).not.toContain('recipient_location');
    expect(view).toContain("if (d) d.style.display = fulfil === 'delivery' ? 'grid' : 'none';");
    expect(view).toContain('if (giftOrder) Object.assign(payload, giftOrder.payload());');
});
