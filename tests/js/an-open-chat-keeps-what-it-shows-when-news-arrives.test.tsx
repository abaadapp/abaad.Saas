import { useState } from 'react';
import { act, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { pageProps } from './setup';

/**
 * محادثةٌ مفتوحة لا يختفي منها ما عُرض حين يصل جديد.
 *
 * ═══ العطب ═══
 *
 * الشاشةُ تسأل كلَّ خمس ثوانٍ، فإن وصل جديد (ردُّ زميلٍ أو رسالةُ زبون) أعادت
 * المحادثةَ من الخادم: آخرَ خمسين رسالة. وكانت **تستبدل** بها ما على الشاشة —
 * فمن فتح «الرسائل الأقدم» يقرؤها اختفت من أمامه مع كلّ ردٍّ يصل، ورسالةٌ
 * خرجت من نافذة الخمسين سقطت من بين ما قرأه وما وصل.
 *
 * ═══ وما يُحرس هنا ═══
 *
 *   - الأقدمُ المعروض يبقى، والجديدُ يُضاف، ولا يتكرّر شيء، والترتيبُ ترتيبُ الخادم.
 *   - وما تبدّل حالُه (وصلت/قُرئت) يُعرض بحاله الجديد.
 *   - وجديدٌ كثيرٌ لا يتّصل بما عُرض (فجوة) يُستبدل به — لا يُلصق فوق فجوةٍ صامتة.
 *   - وزرُّ «الأقدم» يبقى على ما حُمّل: لا يعود يطلب ما عُرض.
 */

vi.mock('@inertiajs/react', async () => {
    const actual = await vi.importActual<Record<string, unknown>>('@inertiajs/react');

    const useForm = (initial: Record<string, unknown>) => {
        const [data, setData] = useState(initial);

        return {
            data,
            errors: {},
            processing: false,
            setData: (key: unknown, value?: unknown) =>
                setData((d) => (typeof key === 'string' ? { ...d, [key]: value } : { ...d, ...(key as Record<string, unknown>) })),
            transform: () => {},
            post: () => {},
            put: () => {},
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

const { default: WhatsAppInbox } = await import('@/Pages/Admin/WhatsAppInbox/Index');

type Msg = Record<string, unknown>;

/** رسالةٌ كما يرسمها الخادم (`WhatsAppInbox::bubble`) — المعرّفُ والختمُ يرتّبانها */
const msg = (id: number, over: Msg = {}): Msg => ({
    kind: id % 2 ? 'in' : 'out',
    key: `m${id}`,
    id,
    ts: 1_761_640_000 + id * 60,
    order: 1,
    at: '2026-10-28 10:00',
    time: '10:00',
    body: `رسالة ${id}`,
    sender: id % 2 ? null : 'منى',
    status: id % 2 ? 'received' : 'sent',
    status_label: id % 2 ? null : 'أُرسلت',
    attachments: [],
    ...over,
});

const range = (from: number, to: number) => Array.from({ length: to - from + 1 }, (_, i) => msg(from + i));

const thread = (messages: Msg[], hasMore: boolean, before: number | null, id = 7) => ({
    id, name: 'سارة', phone: '96891234567', profile_name: 'سارة', customer: null, orders: [], invoices: [],
    source: 'business_own', line_number: '+968 7000 0001', window_ends_at: '2026-10-29 10:30', blocked: null,
    template: null, log_url: null, order: null, messages, has_more: hasMore, before,
});

const setProps = (t: ReturnType<typeof thread>) => {
    for (const k of Object.keys(pageProps)) delete (pageProps as Msg)[k];
    Object.assign(pageProps, {
        translations: {},
        auth: { abilities: [], mayActions: ['whatsapp.inbox.view', 'whatsapp.inbox.reply'], planFeatures: { whatsapp: true } },
        context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 }, hosted: ['whatsapp_inbox'] },
        chats: [{ id: t.id, key: `c${t.id}`, url: null, name: 'سارة', phone: '96891234567', at: '2026-10-28 10:32', preview: '', direction: 'in', unread: 0, source: 'business_own' }],
        pagination: { current_page: 1, last_page: 1, prev_page_url: null, next_page_url: null },
        params: { q: '', c: String(t.id), f: 'all' },
        thread: t,
        canReply: true,
        line: { mode: 'business_own', number: '+968 7000 0001', usable: true, status: 'active', expires_at: null },
        unread: 0,
        unreadChats: 0,
        stamp: '1',
        files: { max: 3, max_kb: 10240, accept: '.jpg,.png,.pdf' },
        readiness: { connected: true, ready: true, steps: [] },
        mayManage: true,
    });
};

/** نصوصُ الفقاعات على الشاشة بترتيبها */
const shown = () => screen.getAllByText(/^رسالة \d+$/).map((el) => el.textContent);

const fetchMock = vi.fn();

beforeEach(() => {
    fetchMock.mockReset();
    vi.stubGlobal('fetch', fetchMock);
});

/** يفتح المحادثةَ على آخر ثلاث (11–13)، ويحمّل الأقدم (8–10) كما يفعل الزرّ */
const openAndLoadOlder = async () => {
    setProps(thread(range(11, 13), true, 11));
    const view = render(<WhatsAppInbox />);

    fetchMock.mockResolvedValueOnce(new Response(JSON.stringify({ messages: range(8, 10), has_more: false, before: null }), { status: 200 }));
    await userEvent.click(screen.getByRole('button', { name: 'عرض الرسائل الأقدم' }));
    expect(shown()).toEqual(['رسالة 8', 'رسالة 9', 'رسالة 10', 'رسالة 11', 'رسالة 12', 'رسالة 13']);

    return view;
};

describe('محادثةٌ مفتوحة يصلها جديد', () => {
    it('الأقدمُ المعروض يبقى والجديدُ يُضاف — بلا تكرارٍ وبترتيب الخادم', async () => {
        const { rerender } = await openAndLoadOlder();

        // وصل 14 — والخادمُ يعيد آخرَ ثلاث: 12–14 (خرج 11 من نافذته)
        setProps(thread(range(12, 14), true, 12));
        await act(async () => rerender(<WhatsAppInbox />));

        expect(shown()).toEqual(['رسالة 8', 'رسالة 9', 'رسالة 10', 'رسالة 11', 'رسالة 12', 'رسالة 13', 'رسالة 14']);
        // حُمّل الأقدمُ كلُّه — فلا يعود الزرُّ يطلبه
        expect(screen.queryByRole('button', { name: 'عرض الرسائل الأقدم' })).toBeNull();
    });

    it('وما تبدّل حالُه يُعرض بحاله الجديد', async () => {
        const { rerender } = await openAndLoadOlder();

        setProps(thread([...range(12, 13).map((m) => (m.id === 12 ? { ...m, status: 'read', status_label: 'قرأها الزبون' } : m)), msg(14)], true, 12));
        await act(async () => rerender(<WhatsAppInbox />));

        expect(screen.getByTitle('قرأها الزبون')).toBeTruthy();
        expect(shown()).toHaveLength(7);
    });

    it('وجديدٌ لا يتّصل بما عُرض يُستبدل به — لا فجوةَ صامتة', async () => {
        const { rerender } = await openAndLoadOlder();

        // وصل 14–40 دفعةً: آخرُ ثلاث 38–40، وبينها وبين 13 ما لم يُحمَّل
        setProps(thread(range(38, 40), true, 38));
        await act(async () => rerender(<WhatsAppInbox />));

        expect(shown()).toEqual(['رسالة 38', 'رسالة 39', 'رسالة 40']);
        expect(screen.getByRole('button', { name: 'عرض الرسائل الأقدم' })).toBeTruthy();
    });

    it('وإشعارٌ آليّ خرج بين رسالتين يقع بينهما — بترتيب الخادم لا بترتيب الوصول', async () => {
        const { rerender } = await openAndLoadOlder();

        const notice = {
            kind: 'notice', key: 'n5', id: 5, ts: (msg(12).ts as number) + 30, order: 0, at: '2026-10-28 10:00', time: '10:00',
            body: 'رسالة 12.5', event: 'تجهيز الطلب', status: 'delivered', status_label: 'وصلت', attachments: [],
        };
        setProps(thread([msg(12), notice, msg(13), msg(14)], true, 12));
        await act(async () => rerender(<WhatsAppInbox />));

        const all = screen.getAllByText(/^رسالة [\d.]+$/).map((el) => el.textContent);
        expect(all).toEqual(['رسالة 8', 'رسالة 9', 'رسالة 10', 'رسالة 11', 'رسالة 12', 'رسالة 12.5', 'رسالة 13', 'رسالة 14']);
    });

    it('وصفحةُ «الأقدم» التي وصلت بعد استبدالٍ لا تتّصل به لا تُلصق فوقه', async () => {
        setProps(thread(range(11, 13), true, 11));
        const { rerender } = render(<WhatsAppInbox />);

        let answer: (r: Response) => void = () => {};
        fetchMock.mockReturnValueOnce(new Promise<Response>((r) => (answer = r)));
        await userEvent.click(screen.getByRole('button', { name: 'عرض الرسائل الأقدم' }));

        // وصل جديدٌ كثيرٌ أثناء الطلب: لا يتّصل بما عُرض فيُستبدل به
        setProps(thread(range(38, 40), true, 38));
        await act(async () => rerender(<WhatsAppInbox />));

        // ثمّ أجاب الخادمُ عن «الأقدم» من 11 — وهي لا تلي 38
        await act(async () => answer(new Response(JSON.stringify({ messages: range(8, 10), has_more: false, before: null }), { status: 200 })));

        expect(shown()).toEqual(['رسالة 38', 'رسالة 39', 'رسالة 40']);
    });

    it('وصفحةُ «الأقدم» تحمل رسالةً معروضة — لا تُكرّرها', async () => {
        setProps(thread(range(11, 13), true, 11));
        render(<WhatsAppInbox />);

        fetchMock.mockResolvedValueOnce(new Response(JSON.stringify({ messages: range(9, 11), has_more: false, before: null }), { status: 200 }));
        await userEvent.click(screen.getByRole('button', { name: 'عرض الرسائل الأقدم' }));

        expect(shown()).toEqual(['رسالة 9', 'رسالة 10', 'رسالة 11', 'رسالة 12', 'رسالة 13']);
    });

    it('ومن لم يحمّل الأقدمَ يرى آخرَ ما أعاده الخادم كما كان', async () => {
        setProps(thread(range(11, 13), true, 11));
        const { rerender } = render(<WhatsAppInbox />);

        setProps(thread(range(12, 14), true, 12));
        await act(async () => rerender(<WhatsAppInbox />));

        expect(shown()).toEqual(['رسالة 11', 'رسالة 12', 'رسالة 13', 'رسالة 14']);
        expect(screen.getByRole('button', { name: 'عرض الرسائل الأقدم' })).toBeTruthy();
    });
});
