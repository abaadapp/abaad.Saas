import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { router } from '@inertiajs/react';
import { describe, expect, it, vi } from 'vitest';

import SupportConversations from '@/Pages/Platform/Conversations/Index';
import CrmConversations from '@/Pages/Platform/Crm/Conversations';
import { pageProps } from './setup';

/**
 * الصفحتان تُترجمان معناهما إلى شكلٍ — ولا يضيع المعنى في الترجمة.
 *
 * القشرةُ لا تعرف دعمًا من مبيعات؛ فالحارسُ هنا على الصفحة: `internal`
 * يُرسم ملاحظةً داخليّةً لا ردًّا، و`platform` صادرٌ و`business` وارد،
 * والنقلُ إلى CRM لا يمرّ قبل تأكيد، واقتراحُ المساعد لا يدخل المحرِّر
 * قبل ضغطة، ولا يُرسل من تلقاء نفسه.
 */
const shell = () => {
    // `route().current()` يقرؤها الشريطُ الجانبيّ ليعلّم البندَ النشط — وبديلُ setup لا يحملها
    const base = globalThis.route as unknown as (...a: unknown[]) => string;
    globalThis.route = ((...a: unknown[]) =>
        a.length ? base(...a) : { current: () => 'super-admin.conversations.index' }) as never;

    return Object.assign(pageProps, {
        auth: {
            abilities: [],
            mayActions: [],
            isEmployee: false,
            user: { name: 'دعم أبعاد', avatar: null, roleLabel: 'مدير المنصة', role: 'super_admin', businessId: null },
        },
        context: null,
        notifications: null,
        supportBadge: 0,
        crmBadge: 0,
        reportPages: [],
        locale: 'ar',
        csrf: 'x',
        flash: {},
    });
};

const supportProps = () => ({
    conversations: { data: [], links: [], total: 0 },
    filters: { q: '', status: 'all', assignment: 'all' },
    counts: {},
    active: {
        id: 7,
        reference: 'SUP-1',
        subject: 'الفاتورة لا تُطبع',
        category: 'مشكلة تقنية',
        status: 'open',
        statusLabel: 'مفتوحة',
        priority: 'normal',
        channel: 'whatsapp',
        channelLabel: 'واتساب',
        whatsappWindowOpen: true,
        whatsappWindowEndsAt: null,
        whatsappLine: true,
        assigneeId: null,
        assignee: null,
        lastMessageAt: null,
        business: { id: 1, name: 'محل الورد', logo: null, status: null, owner: null, email: null, phone: null, url: null },
    },
    messages: [
        { id: 1, scope: 'business' as const, internal: false, body: 'لا تطبع', event: null, eventText: null, sender: 'صاحب المحل', at: '2026-09-21T10:00:00Z', delivery: null, deliveryLabel: null, deliveryError: null, files: [] },
        { id: 2, scope: 'platform' as const, internal: true, body: 'أظنّها التعريفات', event: null, eventText: null, sender: 'دعم', at: '2026-09-21T10:01:00Z', delivery: null, deliveryLabel: null, deliveryError: null, files: [] },
        { id: 3, scope: 'platform' as const, internal: false, body: 'جرّب إعادة التشغيل', event: null, eventText: null, sender: 'دعم', at: '2026-09-21T10:02:00Z', delivery: 'sent' as const, deliveryLabel: 'وصل', deliveryError: null, files: [] },
    ],
    staff: [],
    statuses: [{ value: 'open', label: 'مفتوحة' }],
    priorities: [{ value: 'normal', label: 'عادية' }],
    channels: [],
    maxFiles: 3,
    maxKb: 5120,
    extensions: ['png'],
});

describe('صفحةُ الدعم', () => {
    it('الملاحظةُ الداخليّة وحدَها تحمل كلمتَها، والصادرُ من أبعاد على جهته', () => {
        shell();
        render(<SupportConversations {...supportProps()} />);

        const row = (text: string) => screen.getByText(text).closest('[class*="justify-"]') as HTMLElement;

        expect(screen.getByText('أظنّها التعريفات').closest('div')).toHaveTextContent('ملاحظة داخلية');

        const reply = row('جرّب إعادة التشغيل');
        expect(reply).not.toHaveTextContent('ملاحظة داخلية');
        expect(reply.className).toContain('justify-end');

        expect(row('لا تطبع').className).toContain('justify-start');
    });

    it('النقلُ إلى CRM لا يمرّ قبل تأكيدٍ — وبعده يقصد المسارَ القائم', async () => {
        shell();
        render(<SupportConversations {...supportProps()} />);
        const post = vi.mocked(router.post);
        post.mockClear();

        fireEvent.click(screen.getByRole('button', { name: /نقل إلى CRM/ }));
        expect(post).not.toHaveBeenCalled();

        const dialog = await screen.findByRole('dialog');
        expect(dialog).toHaveTextContent('سيتم نقل المحادثة إلى مسار المبيعات وإغلاق محادثة الدعم مع الاحتفاظ بالسجل.');

        fireEvent.click(within(dialog).getByRole('button', { name: /نقل إلى CRM/ }));
        await waitFor(() => expect(post).toHaveBeenCalledTimes(1));
        expect(post.mock.calls[0][0]).toBe('/super-admin.conversations.toCrm/7');
    });
});

describe('صفحةُ الدعم — الترشيحُ لا يُغلق الخيط', () => {
    it('الضغطُ على مرشِّحٍ يُبقي المحادثةَ المفتوحةَ في الطلب', () => {
        shell();
        render(<SupportConversations {...supportProps()} />);
        const get = vi.mocked(router.get);
        get.mockClear();

        fireEvent.click(screen.getByRole('button', { name: /^مفتوحة/ }));
        expect(get).toHaveBeenCalledTimes(1);
        expect(get.mock.calls[0][1]).toMatchObject({ status: 'open', conversation: 7 });
    });
});

describe('صفحةُ الدعم — الرجوعُ عن النقل', () => {
    it('ومن تراجع في الحوار لا يُنقل شيء', async () => {
        shell();
        render(<SupportConversations {...supportProps()} />);
        const post = vi.mocked(router.post);
        post.mockClear();

        fireEvent.click(screen.getByRole('button', { name: /نقل إلى CRM/ }));
        const dialog = await screen.findByRole('dialog');
        fireEvent.click(within(dialog).getByRole('button', { name: 'إلغاء' }));

        await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
        expect(post).not.toHaveBeenCalled();
    });
});

const crmProps = () => ({
    conversations: [],
    pagination: { current_page: 1, last_page: 1, from: null, to: null, total: 0, prev_page_url: null, next_page_url: null },
    filters: {},
    counts: {},
    stages: [],
    staff: [],
    active: {
        id: 9,
        name: 'عميل',
        businessName: null,
        phone: '96891234567',
        phoneNormalized: '96891234567',
        wilayat: null,
        branchesCount: null,
        currentSystem: null,
        stage: 'new',
        stageLabel: 'جديد',
        stageTone: 'info',
        sourceLabel: 'واتساب',
        assignee: null,
        assigneeId: null,
        plan: null,
        firstContactAt: null,
        lastContactAt: null,
        nextFollowUpAt: null,
        notesSummary: null,
        url: '/lead/9',
        business: null,
        windowOpen: true,
        windowEndsAt: '2026-09-22T10:00:00Z',
        blockedReason: null as string | null,
    },
    messages: [
        { id: 1, direction: 'in' as const, body: 'كم السعر؟', sender: null, at: '10:00', delivery: null, deliveryLabel: null, deliveryError: null, mediaType: null, files: [] },
    ],
    signals: null,
    assistant: { available: true, reason: null },
    line: { connected: true, number: '968' },
    maxFiles: 3,
    maxKb: 5120,
    extensions: ['png'],
});

describe('صفحةُ CRM', () => {
    it('اقتراحُ المساعد يُعرض ولا يدخل المحرِّرَ قبل ضغطة «استخدام الرد»', () => {
        shell();
        Object.assign(pageProps, { ...crmProps(), suggestion: { text: 'الباقة 12 ريالًا', model: 'm' } });
        render(<CrmConversations />);

        const box = screen.getByPlaceholderText('اكتب رسالتك...') as HTMLTextAreaElement;
        expect(box.value).toBe('');
        expect(screen.getByRole('button', { name: 'إرسال' })).toBeDisabled();

        fireEvent.click(screen.getByRole('button', { name: 'استخدام الرد' }));
        expect(box.value).toBe('الباقة 12 ريالًا');
    });

    it('ونافذةٌ مغلقةٌ تحجب المحرِّرَ والمساعدَ معًا', () => {
        shell();
        const props = crmProps();
        props.active.blockedReason = 'نافذة واتساب مغلقة';
        Object.assign(pageProps, { ...props, suggestion: undefined });
        render(<CrmConversations />);

        expect(screen.queryByPlaceholderText('اكتب رسالتك...')).toBeNull();
        expect(screen.queryByRole('button', { name: /اقتراح رد/ })).toBeNull();
        expect(screen.getByText('نافذة واتساب مغلقة')).toBeInTheDocument();
    });
});
