import { useRef, useState } from 'react';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { pageProps } from './setup';

/**
 * فاتورةُ العميل تُوجد برقم هاتفه — في منتقي الفاتورة الجديدة وفي جدول الفواتير.
 *
 *   - المنتقي يبحث بالاسم العربيّ والإنجليزيّ وبالرقم بأيّ شكل، ويعرض الرقمَ تحت
 *     الاسم؛ والاختيارُ يملأ البطاقة كما كان، ولا يُنشئ البحثُ عميلًا.
 *   - والجدولُ يعرض الرقمَ تحت الاسم، ونصُّه الإرشاديّ يقول ما يُبحث به.
 *
 * والخادمُ في `AnInvoiceIsFoundByItsCustomersPhoneTest`.
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
                setData((d) => (typeof key === 'function'
                    ? (key as (d: Record<string, unknown>) => Record<string, unknown>)(d)
                    : typeof key === 'string' ? { ...d, [key]: value } : { ...d, ...(key as Record<string, unknown>) })),
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
const Index = (await import('@/Pages/Admin/CustomerInvoices/Index')).default;
const { customerMatches, phoneNeedle } = await import('@/Pages/Admin/CustomerInvoices/Create');

const customer = (over: Record<string, unknown>) => ({
    id: 1, name: '', name_en: null, customer_type: 'فرد', legal_name: null, tax_number: null, commercial_registration: null,
    phone: null, contact_phone: null, email: null, contact_email: null, address: null, billing_address: null, department: null,
    customer_reference: null, contact_person: null, payment_terms_days: null, allow_credit_sales: true, phone_keys: [], ...over,
});

const customers = [
    customer({ id: 1, name: 'وزارة الثقافة', name_en: 'Ministry of Culture', phone: '+968 9123 4567', phone_keys: ['96891234567'] }),
    customer({ id: 2, name: 'وزارة الثقافة', phone: '93330000', phone_keys: ['96893330000', '93330000'] }),
    customer({ id: 3, name: 'شركة النور', customer_type: 'شركة', phone: '92223333', contact_phone: '968-2444-5555', phone_keys: ['96892223333', '92223333', '96824445555'] }),
];

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

const results = () => screen.queryAllByRole('button').filter((b) => b.closest('.absolute.inset-x-0'));

describe('منتقي العميل في الفاتورة الجديدة', () => {
    const open = async (typed: string) => {
        render(<Create {...(createProps({ customers }) as unknown as React.ComponentProps<typeof Create>)} />);
        const user = userEvent.setup();
        const box = screen.getByPlaceholderText('ابحث باسم العميل أو رقم هاتفه، أو اختر من القائمة');
        await user.click(box);
        if (typed !== '') await user.type(box, typed);

        return { user, box };
    };

    it('يبحث بالاسم العربيّ — ويفرّق بين المتشابهين برقمهما', async () => {
        await open('وزارة');

        const shown = results();
        expect(shown).toHaveLength(2);
        expect(shown.map((b) => within(b).getByTestId('picker-phone').textContent)).toEqual(['+968 9123 4567', '93330000']);
    });

    it('وبالاسم الإنجليزيّ', async () => {
        await open('ministry');
        expect(results()).toHaveLength(1);
    });

    it.each(['91234567', '+968 9123 4567', '00968-91234567', '4567', '٩١٢٣'])('وبالرقم «%s»', async (typed) => {
        await open(typed);

        const shown = results();
        expect(shown).toHaveLength(1);
        expect(shown[0].textContent).toContain('+968 9123 4567');
    });

    it('وبرقم التواصل', async () => {
        await open('2444 5555');
        expect(results()).toHaveLength(1);
        expect(results()[0].textContent).toContain('شركة النور');
    });

    it('والاختيارُ يملأ البطاقة كما كان — ولا يُرسَل شيءٌ ولا يُنشأ عميل', async () => {
        const { user } = await open('9123');
        await user.click(results()[0]);

        expect(screen.queryByPlaceholderText('ابحث باسم العميل أو رقم هاتفه، أو اختر من القائمة')).toBeNull();
        expect(screen.getAllByText('وزارة الثقافة').length).toBeGreaterThan(0);
        expect(sent).toHaveLength(0);
        expect(posts).toHaveLength(0);
    });

    it('وما لا يطابق يُقال', async () => {
        await open('99998888');
        expect(results()).toHaveLength(0);
        expect(screen.getByText('لا عميل بهذا الاسم أو الرقم')).toBeInTheDocument();
    });

    it('ورقمُ فاتورةٍ ليس هاتفًا', () => {
        expect(phoneNeedle('CINV-000123')).toBeNull();
        expect(phoneNeedle('12')).toBeNull();
        expect(phoneNeedle('+968 9123-4567')).toBe('96891234567');
        expect(customerMatches(customers[0], 'CINV-0001')).toBe(false);
    });
});

describe('جدولُ فواتير العملاء', () => {
    it('يعرض رقمَ العميل تحت اسمه، ويقول بمَ يُبحث', () => {
        const props = {
            invoices: [{
                id: 9, number: 'CINV-000009', customer: 'وزارة الثقافة', customer_phone: '+968 9123 4567', customer_id: 1,
                status: 'صادرة', state: 'غير مدفوعة', issued_at: '2027-03-01', due_at: null, total: 100, paid: 0, outstanding: 100,
                days_overdue: 0, po_number: null,
            }],
            pagination: { current_page: 1, last_page: 1, per_page: 20, total: 1, from: 1, to: 1 },
            filters: {}, states: ['غير مدفوعة'], statuses: ['صادرة'], customers: [],
            totals: { total: 100, overdue: 0, due_soon: 0, credit: 0, invoices: 1, customers: 1 },
        };
        render(<Index {...(props as unknown as React.ComponentProps<typeof Index>)} />);

        expect(screen.getByTestId('invoice-customer-phone').textContent).toBe('+968 9123 4567');
        expect(screen.getByPlaceholderText('ابحث برقم الفاتورة أو اسم العميل أو رقم هاتفه أو أمر الشراء')).toBeInTheDocument();
    });
});
