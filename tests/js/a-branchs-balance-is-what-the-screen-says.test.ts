import { describe, expect, it } from 'vitest';

import { availableIn } from '@/Pages/Admin/Inventory/partials/available';

/**
 * «المتوفّر» في شاشة تعديلات المخزون رصيدُ الفرع المختار لا إجماليُّ الشركة.
 *
 * كانت تقرأ `product.quantity` — مجموعَ الفروع — وتقيس عليه حدَّ الخصم،
 * والكتابةُ تقع على فرعٍ بعينه. قِستُها في المتصفّح: باقةٌ رصيدُها كلُّه في
 * الرئيسيّ (٣٢) وصفرٌ في صلالة — تُختار صلالة فتقول الشاشةُ «المتوفّر: ٣٢»،
 * ويُقدَّر أثرُ التلف بثلاثين ريالًا، ويفتح زرُّ التسجيل؛ ثمّ يردّ الخادمُ
 * «رصيد فرع صلالة من هذا الصنف 0 فقط».
 *
 * وهو عطبُ الخادم نفسُه الذي أُصلح في `StockAdjustmentController::store` —
 * وبقي على الشاشة بعده.
 */

const MAIN = 1;
const SALALAH = 2;

const rose = { quantity: 32, stock: { [MAIN]: 32, [SALALAH]: 0 } };

describe('المتاح للخصم', () => {
    it('رصيدُ الفرع المختار لا إجماليُّ الشركة', () => {
        expect(availableIn(rose, String(SALALAH))).toBe(0);
    });

    it('ويقرأ الرقم الصحيح على الفرع الذي فيه الرصيد', () => {
        expect(availableIn(rose, String(MAIN))).toBe(32);
    });

    it('وفرعٌ لا صفَّ له في الدفتر رصيدُه صفر', () => {
        expect(availableIn(rose, '9')).toBe(0);
    });

    it('وبلا فرعٍ مختار يُقرأ الإجماليّ — لا صفرٌ عمّا عنده منه اثنان وثلاثون', () => {
        expect(availableIn(rose, '')).toBe(32);
    });

    it('وبلا صنفٍ مختار لا رقم', () => {
        expect(availableIn(undefined, String(MAIN))).toBe(0);
    });

    it('وصنفٌ لم يُوزَّع بعدُ يقرأ من دفتره — والخادم ينسبه إلى الفرع الأوّل', () => {
        const fresh = { quantity: 10, stock: { [MAIN]: 10 } };

        expect(availableIn(fresh, String(MAIN))).toBe(10);
        expect(availableIn(fresh, String(SALALAH))).toBe(0);
    });

    it('ودفترٌ لم يصل أصلًا لا يُقرأ إجماليًّا من تحت الباب', () => {
        expect(availableIn({ quantity: 32 }, String(MAIN))).toBe(0);
    });

    it('والصفرُ صفرٌ لا فراغ — فلا يسقط إلى الإجماليّ', () => {
        expect(availableIn(rose, String(SALALAH))).not.toBe(rose.quantity);
    });
});
