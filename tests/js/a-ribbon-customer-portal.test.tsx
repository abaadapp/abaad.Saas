import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { readFileSync } from 'node:fs';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { pageProps } from './setup';

/**
 * بوابةُ عملاء RIBBON في شاشات الموظّف — رابطٌ واحدٌ لجميع العملاء. ما لا يرسله الخادمُ
 * لا يُرسم، ورقمُ الجهاز يكتبه الموظّفُ ولا يُعرض له، ولا قناةَ مراسلةٍ فيها.
 */
vi.mock('@/Layouts/AdminLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <div>{children}</div> }));
const toast = vi.hoisted(() => ({ error: vi.fn(), warning: vi.fn(), success: vi.fn() }));
vi.mock('sonner', () => ({ toast }));

const { CustomerPortalCard, PortalButton, PortalSettingsCard } = await import('@/Components/RibbonPortal');
const { PortalSale } = await import('@/Components/RibbonPortalSale');
const { deviceDigits } = await import('@/lib/ribbon-portal');
const { default: CustomerPortal } = await import('@/Pages/Admin/Settings/CustomerPortal');
const { router } = await import('@inertiajs/react');

const URL_ = 'https://ribbon.abaadapp.om/my';
const panel = { url: URL_, qr: 'data:image/svg+xml;base64,AA==', poster: '/admin/customers/portal/poster', name: 'RIBBON', waiting: 0, waitingUrl: '/admin/customers/portal/waiting' };
const urls = { device: '/admin/customers/7/portal/device', revoke: '/admin/customers/7/portal' as string | null };
const state = { urls, url: URL_, hasPhone: true, waiting: [] as { device: string; at: string }[], since: null as string | null, used: null as string | null, devices: 0 };
const inside = { ...state, since: '2026-11-01 09:00', used: '2026-11-20 18:30', devices: 2 };
const maryam = { id: 7, name: 'مريم البلوشي', phone: '91234567', twin: false, devices: [{ device: 'iPhone · Safari', at: '10:02' }], url: urls.device };

const answer = (status: number, body: unknown) => {
    const spy = vi.fn(async () => ({ ok: status < 400, status, json: async () => body }));
    vi.stubGlobal('fetch', spy);

    return spy as unknown as { mock: { calls: [string, RequestInit][] } } & typeof spy;
};
const write = vi.fn();
const files = ['Components/RibbonPortal.tsx', 'Components/RibbonPortalDevice.tsx', 'Components/RibbonPortalSale.tsx', 'Pages/Admin/Settings/CustomerPortal.tsx', 'lib/ribbon-portal.ts'];
const read = (f: string) => readFileSync(`resources/js/${f}`, 'utf8');

beforeEach(() => {
    Object.values(toast).forEach((f) => f.mockClear());
    write.mockReset();
    write.mockResolvedValue(undefined);
    Object.defineProperty(navigator, 'clipboard', { value: { writeText: write }, configurable: true });
    Object.assign(pageProps, { translations: {} });
});
afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
    Reflect.deleteProperty(navigator, 'clipboard');
});

describe('قسمُ العملاء', () => {
    it('زرُّه يفتح الرابطَ الواحد ورمزَه ونسخَه وفتحَه وطباعتَه', async () => {
        answer(200, { waiting: [] });
        render(<PortalButton portal={panel} />);
        expect(screen.queryByTestId('portal-waiting-count')).toBeNull();
        fireEvent.click(screen.getByRole('button', { name: 'بوابة العملاء' }));

        expect(screen.getByTestId('portal-public-box')).toHaveTextContent(URL_);
        expect(screen.getByTestId('portal-public-box')).toHaveTextContent('رابط واحد ورمز QR واحد لجميع العملاء.');
        expect(screen.getByAltText('رمز QR لبوابة العملاء')).toHaveAttribute('src', panel.qr);
        fireEvent.click(screen.getByRole('button', { name: 'نسخ الرابط' }));
        await waitFor(() => expect(write).toHaveBeenCalledWith(URL_));
        expect(screen.getByRole('link', { name: 'فتح البوابة' })).toHaveAttribute('href', URL_);
        expect(screen.getByRole('link', { name: 'فتح البوابة' })).toHaveAttribute('rel', 'noreferrer');
        expect(screen.getByRole('link', { name: 'طباعة الرمز' })).toHaveAttribute('href', panel.poster);
    });

    it('ومن ينتظر يظهر فيه بعدده، ويتحدّث وهو مفتوح — ولا رقمَ جهازٍ معه', async () => {
        vi.useFakeTimers({ shouldAdvanceTime: true });
        const spy = answer(200, { waiting: [maryam] });
        render(<PortalButton portal={{ ...panel, waiting: 1 }} />);
        expect(screen.getByTestId('portal-waiting-count')).toHaveTextContent('1');
        expect(spy).not.toHaveBeenCalled();
        fireEvent.click(screen.getByTestId('portal-button'));

        const row = await screen.findByTestId('portal-waiting-row');
        expect(screen.getByTestId('portal-waiting')).toHaveClass('order-first');
        for (const shown of ['مريم البلوشي', '91234567', 'iPhone · Safari', '10:02']) expect(row).toHaveTextContent(shown);
        expect(row).not.toHaveTextContent('أكثر من عميل');
        expect(spy.mock.calls[0]).toMatchObject([panel.waitingUrl, { method: 'GET', cache: 'no-store' }]);

        const none = answer(200, { waiting: [] });
        await vi.advanceTimersByTimeAsync(8100);
        await waitFor(() => expect(screen.queryByTestId('portal-waiting-row')).toBeNull());
        expect(none).toHaveBeenCalledTimes(1);
        expect(screen.getByText(/لا جهاز ينتظر الآن/)).toBeInTheDocument();
        expect(screen.getByTestId('portal-waiting')).not.toHaveClass('order-first');
        expect(screen.queryByTestId('portal-waiting-count')).toBeNull();

        // وانقطاعٌ لا يمحو ما عُرض، ونافذةٌ أُغلقت لا تسأل
        answer(200, { waiting: [maryam] });
        await vi.advanceTimersByTimeAsync(8100);
        await screen.findByTestId('portal-waiting-row');
        answer(500, {});
        await vi.advanceTimersByTimeAsync(8100);
        expect(screen.getByTestId('portal-waiting-row')).toBeInTheDocument();
        fireEvent.keyDown(screen.getByRole('dialog'), { key: 'Escape' });
        await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
        const closed = answer(200, { waiting: [] });
        await vi.advanceTimersByTimeAsync(30000);
        expect(closed).not.toHaveBeenCalled();
    });

    it('ويُعتمد الجهازُ لصاحبه ويُرفض، ورقمٌ لعميلين يُقال', async () => {
        answer(200, { waiting: [{ ...maryam, twin: true }] });
        render(<PortalButton portal={{ ...panel, waiting: 1 }} />);
        fireEvent.click(screen.getByTestId('portal-button'));
        const row = await screen.findByTestId('portal-waiting-row');
        expect(row).toHaveTextContent('هذا الرقم مسجّل لأكثر من عميل');

        const spy = answer(200, { waiting: [] });
        fireEvent.click(screen.getByRole('button', { name: 'رفض' }));
        await waitFor(() => expect(screen.queryByTestId('portal-waiting-row')).toBeNull());
        expect(spy.mock.calls[0]).toMatchObject([maryam.url, { method: 'DELETE' }]);
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
            expect(read(f), f).not.toMatch(/الرابط الشخصي|رابطه الشخصي|رمز الدخول|رمز دخول|كلمة مرور|portal-make|portal-reissue/);
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

    it('يعتمد الجهازَ بالرقم الذي على شاشة العميل — يكتبه الموظّف', async () => {
        const fetch = answer(200, { state: inside });
        render(<CustomerPortalCard state={state} />);
        expect(screen.queryByTestId('portal-approve')).toBeNull();
        fireEvent.click(screen.getByTestId('portal-approve-button'));
        const box = screen.getByLabelText('رقم الجهاز الظاهر على شاشة العميل');
        expect(screen.getByTestId('portal-approve')).toHaveTextContent('اعتمد جهاز من يقف أمامك');
        expect(box).toHaveValue('');

        // ناقصٌ لا يُرسَل، وأرقامٌ عربيّةٌ تُقرأ، وما زاد يُقصّ
        fireEvent.change(box, { target: { value: '٤١' } });
        expect(screen.getByRole('button', { name: 'اعتماد' })).toBeDisabled();
        fireEvent.submit(screen.getByTestId('portal-approve'));
        expect(fetch).not.toHaveBeenCalled();
        fireEvent.change(box, { target: { value: ' ٤١٧٩ ' } });
        expect(box).toHaveValue('417');
        fireEvent.click(screen.getByRole('button', { name: 'اعتماد' }));

        await waitFor(() => expect(screen.getByTestId('portal-customer-state')).toHaveTextContent('دخل بوابته'));
        expect(fetch.mock.calls).toHaveLength(1);
        expect(fetch.mock.calls[0]).toMatchObject([urls.device, { method: 'POST', cache: 'no-store', body: JSON.stringify({ device: '417' }) }]);
        expect(toast.success).toHaveBeenCalledWith('اعتُمد الجهاز، وتفتح صفحة العميل وحدها');
        expect(screen.queryByTestId('portal-approve')).toBeNull();
    });

    it('وجهازٌ ينتظر يُعرض نوعُه ووقتُه، والخانةُ مفتوحة', () => {
        render(<CustomerPortalCard state={{ ...state, waiting: [{ device: 'iPhone · Safari', at: '10:02' }] }} />);
        expect(screen.getByTestId('portal-approve')).toBeInTheDocument();
        for (const shown of ['iPhone · Safari', '10:02']) expect(screen.getByTestId('portal-customer')).toHaveTextContent(shown);
    });

    it('وخطأُ الخادم يُقال بنصّه في مكانه، ويُمحى ما كُتب', async () => {
        answer(422, { message: 'لا جهاز ينتظر لهذا العميل بهذا الرقم.' });
        render(<CustomerPortalCard state={state} />);
        fireEvent.click(screen.getByTestId('portal-approve-button'));
        fireEvent.change(screen.getByLabelText('رقم الجهاز الظاهر على شاشة العميل'), { target: { value: '417' } });
        fireEvent.click(screen.getByRole('button', { name: 'اعتماد' }));
        expect(await screen.findByRole('alert')).toHaveTextContent('لا جهاز ينتظر لهذا العميل بهذا الرقم.');
        expect(screen.getByLabelText('رقم الجهاز الظاهر على شاشة العميل')).toHaveValue('');
        expect(toast.success).not.toHaveBeenCalled();
        expect(screen.getByTestId('portal-customer-state')).toHaveTextContent('لم يدخل بعد');
    });

    it('وإلغاءُ الدخول لمن يدير، وبتأكيدٍ يقول أثرَه', async () => {
        const gone = answer(200, { state });
        render(<CustomerPortalCard state={inside} />);
        fireEvent.click(screen.getByTestId('portal-revoke'));
        expect(gone).not.toHaveBeenCalled();
        expect(screen.getByText(/ولا يدخل حتى يُعتمد جهازه من جديد/)).toBeInTheDocument();
        fireEvent.click(screen.getAllByRole('button', { name: 'إلغاء الدخول' }).at(-1)!);
        await waitFor(() => expect(screen.getByTestId('portal-customer-state')).toHaveTextContent('لم يدخل بعد'));
        expect(gone.mock.calls[0]).toMatchObject([urls.revoke, { method: 'DELETE' }]);
        // والقولُ يقع عند الإلغاء وحده: أزرارٌ لم تُضغط لم تُرسَل
        expect(gone.mock.calls).toHaveLength(1);
    });

    it('ومن لا يدير لا زرَّ إلغاءٍ له', () => {
        render(<CustomerPortalCard state={{ ...inside, urls: { ...urls, revoke: null } }} />);
        expect(screen.getByTestId('portal-approve-button')).toBeEnabled();
        expect(screen.queryByTestId('portal-revoke')).toBeNull();
    });

    it('ومن لا رقمَ له يُقال له، ولا يُطلب له شيء', () => {
        const fetch = answer(200, {});
        render(<CustomerPortalCard state={{ ...state, hasPhone: false }} />);
        expect(screen.getByText('أضف رقم هاتف العميل أولًا — يدخل به إلى بوابته.')).toBeInTheDocument();
        expect(screen.queryByTestId('portal-approve-button')).toBeNull();
        expect(fetch).not.toHaveBeenCalled();
    });

    it('ورقمُ الجهاز ثلاثُ خاناتٍ بأيّ أرقامٍ كُتب', () => {
        expect(['417', '٤١٧', '۴۱۷', ' 4-1 7', '41789', 'abc', ''].map(deviceDigits)).toEqual(['417', '417', '417', '417', '417', '', '']);
        // كما هو في الخادم
        expect(readFileSync('app/Support/RibbonPortal/Devices.php', 'utf8')).toMatch(/const DIGITS = 3;/);
    });
});

describe('الصندوق', () => {
    const sale = { url: URL_, device: '/pos/orders/INV-1/portal' };

    it('ينسخ الرابطَ الواحد بلا سؤال الخادم', async () => {
        const fetch = answer(200, {});
        render(<PortalSale portal={sale} />);
        fireEvent.click(screen.getByRole('button', { name: 'نسخ رابط البوابة' }));
        await waitFor(() => expect(write).toHaveBeenCalledWith(URL_));
        expect(fetch).not.toHaveBeenCalled();
        expect(screen.queryByRole('link')).toBeNull();
        expect(screen.queryByTestId('portal-approve')).toBeNull();
    });

    it('ويعتمد جهازَ عميل البيعة، ولا يُرسل نموذجًا حوله', async () => {
        const fetch = answer(200, { ok: true });
        const around = vi.fn((e: React.FormEvent) => e.preventDefault());
        render(<div onSubmit={around}><PortalSale portal={sale} /></div>);
        fireEvent.click(screen.getByTestId('portal-sale-device'));
        fireEvent.change(screen.getByLabelText('رقم الجهاز الظاهر على شاشة العميل'), { target: { value: '417' } });
        fireEvent.click(screen.getByRole('button', { name: 'اعتماد' }));

        await waitFor(() => expect(screen.getByTestId('portal-sale-device')).toHaveTextContent('اعتُمد الجهاز'));
        expect(screen.getByTestId('portal-sale-device')).toBeDisabled();
        expect(fetch.mock.calls[0]).toMatchObject([sale.device, { method: 'POST', body: JSON.stringify({ device: '417' }) }]);
        expect(around).not.toHaveBeenCalled();
    });

    it('وردُّ البيعة بلا بابٍ كاملٍ لا يرسم شيئًا', () => {
        expect(read('hooks/usePosCart.ts')).toContain("portal: typeof d.portal?.url === 'string' && typeof d.portal.device === 'string' ? d.portal : null,");
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

    it('حالتُها ورابطُها ومعاينتُها، ومن يدير يعتمد', () => {
        page();
        expect(screen.getByTestId('portal-status')).toHaveTextContent('تعمل');
        expect(screen.getByRole('link', { name: 'معاينة الصفحة' })).toHaveAttribute('href', URL_);
        expect(screen.queryByTestId('portal-save-staff')).toBeNull();

        fireEvent.click(screen.getByLabelText('يدير البوابة — منى'));
        expect(screen.getByLabelText('يعتمد الأجهزة — منى')).toBeChecked();
        // ومن نُزع عنه عرضُها نُزعت إدارتُها
        fireEvent.click(screen.getByLabelText('يعتمد الأجهزة — خالد'));
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
        const said = [...code.matchAll(/(?:\bt\(|\bmessage: |\baction: |\blabel=|\bdone=|[?:] )['\"]([^'\"]+)['\"]/g)].map((m) => m[1]).filter((k) => /[؀-ۿ]/.test(k));
        expect(said.length).toBeGreaterThan(35);
        for (const key of said) expect(en[key], key).toBeTruthy();
    });
});
