import { router } from '@inertiajs/react';
import { act, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import AppHome, { AppLinks } from '@/Pages/Admin/AppHome';

/**
 * `/app` — شاشةُ التطبيق المثبَّت: أرقامُ اليوم، وأزرارُ ما أرسله الخادم، و«النظام كاملًا».
 *
 * والتطبيقُ المثبَّت على iPhone لا يُسحب للتحديث: فالأرقامُ تُجدَّد حين يعود
 * إلى الشاشة وبالزرّ — والبطاقاتُ وحدها.
 */

const cards = [
    { label: 'مبيعات اليوم', value: '12.500 ر.ع', icon: 'shopping-bag', color: 'primary' },
    { label: 'مقبوضات اليوم', value: '7.000 ر.ع', icon: 'wallet', color: 'success' },
];

const links = [
    { key: 'orders', label: 'الطلبات', url: '/admin/orders' },
    { key: 'paymentLink', label: 'رابط دفع', url: '/admin/finance/payment-links' },
];

describe('the installed app opens on today', () => {
    beforeEach(() => vi.mocked(router.reload).mockClear());

    it('shows today\'s numbers, the buttons it was sent, and the way to the full system', () => {
        render(<AppHome cards={cards} links={links} fullUrl="/admin/dashboard" />);

        expect(screen.getByText('12.500 ر.ع')).toBeInTheDocument();
        expect(screen.getByText('7.000 ر.ع')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'الطلبات' })).toHaveAttribute('href', '/admin/orders');
        expect(screen.getByRole('link', { name: 'رابط دفع' })).toHaveAttribute('href', '/admin/finance/payment-links');
        expect(screen.getByRole('link', { name: 'فتح النظام كاملًا' })).toHaveAttribute('href', '/admin/dashboard');
    });

    it('draws no button it was not sent', () => {
        const { container } = render(<AppLinks links={[]} />);

        expect(container.querySelector('[data-app-links]')).toBeNull();
        expect(screen.queryByRole('link')).toBeNull();
    });

    it('refreshes only the cards — by the button and when the app comes back to the screen', () => {
        render(<AppHome cards={cards} links={links} fullUrl="/admin/dashboard" />);

        act(() => screen.getByRole('button', { name: 'تحديث' }).click());
        expect(router.reload).toHaveBeenLastCalledWith({ only: ['cards'] });

        vi.mocked(router.reload).mockClear();
        Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => 'hidden' });
        act(() => document.dispatchEvent(new Event('visibilitychange')));
        expect(router.reload).not.toHaveBeenCalled();

        Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => 'visible' });
        act(() => document.dispatchEvent(new Event('visibilitychange')));
        expect(router.reload).toHaveBeenCalledWith({ only: ['cards'] });
    });
});
