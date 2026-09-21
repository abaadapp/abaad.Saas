import { act, render, renderHook, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { usePosCart, whatBlocksPayment } from '@/hooks/usePosCart';
import CustomerContextCard, { birthdayPhrase } from '@/Pages/Pos/partials/CustomerContextCard';

/**
 * ما يراه الكاشير عن الزبون — والخادمُ يقول، والشاشةُ لا تفترض.
 *
 * الحظرُ لا يُطوى ولا يُفتح به الدفعُ إلّا بسببٍ مكتوب؛ والتحذيرُ يُقرأ
 * ويُطوى ويمضي البيع؛ وعيدُ الميلاد «اليوم» و«غدًا» و«بعد ٣ أيام».
 */
const t = (k: string, r?: Record<string, string | number>) =>
    r ? Object.entries(r).reduce((s, [n, v]) => s.replace(`:${n}`, String(v)), k) : k;

describe('بطاقةُ الزبون في الصندوق', () => {
    const none = { alert: null, birthday_in: null, note: null };

    it('لا شيءَ يُرسم لزبونٍ بلا تنبيهٍ ولا ميلادٍ ولا ملاحظة', () => {
        const { container } = render(<CustomerContextCard context={none} canOverride={false} overrideReason="" onOverrideReason={() => {}} />);
        expect(container).toBeEmptyDOMElement();
    });

    it('التحذيرُ يُقرأ بسببه ويُطوى بـ«متابعة البيع»', async () => {
        render(
            <CustomerContextCard
                context={{ ...none, alert: { type: 'warning', reason: 'تأكد من الدفع قبل التجهيز' } }}
                canOverride={false} overrideReason="" onOverrideReason={() => {}}
            />,
        );
        expect(screen.getByRole('alert')).toHaveTextContent('تنبيه على العميل');
        expect(screen.getByRole('alert')).toHaveTextContent('تأكد من الدفع قبل التجهيز');

        await userEvent.click(screen.getByRole('button', { name: 'متابعة البيع' }));
        expect(screen.queryByRole('alert')).toBeNull();
    });

    it('الحظرُ لا يُطوى — ومن لا يملك التجاوز يُقال له ذلك بلا حقل', () => {
        render(
            <CustomerContextCard context={{ ...none, alert: { type: 'block', reason: 'لم يستلم طلبات' } }} canOverride={false} overrideReason="" onOverrideReason={() => {}} />,
        );
        expect(screen.getByRole('alert')).toHaveTextContent('البيع لهذا العميل موقوف');
        expect(screen.getByRole('alert')).toHaveTextContent('لم يستلم طلبات');
        expect(screen.queryByRole('button', { name: 'متابعة البيع' })).toBeNull();
        expect(screen.queryByRole('textbox')).toBeNull();
        expect(screen.getByText('يلزم إذنُ من يملك تجاوز الحظر.')).toBeInTheDocument();
    });

    it('ومن يملكه يكتب السبب — فيُبلَّغ للسلّة', async () => {
        const onReason = vi.fn();
        render(
            <CustomerContextCard context={{ ...none, alert: { type: 'block', reason: '' } }} canOverride overrideReason="" onOverrideReason={onReason} />,
        );
        await userEvent.type(screen.getByRole('textbox'), 'دفع');
        expect(onReason).toHaveBeenCalled();
    });

    it('عيدُ الميلاد والملاحظةُ يُقالان تحت التنبيه — بطاقةٌ واحدة', () => {
        render(
            <CustomerContextCard
                context={{ alert: { type: 'warning', reason: 'س' }, birthday_in: 3, note: 'يفضل واتساب بعد ٤' }}
                canOverride={false} overrideReason="" onOverrideReason={() => {}}
            />,
        );
        const card = screen.getByTestId('customer-context');
        expect(card).toHaveTextContent('🎂 عيد ميلاد العميل بعد 3 أيام');
        expect(card).toHaveTextContent('ملاحظة داخلية: يفضل واتساب بعد ٤');
        // الترتيب: التحذيرُ قبل الميلاد قبل الملاحظة
        const text = card.textContent ?? '';
        expect(text.indexOf('تنبيه على العميل')).toBeLessThan(text.indexOf('🎂'));
        expect(text.indexOf('🎂')).toBeLessThan(text.indexOf('ملاحظة داخلية'));
    });

    it('«اليوم» و«غدًا» و«بعد :n أيام»', () => {
        expect(birthdayPhrase(0, t)).toBe('🎂 عيد ميلاد العميل اليوم');
        expect(birthdayPhrase(1, t)).toBe('🎂 عيد ميلاد العميل غدًا');
        expect(birthdayPhrase(3, t)).toBe('🎂 عيد ميلاد العميل بعد 3 أيام');
    });
});

describe('السلّةُ وزبونٌ موقوف', () => {
    const onToast = vi.fn();
    let fetchMock: ReturnType<typeof vi.fn>;
    const customers = [
        { id: 1, name: 'موقوف', label: 'موقوف', phone: '1', points: 0, language: 'ar', context: { alert: { type: 'block' as const, reason: 'س' }, birthday_in: null, note: null } },
        { id: 2, name: 'محذَّر', label: 'محذَّر', phone: '2', points: 0, language: 'ar', context: { alert: { type: 'warning' as const, reason: 'س' }, birthday_in: null, note: null } },
    ];
    const cart = () =>
        renderHook(() =>
            usePosCart({
                products: [], customers, coupons: [],
                loyalty: { redeemMaxPct: 50, earnRate: 1, redeemMin: 100 },
                resume: null,
                currency: { code: 'OMR', name: 'ريال', symbol: 'ر.ع', rate: 1, is_base: true, active: true },
                onToast,
            }),
        );
    const bouquet = { key: 'p7', id: 7, name: 'باقة', price: 5, stock: 10 };

    beforeEach(() => {
        localStorage.clear();
        fetchMock = vi.fn(async () => ({ ok: true, status: 200, json: async () => ({ ok: true, invoice: 'INV-1', points_earned: 0 }) }));
        vi.stubGlobal('fetch', fetchMock);
    });
    afterEach(() => { vi.unstubAllGlobals(); onToast.mockReset(); });

    it('بوّابةُ الدفع تُغلق على الموقوف حتى يُكتب سبب — والمحذَّرُ يمرّ', () => {
        expect(whatBlocksPayment({ items: [1], needsLanguage: false, blocked: true, blockOverrideReason: '' })).toBe('blocked');
        expect(whatBlocksPayment({ items: [1], needsLanguage: false, blocked: true, blockOverrideReason: '  ' })).toBe('blocked');
        expect(whatBlocksPayment({ items: [1], needsLanguage: false, blocked: true, blockOverrideReason: 'دفع' })).toBeNull();
        expect(whatBlocksPayment({ items: [1], needsLanguage: false, blocked: false })).toBeNull();
    });

    it('لا تُرسَل بيعةٌ لموقوفٍ بلا سبب — والسببُ يُحمل معها ويُصفَّر عند تبديل الزبون', async () => {
        const { result } = cart();
        act(() => result.current.add(bouquet));
        act(() => result.current.selectCustomer('موقوف', 1));
        expect(result.current.blocked).toBe(true);

        let res: Awaited<ReturnType<typeof result.current.checkoutSale>> | undefined;
        await act(async () => { res = await result.current.checkoutSale('نقدي'); });
        expect(res?.rejected).toBe(true);
        expect(fetchMock).not.toHaveBeenCalled();
        expect(onToast).toHaveBeenCalledWith('البيع لهذا العميل موقوف.', 'danger');

        act(() => result.current.setBlockOverrideReason('دفع مقدّمًا'));
        await act(async () => { await result.current.checkoutSale('نقدي'); });
        expect(fetchMock).toHaveBeenCalledTimes(1);
        const body = JSON.parse((fetchMock.mock.calls[0] as [string, { body: string }])[1].body);
        expect(body.block_override_reason).toBe('دفع مقدّمًا');

        // إغلاقٌ أو تبديلٌ ليس تجاوزًا
        act(() => result.current.selectCustomer('محذَّر', 2));
        expect(result.current.blocked).toBe(false);
        expect(result.current.blockOverrideReason).toBe('');
        await act(async () => { await result.current.checkoutSale('نقدي'); });
        const second = JSON.parse((fetchMock.mock.calls[1] as [string, { body: string }])[1].body);
        expect(second.block_override_reason).toBeNull();
    });
});
