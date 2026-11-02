import { useState } from 'react';
import { act, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { pageProps } from './setup';

/**
 * صندوقٌ واحدٌ لصاحب المتجر وموظّفيه — بشكل واتساب ويب.
 *
 *  • القائمةُ: «الكل» و«غير المقروءة» بعدّها، والمرشِّحُ يُسأل في الخادم.
 *  • التفاصيلُ: الطلباتُ والفواتيرُ وحالُ آخر طلب ورقمُ المتجر — وتُخفى على
 *    الشاشة العريضة ويُحفظ الاختيارُ لهذا المتصفّح.
 *  • الردّ: يحمل مفتاحَه، والضغطةُ الثانية بالمفتاح نفسِه فلا يخرج مرّتين.
 *  • التحديث: ختمٌ تبدّل يعيد القائمةَ والخيطَ وعدَّ غير المقروء — والتبويبُ
 *    المخفيّ لا يسأل.
 *  • شاشةُ الموظّفين: مستوى لكلّ موظّف، وعدّةُ موظّفين بضغطة، ولا يخرج إلّا ما تبدّل.
 *
 * والخادمُ يحكم بها كلِّها: `AShopAndItsStaffShareOneWhatsAppInboxTest`.
 */

const sent: { url: string; payload: Record<string, unknown> }[] = [];

vi.mock('@inertiajs/react', async () => {
    const actual = await vi.importActual<Record<string, unknown>>('@inertiajs/react');

    const useForm = (initial: Record<string, unknown>) => {
        const [data, setData] = useState(initial);
        const send = (url: string) => sent.push({ url, payload: { ...data } });

        return {
            data,
            errors: {},
            processing: false,
            setData: (key: unknown, value?: unknown) =>
                setData((d) => (typeof key === 'string' ? { ...d, [key]: value } : { ...d, ...(key as Record<string, unknown>) })),
            transform: () => {},
            post: send,
            put: send,
            reset: () => setData(initial),
            clearErrors: () => {},
        };
    };

    return {
        ...actual,
        usePage: () => ({ props: pageProps, url: '/', component: 'Test' }),
        router: { visit: vi.fn(), get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn(), reload: vi.fn(), on: vi.fn(() => () => {}) },
        Head: () => null,
        Link: ({ href, children, ...rest }: { href: string; children: React.ReactNode }) => <a href={href} {...rest}>{children}</a>,
        useForm,
    };
});

vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

const { router } = await import('@inertiajs/react');
const { default: WhatsAppInbox, POLL_MS } = await import('@/Pages/Admin/WhatsAppInbox/Index');
const { default: InboxAccessRoster } = await import('@/Pages/Admin/Employees/partials/InboxAccessRoster');

type Props = Record<string, unknown>;

const THREAD = {
    id: 7, name: 'سارة', phone: '96891234567', profile_name: 'سارة', customer: { name: 'سارة', url: '/admin.customers.show/3' },
    orders: [{ number: 'W-1042', total: 25, status: 'قيد التجهيز', url: '/admin.orders.show/W-1042' }],
    invoices: [{ number: 'CINV-000007', total: 40, status: 'صادرة', url: '/admin.customerInvoices.show/9' }],
    source: 'business_own', line_number: '+968 7000 0001', window_ends_at: '2026-10-29 10:30', blocked: null,
    template: null, log_url: null, has_more: false, before: null,
    messages: [
        { kind: 'in', key: 'm1', at: '2026-10-27 18:00', time: '18:00', body: 'مساء الخير', status: 'received', status_label: null, attachments: [] },
        { kind: 'in', key: 'm2', at: '2026-10-28 10:30', time: '10:30', body: 'متى يصل؟', status: 'received', status_label: null, attachments: [] },
        { kind: 'out', key: 'm3', at: '2026-10-28 10:31', time: '10:31', body: 'خلال ساعة', sender: 'منى', status: 'read', status_label: 'قرأها الزبون', attachments: [] },
        { kind: 'out', key: 'm4', at: '2026-10-28 10:32', time: '10:32', body: 'وشكرًا لصبرك', sender: 'سعود', status: 'sent', status_label: 'أُرسلت', attachments: [] },
    ],
};

const draw = (over: Props = {}) => {
    for (const k of Object.keys(pageProps)) delete (pageProps as Props)[k];
    Object.assign(pageProps, {
        translations: {},
        auth: { abilities: [], mayActions: ['whatsapp.inbox.view', 'whatsapp.inbox.reply'], planFeatures: { whatsapp: true } },
        context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 }, hosted: ['whatsapp_inbox'] },
        chats: [
            { id: 7, key: 'c7', url: null, name: 'سارة', phone: '96891234567', at: '2026-10-28 10:32', preview: 'وشكرًا لصبرك', direction: 'out', unread: 0, source: 'business_own' },
            { id: 8, key: 'c8', url: null, name: null, phone: '96899887766', at: '2026-10-28 09:00', preview: 'أريد التوصيل اليوم', direction: 'in', unread: 3, source: 'business_own' },
        ],
        pagination: { current_page: 1, last_page: 1, prev_page_url: null, next_page_url: null },
        params: { q: '', c: '7', f: 'all' },
        thread: THREAD,
        canReply: true,
        line: { mode: 'business_own', number: '+968 7000 0001', usable: true, status: 'active', expires_at: null },
        unread: 3,
        unreadChats: 1,
        stamp: '4|2026-10-28 10:32:00|0|',
        files: { max: 3, max_kb: 10240, accept: '.jpg,.jpeg,.png,.webp,.pdf' },
        readiness: { connected: true, ready: true, steps: [] },
        mayManage: true,
        ...over,
    });

    return render(<WhatsAppInbox />);
};

const fetchMock = vi.fn();

beforeEach(() => {
    sent.length = 0;
    fetchMock.mockReset();
    vi.mocked(router.get).mockClear();
    vi.mocked(router.post).mockClear();
    vi.mocked(router.reload).mockClear();
    vi.stubGlobal('fetch', fetchMock);
    try {
        window.localStorage.clear();
    } catch {
        /* لا تخزين */
    }
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

describe('القائمة', () => {
    it('«الكل» و«غير المقروءة» بعدّ المحادثات — والمرشِّحُ يُسأل في الخادم والمحادثةُ تبقى مفتوحة', async () => {
        draw();
        const user = userEvent.setup();

        const unread = screen.getByRole('button', { name: /غير المقروءة/ });
        expect(unread).toHaveTextContent('1');
        expect(screen.getByRole('button', { name: 'الكل' })).toHaveAttribute('aria-pressed', 'true');

        await user.click(unread);
        expect(router.get).toHaveBeenCalledWith('/admin.whatsappInbox.index', { f: 'unread', c: '7' }, expect.objectContaining({ preserveState: true }));
    });

    it('صفٌّ لم يُقرأ بعدِّه — وفتحُه يطلب المحادثةَ بعينها', async () => {
        draw();

        const rows = within(screen.getByTestId('chat-list')).getAllByTestId('chat-row');
        expect(rows).toHaveLength(2);
        expect(rows[0]).toHaveAttribute('aria-current', 'true');
        expect(within(rows[1]).getByTestId('chat-unread')).toHaveTextContent('3');

        await userEvent.setup().click(rows[1]);
        expect(router.get).toHaveBeenCalledWith('/admin.whatsappInbox.index', { c: 8 }, expect.anything());
    });

    it('ولا غيرَ مقروءٍ يُقال ذلك صراحةً', () => {
        draw({ chats: [], params: { q: '', c: null, f: 'unread' }, thread: null, unreadChats: 0 });

        expect(screen.getByText('لا محادثات غير مقروءة')).toBeInTheDocument();
        expect(screen.queryByTestId('inbox-empty')).toBeNull();
    });
});

describe('الخيط', () => {
    it('كلُّ ردٍّ باسم كاتبه وبعلامته — وفاصلُ يومٍ بين يومين', () => {
        draw();
        const thread = screen.getByTestId('thread');

        const outs = within(thread).getAllByTestId('bubble-out');
        expect(outs[0]).toHaveTextContent('منى');
        expect(outs[1]).toHaveTextContent('سعود');
        expect(within(outs[0]).getByRole('img', { name: 'قرأها الزبون' })).toBeInTheDocument();
        expect(within(outs[1]).getByRole('img', { name: 'أُرسلت' })).toBeInTheDocument();

        expect(within(thread).getByText('2026-10-27')).toBeInTheDocument();
        expect(within(thread).getByText('2026-10-28')).toBeInTheDocument();
    });

    it('الردُّ يحمل مفتاحَه — والضغطةُ الثانية بالمفتاح نفسِه فلا يخرج مرّتين', async () => {
        draw();
        const user = userEvent.setup();

        await user.type(screen.getByTestId('inbox-body'), 'تم');
        await user.click(screen.getByTestId('inbox-send'));
        await user.click(screen.getByTestId('inbox-send'));

        expect(sent).toHaveLength(2);
        expect(sent[0].url).toBe('/admin.whatsappInbox.reply/7');
        expect(typeof sent[0].payload.client_key).toBe('string');
        expect((sent[0].payload.client_key as string).length).toBeGreaterThan(8);
        expect(sent[1].payload.client_key).toBe(sent[0].payload.client_key);
    });

    it('ولا يُرسل الفارغ', async () => {
        draw();

        expect(screen.getByTestId('inbox-send')).toBeDisabled();
    });
});

describe('تفاصيل العميل', () => {
    it('الطلباتُ والفواتيرُ وحالُ آخر طلب ورقمُ المتجر', () => {
        draw();

        expect(screen.getByTestId('thread-orders')).toHaveTextContent('W-1042');
        expect(screen.getByTestId('thread-invoices')).toHaveTextContent('CINV-000007');
        expect(screen.getByText('حالة آخر طلب').nextElementSibling).toHaveTextContent('قيد التجهيز');
        expect(screen.getByText('رقم المتجر').nextElementSibling).toHaveTextContent('+968 7000 0001');
        expect(screen.getByTestId('thread-customer')).toHaveAttribute('href', '/admin.customers.show/3');
    });

    it('وتُخفى على الشاشة العريضة — ويُحفظ الاختيارُ لهذا المتصفّح', async () => {
        draw();
        const user = userEvent.setup();

        const toggle = screen.getByTestId('inbox-details-toggle');
        expect(toggle).toHaveAccessibleName('إخفاء تفاصيل العميل');

        await user.click(toggle);
        expect(screen.getByTestId('inbox-details-toggle')).toHaveAccessibleName('إظهار تفاصيل العميل');
        expect(window.localStorage.getItem('wa-inbox-details-hidden')).toBe('1');
    });
});

describe('التحديث', () => {
    it('ختمٌ تبدّل يعيد القائمةَ والخيطَ وعدَّ غير المقروء', async () => {
        vi.useFakeTimers();
        fetchMock.mockResolvedValue({ ok: true, status: 200, json: async () => ({ stamp: '5|2026-10-28 10:40:00|0|', can_reply: true }) });
        draw();

        await act(async () => {
            await vi.advanceTimersByTimeAsync(POLL_MS);
        });

        expect(router.reload).toHaveBeenCalledWith({ only: expect.arrayContaining(['chats', 'thread', 'unreadChats', 'stamp']) });
    });

    it('والتبويبُ المخفيّ لا يسأل', async () => {
        vi.useFakeTimers();
        const visibility = vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('hidden');
        draw();

        await act(async () => {
            await vi.advanceTimersByTimeAsync(POLL_MS * 2);
        });

        expect(fetchMock).not.toHaveBeenCalled();
        visibility.mockRestore();
    });
});

describe('شاشة الموظفين: محادثات واتساب لعدّة موظفين', () => {
    const roster = {
        staff: [
            { id: 11, name: 'سهى', job_title: 'بائعة', active: true, level: 'view' as const },
            { id: 12, name: 'منى', job_title: 'بائعة', active: true, level: 'reply' as const },
            { id: 13, name: 'خالد', job_title: null, active: false, level: 'none' as const },
        ],
    };

    const drawRoster = () => {
        for (const k of Object.keys(pageProps)) delete (pageProps as Props)[k];
        Object.assign(pageProps, { translations: {} });
        return render(<InboxAccessRoster roster={roster} />);
    };

    it('مستوى لكلّ موظّف — ولا يخرج إلّا ما تبدّل', async () => {
        drawRoster();
        const user = userEvent.setup();

        expect(screen.getAllByTestId('inbox-roster-row')).toHaveLength(3);
        expect(screen.getByTestId('inbox-roster-save')).toBeDisabled();
        expect(screen.getByText(/موقوف/)).toBeInTheDocument();

        await user.click(screen.getByTestId('inbox-roster-13-reply'));
        expect(screen.getByTestId('inbox-roster-changed')).toHaveTextContent('1');

        await user.click(screen.getByTestId('inbox-roster-save'));
        expect(router.post).toHaveBeenCalledWith('/admin.employees.inboxAccess', { levels: { 13: 'reply' } }, expect.objectContaining({ preserveScroll: true }));
    });

    it('عدّةُ موظّفين بضغطة — منحًا أو سحبًا', async () => {
        drawRoster();
        const user = userEvent.setup();

        expect(screen.getByTestId('inbox-roster-bulk-reply')).toBeDisabled();
        await user.click(screen.getByTestId('inbox-roster-all'));
        await user.click(screen.getByTestId('inbox-roster-bulk-none'));
        await user.click(screen.getByTestId('inbox-roster-save'));

        expect(router.post).toHaveBeenCalledWith('/admin.employees.inboxAccess', { levels: { 11: 'none', 12: 'none' } }, expect.anything());
    });

    it('ولا موظّفين: يُدَلّ على اختيارها عند الإضافة', () => {
        for (const k of Object.keys(pageProps)) delete (pageProps as Props)[k];
        Object.assign(pageProps, { translations: {} });
        render(<InboxAccessRoster roster={{ staff: [] }} />);

        expect(screen.getByTestId('inbox-roster-empty')).toHaveTextContent('عند إضافته');
    });
});
