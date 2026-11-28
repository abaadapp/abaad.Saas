import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { router } from '@inertiajs/react';
import { router as core } from '@inertiajs/core';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { AutomationsScreen, type AutomationsView } from '@/Pages/Admin/Integrations/WhatsappAutomations';
import { publishProblem, renderReply, type AutomationRow } from '@/Pages/Admin/Integrations/partials/AutomationWizard';
import ChannelAutomationsSwitch from '@/Pages/Platform/Settings/partials/ChannelAutomationsSwitch';

/**
 * ‏‏أتمتةُ واتساب كما يراها التاجر — قوالبُ يبدأ منها، ومعالجٌ بثلاث خطوات، ومعاينةٌ لما يصل الزبون،
 * وقائمةٌ تقول ما يعمل وكم مرّة. ومديرُ المنصّة يفتحها لمتاجر تجربةٍ بأرقامها.
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
    id: 7, name: 'السعر', template: 'price', match: 'contains', keywords: ['السعر', 'بكم'], excluded: [],
    reply_ar: 'سعرنا ممتاز', reply_en: '', buttons: [], product_id: null, status: 'published', cooldown_minutes: 60,
    counts: { matched: 12, sent: 10, failed: 2 }, last_run_at: null,
};

const VIEW: AutomationsView = {
    available: true,
    may_manage: true,
    automations: [ROW],
    welcome: { enabled: true, state: { state: 'working', reason: null } },
    templates: [
        { key: 'price', name: 'السعر ← رابط المنتج', description: 'وصف', keywords: ['السعر', 'بكم'], reply_ar: '{المنتج} بسعر {السعر}\n{رابط_المنتج}', reply_en: '', buttons: [], needs_product: true },
        { key: 'hours', name: 'ساعات العمل', description: 'وصف', keywords: ['الدوام'], reply_ar: 'ساعات عملنا: [اكتب أيامك وساعاتك]', reply_en: '', buttons: [], needs_product: false },
    ],
    products: [{ id: 3, name: 'باقة الجوري', price: '12.500 ر.ع', link: 'https://rose.abaadapp.om/p/3' }],
    context: { store: 'محل الورد', store_link: 'https://rose.abaadapp.om' },
    variables: {},
    limits: { automations: 50, keywords: 20, keyword: 40, name: 80, text: 900, buttons: 3, button_title: 20, cooldown_max: 10080 },
};

const sent: { url: string; data: Record<string, unknown>; method: string }[] = [];

beforeEach(() => {
    sent.length = 0;
    vi.mocked(router.post).mockClear();
    for (const method of ['post', 'put'] as const) {
        vi.spyOn(core, method).mockImplementation(((url: string, data: Record<string, unknown>) => {
            sent.push({ url, data, method });
        }) as never);
    }
});

afterEach(() => vi.restoreAllMocks());

describe('النصّ كما يصل الزبون', () => {
    it('يملأ المتغيّرات بقيمها ويحذف ما لا قيمة له', () => {
        expect(renderReply('{المنتج} بسعر {السعر} — {رابط_المنتج}', { product: 'باقة', price: '5.000 ر.ع', product_link: 'https://x.om/p/1' }))
            .toBe('باقة بسعر 5.000 ر.ع — https://x.om/p/1');
        expect(renderReply('أهلًا {الاسم}، تفضّل', {})).toBe('أهلًا، تفضّل');
        expect(renderReply('Hi {name}!', { name: 'Sara' })).toBe('Hi Sara!');
    });

    it('لا ينشر بلا كلمة ولا نصّ ولا وما بين القوسين ينتظر', () => {
        expect(publishProblem({ keywords: [], reply_ar: 'نص', reply_en: '' })).toContain('كلمة واحدة');
        expect(publishProblem({ keywords: ['سعر'], reply_ar: ' ', reply_en: '' })).toContain('نص الرد');
        expect(publishProblem({ keywords: ['سعر'], reply_ar: 'دوامنا [اكتب ساعاتك]', reply_en: '' })).toContain('القوسين');
        expect(publishProblem({ keywords: ['سعر'], reply_ar: 'دوامنا من 9', reply_en: '' })).toBeNull();
    });
});

describe('صفحة أتمتة واتساب', () => {
    it('تقول طريقة العمل وتعرض القوالب والترحيب أوّلًا وعدّادات كلّ أتمتة', () => {
        render(<AutomationsScreen view={VIEW} />);

        expect(within(screen.getByTestId('how-it-works')).getAllByRole('listitem')).toHaveLength(3);
        expect(within(screen.getByTestId('templates')).getAllByRole('button')).toHaveLength(2);
        expect(screen.getByTestId('automation-welcome')).toHaveTextContent('رسالة الترحيب');
        expect(screen.getByTestId('automation-7')).toHaveTextContent('تعمل');
        expect(screen.getByTestId('automation-counts')).toHaveTextContent('تشغّلت 12');
        expect(screen.getByTestId('automation-counts')).toHaveTextContent('وصلت 10');
        expect(screen.getByTestId('automation-counts')).toHaveTextContent('فشلت 2');
    });

    it('الإيقاف والحذف بعد التأكيد', async () => {
        const user = userEvent.setup();
        render(<AutomationsScreen view={VIEW} />);

        await user.click(screen.getByTestId('automation-flip'));
        expect(vi.mocked(router.post).mock.calls[0][0]).toContain('admin.integrations.whatsapp.automations.status/7');
        expect(vi.mocked(router.post).mock.calls[0][1]).toEqual({ status: 'paused' });

        await user.click(screen.getByTestId('automation-delete'));
        await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'حذف' }));
        expect(vi.mocked(router.delete).mock.calls.at(-1)?.[0]).toContain('admin.integrations.whatsapp.automations.destroy/7');
    });

    it('القالب يفتح المعالج مملوءًا، والمعاينة تقول ما يصل بمنتج المتجر الحقيقيّ، والنشر يُرسل', async () => {
        const user = userEvent.setup();
        render(<AutomationsScreen view={VIEW} />);

        await user.click(screen.getByTestId('template-price'));
        expect(screen.getByTestId('automation-wizard')).toBeInTheDocument();
        expect(screen.getByTestId('preview-customer')).toHaveTextContent('السعر');

        await user.click(screen.getByTestId('wizard-next'));
        expect(screen.getByTestId('wizard-keywords')).toHaveTextContent('بكم');
        await user.type(within(screen.getByTestId('wizard-keywords')).getByRole('textbox'), 'كم سعر{Enter}');
        expect(screen.getByTestId('wizard-keywords')).toHaveTextContent('كم سعر');

        await user.click(screen.getByTestId('wizard-next'));
        await user.selectOptions(screen.getAllByRole('combobox')[0], '3');
        expect(screen.getByTestId('preview-reply')).toHaveTextContent('باقة الجوري بسعر 12.500 ر.ع');
        expect(screen.getByTestId('preview-reply')).toHaveTextContent('https://rose.abaadapp.om/p/3');

        await user.click(screen.getByTestId('wizard-publish'));
        expect(sent).toHaveLength(1);
        expect(sent[0].url).toContain('admin.integrations.whatsapp.automations.store');
        expect(sent[0].data).toMatchObject({ status: 'published', product_id: 3, template: 'price', keywords: ['السعر', 'بكم', 'كم سعر'] });
    });

    it('قالبٌ ينتظر ما يكتبه التاجر لا يُنشر — ويُحفظ مسوّدة', async () => {
        const user = userEvent.setup();
        render(<AutomationsScreen view={VIEW} />);

        await user.click(screen.getByTestId('template-hours'));
        await user.click(screen.getByTestId('wizard-next'));
        await user.click(screen.getByTestId('wizard-next'));

        expect(screen.getByTestId('wizard-publish')).toBeDisabled();
        expect(screen.getByTestId('wizard-problem')).toHaveTextContent('القوسين');

        await user.click(screen.getByTestId('wizard-draft'));
        expect(sent[0].data).toMatchObject({ status: 'draft' });
    });

    it('التعديل يُرسل إلى الأتمتة نفسها، وزرّ الرابط واحدٌ يظهر في المعاينة', async () => {
        const user = userEvent.setup();
        render(<AutomationsScreen view={VIEW} />);

        await user.click(screen.getByTestId('automation-edit'));
        await user.click(screen.getByTestId('wizard-next'));
        await user.click(screen.getByTestId('wizard-next'));
        await user.click(within(screen.getByTestId('wizard-buttons')).getByRole('button', { name: 'زر رابط' }));
        expect(screen.getAllByTestId('preview-button')).toHaveLength(1);
        expect(screen.getByTestId('preview-button')).toHaveTextContent('افتح المتجر');

        await user.click(screen.getByTestId('wizard-publish'));
        expect(sent[0].method).toBe('put');
        expect(sent[0].url).toContain('admin.integrations.whatsapp.automations.update/7');
        expect(sent[0].data).toMatchObject({ buttons: [{ type: 'link', title: 'افتح المتجر', url: '{رابط_المتجر}' }] });
    });

    it('من لا يملك الإدارة يقرأ ولا يكتب', () => {
        render(<AutomationsScreen view={{ ...VIEW, may_manage: false }} />);

        expect(screen.queryByTestId('automation-new')).toBeNull();
        expect(screen.queryByTestId('automation-flip')).toBeNull();
        expect(screen.getByTestId('template-price')).toBeDisabled();
    });
});

describe('مدير المنصّة يفتح الأتمتة', () => {
    it('مقفلةٌ — ثمّ لمتاجر تجربةٍ بأرقامها بعد التأكيد', async () => {
        const user = userEvent.setup();
        render(<ChannelAutomationsSwitch state={{ mode: 'off', pilot: [], modes: ['off', 'pilot', 'all'], pilot_max: 50 }} />);

        expect(screen.getByTestId('automations-state')).toHaveTextContent('مقفلة');
        await user.click(screen.getByTestId('automations-mode-pilot'));
        await user.type(screen.getByTestId('automations-pilot'), '5, 12');
        await user.click(screen.getByTestId('automations-save'));
        await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'حفظ' }));

        expect(vi.mocked(router.post).mock.calls.at(-1)?.[0]).toContain('super-admin.automations.switch');
        expect(vi.mocked(router.post).mock.calls.at(-1)?.[1]).toEqual({ mode: 'pilot', pilot: '5, 12' });
    });
});
