import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { type ResumeCart, roundMoney, usePosCart } from '@/hooks/usePosCart';

/**
 * ما تقوله شاشةُ الصندوق هو ما تكتبه الفاتورة — بالبيسة.
 *
 * ═══ العطب ═══
 *
 * الخادمُ يقرّب كلَّ خطوةٍ إلى ثلاث منازل: خصمَ الكوبون (`Coupon::discountFor`)،
 * ثمّ الخصمَ كلَّه، ثمّ الضريبة (`SaleLines::taxFor`)، ثمّ الإجماليّ. والشاشةُ
 * كانت تجمع بلا تقريب وتقرّب العرضَ وحدَه. فكوبونٌ بالنسبة على ٢٫٦٥٠ يُقرأ
 * على الشاشة ٢٫٥١٨ ويُكتب في الفاتورة ٢٫٥١٧ — في حالةٍ من كلّ ثمانٍ تقريبًا
 * من سلالِ الكوبون بالنسبة.
 *
 * والحالاتُ نفسُها يُحاسَب عليها الخادمُ في
 * `TheTillShowsTheTotalTheInvoiceWillCarryTest` — من ملفٍّ واحد يقرؤه الطرفان.
 */

interface Case {
    name: string;
    vat: { enabled: boolean; rate: number; inclusive: boolean };
    lines: { price: number; qty: number }[];
    coupon: { type: string; value: number; min_order: number };
    expect: { coupon_discount: number; discount: number; tax: number; total: number };
}

const shared = JSON.parse(readFileSync(resolve(__dirname, '../fixtures/pos-coupon-totals.json'), 'utf8')) as {
    cases: Case[];
};

const cart = (c: Case) =>
    renderHook(() =>
        usePosCart({
            products: [],
            customers: [],
            coupons: [],
            loyalty: { redeemMaxPct: 50, earnRate: 1, redeemMin: 100 },
            vat: c.vat,
            resume: {
                id: null,
                customer: null,
                items: c.lines.map((l, i) => ({ id: i + 1, name: `صنف ${i}`, price: l.price, qty: l.qty })),
            } as ResumeCart,
            currency: { code: 'OMR', name: 'ريال', symbol: 'ر.ع', rate: 1, is_base: true, active: true },
            onToast: vi.fn(),
        }),
    );

describe('الشاشةُ والفاتورةُ رقمٌ واحد', () => {
    beforeEach(() => localStorage.clear());

    afterEach(() => vi.unstubAllGlobals());

    it.each(shared.cases)('$name', async (c) => {
        vi.stubGlobal(
            'fetch',
            vi.fn(async () => ({
                ok: true,
                status: 200,
                json: async () => ({ ok: true, code: 'SHARED', ...c.coupon, message: 'تم' }),
            })),
        );

        const { result } = cart(c);

        await act(async () => {
            await result.current.applyCoupon('SHARED');
        });

        // الأرقامُ نفسُها لا ما يقاربها — فرقُ بيسةٍ واحدةٍ يُسقطها
        expect(result.current.couponDiscount).toBe(c.expect.coupon_discount);
        expect(result.current.discountAmount).toBe(c.expect.discount);
        expect(result.current.taxAmount).toBe(c.expect.tax);
        expect(result.current.total).toBe(c.expect.total);
    });
});

describe('التقريبُ تقريبُ الخادم — round($v, 3) في PHP 8.4', () => {
    /*
     * كلُّ قيمةٍ هنا قُرئت من PHP 8.4 نفسِه (`php -r 'echo round($v, 3);'`).
     *
     * وأكثرُها حدُّ نصفٍ يفترق عنده الطريقان الشائعان: `toFixed(3)` يقرّب
     * ‎0.0045‎ إلى ‎0.004‎ وPHP إلى ‎0.005‎، وحيلةُ `Number(v + 'e3')` تقرّب
     * ‎1.1744999999999999‎ إلى ‎1.175‎ وPHP إلى ‎1.174‎.
     */
    it.each([
        [0.0045, 0.005],
        [0.013499999999999998, 0.013],
        [1.0005, 1.001],
        [2.0004999999999997, 2],
        [0.0625, 0.063],
        [0.0014999999999999998, 0.001],
        [1.1744999999999999, 1.174],
        [6.9405, 6.941],
        [0.3305, 0.331],
        [1.16655, 1.167],
        [8e-7, 0],
        [2.517, 2.517],
        [0, 0],
    ])('%s ← %s', (value, rounded) => {
        expect(roundMoney(value)).toBe(rounded);
    });
});
