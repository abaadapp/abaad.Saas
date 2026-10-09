import { render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { pageProps } from './setup';

vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

const { default: WhatsappConversations } = await import('@/Pages/Admin/Marketing/WhatsappConversations');
const { default: WhatsappLog } = await import('@/Pages/Admin/Marketing/WhatsappLog');
const { default: Sidebar } = await import('@/Components/Sidebar');

type Props = Record<string, unknown>;

const reset = (over: Props) => {
    for (const k of Object.keys(pageProps)) delete (pageProps as Props)[k];
    Object.assign(pageProps, {
        translations: {},
        auth: { abilities: ['marketing'], mayActions: [], planFeatures: { whatsapp: true } },
        context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 }, hosted: [] },
        ...over,
    });
};

const CONVERSATIONS = [
    { key: 12, name: 'سارة', phone: '96891234567', messages: 3, at: '2026-10-09 10:30', preview: 'طلبك جاهز', status: 'read', status_label: 'قرأها الزبون' },
    { key: 9, name: null, phone: '96895555555', messages: 1, at: '2026-10-08 09:00', preview: 'إشعار جاهزية الطلب — #7', status: 'failed', status_label: 'لم تصل' },
];

const bubble = (over: Props) => ({
    id: 1, at: '2026-10-09 10:30', time: '10:30', event: 'الطلب جاهز', status: 'sent', status_label: 'خرجت إلى واتساب',
    reason: null, error: null, ours: null, subject: null, subject_kind: null, body: 'نصّ', summary: null, ...over,
});

const THREAD = {
    key: 12, name: 'سارة', phone: '96891234567', has_more: true, before: 3,
    messages: [
        bubble({ id: 3, body: 'مرحبًا من محل الورد، طلبك 123 جاهز.', status: 'delivered', status_label: 'وصلت جهاز الزبون',
            subject: { label: '#123', url: '/admin.orders.show/123' }, subject_kind: 'order' }),
        bubble({ id: 4, body: null, summary: 'تذكير فاتورة متأخّرة — فاتورة CI-7', status: 'failed', status_label: 'لم تصل',
            error: 'Message undeliverable', ours: false,
            subject: { label: 'فاتورة CI-7', url: '/admin.customerInvoices.show/5' }, subject_kind: 'invoice' }),
        bubble({ id: 5, status: 'queued', status_label: 'في الانتظار' }),
    ],
};

const draw = (over: Props) => {
    reset({
        connected: true, number: '+96892222222', conversations: CONVERSATIONS,
        pagination: { current_page: 1, last_page: 1, from: 1, to: 2, total: 2, prev_page_url: null, next_page_url: null },
        params: { q: '', c: null }, thread: null, ...over,
    });

    return render(<WhatsappConversations />);
};

describe('محادثات واتساب', () => {
    beforeEach(() => reset({}));

    it('بلا رقمٍ مربوط: بابُ الربط لا قائمة', () => {
        draw({ connected: false, number: null, conversations: [], pagination: null });

        expect(screen.getByText('واتساب غير مربوط بعد')).toBeInTheDocument();
        expect(screen.getByText('اربط رقم واتساب لبدء سجل المحادثات')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'اذهب إلى ربط واتساب' })).toHaveAttribute('href', '/admin.integrations.whatsapp');
        expect(screen.queryByTestId('conversation-list')).toBeNull();
    });

    it('مربوطٌ ولم يُرسَل شيء: يقول ذلك', () => {
        draw({ conversations: [], pagination: null });

        expect(screen.getByTestId('conversations-empty')).toHaveTextContent('لم تُرسل رسائل عبر رقمك بعد');
    });

    it('القائمة: الاسمُ والرقمُ تحته، أو الرقمُ وحده', () => {
        draw({});

        const list = screen.getByTestId('conversation-list');
        expect(within(list).getByText('سارة')).toBeInTheDocument();
        expect(within(list).getByText('96891234567')).toBeInTheDocument();
        expect(within(list).getByText('96895555555')).toBeInTheDocument();
        expect(within(list).getByText('طلبك جاهز')).toBeInTheDocument();
        expect(within(list).getByText('قرأها الزبون')).toBeInTheDocument();
        // والمحادثةُ تُفتح بمعرّف رسالةٍ لا برقم الزبون
        expect(within(list).getByText('سارة').closest('a')).toHaveAttribute('href', '/admin.marketing.whatsapp.log/12');
    });

    it('المحادثة: الفقاعاتُ بنصّها أو وصفها، وحالُها، وسببُ فشلها، وورقتُها', () => {
        draw({ thread: THREAD, params: { q: '', c: '12' } });

        const thread = screen.getByTestId('thread');
        expect(within(thread).getAllByTestId('bubble')).toHaveLength(3);
        expect(within(thread).getByText('مرحبًا من محل الورد، طلبك 123 جاهز.')).toBeInTheDocument();

        // وما لا نصَّ له يُكتب وصفًا — لا يُقرأ نصًّا حرفيًّا
        expect(within(thread).getByTestId('bubble-summary')).toHaveTextContent('تذكير فاتورة متأخّرة — فاتورة CI-7');
        expect(within(thread).getByTestId('bubble-summary')).toHaveTextContent('نصّ الرسالة غير محفوظ');

        expect(within(thread).getByText('وصلت جهاز الزبون')).toBeInTheDocument();
        expect(within(thread).getByText('في الانتظار')).toBeInTheDocument();
        expect(within(thread).getByTestId('bubble-failure')).toHaveTextContent('Message undeliverable');

        expect(within(thread).getByRole('link', { name: 'عرض الطلب #123' })).toHaveAttribute('href', '/admin.orders.show/123');
        expect(within(thread).getByRole('link', { name: 'عرض فاتورة CI-7' })).toHaveAttribute('href', '/admin.customerInvoices.show/5');

        // والأقدمُ بطلبٍ لا دفعةً واحدة
        expect(screen.getByRole('button', { name: 'عرض الرسائل الأقدم' })).toBeInTheDocument();
    });

    it('في الهاتف: الرجوعُ إلى القائمة بلا المحادثة المختارة', () => {
        draw({ thread: THREAD, params: { q: 'سارة', c: '12' } });

        // البحثُ يبقى والمحادثةُ تُترك — ولا `c` في الرابط
        expect(decodeURIComponent(screen.getByTestId('thread-back').getAttribute('href') ?? '')).toBe('/admin.marketing.whatsapp.log/سارة');
    });

    it('لا مكانَ للكتابة ولا للإرسال', () => {
        const { container } = draw({ thread: THREAD, params: { q: '', c: '12' } });

        expect(container.querySelector('textarea')).toBeNull();
        // الحقلُ الوحيد هو البحث
        expect(container.querySelectorAll('input')).toHaveLength(1);
        expect(container.querySelector('input')).toHaveAttribute('type', 'search');
        expect(screen.queryByRole('button', { name: /إرسال|أرسل|ردّ|رد/ })).toBeNull();
    });
});

describe('الشريط يسمّي الباب بما خلفه', () => {
    const realRoute = globalThis.route;

    beforeEach(() => {
        globalThis.route = ((name?: string) =>
            name === undefined ? { current: () => 'admin.marketing.whatsapp.log' } : `/${name}`) as never;
    });

    afterEach(() => {
        globalThis.route = realRoute;
    });

    it('من فُتحت له: «محادثات واتساب» لا «سجلّ رسائل واتساب»', () => {
        reset({ context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 }, hosted: ['whatsapp_conversations'] } });
        render(<Sidebar open onClose={() => {}} />);

        expect(screen.getByText('محادثات واتساب')).toBeInTheDocument();
        expect(screen.queryByText('سجلّ رسائل واتساب')).toBeNull();
    });

    it('ومن لم تُفتح له: الشريطُ كما كان', () => {
        reset({});
        render(<Sidebar open onClose={() => {}} />);

        expect(screen.getByText('سجلّ رسائل واتساب')).toBeInTheDocument();
        expect(screen.queryByText('محادثات واتساب')).toBeNull();
    });
});

describe('والسجلّ القديم لمن لم تُفتح له', () => {
    it('يُرسم كما كان', () => {
        reset({
            readiness: { connected: true, steps: [] },
            rows: [{ id: 1, at: '2026-10-09 10:30', event: 'الطلب جاهز', phone: '96891234567', status: 'sent',
                status_label: 'خرجت إلى واتساب', reason: null, error: null, ours: null, subject: null }],
            pagination: { current_page: 1, last_page: 1, from: 1, to: 1, total: 1, prev_page_url: null, next_page_url: null },
            summary: { sent: 1 }, summaryDays: 30, buckets: [{ key: 'all', label: 'الكلّ' }], params: { filter: 'all', q: '' },
        });
        render(<WhatsappLog />);

        expect(screen.getAllByText('سجلّ رسائل واتساب').length).toBeGreaterThan(0);
        expect(screen.getByText('96891234567')).toBeInTheDocument();
    });
});
