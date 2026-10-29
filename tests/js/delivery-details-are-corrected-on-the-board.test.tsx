import { useState } from 'react';
import { render, renderHook, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import PrepDetails from '@/Pages/Admin/Preparation/partials/PrepDetails';
import useChangedPapersForgotten from '@/hooks/useChangedPapersForgotten';
import type { PrepOrder } from '@/Pages/Admin/Preparation/partials/types';
import type { PreparationData } from '@/Components/CustomerInvoicePreparationFields';
import { forgetPreparedReceipts, preparedReceipt, prepareReceipt } from '@/lib/printing/share-print';
import { pageProps } from './setup';

/**
 * «تعديل تفاصيل التسليم» في تفاصيل مهمّة الفاتورة — من جهة الشاشة.
 *
 * ═══ ما يُحرس ═══
 *
 *   - الزرُّ لمن يرسل له الخادمُ ما يملأ النموذج (`details`) وفروعَه وحدَه،
 *     ولمهمّة فاتورةٍ لا لطلب.
 *   - النموذجُ مملوءٌ بما حُفظ، ويُرسل حقولَ التجهيز وحدها إلى بابها باسمه —
 *     لا مبلغَ ولا بندَ ولا سعر.
 *   - وبعد الحفظ يُنسى ملفُّ هذه المهمّة المجهَّزُ للمشاركة، وما سواه يبقى.
 *
 * والخادمُ يحرس ما يقع (`DeliveryDetailsAreCorrectedOnTheBoardTest`).
 */

const sent: { url: string; data: Record<string, unknown> }[] = [];

vi.mock('@inertiajs/react', async () => {
    const actual = await vi.importActual<Record<string, unknown>>('@inertiajs/react');

    const useForm = (initial: Record<string, unknown>) => {
        const [data, setData] = useState(initial);
        const [shape, setShape] = useState<((d: Record<string, unknown>) => Record<string, unknown>) | null>(null);

        return {
            data,
            errors: {} as Record<string, string>,
            processing: false,
            setData: (key: string, value: unknown) => setData((d) => ({ ...d, [key]: value })),
            transform: (fn: (d: Record<string, unknown>) => Record<string, unknown>) => setShape(() => fn),
            post: (url: string, options?: { onSuccess?: () => void }) => {
                sent.push({ url, data: shape ? shape(data) : data });
                options?.onSuccess?.();
            },
        };
    };

    return {
        ...actual,
        usePage: () => ({ props: pageProps, url: '/', component: 'Test' }),
        router: { post: vi.fn(), get: vi.fn(), visit: vi.fn(), reload: vi.fn(), on: vi.fn(() => () => {}) },
        useForm,
    };
});

const DETAILS: PreparationData = {
    branch_id: 4,
    branch: 'فرع الخوير',
    fulfillment_type: 'delivery',
    scheduled_for: '2026-10-19T16:00',
    recipient_name: 'مريم',
    recipient_phone: '99887766',
    delivery_address: 'الخوير',
    delivery_notes: '',
    internal_notes: 'تغليف أبيض',
    is_gift: false,
    occasion_type: '',
    occasion_text: '',
    card_message: '',
    sender_name: '',
    hide_sender: false,
    card_identity_confirmed: false,
};

const OPTIONS = {
    branches: [
        { id: 4, name: 'فرع الخوير' },
        { id: 5, name: 'فرع صحار' },
    ],
    occasions: [{ value: 'birthday', label: 'عيد ميلاد' }],
};

const task = (over: Partial<PrepOrder> = {}): PrepOrder => ({
    key: 'customer-invoice:12',
    kind: 'invoice',
    prep_id: 12,
    source_label: 'فاتورة عميل',
    number: 'CINV-000012',
    status: 'مؤكّد',
    channel: 'customer_invoice',
    delivery_paper: 'thermal',
    customer: 'شركة الضيافة',
    total: 105,
    payment_status: 'مدفوعة',
    payment_verified: true,
    payment_source: 'عبر رابط الدفع',
    fulfillment: 'delivery',
    scheduled_for: '2026-10-19 16:00',
    scheduled: { date: '2026-10-19', time: '16:00', day: 'today', minutes_left: 300 },
    overdue: false,
    recipient: 'مريم',
    recipient_phone: '99887766',
    address: 'الخوير',
    occasion: null,
    card_message: null,
    sender: null,
    hide_sender: false,
    delivery_notes: null,
    internal_notes: 'تغليف أبيض',
    order_notes: null,
    branch: 'فرع الخوير',
    items: [{ id: 3, name: 'باقة ورد أبيض', qty: 2, note: null, image: null, addons: [] }],
    next: ['قيد التجهيز'],
    checks: {},
    print: { status: 'printed', label: 'طُبعت', error: null },
    details: DETAILS,
    ...over,
});

const open = (o: PrepOrder, options: typeof OPTIONS | null = OPTIONS) =>
    render(
        <PrepDetails
            order={o}
            gone={false}
            onClose={() => {}}
            onMove={() => {}}
            onToggle={() => {}}
            deliveryNoteUrl={(n) => `/note/${n}`}
            timelineUrl={(n) => `/timeline/${n}`}
            detailsOptions={options}
        />,
    );

const PDF = new Blob(['%PDF-1.7 paper'], { type: 'application/pdf' });

beforeEach(() => {
    sent.length = 0;
    forgetPreparedReceipts();
    vi.stubGlobal(
        'fetch',
        vi.fn(async () => ({
            ok: true,
            status: 200,
            headers: { get: (h: string) => (h.toLowerCase() === 'content-type' ? 'application/pdf' : null) },
            blob: async () => PDF,
            json: async () => ({ events: [] }),
        })),
    );
});

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('زرُّ «تعديل تفاصيل التسليم»', () => {
    it('لمن يرسل له الخادمُ النموذجَ وفروعَه — وما سواه لا زرّ', () => {
        const { unmount } = open(task());
        expect(screen.getByTestId('prep-edit-details')).toHaveTextContent('تعديل تفاصيل التسليم');
        unmount();

        // بلا الفعل: الخادمُ لا يرسل `details` ولا الفروع
        const second = open(task({ details: null }), null);
        expect(screen.queryByTestId('prep-edit-details')).toBeNull();
        second.unmount();

        // والطلبُ ليس مهمّةَ فاتورة — لا يُعدَّل من هنا
        open(task({ kind: 'order', key: 'order:CINV-000012', prep_id: undefined }));
        expect(screen.queryByTestId('prep-edit-details')).toBeNull();
    });

    it('يفتح النموذجَ مملوءًا، ويُرسل حقولَ التجهيز وحدها إلى بابها', async () => {
        open(task());
        await userEvent.click(screen.getByTestId('prep-edit-details'));

        const recipient = screen.getByDisplayValue('مريم');
        await userEvent.clear(recipient);
        await userEvent.type(recipient, 'نورة');
        await userEvent.click(screen.getByTestId('prep-edit-details-save'));

        expect(sent).toHaveLength(1);
        expect(sent[0].url).toBe('/admin.preparation.invoiceDetails/12');

        const body = sent[0].data.preparation as Record<string, unknown>;
        expect(body.recipient_name).toBe('نورة');
        expect(body.branch_id).toBe('4');
        expect(body.scheduled_for).toBe('2026-10-19T16:00');
        expect(body.enabled).toBe(true);
        // ولا مالَ في الطلب
        for (const money of ['total', 'subtotal', 'tax_total', 'items', 'unit_price', 'discount', 'customer_id']) {
            expect(Object.keys(sent[0].data)).not.toContain(money);
            expect(Object.keys(body)).not.toContain(money);
        }

        // وأُغلق النموذج
        await waitFor(() => expect(screen.queryByTestId('prep-edit-details-dialog')).toBeNull());
    });

    it('وبعد الحفظ تُنسى أوراقُ هذه المهمّة المجهَّزة للمشاركة — وغيرُها يبقى', async () => {
        const mine = '/admin/preparation/customer-invoices/12/internal/pdf';
        const note = '/admin/preparation/customer-invoices/12/delivery-note/thermal';
        const neighbour = '/admin/preparation/customer-invoices/120/internal/pdf';
        for (const url of [mine, note, neighbour]) await prepareReceipt(url, 'CINV-000012');
        expect(preparedReceipt(mine)).not.toBeNull();

        open(task());
        await userEvent.click(screen.getByTestId('prep-edit-details'));
        await userEvent.click(screen.getByTestId('prep-edit-details-save'));

        expect(preparedReceipt(mine)).toBeNull();
        expect(preparedReceipt(note)).toBeNull();
        expect(preparedReceipt(neighbour)).not.toBeNull();
    });
});

describe('ما جُهّز وهو يتنزّل ثمّ نُسي', () => {
    it('لا يُحفظ — فلا تُشارَك نسخةٌ من قبل التعديل', async () => {
        const url = '/admin/preparation/customer-invoices/12/internal/pdf';
        const pending = prepareReceipt(url, 'CINV-000012');
        forgetPreparedReceipts((u) => u === url);
        await pending;

        expect(preparedReceipt(url)).toBeNull();

        // والتنزيلُ التالي يُحفظ كما كان
        await prepareReceipt(url, 'CINV-000012');
        expect(preparedReceipt(url)).not.toBeNull();
    });
});

describe('مهمّةٌ عُدّلت من جهازٍ آخر', () => {
    const mine = '/admin/preparation/customer-invoices/12/delivery-note/thermal';
    const neighbour = '/admin/preparation/customer-invoices/120/delivery-note/thermal';

    it('تُنسى أوراقُها المجهَّزةُ هنا حين يقول الاستطلاعُ إنّ ما يُطبع تبدّل — وغيرُها يبقى', async () => {
        for (const url of [mine, neighbour]) await prepareReceipt(url, 'CINV-000012');

        const other = task({ key: 'customer-invoice:120', prep_id: 120 });
        const { rerender } = renderHook(({ orders }) => useChangedPapersForgotten(orders), {
            initialProps: { orders: [task(), other] },
        });
        // أوّلُ ما تراه الشاشةُ خطُّ أساس
        expect(preparedReceipt(mine)).not.toBeNull();

        // استطلاعٌ لم يتبدّل فيه إلّا ما لا يُطبع — الحالُ وورقةُ الصندوق والوقت
        rerender({ orders: [task({ status: 'قيد التجهيز', print: null, overdue: true }), other] });
        expect(preparedReceipt(mine)).not.toBeNull();

        // زميلٌ غيّر المستلِمَ من المكتب
        rerender({ orders: [task({ recipient: 'نورة' }), other] });
        expect(preparedReceipt(mine)).toBeNull();
        expect(preparedReceipt(neighbour)).not.toBeNull();
    });

    it('والمهمّةُ التي غابت عن المرشّح ثمّ عادت معدَّلةً تُنسى كذلك', async () => {
        await prepareReceipt(mine, 'CINV-000012');

        const { rerender } = renderHook(({ orders }) => useChangedPapersForgotten(orders), {
            initialProps: { orders: [task()] },
        });
        rerender({ orders: [] });
        expect(preparedReceipt(mine)).not.toBeNull();

        rerender({ orders: [task({ scheduled_for: '2026-10-20 18:30' })] });
        expect(preparedReceipt(mine)).toBeNull();
    });
});
