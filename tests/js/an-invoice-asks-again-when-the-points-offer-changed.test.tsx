import { useRef, useState } from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { pageProps } from './setup';

/**
 * فاتورةُ العميل تُمسك ما وافق عليه التاجر — وعرضٌ تغيّر يُسأل عنه من جديد.
 *
 * ═══ ما يُحرس ═══
 *
 *   - «استخدام النقاط» يُرسل النقاطَ وقيمتَها كما عُرضت (`redeem_discount`) —
 *     والخادمُ يقارنهما بما يحسبه (`CustomerInvoices::create`).
 *   - معاينةٌ لاحقةٌ بعرضٍ غيرِه (بندٌ تغيّر، رصيدٌ صُرف في الصندوق) تُعيد
 *     الحسابَ وحدها ويبقى الاختيار — ويُقال ذلك، ويُرسل العرضُ الجديد كما يُرى.
 *     (كان يُلغي الاختيار؛ وطلبُ المالك 2026-11-11: «يعاد الحساب تلقائيًا».)
 *   - وعرضٌ لم يعد مؤهَّلًا يُسقط الاختيارَ ويقول ذلك — ولا خصمَ يُرسل.
 *   - وردُّ الخادم على حقل النقاط يُلغي الاختيارَ ويجلب العرضَ الآن.
 *
 * والخادمُ يحرس ما يُكتب (`LoyaltyRedeemsAlikeOnTheTillAndTheInvoiceTest`).
 */

const sent: { url: string; payload: Record<string, unknown>; options: Record<string, unknown> }[] = [];

vi.mock('@inertiajs/react', async () => {
    const actual = await vi.importActual<Record<string, unknown>>('@inertiajs/react');

    const useForm = (initial: Record<string, unknown>) => {
        const [data, setData] = useState(initial);
        const shape = useRef<(d: Record<string, unknown>) => Record<string, unknown>>((d) => d);

        return {
            data,
            errors: {} as Record<string, string>,
            processing: false,
            setData: (key: unknown, value?: unknown) =>
                setData((d) => (typeof key === 'string' ? { ...d, [key]: value } : { ...d, ...(key as Record<string, unknown>) })),
            transform: (fn: (d: Record<string, unknown>) => Record<string, unknown>) => {
                shape.current = fn;
            },
            post: (url: string, options: Record<string, unknown> = {}) => {
                sent.push({ url, payload: shape.current({ ...data }), options });
            },
            reset: () => setData(initial),
            clearErrors: () => {},
            setError: () => {},
        };
    };

    return {
        ...actual,
        usePage: () => ({ props: pageProps, url: '/', component: 'Test' }),
        router: { visit: vi.fn(), get: vi.fn(), post: vi.fn(), reload: vi.fn(), on: vi.fn() },
        Head: () => null,
        useForm,
    };
});

vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));
vi.mock('@/Components/PaperFrame', () => ({ default: () => null }));

const Create = (await import('@/Pages/Admin/CustomerInvoices/Create')).default;

const customer = {
    id: 7, name: 'مريم', name_en: null, customer_type: null, legal_name: null, tax_number: null,
    commercial_registration: null, phone: '99887766', contact_phone: null, email: null, contact_email: null,
    address: null, billing_address: null,
};

const props = {
    customers: [customer],
    products: [{ id: 1, name: 'بوكيه ورد', sku: 'FLW-1', barcode: null, price: 20 }],
    catalog_truncated: false,
    addons: [],
    addons_truncated: false,
    tax_rate: 5,
    tax_inclusive: false,
    today: '2026-11-11',
    may: { issue: true, cancel: true, credit_note: true, pay: false, brand: false, payment_link: false },
    methods: ['آجل', 'نقدي'],
    bank_methods: [],
    bank_accounts: [],
    new_customer_id: null,
    default_customer_id: 7,
    branding: { display_name: '', language: 'ar', header: '', footer: '', font: 'md', logo: null, default_customer_id: 7, legal_name: 'ورود' },
    payment_links_ready: false,
    form_token: 'tok-form-1',
};

let offer = { eligible: true, reason: null as string | null, balance: 1000, points: 1000, discount: 10 };
let previews = 0;

beforeEach(() => {
    sent.length = 0;
    previews = 0;
    offer = { eligible: true, reason: null, balance: 1000, points: 1000, discount: 10 };
    for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    Object.assign(pageProps, {
        translations: {}, context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 } },
        auth: { abilities: [], mayActions: [] }, flash: { toast: null, status: null }, errors: {},
    });
    globalThis.fetch = vi.fn(async () => {
        previews++;
        return new Response(JSON.stringify({ html: '', loyalty: offer, totals: null }));
    }) as never;
});

const open = async () => {
    render(<Create {...(props as unknown as React.ComponentProps<typeof Create>)} />);
    const user = userEvent.setup();
    await screen.findByTestId('invoice-loyalty', {}, { timeout: 2000 });

    return user;
};

const issue = async (user: ReturnType<typeof userEvent.setup>) => {
    await user.click(screen.getByRole('button', { name: /^إصدار الفاتورة$/ }));

    return sent[sent.length - 1];
};

describe('فاتورةُ العميل — ما وافق عليه التاجرُ يُرسل كما رآه', () => {
    it('«استخدام النقاط» يُرسل النقاطَ وقيمتَها المعروضة', async () => {
        const user = await open();
        await user.click(screen.getByRole('button', { name: 'استخدام النقاط' }));

        const post = await issue(user);
        expect(post.payload.redeem_points).toBe(1000);
        expect(post.payload.redeem_discount).toBe(10);
    });

    it('وبلا جوابٍ لا نقاطَ ولا قيمة', async () => {
        const user = await open();

        const post = await issue(user);
        expect(post.payload.redeem_points).toBe(0);
        expect(post.payload.redeem_discount).toBeNull();
    });

    it('عرضٌ تغيّر بعد «استخدام النقاط» يُعاد حسابُه ويبقى الاختيار — ويُرسل الجديدُ كما يُرى', async () => {
        const user = await open();
        // صُرف الرصيدُ في الصندوق بين العرضين: المعاينةُ التالية ترى ثلاثمئة
        offer = { ...offer, balance: 300, points: 300, discount: 3 };
        await user.click(screen.getByRole('button', { name: 'استخدام النقاط' }));

        await waitFor(() => expect(screen.getByTestId('invoice-loyalty-recalculated')).toHaveTextContent('أُعيد احتساب خصم النقاط'), { timeout: 2000 });
        expect(screen.getByTestId('invoice-loyalty')).toHaveTextContent('300');
        expect(screen.getByTestId('invoice-loyalty-choice')).toHaveTextContent('يُخصم من رصيده');

        const after = await issue(user);
        expect(after.payload.redeem_points).toBe(300);
        expect(after.payload.redeem_discount).toBe(3);
    });

    it('وعرضٌ لم يعد مؤهَّلًا يُسقط الاختيارَ ويقول سببه — ولا خصمَ يُرسل', async () => {
        const user = await open();
        await user.click(screen.getByRole('button', { name: 'استخدام النقاط' }));
        await waitFor(() => expect(previews).toBeGreaterThanOrEqual(2), { timeout: 2000 });

        // تغيّر شيءٌ فصارت الفاتورةُ دون الحدّ الأدنى — والمعاينةُ التالية تقول ذلك
        offer = { ...offer, eligible: false, reason: 'below_min_invoice', points: 0, discount: 0 };
        fireEvent.change(document.getElementById('issued-at') as HTMLInputElement, { target: { value: '2026-11-12' } });

        await waitFor(() => expect(screen.getByTestId('invoice-loyalty-changed')).toHaveTextContent('تغيّر ما يُخصم بالنقاط'), { timeout: 2000 });
        expect(screen.getByTestId('invoice-loyalty-reason')).toHaveTextContent('دون الحدّ الأدنى');

        const post = await issue(user);
        expect(post.payload.redeem_points).toBe(0);
        expect(post.payload.redeem_discount).toBeNull();
    });

    it('وردُّ الخادم على حقل النقاط يُلغي الاختيارَ ويجلب العرضَ الآن', async () => {
        const user = await open();
        await user.click(screen.getByRole('button', { name: 'استخدام النقاط' }));
        await waitFor(() => expect(previews).toBeGreaterThanOrEqual(2), { timeout: 2000 });

        const post = await issue(user);
        const seen = previews;
        offer = { ...offer, balance: 500, points: 500, discount: 5 };
        const onError = post.options.onError as (e: Record<string, string>) => void;
        onError({ redeem_points: 'تغيّر رصيد نقاط العميل أو قواعد الاستبدال — الرصيد الآن 500 نقطة' });

        await waitFor(() => expect(screen.getByRole('button', { name: 'استخدام النقاط' })).toBeInTheDocument());
        await waitFor(() => expect(previews).toBeGreaterThan(seen));
        await waitFor(() => expect(screen.getByTestId('invoice-loyalty')).toHaveTextContent('500'));

        const again = await issue(user);
        expect(again.payload.redeem_points).toBe(0);
    });
});
