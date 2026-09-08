import { describe, expect, it } from 'vitest';
import { targetSortKey } from '@/Pages/Admin/Settings/panels/EmployeesPanel';

/**
 * عمود «تحقيق الهدف» — ومن لا هدف له ليس عند الصفر.
 *
 * كان العمود يرسم مبيعات الشهر بالريال وعليها علامة `%`: «٢١٪» لواحدٍ
 * وعشرين ريالًا. وصار نسبةً تُقاس على `users.monthly_target` — ومن تُرك
 * هدفُه فارغًا لا نسبةَ له، فلا يُرتَّب مع من حقّق صفرًا من هدفٍ مضبوط.
 */
describe('ترتيب تحقيق الهدف', () => {
    it('من لا هدف له يقع تحت من حقّق صفرًا', () => {
        expect(targetSortKey({ target_pct: null })).toBeLessThan(targetSortKey({ target_pct: 0 }));
    });

    it('والنسبةُ تُرتَّب بقيمتها', () => {
        expect(targetSortKey({ target_pct: 150 })).toBe(150);
        expect(targetSortKey({ target_pct: 12.5 })).toBe(12.5);
    });
});
