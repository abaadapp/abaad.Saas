import { describe, expect, it } from 'vitest';

import cases from '../fixtures/loyalty-quote-cases.json';
import randomCases from '../fixtures/loyalty-quote-random-cases.json';
import { loyaltyPercentOf, loyaltyQuote, loyaltyWorth, phpRound, type LoyaltyRules } from '@/lib/loyalty';

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

    /*
     * وأربعُمئة حالةٍ عشوائيّة كتبها الخادم — الطريقتان، صغيرةً وكبيرة
     * (`LoyaltyRedeemsByAFixedAmountOrAPercentTest`). فرقُ تقريبٍ واحدٌ بين
     * PHP وJavaScript في أيّ رقمٍ منها يُسقط هذا.
     */
    it('والحالاتُ العشوائيّةُ كلُّها كما حسبها الخادم', () => {
        type Row = [LoyaltyRules, number, number, number, number | null, ReturnType<typeof loyaltyQuote>];
        const rows = randomCases as unknown as Row[];
        expect(rows).toHaveLength(400);
        const wrong = rows
            .map(([rules, balance, base, other, requested, want], n) => ({ n, got: loyaltyQuote(rules, balance, base, other, requested), want }))
            .filter(({ got, want }) => JSON.stringify(got) !== JSON.stringify({ ...want, max_discount: Number(want.max_discount), discount: Number(want.discount) }));
        expect(wrong).toEqual([]);
    });

    /*
     * والتقريبُ تقريبُ PHP 8.4 نفسُه — القيمُ كما أخرجها `php -r` على الإصدار
     * الذي يعمل به الخادم. أوّلُها سقفُ الحالة ٢٣٥: المرآةُ القديمة قالت ٣٧٣٥٫٠٣٤.
     */
    it('التقريبُ كما يقرّبه الخادم — نصفُ البيسة بقيمته المخزَّنة', () => {
        expect(phpRound((14940.134 * 25) / 100, 3)).toBe(3735.033);
        expect(phpRound((1.701 * 50) / 100, 3)).toBe(0.85);
        expect(phpRound(0.285, 2)).toBe(0.29);
        expect(phpRound(1.955, 2)).toBe(1.96);
        expect(phpRound(5.045, 2)).toBe(5.05);
        expect(phpRound(5.055, 2)).toBe(5.06);
        expect(phpRound(2.675, 2)).toBe(2.68);
        expect(phpRound(-2.675, 2)).toBe(-2.68);
        expect(phpRound(-1.0005, 3)).toBe(-1.001);
        expect(phpRound(0.0005, 3)).toBe(0.001);
    });

    it('نسبةُ النقاط من الفاتورة للعرض — لا تتجاوز مئة', () => {
        const rules = { points: 100, percent: 10 };
        expect(loyaltyPercentOf(rules, 500)).toBe(50);
        expect(loyaltyPercentOf(rules, 333)).toBe(33.3);
        expect(loyaltyPercentOf(rules, 5000)).toBe(100);
        expect(loyaltyPercentOf({ points: 150, percent: 7.5 }, 777)).toBe(38.85);
    });

    it('القيمةُ تُقصّ إلى البيسة ولا تُدوَّر إلى أعلى', () => {
        // ٣٣٣ نقطةً بعُشر ريالٍ لكلّ ثلاث = ١١٫١ بالضبط، و١٨٥ = ٦٫١٦٦ لا ٦٫١٦٧
        expect(loyaltyWorth({ points: 3, value: 0.1 }, 333)).toBe(11.1);
        expect(loyaltyWorth({ points: 3, value: 0.1 }, 185)).toBe(6.166);
    });
});
