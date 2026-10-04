import { render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { pageProps } from './setup';

vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

const { default: Reviews } = await import('@/Pages/Admin/Marketing/Reviews');

/**
 * شاشةُ التقييمات تقول عن أيّ صنفٍ كُتب الرأي وفي أيّ طلب.
 *
 * رأيُ الصنف يُوسَم «رأيٌ في الصنف · شراء موثّق» ويحمل رقمَ طلبه، ورأيُ الطلب
 * يبقى كما كان بلا وسم. ومرشِّحُ النوع حاضر. وقواعدُ النشر والردّ والمتجر
 * في الخادم: `AProductIsReviewedByWhoeverBoughtItTest`.
 */
const review = (over: Record<string, unknown>) => ({
    id: 1,
    author: 'أحمد',
    product: null,
    rating: 5,
    comment: 'جميل',
    status: 'معلّق',
    reply: null,
    replied_at: null,
    at: '2026-10-04',
    byCustomer: true,
    kind: 'order',
    order: null,
    verified: false,
    ...over,
});

const draw = (reviews: Record<string, unknown>[], filters: Record<string, string> = {}) => {
    for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    Object.assign(pageProps, {
        translations: {},
        dir: 'rtl',
        auth: { abilities: ['marketing'], mayActions: [] },
        reviews,
        pagination: { current_page: 1, last_page: 1, per_page: 20, total: reviews.length, from: 1, to: reviews.length },
        filters,
        sorts: [],
        products: [],
        customers: [],
        customersCapped: false,
        summary: { count: reviews.length, pending: reviews.length, published: 0, average: 0 },
    });

    render(<Reviews />);
};

describe('شاشةُ التقييمات', () => {
    beforeEach(() => draw([]));

    it('رأيُ الصنف يقول صنفَه وطلبَه وأنّه شراءٌ موثّق', () => {
        draw([review({ id: 7, kind: 'product', product: 'باقة ورد — كبير', order: 'INV-000123', verified: true })]);

        const mark = screen.getByTestId('review-7-product');
        expect(mark).toHaveTextContent('رأيٌ في الصنف · شراء موثّق');
        const cell = mark.parentElement as HTMLElement;
        expect(within(cell).getByText('باقة ورد — كبير')).toBeInTheDocument();
        expect(within(cell).getByText('INV-000123')).toBeInTheDocument();
    });

    it('ورأيُ الطلب بلا وسم صنف — كما كان', () => {
        draw([review({ id: 8, kind: 'order', order: 'INV-000124' })]);

        expect(screen.queryByTestId('review-8-product')).toBeNull();
        expect(screen.getByText('INV-000124')).toBeInTheDocument();
    });

    // والمرشِّحُ في القائمة المنسدلة — ومختارُه يُقرأ شريحةً باسمه
    it('ومرشِّحُ النوع يفرّق بين رأي الطلب ورأي الصنف', () => {
        draw([review({ id: 9, kind: 'product', verified: true })], { type: 'product' });

        expect(screen.getByText(/عن صنف/)).toBeInTheDocument();
    });
});
