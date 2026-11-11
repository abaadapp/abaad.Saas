import { describe, expect, it } from 'vitest';

import cases from '../fixtures/loyalty-quote-cases.json';
import { loyaltyQuote, loyaltyWorth, type LoyaltyRules } from '@/lib/loyalty';

/**
 * الشاشةُ والخادمُ يحسبان خصمَ النقاط حسابًا واحدًا.
 *
 * الصندوقُ يعرض الخصمَ قبل أن يُرسل البيعة (ويعمل بلا اتّصال أحيانًا)، والخادمُ
 * يكتبه بـ`Loyalty::quote`. والحالاتُ هنا كتبها الخادمُ نفسُه، ويقرؤها اختبارُ
 * PHP (`LoyaltyRedeemsAlikeOnTheTillAndTheInvoiceTest`) — فأيُّ فرقٍ بين البابين
 * يُسقط أحدَهما.
 */

interface Case {
    name: string;
    rules: LoyaltyRules;
    balance: number;
    base: number;
    other: number;
    requested: number | null;
    expect: ReturnType<typeof loyaltyQuote>;
}

describe('مرآةُ حساب النقاط', () => {
    for (const c of (cases as { cases: Case[] }).cases) {
        it(c.name, () => {
            expect(loyaltyQuote(c.rules, c.balance, c.base, c.other, c.requested)).toEqual(c.expect);
        });
    }

    it('القيمةُ تُقصّ إلى البيسة ولا تُدوَّر إلى أعلى', () => {
        // ٣٣٣ نقطةً بعُشر ريالٍ لكلّ ثلاث = ١١٫١ بالضبط، و١٨٥ = ٦٫١٦٦ لا ٦٫١٦٧
        expect(loyaltyWorth({ points: 3, value: 0.1 }, 333)).toBe(11.1);
        expect(loyaltyWorth({ points: 3, value: 0.1 }, 185)).toBe(6.166);
    });
});
