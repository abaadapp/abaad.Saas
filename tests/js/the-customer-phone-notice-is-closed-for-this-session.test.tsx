import { fireEvent, render, screen } from '@testing-library/react';
import { readFileSync } from 'node:fs';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { pageProps } from './setup';

vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));
vi.mock('@/Components/StatGrid', () => ({ default: () => null }));

// ‏‏`optimistic(...).post(...)` و`replaceProp` — ما تناديه اللوحةُ عند الإغلاق
const seen = vi.hoisted(() => ({ calls: [] as unknown[][] }));
vi.mock('@inertiajs/react', async () => {
    const actual = await vi.importActual<Record<string, unknown>>('@inertiajs/react');
    const { pageProps: props } = await import('./setup');
    const router = {
        get: (...a: unknown[]) => seen.calls.push(['get', ...a]),
        on: () => () => {},
        optimistic: (cb: () => unknown) => {
            seen.calls.push(['optimistic', cb()]);
            return router;
        },
        post: (...a: unknown[]) => seen.calls.push(['post', ...a]),
        replaceProp: (...a: unknown[]) => seen.calls.push(['replaceProp', ...a]),
    };

    return { ...actual, usePage: () => ({ props, url: '/', component: 'Test' }), router, Head: () => null };
});

const { CustomerPhoneReviewBanner } = await import('@/Components/CustomerPhoneReview');
const { default: Dashboard } = await import('@/Pages/Admin/Dashboard');

const en = JSON.parse(readFileSync('lang/en.json', 'utf8')) as Record<string, string>;

const dashboard = (customerPhoneReview: number, translations: Record<string, string> = {}) => {
    for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    Object.assign(pageProps, {
        translations,
        context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 }, businessName: 'متجري' },
        stats: [], statCatalog: [], recentOrders: [], topProducts: [], topEmployees: [],
        salesSeries: { labels: [], full: [], data: [], counts: [] },
        paymentDistribution: { labels: [], series: [] },
        customerPhoneReview,
    });
};

beforeEach(() => {
    seen.calls.length = 0;
});

describe('تنبيهُ بيانات العملاء في لوحة التحكم', () => {
    it('زرُّ الإغلاق فيه، ويُخفيه لحظتَه ويسأل الخادمَ أن يحفظه', () => {
        dashboard(4);
        const local = vi.spyOn(Storage.prototype, 'setItem');
        render(<Dashboard />);

        fireEvent.click(screen.getByRole('button', { name: 'إغلاق التنبيه' }));

        const [optimistic, post, replaced] = seen.calls;
        expect(optimistic).toEqual(['optimistic', { customerPhoneReview: 0 }]);
        expect(post.slice(0, 3)).toEqual(['post', '/admin.dashboard.phoneReview.dismiss', {}]);
        expect(post[3]).toMatchObject({ preserveScroll: true });
        // ومن غادر قبل الردّ ثمّ رجع بزرّ «رجوع» يقرأ ما في سجلّ المتصفّح
        expect(replaced).toEqual(['replaceProp', 'customerPhoneReview', 0]);
        // ولا يُحفظ في المتصفّح
        expect(local).not.toHaveBeenCalled();
        local.mockRestore();
    });

    it('وردٌّ ساقطٌ لا يفتح نافذةَ خطأ — يعود التنبيهُ وحده', () => {
        dashboard(4);
        render(<Dashboard />);
        fireEvent.click(screen.getByRole('button', { name: 'إغلاق التنبيه' }));

        const options = seen.calls[1][3] as { onHttpException: () => unknown; onNetworkError: () => unknown };
        for (const fall of [options.onHttpException, options.onNetworkError]) {
            seen.calls.length = 0;
            expect(fall()).toBe(false);
            expect(seen.calls).toEqual([['replaceProp', 'customerPhoneReview', 4]]);
        }
    });

    it('وردٌّ يقول إنّه لم يُحفظ يُعاد سؤالُه — ثلاثًا لا أكثر', () => {
        dashboard(4);
        render(<Dashboard />);
        fireEvent.click(screen.getByRole('button', { name: 'إغلاق التنبيه' }));

        const posts = () => seen.calls.filter(([name]) => name === 'post');
        const reply = (customerPhoneReview: number) =>
            (posts().at(-1)![3] as { onSuccess: (page: unknown) => void }).onSuccess({ props: { customerPhoneReview } });

        // حُفظ: لا يُعاد
        reply(0);
        expect(posts()).toHaveLength(1);

        for (const n of [2, 3, 3]) {
            reply(4);
            expect(posts()).toHaveLength(n);
        }
    });

    it('و«مراجعة العملاء» تفتح المتأثّرين كما كانت', () => {
        dashboard(4);
        render(<Dashboard />);
        fireEvent.click(screen.getByRole('button', { name: 'مراجعة العملاء' }));

        expect(seen.calls).toEqual([['get', '/admin.customers.index', { missing: 'phone' }, { preserveState: false }]]);
    });

    it('ولا يظهر هو ولا زرُّه والعددُ صفر', () => {
        dashboard(0);
        render(<Dashboard />);

        expect(screen.queryByTestId('customer-phone-review')).toBeNull();
        expect(screen.queryByRole('button', { name: 'إغلاق التنبيه' })).toBeNull();
    });

    it('وبالإنجليزيّة بنصوصها', () => {
        dashboard(4, en);
        render(<Dashboard />);

        expect(screen.getByTestId('customer-phone-review')).toHaveTextContent('Customer data notice');
        expect(screen.getByRole('button', { name: 'Review customers' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Dismiss notice' })).toHaveAttribute('title', 'Dismiss notice');
    });

    it('ومكانُه منطقيٌّ لا يمينٌ ولا يسار، ويكبر تحت الإصبع', () => {
        dashboard(4);
        render(<Dashboard />);

        const close = screen.getByRole('button', { name: 'إغلاق التنبيه' });
        const box = screen.getByTestId('customer-phone-review');
        for (const c of ['absolute', 'end-2', 'top-2', 'touch:size-11']) expect(close).toHaveClass(c);
        for (const c of ['relative', 'pe-12']) expect(box).toHaveClass(c);
        expect(close.className + box.className).not.toMatch(/(^|\s)(touch:)?(left|right|pl|pr|ml|mr)-/);
        expect(close).toHaveAttribute('type', 'button');
    });
});

describe('وقسمُ العملاء كما كان', () => {
    it('بلا زرّ إغلاقٍ ولا مكانٍ له — في القائمة وفي المُرشِّح', () => {
        const { rerender } = render(<CustomerPhoneReviewBanner count={4} />);
        expect(screen.getAllByRole('button')).toHaveLength(1);
        expect(screen.getByTestId('customer-phone-review')).not.toHaveClass('relative');

        rerender(<CustomerPhoneReviewBanner count={4} filtering />);
        expect(screen.getAllByRole('button')).toHaveLength(1);
        expect(screen.getByRole('button', { name: 'عرض الكل' })).toBeInTheDocument();

        // ولا تمرّره صفحتُه
        expect(readFileSync('resources/js/Pages/Admin/Customers/Index.tsx', 'utf8')).not.toMatch(/CustomerPhoneReviewBanner[^>]*onDismiss/);
    });
});
