import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { markTouchDevice } from '@/lib/touch';
import { DESKTOP, IPAD, PHONE, TOUCH_LAPTOP, device, forgetDevice } from './devices';

/**
 * أهدافُ اللمس تكبر تحت الإبهام — والآيبادُ إبهامٌ وإن ادّعى الفأرة.
 *
 * ٣٢ بكسلًا مقاسٌ للفأرة لا لليد: زرُّ حذفٍ بهذا الحجم في صفِّ جدولٍ
 * يُخطئه الإصبع أو يضغط جارَه، فيُحذف الصفُّ الخطأ. ولذلك يُكبَّر كلُّ
 * هدفٍ بالمحوّل `touch:` إلى أربعٍ وأربعين.
 *
 * وكان المحوّل استعلامَ وسائطٍ وحده، وسفاري على الآيباد يردّ عليه `fine`
 * — فكان كلُّ ما كُتب للإصبع صامتًا على الجهاز الذي كُتب له. والسمةُ هي
 * البابُ الثاني، وهذا الحارس يقيس متى تُفتح ومتى لا تُفتح.
 */
describe('السمة التي يقرؤها المحوّل touch:', () => {
    const marked = () => document.documentElement.hasAttribute('data-touch');

    beforeEach(() => {
        document.documentElement.removeAttribute('data-touch');
    });

    afterEach(() => {
        document.documentElement.removeAttribute('data-touch');
        forgetDevice();
    });

    it('تُكتب على آيبادٍ يقول إنّه حاسوب', () => {
        device(IPAD);
        markTouchDevice();

        expect(marked()).toBe(true);
    });

    it('وتُكتب على الهاتف', () => {
        device(PHONE);
        markTouchDevice();

        expect(marked()).toBe(true);
    });

    it('ولا تُكتب على حاسوبٍ حقيقيّ', () => {
        device(DESKTOP);
        markTouchDevice();

        expect(marked()).toBe(false);
    });

    it('ولا على حاسوبٍ محمولٍ بشاشةٍ لمسيّة — تحته لوحةٌ وفأرة', () => {
        device(TOUCH_LAPTOP);
        markTouchDevice();

        expect(marked()).toBe(false);
    });

    it('وكتابتُها مرّتين لا تُغيّر شيئًا — الإقلاعُ قد يتكرّر', () => {
        device(IPAD);
        markTouchDevice();
        markTouchDevice();

        expect(document.documentElement.getAttribute('data-touch')).toBe('');
    });
});
