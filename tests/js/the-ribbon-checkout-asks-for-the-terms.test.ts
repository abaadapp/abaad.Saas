import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * الشروطُ والأحكام قبل تأكيد الطلب في RIBBON — خانةٌ يضغطها الزبونُ بنفسه.
 *
 * ═══ ما يُحرس ═══
 *
 *   - الخانةُ غيرُ محدّدة، ولا يُرسَل الطلبُ بلا موافقة ويُقال لماذا.
 *   - الموافقةُ تُرسَل ومعها رقمُ النسخة التي رآها.
 *   - شروطٌ حدّثها صاحبُها تحلّ محلَّ القديمة، وتُفرغ الخانة، وتحمل رقمَها.
 *   - والنصُّ يُبسط كلُّه ويُطوى.
 *
 * والمكتبةُ تُضمَّن في `store/ribbon/checkout.blade.php` كما هي، وما يحرسه
 * الخادمُ في `AnOrderCarriesTheTermsItsBuyerAcceptedTest`.
 */

type Api = {
    mount: (box: HTMLElement, T: Record<string, string>) => {
        payload: () => { terms_version: string; terms_accept: boolean };
        ready: () => boolean;
        replace: (terms: { id: number; version: number; text: string }, message?: string) => void;
    };
};

const LIB = resolve(__dirname, '../../resources/js/store/ribbon-terms.js');
const T = { termsMore: 'عرض الشروط كاملة', termsLess: 'طيّ الشروط', termsNeeded: 'يجب الموافقة على الشروط والأحكام قبل إتمام الطلب.' };

beforeAll(() => {
    new Function(readFileSync(LIB, 'utf8'))();
});

function page() {
    document.body.innerHTML = `
        <div data-rb-terms data-version="7">
            <div data-rb-terms-text style="max-height:180px">النصُّ القديم</div>
            <button type="button" data-rb-terms-toggle aria-expanded="false">عرض الشروط كاملة</button>
            <input type="checkbox" data-rb-terms-accept>
            <div data-err="terms"></div>
        </div>`;

    const box = document.querySelector<HTMLElement>('[data-rb-terms]')!;
    const api = (window as unknown as { RBTerms: Api }).RBTerms.mount(box, T);
    const check = box.querySelector<HTMLInputElement>('[data-rb-terms-accept]')!;
    const tick = () => {
        check.checked = true;
        check.dispatchEvent(new Event('change'));
    };

    return { box, api, check, tick };
}

describe('الشروطُ والأحكام في إتمام RIBBON', () => {
    it('لا يمضي الطلبُ بلا موافقة، ويقول لماذا', () => {
        const { box, api, check } = page();

        expect(check.checked).toBe(false);
        expect(api.ready()).toBe(false);
        expect(box.querySelector('[data-err="terms"]')!.textContent).toBe(T.termsNeeded);
        expect(api.payload()).toEqual({ terms_version: '7', terms_accept: false });
    });

    it('يُرسل الموافقةَ برقم النسخة التي رآها', () => {
        const { box, api, tick } = page();

        api.ready();
        tick();

        expect(api.ready()).toBe(true);
        expect(box.querySelector('[data-err="terms"]')!.textContent).toBe('');
        expect(api.payload()).toEqual({ terms_version: '7', terms_accept: true });
    });

    it('شروطٌ حُدّثت تحلّ محلَّ القديمة وتُفرغ الموافقة', () => {
        const { box, api, check, tick } = page();
        tick();

        api.replace({ id: 9, version: 3, text: 'النصُّ الجديد' }, 'تحدّثت الشروط والأحكام — اقرأها ووافق عليها من جديد.');

        expect(box.querySelector('[data-rb-terms-text]')!.textContent).toBe('النصُّ الجديد');
        expect(check.checked).toBe(false);
        expect(api.payload()).toEqual({ terms_version: '9', terms_accept: false });
        expect(box.querySelector('[data-err="terms"]')!.textContent).toBe('تحدّثت الشروط والأحكام — اقرأها ووافق عليها من جديد.');
    });

    it('يبسط النصَّ كلَّه ويطويه', () => {
        const { box } = page();
        const toggle = box.querySelector<HTMLButtonElement>('[data-rb-terms-toggle]')!;
        const text = box.querySelector<HTMLElement>('[data-rb-terms-text]')!;

        toggle.click();
        expect(text.style.maxHeight).toBe('none');
        expect(toggle.textContent).toBe(T.termsLess);
        expect(toggle.getAttribute('aria-expanded')).toBe('true');

        toggle.click();
        expect(text.style.maxHeight).toBe('');
        expect(toggle.textContent).toBe(T.termsMore);
    });
});
