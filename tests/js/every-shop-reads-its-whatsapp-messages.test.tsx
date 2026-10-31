import { useState } from 'react';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { pageProps } from './setup';

/**
 * سجلُّ رسائل واتساب وصندوقُه — لكلّ متجرٍ يملك واتساب.
 *
 *  • السجلّ: النصُّ كما قرأه الزبون أو وصفٌ بغير شكل النصّ، والعميلُ والرقمُ
 *    ومتى وصلت، وبابُ محادثته — ويُقرأ بعد انقطاع الربط.
 *  • الصندوق: من لم يكتب بعدُ يُفتح من رسالته، وما ينقص الربطَ يُقال بخطواته،
 *    وخارج النافذة قالبُ المتابعة وحده.
 *
 * والخادمُ يحكم بها كلِّها: `EveryShopWithWhatsAppReadsItsMessagesTest`.
 */

const sent: string[] = [];

vi.mock('@inertiajs/react', async () => {
    const actual = await vi.importActual<Record<string, unknown>>('@inertiajs/react');

    const useForm = (initial: Record<string, unknown>) => {
        const [data, setData] = useState(initial);
        const send = (url: string) => sent.push(url);

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

const { default: WhatsappLog } = await import('@/Pages/Admin/Marketing/WhatsappLog');
const { default: WhatsAppInbox } = await import('@/Pages/Admin/WhatsAppInbox/Index');

type Props = Record<string, unknown>;

const NOT_READY = {
    connected: false,
    ready: false,
    steps: [{ key: 'own', label: 'رقم متجرك مربوطٌ ويعمل', done: false, detail: null, fix: 'اربط رقم متجرك أدناه', theirs: false }],
};

const ROW = {
    id: 11, key: 'n11', kind: 'notice', sender: null, at: '2026-10-30 09:00', event: 'الطلب جاهز', phone: '96891234567', customer: 'سارة الهنائي',
    body: 'طلبك جاهز للاستلام', summary: null, source: 'business_own', source_label: 'رقم متجرك',
    status: 'read', status_label: 'قرأها الزبون', times: { sent: '2026-10-30 09:00', read: '2026-10-30 09:05' },
    reason: null, error: null, ours: null, subject: { label: '#1042', url: '/admin.orders.show/1042' },
    open: '/admin.whatsappInbox.open/11',
};

const set = (over: Props) => {
    for (const k of Object.keys(pageProps)) delete (pageProps as Props)[k];
    Object.assign(pageProps, {
        translations: {},
        auth: { abilities: ['marketing'], mayActions: ['whatsapp.inbox.view'], planFeatures: { whatsapp: true } },
        context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 }, hosted: ['whatsapp_inbox'] },
        ...over,
    });
};

const log = (over: Props = {}) => {
    set({
        readiness: { connected: true, ready: true, steps: [] },
        hasHistory: true,
        rows: [ROW, { ...ROW, id: 12, key: 'n12', body: null, summary: 'تأكيد الطلب — #1041', customer: null, source: 'abaad_shared', source_label: 'رقم أبعاد المشترك', status: 'sent', status_label: 'خرجت إلى واتساب', times: { sent: '2026-10-29 12:00' }, open: null }],
        pagination: { current_page: 1, last_page: 1, per_page: 30, total: 2, from: 1, to: 2 },
        summary: { pending: 1, arrived: 1, failed: 0, skipped: 0 },
        summaryDays: 30,
        buckets: [{ key: 'all', label: 'الكلّ' }],
        sources: [{ key: 'all', label: 'كلّ الأرقام' }, { key: 'own', label: 'رقم متجرك' }, { key: 'shared', label: 'رقم أبعاد المشترك' }],
        kinds: [{ key: 'all', label: 'كلّ الرسائل' }, { key: 'notices', label: 'الإشعارات' }, { key: 'replies', label: 'ردود الموظفين' }],
        params: { filter: 'all', source: 'all', kind: 'all', q: '' },
        ...over,
    });

    return render(<WhatsappLog />);
};

const inbox = (over: Props = {}) => {
    set({
        chats: [{ id: null, key: 'm11', url: '/admin.whatsappInbox.open/11', name: 'سارة', phone: '96891234567', at: '2026-10-30 09:00', preview: 'طلبك جاهز', direction: 'out', unread: 0, source: 'business_own' }],
        pagination: { current_page: 1, last_page: 1, prev_page_url: null, next_page_url: null },
        params: { q: '', c: null },
        thread: null,
        canReply: true,
        line: { mode: 'business_own', number: '+968 7000 0001', usable: true, status: 'active', expires_at: null },
        unread: 0,
        stamp: '1|',
        files: { max: 3, max_kb: 10240, accept: '.jpg,.pdf' },
        readiness: { connected: true, ready: true, steps: [] },
        mayManage: true,
        ...over,
    });

    return render(<WhatsAppInbox />);
};

beforeEach(() => {
    sent.length = 0;
    vi.stubGlobal('fetch', vi.fn(async () => ({ ok: false, status: 500 })));
});

describe('سجلّ رسائل واتساب', () => {
    it('يقول النصَّ كما قرأه الزبون، أو وصفًا بغير شكل النصّ — والعميلَ والرقمَ ومتى قُرئت', () => {
        log();

        expect(screen.getByTestId('log-body')).toHaveTextContent('طلبك جاهز للاستلام');
        expect(screen.getByTestId('log-summary')).toHaveTextContent('تأكيد الطلب — #1041');
        expect(screen.getByText('سارة الهنائي')).toBeInTheDocument();
        expect(screen.getAllByTestId('log-source').map((n) => n.textContent)).toEqual(['رقم متجرك', 'رقم أبعاد المشترك']);
        expect(screen.getAllByTestId('log-times')[0]).toHaveTextContent('قُرئت');
        expect(screen.getAllByText('خرجت إلى واتساب').length).toBeGreaterThan(0);
    });

    it('وردُّ الموظّف في السجلّ نفسِه باسم من كتبه — بجوار الإشعارات لا بدلها', () => {
        log({
            rows: [ROW, {
                ...ROW, id: 11, key: 'r11', kind: 'reply', sender: 'منى', event: 'ردّ من المحادثات', body: 'خلال ساعة',
                subject: null, open: '/admin.whatsappInbox.index',
            }],
        });

        expect(screen.getAllByTestId('log-body').map((n) => n.textContent)).toEqual(['طلبك جاهز للاستلام', 'خلال ساعة']);
        expect(screen.getByTestId('log-sender')).toHaveTextContent('منى');
    });

    it('ويفتح محادثةَ الزبون لما خرج إلى واتساب وحده', () => {
        log();

        const open = screen.getAllByTestId('log-open-chat');
        expect(open).toHaveLength(1);
        expect(open[0]).toHaveAttribute('href', '/admin.whatsappInbox.open/11');
    });

    it('وانقطاعُ الربط لا يُخفي دفترًا قائمًا — يُقال ما ينقص فوقه', () => {
        log({ readiness: NOT_READY });

        expect(screen.getByText('ربط واتساب غير مكتمل')).toBeInTheDocument();
        expect(screen.getByTestId('log-body')).toBeInTheDocument();
        expect(screen.queryByText('واتساب غير مربوط بعد')).toBeNull();
    });

    it('ومن لا دفترَ له ولم يربط يُدَلّ على الربط', () => {
        log({ readiness: NOT_READY, hasHistory: false, rows: [] });

        expect(screen.getByText('واتساب غير مربوط بعد')).toBeInTheDocument();
    });
});

describe('صندوق المحادثات لكلّ متجر', () => {
    it('من لم يكتب بعدُ محادثةٌ في القائمة تُفتح من رسالته', () => {
        inbox();

        const row = within(screen.getByTestId('chat-list')).getByTestId('chat-row');
        expect(row).toHaveAttribute('href', '/admin.whatsappInbox.open/11');
        expect(row).toHaveTextContent('طلبك جاهز');
    });

    it('وما ينقص الربطَ يُقال بخطواته — والمحادثاتُ تحته', () => {
        inbox({ readiness: NOT_READY });

        expect(screen.getByTestId('inbox-readiness')).toHaveTextContent('اربط رقم متجرك أدناه');
        expect(screen.getByTestId('chat-list')).toBeInTheDocument();
    });

    it('وخارج النافذة: لا نصَّ حرًّا — زرُّ قالب المتابعة يقول إنّه مدفوع ويسأل قبل أن يخرج', async () => {
        inbox({
            params: { q: '', c: '7' },
            thread: {
                id: 7, name: 'سارة', phone: '96891234567', profile_name: null, customer: null, orders: [],
                source: 'business_own', line_number: '+968 7000 0001', window_ends_at: '2026-10-28 10:00',
                blocked: 'نافذة الردّ مغلقة', template: 'abaad_inbox_followup', log_url: '/admin.marketing.whatsapp.log',
                messages: [], has_more: false, before: null,
            },
        });

        expect(screen.getByTestId('inbox-body')).toBeDisabled();
        expect(screen.getByTestId('thread-log')).toHaveAttribute('href', '/admin.marketing.whatsapp.log');

        // مدفوعٌ عند ميتا: يُقال ذلك تحت الزرّ، ويُسأل قبل أن يخرج — والإلغاءُ لا يُرسل شيئًا
        expect(screen.getByTestId('inbox-follow-up-cost')).toHaveTextContent('رسالةٌ مدفوعة');
        const user = userEvent.setup();
        await user.click(screen.getByTestId('inbox-follow-up'));
        expect(await screen.findByText(/لا يُرسل إلّا بتأكيدك هذا/)).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'إلغاء' }));
        expect(sent).toEqual([]);

        await user.click(screen.getByTestId('inbox-follow-up'));
        await user.click(await screen.findByRole('button', { name: 'أرسل القالب' }));
        expect(sent).toEqual(['/admin.whatsappInbox.followUp/7']);
    });

    it('وبلا قالبٍ معتمَد لا زرّ', () => {
        inbox({
            params: { q: '', c: '7' },
            thread: {
                id: 7, name: 'سارة', phone: '96891234567', profile_name: null, customer: null, orders: [],
                source: 'abaad_shared', line_number: null, window_ends_at: null,
                blocked: 'نافذة الردّ مغلقة', template: null, log_url: null,
                messages: [], has_more: false, before: null,
            },
        });

        expect(screen.queryByTestId('inbox-follow-up')).toBeNull();
        expect(screen.getByTestId('inbox-blocked')).toHaveTextContent('نافذة الردّ مغلقة');
    });
});
