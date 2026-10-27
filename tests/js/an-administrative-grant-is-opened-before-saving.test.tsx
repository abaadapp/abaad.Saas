import { useRef, useState } from 'react';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { pageProps } from './setup';

/**
 * المنحةُ الإداريّة إجراءٌ صريحٌ يُفتح قبل الإرسال — في «تعديل الشركة» و«تعديل الاشتراك».
 *
 *  • لمتجرٍ يُسأل عنها (سياسةُ الإضافات أو حسابٌ بانتظار السداد): زرُّ «منحة إدارية…»،
 *    يفتح حقلَ السبب ويقول إنّ المنحة تتطلب سببًا وتاريخ انتهاء.
 *  • ولا يُرسَل سببٌ لم يُفتح حقلُه، ويُرسَل ما كُتب ليحكم به الخادم (`AdminGrants`).
 *  • والسابقةُ لا زرَّ لها — إلّا ما يقوله الخادمُ خطأً.
 *
 * والخادمُ يحكم بها كلِّها: `AnOldEditScreenOpensNothingWithoutPaymentTest`.
 */

const sent: { url: string; payload: Record<string, unknown> }[] = [];
let serverErrors: Record<string, string> = {};

vi.mock('@inertiajs/react', async () => {
    const actual = await vi.importActual<Record<string, unknown>>('@inertiajs/react');

    const useForm = (initial: Record<string, unknown>) => {
        const [data, setData] = useState(initial);
        const shape = useRef<(d: Record<string, unknown>) => Record<string, unknown>>((d) => d);
        const send = (url: string) => sent.push({ url, payload: shape.current({ ...data }) });

        return {
            data,
            errors: serverErrors,
            processing: false,
            setData: (key: unknown, value?: unknown) =>
                setData((d) => (typeof key === 'string' ? { ...d, [key]: value } : { ...d, ...(key as Record<string, unknown>) })),
            transform: (fn: (d: Record<string, unknown>) => Record<string, unknown>) => {
                shape.current = fn;
            },
            post: send,
            put: send,
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

const { default: BusinessForm } = await import('@/Pages/Platform/Businesses/partials/BusinessForm');
const { default: SubscriptionsIndex } = await import('@/Pages/Platform/Subscriptions/Index');

const PLAN = { label: 'الباقة الأساسية', value: 1, monthly: 9.9, yearly: 99, features: [], max_branches: 1, max_employees: 3, max_products: 100 };

beforeEach(() => {
    sent.length = 0;
    serverErrors = {};
    Object.assign(pageProps, { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 } });
});

function editShop(grantable: boolean) {
    render(
        <BusinessForm
            options={{ types: ['محل ورود'], cities: ['مسقط'], statuses: ['نشط'], plans: [PLAN] }}
            initial={{
                name: 'متجر', type: 'محل ورود', country: 'عُمان', city: 'مسقط', address: '', owner_name: '',
                phone: '', email: '', plan_id: '1', status: 'نشط', starts_at: '2026-11-01', ends_at: '2026-12-01', tier: '', storefront_theme: '',
            } as never}
            logoUrl={null}
            // متجرٌ قائمٌ بحسابه — كما تفتحه شاشةُ التعديل، فلا يُطلب حسابٌ جديد
            businessId={5}
            ownerEmail="owner@shop.om"
            grantable={grantable}
            action="/super-admin/businesses/5"
            method="put"
            submitLabel="حفظ التعديلات"
            cancelHref="/super-admin/businesses"
        />,
    );

    return userEvent.setup();
}

describe('تعديلُ الشركة', () => {
    it('يفتح المنحةَ بزرٍّ صريح ويقول إنّها تتطلب سببًا وتاريخ انتهاء — ثمّ يرسل السبب ليحكم به الخادم', async () => {
        const user = editShop(true);
        expect(screen.queryByTestId('business-grant-reason')).toBeNull();
        expect(screen.getByText('لترقية أو تمديد بلا فاتورة — تتطلب سببًا وتاريخ انتهاء.')).toBeInTheDocument();

        await user.click(screen.getByTestId('business-grant-open'));
        expect(screen.getByTestId('business-grant')).toHaveTextContent('المنحة تتطلب سببًا واضحًا وتاريخ انتهاء لم يمضِ، وتُسجَّل في سجل النشاط.');

        await user.type(screen.getByTestId('business-grant-reason'), 'عرض إطلاق متفق عليه');
        await user.click(screen.getByRole('button', { name: 'حفظ التعديلات' }));
        expect(sent.at(-1)!.payload).toMatchObject({ grant_reason: 'عرض إطلاق متفق عليه', ends_at: '2026-12-01', _method: 'put' });
    });

    it('إلغاءُ المنحة يُغلق الحقل ولا يُرسل سببًا', async () => {
        const user = editShop(true);
        await user.click(screen.getByTestId('business-grant-open'));
        await user.type(screen.getByTestId('business-grant-reason'), 'سبب سيُلغى');
        await user.click(screen.getByTestId('business-grant-cancel'));

        expect(screen.queryByTestId('business-grant-reason')).toBeNull();
        await user.click(screen.getByRole('button', { name: 'حفظ التعديلات' }));
        expect(sent.at(-1)!.payload).not.toHaveProperty('grant_reason');
    });

    it('لا زرَّ لمتجر السياسة السابقة — ويُحفظ كما كان بلا سبب', async () => {
        const user = editShop(false);
        expect(screen.queryByTestId('business-grant-open')).toBeNull();
        expect(screen.queryByTestId('business-grant')).toBeNull();

        await user.click(screen.getByRole('button', { name: 'حفظ التعديلات' }));
        expect(sent.at(-1)!.payload).not.toHaveProperty('grant_reason');
    });

    it('وما يردّه الخادم يُظهر الحقلَ برسالته', () => {
        serverErrors = { grant_reason: 'هذا التعديل يفتح بلا سداد: باقة «الاحترافية».' };
        editShop(true);
        expect(screen.getByTestId('business-grant')).toHaveTextContent('هذا التعديل يفتح بلا سداد');
    });
});

describe('تعديلُ الاشتراك', () => {
    const row = (grantable: boolean) => ({
        id: 9, business_id: 4, plan_id: 1, business: 'متجر', plan: 'الباقة الأساسية',
        start: '2026-11-01', end: '2026-12-01', amount: 9.9, payment: 'مدفوع', status: 'نشط', grantable,
    });

    const open = async (grantable: boolean) => {
        Object.assign(pageProps, { stats: [], planNames: ['الباقة الأساسية'], planOptions: [PLAN], subscriptions: [row(grantable)] });
        render(<SubscriptionsIndex />);
        const user = userEvent.setup();
        await user.click(screen.getByRole('button', { name: 'إجراءات' }));
        await user.click(await screen.findByRole('menuitem', { name: 'تعديل' }));

        return { user, dialog: await screen.findByRole('dialog') };
    };

    it('يفتح المنحةَ بزرٍّ صريح ويرسل سببها مع الدورة', async () => {
        const { user, dialog } = await open(true);
        await user.click(within(dialog).getByTestId('subscription-grant-open'));
        expect(within(dialog).getByTestId('subscription-grant')).toHaveTextContent('تتطلب سببًا واضحًا وتاريخ انتهاء');

        await user.type(within(dialog).getByTestId('subscription-grant-reason'), 'تعويض عن انقطاع الخدمة');
        await user.click(within(dialog).getByRole('button', { name: 'حفظ' }));
        expect(sent.at(-1)!).toMatchObject({ url: '/super-admin.subscriptions.update/9', payload: { grant_reason: 'تعويض عن انقطاع الخدمة', ends_at: '2026-12-01' } });
    });

    it('لا زرَّ لاشتراك متجرٍ على السياسة السابقة', async () => {
        const { user, dialog } = await open(false);
        expect(within(dialog).queryByTestId('subscription-grant-open')).toBeNull();

        await user.click(within(dialog).getByRole('button', { name: 'حفظ' }));
        expect(sent.at(-1)!.payload).toMatchObject({ grant_reason: '' });
    });
});
