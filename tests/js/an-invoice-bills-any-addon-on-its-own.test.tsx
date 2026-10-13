import { useRef, useState } from 'react';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { pageProps } from './setup';

/**
 * فاتورةُ العميل تفوتر أيَّ إضافةٍ — بندًا وحدَها، كما يراها من يكتب.
 *
 * زرٌّ مستقلٌّ بجانب «منتج من الكتالوج»، ونافذةٌ بكلّ إضافات المتجر النشطة،
 * والمختارةُ سطرٌ في جدول البنود اسمُه وثمنُه من الكتالوج. والخادمُ يحرس
 * الثمنَ والهويّة (`ACustomerInvoiceBillsAnyAddonOnItsOwnTest`)، وهذا يحرس
 * أنّ الشاشة تقولها وترسلها.
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
const { AddonDialog } = await import('@/Pages/Admin/CustomerInvoices/Create');

const ADDONS = [
    { id: 11, name: 'شوكولاتة', name_en: 'Chocolate', price: 3 },
    { id: 12, name: 'بطاقة إهداء', name_en: null, price: 1 },
    { id: 13, name: 'شريطة ذهبية', name_en: 'Gold ribbon', price: 0.5 },
];

const props = {
    customers: [],
    products: [{ id: 1, name: 'بوكيه ورد', sku: 'FLW-1', barcode: null, price: 20 }],
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

/** صفوفُ جدول البنود — بلا رأسه */
const bodyRows = () => within(screen.getByRole('table')).getAllByRole('row').slice(1);

/** الإجماليُّ في «ملخص الفاتورة» */
const grandTotal = () => screen.getByText('الإجمالي', { selector: 'span' }).nextElementSibling?.textContent ?? '';

async function pick(user: ReturnType<typeof userEvent.setup>, ...names: string[]) {
    await user.click(screen.getByRole('button', { name: 'إضافة من الإضافات' }));
    const dialog = await screen.findByRole('dialog');

    for (const name of names) {
        await user.type(within(dialog).getByPlaceholderText('اسم الإضافة'), name);
        await user.click(within(dialog).getAllByRole('button').find((b) => b.textContent?.includes(name))!);
    }

    await user.click(within(dialog).getByRole('button', { name: 'تمّ' }));
}

describe('الزرّ بجانب أخويه', () => {
    it('«إضافة من الإضافات» بين «منتج من الكتالوج» و«إضافة بند»', () => {
        page();

        const labels = screen
            .getAllByRole('button')
            .map((b) => b.textContent?.trim())
            .filter((l) => ['منتج من الكتالوج', 'إضافة من الإضافات', 'إضافة بند'].includes(l ?? ''));

        expect(labels.slice(0, 3)).toEqual(['منتج من الكتالوج', 'إضافة من الإضافات', 'إضافة بند']);
    });
});

describe('الاختيار يصير بندًا مستقلًّا', () => {
    it('إضافةٌ بلا منتج تحلّ محلّ السطر الفارغ — باسمها وثمنها', async () => {
        const user = page();
        expect(bodyRows()).toHaveLength(1);

        await pick(user, 'شوكولاتة');

        const rows = bodyRows();
        expect(rows).toHaveLength(1);
        expect(rows[0]).toHaveTextContent('إضافة');
        expect(rows[0]).toHaveTextContent('شوكولاتة');
        expect(rows[0]).toHaveTextContent('3.000');
        // ‏والاسمُ والثمنُ يُقرآن ولا يُكتبان: الكميّةُ والخصمُ وحدهما حقلان
        expect(within(rows[0]).getAllByRole('textbox')).toHaveLength(2);
    });

    it('أكثرُ من إضافةٍ من نافذةٍ واحدة — والإجماليُّ يتحرّك معها', async () => {
        const user = page();

        await pick(user, 'شوكولاتة', 'بطاقة إهداء');

        expect(bodyRows()).toHaveLength(2);
        // ‏٣ + ١ = ٤، وضريبةُ المتجر ٥٪ فوقها
        expect(grandTotal()).toContain('4.200');

        // ‏والكميّةُ تُعدَّل كأيّ بند
        const qty = within(bodyRows()[0]).getByLabelText('الكمية');
        await user.clear(qty);
        await user.type(qty, '2');
        expect(grandTotal()).toContain('7.350');
    });

    it('والحذفُ يُسقطها ويُنقص الإجمالي', async () => {
        const user = page();
        await pick(user, 'شوكولاتة', 'بطاقة إهداء');

        await user.click(within(bodyRows()[0]).getByTitle('حذف البند'));

        expect(bodyRows()).toHaveLength(1);
        expect(bodyRows()[0]).toHaveTextContent('بطاقة إهداء');
        expect(grandTotal()).toContain('1.050');
    });

    it('ويُرسَل البندُ بمعرّف الإضافة لا بمنتجٍ وهميّ', async () => {
        const user = page();
        await pick(user, 'شوكولاتة');

        await user.click(screen.getByRole('button', { name: /حفظ كمسودة|حفظ مسودة|حفظ/ }));

        const items = sent.at(-1)?.payload.items as Record<string, string>[];
        expect(items).toHaveLength(1);
        expect(items[0]).toMatchObject({ addon_id: '11', product_id: '', description: 'شوكولاتة', quantity: '1' });
    });
});

describe('نافذةُ الإضافات', () => {
    function dialog(over: Partial<React.ComponentProps<typeof AddonDialog>> = {}) {
        const onPick = vi.fn();
        render(
            <AddonDialog open onOpenChange={vi.fn()} addons={ADDONS} truncated={false} picked={[]} onPick={onPick} {...over} />,
        );

        return { onPick, user: userEvent.setup() };
    }

    const rows = () =>
        screen.getAllByRole('button').filter((b) => ADDONS.some((a) => b.textContent?.includes(a.name)));

    it('تعرض كلَّ ما أُرسل — بلا منتجٍ يُختار قبلها', () => {
        dialog();
        expect(rows()).toHaveLength(3);
    });

    it('تجد الإضافةَ باسمها العربيّ والإنجليزيّ', async () => {
        const { user } = dialog();
        const input = screen.getByPlaceholderText('اسم الإضافة');

        await user.type(input, 'شوكو');
        expect(rows()).toHaveLength(1);
        expect(rows()[0]).toHaveTextContent('شوكولاتة');

        await user.clear(input);
        await user.type(input, 'ribbon');
        expect(rows()).toHaveLength(1);
        expect(rows()[0]).toHaveTextContent('شريطة ذهبية');
    });

    it('تقول إن لم تجد — ولا تعرض قائمةً فارغةً صامتة', async () => {
        const { user } = dialog();
        await user.type(screen.getByPlaceholderText('اسم الإضافة'), 'ورد');

        expect(screen.getByText('لا إضافة بهذا الاسم')).toBeInTheDocument();
    });

    it('تبقى مفتوحةً بعد الاختيار وتعلّم ما في الفاتورة', async () => {
        const { user, onPick } = dialog({ picked: [12] });

        await user.click(rows().find((b) => b.textContent?.includes('شوكولاتة'))!);
        expect(onPick).toHaveBeenCalledWith(ADDONS[0]);
        expect(screen.getByRole('dialog')).toBeInTheDocument();
        expect(screen.getByText('أُضيف :n بندًا'.replace(':n', '1'))).toBeInTheDocument();

        expect(rows().find((b) => b.textContent?.includes('بطاقة إهداء'))).toHaveTextContent('في الفاتورة');
    });
});
