import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { pageProps } from './setup';

vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

/** التغذيةُ تُلتقط روابطُها: ما يُطلب كلَّ دقيقةٍ يجب أن يحمل النطاق */
const feeds: string[] = [];
vi.mock('@/hooks/useLiveFeed', () => ({
    default: (url: string) => {
        feeds.push(url);

        return { data: null, updatedAt: null };
    },
}));

/** والملفّاتُ الثلاثة تُلتقط روابطُها كما تصل القائمة */
vi.mock('@/Components/ExportMenu', () => ({
    default: ({ xlsx, pdf, csv }: { xlsx: string; pdf: string; csv: string }) => (
        <div data-testid="exports" data-xlsx={xlsx} data-pdf={pdf} data-csv={csv} />
    ),
}));

vi.mock('@/hooks/useLiveStock', () => ({
    default: (_url: string, products: unknown[]) => ({ products, updatedAt: null }),
}));

const { router } = await import('@inertiajs/react');
const { default: ReportsSales } = await import('@/Pages/Admin/Reports/Sales');
const { default: ProductsIndex } = await import('@/Pages/Admin/Products/Index');
const { default: Dues } = await import('@/Pages/Admin/Finance/Dues');

const visits = () =>
    (router.get as unknown as { mock: { calls: [string, Record<string, unknown>][] } }).mock.calls.map(
        ([url, data]) => ({ url, data }),
    );

const BOUTIQUES = [
    { value: 1, label: 'بوتيك لمى', active: true },
    { value: 2, label: 'بوتيك نور', active: true },
];

const reset = (over: Record<string, unknown>) => {
    for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    Object.assign(pageProps, {
        translations: {},
        auth: { abilities: ['reports', 'products', 'finance'], mayActions: [] },
        context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 } },
        ...over,
    });
};

const SUMMARY = {
    sales: 100, cogs: 20, profit: 80, profit_kind: 'net' as const, expenses: 0, tax: 5,
    products: 3, inventory_alerts: 0, employees: 1, customers: 2, payment_methods: 1,
};

const sales = (over: Record<string, unknown> = {}) => {
    reset({
        summary: SUMMARY,
        salesSeries: { labels: ['1'], full: ['1'], data: [100], counts: [1], range: 'week' },
        range: 'week',
        paymentDistribution: { labels: ['نقدي'], series: [100] },
        topSellingProducts: [],
        boutiqueTotals: null,
        boutique: null,
        boutiqueLabel: 'كل المبيعات',
        boutiques: BOUTIQUES,
        channel: 'pos',
        channels: [
            { value: 'website', label: 'الموقع الإلكتروني' },
            { value: 'pos', label: 'نقطة البيع' },
        ],
        ...over,
    });

    return render(<ReportsSales />);
};

/** نطاقُ «بوتيك لمى» كما يرسله الخادم */
const LAMA = {
    summary: null,
    paymentDistribution: null,
    boutique: { kind: 'boutique', id: 1, name: 'بوتيك لمى' },
    boutiqueLabel: 'بوتيك: بوتيك لمى',
    boutiqueTotals: { gross: 50, commission: 10, net: 40, quantity: 1, orders: 1, lines: 1 },
};

beforeEach(() => {
    vi.mocked(router.get).mockClear();
    feeds.length = 0;
});

describe('ملخّصُ المبيعات بنطاق بوتيك', () => {
    it('المُرشِّحُ لمن عنده بوتيكات وحده', () => {
        sales({ boutiques: [] });
        expect(screen.queryByTestId('boutique-filter')).toBeNull();
    });

    it('يعرض «كل المبيعات» و«منتجات المتجر» والبوتيكات', () => {
        sales();

        const bar = screen.getByTestId('boutique-filter');
        expect(bar).toHaveTextContent('كل المبيعات');
        expect(screen.getByTestId('boutique-own')).toHaveTextContent('منتجات المتجر');
        expect(screen.getByTestId('boutique-1')).toHaveTextContent('بوتيك لمى');
        expect(screen.getByTestId('boutique-2')).toHaveTextContent('بوتيك نور');
    });

    it('واختيارُ بوتيكٍ يحمل الفترةَ والقناة', () => {
        sales();
        fireEvent.click(screen.getByTestId('boutique-1'));

        expect(visits().at(-1)?.data).toEqual({ range: 'week', channel: 'pos', boutique: '1' });
    });

    it('وتبديلُ القناة لا يُسقط البوتيك', () => {
        sales(LAMA);
        fireEvent.click(screen.getByTestId('channel-website'));

        expect(visits().at(-1)?.data).toEqual({ range: 'week', channel: 'website', boutique: '1' });
    });

    it('وتبديلُ الفترة لا يُسقط البوتيك', () => {
        sales(LAMA);
        fireEvent.click(screen.getByRole('tab', { name: 'الشهر' }));

        expect(visits().at(-1)?.data).toMatchObject({ range: 'month', channel: 'pos', boutique: '1' });
    });

    it('والتغذيةُ والملفّاتُ الثلاثة تحمل النطاقَ نفسَه', () => {
        sales(LAMA);

        // و`route()` في الاختبار تصفّ قيمَ المعاملات مسارًا: الفترة/القناة/البوتيك
        expect(feeds.at(-1)).toBe('/admin.reports.feed/week/pos/1');

        const ex = screen.getByTestId('exports');
        expect(ex.getAttribute('data-xlsx')).toBe('/admin.reports.xlsx/week/pos/1');
        expect(ex.getAttribute('data-pdf')).toBe('/admin.reports.pdf/week/pos/1');
        expect(ex.getAttribute('data-csv')).toBe('/admin.export.reports/week/pos/1');
    });

    it('بطاقاتُ البوتيك من بنوده — وما على الطلب كاملًا لا يُعرض', () => {
        sales(LAMA);

        expect(screen.getByText('إجمالي مبيعات البوتيك')).toBeInTheDocument();
        expect(screen.getByText('عمولة المتجر')).toBeInTheDocument();
        expect(screen.getByText('المستحق للبوتيك')).toBeInTheDocument();
        expect(screen.getByText('الكمية المباعة')).toBeInTheDocument();

        for (const hidden of ['صافي الربح', 'مُجمل الربح', 'الضريبة المحصّلة', 'المصروفات', 'توزيع وسائل الدفع', 'تكلفة البضاعة المباعة']) {
            expect(screen.queryByText(hidden)).toBeNull();
        }

        expect(screen.getByTestId('boutique-note')).toHaveTextContent('لا تُنسب لبند');
    });

    it('و«كل المبيعات» كما كانت', () => {
        sales({ channel: null });

        expect(screen.getByText('صافي الربح')).toBeInTheDocument();
        expect(screen.getByText('توزيع وسائل الدفع')).toBeInTheDocument();
        expect(screen.queryByTestId('boutique-note')).toBeNull();
    });
});

describe('قائمةُ المنتجات تقول لمن الصنف', () => {
    const PRODUCTS = [
        { id: 1, name: 'عطر لمى', label: 'عطر لمى', cat: '—', price: 50, cost: 30, qty: 4, sku: 'A', barcode: '1', image: null, stock_status: 'متوفر', active: true, alert: 1, tax: 0, discount: 0, boutique: 'بوتيك لمى' },
        { id: 2, name: 'باقة ورد', label: 'باقة ورد', cat: '—', price: 20, cost: 8, qty: 9, sku: 'B', barcode: '2', image: null, stock_status: 'متوفر', active: true, alert: 1, tax: 0, discount: 0, boutique: null },
    ];

    const list = (over: Record<string, unknown> = {}) => {
        reset({
            products: PRODUCTS,
            pagination: { current_page: 1, last_page: 1, per_page: 12, total: 2, from: 1, to: 2 },
            categories: [],
            filters: {},
            sorts: [],
            branches: [],
            currentBranchId: null,
            lastImport: null,
            boutiques: BOUTIQUES,
            ...over,
        });

        return render(<ProductsIndex />);
    };

    it('عمودُ «التبعية»: اسمُ البوتيك أو «منتج المتجر»', () => {
        list();

        expect(screen.getByText('التبعية')).toBeInTheDocument();
        expect(screen.getAllByText('بوتيك لمى').length).toBeGreaterThan(0);
        expect(screen.getAllByText('منتج المتجر').length).toBeGreaterThan(0);
    });

    it('ولا عمودَ لمن لا بوتيكَ عنده', () => {
        list({ boutiques: [], products: PRODUCTS.map(({ boutique: _b, ...p }) => p) });

        expect(screen.queryByText('التبعية')).toBeNull();
        expect(screen.queryByText('منتج المتجر')).toBeNull();
    });

    it('والمُرشِّحُ المطبَّق من الرابط يُقرأ باسمه', () => {
        list({ filters: { boutique: 'own' } });

        expect(screen.getAllByText('منتجات المتجر').length).toBeGreaterThan(0);
    });
});

describe('المبالغ المستحقة تسمّي البوتيك', () => {
    it('مصروفُ التسوية يقول لأيّ بوتيك — وغيرُه لا', () => {
        reset({
            expenses: [
                { id: 7, reference: 'BQ-2027-02-1', title: 'تسوية', type: 'تسوية بوتيكات', boutique: 'بوتيك لمى', amount: 40, due: null, overdue: false },
                { id: 8, reference: null, title: 'إيجار', type: 'إيجار', boutique: null, amount: 100, due: null, overdue: false },
            ],
            invoices: [],
            payroll: [],
            totals: { expenses: 140, invoices: 0, payroll: 0, total: 140, overdue: 0 },
        });

        render(<Dues />);

        expect(screen.getByTestId('due-boutique-7')).toHaveTextContent('بوتيك: بوتيك لمى');
        expect(screen.queryByTestId('due-boutique-8')).toBeNull();
    });
});
