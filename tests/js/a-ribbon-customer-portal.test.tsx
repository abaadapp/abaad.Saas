import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { readFileSync } from 'node:fs';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { pageProps } from './setup';

/**
 * بوابةُ عملاء RIBBON في شاشات الموظّف — رابطٌ واحدٌ لجميع العملاء. ما لا يرسله الخادمُ
 * لا يُرسم، ولا اعتمادَ ولا رقمَ جهاز، ولا قناةَ مراسلةٍ فيها.
 */
vi.mock('@/Layouts/AdminLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <div>{children}</div> }));
const toast = vi.hoisted(() => ({ error: vi.fn(), warning: vi.fn(), success: vi.fn() }));
vi.mock('sonner', () => ({ toast }));

const { CustomerPortalCard, PortalButton, PortalSettingsCard } = await import('@/Components/RibbonPortal');
const { PortalSale } = await import('@/Components/RibbonPortalSale');
const { default: CustomerPortal } = await import('@/Pages/Admin/Settings/CustomerPortal');
const { router } = await import('@inertiajs/react');

const URL_ = 'https://ribbon.abaadapp.om/my';
const panel = { url: URL_, qr: 'data:image/svg+xml;base64,AA==', poster: '/admin/customers/portal/poster', name: 'RIBBON' };
const state = { manage: '/admin/customers/7/portal' as string | null, url: URL_, phone: '91234567' as string | null, stopped: false, since: null as string | null, used: null as string | null, devices: 0 };
const inside = { ...state, since: '2026-11-01 09:00', used: '2026-11-20 18:30', devices: 3 };

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
        const fetch = answer(200, {});
        render(<PortalButton portal={panel} />);
        fireEvent.click(screen.getByRole('button', { name: 'بوابة العملاء' }));

        expect(screen.getByTestId('portal-public-box')).toHaveTextContent(URL_);
        expect(screen.getByTestId('portal-public-box')).toHaveTextContent('رابط واحد ورمز QR واحد لجميع العملاء.');
        expect(screen.getByAltText('رمز QR لبوابة العملاء')).toHaveAttribute('src', panel.qr);
        fireEvent.click(screen.getByRole('button', { name: 'نسخ الرابط' }));
        await waitFor(() => expect(write).toHaveBeenCalledWith(URL_));
        expect(screen.getByRole('link', { name: 'فتح البوابة' })).toHaveAttribute('href', URL_);
        expect(screen.getByRole('link', { name: 'فتح البوابة' })).toHaveAttribute('rel', 'noreferrer');
        expect(screen.getByRole('link', { name: 'طباعة الرمز' })).toHaveAttribute('href', panel.poster);
        // ولا جهازَ ينتظر ولا خانةَ اعتماد، ولا سؤالَ للخادم
        expect(screen.getByRole('dialog')).not.toHaveTextContent(/اعتماد|رقم الجهاز|تنتظر/);
        expect(screen.queryByRole('textbox')).toBeNull();
        expect(fetch).not.toHaveBeenCalled();
    });

    it('وشاشاتُه لا ترسم شيئًا بلا ما يرسله الخادم — والزرُّ بجانب «إضافة عميل»', () => {
        expect(read('Pages/Admin/Customers/Index.tsx')).toMatch(/\{portal && <PortalButton portal=\{portal\} \/>\}\s*<Button onClick=\{\(\) => setAdding\(true\)\}>/);
        expect(read('Pages/Admin/Customers/Show.tsx')).toContain('{portalCustomer && <CustomerPortalCard key={JSON.stringify(portalCustomer)} state={portalCustomer} />}');
        expect(read('Pages/Admin/Settings/Index.tsx')).toContain('portalUrl && <PortalSettingsCard href={portalUrl} />');
        expect(read('Pages/Pos/partials/PaymentDialog.tsx')).toMatch(/result\?\.synced && result\.invoice && result\.portal && \(\s*<PortalSale portal=\{result\.portal\}/);
        // ولا اسمَ مسارٍ لها في كودٍ يصل كلَّ تاجر
        for (const f of files) expect(read(f)).not.toMatch(/route\(\s*[`'"](admin|pos)\.[^`'"]*portal/);
    });

    it('ولا ذكرَ لواتساب ولا لقناة مراسلة، ولا رابطَ لعميلٍ بعينه', () => {
        for (const f of files) {
            expect(read(f), f).not.toMatch(/whatsapp|wa\.me|واتساب|mailto:|sms:|MessageCircle|navigator\.share/i);
            expect(read(f), f).not.toMatch(/الرابط الشخصي|رابطه الشخصي|رمز الدخول|رمز دخول|كلمة مرور|رقم الجهاز|اعتماد جهاز|يعتمد الأجهزة|portal-make|portal-reissue|portal-approve/);
        }
    });
});

describe('ملفُّ العميل: «بوابة العميل»', () => {
    it('حالُ دخوله وكم جهازًا، ونسخُ الرابط الواحد', async () => {
        const fetch = answer(200, {});
        const { unmount } = render(<CustomerPortalCard state={state} />);
        expect(screen.getByTestId('portal-customer-state')).toHaveTextContent('لم يدخل بعد');
        expect(screen.queryByTestId('portal-devices')).toBeNull();
        fireEvent.click(screen.getByRole('button', { name: 'نسخ رابط البوابة' }));
        await waitFor(() => expect(write).toHaveBeenCalledWith(URL_));
        expect(fetch).not.toHaveBeenCalled();
        unmount();

        render(<CustomerPortalCard state={inside} />);
        expect(screen.getByTestId('portal-customer-state')).toHaveTextContent('دخل بوابته');
        for (const shown of ['2026-11-01 09:00', '2026-11-20 18:30']) expect(screen.getByTestId('portal-customer')).toHaveTextContent(shown);
        expect(screen.getByTestId('portal-devices')).toHaveTextContent('3');
        expect(screen.queryByRole('textbox')).toBeNull();
    });

    it('وإيقافُ الدخول لمن يدير، وبتأكيدٍ يقول أثرَه — ثمّ السماحُ به', async () => {
        const gone = answer(200, { state: { ...state, stopped: true } });
        render(<CustomerPortalCard state={inside} />);
        expect(screen.queryByTestId('portal-allow')).toBeNull();
        fireEvent.click(screen.getByTestId('portal-stop'));
        expect(gone).not.toHaveBeenCalled();
        expect(screen.getByText(/ولا تفتح صفحته برقم هاتفه حتى تسمح بذلك/)).toBeInTheDocument();
        fireEvent.click(screen.getAllByRole('button', { name: 'إيقاف الدخول' }).at(-1)!);
        await waitFor(() => expect(screen.getByTestId('portal-customer-state')).toHaveTextContent('الدخول موقوف'));
        expect(gone.mock.calls).toHaveLength(1);
        expect(gone.mock.calls[0]).toMatchObject([state.manage, { method: 'DELETE', cache: 'no-store' }]);
        expect(toast.success).toHaveBeenCalledWith('أُوقف الدخول وخرج العميل من أجهزته');
        expect(screen.queryByTestId('portal-stop')).toBeNull();

        const back = answer(200, { state });
        fireEvent.click(screen.getByTestId('portal-allow'));
        await waitFor(() => expect(screen.getByTestId('portal-customer-state')).toHaveTextContent('لم يدخل بعد'));
        expect(back.mock.calls[0]).toMatchObject([state.manage, { method: 'POST' }]);
        expect(toast.success).toHaveBeenLastCalledWith('سُمح بالدخول، وتفتح صفحة العميل برقم هاتفه');
    });

    it('ومن عدل عن الإيقاف لم يُرسل شيئًا', async () => {
        const fetch = answer(200, { state });
        render(<CustomerPortalCard state={inside} />);
        fireEvent.click(screen.getByTestId('portal-stop'));
        fireEvent.click(screen.getByRole('button', { name: 'إلغاء' }));
        await waitFor(() => expect(screen.queryByText(/ولا تفتح صفحته برقم هاتفه/)).toBeNull());
        expect(fetch).not.toHaveBeenCalled();
    });

    it('وخطأُ الخادم يُقال بنصّه، وتبقى الحالُ كما هي', async () => {
        answer(403, { message: 'ليس لك' });
        render(<CustomerPortalCard state={{ ...state, stopped: true }} />);
        fireEvent.click(screen.getByTestId('portal-allow'));
        await waitFor(() => expect(toast.error).toHaveBeenCalledWith('ليس لك'));
        expect(screen.getByTestId('portal-customer-state')).toHaveTextContent('الدخول موقوف');
        expect(toast.success).not.toHaveBeenCalled();
    });

    it('ومن لا يدير يرى الحالَ ولا زرَّ له', () => {
        const { unmount } = render(<CustomerPortalCard state={{ ...inside, manage: null }} />);
        expect(screen.queryByTestId('portal-stop')).toBeNull();
        unmount();
        render(<CustomerPortalCard state={{ ...inside, manage: null, stopped: true }} />);
        expect(screen.getByTestId('portal-customer-state')).toHaveTextContent('الدخول موقوف');
        expect(screen.queryByTestId('portal-allow')).toBeNull();
    });

    it('ومن لا رقمَ له يُقال له، ولا يُطلب له شيء', () => {
        const fetch = answer(200, {});
        render(<CustomerPortalCard state={{ ...state, phone: null }} />);
        expect(screen.getByText('أضف رقم هاتف العميل أولًا — يدخل به إلى بوابته.')).toBeInTheDocument();
        expect(screen.queryByRole('button')).toBeNull();
        expect(fetch).not.toHaveBeenCalled();
    });
});

describe('الصندوق', () => {
    it('ينسخ الرابطَ الواحد بلا سؤال الخادم، ولا شيءَ سواه', async () => {
        const fetch = answer(200, {});
        render(<PortalSale portal={{ url: URL_ }} />);
        fireEvent.click(screen.getByRole('button', { name: 'نسخ رابط البوابة' }));
        await waitFor(() => expect(write).toHaveBeenCalledWith(URL_));
        expect(fetch).not.toHaveBeenCalled();
        expect(screen.queryByRole('link')).toBeNull();
        expect(screen.getAllByRole('button')).toHaveLength(1);
    });

    it('وردُّ البيعة بلا رابطٍ لا يرسم شيئًا', () => {
        expect(read('hooks/usePosCart.ts')).toContain("portal: typeof d.portal?.url === 'string' ? d.portal : null,");
    });
});

describe('الإعدادات', () => {
    const page = (over = {}) => {
        Object.assign(pageProps, {
            translations: {}, portal: panel, urls: { self: '/s', staff: '/s/staff', signOut: '/s/links' }, mayGrant: true,
            links: [
                { id: 3, customer: 'مريم', since: '2026-11-29 10:00', used: '2026-11-30 11:00', devices: 2, stopped: false, url: '/s/links/3' },
                { id: 4, customer: 'سارة', since: null, used: null, devices: 0, stopped: true, url: '/s/links/4' },
            ],
            pagination: { current_page: 1, last_page: 1, total: 2 },
            employees: [{ id: 1, name: 'منى', role: 'مبيعات', share: false, manage: false }, { id: 2, name: 'خالد', role: 'مبيعات', share: true, manage: true }],
        }, over);
        render(<CustomerPortal />);
    };

    it('حالتُها ورابطُها ومعاينتُها، ومن يدير يراها', () => {
        page();
        expect(screen.getByTestId('portal-status')).toHaveTextContent('تعمل');
        expect(screen.getByRole('link', { name: 'معاينة الصفحة' })).toHaveAttribute('href', URL_);
        expect(screen.queryByTestId('portal-save-staff')).toBeNull();

        fireEvent.click(screen.getByLabelText('يدير البوابة — منى'));
        expect(screen.getByLabelText('يرى البوابة — منى')).toBeChecked();
        // ومن نُزع عنه عرضُها نُزعت إدارتُها
        fireEvent.click(screen.getByLabelText('يرى البوابة — خالد'));
        expect(screen.getByLabelText('يدير البوابة — خالد')).not.toBeChecked();

        fireEvent.click(screen.getByTestId('portal-save-staff'));
        expect(router.post).toHaveBeenCalledWith('/s/staff', { share: [1], manage: [1] }, expect.anything());
    });

    it('ومن ليس صاحبَ النشاط يرى من دخل ولا يمنح', () => {
        page({ mayGrant: false });
        for (const box of screen.getAllByRole('checkbox')) expect(box).toBeDisabled();
        expect(screen.getAllByTestId('portal-link-row')).toHaveLength(2);
        expect(screen.getByRole('button', { name: 'إيقاف الدخول' })).toBeEnabled();
        expect(screen.getByTestId('portal-sign-out')).toBeInTheDocument();
    });

    it('ويقول لصاحب النشاط أثرَ الرقم وحده، ويوقف ويسمح ويُخرج الجميع', async () => {
        page();
        expect(screen.getByTestId('portal-open-note')).toHaveTextContent('من يكتب رقم عميل في البوابة يرى مشترياته وفواتيره ونقاطه.');
        const [maryam, sara] = screen.getAllByTestId('portal-link-row');
        for (const shown of ['مريم', '2026-11-29 10:00', '2026-11-30 11:00', '2', 'إيقاف الدخول']) expect(maryam).toHaveTextContent(shown);
        expect(maryam).not.toHaveTextContent('موقوف');
        for (const shown of ['سارة', 'الدخول موقوف', 'السماح بالدخول']) expect(sara).toHaveTextContent(shown);

        fireEvent.click(screen.getByRole('button', { name: 'السماح بالدخول' }));
        expect(router.post).toHaveBeenCalledWith('/s/links/4', {}, expect.anything());

        fireEvent.click(screen.getByRole('button', { name: 'إيقاف الدخول' }));
        expect(router.delete).not.toHaveBeenCalled();
        fireEvent.click((await screen.findAllByRole('button', { name: 'إيقاف الدخول' })).at(-1)!);
        await waitFor(() => expect(router.delete).toHaveBeenCalledWith('/s/links/3', expect.anything()));

        fireEvent.click(screen.getByTestId('portal-sign-out'));
        expect(screen.getByText(/ويدخل كل منهم من جديد برقم هاتفه/)).toBeInTheDocument();
        fireEvent.click(screen.getAllByRole('button', { name: 'إخراج الجميع' }).at(-1)!);
        await waitFor(() => expect(router.delete).toHaveBeenLastCalledWith('/s/links', expect.anything()));
    });

    it('ولا زرَّ «إخراج الجميع» ولا أحدَ داخل', () => {
        page({ links: [{ id: 4, customer: 'سارة', since: null, used: null, devices: 0, stopped: true, url: '/s/links/4' }] });
        expect(screen.queryByTestId('portal-sign-out')).toBeNull();
    });

    it('وبطاقتُها تفتح صفحتَها', () => {
        render(<PortalSettingsCard href="/admin/settings/customer-portal" />);
        expect(screen.getByTestId('portal-settings-card')).toHaveAttribute('href', '/admin/settings/customer-portal');
    });

    it('ولكلّ نصٍّ فيها إنجليزيّتُه', () => {
        const en = JSON.parse(readFileSync('lang/en.json', 'utf8')) as Record<string, string>;
        const code = files.map(read).join('\n');
        const said = [...code.matchAll(/(?:\bt\(|\bmessage: |\baction: |\blabel=|\bdone=|[?:] )['\"]([^'\"]+)['\"]/g)].map((m) => m[1]).filter((k) => /[؀-ۿ]/.test(k));
        expect(said.length).toBeGreaterThan(25);
        for (const key of said) expect(en[key], key).toBeTruthy();
    });
});
