import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { router } from '@inertiajs/react';
import { router as core } from '@inertiajs/core';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { AutomationsScreen, type AutomationsView } from '@/Pages/Admin/Integrations/partials/AutomationsScreen';
import { InstagramAutomationsNotice } from '@/Pages/Admin/Integrations/InstagramAutomations';
import { InstagramScreen, type InstagramLinkView } from '@/Pages/Admin/Integrations/Instagram';
import { publishProblem, utf8Bytes, type AutomationRow } from '@/Pages/Admin/Integrations/partials/AutomationWizard';

/**
 * ‏‏أتمتةُ إنستقرام كما يراها التاجر — تعليقٌ أو رسالة، على كلّ منشوراته أو على ما يختاره، وردٌّ علنيٌّ تحت
 * التعليق، ومعاينةٌ لما يصل؛ وما لا يقبله إنستقرام يُقال قبل النشر.
 */

vi.mock('@/Components/Field', async () => {
    const actual = await vi.importActual<Record<string, unknown>>('@/Components/Field');

    return {
        ...actual,
        Select: ({ options, value, onChange }: {
            options: { label: string; value: string | number }[];
            value?: string | number | null;
            onChange?: (e: { target: { value: string; name: string } }) => void;
        }) => (
            <select value={value == null ? '' : String(value)} onChange={(e) => onChange?.({ target: { value: e.target.value, name: '' } })}>
                {options.map((o) => <option key={o.value} value={String(o.value)}>{o.label}</option>)}
            </select>
        ),
    };
});

const ROW: AutomationRow = {
    id: 9, name: 'تعليق بالسعر', template: 'ig_comment_price', trigger: 'comment', posts: [{ id: '17900000000000001', caption: 'باقة الجوري' }],
    public_replies: ['أرسلنا لك على الخاص'], match: 'contains', keywords: ['بكم'], excluded: [], reply_ar: 'أهلًا {الاسم}! السعر 12', reply_en: '',
    buttons: [], product_id: null, status: 'published', cooldown_minutes: 60, counts: { matched: 5, sent: 4, failed: 0 }, last_run_at: null,
};

const VIEW: AutomationsView = {
    available: true, may_manage: true, automations: [ROW], welcome: null,
    templates: [
        { key: 'ig_comment_any', name: 'أي تعليق على منشور', description: 'وصف', trigger: 'comment', match: 'any', keywords: [], public_replies: ['وصلتك التفاصيل'], reply_ar: 'أهلًا {الاسم}! {رابط_المتجر}', reply_en: '', buttons: [], needs_product: false },
        { key: 'ig_dm_hours', name: 'ساعات العمل', description: 'وصف', trigger: 'message', match: 'contains', keywords: ['الدوام'], public_replies: [], reply_ar: 'نفتح 9', reply_en: '', buttons: [{ type: 'reply', title: 'السعر' }], needs_product: false },
    ],
    products: [], context: { store: 'محل الورد', store_link: 'https://rose.abaadapp.om' }, variables: {},
    limits: { automations: 50, keywords: 20, keyword: 40, name: 80, text: 900, buttons: 3, button_title: 20, cooldown_max: 10080, posts: 20, public_replies: 5, public_reply: 300, bytes: 1000 },
};

const LINK: InstagramLinkView = {
    state: 'connected', username: 'rose.shop', name: null, account_type: 'BUSINESS', picture: null, expires_at: null, last_webhook_at: null,
    connected_at: null, missing_permissions: [], subscribed: true, hears_phone: true, error: null,
};

const POSTS = [
    { id: '17900000000000001', caption: 'باقة الجوري', image: 'https://cdn.test/1.jpg', reel: false, link: null, at: null },
    { id: '17900000000000002', caption: 'ريلز التوليب', image: null, reel: true, link: null, at: null },
];

const sent: { url: string; data: Record<string, unknown>; method: string }[] = [];
const fetched: string[] = [];
let answer: Record<string, unknown> = {};

beforeEach(() => {
    sent.length = 0;
    fetched.length = 0;
    answer = { ok: true, posts: POSTS, error: null };
    vi.mocked(router.post).mockClear();
    for (const method of ['post', 'put'] as const) {
        vi.spyOn(core, method).mockImplementation(((url: string, data: Record<string, unknown>) => {
            sent.push({ url, data, method });
        }) as never);
    }
    vi.stubGlobal('fetch', vi.fn((url: string) => {
        fetched.push(url);

        return Promise.resolve({ ok: true, json: () => Promise.resolve(answer) });
    }));
});

afterEach(() => {
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
});

describe('ما لا يقبله إنستقرام يُقال قبل النشر', () => {
    const d = { keywords: ['بكم'], reply_ar: 'السعر', reply_en: '' };

    it('يُعدّ بالبايت: الحرفُ العربيّ بمكانين', () => {
        expect(utf8Bytes('سعر')).toBe(6);
        expect(publishProblem({ ...d, reply_ar: 'س'.repeat(500) }, 1000)).toBeNull();
        expect(publishProblem({ ...d, reply_ar: 'س'.repeat(501) }, 1000)).toContain('500 حرف');
        expect(publishProblem({ ...d, reply_ar: 'س'.repeat(501) })).toBeNull();
        expect(publishProblem({ ...d, reply_ar: 'a'.repeat(641), buttons: [{ type: 'link', title: 'x', url: 'https://x' }] }, 1000)).toContain('500 حرف');
    });

    it('«أي تعليق» بمنشورٍ مختار، ولا «آخر طلب»، ولا قوسان في الردّ العلنيّ', () => {
        expect(publishProblem({ ...d, keywords: [], match: 'any', posts: [] }, 1000)).toContain('منشورات تختارها');
        expect(publishProblem({ ...d, keywords: [], match: 'any', posts: [{ id: '1', caption: '' }] }, 1000)).toBeNull();
        expect(publishProblem({ ...d, reply_ar: '{آخر_طلب}' }, 1000)).toContain('آخر طلب');
        expect(publishProblem({ ...d, public_replies: ['تواصل [رقمك]'] }, 1000)).toContain('القوسين');
    });
});

describe('صفحة أتمتة إنستقرام', () => {
    it('تقول نوعَ كلّ أتمتة ومنشوراتِها، ولا ترحيب فيها، وأبوابُها أبوابُ إنستقرام', async () => {
        const user = userEvent.setup();
        render(<AutomationsScreen view={VIEW} channel="instagram" />);

        expect(screen.queryByTestId('automation-welcome')).toBeNull();
        expect(screen.getByTestId('automation-trigger')).toHaveTextContent('تعليق');
        expect(screen.getByTestId('automation-trigger')).toHaveTextContent('1 منشور');

        await user.click(screen.getByTestId('automation-flip'));
        expect(vi.mocked(router.post).mock.calls[0][0]).toContain('admin.integrations.instagram.automations.status/9');
        await user.click(screen.getByTestId('automation-delete'));
        await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'حذف' }));
        expect(vi.mocked(router.delete).mock.calls.at(-1)?.[0]).toContain('admin.integrations.instagram.automations.destroy/9');
    });

    it('قالب «أي تعليق»: لا يُنشر حتى يُختار منشور، والمعاينةُ تعرض العلنيَّ ثمّ الخاصّ', async () => {
        const user = userEvent.setup();
        render(<AutomationsScreen view={VIEW} channel="instagram" />);

        await user.click(screen.getByTestId('template-ig_comment_any'));
        expect(fetched).toEqual([]);
        await user.click(screen.getByTestId('ig-posts-some'));
        expect(fetched[0]).toContain('admin.integrations.instagram.automations.posts');
        await user.click(await screen.findByTestId('ig-post-17900000000000002'));
        expect(screen.getByTestId('ig-posts-count')).toHaveTextContent('اخترت 1 من 20');

        await user.click(screen.getByTestId('wizard-next'));
        expect(screen.getByTestId('wizard-match-any')).toHaveAttribute('aria-checked', 'true');
        expect(screen.queryByTestId('wizard-keywords')).toBeNull();

        await user.click(screen.getByTestId('wizard-next'));
        expect(screen.getByTestId('wizard-no-buttons')).toBeInTheDocument();
        expect(screen.queryByTestId('wizard-buttons')).toBeNull();
        expect(screen.queryByRole('button', { name: '+ حالة آخر طلب' })).toBeNull();
        expect(screen.getByTestId('preview-public')).toHaveTextContent('وصلتك التفاصيل');
        expect(screen.getByTestId('preview-reply')).toHaveTextContent('أهلًا sara! https://rose.abaadapp.om');

        await user.click(screen.getByTestId('wizard-public-add'));
        await user.click(screen.getByTestId('wizard-publish'));
        expect(sent[0].url).toContain('admin.integrations.instagram.automations.store');
        expect(sent[0].data).toMatchObject({
            trigger: 'comment', match: 'any', keywords: [], status: 'published', buttons: [],
            posts: [{ id: '17900000000000002', caption: 'ريلز التوليب' }], public_replies: ['وصلتك التفاصيل'],
        });
    });

    it('إفراغُ المنشورات يُسقط «أي تعليق» — ولا يبقى على كلّ الحساب', async () => {
        const user = userEvent.setup();
        render(<AutomationsScreen view={VIEW} channel="instagram" />);

        await user.click(screen.getByTestId('template-ig_comment_any'));
        await user.click(screen.getByTestId('ig-posts-some'));
        await user.click(await screen.findByTestId('ig-post-17900000000000001'));
        await user.click(screen.getByTestId('ig-posts-all'));
        await user.click(screen.getByTestId('wizard-next'));

        expect(screen.getByTestId('wizard-match-any')).toBeDisabled();
        expect(screen.getByTestId('wizard-match-contains')).toHaveAttribute('aria-checked', 'true');
        expect(screen.getByTestId('wizard-keywords')).toBeInTheDocument();
    });

    it('الرسالة: أزرارٌ بلا منشورات ولا ردٍّ علنيّ', async () => {
        const user = userEvent.setup();
        render(<AutomationsScreen view={VIEW} channel="instagram" />);

        await user.click(screen.getByTestId('template-ig_dm_hours'));
        expect(screen.getByTestId('wizard-trigger-message')).toHaveAttribute('aria-checked', 'true');
        expect(screen.queryByTestId('ig-posts')).toBeNull();
        await user.click(screen.getByTestId('wizard-next'));
        expect(screen.queryByTestId('wizard-match-any')).toBeNull();
        await user.click(screen.getByTestId('wizard-next'));
        expect(screen.queryByTestId('wizard-public')).toBeNull();
        expect(screen.getByTestId('wizard-bytes')).toHaveTextContent('1000');
        expect(screen.getByTestId('preview-button')).toHaveTextContent('السعر');

        await user.click(screen.getByTestId('wizard-publish'));
        expect(sent[0].data).toMatchObject({ trigger: 'message', posts: [], public_replies: [], buttons: [{ type: 'reply', title: 'السعر' }] });
    });

    it('التعديل يعرض المختارَ سابقًا وإن لم يعد في آخر المنشورات، والعطلُ يُقال ويُعاد', async () => {
        const user = userEvent.setup();
        answer = { ok: false, posts: [], error: 'حساب إنستقرام غير مربوط الآن — أعد ربطه.' };
        render(<AutomationsScreen view={VIEW} channel="instagram" />);

        await user.click(screen.getByTestId('automation-edit'));
        expect(await screen.findByTestId('ig-posts-error')).toHaveTextContent('غير مربوط');
        expect(screen.getByTestId('ig-posts-elsewhere')).toHaveTextContent('باقة الجوري');

        answer = { ok: true, posts: POSTS, error: null };
        await user.click(screen.getByRole('button', { name: 'أعد المحاولة' }));
        expect(await screen.findByTestId('ig-post-17900000000000001')).toHaveAttribute('aria-pressed', 'true');
        expect(screen.queryByTestId('ig-posts-elsewhere')).toBeNull();

        await user.click(screen.getByTestId('wizard-next'));
        await user.click(screen.getByTestId('wizard-next'));
        await user.click(screen.getByTestId('wizard-publish'));
        expect(sent[0].method).toBe('put');
        expect(sent[0].url).toContain('admin.integrations.instagram.automations.update/9');
        expect(sent[0].data).toMatchObject({ posts: [{ id: '17900000000000001', caption: 'باقة الجوري' }] });
    });
});

describe('حال الربط فوق الأتمتة', () => {
    it.each([
        [null, 'غير مربوط'],
        [{ ...LINK, state: 'needs_relink' as const }, 'انقطع إذن'],
        [{ ...LINK, missing_permissions: ['instagram_business_manage_comments'] }, 'الربط ناقص'],
        [{ ...LINK, hears_phone: false }, 'لا نرى ردك'],
    ])('يقول ما يمنع الردّ', (link, text) => {
        render(<InstagramAutomationsNotice link={link} />);
        expect(screen.getByTestId('ig-automations-notice')).toHaveTextContent(text);
    });

    it('ومربوطٌ كاملٌ لا تنبيه فيه، وصفحةُ الربط تقود إلى الأتمتة لمن فُتحت له', () => {
        const a = render(<InstagramAutomationsNotice link={LINK} />);
        expect(screen.queryByTestId('ig-automations-notice')).toBeNull();
        a.unmount();

        const b = render(<InstagramScreen view={{ link: LINK, may_manage: true, scopes: [], automations: { published: 2, total: 3 } }} />);
        expect(screen.getByTestId('ig-automations-count')).toHaveTextContent('2 تعمل من 3');
        b.unmount();

        render(<InstagramScreen view={{ link: LINK, may_manage: true, scopes: [], automations: null }} />);
        expect(screen.queryByTestId('ig-automations-count')).toBeNull();
    });
});
