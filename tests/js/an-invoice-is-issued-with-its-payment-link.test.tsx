import { useRef, useState } from 'react';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { pageProps } from './setup';

/**
 * «إصدار الفاتورة وإنشاء رابط دفع» — من جهة الشاشة.
 *
 * ═══ ما يُحرس ═══
 *
 *   - الزرُّ لمن يملك الإصدارَ وطلبَ الدفع معًا ومتجرُه تُفتح له الروابط، ويرسل
 *     الفاتورةَ آجلةً مع رمز نموذجها.
 *   - مع «نقدي» لا يُطلب رابط: الزرُّ معطَّلٌ والسببُ مكتوبٌ بجانبه، والإصدارُ باقٍ.
 *   - الرابطُ القائمُ بارزًا: رقمُ الفاتورة والمبلغُ بعملته والرابطُ وحالُه ونهايتُه،
 *     ونسخُه وإرسالُه بواتساب وفتحُه — ورسالةُ واتساب بلا اسم العميل.
 *   - تعذّر الرابط: السببُ وإعادةُ المحاولة على الفاتورة نفسِها؛ ومحاولةٌ لا تُعرف
 *     نتيجتُها لا تُعاد إلّا بقرارٍ صريح.
 *   - البطاقةُ تطلب من باب صفحتها لا من «المالية»، والإلغاءُ لمن يملكه وحده.
 *
 * والخادمُ يحرس ما يقع (`AnInvoiceIsIssuedAndAskedForPaymentInOneStepTest`).
 */

const sent: { url: string; payload: Record<string, unknown> }[] = [];
const posts: { url: string; data: unknown }[] = [];

vi.mock('@inertiajs/react', async () => {
    const actual = await vi.importActual<Record<string, unknown>>('@inertiajs/react');

    const useForm = (initial: Record<string, unknown>) => {
        const [data, setData] = useState(initial);
        const shape = useRef<(d: Record<string, unknown>) => Record<string, unknown>>((d) => d);

        const send = (url: string) => {
            sent.push({ url, payload: shape.current({ ...data }) });
        };

        return {
            data,
            errors: {} as Record<string, string>,
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
        };
    };

    return {
        ...actual,
        usePage: () => ({ props: pageProps, url: '/', component: 'Test' }),
        router: {
            visit: vi.fn(),
            get: vi.fn(),
            post: vi.fn((url: string, data: unknown) => posts.push({ url, data })),
            reload: vi.fn(),
            on: vi.fn(),
        },
        Head: () => null,
        useForm,
    };
});

vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));
vi.mock('@/Components/PaperFrame', () => ({ default: () => null }));

const Create = (await import('@/Pages/Admin/CustomerInvoices/Create')).default;
const Show = (await import('@/Pages/Admin/CustomerInvoices/Show')).default;
const { default: PaymentLinksCard, InvoicePaymentLink, PaymentLinkRetry, invoiceLinkView } = await import('@/Components/PaymentLinks');

type Row = import('@/Components/PaymentLinks').PaymentLinkRow;

const row = (over: Partial<Row> = {}): Row => ({
    id: 5, reference: 'pay-abc', customer: { id: 7, name: 'وزارة الثقافة' }, invoice: { id: 3, number: 'CINV-000003' },
    description: null, amount: 105, currency: 'OMR', status: 'pending', is_live: true, url: 'https://paymob.link/abc123',
    created_at: '2026-10-19 10:00', expires_at: '2026-10-26 23:59', paid_at: null, payment: null, error: null, review: false,
    ...over,
});

const createProps = (over: Record<string, unknown> = {}) => ({
    customers: [],
    products: [{ id: 1, name: 'بوكيه ورد', sku: 'FLW-1', barcode: null, price: 20 }],
    catalog_truncated: false,
    addons: [],
    addons_truncated: false,
    tax_rate: 5,
    tax_inclusive: false,
    today: '2026-10-19',
    may: { issue: true, cancel: true, credit_note: true, pay: false, brand: false, payment_link: true },
    methods: ['آجل', 'نقدي'],
    bank_methods: [],
    bank_accounts: [],
    new_customer_id: null,
    default_customer_id: null,
    branding: { display_name: '', language: 'ar', header: '', footer: '', font: 'md', logo: null, default_customer_id: null, legal_name: 'ورود' },
    payment_links_ready: true,
    form_token: 'tok-form-1',
    ...over,
});

const reset = () => {
    for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    Object.assign(pageProps, {
        translations: {}, context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 } },
        auth: { abilities: [], mayActions: [] }, flash: { toast: null, status: null }, errors: {},
    });
};

beforeAll(() => {
    globalThis.fetch = vi.fn(async () => new Response(JSON.stringify({ html: '' }))) as never;
});

beforeEach(() => {
    sent.length = 0;
    posts.length = 0;
    reset();
});

const createPage = (over: Record<string, unknown> = {}) => {
    render(<Create {...(createProps(over) as unknown as React.ComponentProps<typeof Create>)} />);

    return userEvent.setup();
};

describe('«إصدار الفاتورة وإنشاء رابط دفع» في شاشة الإنشاء', () => {
    it('لمن يملك الإصدارَ وطلبَ الدفع ومتجرُه تُفتح له الروابط — وإلّا لا زرّ', () => {
        const { unmount } = render(<Create {...(createProps() as unknown as React.ComponentProps<typeof Create>)} />);
        expect(screen.getByTestId('issue-with-payment-link')).toHaveTextContent('إصدار الفاتورة وإنشاء رابط دفع');
        expect(screen.getByRole('button', { name: /^إصدار الفاتورة$/ })).toBeInTheDocument();
        unmount();

        for (const over of [
            { may: { issue: true, cancel: false, credit_note: false, pay: false, brand: false, payment_link: false } },
            { may: { issue: false, cancel: false, credit_note: false, pay: false, brand: false, payment_link: true } },
            { payment_links_ready: false },
        ]) {
            const r = render(<Create {...(createProps(over) as unknown as React.ComponentProps<typeof Create>)} />);
            expect(screen.queryByTestId('issue-with-payment-link')).toBeNull();
            r.unmount();
        }
    });

    it('يُصدرها آجلةً ويطلب الرابط معها — برمز نموذجها', async () => {
        const user = createPage();

        // و«آجل» لا يحتاج شرحًا: الزرُّ مفتوح ولا تنبيهَ بجانبه
        expect(screen.queryByTestId('payment-link-needs-credit')).toBeNull();
        await user.click(screen.getByTestId('issue-with-payment-link'));

        expect(sent).toHaveLength(1);
        expect(sent[0].url).toBe('/admin/customer-invoices');
        expect(sent[0].payload).toMatchObject({ issue: true, payment_link: true, payment_method: 'آجل', form_token: 'tok-form-1' });
    });

    it('والإصدارُ العاديّ لا يطلب رابطًا', async () => {
        const user = createPage();

        await user.click(screen.getByRole('button', { name: /^إصدار الفاتورة$/ }));

        expect(sent[0].payload).toMatchObject({ issue: true, payment_link: false });
    });

    it('ومع «نقدي» لا يُطلب رابط: الزرُّ معطَّلٌ والسببُ بجانبه — والإصدارُ باقٍ', async () => {
        // و«مدفوع نقدًا» لمن يسجّل التحصيل وحده (`CustomerInvoiceController::store`)
        const user = createPage({ may: { issue: true, cancel: true, credit_note: true, pay: true, brand: false, payment_link: true } });

        await user.click(screen.getByRole('radio', { name: 'مدفوع نقدًا' }));

        expect(screen.getByTestId('issue-with-payment-link')).toBeDisabled();
        expect(screen.getByTestId('payment-link-needs-credit')).toHaveTextContent('اختر «آجل» لإنشاء الرابط');
        expect(screen.getByRole('button', { name: /^إصدار الفاتورة$/ })).toBeEnabled();
    });
});

describe('ما تعرضه صفحةُ الفاتورة من رابطها', () => {
    const issued = { id: 3, status: 'صادرة', outstanding: 105 };
    const failure = { error: 'رفضت Paymob الطلب', at: null, uncertain: false, until: null };

    it('ما أُنشئ للتوّ لهذه الفاتورة أوّلًا — و«تم الإصدار» بعد الإصدار مع الرابط وحده', () => {
        const fresh = row({ id: 9, url: 'https://paymob.link/fresh' });
        const older = row({ id: 5 });

        expect(invoiceLinkView(issued, [older], { paymentLink: fresh, paymentLinkIssued: true }, null)).toEqual({ current: fresh, issued: true, retry: null });
        expect(invoiceLinkView(issued, [older], { paymentLink: fresh }, null)).toMatchObject({ current: fresh, issued: false });

        // ورابطُ فاتورةٍ أخرى في رسالة الجلسة لا يُعرض هنا — القائمُ لهذه
        const elsewhere = row({ id: 11, invoice: { id: 4, number: 'CINV-000004' } });
        expect(invoiceLinkView(issued, [older], { paymentLink: elsewhere, paymentLinkIssued: true }, null)).toEqual({ current: older, issued: false, retry: null });
    });

    it('بعد التحديث: القائمُ بانتظار الدفع وحده — لا المدفوعُ ولا الملغى ولا ما لا رابطَ له', () => {
        const paid = row({ id: 1, status: 'paid' });
        const cancelled = row({ id: 2, status: 'cancelled' });
        const noUrl = row({ id: 3, url: null });
        const pending = row({ id: 4 });

        expect(invoiceLinkView(issued, [paid, cancelled, noUrl, pending], undefined, null).current).toBe(pending);
        expect(invoiceLinkView(issued, [paid, cancelled, noUrl], undefined, null).current).toBeNull();
    });

    it('وإعادةُ المحاولة: لا رابطَ قائم، والفاتورةُ صادرةٌ وعليها ما يُطلب', () => {
        expect(invoiceLinkView(issued, [], undefined, failure).retry).toBe(failure);
        expect(invoiceLinkView(issued, [row()], undefined, failure).retry, 'رابطٌ قائم: لا يُطلب ثانٍ').toBeNull();
        expect(invoiceLinkView({ ...issued, outstanding: 0 }, [], undefined, failure).retry, 'سُدّدت').toBeNull();
        expect(invoiceLinkView({ ...issued, status: 'ملغاة' }, [], undefined, failure).retry).toBeNull();
        expect(invoiceLinkView(issued, [], undefined, null).retry).toBeNull();
    });
});

describe('صفحةُ الفاتورة نفسُها', () => {
    const invoice = {
        id: 3, number: 'CINV-000003', customer: 'وزارة الثقافة', customer_id: 7, status: 'صادرة', state: 'صادرة',
        issued_at: '2026-10-19', due_at: null, total: 105, paid: 0, outstanding: 105, days_overdue: 0,
        po_number: null, contract_number: null, external_reference: null, department: null, cost_center: null, attention_to: null,
        subtotal: 100, discount_total: 0, tax_total: 5, notes: null, internal_notes: null, may_read_attachments: false,
        attachments: [], orders: [], cancellation_reason: null,
        items: [{ description: 'باقة', quantity: 2, unit_price: 50, discount: 0, tax_rate: 5, line_total: 105 }],
        payments: [], credit_notes: [],
    };

    const showPage = (over: Record<string, unknown> = {}) => {
        Object.assign(pageProps, {
            invoice, may: { issue: true, cancel: true, credit_note: true, pay: false, payment_link: true },
            bank_accounts: [], methods: ['نقدي'], bank_methods: [], paper: { html: '', size: 'A4' },
            paymentLinks: { ready: true, can: true, can_cancel: false, settings_url: null, links: [] as Row[] },
            paymentLinkFailure: null, preparation: null,
            ...over,
        });
        render(<Show />);
    };

    it('بعد «إصدار الفاتورة وإنشاء رابط دفع»: الرابطُ بارزًا بعنوانه', () => {
        showPage({ flash: { toast: null, status: null, paymentLink: row(), paymentLinkIssued: true } });

        expect(screen.getByTestId('invoice-payment-link')).toHaveTextContent('تم إصدار الفاتورة وإنشاء رابط الدفع');
        expect(screen.getByTestId('invoice-payment-link-url')).toHaveValue('https://paymob.link/abc123');
        expect(screen.queryByTestId('payment-link-failure')).toBeNull();
    });

    it('وبعد التحديث: القائمُ من الخادم، بلا عنوان الإصدار', () => {
        showPage({ paymentLinks: { ready: true, can: true, can_cancel: false, settings_url: null, links: [row()] } });
        expect(screen.getByTestId('invoice-payment-link')).not.toHaveTextContent('تم إصدار الفاتورة');
        expect(screen.getByTestId('invoice-payment-link-url')).toHaveValue('https://paymob.link/abc123');
    });

    it('وتعذّر الرابط: السببُ وإعادةُ المحاولة على الفاتورة نفسِها', () => {
        showPage({ paymentLinkFailure: { error: 'رفضت Paymob الطلب', at: null, uncertain: false, until: null } });

        expect(screen.queryByTestId('invoice-payment-link')).toBeNull();
        expect(screen.getByTestId('payment-link-failure')).toHaveTextContent('رفضت Paymob الطلب');
        expect(screen.getByTestId('payment-link-retry')).toBeInTheDocument();
    });
});

describe('الرابطُ القائم بارزًا في صفحة الفاتورة', () => {
    it('رقمُ الفاتورة والمبلغُ بعملته والرابطُ وحالُه ونهايتُه — بعد الإصدار مباشرة', () => {
        render(<InvoicePaymentLink link={row()} issued />);

        const box = screen.getByTestId('invoice-payment-link');
        expect(box).toHaveTextContent('تم إصدار الفاتورة وإنشاء رابط الدفع');
        expect(screen.getByTestId('invoice-payment-link-number')).toHaveTextContent('CINV-000003');
        expect(screen.getByTestId('invoice-payment-link-amount')).toHaveTextContent('105.000');
        expect(screen.getByTestId('invoice-payment-link-amount')).toHaveTextContent('OMR');
        expect(screen.getByTestId('invoice-payment-link-url')).toHaveValue('https://paymob.link/abc123');
        expect(within(box).getByTestId('payment-link-status')).toHaveTextContent('بانتظار الدفع');
        expect(screen.getByTestId('invoice-payment-link-expires')).toHaveTextContent('2026-10-26 23:59');
        expect(screen.getByRole('button', { name: /نسخ رابط الدفع/ })).toBeInTheDocument();
        expect(screen.getByTestId('invoice-payment-link-open')).toHaveAttribute('href', 'https://paymob.link/abc123');
        expect(screen.getByTestId('invoice-payment-link-open')).toHaveTextContent('فتح رابط الدفع');
    });

    it('وواتساب: رقمُ الفاتورة والمبلغُ والرابطُ مرمَّزةً — بلا اسم العميل ولا رقمٍ مسبق', () => {
        render(<InvoicePaymentLink link={row()} issued={false} />);

        const href = screen.getByTestId('invoice-payment-link-whatsapp').getAttribute('href') ?? '';
        expect(screen.getByTestId('invoice-payment-link-whatsapp')).toHaveTextContent('إرسال عبر واتساب');
        expect(href.startsWith('https://wa.me/?text=')).toBe(true);
        const text = decodeURIComponent(href.split('text=')[1]);
        expect(text).toContain('CINV-000003');
        expect(text).toContain('105.000');
        expect(text).toContain('https://paymob.link/abc123');
        expect(text).not.toContain('وزارة الثقافة');
        expect(href).not.toMatch(/[ \n]/);
        expect(screen.getByTestId('invoice-payment-link')).toHaveTextContent('رابط الدفع');
        expect(screen.getByTestId('invoice-payment-link')).not.toHaveTextContent('تم إصدار الفاتورة');
    });

    it('والمدفوعُ بلا مشاركة — والمنتهي بلا رابطٍ لا يُرسم', () => {
        const { unmount } = render(<InvoicePaymentLink link={row({ status: 'paid' })} issued={false} />);
        expect(screen.queryByTestId('invoice-payment-link-whatsapp')).toBeNull();
        unmount();

        render(<InvoicePaymentLink link={row({ status: 'expired', url: null })} issued={false} />);
        expect(screen.queryByTestId('invoice-payment-link')).toBeNull();
    });
});

describe('تعذّر الرابط — السببُ وإعادةُ المحاولة على الفاتورة نفسِها', () => {
    it('فشلٌ مؤكَّد: «إعادة المحاولة» تطلب من باب الفاتورة', async () => {
        render(<PaymentLinkRetry invoiceId={3} failure={{ error: 'رفضت Paymob مفتاح API.', at: '2026-10-19 10:00', uncertain: false, until: null }} can />);

        expect(screen.getByTestId('payment-link-failure')).toHaveTextContent('رفضت Paymob مفتاح API.');
        expect(screen.queryByTestId('payment-link-uncertain')).toBeNull();
        await userEvent.click(screen.getByTestId('payment-link-retry'));

        expect(posts).toEqual([{ url: '/admin.customerInvoices.paymentLink/3', data: {} }]);
    });

    it('ومحاولةٌ لا تُعرف نتيجتُها: يُقال ذلك، ولا تُعاد إلّا بقرارٍ صريح', async () => {
        render(<PaymentLinkRetry invoiceId={3} failure={{ error: 'تعذّر الاتصال بـ Paymob', at: null, uncertain: true, until: '2026-10-26 23:59' }} can />);

        expect(screen.getByTestId('payment-link-uncertain')).toHaveTextContent('فأرسل الجديد وحده للعميل');
        expect(screen.queryByTestId('payment-link-retry')).toBeNull();
        await userEvent.click(screen.getByTestId('payment-link-replace'));

        expect(posts).toEqual([{ url: '/admin.customerInvoices.paymentLink/3', data: { replace_uncertain: true } }]);
    });

    it('ومن لا يملك الطلبَ يقرأ السببَ ولا زرَّ له', () => {
        render(<PaymentLinkRetry invoiceId={3} failure={{ error: 'x', at: null, uncertain: false, until: null }} can={false} />);

        expect(screen.queryByRole('button')).toBeNull();
    });
});

describe('البطاقةُ تطلب من باب صفحتها — لا من «المالية»', () => {
    const panel = (over: Record<string, unknown> = {}) => ({ ready: true, can: true, can_cancel: false, settings_url: null, links: [] as Row[], ...over });

    it('الفاتورةُ ببابها، والعميلُ ببابه', async () => {
        const user = userEvent.setup();
        const { unmount } = render(<PaymentLinksCard panel={panel()} customerId={7} invoiceId={3} outstanding={105} title="روابط الدفع" action="إنشاء رابط دفع" />);
        await user.click(screen.getByRole('button', { name: 'إنشاء رابط دفع' }));
        await user.click(screen.getByTestId('payment-link-submit'));
        expect(sent.at(-1)).toEqual({ url: '/admin.customerInvoices.paymentLink/3', payload: { amount: '105.000', description: '' } });
        unmount();

        render(<PaymentLinksCard panel={panel()} customerId={7} title="روابط الدفع" action="طلب دفعة" />);
        await user.click(screen.getByRole('button', { name: 'طلب دفعة' }));
        await user.type(screen.getByRole('textbox', { name: 'المبلغ' }), '20');
        await user.click(screen.getByTestId('payment-link-submit'));
        expect(sent.at(-1)?.url).toBe('/admin.customers.paymentLink/7');
        expect(sent.map((s) => s.url).join()).not.toContain('finance');
    });

    it('ومن يطلب ولا يملك التحصيل: لا زرَّ إلغاء', () => {
        render(<PaymentLinksCard panel={panel({ links: [row()] })} customerId={7} invoiceId={3} outstanding={105} title="روابط الدفع" action="إنشاء رابط دفع" />);

        expect(screen.getByTestId('payment-link-actions')).toBeInTheDocument();
        expect(screen.queryByTestId('payment-link-cancel')).toBeNull();
    });
});

describe('القبضُ عند الإصدار لمن يسجّل التحصيل', () => {
    it('من لا يسجّل التحصيل لا يُعرض له إلّا «آجل»', () => {
        createPage();

        expect(screen.queryByRole('radio', { name: 'مدفوع نقدًا' })).toBeNull();
        expect(screen.getByRole('radio', { name: /^آجل/ })).toBeInTheDocument();
    });

    it('ومن يسجّله تُعرض له وسائلُ القبض', () => {
        createPage({ may: { issue: true, cancel: false, credit_note: false, pay: true, brand: false, payment_link: false } });

        expect(screen.getByRole('radio', { name: 'مدفوع نقدًا' })).toBeInTheDocument();
    });
});
