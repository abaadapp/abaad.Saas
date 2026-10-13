import { useRef, useState } from 'react';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { pageProps } from './setup';

/**
 * الباقةُ المختارة تُعرض بما فيها هي — لا بقدرات النظام كلِّها.
 *
 * تحت اختيار الباقة في «الشركات» و«تعديل الاشتراك» بطاقةُ «تفاصيل الباقة
 * المختارة»: نصوصُها وسعراها وسقوفُها من صفّها. والأساسيّةُ خمسةُ أسطر، لا
 * واتساب فيها ولا موقع ولا تطبيقات تكامليّة. وبطاقةُ الباقة في صفحة الباقات
 * كذلك — أمّا محرّرُها فيعرض المربّعاتِ كلَّها لمن يقرّر ما تفتحه.
 */

const sent: { url: string; payload: Record<string, unknown> }[] = [];

vi.mock('@inertiajs/react', async () => {
    const actual = await vi.importActual<Record<string, unknown>>('@inertiajs/react');

    const useForm = (initial: Record<string, unknown>) => {
        const [data, setData] = useState(initial);
        const shape = useRef<(d: Record<string, unknown>) => Record<string, unknown>>((d) => d);
        const send = (url: string) => sent.push({ url, payload: shape.current({ ...data }) });

        return {
            data,
            errors: {} as Record<string, string>,
            processing: false,
            setData: (key: unknown, value?: unknown) =>
                setData((d) =>
                    typeof key === 'string' ? { ...d, [key]: value } : { ...d, ...(key as Record<string, unknown>) },
                ),
            transform: (fn: (d: Record<string, unknown>) => Record<string, unknown>) => {
                shape.current = fn;
            },
            post: send,
            put: send,
            patch: send,
            reset: () => setData(initial),
            clearErrors: () => {},
            setError: () => {},
            recentlySuccessful: false,
            wasSuccessful: false,
            isDirty: false,
        };
    };

    return {
        ...actual,
        usePage: () => ({ props: pageProps, url: '/', component: 'Test' }),
        router: { visit: vi.fn(), get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn(), on: vi.fn() },
        Head: () => null,
        useForm,
    };
});

vi.mock('@/Layouts/PlatformLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

const { default: PlanSelectionSummary } = await import('@/Components/PlanSelectionSummary');
const { default: BusinessForm } = await import('@/Pages/Platform/Businesses/partials/BusinessForm');
const { default: SubscriptionsIndex } = await import('@/Pages/Platform/Subscriptions/Index');
const { default: Plans } = await import('@/Pages/Platform/Subscriptions/Plans');

const BASIC_LINES = ['إدارة متجرك اليومية', 'فرع واحد', '3 موظفين', '100 منتج', 'الأدوات التشغيلية الأساسية'];
const PRO_LINES = ['إدارة + نمو', '3 فروع', '15 موظفًا', '5,000 منتج', 'الموقع الإلكتروني', 'أدوات التسويق', 'واتساب أبعاد'];

/** ما لا يُعرض تحت الأساسيّة — قدراتٌ ليست فيها */
const NOT_IN_BASIC = ['واتساب', 'الموقع الإلكتروني', 'التطبيقات التكاملية'];

const BASIC = {
    label: 'الباقة الأساسية', value: 1, monthly: 9.9, yearly: 99,
    features: BASIC_LINES, max_branches: 1, max_employees: 3, max_products: 100,
};
const PRO = {
    label: 'الباقة الاحترافية', value: 2, monthly: 29.9, yearly: 299,
    features: PRO_LINES, max_branches: 3, max_employees: 15, max_products: 5000,
};

const CAPABILITY_OPTIONS = [
    { key: 'reports_advanced', label: 'التقارير المتقدّمة', hint: '' },
    { key: 'custom_permissions', label: 'الصلاحيات المخصّصة', hint: '' },
    { key: 'loyalty', label: 'برنامج الولاء', hint: '' },
    { key: 'whatsapp', label: 'إشعارات واتساب', hint: '' },
    { key: 'website', label: 'الموقع الإلكتروني', hint: '' },
    { key: 'marketing_tools', label: 'أدوات التسويق', hint: '' },
    { key: 'integrations', label: 'التطبيقات التكاملية', hint: '' },
];

beforeAll(() => {
    // ‏والقائمةُ المنسدلة تسأل عن مؤشّرٍ لا يملكه jsdom — يُعطى جسدًا فارغًا لا يُحتال عليه في الكود
    Element.prototype.hasPointerCapture = () => false;
    Element.prototype.setPointerCapture = () => {};
    Element.prototype.releasePointerCapture = () => {};
});

beforeEach(() => {
    sent.length = 0;
    Object.assign(pageProps, { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 } });
});

/** البطاقةُ المعروضة — واحدةٌ أو لا شيء */
const summary = () => screen.queryAllByTestId('plan-summary');

const lines = (el: HTMLElement) => within(el).queryAllByRole('listitem').map((li) => li.textContent?.trim());

async function choose(user: ReturnType<typeof userEvent.setup>, trigger: HTMLElement, name: string) {
    await user.click(trigger);
    await user.click(await screen.findByRole('option', { name }));
}

describe('بطاقةُ «تفاصيل الباقة المختارة»', () => {
    it('الأساسيّةُ: اسمُها وسعراها وأسطرُها الخمسة — ولا قدرةَ ليست فيها', () => {
        render(<PlanSelectionSummary plan={BASIC} />);
        const card = summary()[0];

        expect(card).toHaveTextContent('تفاصيل الباقة المختارة');
        expect(card).toHaveTextContent('الباقة الأساسية');
        expect(card).toHaveTextContent('9.900');
        expect(card).toHaveTextContent('99.000');
        expect(lines(card)).toEqual(BASIC_LINES);

        for (const text of NOT_IN_BASIC) {
            expect(card.textContent, text).not.toContain(text);
        }
        for (const option of CAPABILITY_OPTIONS) {
            expect(card.textContent, option.label).not.toContain(option.label);
        }
    });

    it('ولا بطاقةَ قبل أن تُختار باقة', () => {
        render(<PlanSelectionSummary plan={undefined} />);
        expect(summary()).toHaveLength(0);
    });
});

describe('نموذجُ الشركة', () => {
    const form = () =>
        render(
            <BusinessForm
                options={{ types: ['محل ورود'], cities: ['مسقط'], statuses: ['نشط'], plans: [BASIC, PRO] }}
                initial={{
                    name: 'متجر', type: 'محل ورود', country: 'عُمان', city: 'مسقط', address: '', owner_name: '',
                    phone: '', email: '', plan_id: '', status: 'نشط', starts_at: '', ends_at: '', tier: '', storefront_theme: '',
                } as never}
                logoUrl={null}
                action="/super-admin/businesses"
                method="post"
                submitLabel="حفظ"
                cancelHref="/super-admin/businesses"
            />,
        );

    const planTrigger = () =>
        screen.getAllByRole('combobox').find((c) => c.textContent?.includes('اختر الباقة') || c.textContent?.includes('الباقة'))!;

    it('اختيارُ الأساسيّة يعرض أسطرَها الخمسة وحدها', async () => {
        const user = userEvent.setup();
        form();
        expect(summary()).toHaveLength(0);

        await choose(user, planTrigger(), 'الباقة الأساسية');

        expect(summary()).toHaveLength(1);
        expect(lines(summary()[0])).toEqual(BASIC_LINES);
    });

    it('والاحترافيّةُ أسطرَها هي — والتبديلُ يستبدل لا يُراكم', async () => {
        const user = userEvent.setup();
        form();

        await choose(user, planTrigger(), 'الباقة الأساسية');
        await choose(user, planTrigger(), 'الباقة الاحترافية');

        expect(summary()).toHaveLength(1);
        expect(lines(summary()[0])).toEqual(PRO_LINES);
        expect(summary()[0].textContent).not.toContain('إدارة متجرك اليومية');
    });
});

describe('نافذةُ تعديل الاشتراك', () => {
    const page = () => {
        Object.assign(pageProps, {
            stats: [],
            planNames: ['الباقة الأساسية', 'الباقة الاحترافية'],
            planOptions: [BASIC, PRO],
            subscriptions: [{
                id: 9, business_id: 4, plan_id: 2, business: 'متجر نامٍ', plan: 'الباقة الاحترافية',
                start: '2026-10-01', end: '2026-10-31', amount: 25, payment: 'مدفوع', status: 'نشط',
            }],
        });

        render(<SubscriptionsIndex />);

        return userEvent.setup();
    };

    it('تعرض باقةَ الاشتراك ثمّ المختارةَ بدلها — والمبلغُ يُقترح ولا يُفرض', async () => {
        const user = page();

        await user.click(screen.getByRole('button', { name: 'إجراءات' }));
        await user.click(await screen.findByRole('menuitem', { name: 'تعديل' }));
        const dialog = await screen.findByRole('dialog');

        expect(lines(within(dialog).getByTestId('plan-summary'))).toEqual(PRO_LINES);

        const trigger = within(dialog).getAllByRole('combobox')[0];
        await choose(user, trigger, 'الباقة الأساسية');

        const card = within(dialog).getAllByTestId('plan-summary');
        expect(card).toHaveLength(1);
        expect(lines(card[0])).toEqual(BASIC_LINES);

        // ‏والمبلغُ المتّفق عليه يبقى كما كُتب — لم يتبع الباقة
        const amount = within(dialog).getByDisplayValue('25');
        expect(amount).toBeInTheDocument();

        // ‏وزرُّ «سعر الشهر» يقترح سعرَ المختارة…
        await user.click(within(dialog).getByRole('button', { name: 'سعر الشهر' }));
        const suggested = within(dialog).getByDisplayValue('9.9');

        // ‏…والخصمُ اليدويُّ يُكتب فوقه، ولا يمحوه تبديلُ الباقة بعده
        await user.clear(suggested);
        await user.type(suggested, '7.5');
        await choose(user, within(dialog).getAllByRole('combobox')[0], 'الباقة الاحترافية');

        expect(within(dialog).getByDisplayValue('7.5')).toBeInTheDocument();
        expect(lines(within(dialog).getByTestId('plan-summary'))).toEqual(PRO_LINES);
    });
});

describe('صفحةُ الباقات', () => {
    const render_ = () => {
        Object.assign(pageProps, {
            capabilityOptions: CAPABILITY_OPTIONS,
            plans: [
                { id: 1, name: 'الباقة الأساسية', monthly: 9.9, yearly: 99, color: 'primary', popular: false, features: BASIC_LINES, max_branches: 1, max_employees: 3, max_products: 100, capabilities: [] },
                { id: 2, name: 'الباقة الاحترافية', monthly: 29.9, yearly: 299, color: 'secondary', popular: true, features: PRO_LINES, max_branches: 3, max_employees: 15, max_products: 5000, capabilities: CAPABILITY_OPTIONS.map((o) => o.key) },
            ],
        });
        render(<Plans />);

        return userEvent.setup();
    };

    const basicCard = () => screen.getByRole('heading', { name: 'الباقة الأساسية' }).closest('.relative') as HTMLElement;

    it('بطاقةُ الأساسيّة: أسطرُها الخمسة وحدها — لا واتساب ولا موقع ولا تكاملات', () => {
        render_();
        const card = basicCard();

        expect(lines(card)).toEqual(BASIC_LINES);
        for (const text of NOT_IN_BASIC) {
            expect(card.textContent, text).not.toContain(text);
        }
    });

    it('ومحرّرُها يعرض المربّعاتِ كلَّها — ومنها التطبيقات التكامليّة', async () => {
        const user = render_();

        await user.click(within(basicCard()).getByRole('button', { name: 'تعديل الباقة' }));
        const dialog = await screen.findByRole('dialog');

        for (const option of CAPABILITY_OPTIONS) {
            expect(within(dialog).getByText(option.label), option.label).toBeInTheDocument();
        }
        // والأساسيّةُ `[]`: لا مربّعَ مؤشَّرًا
        expect(within(dialog).getAllByRole('checkbox').filter((c) => (c as HTMLInputElement).checked)).toHaveLength(0);
        expect(within(dialog).getAllByRole('checkbox').length).toBeGreaterThanOrEqual(CAPABILITY_OPTIONS.length);
    });
});
