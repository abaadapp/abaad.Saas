import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { router } from '@inertiajs/react';
import { router as core } from '@inertiajs/core';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { pageProps } from './setup';

vi.mock('@/Layouts/AdminLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <div>{children}</div> }));
vi.mock('@/Layouts/PlatformLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <div>{children}</div> }));

const { default: MerchantAddons } = await import('@/Pages/Admin/Subscription/Addons');
const { default: PlatformAddons } = await import('@/Pages/Platform/Subscriptions/Addons');

/**
 * «طوّر متجرك مع أبعاد» و«التسعير والإعدادات» — ما يُرى.
 *
 * ═══ ما يُحرس ═══
 *
 *   - متجرُ السياسة السابقة يرى باقتَه وخدماتِ أبعاد بحالتها — بلا سعرٍ ولا زرِّ شراء.
 *   - «تواصل مع أبعاد» رابطٌ إلى «المساعدة والدعم» لا طلبٌ يُرسل.
 *   - نموذجُ الإضافة بلا حقل رمز، والرمزُ يُقرأ في «تفاصيل تقنية» وحدها.
 *   - التفعيلُ بنافذةٍ تلخّص أثرَه ولا تُرسل قبل موافقةٍ تُؤشَّر، والإيقافُ بتأكيدين.
 *   - المشتركون بحالة الاستحقاق ودورته وتجديده.
 *
 * والخادمُ هو الحارس (`AnOldShopBrowsesAbaadServicesWithoutBuyingTest`،
 * `TheAddonLaunchLivesInPricingAndSettingsTest`) — فهذا ما يُرى.
 */
const currency = { code: 'OMR', symbol: 'ر.ع', decimals: 3 };

const legacy = (over: Record<string, unknown> = {}) => ({
    policy: 'legacy',
    plan: { name: 'الباقة الأساسية', price: null },
    subscription: { status: 'نشط', live: true, ends_at: '2027-01-31' },
    showcase: [
        { id: 1, capability: 'pos', name: 'نظام الكاشير ونقاط البيع POS', description: 'البيع من صندوق المحلّ', state: 'included', note: null },
        { id: 2, capability: 'website', name: 'المتجر الإلكتروني وإدارة الطلبات', description: null, state: 'not_included', note: null },
        { id: 6, capability: 'branches', name: 'فرع إضافي', description: null, state: 'learn', note: 'حدّ الفروع في باقتك: 1' },
        { id: 9, capability: null, name: 'التكامل مع الأنظمة الخارجية API', description: null, state: 'learn', note: null },
    ],
    included: [],
    available: [],
    active: [],
    orders: [],
    addons_total: { monthly: 0, yearly: 0 },
    may_request: false,
    mayBuy: true,
    currency,
    ...over,
});

const showMerchant = (props: Record<string, unknown>) => {
    Object.assign(pageProps, { translations: {}, auth: { abilities: ['settings'], mayActions: [] }, ...props });
    return render(<MerchantAddons />);
};

afterEach(() => {
    vi.mocked(router.post).mockClear();
});

describe('«طوّر متجرك مع أبعاد» لمتجر السياسة السابقة', () => {
    it('باقتُه الحقيقيّة وحالتُها، و«اشتراكك الحالي محفوظ»', () => {
        showMerchant(legacy());

        expect(screen.getByText('طوّر متجرك مع أبعاد')).toBeInTheDocument();
        const card = screen.getByTestId('legacy-policy-note');
        expect(card).toHaveTextContent('اشتراكك الحالي محفوظ');
        expect(card).toHaveTextContent('جميع خدمات باقتك وأسعار اشتراكك الحالية مستمرة دون تغيير');
        expect(within(card).getByTestId('current-plan')).toHaveTextContent('الباقة الأساسية');
        expect(within(card).getByTestId('subscription-status')).toHaveTextContent('نشط');
        // والمشمولُ ما أثبته الحارس وحده
        expect(within(card).getByTestId('included-addons')).toHaveTextContent('نظام الكاشير ونقاط البيع POS');
        expect(within(card).getByTestId('included-addons')).not.toHaveTextContent('المتجر الإلكتروني');
    });

    it('كلُّ خدمةٍ ببطاقتها وحالتها — ولا سعرَ ولا زرَّ شراء', () => {
        showMerchant(legacy());

        const cards = within(screen.getByTestId('services-showcase')).getAllByTestId('service-card');
        expect(cards).toHaveLength(4);
        expect(within(cards[0]).getByTestId('service-state')).toHaveTextContent('متوفرة في اشتراكك');
        expect(within(cards[1]).getByTestId('service-state')).toHaveTextContent('ليست ضمن باقتك الحالية');
        expect(within(cards[2]).getByTestId('service-state')).toHaveTextContent('تعرّف على الخدمة');
        expect(cards[2]).toHaveTextContent('حدّ الفروع في باقتك: 1');
        expect(within(cards[3]).getByTestId('service-state')).toHaveAttribute('data-state', 'learn');

        const page = document.body;
        expect(page).not.toHaveTextContent('/ شهريًا');
        expect(page).not.toHaveTextContent('ر.ع');
        expect(screen.queryByRole('button', { name: /اطلب/ })).toBeNull();
        expect(screen.queryByTestId('available-addons')).toBeNull();
    });

    it('«تواصل مع أبعاد» رابطٌ إلى الدعم بموضوعه — لا يُرسل شيئًا', () => {
        showMerchant(legacy());

        const contact = screen.getByTestId('contact-abaad');
        expect(contact).toHaveTextContent('تحتاج خدمة إضافية لمتجرك؟');
        const link = within(contact).getByTestId('contact-abaad-button');
        expect(link).toHaveTextContent('تواصل مع أبعاد');
        expect(link.getAttribute('href')).toContain('admin.help.index');
        expect(link.getAttribute('href')).toContain('services');
        // رابطٌ يُفتح لا نموذجٌ يُرسل — ولا زرَّ في البطاقة يُرسل شيئًا
        expect(link.tagName).toBe('A');
        expect(within(contact).queryAllByRole('button')).toHaveLength(0);
    });

    it('كتالوجٌ فارغ يقول ذلك', () => {
        showMerchant(legacy({ showcase: [] }));
        expect(screen.getByTestId('showcase-empty')).toHaveTextContent('لا خدمات معروضة الآن.');
    });
});

describe('«طوّر متجرك مع أبعاد» لمتجر الإضافات', () => {
    it('الأسعارُ وزرُّ الطلب كما كانا، والتجديدُ الموقوفُ يُقال', () => {
        showMerchant({
            ...legacy(),
            policy: 'addons_v1',
            showcase: [],
            plan: { name: 'الباقة الأساسية', price: { monthly: 4.9, yearly: 49 } },
            available: [{ code: 'pos', capability: 'pos', name: 'POS', description: null, pricing: 'paid', unit: 'store', counted: false, monthly_price: 9.9, yearly_price: 99, active: false, pending: false }],
            active: [{ id: 1, addon: { name: 'برنامج الولاء', code: 'loyalty' }, quantity: 1, source: 'purchase', cycle: 'monthly', unit_price: 3.9, starts_at: '2026-11-02', ends_at: '2026-12-02', renews: false }],
            may_request: true,
        });

        expect(screen.getByTestId('plan-summary')).toHaveTextContent('4.900');
        expect(within(screen.getByTestId('available-addons')).getByTestId('request-pos')).toHaveTextContent('اطلب الإضافة');
        expect(screen.getByTestId('active-addons')).toHaveTextContent('التجديد موقوف');
        expect(screen.getByRole('button', { name: 'إعادة التجديد' })).toBeInTheDocument();
        expect(screen.getByTestId('contact-abaad')).toBeInTheDocument();
        expect(screen.queryByTestId('services-showcase')).toBeNull();
    });
});

const plans = [
    { id: 1, name: 'الباقة الأساسية', code: 'basic', legacy: { monthly: 9.9, yearly: 99 }, addons: { monthly: 4.9, yearly: 49 }, capabilities_set: true, businesses: { legacy: 4, addons: 0 } },
    { id: 2, name: 'الباقة الاحترافية', code: null, legacy: { monthly: 29.9, yearly: 299 }, addons: null, capabilities_set: true, businesses: { legacy: 1, addons: 0 } },
];

const checks = (ok: boolean) => [
    { key: 'basic_price', label: 'سعر الباقة الأساسيّة الجديدة معتمد', ok: true, problems: [] },
    { key: 'basic_capabilities', label: 'قدرات الباقة الأساسيّة محدّدة', ok: true, problems: [] },
    { key: 'pos_separate', label: 'نقطة البيع منفصلة عن الأساسيّة الجديدة ومعروضة للبيع', ok, problems: ok ? [] : ['نقطة البيع مشمولةٌ في الباقة الأساسيّة'] },
    { key: 'catalog_priced', label: 'كل إضافة مدفوعة معروضة لها سعر شهري وسنوي', ok: true, problems: [] },
];

const platform = (over: Record<string, unknown> = {}) => ({
    catalog: [
        { id: 10, code: 'addon_01jabcdefghjkmnpqrstvwxyz0', name: 'دعم بأولوية', name_en: 'Priority support', description: null, description_en: null, capability: null, pricing: 'quote', unit: 'store', monthly_price: null, yearly_price: null, for_sale: true, for_new_merchants: true, sort: 0, plans: {}, subscribers: 0 },
    ],
    plans,
    orders: [],
    subscribers: [],
    launch: { enabled: false, checks: checks(true), blockers: [], counts: { legacy: 5, addons: 0 } },
    capabilityOptions: [{ key: 'pos', label: 'نظام الكاشير ونقاط البيع' }],
    currency,
    ...over,
});

const showPlatform = (props: Record<string, unknown>) => {
    Object.assign(pageProps, { translations: {}, ...props });
    render(<PlatformAddons />);
};

describe('«إضافات الاشتراكات» في لوحة المنصّة', () => {
    it('أربعةُ تبويبات، والبطاقةُ بلا رمز، والنموذجُ بلا حقل رمز', () => {
        showPlatform(platform());

        for (const name of ['كتالوج الإضافات', 'طلبات الإضافات', 'المشتركون', 'التسعير والإعدادات']) {
            expect(screen.getByRole('tab', { name: new RegExp(name) })).toBeInTheDocument();
        }
        expect(screen.queryByRole('tab', { name: /الإطلاق/ })).toBeNull();

        const card = screen.getAllByTestId('addon-card')[0];
        expect(card).not.toHaveTextContent('addon_01j');
        expect(card).toHaveTextContent('Priority support');

        fireEvent.click(screen.getByRole('button', { name: /إضافة جديدة/ }));
        const form = screen.getByTestId('addon-form');
        expect(within(form).queryByText('الرمز')).toBeNull();
        expect(within(form).getByText('الاسم بالعربية')).toBeInTheDocument();
        expect(within(form).queryByTestId('addon-technical')).toBeNull();
    });

    it('الإضافةُ الجديدة موقوفٌ بيعُها حتى يُقرَّر — والقائمةُ تبقى على حالها', () => {
        showPlatform(platform());
        const post = vi.spyOn(core, 'post').mockImplementation((() => {}) as never);

        fireEvent.click(screen.getByRole('button', { name: /إضافة جديدة/ }));
        const form = screen.getByTestId('addon-form');
        const forSale = within(form).getByText('تُباع الآن').closest('label')!.querySelector('input') as HTMLInputElement;
        expect(forSale.checked).toBe(false);
        fireEvent.change(within(form).getAllByRole('textbox')[0], { target: { value: 'تصوير المنتجات' } });
        fireEvent.submit(form);
        expect(post).toHaveBeenCalledTimes(1);
        expect(post.mock.calls[0][1]).toMatchObject({ name: 'تصوير المنتجات', for_sale: false });
        expect(post.mock.calls[0][1]).not.toHaveProperty('code');
        post.mockRestore();
    });

    it('وتعديلُ إضافةٍ معروضةٍ يُبقيها معروضة', () => {
        showPlatform(platform());
        fireEvent.click(within(screen.getAllByTestId('addon-card')[0]).getByRole('button', { name: /تعديل/ }));
        const forSale = within(screen.getByTestId('addon-form')).getByText('تُباع الآن').closest('label')!.querySelector('input') as HTMLInputElement;
        expect(forSale.checked).toBe(true);
    });

    it('والتعديلُ يُظهر المعرّفَ للقراءة في «تفاصيل تقنية» وحدها', () => {
        showPlatform(platform());
        fireEvent.click(within(screen.getAllByTestId('addon-card')[0]).getByRole('button', { name: /تعديل/ }));

        const technical = screen.getByTestId('addon-technical');
        expect(technical).toHaveTextContent('تفاصيل تقنية');
        expect(technical).toHaveTextContent('addon_01jabcdefghjkmnpqrstvwxyz0');
        expect(within(screen.getByTestId('addon-form')).queryByDisplayValue('addon_01jabcdefghjkmnpqrstvwxyz0')).toBeNull();
    });

    it('غيرُ مفعّل وشرطُه ناقص: يقول الشرطَ ولا يُضغط', () => {
        showPlatform(platform({ launch: { enabled: false, checks: checks(false), blockers: ['نقطة البيع مشمولةٌ في الباقة الأساسيّة'], counts: { legacy: 5, addons: 0 } } }));
        fireEvent.click(screen.getByRole('tab', { name: /التسعير والإعدادات/ }));

        expect(screen.getByTestId('launch-status')).toHaveTextContent('نظام الاشتراكات الجديد غير مفعّل');
        expect(screen.getByTestId('launch-status')).toHaveTextContent('المتاجر الجديدة تستمر حاليًا على سياسة الاشتراكات السابقة');
        const rows = within(screen.getByTestId('launch-checks')).getAllByTestId('launch-check');
        expect(rows).toHaveLength(4);
        expect(rows[2]).toHaveAttribute('data-ok', '0');
        expect(rows[2]).toHaveTextContent('نقطة البيع مشمولةٌ في الباقة الأساسيّة');
        expect(screen.getByTestId('launch-activate')).toBeDisabled();
        // والسعران منفصلان
        const basic = screen.getAllByTestId('plan-price-row')[0];
        expect(within(basic).getByTestId('plan-legacy-price')).toHaveTextContent('9.900');
        expect(basic).toHaveTextContent('سعر النظام الجديد');
    });

    it('التفعيلُ بنافذةٍ تلخّص أثرَه — ولا يُرسل قبل الموافقة، ثمّ يُرسلها', () => {
        showPlatform(platform());
        fireEvent.click(screen.getByRole('tab', { name: /التسعير والإعدادات/ }));
        fireEvent.click(screen.getByTestId('launch-activate'));

        const dialog = screen.getByTestId('launch-dialog');
        expect(dialog).toHaveTextContent('4.900');
        expect(dialog).toHaveTextContent('المتاجر الموجودة (5) تبقى على سياستها');
        const go = within(dialog).getByTestId('launch-confirm');
        expect(go).toBeDisabled();
        fireEvent.click(go);
        expect(router.post).not.toHaveBeenCalled();

        fireEvent.click(within(dialog).getByTestId('launch-ack'));
        expect(go).toBeEnabled();
        fireEvent.click(go);
        expect(router.post).toHaveBeenCalledTimes(1);
        expect(vi.mocked(router.post).mock.calls[0][1]).toEqual({ enabled: true, acknowledged: true });
    });

    it('المفعّلُ يقول عددَ المتاجر على كلّ سياسة — والإيقافُ بتأكيدين', async () => {
        showPlatform(platform({ launch: { enabled: true, checks: checks(true), blockers: [], counts: { legacy: 5, addons: 2 } } }));
        fireEvent.click(screen.getByRole('tab', { name: /التسعير والإعدادات/ }));

        expect(screen.getByTestId('launch-status')).toHaveTextContent('نظام الإضافات مفعّل للتسجيلات الجديدة');
        expect(screen.getByTestId('policy-counts')).toHaveTextContent('متاجر على السياسة السابقة: 5 · على نظام الإضافات: 2');
        expect(screen.queryByTestId('launch-activate')).toBeNull();

        fireEvent.click(screen.getByTestId('launch-stop'));
        await act(async () => fireEvent.click(await screen.findByRole('button', { name: 'متابعة الإيقاف' })));
        expect(router.post).not.toHaveBeenCalled();
        await act(async () => fireEvent.click(await screen.findByRole('button', { name: 'إيقاف نظام الإضافات' })));
        await waitFor(() => expect(router.post).toHaveBeenCalledTimes(1));
        expect(vi.mocked(router.post).mock.calls[0][1]).toEqual({ enabled: false });
    });

    it('المشتركون بحالة الاستحقاق ودورته وتجديده', () => {
        showPlatform(platform({
            subscribers: [
                { id: 1, addon: { id: 1, name: 'POS', code: 'pos' }, quantity: 1, source: 'purchase', cycle: 'monthly', unit_price: 9.9, starts_at: '2026-11-01', ends_at: '2026-12-01', renews: false, cancelled_at: null, active: true, business: { id: 3, name: 'ورد مسقط' } },
                { id: 2, addon: { id: 9, name: 'API', code: 'api_integrations' }, quantity: 1, source: 'grant', cycle: null, unit_price: 0, starts_at: '2026-11-01', ends_at: null, renews: false, cancelled_at: null, active: true, business: { id: 4, name: 'زهور' } },
            ],
        }));
        fireEvent.click(screen.getByRole('tab', { name: /المشتركون/ }));

        const table = screen.getByTestId('addon-subscribers');
        expect(within(table).getAllByTestId('entitlement-state')[0]).toHaveTextContent('سارية');
        expect(within(table).getAllByTestId('entitlement-renewal')[0]).toHaveTextContent('التجديد موقوف');
        expect(within(table).getAllByTestId('entitlement-renewal')[1]).toHaveTextContent('لا تجديد');
        expect(table).toHaveTextContent('شهري');
    });
});
