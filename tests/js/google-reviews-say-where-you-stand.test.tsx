import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { router } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import GoogleReviewsStep, { type ReviewsManagement } from '@/Pages/Admin/Integrations/partials/GoogleReviewsStep';
import { pageProps } from './setup';

vi.mock('@/Layouts/AdminLayout', () => ({ default: ({ children }: { children: ReactNode }) => <div>{children}</div> }));

const { default: GoogleBusinessPage } = await import('@/Pages/Admin/Integrations/GoogleBusiness');

/**
 * إدارةُ تقييمات Google — كلُّ حالٍ تُقال بما يُفعل فيها.
 *
 * مفتاحُ الخرائط لا يقوم مقام الإذن، وما عند أبعاد لا يُطلب من التاجر، وتعثُّرُ
 * فرعٍ يُقال عليه وحده. والقرارُ في الخادم: `EveryMerchantRunsItsOwnGoogleReviewsTest`.
 */
const reset = (over: Record<string, unknown>, en = false) => {
    for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    Object.assign(pageProps, {
        translations: en
            ? {
                  'إدارة تقييمات Google': 'Google review management',
                  'تفعيل إدارة التقييمات': 'Enable review management',
                  'غير مفعّلة': 'Not enabled',
                  'بانتظار اعتماد Google': 'Waiting for Google approval',
              }
            : {},
        locale: en ? 'en' : 'ar',
        dir: en ? 'ltr' : 'rtl',
        auth: { abilities: [], mayActions: [] },
        errors: {},
        ...over,
    });
};

const STEP: ReviewsManagement = { state: 'off', maps: true, locations: 0, branches: 2 };

const BRANCH = {
    id: 1,
    name: 'الخوض',
    location: 'locations/1',
    linked: true,
    reviews: 60,
    placeId: 'place-mine',
    syncedAt: '2026-11-03T10:00:00Z',
    syncError: null,
};

const PAGE = {
    configured: true,
    state: 'ready',
    connected: true,
    reconnect: false,
    lastError: null,
    account: { email: null, accountName: 'accounts/1', linkedAt: null, syncedAt: null, error: null },
    branches: [BRANCH],
    reviews: [],
    alerts: { enabled: true, threshold: 2 },
};

afterEach(() => vi.restoreAllMocks());

describe('خطوةُ إدارة التقييمات في صفحة الخرائط', () => {
    it('الخرائطُ مربوطةٌ بلا إذن: زرُّ تفعيلٍ صريح يقول إنّ المفتاح لا يكفي', () => {
        reset({});
        const { container } = render(<GoogleReviewsStep data={STEP} />);

        expect(container.textContent).toContain('غير مفعّلة');
        expect(container.textContent).toContain('مفتاح الخرائط يعرض المعدّل فقط');
        expect(screen.getByTestId('gbp-step-connect').getAttribute('href')).toContain('googleBusiness.connect');
    });

    it('انتهى الإذن: يعيده التاجر بنفسه', () => {
        reset({});
        const { container } = render(<GoogleReviewsStep data={{ ...STEP, state: 'reconnect' }} />);

        expect(container.textContent).toContain('انتهى الإذن');
        expect(screen.getByTestId('gbp-step-connect').textContent).toContain('إعادة ربط حساب Google');
    });

    it('لم تعتمد Google: لا يُطلب من التاجر شيء ولا يُعرض زرُّ إذن', () => {
        reset({});
        const { container } = render(<GoogleReviewsStep data={{ ...STEP, state: 'api_blocked' }} />);

        expect(container.textContent).toContain('بانتظار اعتماد Google');
        expect(container.textContent).toContain('ليس عليك شيء');
        expect(screen.queryByTestId('gbp-step-connect')).toBeNull();
    });

    it('غير متاحة على المنصّة: لا زرَّ يقود إلى خطأ', () => {
        reset({});
        const { container } = render(<GoogleReviewsStep data={{ ...STEP, state: 'unavailable' }} />);

        expect(container.textContent).toContain('غير متاحة بعد');
        expect(container.querySelector('a')).toBeNull();
    });

    it('جاهز: يقول كم فرعًا رُبط ويقود إلى الإدارة', () => {
        reset({});
        const { container } = render(<GoogleReviewsStep data={{ ...STEP, state: 'ready', locations: 2 }} />);

        expect(container.textContent).toContain('جاهز للعمل');
        expect(container.textContent).toContain('2 من 2');
        expect(screen.queryByTestId('gbp-step-connect')).toBeNull();
    });

    it('بالإنجليزية', () => {
        reset({}, true);
        const { container } = render(<GoogleReviewsStep data={STEP} />);

        expect(container.textContent).toContain('Google review management');
        expect(screen.getByTestId('gbp-step-connect').textContent).toContain('Enable review management');
    });
});

describe('شاشةُ التقييمات', () => {
    it('عدمُ الاعتماد يُقال بلا زرّ «أعد الربط»', () => {
        reset({
            ...PAGE,
            state: 'api_blocked',
            account: { ...PAGE.account, error: 'لم تعتمد Google بعدُ وصول أبعاد' },
        });
        render(<GoogleBusinessPage />);

        expect(screen.getByTestId('gbp-api-blocked').textContent).toContain('ليس عليك شيء');
        expect(screen.queryByTestId('gbp-reconnect')).toBeNull();
    });

    it('تعثُّرُ فرعٍ يُقال عليه وحده', () => {
        reset({
            ...PAGE,
            branches: [BRANCH, { ...BRANCH, id: 2, name: 'المعبيلة', syncError: 'لم تجد Google هذا الموقع أو التقييم.' }],
        });
        render(<GoogleBusinessPage />);

        const errors = screen.getAllByTestId('gbp-branch-error');
        expect(errors).toHaveLength(1);
        expect(errors[0].textContent).toContain('لم تجد Google');
    });

    it('التقييماتُ صفحاتٌ: يُعرض العدُّ كلُّه وينتقل بالخادم', () => {
        reset({
            ...PAGE,
            pagination: { current_page: 2, last_page: 3, total: 60, prev_page_url: '/p?page=1', next_page_url: '/p?page=3' },
            reviews: [{ id: 1, branch: 'الخوض', rating: 5, comment: 'جيّد', author: 'زبونة', photo: null, at: null, reply: null, repliedAt: null }],
        });
        const get = vi.spyOn(router, 'get');
        const { container } = render(<GoogleBusinessPage />);

        expect(container.textContent).toContain('صفحة 2 من 3');
        fireEvent.click(screen.getByRole('button', { name: 'التالي' }));
        expect(get).toHaveBeenCalledWith('/p?page=3', {}, expect.anything());
    });

    it('المرشِّحُ في الخادم لا في الصفحة', () => {
        reset({ ...PAGE, pagination: { current_page: 1, last_page: 1, total: 0, prev_page_url: null, next_page_url: null } });
        const get = vi.spyOn(router, 'get');
        render(<GoogleBusinessPage />);

        fireEvent.click(screen.getByLabelText('بلا ردّ فقط'));

        expect(get).toHaveBeenCalledWith(expect.anything(), { unanswered: 1 }, expect.anything());
    });

    it('موقعٌ يطابق الفرع يُعلَّم، وغيرُ المطابق يُسأل عنه قبل الربط', async () => {
        reset(PAGE);
        vi.stubGlobal('fetch', vi.fn(async () => ({
            json: async () => ({
                ok: true,
                locations: [
                    { name: 'locations/2', title: 'آخر', address: '', account: 'accounts/2', accountTitle: 'حساب ب', placeId: 'place-other' },
                    { name: 'locations/1', title: 'فرعي', address: '', account: 'accounts/1', accountTitle: 'حساب أ', placeId: 'place-mine' },
                ],
            }),
        })));
        const post = vi.spyOn(router, 'post');
        render(<GoogleBusinessPage />);

        fireEvent.click(screen.getByRole('button', { name: 'تغيير الموقع' }));
        await act(async () => {
            fireEvent.click(screen.getByRole('button', { name: 'اقرأ مواقعي من Google' }));
        });

        await waitFor(() => expect(screen.getByTestId('gbp-location-match').textContent).toContain('يطابق هذا الفرع'));
        expect(screen.getByText('حساب ب')).toBeTruthy();

        const buttons = screen.getAllByRole('button', { name: 'اختيار هذا الموقع' });
        // المطابقُ أوّلًا، وغيرُ المطابق ثانيًا
        fireEvent.click(buttons[1]);

        expect(post).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: 'ربطه رغم ذلك' }));

        expect(post).toHaveBeenCalledWith(
            expect.anything(),
            { location: 'locations/2', account: 'accounts/2', confirmed: true },
            expect.anything(),
        );
    });
});
