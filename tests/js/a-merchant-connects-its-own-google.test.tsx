import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import GoogleCard, { type BusinessGoogle } from '@/Pages/Platform/Businesses/partials/GoogleCard';
import { pageProps } from './setup';

vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

const { default: GoogleBusinessPage } = await import('@/Pages/Admin/Integrations/GoogleBusiness');
const { default: GoogleMapsPage } = await import('@/Pages/Admin/Integrations/Google');

/**
 * كلُّ تاجرٍ يربط Google بنفسه — والشاشةُ تقول له ما يفعل هو، لا «راجعنا».
 *
 * والقرارُ في الخادم: `AMerchantConnectsItsOwnGoogleTest`. وهنا ما يُرى:
 * إذنٌ انقطع يُعاد بزرّ، ومنصّةٌ لم تُهيّئ العميل تقول ذلك بلا أن تطلب من
 * التاجر مراسلةَ أحد، واختبارُ المفتاح، وتنبيهُ التقييد بالنطاق، وبطاقةُ
 * المنصّة بلا مفتاحٍ ولا رمز.
 */
const reset = (over: Record<string, unknown>, en = false) => {
    for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    Object.assign(pageProps, {
        translations: en
            ? {
                  'تقييمات Google': 'Google reviews',
                  'انقطع ربط حساب Google': 'Your Google account was disconnected',
                  'إعادة ربط حساب Google': 'Reconnect Google account',
                  'اختبار الاتصال': 'Test connection',
              }
            : {},
        locale: en ? 'en' : 'ar',
        dir: en ? 'ltr' : 'rtl',
        auth: { abilities: [], mayActions: [] },
        errors: {},
        ...over,
    });
};

const GBP = {
    configured: true,
    connected: false,
    reconnect: false,
    lastError: null,
    account: null,
    branches: [],
    reviews: [],
    alerts: { enabled: true, threshold: 2 },
};

afterEach(() => vi.restoreAllMocks());

describe('تقييمات Google — يربطها التاجر بنفسه', () => {
    it('منصّةٌ لم تُهيّئ العميل لا تطلب من التاجر أن يراسل أحدًا', () => {
        reset({ ...GBP, configured: false });
        const { container } = render(<GoogleBusinessPage />);

        expect(container.textContent).toContain('غير متاحة على المنصّة بعد');
        expect(container.textContent).not.toContain('راجعنا');
        expect(screen.queryByTestId('gbp-connect')).toBeNull();
    });

    it('من لم يربط يرى زرَّ الربط بنفسه', () => {
        reset(GBP);
        render(<GoogleBusinessPage />);

        const link = screen.getByTestId('gbp-connect');
        expect(link.getAttribute('href')).toContain('googleBusiness.connect');
        expect(link.textContent).toContain('ربط حساب Google Business');
    });

    it('إذنٌ انقطع يُعرض «انقطع» وزرُّ إعادة الربط — لا «لم يُربط بعد»', () => {
        reset({ ...GBP, reconnect: true, lastError: 'انتهت صلاحية الإذن — أعِد ربط حساب Google.' });
        const { container } = render(<GoogleBusinessPage />);

        expect(container.textContent).toContain('انقطع ربط حساب Google');
        expect(container.textContent).not.toContain('لم يُربط حساب Google بعد');
        expect(screen.getByTestId('gbp-connect').textContent).toContain('إعادة ربط حساب Google');
        expect(container.textContent).toContain('انتهت صلاحية الإذن');
    });

    it('مربوطٌ تعثّر سحبُه يجد زرَّ إعادة الربط بجوار الخطأ', () => {
        reset({
            ...GBP,
            connected: true,
            account: { email: 'o@gmail.test', accountName: 'accounts/1', linkedAt: null, syncedAt: null, error: 'رفضت Google الطلب.' },
        });
        render(<GoogleBusinessPage />);

        expect(screen.getByTestId('gbp-reconnect').getAttribute('href')).toContain('googleBusiness.connect');
    });

    it('وبالإنجليزية', () => {
        reset({ ...GBP, reconnect: true }, true);
        const { container } = render(<GoogleBusinessPage />);

        expect(container.textContent).toContain('Your Google account was disconnected');
        expect(screen.getByTestId('gbp-connect').textContent).toContain('Reconnect Google account');
    });
});

const MAPS = {
    settings: {},
    link: { place_id: null, place_name: null, branch_id: null, on_receipt: false, review_url: null, place_url: null },
    keyHint: '••••A1b2',
    enabled: true,
    serverIp: '203.0.113.7',
    google: { state: 'unlinked', error: null, fetched_at: null, place: null },
    internal: 0,
    readiness: { connected: true, ready: false, steps: [] },
    branches: [],
    searchMin: 3,
};

describe('خرائط Google — مفتاحُ التاجر', () => {
    it('اختبارُ الاتّصال يسأل خادمنا ويقول النتيجة — والمفتاحُ لا يُعرض', async () => {
        reset(MAPS);
        const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(
            new Response(JSON.stringify({ ok: true, message: 'المفتاح يعمل — Places API (New) تقبله.' }), { status: 200 }),
        );

        const { container } = render(<GoogleMapsPage />);

        await act(async () => {
            fireEvent.click(screen.getByTestId('google-key-test-button'));
        });

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(String(fetchMock.mock.calls[0][0])).toContain('google.key.test');
        expect(screen.getByTestId('google-key-test').dataset.state).toBe('ok');
        expect(container.textContent).toContain('••••A1b2');
    });

    it('رفضُ Google يُقال بسببه', async () => {
        reset(MAPS);
        vi.spyOn(globalThis, 'fetch').mockResolvedValue(
            new Response(JSON.stringify({ ok: false, message: 'الفوترة غير مفعّلة في مشروعك على Google Cloud' }), { status: 200 }),
        );

        render(<GoogleMapsPage />);

        await act(async () => {
            fireEvent.click(screen.getByTestId('google-key-test-button'));
        });

        expect(screen.getByTestId('google-key-test').dataset.state).toBe('error');
        expect(screen.getByTestId('google-key-test').textContent).toContain('الفوترة غير مفعّلة');
    });

    it('الدليلُ يقول: لا تقييدَ بنطاق الموقع — المفتاحُ من الخادم وحده', () => {
        reset({ ...MAPS, keyHint: null, enabled: false });
        render(<GoogleMapsPage />);

        const note = screen.getByTestId('google-no-referrer').textContent ?? '';
        expect(note).toContain('HTTP referrers');
        expect(note).toContain('نطاقك الخاص');
        expect(screen.getByTestId('google-server-ip').textContent).toContain('203.0.113.7');
    });

    it('بلا مفتاحٍ محفوظٍ ولا ملصوق لا زرَّ اختبار', () => {
        reset({ ...MAPS, keyHint: null, enabled: false });
        render(<GoogleMapsPage />);

        expect(screen.queryByTestId('google-key-test-button')).toBeNull();
    });
});

describe('بطاقةُ المنصّة — حالٌ بلا أسرار', () => {
    const DATA: BusinessGoogle = {
        maps: { hasKey: true, enabled: true, linkedBranches: 1, branches: 2 },
        business: { configured: true, connected: false, reconnect: true, locations: 0, syncedAt: null, error: true },
    };

    it('تقول نعم ولا وأين انقطع — بلا حقلٍ ولا زرّ', () => {
        reset({});
        const { container } = render(<GoogleCard data={DATA} />);

        const text = container.textContent ?? '';
        expect(text).toContain('محفوظ');
        expect(text).toContain('1 / 2');
        expect(text).toContain('انقطع الربط');
        expect(container.querySelector('input')).toBeNull();
        expect(container.querySelector('button')).toBeNull();
        expect(text).not.toMatch(/AIza|••••/);
    });
});
