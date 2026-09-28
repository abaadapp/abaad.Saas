import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { usePosCart } from '@/hooks/usePosCart';

/**
 * الكوبونُ يتبع الزبونَ في الصندوق — لا يبقى خصمُ الأوّل على فاتورة الثاني.
 *
 * ═══ العطبُ الذي يحرسه هذا الملفّ ═══
 *
 * كودٌ حدُّه «مرّتان لكلّ زبون». يطبّقه الكاشيرُ على أحمد، ثمّ يبدّل الزبونَ
 * إلى محمّد — والخصمُ معروضٌ على الشاشة كما هو. فإن كان محمّدٌ قد بلغ حدَّه
 * أُعلن له سعرٌ ثمّ رُدّ عند الدرج، أمام الزبون. وإن لم يبلغه فالخصمُ صحيحٌ
 * بالمصادفة لا بالفحص.
 *
 * فتغييرُ الزبون يُعيد سؤالَ الخادم. وهو سؤالُ المعاينة نفسُه: لا يحتسب
 * استعمالًا، والدفعُ يُعيد الفحصَ مرّةً أخيرةً على كلّ حال.
 */
const customers = [
    { id: 1, name: 'أحمد', name_en: null, label: 'أحمد', phone: '91234567', points: 0, language: 'ar' },
    { id: 2, name: 'محمد', name_en: null, label: 'محمد', phone: '92222222', points: 0, language: 'ar' },
];

const onToast = vi.fn();

const cart = () =>
    renderHook(() =>
        usePosCart({
            products: [],
            customers,
            coupons: [],
            loyalty: { redeemMaxPct: 50, earnRate: 1, redeemMin: 100 },
            resume: null,
            currency: { code: 'OMR', name: 'ريال', symbol: 'ر.ع', rate: 1, is_base: true, active: true },
            onToast,
        }),
    );

/** نداءاتُ باب المعاينة وحدَها — والحمولةُ مقروءةً */
const previews = (mock: ReturnType<typeof vi.fn>) =>
    mock.mock.calls
        .filter((c) => String(c[0]).includes('/pos/coupon'))
        .map((c) => JSON.parse(String((c[1] as { body: string }).body)));

describe('الكوبونُ وزبونُه في الصندوق', () => {
    let fetchMock: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        localStorage.clear();
        fetchMock = vi.fn(async () => ({
            ok: true,
            status: 200,
            json: async () => ({ ok: true, code: 'SAVE5', type: 'مبلغ', value: 5, message: 'تم' }),
        }));
        vi.stubGlobal('fetch', fetchMock);
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        onToast.mockReset();
    });

    it('المعاينةُ تحمل الزبونَ معها — معرّفًا واسمًا', async () => {
        const { result } = cart();

        await act(async () => {
            result.current.selectCustomer('أحمد', 1);
        });
        await act(async () => {
            await result.current.applyCoupon('SAVE5');
        });

        const sent = previews(fetchMock);
        expect(sent).toHaveLength(1);
        expect(sent[0].customer_id).toBe(1);
        expect(sent[0].code).toBe('SAVE5');
    });

    it('وتغييرُ الزبون يُعيد الفحصَ بالزبون الجديد', async () => {
        const { result } = cart();

        await act(async () => {
            result.current.selectCustomer('أحمد', 1);
        });
        await act(async () => {
            await result.current.applyCoupon('SAVE5');
        });

        expect(previews(fetchMock)).toHaveLength(1);

        await act(async () => {
            result.current.selectCustomer('محمد', 2);
        });

        const sent = previews(fetchMock);
        expect(sent.length).toBeGreaterThan(1);
        expect(sent[sent.length - 1].customer_id).toBe(2);
    });

    it('وزبونٌ بلغ حدَّه يُسقط الخصمَ ويُقال السبب — عند السلّة لا عند الدرج', async () => {
        const { result } = cart();

        await act(async () => {
            result.current.selectCustomer('أحمد', 1);
        });
        await act(async () => {
            await result.current.applyCoupon('SAVE5');
        });

        expect(result.current.coupon?.code).toBe('SAVE5');

        // والخادمُ يردّ الثاني
        fetchMock.mockImplementation(async () => ({
            ok: false,
            status: 422,
            json: async () => ({ ok: false, error: 'حدُّ هذا الكوبون 1 استخدام لكل زبون، وقد بلغه هذا الزبون.' }),
        }));

        await act(async () => {
            result.current.selectCustomer('محمد', 2);
        });

        expect(result.current.coupon).toBeNull();
        expect(result.current.couponError).toContain('لكل زبون');
    });

    it('ولا يُسأل الخادمُ حين لا كوبونَ مطبَّق', async () => {
        const { result } = cart();

        await act(async () => {
            result.current.selectCustomer('أحمد', 1);
        });
        await act(async () => {
            result.current.selectCustomer('محمد', 2);
        });

        expect(previews(fetchMock)).toHaveLength(0);
    });

    it('ولا يُعاد الفحصُ حين لا يتغيّر الزبون', async () => {
        const { result } = cart();

        await act(async () => {
            result.current.selectCustomer('أحمد', 1);
        });
        await act(async () => {
            await result.current.applyCoupon('SAVE5');
        });

        const before = previews(fetchMock).length;

        // ضغطةٌ على الزبون نفسِه لا تسأل شيئًا
        await act(async () => {
            result.current.selectCustomer('أحمد', 1);
        });

        expect(previews(fetchMock)).toHaveLength(before);
    });
});
