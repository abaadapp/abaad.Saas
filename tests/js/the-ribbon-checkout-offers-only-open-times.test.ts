import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/**
 * يومُ التسليم وفترتُه في إتمام RIBBON — من جدول المتجر، المتاحُ وحدَه.
 *
 * ═══ ما يُحرس ═══
 *
 *   - القائمةُ أيّامُ الطريقة المختارة وحدَها، وتبديلُ الطريقة يبدّلها.
 *   - اليومُ يفتح فتراتِه وحدَها، وفترةٌ واحدةٌ في يومٍ تُختار وحدَها.
 *   - ما لم يعد في القائمة لا يبقى مختارًا، ولا يُرسَل.
 *   - الموعدُ يُكتب في الملخّص، وطريقةٌ بلا أيّامٍ يُقال لها ذلك — وللتوصيل
 *     بكلمات صاحب المتجر إن كتبها، والاستلامُ يبقى يُختار.
 *   - فترةٌ ردّها الخادمُ تُطرح من يومها وطريقتها وحدهما، ويومُها إن خلا —
 *     والمعرّفُ نفسُه في يومٍ آخر أو طريقةٍ أخرى باقٍ.
 *
 * والمكتبةُ تُضمَّن في `store/ribbon/checkout.blade.php` كما هي، وما يحرسه
 * الخادمُ في `TheShopSetsItsDeliveryAndPickupTimesTest`.
 */

type Day = { date: string; label: string; slots: { id: string; label: string }[] };

type Api = {
    mount: (form: HTMLFormElement, days: Record<string, Day[]>, T: Record<string, string>) => {
        fulfil: (f: string) => void;
        payload: () => { date: string; slot: string };
        drop: (sent: { fulfil: string; date: string; slot: string }) => void;
    };
};

const LIB = resolve(__dirname, '../../resources/js/store/ribbon-schedule.js');
const T = { pickDay: 'اختر اليوم', pickSlot: 'اختر الفترة' };

beforeAll(() => {
    new Function(readFileSync(LIB, 'utf8'))();
});

function days(): Record<string, Day[]> {
    return {
        delivery: [
            { date: '2027-02-10', label: 'الأربعاء 10 فبراير', slots: [{ id: 'pm01', label: '4:00 م – 9:00 م' }] },
            {
                date: '2027-02-13',
                label: 'السبت 13 فبراير',
                slots: [
                    { id: 'am01', label: 'الصباح (9:00 ص – 12:00 م)' },
                    { id: 'pm01', label: '4:00 م – 9:00 م' },
                ],
            },
        ],
        pickup: [{ date: '2027-02-12', label: 'الجمعة 12 فبراير', slots: [{ id: 'pk01', label: '10:00 ص – 1:00 م' }] }],
    };
}

function page(data = days(), delivery = '') {
    document.body.innerHTML = `
        <form>
            <select name="date" data-rb-day></select>
            <select name="slot" data-rb-slot></select>
            <p data-rb-no-days hidden ${delivery ? `data-delivery="${delivery}"` : ''}>لا مواعيد متاحة الآن لهذه الطريقة — تواصل معنا.</p>
            <div data-rb-when-row style="display:none"><span data-rb-when></span></div>
        </form>`;

    const form = document.querySelector('form')!;
    const api = (window as unknown as { RBSchedule: Api }).RBSchedule.mount(form, data, T);
    const day = form.querySelector<HTMLSelectElement>('[data-rb-day]')!;
    const slot = form.querySelector<HTMLSelectElement>('[data-rb-slot]')!;
    const pick = (sel: HTMLSelectElement, value: string) => {
        sel.value = value;
        sel.dispatchEvent(new Event('change'));
    };
    const values = (sel: HTMLSelectElement) => Array.from(sel.options).map((o) => o.value).filter(Boolean);

    return { form, api, day, slot, pick, values };
}

describe('يومُ التسليم وفترتُه في إتمام RIBBON', () => {
    it('يعرض أيّامَ الطريقة المختارة وحدها، وتبديلُها يبدّلها', () => {
        const { api, day, values } = page();

        api.fulfil('delivery');
        expect(values(day)).toEqual(['2027-02-10', '2027-02-13']);

        api.fulfil('pickup');
        expect(values(day)).toEqual(['2027-02-12']);
    });

    it('اليومُ يفتح فتراتِه وحدها، وفترةٌ وحيدةٌ تُختار وحدها', () => {
        const { api, day, slot, pick, values } = page();
        api.fulfil('delivery');

        expect(slot.disabled).toBe(true);

        pick(day, '2027-02-13');
        expect(values(slot)).toEqual(['am01', 'pm01']);
        expect(slot.value).toBe('');

        pick(day, '2027-02-10');
        expect(values(slot)).toEqual(['pm01']);
        expect(slot.value).toBe('pm01');
        expect(api.payload()).toEqual({ date: '2027-02-10', slot: 'pm01' });
    });

    it('ما لم يعد في القائمة لا يبقى مختارًا', () => {
        const { api, day, slot, pick } = page();
        api.fulfil('delivery');
        pick(day, '2027-02-13');
        pick(slot, 'am01');

        // الاستلامُ لا يعرف السبتَ — فلا يُرسَل يومُ التوصيل مع طلب استلام
        api.fulfil('pickup');
        expect(api.payload()).toEqual({ date: '', slot: '' });
    });

    it('يكتب الموعدَ في الملخّص', () => {
        const { form, api, day, slot, pick } = page();
        api.fulfil('delivery');
        pick(day, '2027-02-13');
        pick(slot, 'am01');

        expect(form.querySelector('[data-rb-when]')!.textContent).toBe('السبت 13 فبراير · الصباح (9:00 ص – 12:00 م)');
        expect(form.querySelector<HTMLElement>('[data-rb-when-row]')!.style.display).toBe('flex');
    });

    it('يقول للتوصيل بلا مواعيد كلماتِ صاحب المتجر — والاستلامُ يبقى يُختار', () => {
        const { form, api, day, values } = page({ delivery: [], pickup: days().pickup }, 'لا توصيل هذا الأسبوع — الاستلام متاح.');
        const none = form.querySelector<HTMLElement>('[data-rb-no-days]')!;

        api.fulfil('delivery');
        expect(none.hidden).toBe(false);
        expect(none.textContent).toBe('لا توصيل هذا الأسبوع — الاستلام متاح.');

        api.fulfil('pickup');
        expect(none.hidden).toBe(true);
        expect(values(day)).toEqual(['2027-02-12']);
    });

    it('ولا يقول كلماتِ التوصيل لاستلامٍ بلا مواعيد — بل نصَّ النظام', () => {
        const { form, api } = page({ delivery: [], pickup: [] }, 'لا توصيل هذا الأسبوع.');
        const none = form.querySelector<HTMLElement>('[data-rb-no-days]')!;

        api.fulfil('pickup');
        expect(none.hidden).toBe(false);
        expect(none.textContent).toBe('لا مواعيد متاحة الآن لهذه الطريقة — تواصل معنا.');

        api.fulfil('delivery');
        expect(none.textContent).toBe('لا توصيل هذا الأسبوع.');
    });

    it('يقول لطريقةٍ بلا أيّامٍ إنّها بلا مواعيد', () => {
        const { form, api } = page({ delivery: [], pickup: days().pickup });

        api.fulfil('delivery');
        expect(form.querySelector<HTMLElement>('[data-rb-no-days]')!.hidden).toBe(false);

        api.fulfil('pickup');
        expect(form.querySelector<HTMLElement>('[data-rb-no-days]')!.hidden).toBe(true);
    });

    it('يطرح فترةً ردّها الخادمُ من يومها، ويومَها إن خلا', () => {
        const { api, day, slot, pick, values } = page();
        api.fulfil('delivery');
        pick(day, '2027-02-10');

        api.drop({ fulfil: 'delivery', date: '2027-02-10', slot: 'pm01' });

        expect(values(day)).toEqual(['2027-02-13']);
        expect(api.payload()).toEqual({ date: '', slot: '' });
        // و«المساء» بالمعرّف نفسِه في السبت فترةٌ أخرى — لم يردّها الخادم
        pick(day, '2027-02-13');
        expect(values(slot)).toEqual(['am01', 'pm01']);
    });

    it('لا يطرح المعرّفَ نفسَه من يومٍ آخر ولا من الطريقة الأخرى', () => {
        const evening = { id: 'pm01', label: '4:00 م – 9:00 م' };
        const morning = { id: 'am01', label: '9:00 ص – 12:00 م' };
        const { api, day, slot, pick, values } = page({
            delivery: [
                { date: '2027-02-10', label: 'الأربعاء 10 فبراير', slots: [morning, evening] },
                { date: '2027-02-11', label: 'الخميس 11 فبراير', slots: [evening] },
            ],
            pickup: [{ date: '2027-02-10', label: 'الأربعاء 10 فبراير', slots: [evening] }],
        });
        api.fulfil('delivery');
        // والزبونُ بدّل إلى الاستلام والطلبُ في الطريق — والردُّ عن التوصيل الذي أُرسل
        api.fulfil('pickup');

        api.drop({ fulfil: 'delivery', date: '2027-02-10', slot: 'pm01' });

        pick(day, '2027-02-10');
        expect(values(slot)).toEqual(['pm01']);

        api.fulfil('delivery');
        pick(day, '2027-02-10');
        expect(values(slot)).toEqual(['am01']);
        pick(day, '2027-02-11');
        expect(values(slot)).toEqual(['pm01']);
    });
});
