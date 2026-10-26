import { render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it } from 'vitest';

import OrdersIndex, { type SaleRow } from '@/Pages/Admin/Orders/Index';
import OrderCollection from '@/Pages/Admin/Orders/Collection';
import PaidLinkCollectionCard, { type PaidCollection } from '@/Components/PaidLinkCollection';
import { pageProps } from './setup';

/**
 * «المبيعات ← كل المصادر» — التحصيلُ صفٌّ بين الطلبات، و«عرض» يفتح فاتورتَه الأصليّة.
 *
 * ═══ ما يُحرس ═══
 *
 * ١) صفُّ التحصيل يقول «رابط دفع» وسندَه ومرجعَ Paymob، ومبلغُه «محصّل» لا إجماليُّ بيع.
 * ٢) «عرض» المرتبطِ بفاتورةٍ يفتح الفاتورةَ نفسَها (ومعها التحصيل)، والمستقلُّ صفحةَ
 *    تحصيله — لا سندَ القبض. والسندُ رابطٌ ثانٍ لمن يفتح «المالية» وحده، ولا «حذف».
 * ٣) «إجمالي المبيعات» ومجموعُ التحصيلات كلٌّ باسمه.
 * ٤) صفحةُ التحصيل المستقلّ تقول إنّه لا فاتورةَ له، وبطاقتُه تقول مبلغَه ويومَه
 *    ومرجعَه وطلباتِه روابطَ — والورقةُ تحمل نشاطَ الصفحة.
 */

const pos: SaleRow = {
    id: 'POS-9', customer: 'سالم', employee: 'خالد', branch: 'الخوير',
    items_count: 1, total: 20, payment: 'نقدي', status: 'مكتمل', date: '2026-10-20 11:00',
    channel: 'pos', channel_label: 'نقطة البيع',
};

const onInvoice: PaidCollection = {
    key: 'link:1', link_id: 1, id: 'RC-0001', reference: '7001', customer: 'وزارة الثقافة', type: 'تحصيل فاتورة',
    invoice: { id: 4, number: 'CI-0004' }, orders: ['CR-1'], branch: 'مسقط', amount: 40, date: '2026-10-20 10:00',
    status: 'مدفوع', review: false, payment: { id: 9, number: 'RC-0001' },
};

const standalone: PaidCollection = {
    ...onInvoice, key: 'link:2', link_id: 2, id: 'RC-0002', reference: '7002', customer: 'شركة الورد',
    type: 'تحصيل عبر رابط دفع', invoice: null, orders: [], branch: null, amount: 25, payment: { id: 10, number: 'RC-0002' },
};

const base = globalThis.route as unknown as (...a: unknown[]) => string;
globalThis.route = ((...a: unknown[]) => (a.length ? base(...a) : { current: () => 'admin.orders.index' })) as never;

const shared = {
    translations: {},
    context: {
        currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 },
        currencies: [], branches: [], branchId: null, branchName: '', tier: null, website: null,
    },
    auth: { abilities: ['*'], mayActions: [], isEmployee: false, user: { name: 'سعود', avatar: null, roleLabel: 'مدير نشاط', role: 'admin', businessId: 1 } },
    notifications: null, reportPages: [], locale: 'ar', csrf: 'x', flash: {},
};

const draw = (over: Record<string, unknown> = {}) => {
    Object.assign(pageProps, shared, {
        orders: [{ ...onInvoice, kind: 'link' }, pos, { ...standalone, kind: 'link' }],
        pagination: { current_page: 1, last_page: 1, per_page: 10, total: 3, from: 1, to: 3, links: [] },
        filters: {},
        sorts: [],
        totalAmount: 20,
        totalCount: 1,
        cancelledCount: 0,
        websiteCount: 0,
        websiteAmount: 0,
        statusOptions: [{ value: 'مكتمل', label: 'مكتمل' }],
        channelOptions: [{ value: 'pos', label: 'نقطة البيع' }, { value: 'payment_link', label: 'رابط دفع' }],
        mayDelete: true,
        mayOpenReceipts: true,
        withLinks: true,
        linkCollections: { count: 2, amount: 65 },
        ...over,
    });

    return render(<OrdersIndex />);
};

const rowOf = (text: string) => screen.getByText(text).closest('tr') as HTMLElement;
const viewOf = (row: HTMLElement) => within(row).getByText('عرض').closest('a')?.getAttribute('href');

describe('التحصيلُ صفٌّ بين الطلبات', () => {
    beforeEach(() => {
        for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    });

    it('صفُّ التحصيل يقول مصدرَه وسندَه ومرجعَه، ومبلغُه محصّلٌ لا إجماليّ', () => {
        draw();

        const row = rowOf('RC-0001');
        expect(row).toHaveTextContent('Paymob · 7001');
        expect(row).toHaveTextContent('رابط دفع');
        expect(row).toHaveTextContent('تحصيل فاتورة CI-0004');
        expect(row).toHaveTextContent('2026-10-20 10:00');
        expect(within(row).getByTestId('collection-amount')).toHaveTextContent('محصّل');
        expect(within(rowOf('RC-0002')).getByTestId('collection-type')).toHaveTextContent('دون فاتورة');
        // والطلبُ كما كان
        expect(rowOf('POS-9')).toHaveTextContent('نقطة البيع');
        expect(within(rowOf('POS-9')).queryByTestId('collection-amount')).toBeNull();
    });

    it('«عرض» يفتح الفاتورةَ الأصليّة — والمستقلُّ صفحةَ تحصيله — لا سندَ القبض', () => {
        draw();

        expect(viewOf(rowOf('RC-0001'))).toBe('/admin.customerInvoices.show/4/1');
        expect(viewOf(rowOf('RC-0002'))).toBe('/admin.orders.collection/2');
        expect(viewOf(rowOf('POS-9'))).toBe('/admin.orders.show/POS-9');
        // والسندُ رابطٌ ثانٍ منفصل
        expect(within(rowOf('RC-0001')).getByText('سند القبض').closest('a')).toHaveAttribute('href', '/admin.customerPayments.show/9');
    });

    it('لا «حذف» لتحصيل، ولا سندَ لمن لا «مالية» له', () => {
        draw({ mayOpenReceipts: false });

        expect(within(rowOf('RC-0001')).queryByTestId('order-delete')).toBeNull();
        expect(within(rowOf('POS-9')).getByTestId('order-delete')).toBeInTheDocument();
        expect(screen.queryByText('سند القبض')).toBeNull();
        expect(viewOf(rowOf('RC-0001'))).toBe('/admin.customerInvoices.show/4/1');
    });

    it('«إجمالي المبيعات» ومجموعُ التحصيلات كلٌّ باسمه', () => {
        draw();

        expect(screen.getByTestId('sales-total').parentElement).toHaveTextContent('إجمالي المبيعات:');
        expect(screen.getByTestId('sales-total')).toHaveTextContent('20.000');
        const line = screen.getByTestId('link-collections');
        expect(line).toHaveTextContent('مجموع التحصيلات عبر روابط الدفع');
        expect(line).toHaveTextContent('وليست من إجمالي المبيعات');
        expect(line).toHaveTextContent('65.000');
    });

    it('ومتجرٌ بلا روابط — أو مُرشِّحٌ يسأل عن طلبات — كما كان', () => {
        draw({ withLinks: false, orders: [pos], linkCollections: null });

        expect(screen.queryByText(/إجمالي المبيعات/)).toBeNull();
        expect(screen.getAllByText(/الإجمالي/).length).toBeGreaterThan(0);
        expect(screen.queryByTestId('link-collections')).toBeNull();
        expect(screen.queryByTestId('collection-amount')).toBeNull();
    });

    it('ومُرشِّحُ «رابط دفع» مستقلٌّ — و«عرض» فيه يفتح الفاتورة أيضًا', () => {
        draw({
            orders: [],
            collections: [onInvoice, standalone],
            collectionTotals: { count: 2, amount: 65, on_invoices: { count: 1, amount: 40 }, standalone: { count: 1, amount: 25 } },
            filters: { channel: 'payment_link' },
            withLinks: false,
            linkCollections: null,
            mayDelete: false,
        });

        expect(viewOf(rowOf('RC-0001'))).toBe('/admin.customerInvoices.show/4/1');
        expect(viewOf(rowOf('RC-0002'))).toBe('/admin.orders.collection/2');
    });
});

describe('صفحةُ التحصيل وبطاقتُه', () => {
    beforeEach(() => {
        for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
        Object.assign(pageProps, shared);
    });

    it('المستقلُّ يقول صراحةً إنّه لا فاتورةَ له — ويقول ما دُفع', () => {
        Object.assign(pageProps, { collection: { ...standalone, link_reference: 'PL-2', description: 'دفعة مقدّمة' }, mayOpenReceipts: true });
        render(<OrderCollection />);

        expect(screen.getByTestId('standalone-note')).toHaveTextContent('لا توجد فاتورة مرتبطة بهذا التحصيل');
        const card = screen.getByTestId('paid-collection');
        expect(card).toHaveTextContent('25.000');
        expect(card).toHaveTextContent('2026-10-20 10:00');
        expect(card).toHaveTextContent('7002');
        expect(card).toHaveTextContent('PL-2');
        expect(card).toHaveTextContent('RC-0002');
        expect(card).toHaveTextContent('دفعة مقدّمة');
        expect(within(card).getByTestId('collection-no-invoice')).toBeInTheDocument();
        // وطباعةُ السند تحمل نشاطَ الصفحة
        expect(within(card).getByText('طباعة سند القبض').closest('a')?.getAttribute('href')).toContain('expected_business_id=1');
    });

    it('المرتبطُ بفاتورةٍ يقول فاتورتَه وطلباتِه روابطَ — ولا سندَ بلا «مالية»', () => {
        render(<PaidLinkCollectionCard collection={onInvoice} mayOpenReceipts={false} />);

        const card = screen.getByTestId('paid-collection');
        expect(card).toHaveTextContent('CI-0004');
        expect(within(card).queryByTestId('collection-no-invoice')).toBeNull();
        expect(within(card).getByText('CR-1').closest('a')).toHaveAttribute('href', '/admin.orders.show/CR-1');
        expect(within(card).queryByText('سند القبض')).toBeNull();
        expect(card).toHaveTextContent('لا يُضاف هذا إلى إجمالي المبيعات');
    });
});
