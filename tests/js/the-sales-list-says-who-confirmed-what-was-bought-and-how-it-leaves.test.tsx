import { fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import OrdersIndex, { type SaleRow } from '@/Pages/Admin/Orders/Index';
import StoreBranchSettings from '@/Components/StoreBranchSettings';
import type { PaidCollection } from '@/Components/PaidLinkCollection';
import { pageProps } from './setup';

/**
 * «المبيعات» — من أكّد، وما اشتُري، وكيف يخرج — والجملُ من الخادم.
 *
 * ═══ ما يُحرس ═══
 *
 * ١) طلبُ الموقع يقول في «الموظف» من أكّده أو أنّه ينتظر أو أنّ المؤكِّدَ غيرُ
 *    معروف؛ وبيعةُ الصندوق كاشيرَها؛ والتحصيلُ منشئَ رابطه.
 * ٢) «منتجان — 5 قطع» والإضافةُ تحتها، و«لا توجد منتجات مرتبطة» لرابطٍ مستقلّ.
 * ٣) «التسليم / الاستلام» نوعُ التنفيذ دائمًا و«متأخّر» بجانبه لا بدلَه، و«لم
 *    يُحدد موعد»، و«مواعيد متعددة» بتفاصيلها، و«فروع متعددة» بأسمائها.
 * ٤) مقبضُ فرع طلبات الموقع لا يظهر لمتجرٍ بفرعٍ واحد.
 */

const products = (label: string, addons: string | null = null) => ({ lines: 2, pieces: 5, addons: addons ? 1 : 0, label, addons_label: addons });

const website: SaleRow = {
    id: 'WEB-1', customer: 'مريم', employee: 'الموقع الإلكتروني', branch: 'الخوير', items_count: 3, total: 25,
    payment: 'نقدي', status: 'مؤكّد', date: '2026-10-28 09:00', channel: 'website', channel_label: 'الموقع الإلكتروني',
    confirmation: { state: 'confirmed', by: 'أحمد', by_id: 7, at: '2026-10-28 10:30', label: 'أكّده: أحمد — 10:30 ص' },
    products: products('منتجان — 5 قطع', '+ إضافة واحدة'),
    handover: { kind: 'delivery', type: 'توصيل', when: '28/10/2026، 5:00 م – 7:00 م', label: 'توصيل — 28/10/2026، 5:00 م – 7:00 م' },
    late: true,
};

const waiting: SaleRow = {
    ...website, id: 'WEB-2', status: 'جديد', late: false,
    confirmation: { state: 'pending', by: null, by_id: null, at: null, label: 'بانتظار التأكيد' },
    handover: { kind: 'pickup', type: 'استلام من المحل', when: null, label: 'استلام من المحل — لم يُحدد موعد' },
};

const old: SaleRow = {
    ...website, id: 'WEB-3', late: false,
    confirmation: { state: 'unknown', by: null, by_id: null, at: null, label: 'مؤكّد — الموظف غير معروف' },
};

const till: SaleRow = {
    ...website, id: 'POS-1', employee: 'خالد', channel: 'pos', channel_label: 'نقطة البيع', confirmation: null,
    products: { lines: 1, pieces: 1, addons: 0, label: 'منتج واحد — قطعة واحدة', addons_label: null }, handover: null, late: false,
};

const link: PaidCollection & { kind: 'link' } = {
    kind: 'link', key: 'link:9', link_id: 9, id: 'RC-0009', reference: '7009', customer: 'وزارة الثقافة', type: 'تحصيل فاتورة',
    invoice: { id: 4, number: 'CI-0004' }, orders: ['CR-1', 'CR-2'], branch: 'فروع متعددة', branches: ['مسقط', 'الخوير'],
    creator: 'سارة', amount: 40, date: '2026-10-28 11:00', status: 'مدفوع', review: false, payment: { id: 3, number: 'RC-0009' },
    products: products('منتجان — 5 قطع'),
    handover: { kind: null, type: null, when: null, label: 'مواعيد متعددة', details: ['CR-1: توصيل — 23/10/2026، 10:00 ص', 'CR-2: استلام من المحل — 24/10/2026، 6:00 م'] },
};

const alone: PaidCollection & { kind: 'link' } = {
    ...link, key: 'link:10', link_id: 10, id: 'RC-0010', reference: '7010', type: 'تحصيل عبر رابط دفع', invoice: null, orders: [],
    branch: null, branches: [], products: null, handover: null, creator: null,
};

const base = globalThis.route as unknown as (...a: unknown[]) => string;
globalThis.route = ((...a: unknown[]) => (a.length ? base(...a) : { current: () => 'admin.orders.index' })) as never;

const draw = () => {
    for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    Object.assign(pageProps, {
        translations: {},
        context: {
            currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 },
            currencies: [], branches: [], branchId: null, branchName: '', tier: null, website: null,
        },
        auth: { abilities: ['*'], mayActions: [], isEmployee: false, user: { name: 'سعود', avatar: null, roleLabel: 'مدير نشاط', role: 'admin', businessId: 1 } },
        notifications: null, reportPages: [], locale: 'ar', csrf: 'x', flash: {},
        orders: [website, waiting, old, till, link, alone],
        pagination: { current_page: 1, last_page: 1, per_page: 10, total: 6, from: 1, to: 6, links: [] },
        filters: {}, sorts: [], totalAmount: 100, totalCount: 4, cancelledCount: 0, websiteCount: 3, websiteAmount: 75,
        statusOptions: [], channelOptions: [], mayDelete: true, mayOpenReceipts: true, withLinks: true,
        linkCollections: { count: 2, amount: 80 },
    });

    return render(<OrdersIndex />);
};

const rowOf = (text: string) => screen.getByText(text).closest('tr') as HTMLElement;

describe('من أكّد، وما اشتُري، وكيف يخرج', () => {
    beforeEach(() => {
        draw();
    });

    it('طلبُ الموقع يقول من أكّده أو أنّه ينتظر — ولا يُخترع اسمٌ لقديم', () => {
        expect(within(rowOf('WEB-1')).getByTestId('order-confirmation')).toHaveTextContent('أكّده: أحمد — 10:30 ص');
        expect(within(rowOf('WEB-1')).getByTestId('order-confirmation')).toHaveAttribute('data-state', 'confirmed');
        expect(within(rowOf('WEB-2')).getByTestId('order-confirmation')).toHaveTextContent('بانتظار التأكيد');
        expect(within(rowOf('WEB-3')).getByTestId('order-confirmation')).toHaveTextContent('مؤكّد — الموظف غير معروف');
        // وبيعةُ الصندوق كاشيرُها، ولا سطرَ تأكيد
        expect(rowOf('POS-1')).toHaveTextContent('خالد');
        expect(within(rowOf('POS-1')).queryByTestId('order-confirmation')).toBeNull();
        // والتحصيلُ منشئُ رابطه — لا مؤكِّدُ طلب
        expect(within(rowOf('RC-0009')).getByTestId('link-creator')).toHaveTextContent('سارة');
        expect(within(rowOf('RC-0009')).queryByTestId('order-confirmation')).toBeNull();
    });

    it('«منتجان — 5 قطع» والإضافةُ تحتها، والمستقلُّ بلا منتجات', () => {
        const bought = within(rowOf('WEB-1')).getByTestId('order-products');
        expect(bought).toHaveTextContent('منتجان — 5 قطع');
        expect(bought).toHaveTextContent('+ إضافة واحدة');
        expect(within(rowOf('RC-0010')).getByTestId('no-products')).toHaveTextContent('لا توجد منتجات مرتبطة');
    });

    it('التسليمُ نوعُه دائمًا و«متأخّر» بجانبه، و«لم يُحدد موعد»، والمواعيدُ والفروعُ المتعدّدة بتفاصيلها', () => {
        const late = within(rowOf('WEB-1')).getByTestId('handover');
        expect(late).toHaveTextContent('توصيل');
        expect(late).toHaveTextContent('متأخّر');
        expect(late).toHaveTextContent('28/10/2026، 5:00 م – 7:00 م');

        const pickup = within(rowOf('WEB-2')).getByTestId('handover');
        expect(pickup).toHaveAttribute('data-kind', 'pickup');
        expect(pickup).toHaveTextContent('استلام من المحل');
        expect(pickup).toHaveTextContent('لم يُحدد موعد');
        expect(pickup).not.toHaveTextContent('متأخّر');

        const many = within(rowOf('RC-0009')).getByTestId('handover');
        expect(many).toHaveTextContent('مواعيد متعددة');
        expect(within(many).getByTestId('handover-details')).toHaveTextContent('CR-2: استلام من المحل — 24/10/2026، 6:00 م');
        expect(within(rowOf('RC-0009')).getByTestId('link-branches')).toHaveTextContent('مسقط، الخوير');

        // ولا شيءَ يُخترع لبيعة المنضدة ولا لرابطٍ مستقلّ
        expect(within(rowOf('POS-1')).queryByTestId('handover')).toBeNull();
        expect(within(rowOf('RC-0010')).queryByTestId('handover')).toBeNull();
        expect(screen.getByText('التسليم / الاستلام')).toBeInTheDocument();
    });
});

describe('فرعُ طلبات الموقع وفرعُ الاستلام', () => {
    beforeEach(() => {
        for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
        Object.assign(pageProps, { translations: {}, locale: 'ar' });
    });

    it('لا يظهر لمتجرٍ بفرعٍ واحد', () => {
        render(<StoreBranchSettings branches={[{ id: 1, name: 'الخوير' }]} branchId="" onBranch={() => {}} choice={false} onChoice={() => {}} />);
        expect(screen.queryByTestId('store-branch-settings')).toBeNull();
    });

    it('ولمتجرٍ بفرعين: أوّلُ الفروع افتراضًا، ويُختار غيرُه', () => {
        const onBranch = vi.fn();
        render(<StoreBranchSettings branches={[{ id: 1, name: 'الخوير' }, { id: 2, name: 'مسقط' }]} branchId="" onBranch={onBranch} choice={false} onChoice={() => {}} />);

        const select = screen.getByTestId('store-branch') as HTMLSelectElement;
        expect(select.value).toBe('');
        expect(within(select).getByText('أول فروع المتجر')).toBeInTheDocument();
        fireEvent.change(select, { target: { value: '2' } });
        expect(onBranch).toHaveBeenCalledWith('2');
        expect(screen.getByText('يختار الزبون فرع الاستلام')).toBeInTheDocument();
    });
});
