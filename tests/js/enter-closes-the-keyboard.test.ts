import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { enterEndsTypingOnTouch } from '@/lib/enter-key';
import { DESKTOP, IPAD, PHONE, TOUCH_LAPTOP, device, forgetDevice } from './devices';

/**
 * «تم» تُغلق اللوحة — من كلّ حقلٍ في النظام لا من حقول النماذج وحدها.
 *
 * وعلى الآيباد لا تُقلّص لوحةُ المفاتيح الصفحة: تغطّي نصفَها السفليّ وتبقى.
 * فحقلٌ لا تُغلق لوحتُه يحجب ما تحته — زرَّ الحفظ، وبقيّةَ النموذج — حتى
 * يجد صاحبُه فراغًا ينقره. وكان ذلك حالَ ١٧٤ حقلًا لا نموذجَ لها.
 *
 * والسلوكُ يُقاس هنا لا يُقرأ من المصدر: مستمعٌ على المستند، وشرطُ جهازٍ
 * لمسيّ، وأربعةُ استثناءات — وقراءةُ `if` في ملفّ لا تُثبت أيًّا منها.
 */
describe('Enter تُنهي الكتابة على الأجهزة اللمسية', () => {
    /** حقلٌ في الصفحة، وفي نموذجٍ إن طُلب */
    const field = (opts: { form?: 'plain' | 'submits'; type?: string; tag?: 'input' | 'textarea' } = {}) => {
        const el = document.createElement(opts.tag ?? 'input');

        if (el instanceof HTMLInputElement && opts.type) {
            el.type = opts.type;
        }

        if (opts.form) {
            const form = document.createElement('form');
            if (opts.form === 'submits') {
                form.dataset.enterSubmits = '';
            }
            form.appendChild(el);
            document.body.appendChild(form);
        } else {
            document.body.appendChild(el);
        }

        el.focus();

        return el;
    };

    /** ضغطةُ Enter كما تصل من اللوحة — وقد يستهلكها أحدٌ قبلنا */
    const press = (el: HTMLElement, consumed = false) => {
        const event = new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true });
        if (consumed) {
            event.preventDefault();
        }
        el.dispatchEvent(event);

        return event;
    };

    /*
     * المستمعُ يسكن `document` ولا يُنزع — وهو صحيحٌ في التطبيق: يُركَّب
     * مرّةً عند الإقلاع ويبقى ما بقيت الصفحة. لكنّ الاختبارات تُركّبه
     * مرّةً لكلٍّ منها على المستند نفسه، فيتراكم: مستمعُ اختبارِ اللمس
     * يُنهي التركيز في اختبار سطح المكتب فيسقط حارسٌ سليم.
     *
     * فيُلتقط ما يُركَّب ويُنزع بعد كلّ اختبار — عزلٌ في الاختبار لا حيلةٌ
     * في الكود.
     */
    let attached: Array<[string, EventListener]> = [];

    beforeEach(() => {
        document.body.innerHTML = '';
        attached = [];

        const add = document.addEventListener.bind(document);

        vi.spyOn(document, 'addEventListener').mockImplementation(((
            type: string,
            handler: EventListener,
            options?: boolean | AddEventListenerOptions,
        ) => {
            attached.push([type, handler]);
            add(type, handler, options);
        }) as never);
    });

    afterEach(() => {
        attached.forEach(([type, handler]) => document.removeEventListener(type, handler));

        forgetDevice();
    });

    it('تُغلق اللوحة في حقلٍ لا نموذجَ له', () => {
        device(PHONE);
        enterEndsTypingOnTouch();

        const el = field();
        expect(document.activeElement).toBe(el);

        press(el);

        expect(document.activeElement).not.toBe(el);
    });

    it('تُغلق اللوحة في حقلٍ داخل نموذج — ولا تُرسله', () => {
        device(PHONE);
        enterEndsTypingOnTouch();

        const el = field({ form: 'plain' });
        const event = press(el);

        expect(document.activeElement).not.toBe(el);
        expect(event.defaultPrevented).toBe(true);
    });

    it('وعلى آيبادٍ يقول إنّه حاسوب تُغلقها كذلك — ولا تقفز إلى الحقل التالي', () => {
        device(IPAD);
        enterEndsTypingOnTouch();

        const el = field({ form: 'plain' });
        const event = press(el);

        // القفزةُ فعلُ المتصفّح الافتراضيُّ على Enter: مُنِعت فلم تقع
        expect(event.defaultPrevented).toBe(true);
        expect(document.activeElement).not.toBe(el);
    });

    it('ولا تمسّ نموذجًا أذِن لها أن ترسله', () => {
        device(PHONE);
        enterEndsTypingOnTouch();

        const el = field({ form: 'submits' });
        const event = press(el);

        expect(document.activeElement).toBe(el);
        expect(event.defaultPrevented).toBe(false);
    });

    it('ولا تمسّ حقلًا استهلك المفتاحَ قبلها — ماسحُ الباركود', () => {
        device(PHONE);
        enterEndsTypingOnTouch();

        const el = field();
        press(el, true);

        // الكاشير يمسح صنفًا بعد صنف: فقدانُ التركيز بعد كلّ مسحة يوقف العمل
        expect(document.activeElement).toBe(el);
    });

    it('ولا تمسّ منطقةَ نصٍّ — Enter فيها سطرٌ جديد', () => {
        device(PHONE);
        enterEndsTypingOnTouch();

        const el = field({ tag: 'textarea' });
        const event = press(el);

        expect(document.activeElement).toBe(el);
        expect(event.defaultPrevented).toBe(false);
    });

    it('ولا تمسّ زرًّا — Enter عليه تضغطه', () => {
        device(PHONE);
        enterEndsTypingOnTouch();

        const el = field({ form: 'plain', type: 'submit' });
        const event = press(el);

        expect(event.defaultPrevented).toBe(false);
    });

    it('وعلى سطح المكتب لا شيء يتغيّر', () => {
        device(DESKTOP);
        enterEndsTypingOnTouch();

        const el = field({ form: 'plain' });
        const event = press(el);

        expect(document.activeElement).toBe(el);
        expect(event.defaultPrevented).toBe(false);
    });

    it('ولا على حاسوبٍ محمولٍ بشاشةٍ لمسيّة — لوحتُه تحت أصابعه', () => {
        device(TOUCH_LAPTOP);
        enterEndsTypingOnTouch();

        const el = field({ form: 'plain' });
        const event = press(el);

        expect(document.activeElement).toBe(el);
        expect(event.defaultPrevented).toBe(false);
    });
});
