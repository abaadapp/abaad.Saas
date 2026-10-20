import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { type CartItem, type ResumeCart, usePosCart } from '@/hooks/usePosCart';

/**
 * الكوبونُ يُعاد سؤالُه حين تتغيّر السلّة — ولا يبقى خصمٌ لا يصحّ على الشاشة.
 *
 * ═══ العطبُ الذي يحرسه هذا الملفّ ═══
 *
 * كانت السلّةُ تحفظ الكوبونَ نوعًا وقيمةً وحسب، وتحسب الخصمَ من المجموع كلّما
 * تبدّل. فكوبونٌ حدُّه الأدنى عشرون يُطبَّق على سلّةٍ بثلاثين، ثمّ يحذف الكاشيرُ
 * صنفًا فتصير عشرة — والخصمُ باقٍ على الشاشة، يُعلَن للزبون، ثمّ يردّه الدرج.
 * وما سوى الحدّ الأدنى لا يعرفه إلّا الخادم: كودٌ نفدت مرّاتُه من صندوقٍ آخر،
 * أو أوقفه صاحبُ المحلّ، أو انتهى عند منتصف الليل.
 *
 * ═══ والقاعدة ═══
 *
 *   - الحدُّ الأدنى يُحكم به في الحال: لا خصمَ على الشاشة ولا كودَ في الحمولة.
 *   - والخادمُ يُسأل مرّةً حين تهدأ السلّة — لا مع كلّ نقرة.
 *   - وما يرفضه الخادمُ يُسقط الكوبونَ ويُقال سببُه.
 *   - والجوابُ يُقرأ لآخر سؤالٍ وحدَه: جوابٌ قديمٌ يصل متأخّرًا لا يُعيد كوبونًا
 *     أسقطه جوابٌ أحدث، ولا يُسقط كوبونًا أكّده.
 *   - والدفعُ يُعيد الفحصَ على كلّ حال (`PosController::completeSale`) — الشاشةُ
 *     لا تُغني عنه.
 */

/** أطولُ من مهلة الهدوء — والمهلةُ أطولُ من `NOT_YET` */
const SETTLED = 1_000;
const NOT_YET = 50;

const customers = [
    { id: 1, name: 'أحمد', name_en: null, label: 'أحمد', phone: '91234567', points: 0, language: 'ar' },
    { id: 2, name: 'محمد', name_en: null, label: 'محمد', phone: '92222222', points: 0, language: 'ar' },
];

const cart = (items: Omit<CartItem, 'key' | 'note'>[]) =>
    renderHook(() =>
        usePosCart({
            products: [],
            customers,
            coupons: [],
            loyalty: { redeemMaxPct: 50, earnRate: 1, redeemMin: 100 },
            resume: { id: null, customer: null, items } as ResumeCart,
            currency: { code: 'OMR', name: 'ريال', symbol: 'ر.ع', rate: 1, is_base: true, active: true },
            onToast: vi.fn(),
        }),
    );

const ROSE = { id: 1, name: 'وردة', price: 20, qty: 1 };
const CARD = { id: 2, name: 'كرت', price: 10, qty: 1 };

/** خمسةُ ريالات، والحدُّ الأدنى عشرون */
const SAVE5 = { ok: true, code: 'SAVE5', type: 'مبلغ', value: 5, min_order: 20, message: 'تم' };
/** عشرةٌ في المئة، والحدُّ الأدنى عشرون */
const TEN = { ok: true, code: 'TEN', type: 'نسبة', value: 10, min_order: 20, message: 'تم' };

const accept = (body: Record<string, unknown> = SAVE5) => ({ ok: true, status: 200, json: async () => body });
const refuse = (error: string) => ({ ok: false, status: 422, json: async () => ({ ok: false, error }) });

type Reply = ReturnType<typeof accept> | ReturnType<typeof refuse>;

const LIMIT = 'حدُّ هذا الكوبون 1 استخدام لكل زبون، وقد بلغه هذا الزبون.';
const USED_UP = 'انتهت مرات استخدام الكوبون';

describe('الكوبونُ والسلّةُ تتغيّر', () => {
    let fetchMock: ReturnType<typeof vi.fn>;
    /** أسئلةُ المعاينة كما وصلت «الخادم» — بحمولتها، وبيدِ الاختبار متى يُجاب كلٌّ منها */
    let asked: { body: Record<string, unknown>; answer: (r: Reply) => void }[];
    /** وحين لا يُمسَك الجواب: يُجاب في الحال بهذا */
    let server: ((body: Record<string, unknown>) => Reply) | null;

    const bodies = (url: string) =>
        fetchMock.mock.calls
            .filter((c) => String(c[0]).includes(url))
            .map((c) => JSON.parse(String((c[1] as { body: string }).body)) as Record<string, unknown>);

    const previews = () => bodies('/pos/coupon');

    const settle = async (ms = SETTLED) => {
        await act(async () => {
            vi.advanceTimersByTime(ms);
        });
    };

    beforeEach(() => {
        vi.useFakeTimers();
        localStorage.clear();
        asked = [];
        // «خادمٌ» يقرأ الحدَّ الأدنى من الحمولة كما يقرؤه `PosController::applyCoupon`
        server = (body) => {
            const coupon = body.code === 'TEN' ? TEN : SAVE5;
            return Number(body.subtotal) < coupon.min_order ? refuse('الحد الأدنى للطلب 20.000 ر.ع') : accept(coupon);
        };
        fetchMock = vi.fn((url: string, init?: { body?: string }) => {
            if (!String(url).includes('/pos/coupon')) {
                return Promise.resolve({ ok: true, status: 200, json: async () => ({ ok: true, invoice: 'INV-1' }) });
            }
            const body = JSON.parse(String(init?.body ?? '{}')) as Record<string, unknown>;
            if (server) return Promise.resolve(server(body));

            return new Promise<Reply>((resolve) => asked.push({ body, answer: resolve }));
        });
        vi.stubGlobal('fetch', fetchMock);
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.unstubAllGlobals();
    });

    /* ═════════════ الحدُّ الأدنى ═════════════ */

    it('سلّةٌ نزلت تحت الحدّ الأدنى ← يسقط الخصمُ في الحال، ويُقال السبب، ولا يُرسَل الكود', async () => {
        const { result } = cart([ROSE, CARD]);

        await act(async () => {
            await result.current.applyCoupon('SAVE5');
        });
        expect(result.current.couponDiscount).toBe(5);
        expect(result.current.total).toBe(25);

        // يحذف الوردةَ فتصير السلّةُ عشرة
        act(() => result.current.remove('r0'));

        expect(result.current.couponDiscount).toBe(0);
        expect(result.current.discountAmount).toBe(0);
        expect(result.current.total).toBe(10);
        expect(result.current.couponError).toContain('الحد الأدنى للطلب');
        expect(result.current.couponError).toContain('20.000');

        // ولو ضغط «دفع» قبل أن تهدأ السلّة: الحمولةُ بلا كود — ما على الشاشة هو ما يُرسل
        await act(async () => {
            await result.current.checkoutSale('نقدي');
        });
        expect(bodies('/pos/checkout')[0].coupon_code).toBeNull();

        // وحين تهدأ: يُسقط الكوبونُ ويبقى سببُه — بلا سؤالٍ لا يلزم
        await settle();
        expect(result.current.coupon).toBeNull();
        expect(result.current.couponError).toContain('الحد الأدنى للطلب');
        expect(previews()).toHaveLength(1);
    });

    it('والكمّيةُ تزيد وتنقص: الخصمُ يتبعها في الحال، والخادمُ يُسأل مرّةً حين تهدأ', async () => {
        const { result } = cart([{ id: 1, name: 'وردة', price: 10, qty: 3 }]);

        await act(async () => {
            await result.current.applyCoupon('TEN');
        });
        expect(result.current.couponDiscount).toBe(3);
        expect(previews()).toHaveLength(1);

        act(() => result.current.inc('r0'));
        expect(result.current.couponDiscount).toBe(4);

        // لم تهدأ بعد — لا سؤال
        await settle(NOT_YET);
        expect(previews()).toHaveLength(1);

        await settle();
        expect(previews()).toHaveLength(2);
        expect(previews()[1].subtotal).toBe(40);
        expect(result.current.coupon?.code).toBe('TEN');
        expect(result.current.couponDiscount).toBe(4);

        // ينقص إلى عشرين (على الحدّ تمامًا)، ثمّ إلى عشرة، ثمّ يعود إلى عشرين
        act(() => result.current.dec('r0'));
        act(() => result.current.dec('r0'));
        expect(result.current.couponDiscount).toBe(2);

        act(() => result.current.dec('r0'));
        expect(result.current.couponDiscount).toBe(0);
        expect(result.current.couponError).toContain('الحد الأدنى للطلب');

        act(() => result.current.inc('r0'));
        expect(result.current.couponDiscount).toBe(2);
        expect(result.current.couponError).toBe('');

        // وسؤالٌ واحدٌ عن الحال الأخيرة — لا عن كلّ نقرة، والكوبونُ باقٍ
        await settle();
        expect(previews()).toHaveLength(3);
        expect(previews()[2].subtotal).toBe(20);
        expect(result.current.coupon?.code).toBe('TEN');
        expect(result.current.couponDiscount).toBe(2);
    });

    /* ═════════════ ما لا يعرفه إلّا الخادم ═════════════ */

    it('كودٌ نفدت مرّاتُه بين التطبيق والتعديل ← يُسقط حين يُسأل، ويُقال السبب', async () => {
        const { result } = cart([ROSE, CARD]);

        await act(async () => {
            await result.current.applyCoupon('SAVE5');
        });

        // صندوقٌ آخر استعمل آخرَ مرّة
        server = () => refuse(USED_UP);

        act(() => result.current.inc('r1'));
        await settle();

        expect(result.current.coupon).toBeNull();
        expect(result.current.couponDiscount).toBe(0);
        expect(result.current.couponError).toBe(USED_UP);

        await act(async () => {
            await result.current.checkoutSale('نقدي');
        });
        expect(bodies('/pos/checkout')[0].coupon_code).toBeNull();
    });

    /* ═════════════ الجوابُ لآخر سؤالٍ وحدَه ═════════════ */

    it('يتبدّل الزبونُ والتطبيقُ في الطريق ← يُسأل للجديد، وجوابُ القديم المتأخّر لا يُعيد الخصم', async () => {
        server = null;
        const { result } = cart([ROSE, CARD]);

        await act(async () => {
            result.current.selectCustomer('أحمد', 1);
        });
        act(() => {
            void result.current.applyCoupon('SAVE5');
        });
        await act(async () => {
            result.current.selectCustomer('محمد', 2);
        });

        expect(asked).toHaveLength(2);
        expect(asked[0].body.customer_id).toBe(1);
        expect(asked[1].body.customer_id).toBe(2);

        // محمّدٌ بلغ حدَّه — ويصل جوابُه أوّلًا
        await act(async () => {
            asked[1].answer(refuse(LIMIT));
        });
        // ثمّ يصل جوابُ أحمدَ القديم: «مقبول»
        await act(async () => {
            asked[0].answer(accept());
        });

        expect(result.current.coupon).toBeNull();
        expect(result.current.couponDiscount).toBe(0);
        expect(result.current.couponError).toBe(LIMIT);
        expect(result.current.couponLoading).toBe(false);
    });

    it('وجوابٌ قديمٌ برفضٍ بعد جوابٍ أحدث بقبول ← لا يُسقط كوبونًا صحيحًا', async () => {
        server = null;
        const { result } = cart([ROSE, CARD]);

        await act(async () => {
            result.current.selectCustomer('محمد', 2);
        });
        act(() => {
            void result.current.applyCoupon('SAVE5');
        });
        await act(async () => {
            asked[0].answer(accept());
        });
        expect(result.current.coupon?.code).toBe('SAVE5');

        // إلى أحمد (بلغ حدَّه) ثمّ عودٌ إلى محمّد — سؤالان في الطريق
        await act(async () => {
            result.current.selectCustomer('أحمد', 1);
        });
        await act(async () => {
            result.current.selectCustomer('محمد', 2);
        });
        expect(asked).toHaveLength(3);

        await act(async () => {
            asked[2].answer(accept());
        });
        await act(async () => {
            asked[1].answer(refuse(LIMIT));
        });

        expect(result.current.coupon?.code).toBe('SAVE5');
        expect(result.current.couponDiscount).toBe(5);
        expect(result.current.couponError).toBe('');
    });

    it('وجوابٌ عن سلّةٍ قديمة يصل بعد جوابِ الأحدث ← يُهمَل', async () => {
        const { result } = cart([ROSE, CARD]);

        await act(async () => {
            await result.current.applyCoupon('SAVE5');
        });

        server = null;
        act(() => result.current.inc('r1'));
        await settle();
        act(() => result.current.inc('r1'));
        await settle();
        expect(asked).toHaveLength(2);
        expect(asked[0].body.subtotal).toBe(40);
        expect(asked[1].body.subtotal).toBe(50);

        await act(async () => {
            asked[1].answer(accept());
        });
        await act(async () => {
            asked[0].answer(refuse(USED_UP));
        });

        expect(result.current.coupon?.code).toBe('SAVE5');
        expect(result.current.couponDiscount).toBe(5);
    });

    it('كوبونٌ أزاله الكاشيرُ وجوابُه في الطريق ← لا يعود', async () => {
        server = null;
        const { result } = cart([ROSE, CARD]);

        act(() => {
            void result.current.applyCoupon('SAVE5');
        });
        act(() => result.current.removeCoupon());

        await act(async () => {
            asked[0].answer(accept());
        });

        expect(result.current.coupon).toBeNull();
        expect(result.current.couponCode).toBe('');
        expect(result.current.couponDiscount).toBe(0);
        expect(result.current.couponLoading).toBe(false);
    });

    /* ═════════════ ولا أسئلةَ بلا داعٍ ═════════════ */

    it('لا سؤالَ بلا كوبون، ولا مع كلّ نقرة، ولا لملاحظةٍ لا تغيّر المجموع', async () => {
        const { result, rerender } = cart([ROSE, CARD]);

        act(() => result.current.inc('r0'));
        act(() => result.current.remove('r1'));
        await settle();
        expect(previews()).toHaveLength(0);

        await act(async () => {
            await result.current.applyCoupon('SAVE5');
        });
        expect(previews()).toHaveLength(1);

        // خمسُ نقراتٍ متتابعة سؤالٌ واحد
        for (let i = 0; i < 5; i++) act(() => result.current.inc('r0'));
        await settle();
        expect(previews()).toHaveLength(2);

        // والملاحظةُ وإعادةُ الرسم لا تسألان
        act(() => result.current.setNote('r0', 'بلا شريط'));
        rerender();
        await settle();
        expect(previews()).toHaveLength(2);
    });
});
