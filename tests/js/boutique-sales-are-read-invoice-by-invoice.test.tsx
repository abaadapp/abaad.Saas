import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import SalesPanel, { salesQuery, type InvoiceDetails, type SaleRow, type SalesPage, type SalesSummary } from '@/Pages/Admin/Boutiques/SalesPanel';

/* قائمةُ Radix لا تُختار في jsdom — فتُرسم قائمةً أصليّة بالعقد نفسِه */
vi.mock('@/Components/Field', async () => {
    const actual = await vi.importActual<Record<string, unknown>>('@/Components/Field');

    return {
        ...actual,
        Select: ({ options, value, onChange, 'aria-label': label }: {
            options: { label: string; value: string | number }[];
            value?: string | number | null;
            onChange?: (e: { target: { value: string; name: string } }) => void;
            'aria-label'?: string;
        }) => (
            <select aria-label={label} value={value ?? ''} onChange={(e) => onChange?.({ target: { value: e.target.value, name: '' } })}>
                {options.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
            </select>
        ),
    };
});

/**
 * «مبيعات البوتيك وفواتيره» في الواجهة — ما يقرؤه صاحبُ المتجر.
 *
 * ═══ ما يُحرس ═══
 *
 *   - الطقمُ بعشرين فيه قطعةٌ بستّة: صفُّ القطعة باسم الطقم وسعره، و«لا عمولة»،
 *     وللبوتيك ستّة — لا يُعرض الطقمُ بيعًا للبوتيك.
 *   - المنفرد بعمولته ونسبتها، والملغاةُ والمرتجعةُ جزئيًّا بحالها.
 *   - الملخّصُ يفصل المبيعاتِ والتسويةَ والمرتجعات، ويقول إنّ الباقي للمتجر ليس ربحًا.
 *   - كلُّ مُرشِّحٍ طلبٌ للخادم، والصفحةُ من الخادم، والبحثُ لا يُرسَل لكلّ حرف.
 *   - الهاتفُ بطاقات والحاسوبُ جدول — والعددُ واحد.
 *   - تفاصيلُ الفاتورة: ما للبوتيكات وما يبقى للمتجر من البند، والخصمُ يتحمّله المتجر.
 *
 * والحسبةُ في الخادم (`BoutiqueSales`)، يحرسها `ABoutiqueSalesAreReadInvoiceByInvoiceTest`.
 */

const currency = { id: 1, code: 'OMR', name: 'ريال عماني', symbol: 'ر.ع', rate: 1, is_base: true, active: true, decimals: 3 } as never;

const row = (over: Partial<SaleRow> = {}): SaleRow => ({
    key: 'bundle:1', type: 'bundle', order_id: 11, order_number: 'INV-0011', ordered_at: '2027-02-10 10:00',
    order_status: 'مكتمل', invoice_state: 'active', boutique_id: 5, boutique: 'بوتيك لمى',
    product: 'قطعة فضّة', variant: null, parent: 'طقم الفضّة', parent_variant: null, parent_total: 20, addon: null,
    quantity: 1, unit_price: 6, amount: 6, rate: null, commission: 0, net: 6, credited: 0, kept_state: null,
    settle_state: 'pending', settlement: null, ...over,
});

const summary: SalesSummary = {
    standalone: { gross: 12, commission: 2.4, net: 9.6, quantity: 2, lines: 1, orders: 1, taxed_orders: 0 },
    components: { bundle: 6, addon: 0, due: 6, quantity: 1 },
    bundles: { lines: 1, revenue: 20, boutiques_due: 6, shop_remaining: 14 },
    owed: 15.6,
    pieces: 'on',
    hosting: true,
    pieces_recorded: false,
    rounding: { lines_commission: 2.4, report_commission: 2.4, report_difference: 0, lines_pending_net: 9.6, sheet_pending_net: 9.6, sheet_difference: 0 },
    settlement: { sheets: 0, issued: 0, paid: 0, unpaid: 0, remaining: 15.6, pending: { standalone: 9.6, components: 6, credit: 0, total: 15.6 } },
    returns: { notes: 0, amount: 0, reduced: 0, claims_open: 0, review: 0, shop: 0, component_credits: 0, cancelled_gross: 0 },
};

const page = (rows: SaleRow[], over: Partial<SalesPage> = {}): SalesPage => ({ rows, page: 1, last_page: 1, total: rows.length, per_page: 25, ...over });

let calls: string[] = [];
let respond: (url: string) => unknown;

beforeEach(() => {
    calls = [];
    respond = (url) => (url.includes('summary') ? summary : page([
        row(),
        row({ key: 'standalone:2', type: 'standalone', order_id: 12, order_number: 'INV-0012', parent: null, parent_total: null,
            quantity: 2, unit_price: 6, amount: 12, rate: 20, commission: 2.4, net: 9.6 }),
        row({ key: 'standalone:3', type: 'standalone', order_id: 13, order_number: 'INV-0013', parent: null, parent_total: null,
            invoice_state: 'cancelled', rate: 20, commission: 1.2, net: 4.8 }),
    ]));
    vi.stubGlobal('fetch', vi.fn(async (url: string) => {
        calls.push(url);
        return { ok: true, json: async () => respond(url) } as Response;
    }));
});

afterEach(() => {
    vi.unstubAllGlobals();
    vi.useRealTimers();
});

const mount = (over: Partial<Parameters<typeof SalesPanel>[0]> = {}) => render(
    <SalesPanel
        boutiqueId={5}
        boutiques={[{ value: 5, label: 'بوتيك لمى' }, { value: 6, label: 'بوتيك ريم' }]}
        period="2027-02"
        periods={[{ value: '2027-02', label: 'فبراير 2027' }, { value: '2027-01', label: 'يناير 2027' }]}
        onPeriodChange={vi.fn()}
        currency={currency}
        {...over}
    />,
);

describe('مبيعات البوتيك وفواتيره', () => {
    it('قطعةُ الطقم صفٌّ باسم الطقم وسعره — بلا عمولة وللبوتيك ستّة', async () => {
        mount();
        const table = await screen.findByTestId('boutique-sales-table');
        const tr = within(table).getByText('INV-0011').closest('tr')!;
        expect(within(tr).getByText('قطعة فضّة')).toBeTruthy();
        expect(within(tr).getByTestId('sale-parent').textContent).toContain('طقم الفضّة');
        expect(within(tr).getByTestId('sale-parent').textContent).toContain('20.000');
        expect(within(tr).getByText('ضمن باقة')).toBeTruthy();
        expect(within(tr).getByText('لا عمولة')).toBeTruthy();
        expect(tr.textContent).toContain('6.000');
    });

    it('المنفردُ بعمولته ونسبتها، والملغاةُ تقول إنّها ملغاة', async () => {
        mount();
        const table = await screen.findByTestId('boutique-sales-table');
        const sold = within(table).getByText('INV-0012').closest('tr')!;
        expect(within(sold).getByText('بيع منفرد')).toBeTruthy();
        expect(sold.textContent).toContain('2.400');
        expect(sold.textContent).toContain('(20.00%)');
        expect(sold.textContent).toContain('9.600');
        expect(within(within(table).getByText('INV-0013').closest('tr')!).getByText('ملغاة')).toBeTruthy();
    });

    it('الملخّصُ يفصل المبيعات والتسوية — والباقي للمتجر «ليس ربحًا»', async () => {
        mount();
        const box = await screen.findByTestId('boutique-sales-summary');
        expect(box.textContent).toContain('مبيعات منتجات البوتيك المنفردة');
        expect(box.textContent).toContain('15.600');
        expect(box.textContent).toContain('المتبقي المستحق');
        expect(box.textContent).toContain('ليس ربحًا');
        expect(box.textContent).toContain('14.000');
        // ولا قسمَ مرتجعاتٍ حين لا مرتجعات
        expect(box.textContent).not.toContain('المرتجعات والأرصدة');
    });

    it('حسابُ القطع يُقال على الشاشة إن كان مطفأً أو مرفوضًا بالضريبة — ولا يُقال وهو يعمل', async () => {
        const first = mount();
        await screen.findByTestId('boutique-sales-summary');
        expect(screen.queryByTestId('boutique-pieces-note')).toBeNull();
        expect(screen.queryByTestId('boutique-hosting-off')).toBeNull();
        first.unmount();

        respond = (url) => (url.includes('summary') ? { ...summary, pieces: 'off' } : page([]));
        const off = mount();
        expect((await screen.findByTestId('boutique-pieces-note')).textContent).toContain('مطفأ لهذا المتجر');
        off.unmount();

        respond = (url) => (url.includes('summary') ? { ...summary, pieces: 'off', pieces_recorded: true, hosting: false } : page([]));
        const recorded = mount();
        const note = await screen.findByTestId('boutique-pieces-note');
        expect(note.textContent).toContain('معروض ومحسوب كما هو');
        expect(note.textContent).not.toContain('فلا تظهر صفوف');
        expect(screen.getByTestId('boutique-hosting-off').textContent).toContain('ولم يُحذف');
        recorded.unmount();

        respond = (url) => (url.includes('summary') ? { ...summary, pieces: 'taxed' } : page([]));
        mount();
        expect((await screen.findByTestId('boutique-pieces-note')).textContent).toContain('يحتسب الضريبة');
    });

    it('كلُّ مُرشِّحٍ طلبٌ للخادم، والبوتيكُ «الكلّ» لا يُرسَل', async () => {
        mount();
        await screen.findByTestId('boutique-sales-table');
        expect(calls.some((u) => u.startsWith('/admin.boutiques.sales/2027-02/5'))).toBe(true);

        fireEvent.change(screen.getByLabelText('نوع العملية'), { target: { value: 'bundle' } });
        await waitFor(() => expect(calls.at(-1)).toBe('/admin.boutiques.sales/2027-02/5/bundle'));

        fireEvent.change(screen.getByLabelText('حالة التسوية'), { target: { value: 'paid' } });
        await waitFor(() => expect(calls.at(-1)).toBe('/admin.boutiques.sales/2027-02/5/bundle/paid'));

        fireEvent.change(screen.getByLabelText('البوتيك'), { target: { value: 'all' } });
        await waitFor(() => expect(calls).toContain('/admin.boutiques.sales.summary/2027-02'));

        expect(salesQuery({ period: '2027-02', boutique: 'all', type: '', settle: '', q: '  ' })).toEqual({ period: '2027-02' });
        expect(salesQuery({ period: '2027-02', boutique: 'all', type: 'all', settle: 'all', q: '' })).toEqual({ period: '2027-02' });
        // و«الكلّ» خيارٌ له اسمٌ يُرى — لا خانةٌ فارغة
        expect((screen.getByLabelText('نوع العملية') as HTMLSelectElement).value).toBe('bundle');
        fireEvent.change(screen.getByLabelText('نوع العملية'), { target: { value: 'all' } });
        await waitFor(() => expect(calls.at(-1)).toBe('/admin.boutiques.sales/2027-02/paid'));
    });

    it('الشهرُ شهرُ الصفحة: يُبدَّل منها لا من حالةٍ ثانية', async () => {
        const onPeriodChange = vi.fn();
        mount({ onPeriodChange });
        await screen.findByTestId('boutique-sales-table');
        fireEvent.change(screen.getByLabelText('الشهر'), { target: { value: '2027-01' } });
        expect(onPeriodChange).toHaveBeenCalledWith('2027-01');
    });

    it('البحثُ يُرسَل بعد أن يكفّ الكاتب — لا لكلّ حرف', async () => {
        mount();
        await screen.findByTestId('boutique-sales-table');
        vi.useFakeTimers();
        const before = calls.length;
        const input = screen.getByLabelText('بحث في الفواتير');
        fireEvent.change(input, { target: { value: 'I' } });
        fireEvent.change(input, { target: { value: 'IN' } });
        fireEvent.change(input, { target: { value: 'INV-0011' } });
        await act(async () => { vi.advanceTimersByTime(350); });
        vi.useRealTimers();
        await waitFor(() => expect(calls.filter((u) => u.includes('INV-0011'))).toHaveLength(1));
        expect(calls.slice(before).filter((u) => !u.includes('summary') && !u.includes('INV-0011'))).toHaveLength(0);
    });

    it('الصفحةُ من الخادم: «التالي» يطلب الصفحة الثانية', async () => {
        respond = (url) => (url.includes('summary') ? summary : page([row()], { page: url.endsWith('/2') ? 2 : 1, last_page: 3, total: 60 }));
        mount();
        await screen.findByText('صفحة 1 من 3 — 60 صفًّا');
        fireEvent.click(screen.getByRole('button', { name: /التالي/ }));
        await waitFor(() => expect(calls.at(-1)).toBe('/admin.boutiques.sales/2027-02/5/2'));
        await screen.findByText('صفحة 2 من 3 — 60 صفًّا');
    });

    it('الهاتفُ بطاقاتٌ والحاسوبُ جدول — بالعدد نفسه', async () => {
        mount();
        const cards = await screen.findByTestId('boutique-sales-cards');
        const table = screen.getByTestId('boutique-sales-table');
        expect(cards.querySelectorAll('li')).toHaveLength(3);
        expect(table.querySelectorAll('tbody tr')).toHaveLength(3);
        expect(cards.className).toContain('md:hidden');
        expect(table.parentElement!.className).toContain('md:block');
    });

    const invoice = (over: Partial<InvoiceDetails['order']> = {}, shares: Partial<InvoiceDetails['shares']> = {}): InvoiceDetails => ({
        order: { id: 11, number: 'INV-0011', ordered_at: '2027-02-10 10:00', status: 'مكتمل', invoice_state: 'active', subtotal: 40, discount: 5, tax: 0, delivery_fee: 0, total: 35, tax_basis: 'none', ...over },
        lines: [
            {
                id: 1, name: 'طقم الفضّة', variant: null, quantity: 1, price: 20, total: 20, addons_total: 0, boutique: null,
                pieces: [{ boutique: 'بوتيك لمى', product: 'قطعة فضّة', type: 'bundle', addon: null, quantity: 1, unit_price: 6, amount: 6, credited: 0, kept_state: null, settlement: null, settle_state: 'pending' }],
                dues: 6, shop_remaining: 14, shop_share: 14,
            },
            { id: 2, name: 'باقة بسيطة', variant: null, quantity: 1, price: 20, total: 20, addons_total: 0, boutique: null, pieces: [], dues: 0, shop_remaining: null, shop_share: 20 },
        ],
        shares: {
            boutiques: [{ id: 5, name: 'بوتيك لمى', standalone_gross: 0, commission: 0, standalone_net: 0, components_due: 6, owed: 6 }],
            shop: 34, lines_total: 40, reconciles: true, void: false, ...shares,
        },
        totals: { standalone_gross: 0, commission: 0, standalone_net: 0, components_due: 6, owed: 6 },
        edits: [], can_open: false, url: null,
    });

    const openInvoice = async (data: InvoiceDetails) => {
        respond = (url) => (url.includes('summary') ? summary : url.includes('sales.order') ? data : page([row()]));
        mount();
        const table = await screen.findByTestId('boutique-sales-table');
        fireEvent.click(within(table).getByText('INV-0011'));
        return screen.findByTestId('boutique-invoice');
    };

    it('تفاصيلُ الفاتورة: بنودُها كلُّها، ونصيبُ كلّ جهةٍ يجمع إلى مجموع البنود', async () => {
        const box = await openInvoice(invoice());
        expect(calls).toContain('/admin.boutiques.sales.order/11');
        expect(box.textContent).toContain('ويبقى للمتجر من إيراد البند 14.000');
        expect(box.textContent).toContain('منتج المتجر — لا نصيب فيه لبوتيك');
        const header = screen.getByTestId('boutique-invoice-header');
        expect(header.textContent).toContain('خصم الفاتورة (على المتجر)');
        expect(header.textContent).toContain('35.000');
        const shares = screen.getByTestId('boutique-invoice-shares');
        expect(shares.textContent).toContain('بوتيك لمى');
        expect(shares.textContent).toContain('6.000');
        expect(shares.textContent).toContain('34.000');
        expect(shares.textContent).toContain('40.000');
        expect(screen.queryByTestId('boutique-invoice-mismatch')).toBeNull();
        // ومن لا يملك قسم المبيعات لا يُعطى رابطَ الفاتورة الكاملة
        expect(screen.queryByText('فتح الفاتورة كاملة')).toBeNull();
    });

    it('الضريبةُ ضمن الأسعار تُقال، والفاتورةُ التي لا تطابق تُراجَع ولا تُعتمد', async () => {
        const inside = await openInvoice(invoice({ tax: 1.286, tax_basis: 'inside', total: 27 }));
        expect(screen.getByTestId('boutique-invoice-header').textContent).toContain('الضريبة (ضمن الأسعار)');
        expect(inside.textContent).toContain('وهو هنا شامل الضريبة');
        cleanup();

        await openInvoice(invoice({ tax_basis: 'unknown' }, { reconciles: false }));
        expect(screen.getByTestId('boutique-invoice-mismatch').textContent).toContain('راجعها قبل اعتماد الأنصبة');
    });

    it('فرقُ التقريب بين البنود ودالّتي النظام يُعرض بعينه — ولا يُعرض صفرًا', async () => {
        const first = mount();
        await screen.findByTestId('boutique-sales-summary');
        expect(screen.queryByTestId('boutique-rounding-note')).toBeNull();
        first.unmount();

        respond = (url) => (url.includes('summary')
            ? { ...summary, rounding: { lines_commission: 0.875, report_commission: 0.874, report_difference: -0.001, lines_pending_net: 6.118, sheet_pending_net: 6.119, sheet_difference: 0.001 } }
            : page([]));
        mount();
        const note = await screen.findByTestId('boutique-rounding-note');
        expect(note.textContent).toContain('لم يُعدَّل أي مبلغ');
        expect(note.textContent).toContain('0.875');
        expect(note.textContent).toContain('0.874');
        expect(note.textContent).toContain('6.119');
        expect(note.textContent).toContain('المعتمد للدفع الورقة');
    });

    it('الفاتورةُ الملغاة تُعرض ببنودها ولا نصيبَ فيها', async () => {
        await openInvoice(invoice({ invoice_state: 'cancelled' }, { void: true, shop: 0, boutiques: [] }));
        expect(screen.getByTestId('boutique-invoice-void').textContent).toContain('ولا نصيب فيها لأحد');
    });

    it('الملخّصُ يقول إنّ ما قبل الورقة ليس تسويةً نهائية، وينبّه على فواتير الضريبة', async () => {
        const first = mount();
        expect((await screen.findByTestId('boutique-not-final-note')).textContent).toContain('وليست تسوية نهائية');
        expect(screen.queryByTestId('boutique-tax-note')).toBeNull();
        first.unmount();

        respond = (url) => (url.includes('summary') ? { ...summary, standalone: { ...summary.standalone, taxed_orders: 3 } } : page([]));
        mount();
        expect((await screen.findByTestId('boutique-tax-note')).textContent).toContain('في هذا الشهر 3 فاتورة فيها ضريبة');
    });

    it('خطأُ الخادم يُقال ولا يُعرض صفرًا', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => ({ ok: false, status: 500, json: async () => ({}) }) as Response));
        mount();
        await screen.findByText('تعذّر تحميل مبيعات البوتيك — أعد المحاولة.');
    });
});
