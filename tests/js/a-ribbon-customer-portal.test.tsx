import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { readFileSync } from 'node:fs';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { pageProps } from './setup';

/**
 * بوابةُ عملاء RIBBON في شاشات الموظّف — رابطٌ واحدٌ لجميع العملاء. ما لا يرسله الخادمُ
 * لا يُرسم، ورمزُ الدخول لا يُطلب حتّى يُضغط زرُّه، ولا قناةَ مراسلةٍ فيها.
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
const urls = { code: '/admin/customers/7/portal/code', revoke: '/admin/customers/7/portal' as string | null };
const state = { urls, url: URL_, hasPhone: true, since: null as string | null, used: null as string | null, devices: 0 };
const inside = { ...state, since: '2026-11-01 09:00', used: '2026-11-20 18:30', devices: 2 };

const answer = (status: number, body: unknown) => {
    const spy = vi.fn(async () => ({ ok: status < 400, status, json: async () => body }));
    vi.stubGlobal('fetch', spy);

    return spy as unknown as { mock: { calls: [string, RequestInit][] } } & typeof spy;
};
const write = vi.fn();
const files = ['Components/RibbonPortal.tsx', 'Components/RibbonPortalSale.tsx', 'Pages/Admin/Settings/CustomerPortal.tsx', 'lib/ribbon-portal.ts'];
const read = (f: string) => readFileSync(`resources/js/${f}`, 'utf8');

beforeEach(() => {
    Object.values(toast).forEach((f) => f.mockClear());
    write.mockReset();
    write.mockResolvedValue(undefined);
    Object.defineProperty(navigator, 'clipboard', { value: { writeText: write }, configurable: true });
    Object.assign(pageProps, { translations: {} });
});
afterEach(() => {
    vi.unstubAllGlobals();
    Reflect.deleteProperty(navigator, 'clipboard');
});

describe('قسمُ العملاء', () => {
    it('زرُّه يفتح الرابطَ الواحد ورمزَه ونسخَه وفتحَه وطباعتَه', async () => {
        render(<PortalButton portal={panel} />);
        fireEvent.click(screen.getByRole('button', { name: 'بوابة العملاء' }));

        expect(screen.getByTestId('portal-public-box')).toHaveTextContent(URL_);
        expect(screen.getByTestId('portal-public-box')).toHaveTextContent('رابط واحد ورمز واحد لجميع العملاء.');
        expect(screen.getByAltText('رمز QR لبوابة العملاء')).toHaveAttribute('src', panel.qr);
        fireEvent.click(screen.getByRole('button', { name: 'نسخ الرابط' }));
        await waitFor(() => expect(write).toHaveBeenCalledWith(URL_));
        expect(screen.getByRole('link', { name: 'فتح البوابة' })).toHaveAttribute('href', URL_);
        expect(screen.getByRole('link', { name: 'فتح البوابة' })).toHaveAttribute('rel', 'noreferrer');
        expect(screen.getByRole('link', { name: 'طباعة الرمز' })).toHaveAttribute('href', panel.poster);
    });

    it('وشاشاتُه لا ترسم شيئًا بلا ما يرسله الخادم — والزرُّ بجانب «إضافة عميل»', () => {
        expect(read('Pages/Admin/Customers/Index.tsx')).toMatch(/\{portal && <PortalButton portal=\{portal\} \/>\}\s*<Button onClick=\{\(\) => setAdding\(true\)\}>/);
        expect(read('Pages/Admin/Customers/Show.tsx')).toContain('{portalCustomer && <CustomerPortalCard key={JSON.stringify(portalCustomer)} state={portalCustomer} />}');
        expect(read('Pages/Admin/Settings/Index.tsx')).toContain('portalUrl && <PortalSettingsCard href={portalUrl} />');
        expect(read('Pages/Pos/partials/PaymentDialog.tsx')).toMatch(/result\?\.synced && result\.invoice && result\.portal && \(\s*<PortalSaleLink url=\{result\.portal\}/);
        // ولا اسمَ مسارٍ لها في كودٍ يصل كلَّ تاجر
        for (const f of files) expect(read(f)).not.toMatch(/route\(\s*[`'"](admin|pos)\.[^`'"]*portal/);
    });

    it('ولا ذكرَ لواتساب ولا لقناة مراسلة، ولا رابطَ لعميلٍ بعينه', () => {
        for (const f of files) {
            expect(read(f), f).not.toMatch(/whatsapp|wa\.me|واتساب|mailto:|sms:|MessageCircle|navigator\.share/i);
            expect(read(f), f).not.toMatch(/الرابط الشخصي|رابطه الشخصي|portal-make|portal-reissue/);
        }
    });
});

describe('ملفُّ العميل: «بوابة العميل»', () => {
    it('حالُ دخوله وأجهزتُه، ونسخُ الرابط الواحد', async () => {
        const fetch = answer(200, {});
        const { unmount } = render(<CustomerPortalCard state={state} />);
        expect(screen.getByTestId('portal-customer-state')).toHaveTextContent('لم يدخل بعد');
        fireEvent.click(screen.getByRole('button', { name: 'نسخ رابط البوابة' }));
        await waitFor(() => expect(write).toHaveBeenCalledWith(URL_));
        expect(fetch).not.toHaveBeenCalled();
        unmount();

        render(<CustomerPortalCard state={inside} />);
        expect(screen.getByTestId('portal-customer-state')).toHaveTextContent('دخل بوابته');
        for (const shown of ['2026-11-01 09:00', '2026-11-20 18:30', '2']) expect(screen.getByTestId('portal-customer')).toHaveTextContent(shown);
    });

    it('ورمزُ الدخول لا يُطلب حتّى يُضغط زرُّه', async () => {
        const fetch = answer(200, { code: '482 177 305' });
        render(<CustomerPortalCard state={inside} />);
        expect(screen.queryByTestId('portal-code')).toBeNull();
        fireEvent.click(screen.getByRole('button', { name: 'عرض رمز دخول' }));
        expect(await screen.findByTestId('portal-code')).toHaveTextContent('482 177 305');
        expect(screen.getByTestId('portal-code')).toHaveTextContent('تأكد أنه صاحب الرقم');
        expect(fetch.mock.calls).toHaveLength(1);
        expect(fetch.mock.calls[0][0]).toBe(urls.code);
        expect(fetch.mock.calls[0][1]).toMatchObject({ method: 'POST', cache: 'no-store' });
    });

    it('وإلغاءُ الدخول لمن يدير، وبتأكيدٍ يقول أثرَه', async () => {
        const fetch = answer(200, { code: '482 177 305' });
        render(<CustomerPortalCard state={inside} />);
        fireEvent.click(screen.getByTestId('portal-code-button'));
        await screen.findByTestId('portal-code');

        const gone = answer(200, { state });
        fireEvent.click(screen.getByTestId('portal-revoke'));
        expect(gone).not.toHaveBeenCalled();
        expect(screen.getByText(/وتقف رموز فواتيره المطبوعة قبل الآن/)).toBeInTheDocument();
        fireEvent.click(screen.getAllByRole('button', { name: 'إلغاء الدخول' }).at(-1)!);
        await waitFor(() => expect(screen.getByTestId('portal-customer-state')).toHaveTextContent('لم يدخل بعد'));
        expect(gone.mock.calls[0]).toMatchObject([urls.revoke, { method: 'DELETE' }]);
        // ورمزٌ قُرئ قبل الإلغاء لا يبقى على الشاشة: لم يعد يعمل
        expect(screen.queryByTestId('portal-code')).toBeNull();
        expect(fetch.mock.calls).toHaveLength(1);
    });

    it('ومن لا يدير لا زرَّ إلغاءٍ له', () => {
        render(<CustomerPortalCard state={{ ...inside, urls: { ...urls, revoke: null } }} />);
        expect(screen.getByTestId('portal-code-button')).toBeEnabled();
        expect(screen.queryByTestId('portal-revoke')).toBeNull();
    });

    it('ومن لا رقمَ له يُقال له، ولا يُطلب له شيء', () => {
        const fetch = answer(200, {});
        render(<CustomerPortalCard state={{ ...state, hasPhone: false }} />);
        expect(screen.getByText('أضف رقم هاتف العميل أولًا — يدخل به إلى بوابته.')).toBeInTheDocument();
        expect(screen.queryByTestId('portal-code-button')).toBeNull();
        expect(fetch).not.toHaveBeenCalled();
    });

    it('وخطأُ الخادم يُقال بنصّه، ولا يُعرض رمز', async () => {
        answer(422, { message: 'أضف رقم هاتف العميل أولًا' });
        render(<CustomerPortalCard state={state} />);
        fireEvent.click(screen.getByTestId('portal-code-button'));
        await waitFor(() => expect(toast.error).toHaveBeenCalledWith('أضف رقم هاتف العميل أولًا'));
        expect(screen.queryByTestId('portal-code')).toBeNull();
    });
});

describe('الصندوق', () => {
    it('زرٌّ واحد ينسخ الرابطَ الواحد بلا سؤال الخادم', async () => {
        const fetch = answer(200, {});
        render(<PortalSaleLink url={URL_} />);
        expect(screen.getAllByRole('button')).toHaveLength(1);
        fireEvent.click(screen.getByRole('button', { name: 'نسخ رابط بوابة العملاء' }));
        await waitFor(() => expect(write).toHaveBeenCalledWith(URL_));
        expect(await screen.findByRole('button', { name: 'نُسخ رابط بوابة العملاء' })).toBeInTheDocument();
        expect(fetch).not.toHaveBeenCalled();
        expect(screen.queryByRole('link')).toBeNull();
    });
});

describe('الإعدادات', () => {
    const page = (over = {}) => {
        Object.assign(pageProps, {
            translations: {}, portal: panel, urls: { self: '/s', staff: '/s/staff', revokeAll: '/s/links' }, mayGrant: true,
            links: [{ id: 3, customer: 'مريم', since: '2026-11-29 10:00', used: null, devices: 1, revokeUrl: '/s/links/3' }],
            pagination: { current_page: 1, last_page: 1, total: 1 },
            employees: [{ id: 1, name: 'منى', role: 'مبيعات', share: false, manage: false }, { id: 2, name: 'خالد', role: 'مبيعات', share: true, manage: true }],
        }, over);
        render(<CustomerPortal />);
    };

    it('حالتُها ورابطُها ومعاينتُها، ومن يدير يعرض', () => {
        page();
        expect(screen.getByTestId('portal-status')).toHaveTextContent('تعمل');
        expect(screen.getByRole('link', { name: 'معاينة الصفحة' })).toHaveAttribute('href', URL_);
        expect(screen.queryByTestId('portal-save-staff')).toBeNull();

        fireEvent.click(screen.getByLabelText('يدير البوابة — منى'));
        expect(screen.getByLabelText('يعرض البوابة — منى')).toBeChecked();
        // ومن نُزع عنه عرضُها نُزعت إدارتُها
        fireEvent.click(screen.getByLabelText('يعرض البوابة — خالد'));
        expect(screen.getByLabelText('يدير البوابة — خالد')).not.toBeChecked();

        fireEvent.click(screen.getByTestId('portal-save-staff'));
        expect(router.post).toHaveBeenCalledWith('/s/staff', { share: [1], manage: [1] }, expect.anything());
    });

    it('ومن ليس صاحبَ النشاط يرى من دخل ولا يمنح', () => {
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
        const said = [...code.matchAll(/\b(?:t\(|message: |action: |label=|done=)['\"]([^'\"]+)['\"]/g)].map((m) => m[1]).filter((k) => /[؀-ۿ]/.test(k));
        expect(said.length).toBeGreaterThan(35);
        for (const key of said) expect(en[key], key).toBeTruthy();
    });
});
