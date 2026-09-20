import { act, cleanup as cleanupAll, render, renderHook, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { router } from '@inertiajs/react';
import LanguageChoice from '@/Components/LanguageChoice';
import { usePosCart, whatBlocksPayment } from '@/hooks/usePosCart';
import { LanguageCell, LanguageRoundBanner } from '@/Pages/Admin/Customers/Index';

/**
 * الزبونُ يُسأل لغتَه — والشاشةُ لا تفترضها ولا تدع الكاشير يمرّ بلا جواب.
 *
 * ═══ ما يحرسه الخادم وما لا يحرسه ═══
 *
 * الخادمُ يردّ عميلًا بلا لغةٍ وبيعةً لزبونٍ لا لغةَ له — وهذا محروسٌ في
 * PHP. لكنّ الردَّ وحدَه يترك الكاشيرَ أمام «رُدّت البيعة» بلا زرٍّ يُجيب
 * به: لو اختفى الزرّان من الشاشة، أو لو فُتح الدفعُ قبل الجواب، لا يقول
 * اختبارُ PHP شيئًا. فيُقاس هنا ما يراه الكاشير ويضغطه.
 */
describe('زرّا اللغة', () => {
    it('لا يُلوَّن أحدُهما حتى يُختار — فالفراغُ يُرى', () => {
        render(<LanguageChoice value="" onChange={() => {}} />);

        const radios = screen.getAllByRole('radio');
        expect(radios).toHaveLength(2);
        radios.forEach((r) => expect(r).toHaveAttribute('aria-checked', 'false'));
    });

    it('النقرُ يُبلّغ الرمزَ لا النصّ', async () => {
        const onChange = vi.fn();
        render(<LanguageChoice value="" onChange={onChange} />);

        await userEvent.click(screen.getByRole('radio', { name: 'English' }));
        expect(onChange).toHaveBeenCalledWith('en');

        await userEvent.click(screen.getByRole('radio', { name: 'العربية' }));
        expect(onChange).toHaveBeenCalledWith('ar');
    });

    it('في نموذجٍ يُقرأ بـFormData يحمل حقلًا مخفيًّا باسمه', () => {
        const { container, rerender } = render(<LanguageChoice name="language" value="" onChange={() => {}} />);
        const hidden = container.querySelector('input[name="language"]') as HTMLInputElement;
        expect(hidden).not.toBeNull();
        expect(hidden.value).toBe('');

        rerender(<LanguageChoice name="language" value="en" onChange={() => {}} />);
        expect((container.querySelector('input[name="language"]') as HTMLInputElement).value).toBe('en');
        expect(screen.getByRole('radio', { name: 'English' })).toHaveAttribute('aria-checked', 'true');
    });
});

/**
 * سلّةُ الصندوق: زبونٌ سُجّل قبل أن يُسأل لا تُتمّ بيعتُه حتى يُجاب عنه —
 * والجوابُ يُحمل مع البيعة لا في طلبٍ منفصل.
 */
describe('سلّةُ الصندوق وزبونٌ بلا لغة', () => {
    const onToast = vi.fn();
    let fetchMock: ReturnType<typeof vi.fn>;

    const customers = [
        { id: 1, name: 'قديم', label: 'قديم', phone: '99110001', points: 0, language: null },
        { id: 2, name: 'John', label: 'John', phone: '99110002', points: 0, language: 'en' },
    ];

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

    const bouquet = { key: 'p7', id: 7, name: 'باقة', price: 5, stock: 10 };

    beforeEach(() => {
        localStorage.clear();
        fetchMock = vi.fn(async () => ({ ok: true, status: 200, json: async () => ({ ok: true, invoice: 'INV-1', points_earned: 0 }) }));
        vi.stubGlobal('fetch', fetchMock);
    });
    afterEach(() => {
        vi.unstubAllGlobals();
        onToast.mockReset();
    });

    it('اختيارُ زبونٍ بلا لغةٍ يُشعل السؤال — والمُجابُ عنه لا', () => {
        const { result } = cart();

        act(() => result.current.selectCustomer('قديم', 1));
        expect(result.current.needsLanguage).toBe(true);

        act(() => result.current.selectCustomer('John', 2));
        expect(result.current.needsLanguage).toBe(false);

        act(() => result.current.selectCustomer('عميل نقدي', null));
        expect(result.current.needsLanguage).toBe(false);
    });

    it('لا تُرسَل بيعةٌ قبل الجواب — تُردّ برسالةٍ ولا تدخل الطابور', async () => {
        const { result } = cart();
        act(() => result.current.add(bouquet));
        act(() => result.current.selectCustomer('قديم', 1));

        let res: Awaited<ReturnType<typeof result.current.checkoutSale>> | undefined;
        await act(async () => {
            res = await result.current.checkoutSale('نقدي');
        });

        expect(res?.rejected).toBe(true);
        expect(res?.synced).toBe(false);
        expect(fetchMock).not.toHaveBeenCalled();
        expect(onToast).toHaveBeenCalledWith(expect.stringContaining('اختر لغة رسائل واتساب'), 'warning');
        expect(JSON.parse(localStorage.getItem('abadpos:pos:outbox') || '[]')).toHaveLength(0);
    });

    it('الجوابُ يطفئ السؤالَ ويُحمل مع البيعة', async () => {
        const { result } = cart();
        act(() => result.current.add(bouquet));
        act(() => result.current.selectCustomer('قديم', 1));

        act(() => result.current.setCustomerLanguage(1, 'en'));
        expect(result.current.needsLanguage).toBe(false);

        await act(async () => {
            await result.current.checkoutSale('نقدي');
        });

        expect(fetchMock).toHaveBeenCalledTimes(1);
        const body = JSON.parse((fetchMock.mock.calls[0] as [string, { body: string }])[1].body);
        expect(body.customer_id).toBe(1);
        expect(body.customer_language).toBe('en');
    });

    it('والزبونُ المُجابُ عنه من قبل يحمل لغتَه كما هي', async () => {
        const { result } = cart();
        act(() => result.current.add(bouquet));
        act(() => result.current.selectCustomer('John', 2));

        await act(async () => {
            await result.current.checkoutSale('نقدي');
        });

        const body = JSON.parse((fetchMock.mock.calls[0] as [string, { body: string }])[1].body);
        expect(body.customer_language).toBe('en');
    });
});

/** وزرُّ الدفع يُقال عنده السبب — لا بعد اختيار الوسيلة */
describe('ما يمنع فتحَ الدفع', () => {
    it('السلّةُ الفارغة أوّلًا، ثمّ الزبونُ بلا لغة، ثمّ لا شيء', () => {
        expect(whatBlocksPayment({ items: [], needsLanguage: false })).toBe('empty');
        expect(whatBlocksPayment({ items: [], needsLanguage: true })).toBe('empty');
        expect(whatBlocksPayment({ items: [1], needsLanguage: true })).toBe('language');
        expect(whatBlocksPayment({ items: [1], needsLanguage: false })).toBeNull();
    });
});

/**
 * جولةُ اللغة في شاشة العملاء: الصفُّ يُجاب منه، والشريطُ يقود إليهم.
 */
describe('جولةُ اللغة في شاشة العملاء', () => {
    it('من أُجيب عنه يرى شارةً لا زرّين', () => {
        render(<LanguageCell customer={{ id: 5, language: 'en' }} />);
        expect(screen.getByText('English')).toBeInTheDocument();
        expect(screen.queryAllByRole('radio')).toHaveLength(0);

        render(<LanguageCell customer={{ id: 6, language: 'ar' }} />);
        expect(screen.getByText('العربية')).toBeInTheDocument();
    });

    it('من لم يُجَب عنه يرى الزرّين — والنقرُ يحفظ على مسار الصفّ بلا فتح ملفّه', async () => {
        render(<LanguageCell customer={{ id: 9, language: null }} />);

        expect(screen.getAllByRole('radio')).toHaveLength(2);
        await userEvent.click(screen.getByRole('radio', { name: 'English' }));

        expect(router.post).toHaveBeenCalledWith(
            '/admin.customers.language/9',
            { language: 'en' },
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('الشريطُ يعدّهم ويحوّل القائمةَ إليهم — ويختفي حين يصيرون صفرًا', async () => {
        const { unmount } = render(<LanguageRoundBanner unlanguaged={7} filtering={false} />);
        expect(screen.getByText(/7 من العملاء لم تُحدَّد لغة/)).toBeInTheDocument();

        await userEvent.click(screen.getByRole('button', { name: 'حدّدها الآن' }));
        expect(router.get).toHaveBeenCalledWith('/admin.customers.index', { missing: 'language' }, expect.anything());
        unmount();

        render(<LanguageRoundBanner unlanguaged={7} filtering />);
        expect(screen.getByRole('button', { name: 'عرض الكل' })).toBeInTheDocument();
        cleanupAll();

        const { container } = render(<LanguageRoundBanner unlanguaged={0} filtering={false} />);
        expect(container).toBeEmptyDOMElement();
    });
});
