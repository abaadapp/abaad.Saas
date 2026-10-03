import { render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { pageProps } from './setup';

/* الصفحةُ تُختبر لا القشرةُ حولها */
vi.mock('@/Layouts/AdminLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <>{children}</> }));

/**
 * كتلةُ «🎁 طلب هدية» في شاشة الطلب — ما يحتاجه من يجهّز الهديّة في موضعٍ واحد.
 *
 * ═══ ما يُحرس ═══
 *
 *   - المشتري يُرى ولو طلب «لا تذكر اسمي»: الإخفاءُ عن المستلِم وحده.
 *   - رقمُ المشتري حين يُرسله الخادم وحده — لمن يرى العملاء (`GiftOrders::buyerPhone`).
 *   - المستلِمُ والمناسبةُ والموقعُ وحالُ انتظاره والرسالةُ وزرُّ واتساب فيها،
 *     ولا تُعاد كتلتا «المستلِم» و«المناسبة والبطاقة» تحتها.
 *   - والطلبُ العاديّ كما كان: لا كتلةَ هديّة.
 *
 * وما يُرسله الخادمُ ومن يراه في `AGiftsInvoiceStaysTheBuyersPaperTest`.
 */

const detail = (over: Record<string, unknown> = {}) => ({
    id: 'WEB-9',
    customer: 'مريم المُهدية',
    customer_language: null,
    customer_alert: null,
    employee: 'الموقع',
    branch: 'الرئيسي',
    date: '2027-02-01 10:00',
    payment: 'نقدي',
    payment_status: 'غير مدفوع',
    notes: null,
    recipient_name: 'سارة',
    recipient_phone: '96899110002',
    fulfillment_type: 'delivery',
    scheduled_for: '2027-02-03T09:00',
    occasion_type: 'birthday',
    occasion_label: 'عيد ميلاد',
    card_message: 'كل عام وأنتِ بخير',
    card_align: 'center',
    card_file: null,
    card_file_name: null,
    sender_name: 'مريم المُهدية',
    hide_sender: true,
    is_gift: true,
    recipient_location_mode: 'contact_recipient',
    location_label: 'الموقع: بانتظار التواصل مع المستلم',
    awaiting_location: true,
    buyer_phone: '96899110001',
    delivery_address: null,
    delivery_notes: '9 ص – 12 م',
    internal_notes: null,
    status: 'قيد الانتظار',
    next_statuses: [],
    channel: 'website',
    occasions: [],
    fulfillments: [{ value: 'delivery', label: 'توصيل' }],
    subtotal: 20,
    discount: 0,
    tax: 0,
    delivery: 2,
    total: 22,
    items: [{ id: 1, name: 'باقة', qty: 1, price: 20, total: 20 }],
    payment_methods: ['نقدي'],
    edits: [],
    ...over,
});

const orderScreen = async (order: Record<string, unknown>) => {
    Object.assign(pageProps, {
        order,
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

describe('كتلةُ طلب الهديّة', () => {
    it('تجمع المشتري والمُهدي والمستلِم والموقع والرسالة وزرَّ التواصل', async () => {
        await orderScreen(detail());
        const block = within(screen.getByTestId('gift-order-block'));

        expect(block.getByText(/طلب هدية/)).toBeTruthy();
        // «لا تذكر اسمي» لا يُخفي المشتري عن التاجر
        expect(block.getAllByText('مريم المُهدية')).toHaveLength(2);
        expect(block.getByText('96899110001')).toBeTruthy();
        expect(block.getByText('مخفيّ عن المستلِم')).toBeTruthy();
        expect(block.getByText('سارة')).toBeTruthy();
        expect(block.getByText('96899110002')).toBeTruthy();
        expect(block.getByText('عيد ميلاد')).toBeTruthy();
        expect(block.getByTestId('gift-location-state').textContent).toBe('الموقع: بانتظار التواصل مع المستلم');
        expect(block.getByText('كل عام وأنتِ بخير')).toBeTruthy();
        expect(block.getByTestId('contact-recipient')).toBeTruthy();

        // ولا تُعاد تحتها: زرٌّ واحد، ورسالةٌ واحدة، ولا كتلةَ «المستلِم»
        expect(screen.getAllByTestId('contact-recipient')).toHaveLength(1);
        expect(screen.getAllByText('كل عام وأنتِ بخير')).toHaveLength(1);
        expect(screen.queryByText('المناسبة والبطاقة')).toBeNull();
    });

    it('رقمُ المشتري لا يُرسم إن لم يُرسله الخادم — والمستلِمُ يبقى', async () => {
        await orderScreen(detail({ buyer_phone: null, hide_sender: false, awaiting_location: false, delivery_address: 'العذيبة، بيت ١٢', location_label: 'تواصلوا مع المستلم للحصول على الموقع' }));
        const block = within(screen.getByTestId('gift-order-block'));

        expect(block.queryByText('هاتف المشتري')).toBeNull();
        expect(block.getByText('ظاهر للمستلِم')).toBeTruthy();
        expect(block.getByText('96899110002')).toBeTruthy();
        expect(block.getByText('العذيبة، بيت ١٢')).toBeTruthy();
        expect(block.queryByTestId('contact-recipient')).toBeNull();
    });

    it('والطلبُ العاديّ كما كان — بلا كتلة هديّة', async () => {
        await orderScreen(detail({ is_gift: false, buyer_phone: null, location_label: null, awaiting_location: false, hide_sender: false }));

        expect(screen.queryByTestId('gift-order-block')).toBeNull();
        expect(screen.getByText('المناسبة والبطاقة')).toBeTruthy();
        expect(screen.getByText('كل عام وأنتِ بخير')).toBeTruthy();
    });
});
