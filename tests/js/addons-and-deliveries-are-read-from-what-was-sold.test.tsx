import { render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { pageProps } from './setup';

vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

vi.mock('@/hooks/useLiveFeed', () => ({ default: () => ({ data: null, updatedAt: null }) }));

const { default: ReportsAddons } = await import('@/Pages/Admin/Reports/Addons');
const { default: ReportsOrders } = await import('@/Pages/Admin/Reports/Orders');
const { default: ReportsSales } = await import('@/Pages/Admin/Reports/Sales');

/**
 * الإضافاتُ والتوصيلُ على الشاشة — جزءٌ من الإجماليّ لا فوقه.
 *
 * ═══ وأثقلُ ما يُحرَس ═══
 *
 * أنّ كلَّ رقمٍ منهما يُسمّى «ضمن الإجمالي» حيث يجاور الإجماليّ: بطاقةٌ تقول
 * «قيمة الإضافات» وحدها بجوار «إجمالي المبيعات» يجمعها قارئُها عليه. وأنّ
 * صفًّا مجموعًا بالاسم يُوسم بذلك — لا يُقرأ هويّةَ إضافةٍ حيّة.
 */

const reset = () => {
    for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
};

const base = {
    translations: {},
    auth: { abilities: ['reports'], mayActions: [] },
    context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 } },
    range: 'month',
    rangeLabel: 'هذا الشهر',
    truncated: null,
    limit: 500,
};

const ADDON_SUMMARY = { uses: 2, quantity: 3, orders: 1, revenue: 7, cost: 1.5, profit: 5.5, unlinked: 0, sales: 19 };

const addonRow = (over: Record<string, unknown> = {}) => ({
    id: '3:دبّ', addon_id: 3, name: 'دبّ', linked: true, link: 'مربوطة بإضافة',
    orders: 1, uses: 1, quantity: 1, standalone_quantity: 1,
    revenue: 4, cost: 1.5, profit: 2.5, average: 4, ...over,
});

const drawAddons = (over: Record<string, unknown> = {}) => {
    reset();
    Object.assign(pageProps, base, {
        rows: [addonRow(), addonRow({ id: '1:لفّ فاخر', addon_id: 1, name: 'لفّ فاخر', quantity: 2, standalone_quantity: 0, revenue: 3, cost: 0, profit: 3, average: 1.5 })],
        summary: ADDON_SUMMARY,
        filters: { range: 'month', branch_id: null, channel: null },
        options: {
            branches: [{ value: '1', label: 'الرئيسي' }],
            channels: [
                { value: 'website', label: 'الموقع الإلكتروني' },
                { value: 'pos', label: 'نقطة البيع' },
                { value: 'unknown', label: 'غير محدّدة' },
            ],
        },
        ...over,
    });

    return render(<ReportsAddons />);
};

describe('تقريرُ الإضافات', () => {
    beforeEach(reset);

    it('يرسم البطاقات الأربع بأرقام الخادم — والقيمةُ «ضمن إجمالي المبيعات»', () => {
        drawAddons();

        expect(screen.getByText('مرات استخدام الإضافات')).toBeInTheDocument();
        expect(screen.getByText('طلبات فيها إضافات')).toBeInTheDocument();
        expect(screen.getByText('قيمة الإضافات — ضمن إجمالي المبيعات')).toBeInTheDocument();
        expect(screen.getByText('ربح الإضافات')).toBeInTheDocument();
        expect(screen.getAllByText('7.000 ر.ع').length).toBeGreaterThan(0);
        expect(screen.getByText('5.500 ر.ع')).toBeInTheDocument();

        // ولا بطاقةَ باسم «قيمة الإضافات» وحدها — تُقرأ رقمًا يُجمع على المبيعات
        expect(screen.queryByText('قيمة الإضافات')).not.toBeInTheDocument();
    });

    it('ويقول تحتها إنّها من إجمالي الطلبات نفسِها لا فوقه', () => {
        drawAddons();

        const share = screen.getByTestId('addons-share');
        expect(share).toHaveTextContent('7.000 ر.ع');
        expect(share).toHaveTextContent('19.000 ر.ع');
        expect(share).toHaveTextContent('داخلةٌ فيه لا مضافةٌ إليه');
    });

    it('الجدولُ بأعمدته وبترتيب الخادم — عددُ الطلبات غيرُ الكميّة', () => {
        drawAddons();

        const table = screen.getByTestId('addons-report');
        const heads = within(table).getAllByRole('columnheader').map((h) => h.textContent);
        expect(heads).toEqual(['الإضافة', 'عدد الطلبات', 'مرات الاستخدام', 'الكمية', 'قيمة المبيعات', 'التكلفة', 'الربح', 'متوسّط سعر الوحدة']);

        const rows = within(table).getAllByRole('row').slice(1);
        expect(rows.map((r) => within(r).getAllByRole('cell')[0].textContent)).toEqual(['دبّ', 'لفّ فاخر']);

        const wrap = within(rows[1]).getAllByRole('cell').map((c) => c.textContent);
        expect(wrap).toEqual(['لفّ فاخر', '1', '1', '2', '3.000 ر.ع', '0.000 ر.ع', '3.000 ر.ع', '1.500 ر.ع']);
    });

    it('والصفُّ المجموعُ بالاسم يُوسم — والمربوطُ لا', () => {
        drawAddons({
            rows: [addonRow(), addonRow({ id: 'x:بطاقة قديمة', addon_id: null, name: 'بطاقة قديمة', linked: false })],
            summary: { ...ADDON_SUMMARY, unlinked: 4 },
        });

        const rows = within(screen.getByTestId('addons-report')).getAllByRole('row').slice(1);
        expect(within(rows[0]).queryByText('باسمها يوم البيع')).not.toBeInTheDocument();
        expect(within(rows[1]).getByText('باسمها يوم البيع')).toBeInTheDocument();

        const note = screen.getByTestId('addons-unlinked-note');
        expect(note).toHaveTextContent('4.000 ر.ع');
        expect(note).toHaveTextContent('ولا تُنسب إلى إضافةٍ حاليّة بالظنّ');
    });

    it('ولا ملاحظةَ عن غير المربوط حين لا شيءَ منه', () => {
        drawAddons();

        expect(screen.queryByTestId('addons-unlinked-note')).not.toBeInTheDocument();
    });

    it('وبلا مبيعات يقول ذلك', () => {
        drawAddons({ rows: [], summary: { ...ADDON_SUMMARY, uses: 0, orders: 0, revenue: 0, cost: 0, profit: 0, sales: 0 } });

        expect(screen.getByText('لم تُبع إضافاتٌ في هذه الفترة')).toBeInTheDocument();
    });

    it('ومرشّحاه الفرعُ والقناة', () => {
        drawAddons();

        expect(screen.getByText('الفرع')).toBeInTheDocument();
        expect(screen.getByText('القناة')).toBeInTheDocument();
    });
});

/* ══════════════════════════ تقريرُ الطلبات ══════════════════════════ */

const ORDER_SUMMARY = {
    count: 3, total: 29, average: 14.5, cancelled: 1,
    delivery: 1, pickup: 1, delivery_fees: 2, addons: 7,
};

const drawOrders = (over: Record<string, unknown> = {}) => {
    reset();
    Object.assign(pageProps, base, {
        rows: [
            { id: 1, number: 'INV-1', customer: 'سارة', branch: 'الرئيسي', status: 'مكتمل', method: 'نقدي', fulfillment: 'توصيل', total: 19, at: '2026-09-29' },
            { id: 2, number: 'INV-2', customer: 'خالد', branch: 'الرئيسي', status: 'مكتمل', method: 'نقدي', fulfillment: 'استلام من المحل', total: 10, at: '2026-09-29' },
            { id: 3, number: 'INV-3', customer: 'عميل نقدي', branch: 'الرئيسي', status: 'ملغي', method: 'نقدي', fulfillment: null, total: 5, at: '2026-09-29' },
        ],
        summary: ORDER_SUMMARY,
        filters: { range: 'month', status: null, branch_id: null, payment_method: null, fulfillment: null },
        options: {
            statuses: [], branches: [], methods: [],
            fulfillments: [
                { value: 'pickup', label: 'استلام من المحل' },
                { value: 'delivery', label: 'توصيل' },
            ],
        },
        ...over,
    });

    return render(<ReportsOrders />);
};

describe('تقريرُ الطلبات — التوصيلُ والإضافات', () => {
    beforeEach(reset);

    it('يضيف أربع بطاقات — والمبلغان «ضمن الإجمالي»', () => {
        drawOrders();

        expect(screen.getByText('طلبات التوصيل (غير الملغاة)')).toBeInTheDocument();
        expect(screen.getByText('طلبات الاستلام (غير الملغاة)')).toBeInTheDocument();
        expect(screen.getByText('رسوم التوصيل — ضمن الإجمالي')).toBeInTheDocument();
        expect(screen.getByText('قيمة الإضافات — ضمن الإجمالي')).toBeInTheDocument();
        expect(screen.getByText('2.000 ر.ع')).toBeInTheDocument();
        expect(screen.getByText('7.000 ر.ع')).toBeInTheDocument();
    });

    it('والإجماليُّ والمتوسّطُ كما أرسلهما الخادم — لا يُجمع عليهما شيء', () => {
        drawOrders();

        expect(screen.getByText('إجمالي المبيعات')).toBeInTheDocument();
        expect(screen.getByText('29.000 ر.ع')).toBeInTheDocument();
        expect(screen.getByText('14.500 ر.ع')).toBeInTheDocument();
        // ٢٩ + ٢ + ٧ لا تظهر في مكان
        expect(screen.queryByText('38.000 ر.ع')).not.toBeInTheDocument();
    });

    it('وعمودُ نوع التنفيذ — وبيعةُ المنضدة شرطة', () => {
        drawOrders();

        const heads = screen.getAllByRole('columnheader').map((h) => h.textContent);
        expect(heads).toContain('نوع التنفيذ');

        const rows = screen.getAllByRole('row').slice(1);
        const at = heads.indexOf('نوع التنفيذ');
        expect(rows.map((r) => within(r).getAllByRole('cell')[at].textContent)).toEqual(['توصيل', 'استلام من المحل', '—']);
    });

    it('والملغى يبقى في الجدول كما كان', () => {
        drawOrders();

        expect(screen.getAllByRole('row').slice(1)).toHaveLength(3);
        expect(screen.getByText('INV-3')).toBeInTheDocument();
    });

    it('ومرشّحُ نوع التنفيذ بجانب المرشّحات القائمة', () => {
        drawOrders();

        for (const label of ['الحالة', 'الفرع', 'وسيلة الدفع', 'نوع التنفيذ']) {
            expect(screen.getAllByText(label).length).toBeGreaterThan(0);
        }
    });
});

/* ══════════════════════════ ملخّصُ المبيعات ══════════════════════════ */

const SALES_SUMMARY = {
    sales: 19, addons: 7, cogs: 4.5, profit: 14.5, profit_kind: 'net' as const, expenses: 0, tax: 0,
    products: 3, inventory_alerts: 0, employees: 1, customers: 2, payment_methods: 1,
};

const drawSales = (summary: Record<string, unknown> = SALES_SUMMARY) => {
    reset();
    Object.assign(pageProps, {
        translations: {},
        auth: { abilities: ['reports'], mayActions: [] },
        context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 } },
        summary,
        salesSeries: { labels: ['1'], full: ['1'], data: [19], counts: [1], range: 'month' },
        range: 'month',
        paymentDistribution: { labels: ['نقدي'], series: [19] },
        topSellingProducts: [],
        channel: null,
        channels: [],
    });

    return render(<ReportsSales />);
};

describe('ملخّصُ المبيعات — «منها إضافات»', () => {
    beforeEach(reset);

    it('سطرٌ تحت البطاقات يقول إنّها جزءٌ من الإجمالي', () => {
        drawSales();

        const note = screen.getByTestId('sales-addons-note');
        expect(note).toHaveTextContent('منها إضافات');
        expect(note).toHaveTextContent('7.000');
        expect(note).toHaveTextContent('جزءٌ من إجمالي المبيعات لا مضافٌ إليه');
    });

    it('ولا بطاقةَ سادسةَ بجوار الإجماليّ', () => {
        drawSales();

        expect(screen.queryByText('قيمة الإضافات')).not.toBeInTheDocument();
        expect(screen.getByText('إجمالي المبيعات')).toBeInTheDocument();
    });

    it('ولا سطرَ حين لا إضافات', () => {
        drawSales({ ...SALES_SUMMARY, addons: 0 });

        expect(screen.queryByTestId('sales-addons-note')).not.toBeInTheDocument();
    });
});
