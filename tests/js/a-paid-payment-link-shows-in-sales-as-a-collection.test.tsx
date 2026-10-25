import { render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it } from 'vitest';

import OrdersIndex, { type Collection } from '@/Pages/Admin/Orders/Index';
import { CUSTOMER_INVOICE_TABS } from '@/Components/SectionTabs';
import type { Order } from '@/types/models';
import { pageProps } from './setup';

/**
 * «رابط دفع» في «المبيعات» — تحصيلٌ يُقرأ، لا طلبٌ يُضاف.
 *
 * ═══ ما يُحرس ═══
 *
 * ١) صفُّ التحصيل يقول سندَه ومرجعَ Paymob والعميلَ والمبلغَ ويومَ الدفع وحالَه،
 *    وعن أيّ فاتورةٍ (وطلبِها) دُفع — والرابطُ بلا فاتورةٍ «تحصيل عبر رابط دفع».
 * ٢) لا «حذف» ولا مُرشِّحاتِ طلب، وسندُ القبض لمن يفتح «المالية» وحده.
 * ٣) المحصَّلُ يُقال «المحصّل» لا «الإجمالي»، و«كل المصادر» يذكره خارج إجماليّه.
 * ٤) «فواتير العملاء» تبويبٌ واحد — فلا شريطَ تبويبات.
 */

const pos: Order = {
    id: 'POS-9', customer: 'سالم', employee: 'خالد', branch: 'الخوير',
    items_count: 1, total: 20, payment: 'نقدي', status: 'مكتمل', date: '2026-10-20 11:00',
    channel: 'pos', channel_label: 'نقطة البيع',
};

const onInvoice: Collection = {
    key: 'link:1', id: 'RC-0001', reference: '7001', customer: 'وزارة الثقافة', type: 'تحصيل فاتورة',
    invoice: { id: 4, number: 'CI-0004' }, orders: ['CR-1'], amount: 40, date: '2026-10-20 10:00',
    status: 'مدفوع', review: false, payment: { id: 9, number: 'RC-0001' },
};

const standalone: Collection = {
    ...onInvoice, key: 'link:2', id: 'RC-0002', reference: '7002', customer: 'شركة الورد', type: 'تحصيل عبر رابط دفع',
    invoice: null, orders: [], amount: 25, payment: { id: 10, number: 'RC-0002' },
};

const base = globalThis.route as unknown as (...a: unknown[]) => string;
globalThis.route = ((...a: unknown[]) =>
    a.length ? base(...a) : { current: () => 'admin.orders.index' }) as never;

const channelOptions = [
    { value: 'website', label: 'الموقع الإلكتروني' },
    { value: 'pos', label: 'نقطة البيع' },
    { value: 'unknown', label: 'غير محدّدة' },
    { value: 'payment_link', label: 'رابط دفع' },
];

const draw = (over: Record<string, unknown> = {}) => {
    Object.assign(pageProps, {
        translations: {},
        context: {
            currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 },
            currencies: [], branches: [], branchId: null, branchName: '', tier: null, website: null,
        },
        auth: { abilities: ['*'], mayActions: [], isEmployee: false, user: { name: 'سعود', avatar: null, roleLabel: 'مدير نشاط', role: 'admin', businessId: 1 } },
        notifications: null, reportPages: [], locale: 'ar', csrf: 'x', flash: {},
        orders: [pos],
        pagination: { current_page: 1, last_page: 1, per_page: 10, total: 1, from: 1, to: 1, links: [] },
        filters: {},
        sorts: [],
        totalAmount: 20,
        totalCount: 1,
        cancelledCount: 0,
        websiteCount: 0,
        websiteAmount: 0,
        statusOptions: [{ value: 'مكتمل', label: 'مكتمل' }],
        channelOptions,
        mayDelete: true,
        ...over,
    });

    return render(<OrdersIndex />);
};

const links = (over: Record<string, unknown> = {}) =>
    draw({
        orders: [],
        collections: [onInvoice, standalone],
        collectionTotals: {
            count: 2, amount: 65, on_invoices: { count: 1, amount: 40 }, standalone: { count: 1, amount: 25 },
        },
        mayOpenReceipts: true,
        filters: { channel: 'payment_link' },
        totalAmount: 0,
        totalCount: 0,
        mayDelete: false,
        ...over,
    });

const rowOf = (text: string) => screen.getByText(text).closest('tr') as HTMLElement;

describe('رابط دفع في المبيعات', () => {
    beforeEach(() => {
        for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    });

    it('صفُّ التحصيل يقول سندَه ومرجعَه وعن أيّ فاتورةٍ وطلبٍ دُفع', () => {
        links();

        const row = rowOf('RC-0001');
        expect(row).toHaveTextContent('Paymob · 7001');
        expect(row).toHaveTextContent('وزارة الثقافة');
        expect(row).toHaveTextContent('رابط دفع');
        expect(within(row).getByText('CI-0004').closest('a')).toHaveAttribute('href', '/admin.customerInvoices.show/4');
        expect(row).toHaveTextContent('عن الطلب CR-1');
        expect(row).toHaveTextContent('2026-10-20 10:00');
        expect(row).toHaveTextContent('مدفوع');

        const alone = rowOf('RC-0002');
        expect(within(alone).getByTestId('collection-type')).toHaveTextContent('تحصيل عبر رابط دفع');
        expect(within(alone).queryByRole('link', { name: /CI-/ })).toBeNull();
    });

    it('لا «حذف» ولا مُرشِّحاتِ طلب — وسندُ القبض لمن يفتح «المالية»', () => {
        links();

        expect(screen.queryByTestId('order-delete')).toBeNull();
        expect(screen.queryByText('كل الحالات')).toBeNull();
        expect(screen.queryByText('كل وسائل الدفع')).toBeNull();
        expect(screen.queryByText('كل المواعيد')).toBeNull();
        expect(within(rowOf('RC-0001')).getByText('سند القبض').closest('a')).toHaveAttribute('href', '/admin.customerPayments.show/9');
    });

    it('ومن لا «مالية» له لا يُعطى بابَ السند', () => {
        links({ mayOpenReceipts: false });

        expect(screen.queryByText('سند القبض')).toBeNull();
    });

    it('المحصَّلُ يُقال «المحصّل» ولا يُقرأ إجماليَّ مبيعات', () => {
        links();

        const totals = screen.getByTestId('collection-totals');
        expect(totals).toHaveTextContent('تحصيلات عبر روابط الدفع: 2');
        expect(totals).toHaveTextContent('المحصّل');
        expect(totals).toHaveTextContent('لا يُضاف هذا إلى إجمالي المبيعات');
        expect(totals).not.toHaveTextContent('الإجمالي:');
    });

    it('و«كل المصادر» يذكر التحصيلات خارج إجماليّه ويقود إلى مُرشِّحها', () => {
        draw({ linkCollections: { count: 2, amount: 65 }, filters: { q: 'وزارة', status: 'مكتمل' } });

        const line = screen.getByTestId('link-collections');
        expect(line).toHaveTextContent('ليست ضمن الإجمالي');
        expect(line).toHaveTextContent('2');
        // البحثُ يُحمل، وحالةُ الطلب لا تُسأل عن تحصيل
        const href = within(line).getByText('عرضها').closest('a')?.getAttribute('href') ?? '';
        expect(decodeURI(href)).toBe('/admin.orders.index/وزارة/payment_link');
        expect(rowOf('POS-9')).toBeInTheDocument();
    });

    it('ولا سطرَ حين لا تحصيل', () => {
        draw({ linkCollections: null });

        expect(screen.queryByTestId('link-collections')).toBeNull();
    });
});

describe('فواتير العملاء', () => {
    it('تبويبٌ واحد: «فواتير العملاء» وحدها', () => {
        expect(CUSTOMER_INVOICE_TABS.map((t) => t.label)).toEqual(['فواتير العملاء']);
        expect(CUSTOMER_INVOICE_TABS[0].routeName).toBe('admin.customerInvoices.index');
    });
});
