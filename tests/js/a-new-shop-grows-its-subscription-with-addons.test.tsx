import { fireEvent, render, screen, within } from '@testing-library/react';
import { router } from '@inertiajs/react';
import { router as core } from '@inertiajs/core';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { pageProps } from './setup';

vi.mock('@/Layouts/AdminLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <div>{children}</div> }));
vi.mock('@/Layouts/PlatformLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <div>{children}</div> }));

const { default: MerchantAddons } = await import('@/Pages/Admin/Subscription/Addons');
const { default: PlatformAddons } = await import('@/Pages/Platform/Subscriptions/Addons');

/**
 * إضافاتُ الاشتراك على الشاشة — التاجرُ يرى ويطلب، وصاحبُ المنصّة يدير ويُطلق.
 *
 * ═══ ما يُحرس ═══
 *
 *   - متجرُ السياسة السابقة يُقال له إنّ اشتراكه كما هو — ولا زرَّ شراء.
 *   - المشمولةُ تُرى مشمولةً لا تُشترى، والمتاحةُ بسعرها، و«حسب الطلب» بلا سعر.
 *   - زرُّ الطلب لصاحب النشاط وحده، والطلبُ يُرسل رمزَ الإضافة ودورتَها لا سعرَها.
 *   - التفعيلُ (كان «الإطلاق») لا يُضغط وشرطُه ناقص، ويقول ما ينقصه.
 *
 * والخادمُ هو الحارس (`TheOwnerAloneBuysAddonsForHisOwnShopTest`) — فهذا ما يُرى.
 */
const currency = { code: 'OMR', symbol: 'ر.ع', decimals: 3 };

const merchant = (over: Record<string, unknown> = {}) => ({
    policy: 'addons_v1',
    plan: { name: 'الباقة الأساسية', price: { monthly: 4.9, yearly: 49 } },
    included: [],
    available: [
        { code: 'pos', name: 'نظام الكاشير ونقاط البيع POS', description: null, pricing: 'paid', unit: 'store', counted: false, monthly_price: 9.9, yearly_price: 99, active: false, pending: false },
        { code: 'whatsapp_own_number', name: 'ربط واتساب برقم المتجر', description: null, pricing: 'quote', unit: 'store', counted: false, monthly_price: null, yearly_price: null, active: false, pending: false },
    ],
    active: [],
    orders: [],
    addons_total: { monthly: 0, yearly: 0 },
    may_request: true,
    mayBuy: true,
    currency,
    ...over,
});

const showMerchant = (props: Record<string, unknown>) => {
    Object.assign(pageProps, { translations: {}, auth: { abilities: ['settings'], mayActions: [] }, ...props });
    render(<MerchantAddons />);
};

afterEach(() => {
    vi.mocked(router.post).mockClear();
    vi.mocked(router.visit).mockClear();
});

describe('«إضافات متجرك»', () => {
    it('متجرُ السياسة السابقة: اشتراكُك كما هو — ولا زرَّ شراء', () => {
        showMerchant(merchant({ policy: 'legacy', available: [], may_request: false }));

        expect(screen.getByTestId('legacy-policy-note')).toHaveTextContent('جميع خدمات باقتك وأسعار اشتراكك الحالية مستمرة دون تغيير');
        expect(screen.queryByRole('button', { name: /اطلب/ })).toBeNull();
    });

    it('المتاحةُ بسعرها، و«حسب الطلب» تطلب عرضَ سعر، والمشمولةُ تُرى مشمولة', () => {
        showMerchant(merchant({ included: [{ code: 'website', name: 'المتجر الإلكتروني', pricing: 'paid', unit: 'store', counted: false, monthly_price: 12.9, yearly_price: 129, description: null }] }));

        const list = screen.getByTestId('available-addons');
        expect(list).toHaveTextContent('9.900');
        expect(list).toHaveTextContent('99.000');
        expect(within(list).getByTestId('request-whatsapp_own_number')).toHaveTextContent('اطلب عرض سعر');
        expect(screen.getByTestId('included-addons')).toHaveTextContent('المتجر الإلكتروني');
        expect(screen.queryByTestId('request-website')).toBeNull();
    });

    it('الطلبُ يُرسل رمزَ الإضافة ودورتَها — والمبلغُ يُقال قبل الإرسال', () => {
        showMerchant(merchant());

        // `useForm` يُرسل عبر موجّه النواة لا موجّه React المزيَّف
        const post = vi.spyOn(core, 'post').mockImplementation((() => {}) as never);

        fireEvent.click(screen.getByTestId('request-pos'));
        expect(screen.getByTestId('request-summary')).toHaveTextContent('9.900');
        fireEvent.submit(screen.getByTestId('addon-request-form'));

        expect(post).toHaveBeenCalledTimes(1);
        expect(String(post.mock.calls[0][0])).toContain('admin.subscription.addons.request');
        expect(post.mock.calls[0][1]).toEqual({ code: 'pos', cycle: 'monthly', quantity: '1' });
        post.mockRestore();
    });

    it('الموظّفُ يرى ولا يطلب — الطلبُ لصاحب النشاط', () => {
        showMerchant(merchant({ mayBuy: false }));

        expect(screen.queryByTestId('request-pos')).toBeNull();
        expect(screen.getAllByText('يطلبها صاحب النشاط')).toHaveLength(2);
    });

    it('الطلبُ المعلّق يُرى معلّقًا، والساريةُ بتاريخ نهايتها', () => {
        showMerchant(merchant({
            available: [{ code: 'pos', name: 'POS', description: null, pricing: 'paid', unit: 'store', counted: false, monthly_price: 9.9, yearly_price: 99, active: false, pending: true }],
            active: [{ id: 1, addon: { name: 'برنامج الولاء', code: 'loyalty' }, quantity: 1, source: 'purchase', cycle: 'monthly', unit_price: 3.9, starts_at: '2026-11-02', ends_at: '2026-12-02', renews: true }],
            orders: [{ id: 5, addon: { name: 'POS', pricing: 'paid' }, kind: 'new', cycle: 'monthly', quantity: 1, amount: 9.9, status: 'invoiced', invoice: { number: 'INV-P-0009' } }],
        }));

        expect(screen.getByText('طلبك قيد المعالجة')).toBeInTheDocument();
        expect(screen.getByTestId('active-addons')).toHaveTextContent('سارية حتى 2026-12-02');
        expect(screen.getByTestId('addon-requests')).toHaveTextContent('فاتورة INV-P-0009 بانتظار السداد');
    });
});

const platform = (over: Record<string, unknown> = {}) => ({
    catalog: [
        { id: 1, code: 'pos', name: 'نظام الكاشير ونقاط البيع POS', name_en: null, description: null, description_en: null, capability: 'pos', pricing: 'paid', unit: 'store', monthly_price: 9.9, yearly_price: 99, for_sale: true, for_new_merchants: true, sort: 10, plans: { '1': 'eligible', '2': 'included' }, subscribers: 3 },
        { id: 2, code: 'api_integrations', name: 'التكامل مع الأنظمة الخارجية API', name_en: null, description: null, description_en: null, capability: null, pricing: 'quote', unit: 'store', monthly_price: null, yearly_price: null, for_sale: true, for_new_merchants: true, sort: 90, plans: {}, subscribers: 0 },
    ],
    plans: [
        { id: 1, name: 'الباقة الأساسية', code: 'basic', legacy: { monthly: 9.9, yearly: 99 }, addons: null, capabilities_set: true, businesses: { legacy: 4, addons: 0 } },
        { id: 2, name: 'الباقة الاحترافية', code: null, legacy: { monthly: 29.9, yearly: 299 }, addons: null, capabilities_set: true, businesses: { legacy: 1, addons: 0 } },
    ],
    orders: [],
    subscribers: [],
    launch: { enabled: false, blockers: ['سعر الباقة الأساسيّة في السياسة الجديدة لم يُعتمد.'], counts: { legacy: 5, addons: 0 } },
    capabilityOptions: [{ key: 'pos', label: 'نظام الكاشير ونقاط البيع' }],
    currency,
    ...over,
});

const showPlatform = (props: Record<string, unknown>) => {
    Object.assign(pageProps, { translations: {}, ...props });
    render(<PlatformAddons />);
};

describe('«إضافات الاشتراكات» في لوحة المنصّة', () => {
    it('الكتالوجُ بأسعاره وأماكن بيعه — و«حسب الطلب» بلا سعر، والخدمةُ بلا باب', () => {
        showPlatform(platform());

        const cards = screen.getAllByTestId('addon-card');
        expect(cards[0]).toHaveTextContent('9.900');
        expect(cards[0]).toHaveTextContent('الباقة الأساسية · تُشترى');
        expect(cards[0]).toHaveTextContent('الباقة الاحترافية · مشمولة');
        expect(cards[1]).toHaveTextContent('حسب الطلب');
        expect(cards[1]).toHaveTextContent('خدمة تقدّمها أبعاد');
        expect(cards[1]).toHaveTextContent('لا تُباع في أيّ باقة بعد');
    });

    it('الإطلاقُ لا يُضغط وشرطُه ناقص — ويقول ما ينقصه، وسعرُ الأساسيّة يمنعه', () => {
        showPlatform(platform());
        fireEvent.click(screen.getByRole('tab', { name: /التسعير والإعدادات/ }));

        expect(screen.getByTestId('launch-state')).toHaveTextContent('غير مفعّل');
        expect(screen.getByRole('button', { name: /تفعيل نظام الإضافات للتجار الجدد/ })).toBeDisabled();
        expect(screen.getByTestId('launch-blockers')).toHaveTextContent('سعر الباقة الأساسيّة');
        expect(screen.getAllByTestId('plan-price-row')[0]).toHaveTextContent('لم يُعتمد — يمنع التفعيل');
        expect(screen.getAllByTestId('plan-price-row')[1]).toHaveTextContent('تُفوتَر بسعرها السابق');
    });

    it('الطلبُ بانتظار الاعتماد يُعتمد، والمصدَّرُ يُسجَّل سدادُه', () => {
        showPlatform(platform({
            orders: [
                { id: 7, addon: { id: 1, name: 'POS', pricing: 'paid' }, kind: 'new', cycle: 'monthly', quantity: 1, unit_price: 9.9, amount: 9.9, status: 'requested', invoice: null, requested_by: 'سالم', created_at: '2026-11-02', business: { id: 3, name: 'ورد مسقط' } },
                { id: 8, addon: { id: 2, name: 'API', pricing: 'quote' }, kind: 'new', cycle: 'yearly', quantity: 1, unit_price: 50, amount: 50, status: 'invoiced', invoice: { id: 11, number: 'INV-P-0011', paid: false }, requested_by: null, created_at: '2026-11-02', business: { id: 4, name: 'زهور' } },
            ],
        }));
        fireEvent.click(screen.getByRole('tab', { name: /طلبات الإضافات/ }));

        const rows = screen.getAllByTestId('addon-order-row');
        expect(within(rows[0]).getByRole('button', { name: /اعتماد/ })).toBeInTheDocument();
        expect(within(rows[0]).queryByRole('button', { name: /تسجيل السداد/ })).toBeNull();
        expect(within(rows[1]).getByRole('button', { name: /تسجيل السداد/ })).toBeInTheDocument();
        expect(rows[1]).toHaveTextContent('INV-P-0011');
    });
});
