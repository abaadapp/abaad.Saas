import { render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import PrepDetails from '@/Pages/Admin/Preparation/partials/PrepDetails';
import type { PrepOrder } from '@/Pages/Admin/Preparation/partials/types';
import { pageProps } from './setup';

/* الصفحةُ تُختبر لا القشرةُ حولها */
vi.mock('@/Layouts/AdminLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <>{children}</> }));

/**
 * سندُ التسليم — زرٌّ واحدٌ يقول الخادمُ أيَّهما.
 *
 * طلبُ موقعٍ في متجرٍ فُتح له سندُ الشريط (`delivery_paper` / `deliveryNote`
 * = `thermal`): «طباعة سند التسليم» إلى بابِ الشريط، ولا زرَّ A4. وكلُّ ما
 * سواه: «سند تسليم» A4 كما كان، ولا زرَّ شريط. ولا يُطبع شيءٌ بلا ضغطة.
 *
 * والقاعدةُ نفسُها في الخادم — حارسُها
 * `OnlyAnOpenedShopsWebsiteOrdersPrintTheDeliveryNoteOnTheStripTest`.
 */

const THERMAL = 'طباعة سند التسليم';
const A4 = 'سند تسليم';

let openMock: ReturnType<typeof vi.fn>;

beforeEach(() => {
    openMock = vi.fn();
    vi.stubGlobal('open', openMock);
});

afterEach(() => {
    vi.unstubAllGlobals();
    Object.assign(pageProps, {
        context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 } },
        auth: { abilities: [], mayActions: [] },
        order: undefined,
        deliveryNote: undefined,
    });
});

/* ═══════════════════════ لوحة التجهيز ═══════════════════════ */

const prep = (over: Partial<PrepOrder> = {}): PrepOrder => ({
    number: 'WEB-1',
    status: 'قيد التجهيز',
    channel: 'website',
    customer: 'سارة',
    fulfillment: 'delivery',
    scheduled_for: '2026-09-23 14:00',
    scheduled: { date: '2026-09-23', time: '14:00', day: 'today', minutes_left: 120 },
    overdue: false,
    recipient: null,
    recipient_phone: null,
    address: null,
    occasion: null,
    card_message: null,
    sender: null,
    hide_sender: false,
    delivery_notes: null,
    internal_notes: null,
    order_notes: null,
    branch: null,
    items: [{ id: 7, name: 'باقة', qty: 1, note: null, image: null, addons: [] }],
    next: ['جاهز'],
    checks: {},
    ...over,
});

const noop = () => {};

const details = (o: PrepOrder) =>
    render(
        <PrepDetails
            order={o}
            gone={false}
            onClose={noop}
            onMove={noop}
            onToggle={noop}
            deliveryNoteUrl={(n) => `/admin.preparation.deliveryNote/${n}`}
            timelineUrl={(n) => `/timeline/${n}`}
        />,
    );

describe('لوحة التجهيز', () => {
    it('طلبُ موقعٍ في متجرٍ فُتح له: «طباعة سند التسليم» إلى الشريط، ولا A4', () => {
        details(prep({ delivery_paper: 'thermal' }));

        const strip = screen.getByRole('link', { name: THERMAL });
        expect(strip.getAttribute('href')).toContain('/admin.preparation.deliveryNoteThermal/WEB-1');
        expect(screen.queryByRole('link', { name: A4 })).toBeNull();
        expect(screen.getAllByRole('link').filter((a) => /deliveryNote/.test(a.getAttribute('href') ?? ''))).toHaveLength(1);
        // ولا طباعةَ بلا ضغطة
        expect(openMock).not.toHaveBeenCalled();
    });

    /*
     * وطلبُ الموقع نفسُه في متجرٍ لم يُفتح له — والقناةُ وحدها لا تكفي: الشاشةُ
     * ترسم ما قاله الخادم، ولا تحكم من قناة الطلب.
     */
    it.each([
        ['a4' as const, 'website'],
        [undefined, 'website'],
        ['a4' as const, 'pos'],
    ])('وكلُّ ما سواه (%s · %s): «سند تسليم» A4 كما كان، ولا شريط', (paper, channel) => {
        details(prep({ delivery_paper: paper, channel }));

        expect(screen.getByRole('link', { name: A4 }).getAttribute('href')).toBe('/admin.preparation.deliveryNote/WEB-1');
        expect(screen.queryByRole('link', { name: THERMAL })).toBeNull();
        expect(document.body.innerHTML).not.toContain('deliveryNoteThermal');
    });
});

/* ═══════════════════════ صفحة الطلب ═══════════════════════ */

const detail = (over: Record<string, unknown> = {}) => ({
    id: 'WEB-7',
    customer: 'سارة',
    customer_language: null,
    customer_alert: null,
    employee: 'الموقع',
    branch: 'الرئيسي',
    date: '2026-09-29 10:00',
    payment: 'نقدي',
    payment_status: 'مدفوع',
    notes: null,
    recipient_name: null,
    recipient_phone: null,
    fulfillment_type: 'delivery',
    scheduled_for: null,
    occasion_type: null,
    card_message: null,
    card_align: null,
    card_file: null,
    card_file_name: null,
    sender_name: null,
    hide_sender: false,
    delivery_address: null,
    delivery_notes: null,
    internal_notes: null,
    status: 'مؤكد',
    next_statuses: [],
    channel: 'website',
    occasions: [],
    fulfillments: [],
    subtotal: 25,
    discount: 0,
    tax: 0,
    delivery: 0,
    total: 25,
    items: [{ id: 1, name: 'باقة', qty: 1, price: 25, total: 25 }],
    payment_methods: ['نقدي'],
    edits: [],
    ...over,
});

const orderScreen = async (deliveryNote: 'thermal' | 'a4' | undefined, order = detail()) => {
    Object.assign(pageProps, {
        order,
        deliveryNote,
        context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 }, peripherals: [] },
        auth: { abilities: ['orders'], mayActions: [] },
        invoiceEdit: { can: false, reason: null },
        otherBranch: null,
        paper: null,
        taxInvoice: { registered: false, ready: false },
        googleReview: { show: false, reason: null, requestedAt: null },
        storeReview: { show: false, reason: null, written: false },
        statusNotice: { event: null, show: false, reason: null, preparedAt: null },
    });
    const { default: OrderShow } = await import('@/Pages/Admin/Orders/Show');

    return render(<OrderShow />);
};

describe('صفحة الطلب', () => {
    it('طلبُ موقعٍ في متجرٍ فُتح له: «طباعة سند التسليم» برقم الطلب إلى الشريط، ولا A4', async () => {
        await orderScreen('thermal');

        const strip = screen.getByRole('link', { name: THERMAL });
        expect(strip.getAttribute('href')).toContain('/admin.orders.deliveryNoteThermal/WEB-7');
        expect(screen.queryByRole('link', { name: A4 })).toBeNull();
        expect(document.body.innerHTML).not.toContain('/admin.orders.deliveryNote/');
        expect(openMock).not.toHaveBeenCalled();
    });

    it.each([
        ['a4' as const, 'website'],
        [undefined, 'website'],
        ['a4' as const, 'pos'],
    ])('وكلُّ ما سواه (%s · %s): «سند تسليم» A4 كما كان، ولا شريط', async (paper, channel) => {
        await orderScreen(paper, detail({ channel, id: 'INV-8' }));

        expect(screen.getByRole('link', { name: A4 }).getAttribute('href')).toContain('/admin.orders.deliveryNote/INV-8');
        expect(screen.queryByRole('link', { name: THERMAL })).toBeNull();
        expect(document.body.innerHTML).not.toContain('deliveryNoteThermal');
    });
});
