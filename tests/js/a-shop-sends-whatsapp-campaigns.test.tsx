import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { router } from '@inertiajs/react';
import { router as core } from '@inertiajs/core';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { CampaignsScreen, type CampaignRow, type CampaignTemplate, type CampaignsView } from '@/Pages/Admin/Marketing/WhatsappCampaigns';
import WhatsappCampaignsSwitch from '@/Pages/Platform/Settings/partials/WhatsappCampaignsSwitch';

/**
 * ‏‏حملاتُ واتساب كما يراها التاجر — لا تُرسل حتى يرى العدد ويكتبه ويقرّ، وزرُّ الإيقاف في كلّ قالب لا
 * يُحذف، ولكلّ حملةٍ ما خرج وما وصل ولماذا وقفت. ومديرُ المنصّة يفتحها لمتاجر تجربة.
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

const TEMPLATE: CampaignTemplate = {
    id: 4, title: 'عرض العيد', language: 'ar', body: 'أهلًا {الاسم}، خصم اليوم', preview: 'أهلًا سارة، خصم اليوم', footer: 'لا تريد عروضنا؟', button: null,
    opt_out: 'إيقاف الرسائل', status: 'APPROVED', label: 'معتمد', why: null, usable: true, checked_at: null,
};

const ROW: CampaignRow = {
    id: 9, name: 'حملة العيد', status: 'sending', why: null, total: 100, counts: { pending: 40, out: 55, delivered: 50, read: 20, failed: 5, skipped: 0 },
    body: 'أهلًا', failures: [{ why: 'لا واتساب على الرقم، أو لا يستقبل', n: 5 }], scheduled_at: null, started_at: '2026-11-24T12:00:00+04:00', finished_at: null,
};

const VIEW: CampaignsView = {
    may_manage: true, obstacle: null, number: '+968', store: 'محل الورد', store_link: 'https://rose.test', templates: [TEMPLATE], campaigns: [ROW],
    optouts: { total: 3, by: { customer: 3 } }, branches: [{ id: 1, name: 'الرئيسي' }],
    limits: { body: 900, title: 80, button: 25, templates: 30, days: [30, 90], quiet: [0, 3], recipients: 5000, per_hour: 1500, quiet_from: 22, quiet_to: 8, schedule_days: 30 },
    footers: { ar: 'لا تريد عروضنا؟ اضغط «إيقاف الرسائل»', en: 'No more offers?' }, opt_out_titles: { ar: 'إيقاف الرسائل', en: 'Stop messages' },
};

const sent: { url: string; data: Record<string, unknown> }[] = [];
const asked: Record<string, unknown>[] = [];
let count = 42;

beforeEach(() => {
    sent.length = 0;
    asked.length = 0;
    count = 42;
    vi.mocked(router.post).mockClear();
    vi.mocked(router.delete).mockClear();
    vi.spyOn(core, 'post').mockImplementation(((url: string, data: Record<string, unknown>) => {
        sent.push({ url, data });
    }) as never);
    vi.stubGlobal('fetch', vi.fn((_url: string, init: { body: string }) => {
        asked.push((JSON.parse(init.body) as { audience: Record<string, unknown> }).audience);

        return Promise.resolve({ ok: true, json: () => Promise.resolve({ ok: true, count, skipped: { no_phone: 2, opted_out: 1, recent: 0, duplicate: 0 } }) });
    }));
});

afterEach(() => {
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
});

describe('حملةٌ جديدة', () => {
    it('لا تُرسل حتى يُكتب العددُ المعروض ويُقرّ بالموافقة والكلفة، ثمّ تأكيدٌ أخير', async () => {
        const user = userEvent.setup();
        render(<CampaignsScreen view={VIEW} />);

        await user.click(screen.getByTestId('campaign-new'));
        await waitFor(() => expect(screen.getByTestId('campaign-count')).toHaveTextContent('42 رسالة'));
        expect(screen.getByTestId('campaign-count')).toHaveTextContent('1 طلبوا الإيقاف');
        expect(screen.getByTestId('campaign-count')).toHaveTextContent('على حسابك عند Meta');
        expect(screen.getByTestId('offer-body')).toHaveTextContent('أهلًا سارة');

        await user.type(screen.getByTestId('campaign-name'), 'عرض الجمعة');
        expect(screen.getByTestId('campaign-launch')).toBeDisabled();
        await user.click(screen.getByTestId('campaign-consent'));
        await user.type(screen.getByTestId('campaign-confirm'), '41');
        expect(screen.getByTestId('campaign-launch')).toBeDisabled();
        await user.clear(screen.getByTestId('campaign-confirm'));
        await user.type(screen.getByTestId('campaign-confirm'), '42');

        await user.click(screen.getByTestId('campaign-launch'));
        expect(sent).toHaveLength(0);
        expect(screen.getByRole('dialog')).toHaveTextContent('42');
        await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'أرسل' }));

        expect(sent[0].url).toContain('admin.marketing.whatsapp.campaigns.store');
        expect(sent[0].data).toMatchObject({ name: 'عرض الجمعة', template_id: 4, confirm_count: 42, consent: true, scheduled_at: null, audience: { segment: 'recent', days: 90, quiet_days: 3 } });
    });

    it('تغييرُ الاختيار يعيد حسابَ العدد ويمحو ما كُتب لتأكيده', async () => {
        const user = userEvent.setup();
        render(<CampaignsScreen view={VIEW} />);

        await user.click(screen.getByTestId('campaign-new'));
        await waitFor(() => expect(screen.getByTestId('campaign-count')).toHaveTextContent('42 رسالة'));
        await user.type(screen.getByTestId('campaign-confirm'), '42');
        count = 7;
        await user.click(screen.getByTestId('segment-never'));
        expect(screen.getByTestId('campaign-confirm')).toHaveValue('');
        await waitFor(() => expect(screen.getByTestId('campaign-count')).toHaveTextContent('7 رسالة'));
        expect(asked.at(-1)).toMatchObject({ segment: 'never' });
        expect(screen.queryByTestId('audience-min')).toBeNull();
    });

    it('بلا قالبٍ معتمدٍ لا حملة، وبمانعٍ لا زرّ', async () => {
        const user = userEvent.setup();
        const a = render(<CampaignsScreen view={{ ...VIEW, templates: [{ ...TEMPLATE, usable: false, status: 'PENDING', label: 'قيد مراجعة واتساب' }] }} />);
        await user.click(screen.getByTestId('campaign-new'));
        expect(screen.getByTestId('campaign-no-template')).toBeInTheDocument();
        a.unmount();

        render(<CampaignsScreen view={{ ...VIEW, obstacle: 'الحملات تخرج من رقم متجرك — اربطه أولًا.' }} />);
        expect(screen.getByTestId('campaigns-obstacle')).toHaveTextContent('اربطه أولًا');
        expect(screen.getByTestId('campaign-new')).toBeDisabled();
        expect(screen.getByTestId('template-new')).toBeDisabled();
    });
});

describe('القوالب', () => {
    it('المعاينةُ بزرّ الإيقاف دائمًا، والقالبُ يُرسل للمراجعة بزرّ رابطه', async () => {
        const user = userEvent.setup();
        render(<CampaignsScreen view={VIEW} />);

        await user.click(screen.getByTestId('template-new'));
        const form = screen.getByTestId('template-form');
        expect(within(form).getAllByTestId('offer-button').map((b) => b.textContent)).toEqual(['إيقاف الرسائل']);

        await user.type(screen.getByTestId('template-title'), 'عرض');
        await user.type(screen.getByTestId('template-body'), 'أهلًا');
        await user.click(within(form).getByRole('button', { name: '+ اسم العميل' }));
        expect(screen.getByTestId('template-body')).toHaveValue('أهلًا {الاسم}');
        expect(within(form).getByTestId('offer-body')).toHaveTextContent('أهلًا سارة');
        await user.type(screen.getByTestId('template-button'), 'اطلب');
        await user.click(within(form).getByRole('button', { name: 'استعمل رابط متجري' }));
        expect(within(form).getAllByTestId('offer-button').map((b) => b.textContent)).toEqual(['إيقاف الرسائل', 'اطلب']);

        await user.click(screen.getByTestId('template-send'));
        expect(sent[0].url).toContain('admin.marketing.whatsapp.campaigns.templates.store');
        expect(sent[0].data).toMatchObject({ language: 'ar', body: 'أهلًا {الاسم}', button: { title: 'اطلب', url: 'https://rose.test' } });
    });

    it('الحذفُ بعد تأكيد، والحالُ وسببُ الرفض يُعرضان', async () => {
        const user = userEvent.setup();
        render(<CampaignsScreen view={{ ...VIEW, templates: [{ ...TEMPLATE, status: 'REJECTED', label: 'مرفوض', why: 'محتوى يخالف سياسة واتساب.', usable: false }] }} />);

        expect(screen.getByTestId('template-4')).toHaveTextContent('مرفوض');
        expect(screen.getByTestId('template-4')).toHaveTextContent('يخالف سياسة');
        await user.click(screen.getByTestId('template-sync'));
        expect(vi.mocked(router.post).mock.calls[0][0]).toContain('campaigns.templates.sync');
        await user.click(screen.getByTestId('template-delete'));
        await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'حذف' }));
        expect(vi.mocked(router.delete).mock.calls[0][0]).toContain('campaigns.templates.destroy/4');
    });
});

describe('ما خرج', () => {
    it('تقول ما خرج ووصل وقُرئ وفشل ولماذا، وتوقَف وتُلغى', async () => {
        const user = userEvent.setup();
        render(<CampaignsScreen view={VIEW} />);

        const row = screen.getByTestId('campaign-9');
        expect(row).toHaveTextContent('خرجت 60 من 100');
        expect(row).toHaveTextContent('وصلت 50');
        expect(row).toHaveTextContent('فشلت 5');
        expect(screen.getByTestId('campaign-failures')).toHaveTextContent('لا واتساب على الرقم');

        await user.click(screen.getByTestId('campaign-pause'));
        expect(vi.mocked(router.post).mock.calls[0][0]).toContain('campaigns.pause/9');
        await user.click(screen.getByTestId('campaign-cancel'));
        await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'ألغِ الحملة' }));
        expect(vi.mocked(router.post).mock.calls.at(-1)?.[0]).toContain('campaigns.cancel/9');
    });

    it('الموقوفةُ بسببها تُستأنف، ومن لا يملك لا يرى زرًّا', async () => {
        const user = userEvent.setup();
        const a = render(<CampaignsScreen view={{ ...VIEW, campaigns: [{ ...ROW, status: 'paused', why: 'وسيلة الدفع في حسابك عند Meta تحتاج تحديثًا.' }] }} />);
        expect(screen.getByTestId('campaign-why')).toHaveTextContent('وسيلة الدفع');
        await user.click(screen.getByTestId('campaign-resume'));
        expect(vi.mocked(router.post).mock.calls[0][0]).toContain('campaigns.resume/9');
        a.unmount();

        render(<CampaignsScreen view={{ ...VIEW, may_manage: false }} />);
        expect(screen.queryByTestId('campaign-new')).toBeNull();
        expect(screen.queryByTestId('campaign-pause')).toBeNull();
        expect(screen.queryByTestId('optout-add')).toBeNull();
    });

    it('رقمٌ طلب الإيقاف يُضاف بيد التاجر', async () => {
        const user = userEvent.setup();
        render(<CampaignsScreen view={VIEW} />);

        expect(screen.getByTestId('optouts-total')).toHaveTextContent('3 رقم');
        await user.type(screen.getByTestId('optout-phone'), '91234567');
        await user.click(screen.getByTestId('optout-add'));
        expect(vi.mocked(router.post).mock.calls[0][0]).toContain('campaigns.optouts.store');
        expect(vi.mocked(router.post).mock.calls[0][1]).toEqual({ phone: '91234567' });
    });
});

describe('مدير المنصّة', () => {
    it('يفتحها لمتاجر تجربةٍ بعد التأكيد', async () => {
        const user = userEvent.setup();
        render(<WhatsappCampaignsSwitch state={{ mode: 'off', pilot: [], modes: ['off', 'pilot', 'all'], pilot_max: 50 }} />);

        expect(screen.getByTestId('campaigns-state')).toHaveTextContent('مقفلة');
        await user.click(screen.getByTestId('campaigns-mode-pilot'));
        await user.type(screen.getByTestId('campaigns-pilot'), '5');
        await user.click(screen.getByTestId('campaigns-save'));
        await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'حفظ' }));
        expect(vi.mocked(router.post).mock.calls.at(-1)?.[0]).toContain('super-admin.whatsappCampaigns.switch');
        expect(vi.mocked(router.post).mock.calls.at(-1)?.[1]).toEqual({ mode: 'pilot', pilot: '5' });
    });
});
