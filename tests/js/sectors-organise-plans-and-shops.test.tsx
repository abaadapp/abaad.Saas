import { useRef, useState } from 'react';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { pageProps } from './setup';

/**
 * القطاعات في لوحة المنصّة — وإضافةُ شركةٍ أبسط.
 *
 *  • بطاقةٌ لكلّ قطاع بحالته وعدّ باقاته وشركاته؛ والرمزُ يُكتب في الإضافة ويُعرض ثابتًا في التعديل.
 *  • نموذجُ الإنشاء صفحةٌ واحدة: القطاعُ الوحيدُ يُحدَّد وحده، والباقاتُ من قطاعه وحده،
 *    والاختياريُّ مطويٌّ ويُفتح وحده إن ردّ الخادمُ خطأً فيه، وملخّصٌ قبل الزرّ الواحد.
 *  • صفحةُ القطاع: باقاتُه، وشركاتُه بحال اشتراكها ورابطِ اشتراكها، و«إضافة شركة» بقطاعه.
 *
 * والخادمُ يحكم بها كلِّها: `ASectorGathersItsPlansAndShopsTest`.
 */

const sent: { url: string; payload: Record<string, unknown> }[] = [];
let errorsNow: Record<string, string> = {};

vi.mock('@inertiajs/react', async () => {
    const actual = await vi.importActual<Record<string, unknown>>('@inertiajs/react');

    const useForm = (initial: Record<string, unknown>) => {
        const [data, setData] = useState(initial);
        const shape = useRef<(d: Record<string, unknown>) => Record<string, unknown>>((d) => d);
        const send = (url: string) => sent.push({ url, payload: shape.current({ ...data }) });

        return {
            data,
            errors: errorsNow,
            processing: false,
            setData: (key: unknown, value?: unknown) =>
                setData((d) =>
                    typeof key === 'function'
                        ? (key as (d: Record<string, unknown>) => Record<string, unknown>)(d)
                        : typeof key === 'string'
                          ? { ...d, [key]: value }
                          : { ...d, ...(key as Record<string, unknown>) },
                ),
            transform: (fn: (d: Record<string, unknown>) => Record<string, unknown>) => {
                shape.current = fn;
            },
            post: send,
            put: send,
            reset: () => setData(initial),
            clearErrors: () => {},
            setError: () => {},
        };
    };

    return {
        ...actual,
        usePage: () => ({ props: pageProps, url: '/', component: 'Test' }),
        router: { visit: vi.fn(), get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn(), on: vi.fn() },
        Head: () => null,
        Link: ({ href, children, ...rest }: { href: string; children: React.ReactNode }) => <a href={href} {...rest}>{children}</a>,
        useForm,
    };
});

vi.mock('@/Layouts/PlatformLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

const { default: SectorsIndex } = await import('@/Pages/Platform/Sectors/Index');
const { default: SectorShow } = await import('@/Pages/Platform/Sectors/Show');
const { default: BusinessForm } = await import('@/Pages/Platform/Businesses/partials/BusinessForm');

const plan = (value: number, label: string, sector_id: number) => ({
    label, value, monthly: 9.9, semiannual: 59.4, yearly: 99, features: [], max_branches: 1, max_employees: 3, max_products: 100, sector_id,
});
const BASIC = plan(1, 'الباقة الأساسية', 1);
const PRO = plan(2, 'الباقة الاحترافية', 1);
const CLINIC = plan(3, 'باقة العيادات', 2);

const RETAIL = { id: 1, name: 'التجارة والتجزئة', icon: 'store' };
const HEALTH = { id: 2, name: 'الرعاية الصحية', icon: 'stethoscope' };

const SUBSCRIPTION = {
    policy: 'addons_v1' as const,
    trial_days: 14,
    basic_plan_id: 1,
    cycles: [
        { value: 'monthly' as const, label: 'شهري' },
        { value: 'semiannual' as const, label: 'نصف سنوي' },
        { value: 'yearly' as const, label: 'سنوي' },
    ],
    plans: [
        { id: 1, name: 'الباقة الأساسية', prices: { monthly: 9.9, semiannual: 59.4, yearly: 99 }, included: [], eligible: [] },
        { id: 2, name: 'الباقة الاحترافية', prices: { monthly: 29.9, semiannual: 179.4, yearly: 299 }, included: [], eligible: [] },
        { id: 3, name: 'باقة العيادات', prices: { monthly: 19.9, semiannual: 119.4, yearly: 199 }, included: [], eligible: [] },
    ],
};

const EMPTY = {
    name: '', type: '', country: 'عُمان', city: '', address: '', owner_name: '', phone: '', email: '', plan_id: '',
    sector_id: '', tier: '', storefront_theme: '', status: 'نشط', starts_at: '', ends_at: '',
    subscription_mode: 'trial' as const, cycle: '' as const, grant_until: '', grant_reason: '', addons: [],
};

function createForm(sectors: typeof RETAIL[], sectorId: number | null = null) {
    return render(
        <BusinessForm
            options={{ types: [], cities: [], statuses: ['نشط'], plans: [BASIC, PRO, CLINIC], subscription: SUBSCRIPTION, sectors }}
            initial={{ ...EMPTY, sector_id: sectorId ? String(sectorId) : '' }}
            sectorId={sectorId}
            action="/super-admin.businesses.store"
            method="post"
            submitLabel="حفظ الشركة"
            cancelHref="/super-admin.businesses.index"
        />,
    );
}

/** خياراتُ قائمة الباقات كما تُرسم — القائمةُ الخفيّة التي يحملها النموذج */
const planChoices = () => {
    const select = [...document.querySelectorAll('select')].find((s) => [...s.options].some((o) => o.textContent === 'الباقة الأساسية' || o.textContent === 'باقة العيادات'));
    return select ? [...select.options].map((o) => o.textContent).filter((t) => t && !t.includes('اختر')) : [];
};

beforeAll(() => {
    Element.prototype.hasPointerCapture = () => false;
    Element.prototype.setPointerCapture = () => {};
    Element.prototype.releasePointerCapture = () => {};
    Element.prototype.scrollIntoView = () => {};
});

beforeEach(() => {
    sent.length = 0;
    errorsNow = {};
    Object.assign(pageProps, { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 } });
});

describe('القطاعات', () => {
    const CARD = {
        id: 1, code: 'retail', name: 'التجارة والتجزئة', name_en: 'Retail & Commerce', icon: 'store', sort: 1, active: true,
        system: 'نظام أبعاد', plans_count: 3, businesses_count: 12,
    };

    it('بطاقةٌ لكلّ قطاع بحالته وعدّه — والرمزُ ثابتٌ في التعديل', async () => {
        const user = userEvent.setup();
        Object.assign(pageProps, { sectors: [CARD, { ...CARD, id: 2, code: 'health', name: 'الرعاية الصحية', active: false, plans_count: 0, businesses_count: 0 }], icons: ['store', 'stethoscope'] });
        render(<SectorsIndex />);

        const retail = screen.getByTestId('sector-card-retail');
        expect(retail).toHaveTextContent('التجارة والتجزئة');
        expect(within(retail).getByTestId('sector-status')).toHaveTextContent('نشط');
        expect(retail).toHaveTextContent('12');
        expect(within(screen.getByTestId('sector-card-health')).getByTestId('sector-status')).toHaveTextContent('غير نشط');

        await user.click(screen.getByTestId('sector-edit-retail'));
        expect(screen.getByTestId('sector-code')).toBeDisabled();
        expect(screen.getByTestId('sector-code')).toHaveValue('retail');

        await user.clear(screen.getByDisplayValue('التجارة والتجزئة'));
        await user.type(screen.getByPlaceholderText('مثال: التجارة والتجزئة'), 'التجارة');
        await user.click(screen.getByRole('button', { name: 'حفظ التغييرات' }));

        expect(sent).toHaveLength(1);
        expect(sent[0].url).toBe('/super-admin.sectors.update/1');
        expect(sent[0].payload).toMatchObject({ name: 'التجارة', icon: 'store', active: true });
        expect(sent[0].payload).not.toHaveProperty('code');
    });

    it('القطاعُ الجديد يُكتب رمزُه ويُختار أيقونتُه', async () => {
        const user = userEvent.setup();
        Object.assign(pageProps, { sectors: [CARD], icons: ['store', 'stethoscope'] });
        render(<SectorsIndex />);

        await user.click(screen.getByTestId('sector-new'));
        expect(screen.getByTestId('sector-code')).toBeEnabled();
        await user.type(screen.getByTestId('sector-code'), 'Health');
        await user.type(screen.getByPlaceholderText('مثال: التجارة والتجزئة'), 'الرعاية الصحية');
        await user.click(screen.getByRole('radio', { name: 'stethoscope' }));
        await user.click(screen.getByRole('button', { name: 'إضافة القطاع' }));

        expect(sent[0].url).toBe('/super-admin.sectors.store');
        expect(sent[0].payload).toMatchObject({ code: 'health', name: 'الرعاية الصحية', icon: 'stethoscope', active: true });
    });
});

describe('إضافة شركة', () => {
    it('القطاعُ الوحيدُ يُحدَّد وحده — وباقاتُه وحدها تُعرض', () => {
        createForm([RETAIL], 1);

        expect(screen.getByTestId('business-sector-fixed')).toHaveTextContent('التجارة والتجزئة');
        expect(screen.queryByRole('combobox', { name: 'القطاع' })).toBeNull();
        expect(planChoices()).toEqual(['الباقة الأساسية', 'الباقة الاحترافية']);
    });

    it('القادمُ من صفحة قطاعٍ يجده محدَّدًا ولو تعدّدت القطاعات', () => {
        createForm([RETAIL, HEALTH], 2);

        expect(screen.getByTestId('business-sector-fixed')).toHaveTextContent('الرعاية الصحية');
        expect(planChoices()).toEqual(['باقة العيادات']);
    });

    it('وبقطاعين بلا تحديد يُختار القطاعُ أوّلًا، فتتبدّل الباقات', async () => {
        const user = userEvent.setup();
        createForm([RETAIL, HEALTH]);

        const trigger = screen.getByRole('combobox', { name: 'القطاع' });
        await user.click(trigger);
        await user.click(await screen.findByRole('option', { name: 'الرعاية الصحية' }));

        expect(planChoices()).toEqual(['باقة العيادات']);
    });

    it('الاختياريُّ مطويّ — ويُفتح وحده إن ردّ الخادمُ خطأً فيه', async () => {
        const user = userEvent.setup();
        const { unmount } = createForm([RETAIL], 1);

        expect(screen.queryByText('بريد التواصل')).toBeNull();
        expect(screen.queryByText('الفئة')).toBeNull();
        await user.click(screen.getByTestId('business-extra-toggle'));
        expect(screen.getByText('بريد التواصل')).toBeInTheDocument();
        unmount();

        errorsNow = { email: 'البريد غير صحيح' };
        createForm([RETAIL], 1);
        expect(screen.getByText('البريد غير صحيح')).toBeInTheDocument();
        expect(screen.getByTestId('business-extra-toggle')).toHaveAttribute('aria-expanded', 'true');
    });

    it('ملخّصٌ قبل الزرّ الواحد — والقطاعُ يُرسل مع الطلب', async () => {
        const user = userEvent.setup();
        createForm([RETAIL], 1);

        await user.type(screen.getByPlaceholderText('مثال: زهرة مسقط'), 'زهرة الشاطئ');
        await user.type(screen.getByPlaceholderText('مثال: محل ورود'), 'محل ورود');
        await user.type(screen.getByPlaceholderText('zahra'), 'zahra');
        await user.click(screen.getByRole('button', { name: 'ولّد كلمة مرور' }));

        expect(screen.getByTestId('review-name')).toHaveTextContent('زهرة الشاطئ');
        expect(screen.getByTestId('review-sector')).toHaveTextContent('التجارة والتجزئة · محل ورود');
        expect(screen.getByTestId('review-account')).toHaveTextContent('zahra@abaadapp.om');
        // والتجربةُ على الأساسيّة أيًّا كان الاختيار
        expect(screen.getByTestId('review-plan')).toHaveTextContent('الباقة الأساسية');
        expect(screen.getByTestId('review-mode')).toHaveTextContent('تجربة الأساسية 14 يومًا');
        expect(screen.getByTestId('review-price')).toHaveTextContent('14 يومًا — بلا فاتورة');

        expect(screen.getAllByRole('button', { name: 'حفظ الشركة' })).toHaveLength(1);
        await user.click(screen.getByTestId('business-save'));
        expect(sent).toHaveLength(1);
        expect(sent[0].payload).toMatchObject({ name: 'زهرة الشاطئ', sector_id: '1', subscription_mode: 'trial' });
    });
});

describe('صفحة القطاع', () => {
    const page = () =>
        Object.assign(pageProps, {
            sector: { id: 7, code: 'retail', name: 'التجارة والتجزئة', name_en: 'Retail & Commerce', icon: 'store', sort: 1, active: true, system: 'نظام أبعاد', plans_count: 1, businesses_count: 2 },
            icons: ['store'],
            tab: 'plans',
            plans: [{ id: 1, name: 'الباقة الأساسية', monthly: 9.9, yearly: 99, color: 'primary', popular: false, features: [], max_branches: 1, max_employees: 3, max_products: 100, capabilities: null, sector_id: 7 }],
            capabilityOptions: [],
            businesses: [
                { id: 12, name: 'زهرة مسقط', type: 'محل ورود', plan: 'الباقة الأساسية', state: 'trial', expires: '2026-11-20', daysLeft: 14 },
                { id: 13, name: 'ورد الريان', type: 'محل ورود', plan: 'الباقة الأساسية', state: 'subscribed', expires: '2027-01-01', daysLeft: 56 },
            ],
            pagination: { current_page: 1, last_page: 1, from: 1, to: 2, total: 2, prev_page_url: null, next_page_url: null },
            filters: {},
            planNames: ['الباقة الأساسية'],
            system: { code: 'abaad', name: 'نظام أبعاد', name_en: 'Abaad', running: true, live: 2 },
        });

    it('باقاتُه، ثمّ شركاتُه بحال اشتراكها ورابطِ اشتراكها، ثمّ نظامُه', async () => {
        const user = userEvent.setup();
        page();
        render(<SectorShow />);

        expect(screen.getByTestId('sector-plans')).toHaveTextContent('الباقة الأساسية');
        expect(screen.getByTestId('sector-add-business')).toHaveAttribute('href', '/super-admin.businesses.create/7');

        await user.click(screen.getByRole('tab', { name: /الشركات والاشتراكات/ }));
        const table = screen.getByTestId('sector-businesses');
        expect(within(table).getAllByTestId('sector-business-state').map((s) => s.textContent)).toEqual(['في التجربة', 'مشترك']);
        expect(within(table).getAllByTestId('sector-business-subscription')[0]).toHaveAttribute('href', '/super-admin.businesses.show/12#subscription');

        await user.click(screen.getByRole('tab', { name: /النظام/ }));
        expect(screen.getByTestId('sector-system')).toHaveTextContent('نظام أبعاد');
        expect(screen.getByTestId('sector-system-status')).toHaveTextContent('يعمل');
    });
});
