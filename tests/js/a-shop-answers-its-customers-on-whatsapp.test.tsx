import { useState } from 'react';
import { act, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { pageProps } from './setup';

/**
 * «محادثات واتساب» — ما يراه موظّفُ المشاهدة وموظّفُ الردّ، وما يُغلق حين تُسحب الصلاحية.
 *
 *  • المشاهدةُ وحدها: الخيطُ بلا مربّع ردّ.
 *  • الردّ: المربّعُ يرسل النصَّ إلى بابه، وما يمنعه يُقال قبل الكتابة.
 *  • والصفحةُ المفتوحة تسأل الخادم: سُحبت الصلاحيةُ فتُغلق، أو نُزع الردّ فيختفي المربّع.
 *  • والشريطُ: البندُ لمن مُنح الفعلَ ولمتجرٍ فُتح له الصندوق — لا للدور ولا للقسم.
 *  • وشاشةُ الموظّف: ثلاثةُ مستويات، يمنحها صاحبُ النشاط وحده.
 *
 * والخادمُ يحكم بها كلِّها: `AShopReadsAndAnswersItsCustomersOnWhatsAppTest`.
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

const { default: WhatsAppInbox, POLL_MS } = await import('@/Pages/Admin/WhatsAppInbox/Index');
const { default: Sidebar } = await import('@/Components/Sidebar');
const { default: InboxAccessField } = await import('@/Pages/Admin/Employees/partials/InboxAccessField');

type Props = Record<string, unknown>;

const THREAD = {
    id: 7, name: 'سارة', phone: '96891234567', profile_name: 'سارة', customer: { name: 'سارة', url: '/admin.customers.show/3' },
    orders: [{ number: '1042', total: 25, status: 'pending', url: '/admin.orders.show/1042' }],
    source: 'business_own', line_number: '+968 7000 0001', window_ends_at: '2026-10-29 10:30', blocked: null,
    has_more: false, before: null,
    messages: [
        { kind: 'notice', key: 'n1', at: '2026-10-28 09:00', time: '09:00', body: 'طلبك 1042 جاهز', status: 'read', status_label: 'قرأها الزبون', event: 'الطلب جاهز' },
        { kind: 'in', key: 'm1', at: '2026-10-28 10:30', time: '10:30', body: 'متى يصل؟', status: 'received', status_label: null, attachments: [] },
        { kind: 'out', key: 'm2', at: '2026-10-28 10:31', time: '10:31', body: 'خلال ساعة', sender: 'منى', status: 'delivered', status_label: 'وصلت جهاز الزبون', attachments: [] },
    ],
};

const draw = (over: Props = {}) => {
    for (const k of Object.keys(pageProps)) delete (pageProps as Props)[k];
    Object.assign(pageProps, {
        translations: {},
        auth: { abilities: [], mayActions: ['whatsapp.inbox.view'], planFeatures: { whatsapp: true } },
        context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 }, hosted: ['whatsapp_inbox'] },
        chats: [{ id: 7, name: 'سارة', phone: '96891234567', at: '2026-10-28 10:31', preview: 'خلال ساعة', direction: 'out', unread: 2, source: 'business_own' }],
        pagination: { current_page: 1, last_page: 1, prev_page_url: null, next_page_url: null },
        params: { q: '', c: '7' },
        thread: THREAD,
        canReply: false,
        line: { mode: 'business_own', number: '+968 7000 0001', usable: true, status: 'active', expires_at: null },
        unread: 2,
        stamp: '3|2026-10-28 10:31:00',
        files: { max: 3, max_kb: 10240, accept: '.jpg,.jpeg,.png,.webp,.pdf' },
        ...over,
    });

    return render(<WhatsAppInbox />);
};

const fetchMock = vi.fn();

beforeEach(() => {
    sent.length = 0;
    fetchMock.mockReset();
    vi.stubGlobal('fetch', fetchMock);
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

describe('صندوق المحادثات', () => {
    it('المشاهدةُ وحدها: الخيطُ كلُّه — الوارد والردّ باسم من كتبه والإشعار — ولا مربّعَ ردّ', () => {
        draw();

        const thread = screen.getByTestId('thread');
        expect(within(thread).getByTestId('bubble-in')).toHaveTextContent('متى يصل؟');
        expect(within(thread).getByTestId('bubble-out')).toHaveTextContent('خلال ساعة');
        expect(within(thread).getByTestId('bubble-sender')).toHaveTextContent('منى');
        expect(within(thread).getByTestId('bubble-notice')).toHaveTextContent('إشعار آلي');
        expect(within(screen.getByTestId('bubble-out')).getByTestId('item-status')).toHaveTextContent('وصلت جهاز الزبون');

        expect(screen.queryByTestId('inbox-composer')).toBeNull();
        expect(screen.getByTestId('inbox-read-only')).toHaveTextContent('للمشاهدة فقط');
        expect(screen.getByTestId('chat-unread')).toHaveTextContent('2');
        expect(screen.getByTestId('thread-customer')).toHaveAttribute('href', '/admin.customers.show/3');
    });

    it('الردّ: يرسل النصَّ إلى باب المحادثة نفسِها', async () => {
        draw({ canReply: true });
        const user = userEvent.setup();

        await user.type(screen.getByTestId('inbox-body'), 'تم، شكرًا');
        await user.click(screen.getByTestId('inbox-send'));

        expect(sent.at(-1)).toMatchObject({ url: '/admin.whatsappInbox.reply/7', payload: { body: 'تم، شكرًا' } });
    });

    it('وما يمنع الردَّ يُقال قبل الكتابة — والمربّعُ مقفل', async () => {
        draw({ canReply: true, thread: { ...THREAD, blocked: 'نافذة الردّ مغلقة — مرّت أكثر من 24 ساعة.' } });

        expect(screen.getByTestId('inbox-blocked')).toHaveTextContent('نافذة الردّ مغلقة');
        expect(screen.getByTestId('inbox-body')).toBeDisabled();
        expect(screen.getByTestId('inbox-send')).toBeDisabled();
    });

    it('سحبُ الصلاحية والصفحةُ مفتوحة: يُغلق الخيطُ في السؤال التالي', async () => {
        vi.useFakeTimers();
        fetchMock.mockResolvedValue({ ok: false, status: 403, json: async () => ({}) });
        draw({ canReply: true });

        expect(screen.getByTestId('thread')).toBeInTheDocument();
        await act(async () => {
            await vi.advanceTimersByTimeAsync(POLL_MS);
        });

        expect(screen.getByTestId('inbox-revoked')).toBeInTheDocument();
        expect(screen.queryByTestId('thread')).toBeNull();
        expect(screen.queryByTestId('inbox-composer')).toBeNull();
    });

    it('ونزعُ الردّ وحده يُخفي المربّعَ ويُبقي الخيط', async () => {
        vi.useFakeTimers();
        fetchMock.mockResolvedValue({ ok: true, status: 200, json: async () => ({ stamp: '3|2026-10-28 10:31:00', can_reply: false }) });
        draw({ canReply: true });

        expect(screen.getByTestId('inbox-composer')).toBeInTheDocument();
        await act(async () => {
            await vi.advanceTimersByTimeAsync(POLL_MS);
        });

        expect(screen.queryByTestId('inbox-composer')).toBeNull();
        expect(screen.getByTestId('inbox-read-only')).toBeInTheDocument();
        expect(screen.getByTestId('thread')).toBeInTheDocument();
    });

    it('ورقمُ المتجر المنقطع يُقال فوق الصندوق', () => {
        draw({ line: { mode: 'business_own', number: null, usable: false, status: 'expired', expires_at: '2026-10-01' } });

        expect(screen.getByTestId('inbox-line-down')).toHaveTextContent('ولا يُرسل من رقم آخر');
    });
});

describe('الشريط', () => {
    const realRoute = globalThis.route;

    beforeEach(() => {
        globalThis.route = ((name?: string) =>
            name === undefined ? { current: () => 'admin.dashboard' } : `/${name}`) as never;
    });

    afterEach(() => {
        globalThis.route = realRoute;
    });

    const side = (mayActions: string[], hosted: string[], abilities: string[] = []) => {
        Object.assign(pageProps, {
            auth: { abilities, mayActions, planFeatures: { whatsapp: true } },
            context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 }, hosted },
            whatsappInboxBadge: 4,
        });

        return render(<Sidebar open onClose={() => {}} />);
    };

    it('لمن مُنح المشاهدة في متجرٍ فُتح له الصندوق — وشارتُه ما لم يقرأه', () => {
        side(['whatsapp.inbox.view'], ['whatsapp_inbox']);

        const link = screen.getByText('محادثات واتساب').closest('a')!;
        expect(link).toHaveAttribute('href', '/admin.whatsappInbox.index');
        expect(link).toHaveTextContent('4');
    });

    it('ولا يظهر بقسم التسويق ولا بالتكاملات ولا بالدور — ولا لمتجرٍ لم يُفتح له', () => {
        side([], ['whatsapp_inbox'], ['marketing', 'integrations', 'customers']);
        expect(screen.queryByRole('link', { name: /^محادثات واتساب/ })).toBeNull();
    });

    it('ولا لمتجرٍ لم يُفتح له الصندوق ولو مُنح الفعل', () => {
        side(['whatsapp.inbox.view'], []);
        expect(screen.queryByText('محادثات واتساب')).toBeNull();
    });

    it('ومن فُتح له الصندوقُ ومحادثاتُ الإشعارات معًا: صفحةُ الإشعارات «سجلّ إشعارات واتساب» — لا اسمان لشيئين', () => {
        // وقائمةُ التسويق مفتوحةٌ على صفحة السجلّ — بنوها لا يُرسمون وهي مطويّة
        globalThis.route = ((name?: string) =>
            name === undefined ? { current: () => 'admin.marketing.whatsapp.log' } : `/${name}`) as never;
        side(['whatsapp.inbox.view'], ['whatsapp_inbox', 'whatsapp_conversations'], ['marketing']);

        expect(screen.getAllByText('محادثات واتساب')).toHaveLength(1);
        expect(screen.getByText('سجلّ إشعارات واتساب')).toBeInTheDocument();
    });
});

describe('صلاحية الموظّف على المحادثات', () => {
    it('ثلاثةُ مستويات — والاختيارُ يُرسل مستواه', async () => {
        const onChange = vi.fn();
        render(<InboxAccessField value="none" canGrant onChange={onChange} />);
        const user = userEvent.setup();

        expect(screen.getByTestId('inbox-access-none')).toHaveAttribute('aria-checked', 'true');
        expect(screen.getByText('بدون صلاحية')).toBeInTheDocument();
        expect(screen.getByText('مشاهدة فقط')).toBeInTheDocument();
        expect(screen.getByText('مشاهدة ورد')).toBeInTheDocument();

        await user.click(screen.getByTestId('inbox-access-reply'));
        expect(onChange).toHaveBeenCalledWith('reply');
    });

    it('ومن لا يمنحها يراها مقفلةً بسببها مكتوبًا', () => {
        render(<InboxAccessField value="view" canGrant={false} onChange={() => {}} />);

        for (const key of ['none', 'view', 'reply']) {
            expect(screen.getByTestId(`inbox-access-${key}`)).toBeDisabled();
        }
        expect(screen.getByTestId('inbox-access-locked')).toHaveTextContent('يمنحها صاحب النشاط وحده');
    });
});
