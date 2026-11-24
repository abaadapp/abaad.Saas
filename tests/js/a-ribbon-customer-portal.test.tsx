import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { readFileSync } from 'node:fs';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { pageProps } from './setup';

/**
 * بوابةُ عملاء RIBBON في شاشات الموظّف — ما لا يرسله الخادمُ لا يُرسم، والرابطُ
 * لا يُطلب حتّى يُضغط، وفشلُ المشاركة لا يمسّ بيعةً تمّت.
 */
vi.mock('@/Layouts/AdminLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <div>{children}</div> }));
const toast = vi.hoisted(() => ({ error: vi.fn(), warning: vi.fn(), success: vi.fn() }));
vi.mock('sonner', () => ({ toast }));

const { CustomerPortalButton, PortalButton, PortalSettingsCard } = await import('@/Components/RibbonPortal');
const { PortalSaleShare } = await import('@/Components/RibbonPortalSale');
const { default: CustomerPortal } = await import('@/Pages/Admin/Settings/CustomerPortal');
const { router } = await import('@inertiajs/react');

const URL_ = 'https://ribbon.abaadapp.om/my';
const panel = { url: URL_, qr: 'data:image/svg+xml;base64,AA==', poster: '/admin/customers/portal/poster', name: 'RIBBON' };
const urls = { share: '/admin/customers/7/portal', reissue: '/admin/customers/7/portal/reissue', revoke: '/admin/customers/7/portal' };
const state = { urls, hasPhone: true, issued: null as string | null, used: null as string | null };
const shared = (key = 'K1') => ({ ok: true, url: `${URL_}#${key}`, message: `أهلًا بك في RIBBON\n${URL_}#${key}`, whatsapp: 'https://wa.me/96891234567?text=x', state: { ...state, issued: '2026-11-29 10:00' } });

const answer = (status: number, body: unknown) => {
    const spy = vi.fn(async () => ({ ok: status < 400, status, json: async () => body }));
    vi.stubGlobal('fetch', spy);

    return spy as unknown as { mock: { calls: [string, RequestInit][] } } & typeof spy;
};

beforeEach(() => {
    Object.values(toast).forEach((f) => f.mockClear());
    Object.assign(pageProps, { translations: {} });
});
afterEach(() => vi.unstubAllGlobals());

describe('قسمُ العملاء', () => {
    it('زرُّه يفتح الرابطَ العامّ ورمزَه ونسخَه وفتحَه وطباعتَه', () => {
        render(<PortalButton portal={panel} />);
        fireEvent.click(screen.getByRole('button', { name: 'بوابة عملاء RIBBON' }));

        expect(screen.getByTestId('portal-public-box')).toHaveTextContent(URL_);
        expect(screen.getByAltText('رمز QR لبوابة العملاء')).toHaveAttribute('src', panel.qr);
        expect(screen.getByRole('button', { name: 'نسخ الرابط' })).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'فتح البوابة' })).toHaveAttribute('href', URL_);
        expect(screen.getByRole('link', { name: 'فتح البوابة' })).toHaveAttribute('rel', 'noreferrer');
        expect(screen.getByRole('link', { name: 'طباعة الرمز' })).toHaveAttribute('href', panel.poster);
    });

    it('وشاشاتُه لا ترسم زرًّا بلا ما يرسله الخادم', () => {
        const read = (f: string) => readFileSync(f, 'utf8');
        expect(read('resources/js/Pages/Admin/Customers/Index.tsx')).toContain('{portal && <PortalButton portal={portal} />}');
        expect(read('resources/js/Pages/Admin/Customers/Show.tsx')).toContain('{portalLink && <CustomerPortalButton state={portalLink} />}');
        expect(read('resources/js/Pages/Admin/Settings/Index.tsx')).toContain("portalUrl && <PortalSettingsCard href={portalUrl} />");
        expect(read('resources/js/Pages/Pos/partials/PaymentDialog.tsx')).toMatch(/result\?\.synced && result\.invoice && result\.portal && \(\s*<PortalSaleShare url=\{result\.portal\}/);
        // ولا اسمَ مسارٍ لها في كودٍ يصل كلَّ تاجر
        for (const f of ['Components/RibbonPortal.tsx', 'Components/RibbonPortalSale.tsx', 'Pages/Admin/Settings/CustomerPortal.tsx', 'lib/ribbon-portal.ts']) {
            expect(read(`resources/js/${f}`)).not.toMatch(/route\(\s*[`'"](admin|pos)\.[^`'"]*portal/);
        }
    });
});

describe('ملفُّ العميل', () => {
    it('لا رابطَ حتّى يُطلب، ثمّ يُنسخ ويُشارك — ولا يُرسَل', async () => {
        const fetch = answer(200, shared());
        render(<CustomerPortalButton state={state} />);
        fireEvent.click(screen.getByRole('button', { name: 'مشاركة رابط العميل' }));

        expect(fetch).not.toHaveBeenCalled();
        expect(screen.getByTestId('portal-state')).toHaveTextContent('لم يصدر بعد');
        expect(screen.queryByTestId('portal-revoke')).toBeNull();

        fireEvent.click(screen.getByRole('button', { name: 'إنشاء رابط العميل' }));
        await screen.findByTestId('portal-shared');
        expect(fetch.mock.calls).toHaveLength(1);
        expect(fetch.mock.calls[0][0]).toBe(urls.share);
        expect(fetch.mock.calls[0][1]).toMatchObject({ method: 'POST', cache: 'no-store' });
        expect(screen.getByTestId('portal-whatsapp')).toHaveAttribute('href', 'https://wa.me/96891234567?text=x');
        expect(screen.getByTestId('portal-shared')).toHaveTextContent('لا تُرسل الرسالة تلقائيًّا');
        expect(screen.getByTestId('portal-reissue')).toBeInTheDocument();
    });

    it('وإعادةُ الإصدار تبدّله', async () => {
        const fetch = answer(200, shared('K2'));
        render(<CustomerPortalButton state={{ ...state, issued: '2026-11-01 09:00' }} />);
        fireEvent.click(screen.getByTestId('portal-customer-button'));
        fireEvent.click(screen.getByTestId('portal-reissue'));
        await screen.findByTestId('portal-shared');
        expect(fetch.mock.calls[0][0]).toBe(urls.reissue);
        expect(screen.getByTestId('portal-shared')).toHaveTextContent('#K2');
        expect(toast.success).toHaveBeenCalledWith('صدر رابط جديد، والقديم لم يعد يعمل');
    });

    it('ومن لا رقمَ له يُقال له، ولا يُطلب له رابط', () => {
        const fetch = answer(200, shared());
        render(<CustomerPortalButton state={{ ...state, hasPhone: false }} />);
        fireEvent.click(screen.getByTestId('portal-customer-button'));
        expect(screen.getByText('أضف رقم هاتف العميل أولًا — يدخل به إلى بوابته.')).toBeInTheDocument();
        expect(screen.queryByTestId('portal-make')).toBeNull();
        expect(fetch).not.toHaveBeenCalled();
    });

    it('وخطأُ الخادم يُقال بنصّه', async () => {
        answer(422, { message: 'أضف رقم هاتف العميل أولًا' });
        render(<CustomerPortalButton state={state} />);
        fireEvent.click(screen.getByTestId('portal-customer-button'));
        fireEvent.click(screen.getByTestId('portal-make'));
        await waitFor(() => expect(toast.error).toHaveBeenCalledWith('أضف رقم هاتف العميل أولًا'));
        expect(screen.queryByTestId('portal-shared')).toBeNull();
    });
});

describe('الصندوق', () => {
    it('يشارك بعد البيع بنسخٍ وواتساب', async () => {
        const fetch = answer(200, shared());
        render(<PortalSaleShare url="/pos/orders/INV-1/portal" />);
        fireEvent.click(screen.getByRole('button', { name: 'مشاركة رابط مشترياتي' }));
        await screen.findByTestId('portal-sale-shared');
        expect(fetch.mock.calls[0][0]).toBe('/pos/orders/INV-1/portal');
        expect(screen.getByRole('link', { name: 'واتساب' })).toHaveAttribute('href', 'https://wa.me/96891234567?text=x');
    });

    it('وفشلُه يُقال ويبقى الزرُّ — بلا استثناءٍ يوقف شاشةَ النجاح', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => { throw new Error('offline'); }));
        render(<PortalSaleShare url="/pos/orders/INV-1/portal" />);
        fireEvent.click(screen.getByTestId('portal-sale'));
        await waitFor(() => expect(toast.warning).toHaveBeenCalled());
        expect(screen.getByTestId('portal-sale')).toBeEnabled();
    });
});

describe('الإعدادات', () => {
    const page = (over = {}) => {
        Object.assign(pageProps, {
            translations: {}, portal: panel, urls: { self: '/s', staff: '/s/staff', revokeAll: '/s/links' }, mayGrant: true,
            links: [{ id: 3, customer: 'مريم', by: 'سعود', issued: '2026-11-29 10:00', used: null, revokeUrl: '/s/links/3' }],
            pagination: { current_page: 1, last_page: 1, total: 1 },
            employees: [{ id: 1, name: 'منى', role: 'مبيعات', share: false, manage: false }, { id: 2, name: 'خالد', role: 'مبيعات', share: true, manage: true }],
        }, over);
        render(<CustomerPortal />);
    };

    it('حالتُها ورابطُها ومعاينتُها، ومن يدير يشارك', () => {
        page();
        expect(screen.getByTestId('portal-status')).toHaveTextContent('تعمل');
        expect(screen.getByRole('link', { name: 'معاينة الصفحة' })).toHaveAttribute('href', URL_);
        expect(screen.queryByTestId('portal-save-staff')).toBeNull();

        fireEvent.click(screen.getByLabelText('يدير البوابة — منى'));
        expect(screen.getByLabelText('يشارك الرابط — منى')).toBeChecked();
        // ومن نُزعت مشاركتُه نُزعت إدارتُه
        fireEvent.click(screen.getByLabelText('يشارك الرابط — خالد'));
        expect(screen.getByLabelText('يدير البوابة — خالد')).not.toBeChecked();

        fireEvent.click(screen.getByTestId('portal-save-staff'));
        expect(router.post).toHaveBeenCalledWith('/s/staff', { share: [1], manage: [1] }, expect.anything());
    });

    it('ومن ليس صاحبَ النشاط يراها ولا يمنح', () => {
        page({ mayGrant: false });
        for (const box of screen.getAllByRole('checkbox')) expect(box).toBeDisabled();
        expect(screen.getAllByTestId('portal-link-row')).toHaveLength(1);
        expect(screen.getByTestId('portal-revoke-all')).toBeInTheDocument();
    });

    it('وبطاقتُها تفتح صفحتَها', () => {
        render(<PortalSettingsCard href="/admin/settings/customer-portal" />);
        expect(screen.getByTestId('portal-settings-card')).toHaveAttribute('href', '/admin/settings/customer-portal');
    });

    it('ولكلّ نصٍّ فيها إنجليزيّتُه', () => {
        const en = JSON.parse(readFileSync('lang/en.json', 'utf8')) as Record<string, string>;
        const code = ['Components/RibbonPortal.tsx', 'Components/RibbonPortalSale.tsx', 'Pages/Admin/Settings/CustomerPortal.tsx'].map((f) => readFileSync(`resources/js/${f}`, 'utf8')).join('\n');
        const said = [...code.matchAll(/\bt\('([^']+)'\)/g)].map((m) => m[1]).filter((k) => /[؀-ۿ]/.test(k));
        expect(said.length).toBeGreaterThan(30);
        for (const key of said) expect(en[key], key).toBeTruthy();
    });
});
