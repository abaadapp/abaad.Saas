import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { readFileSync } from 'node:fs';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { pageProps } from './setup';

/**
 * بوابةُ عملاء RIBBON في شاشات الموظّف — ما لا يرسله الخادمُ لا يُرسم، والرابطُ
 * لا يُطلب حتّى يُضغط، وفشلُ نسخه لا يمسّ بيعةً تمّت. ولا قناةَ مراسلةٍ فيها:
 * يُنسخ الرابطُ أو يُمسح رمزُه.
 */
vi.mock('@/Layouts/AdminLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <div>{children}</div> }));
const toast = vi.hoisted(() => ({ error: vi.fn(), warning: vi.fn(), success: vi.fn() }));
vi.mock('sonner', () => ({ toast }));

const { CustomerPortalCard, PortalButton, PortalSettingsCard } = await import('@/Components/RibbonPortal');
const { PortalSaleLink } = await import('@/Components/RibbonPortalSale');
const { default: CustomerPortal } = await import('@/Pages/Admin/Settings/CustomerPortal');
const { router } = await import('@inertiajs/react');

const URL_ = 'https://ribbon.abaadapp.om/my';
const panel = { url: URL_, qr: 'data:image/svg+xml;base64,AA==', poster: '/admin/customers/portal/poster', name: 'RIBBON' };
const urls = { share: '/admin/customers/7/portal', reissue: '/admin/customers/7/portal/reissue', revoke: '/admin/customers/7/portal' };
const state = { urls, hasPhone: true, issued: null as string | null, used: null as string | null };
const live = { ...state, issued: '2026-11-01 09:00' };
const sharer = { ...live, urls: { ...urls, reissue: null, revoke: null } };
const shared = (key = 'K1') => ({ url: `${URL_}#${key}`, qr: `data:image/svg+xml;base64,${key}`, state: live });

const answer = (status: number, body: unknown) => {
    const spy = vi.fn(async () => ({ ok: status < 400, status, json: async () => body }));
    vi.stubGlobal('fetch', spy);

    return spy as unknown as { mock: { calls: [string, RequestInit][] } } & typeof spy;
};
const write = vi.fn();
const clipboard = (does: () => Promise<void>) => {
    write.mockImplementation(does);
    Object.defineProperty(navigator, 'clipboard', { value: { writeText: write }, configurable: true });
};
const files = ['Components/RibbonPortal.tsx', 'Components/RibbonPortalSale.tsx', 'Pages/Admin/Settings/CustomerPortal.tsx', 'lib/ribbon-portal.ts'];
const read = (f: string) => readFileSync(`resources/js/${f}`, 'utf8');

beforeEach(() => {
    Object.values(toast).forEach((f) => f.mockClear());
    write.mockReset();
    clipboard(() => Promise.resolve());
    Object.assign(pageProps, { translations: {} });
});
afterEach(() => {
    vi.unstubAllGlobals();
    Reflect.deleteProperty(navigator, 'clipboard');
});

describe('قسمُ العملاء', () => {
    it('زرُّه يفتح الرابطَ العامّ ورمزَه ونسخَه وفتحَه وطباعتَه', async () => {
        render(<PortalButton portal={panel} />);
        fireEvent.click(screen.getByRole('button', { name: 'بوابة العملاء' }));

        expect(screen.getByTestId('portal-public-box')).toHaveTextContent(URL_);
        expect(screen.getByAltText('رمز QR لبوابة العملاء')).toHaveAttribute('src', panel.qr);
        fireEvent.click(screen.getByRole('button', { name: 'نسخ الرابط' }));
        await waitFor(() => expect(write).toHaveBeenCalledWith(URL_));
        expect(screen.getByRole('link', { name: 'فتح البوابة' })).toHaveAttribute('href', URL_);
        expect(screen.getByRole('link', { name: 'فتح البوابة' })).toHaveAttribute('rel', 'noreferrer');
        expect(screen.getByRole('link', { name: 'طباعة الرمز' })).toHaveAttribute('href', panel.poster);
    });

    it('وشاشاتُه لا ترسم شيئًا بلا ما يرسله الخادم — والزرُّ بجانب «إضافة عميل»', () => {
        expect(read('Pages/Admin/Customers/Index.tsx')).toMatch(/\{portal && <PortalButton portal=\{portal\} \/>\}\s*<Button onClick=\{\(\) => setAdding\(true\)\}>/);
        expect(read('Pages/Admin/Customers/Show.tsx')).toContain('{portalLink && <CustomerPortalCard key={JSON.stringify(portalLink)} state={portalLink} />}');
        expect(read('Pages/Admin/Settings/Index.tsx')).toContain('portalUrl && <PortalSettingsCard href={portalUrl} />');
        expect(read('Pages/Pos/partials/PaymentDialog.tsx')).toMatch(/result\?\.synced && result\.invoice && result\.portal && \(\s*<PortalSaleLink url=\{result\.portal\}/);
        // ولا اسمَ مسارٍ لها في كودٍ يصل كلَّ تاجر
        for (const f of files) expect(read(f)).not.toMatch(/route\(\s*[`'"](admin|pos)\.[^`'"]*portal/);
    });

    it('ولا ذكرَ لواتساب ولا لقناة مراسلةٍ في شاشاتها', () => {
        for (const f of files) expect(read(f), f).not.toMatch(/whatsapp|wa\.me|واتساب|mailto:|sms:|MessageCircle|navigator\.share/i);
    });
});

describe('ملفُّ العميل: «رابط بوابة العميل»', () => {
    it('لا رابطَ حتّى يُنشأ، ثمّ يُنسخ ويُعرض رمزُه', async () => {
        const fetch = answer(200, shared());
        render(<CustomerPortalCard state={state} />);

        expect(screen.getByTestId('portal-customer')).toHaveTextContent('رابط بوابة العميل');
        expect(screen.getByTestId('portal-customer-state')).toHaveTextContent('لم يصدر بعد');
        expect(fetch).not.toHaveBeenCalled();
        for (const id of ['portal-copy', 'portal-qr', 'portal-reissue', 'portal-revoke', 'portal-link']) expect(screen.queryByTestId(id), id).toBeNull();

        fireEvent.click(screen.getByRole('button', { name: 'إنشاء الرابط الشخصي' }));
        await screen.findByTestId('portal-link');
        expect(fetch.mock.calls).toHaveLength(1);
        expect(fetch.mock.calls[0][0]).toBe(urls.share);
        expect(fetch.mock.calls[0][1]).toMatchObject({ method: 'POST', cache: 'no-store' });
        expect(screen.getByTestId('portal-customer-state')).toHaveTextContent('فعّال');
        expect(screen.getByTestId('portal-link')).toHaveTextContent(`${URL_}#K1`);
        expect(write).not.toHaveBeenCalled();

        fireEvent.click(screen.getByRole('button', { name: 'نسخ الرابط' }));
        await waitFor(() => expect(write).toHaveBeenCalledWith(`${URL_}#K1`));
        expect(await screen.findByRole('button', { name: 'نُسخ' })).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'عرض رمز QR' }));
        expect(await screen.findByAltText('رمز QR لرابط العميل')).toHaveAttribute('src', 'data:image/svg+xml;base64,K1');
        expect(screen.getByTestId('portal-customer-qr')).toHaveTextContent('يمسحه العميل بهاتفه، ثم يكتب رقم هاتفه المسجّل.');
        expect(screen.queryByRole('link')).toBeNull();
    });

    it('والرابطُ القائم يُسأل عنه الخادمُ في كلّ ضغطة — قد أعاد غيرُك إصدارَه', async () => {
        const fetch = answer(200, shared('NEW'));
        render(<CustomerPortalCard state={live} />);
        expect(screen.queryByTestId('portal-make')).toBeNull();
        expect(screen.queryByTestId('portal-link')).toBeNull();
        fireEvent.click(screen.getByTestId('portal-copy'));
        await waitFor(() => expect(write).toHaveBeenCalledWith(`${URL_}#NEW`));
        expect(fetch.mock.calls).toHaveLength(1);
    });

    it('وجهازٌ يمنع الحافظة لا يُقال له «نُسخ»، ويُعرض الرابطُ ليُحدَّد', async () => {
        clipboard(() => Promise.reject(new Error('blocked')));
        answer(200, shared());
        render(<CustomerPortalCard state={live} />);
        fireEvent.click(screen.getByTestId('portal-copy'));
        expect(await screen.findByTestId('portal-link')).toHaveTextContent(`${URL_}#K1`);
        expect(screen.queryByRole('button', { name: 'نُسخ' })).toBeNull();
    });

    it('وإعادةُ الإصدار والإلغاءُ لمن يدير، وبتأكيد', async () => {
        const fetch = answer(200, shared('K2'));
        render(<CustomerPortalCard state={live} />);
        fireEvent.click(screen.getByTestId('portal-reissue'));
        expect(fetch).not.toHaveBeenCalled();
        fireEvent.click(screen.getAllByRole('button', { name: 'إعادة إصدار الرابط' }).at(-1)!);
        expect(await screen.findByTestId('portal-link')).toHaveTextContent('#K2');
        expect(fetch.mock.calls[0][0]).toBe(urls.reissue);
        expect(toast.success).toHaveBeenCalledWith('صدر رابط جديد، والقديم لم يعد يعمل');

        const gone = answer(200, { state });
        fireEvent.click(screen.getByTestId('portal-revoke'));
        expect(gone).not.toHaveBeenCalled();
        fireEvent.click(screen.getAllByRole('button', { name: 'إلغاء الرابط' }).at(-1)!);
        await screen.findByTestId('portal-make');
        expect(gone.mock.calls[0]).toMatchObject([urls.revoke, { method: 'DELETE' }]);
        expect(screen.queryByTestId('portal-link')).toBeNull();
        expect(screen.getByTestId('portal-customer-state')).toHaveTextContent('لم يصدر بعد');
    });

    it('ومن يشارك ولا يدير: ينسخ ويعرض الرمز، ولا إلغاءَ ولا إعادة', () => {
        render(<CustomerPortalCard state={sharer} />);
        expect(screen.getByTestId('portal-copy')).toBeEnabled();
        expect(screen.getByTestId('portal-qr')).toBeEnabled();
        expect(screen.queryByTestId('portal-reissue')).toBeNull();
        expect(screen.queryByTestId('portal-revoke')).toBeNull();
    });

    it('ومن لا رقمَ له يُقال له، ولا يُطلب له رابط', () => {
        const fetch = answer(200, shared());
        render(<CustomerPortalCard state={{ ...state, hasPhone: false }} />);
        expect(screen.getByText('أضف رقم هاتف العميل أولًا — يدخل به إلى بوابته.')).toBeInTheDocument();
        expect(screen.queryByTestId('portal-make')).toBeNull();
        expect(fetch).not.toHaveBeenCalled();
    });

    it('وخطأُ الخادم يُقال بنصّه، ولا يُنسخ شيءٌ ولا يُعرض رمز', async () => {
        answer(422, { message: 'أضف رقم هاتف العميل أولًا' });
        render(<CustomerPortalCard state={live} />);
        for (const id of ['portal-copy', 'portal-qr']) {
            fireEvent.click(screen.getByTestId(id));
            await waitFor(() => expect(toast.error).toHaveBeenCalledWith('أضف رقم هاتف العميل أولًا'));
            await waitFor(() => expect(screen.getByTestId(id)).toBeEnabled());
            toast.error.mockClear();
        }
        expect(write).not.toHaveBeenCalled();
        expect(screen.queryByTestId('portal-customer-qr')).toBeNull();
    });
});

describe('الصندوق', () => {
    it('زرٌّ واحد ينسخ رابطَ عميل البيعة', async () => {
        const fetch = answer(200, { url: `${URL_}#K1` });
        render(<PortalSaleLink url="/pos/orders/INV-1/portal" />);
        expect(screen.getAllByRole('button')).toHaveLength(1);
        fireEvent.click(screen.getByRole('button', { name: 'نسخ رابط بوابة العميل' }));
        await waitFor(() => expect(write).toHaveBeenCalledWith(`${URL_}#K1`));
        expect(await screen.findByRole('button', { name: 'نُسخ رابط بوابة العميل' })).toBeInTheDocument();
        expect(fetch.mock.calls[0][0]).toBe('/pos/orders/INV-1/portal');
        expect(screen.queryByTestId('portal-sale-link')).toBeNull();
        expect(screen.queryByRole('link')).toBeNull();
    });

    it('وجهازٌ يمنع الحافظة يعرض الرابطَ لينسخه بيده', async () => {
        clipboard(() => Promise.reject(new Error('blocked')));
        answer(200, { url: `${URL_}#K1` });
        render(<PortalSaleLink url="/pos/orders/INV-1/portal" />);
        fireEvent.click(screen.getByTestId('portal-sale'));
        expect(await screen.findByTestId('portal-sale-link')).toHaveTextContent(`${URL_}#K1`);
        expect(screen.queryByRole('button', { name: 'نُسخ رابط بوابة العميل' })).toBeNull();
        // وزرُّ النسخ بجانبه ضغطةٌ ثانية — وصلَ النصُّ قبلها
        clipboard(() => Promise.resolve());
        fireEvent.click(screen.getByRole('button', { name: 'نسخ' }));
        await waitFor(() => expect(write).toHaveBeenLastCalledWith(`${URL_}#K1`));
    });

    it('وفشلُه يُقال ويبقى الزرُّ — بلا استثناءٍ يوقف شاشةَ النجاح', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => { throw new Error('offline'); }));
        render(<PortalSaleLink url="/pos/orders/INV-1/portal" />);
        fireEvent.click(screen.getByTestId('portal-sale'));
        await waitFor(() => expect(toast.warning).toHaveBeenCalled());
        expect(screen.getByTestId('portal-sale')).toBeEnabled();
        expect(write).not.toHaveBeenCalled();
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
        const code = files.map(read).join('\n');
        const said = [...code.matchAll(/\b(?:t\(|message: |action: )'([^']+)'/g)].map((m) => m[1]).filter((k) => /[؀-ۿ]/.test(k));
        expect(said.length).toBeGreaterThan(35);
        for (const key of said) expect(en[key], key).toBeTruthy();
    });
});
