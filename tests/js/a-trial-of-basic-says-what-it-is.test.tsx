import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { pageProps } from './setup';

vi.mock('@/Layouts/AdminLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <div>{children}</div> }));

const { default: SubscriptionBanner } = await import('@/Components/SubscriptionBanner');
const { default: MerchantAddons } = await import('@/Pages/Admin/Subscription/Addons');


/**
 * تجربةُ الأساسيّة تقول ما هي — في اللوحة، وفي «إضافات متجرك».
 *
 * ═══ ما يُحرس ═══
 *
 *   - الشريطُ يقول اسمَ الباقة و«تجربة مجانية» ويومَ انتهائها من أوّل يوم، لا في أسبوعها الأخير وحده.
 *   - ويقول إنّ نقطة البيع والإضافات تُفعَّل عبر فريق أبعاد.
 *   - المشتركُ الذي ليس في تجربة لا يرى الشريطَ قبل أسبوعه الأخير، كما كان.
 *   - في التجربة لا زرَّ طلبٍ ولا تجديد: الإضافةُ تُعرض ويُستفسر عنها.
 *
 * والخادمُ هو الحارس (`ATrialOfBasicIsBasicWithoutThePosTest`) — فهذا ما يُرى.
 */
const subscription = (over: Record<string, unknown> = {}) => ({
    endsAt: '2026-11-16', daysLeft: 14, graceLeft: null, trial: true, plan: 'الباقة الأساسية', trialWithoutPos: true, ...over,
});

const currency = { code: 'OMR', symbol: 'ر.ع', decimals: 3 };

const addons = (over: Record<string, unknown> = {}) => ({
    policy: 'addons_v1',
    trial: true,
    plan: { name: 'الباقة الأساسية', price: { monthly: 9.9, yearly: 99 } },
    subscription: { status: 'نشط', live: true, ends_at: '2026-11-16' },
    included: [],
    available: [
        { code: 'pos', name: 'نظام الكاشير ونقاط البيع POS', description: null, pricing: 'paid', unit: 'store', counted: false, monthly_price: 9.9, yearly_price: 99, active: false, pending: false },
    ],
    active: [{ id: 7, addon: { name: 'المتجر الإلكتروني', code: 'website', unit: 'store' }, quantity: 1, ends_at: '2026-11-10', source: 'purchase', cycle: 'monthly', unit_price: 12.9, renews: true }],
    orders: [],
    addons_total: { monthly: 0, yearly: 0 },
    may_request: false,
    mayBuy: true,
    currency,
    ...over,
});

describe('شريطُ التجربة في اللوحة', () => {
    it('يقول الباقةَ و«تجربة مجانية» ويومَ انتهائها من أوّل يوم', () => {
        Object.assign(pageProps, { translations: {}, context: { subscription: subscription() } });
        render(<SubscriptionBanner />);

        const banner = screen.getByTestId('trial-banner');
        expect(screen.getByTestId('trial-plan')).toHaveTextContent('الباقة الأساسية');
        expect(banner).toHaveTextContent('تجربة مجانية');
        expect(screen.getByTestId('trial-ends')).toHaveTextContent('2026-11-16');
        expect(banner).toHaveTextContent('بقي 14 يومًا');
        expect(screen.getByTestId('trial-without-pos')).toHaveTextContent('نقطة البيع والإضافات غير مشمولة في التجربة');
    });

    it('واليومُ الأخير يُقال بلفظه', () => {
        Object.assign(pageProps, { translations: {}, context: { subscription: subscription({ daysLeft: 0, trialWithoutPos: false }) } });
        render(<SubscriptionBanner />);

        expect(screen.getByTestId('trial-banner')).toHaveTextContent('اليوم آخر يوم');
        expect(screen.queryByTestId('trial-without-pos')).toBeNull();
    });

    it('والمشتركُ خارج التجربة لا يرى شيئًا قبل أسبوعه الأخير — كما كان', () => {
        Object.assign(pageProps, { translations: {}, context: { subscription: subscription({ trial: false, daysLeft: 40 }) } });
        const { container } = render(<SubscriptionBanner />);

        expect(container).toBeEmptyDOMElement();
    });
});

describe('«إضافات متجرك» في التجربة', () => {
    it('تُعرض الإضافةُ ويُستفسر عنها — لا زرَّ طلبٍ ولا تجديد', () => {
        Object.assign(pageProps, { translations: {}, auth: { abilities: ['settings'], mayActions: [] }, ...addons() });
        render(<MerchantAddons />);

        expect(screen.getByTestId('trial-addons-note')).toHaveTextContent('أنت في التجربة المجانية');
        expect(screen.queryByTestId('request-pos')).toBeNull();
        expect(screen.getByTestId('ask-pos')).toHaveTextContent('استفسر عبر فريق أبعاد');
        expect(screen.queryByRole('button', { name: /التجديد/ })).toBeNull();
    });

    it('وبعد الاشتراك يعود الطلبُ لصاحب النشاط', () => {
        Object.assign(pageProps, { translations: {}, auth: { abilities: ['settings'], mayActions: [] }, ...addons({ trial: false, may_request: true }) });
        render(<MerchantAddons />);

        expect(screen.queryByTestId('trial-addons-note')).toBeNull();
        expect(screen.getByTestId('request-pos')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /إيقاف التجديد/ })).toBeInTheDocument();
    });
});
