import { useRef, useState } from 'react';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { pageProps } from './setup';

/**
 * صنفُ بوتيكٍ في فاتورة عميل لا يدخل حسابَه — يُقال لكاتبها ولا يُمنع.
 *
 * تنبيهٌ تحت البند وحده: لا يغيّر سعرًا ولا يردّ حفظًا. وصنفُ المتجر بلا تنبيه.
 */

const sent: { url: string; payload: Record<string, unknown> }[] = [];

vi.mock('@inertiajs/react', async () => {
    const actual = await vi.importActual<Record<string, unknown>>('@inertiajs/react');

    /* بديلٌ أصغرُ ما يصدق: يمسك ما يُحوَّل وما يُرسَل، ولا يفتح شبكة */
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
                setData((d) =>
                    typeof key === 'string' ? { ...d, [key]: value } : { ...d, ...(key as Record<string, unknown>) },
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
        router: { visit: vi.fn(), get: vi.fn(), post: vi.fn(), reload: vi.fn(), on: vi.fn() },
        Head: () => null,
        useForm,
    };
});

vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

// ‏والورقةُ تُرسم في الخادم داخل إطار — ليست ممّا يُختبر هنا
vi.mock('@/Components/PaperFrame', () => ({ default: () => null }));

const Create = (await import('@/Pages/Admin/CustomerInvoices/Create')).default;

const ADDONS = [
    { id: 11, name: 'شوكولاتة', name_en: 'Chocolate', price: 3 },
    { id: 12, name: 'بطاقة إهداء', name_en: null, price: 1 },
    { id: 13, name: 'شريطة ذهبية', name_en: 'Gold ribbon', price: 0.5 },
];

const props = {
    customers: [],
    products: [
        { id: 1, name: 'بوكيه ورد', sku: 'FLW-1', barcode: null, price: 20, boutique: null },
        { id: 2, name: 'قطعة فضّة', sku: 'SLV-1', barcode: null, price: 12, boutique: 'بوتيك لمى' },
    ],
    catalog_truncated: false,
    addons: ADDONS,
    addons_truncated: false,
    tax_rate: 5,
    tax_inclusive: false,
    today: '2026-10-13',
    may: { issue: true, cancel: true, credit_note: true, pay: true, brand: false },
    methods: ['آجل', 'نقدي'],
    bank_methods: [],
    bank_accounts: [],
    new_customer_id: null,
    default_customer_id: null,
    branding: {
        display_name: '', language: 'ar', header: '', footer: '', font: 'md',
        logo: null, default_customer_id: null, legal_name: 'ورود',
    },
};

beforeAll(() => {
    // ‏والمعاينةُ تطلب ورقتَها من الخادم — يُردّ بورقةٍ فارغة
    globalThis.fetch = vi.fn(async () => new Response(JSON.stringify({ html: '' }))) as never;
});

beforeEach(() => {
    sent.length = 0;
});

function page() {
    render(<Create {...(props as unknown as React.ComponentProps<typeof Create>)} />);

    return userEvent.setup();
}

async function pick(user: ReturnType<typeof userEvent.setup>, name: string) {
    await user.click(screen.getByRole('button', { name: 'منتج من الكتالوج' }));
    const dialog = await screen.findByRole('dialog');
    await user.click(within(dialog).getAllByRole('button').find((b) => b.textContent?.includes(name))!);
    const done = within(dialog).queryByRole('button', { name: 'تمّ' });
    if (done) await user.click(done);
}

describe('صنفُ بوتيكٍ في فاتورة عميل', () => {
    it('يُنبَّه تحت بنده باسم بوتيكه وبما يُفعل', async () => {
        const user = page();
        await pick(user, 'قطعة فضّة');
        const note = await screen.findByTestId('line-boutique-note');
        expect(note).toHaveTextContent('بوتيك لمى');
        expect(note).toHaveTextContent('بيعًا آجلًا من نقطة البيع');
    });

    it('ولا يُنبَّه عن صنف المتجر', async () => {
        const user = page();
        await pick(user, 'بوكيه ورد');
        expect(screen.queryByTestId('line-boutique-note')).toBeNull();
    });

    it('ولا يمنع الحفظ ولا يغيّر السعر', async () => {
        const user = page();
        await pick(user, 'قطعة فضّة');
        await user.click(screen.getByRole('button', { name: /حفظ كمسودة|حفظ مسودة|حفظ/ }));
        const items = sent.at(-1)?.payload.items as Record<string, string>[];
        expect(items[0]).toMatchObject({ product_id: '2', unit_price: '12' });
        expect(Object.keys(items[0])).not.toContain('boutique');
    });
});
