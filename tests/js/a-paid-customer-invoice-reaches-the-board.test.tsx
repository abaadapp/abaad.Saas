import { afterEach, describe, expect, it, vi } from 'vitest';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import { router } from '@inertiajs/react';

import CustomerInvoicePreparationFields, {
    EMPTY_PREPARATION,
    preparationPayload,
    type PreparationForm,
} from '@/Components/CustomerInvoicePreparationFields';
import PreparationPanel, { type PreparationPanelData } from '@/Pages/Admin/CustomerInvoices/partials/PreparationPanel';
import PrepCard from '@/Pages/Admin/Preparation/partials/PrepCard';
import PrepDetails from '@/Pages/Admin/Preparation/partials/PrepDetails';
import { checkProgress, idOf, type PrepOrder } from '@/Pages/Admin/Preparation/partials/types';
import { pageProps } from './setup';

vi.mock('@/Layouts/AdminLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <>{children}</> }));

/**
 * فاتورةُ العميل المسدَّدة على لوحة التجهيز — من النموذج إلى البطاقة.
 *
 * ═══ ما يُحرس ═══
 *
 *   - قسمُ «التجهيز والتسليم»: مطفأً لا يُرسل إلّا مفتاحه؛ والتوصيلُ يسأل
 *     العنوان والاستلامُ لا؛ والأخطاءُ من الخادم تُقرأ بأسماء حقولها.
 *   - البطاقةُ تقول «فاتورة عميل» ورقمها، ومفتاحُها مصنَّفٌ لا يتصادم بطلب.
 *   - التفاصيلُ: البنودُ بكميّاتها بلا مربّعات، وسندُ التسليم والنسخةُ الداخليّة
 *     ببابيهما، و«إعادة الإرسال إلى الصندوق» حين لم تُطبع وحدها.
 *   - النقلُ ببابها وبمعرّفها — لا ببابِ الطلب وبرقمه.
 */

afterEach(() => {
    vi.mocked(router.post).mockReset();
    Object.assign(pageProps, {
        context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 } },
        auth: { abilities: [], mayActions: [] },
        orders: undefined,
    });
});

const OPTIONS = {
    branches: [{ id: 4, name: 'فرع الخوير' }],
    occasions: [{ value: 'birthday', label: 'عيد ميلاد' }],
};

/** النموذجُ كما تقوده الشاشة — قيمةٌ وتعديلٌ عليها */
function Harness({ initial = EMPTY_PREPARATION, errors = {} }: { initial?: PreparationForm; errors?: Record<string, string> }) {
    const [value, setValue] = useState(initial);

    return (
        <>
            <CustomerInvoicePreparationFields value={value} onChange={(p) => setValue((v) => ({ ...v, ...p }))} options={OPTIONS} errors={errors} />
            <output data-testid="payload">{JSON.stringify(preparationPayload(value))}</output>
        </>
    );
}

const payload = () => JSON.parse(screen.getByTestId('payload').textContent ?? '{}');

describe('قسمُ «التجهيز والتسليم»', () => {
    it('مطفأٌ افتراضًا ولا يُرسل إلّا مفتاحه — والمربّعُ يفتحه', async () => {
        render(<Harness />);

        expect(screen.queryByText('نوع التسليم')).toBeNull();
        expect(payload()).toEqual({ enabled: false });

        await userEvent.click(screen.getByTestId('preparation-enabled'));
        expect(screen.getByText('نوع التسليم')).toBeInTheDocument();
        expect(screen.getByText('إرسال إلى لوحة التجهيز بعد السداد')).toBeInTheDocument();
    });

    it('التوصيلُ يسأل العنوانَ والمستلِم، والاستلامُ من المحلّ لا يسأل عنوانًا ولا يُرسله', async () => {
        render(<Harness initial={{ ...EMPTY_PREPARATION, enabled: true, delivery_address: 'عنوانٌ كُتب قبل التبديل' }} />);

        await userEvent.click(screen.getByRole('radio', { name: 'توصيل' }));
        expect(screen.getByText('عنوان التوصيل')).toBeInTheDocument();
        expect(screen.getByText('اسم المستلم')).toBeInTheDocument();
        expect(screen.getByText('فرع التجهيز')).toBeInTheDocument();

        await userEvent.click(screen.getByRole('radio', { name: 'استلام من المحل' }));
        expect(screen.queryByText('عنوان التوصيل')).toBeNull();
        expect(screen.getByText('من يستلم')).toBeInTheDocument();
        expect(screen.getByText('فرع الاستلام')).toBeInTheDocument();
        expect(payload()).toMatchObject({ enabled: true, fulfillment_type: 'pickup', delivery_address: '' });
    });

    it('وأخطاءُ الخادم تُقرأ بأسماء حقولها — والتأكيدُ على رسالةٍ تكشف المرسل يظهر بعد سؤاله', () => {
        render(
            <Harness
                initial={{ ...EMPTY_PREPARATION, enabled: true, fulfillment_type: 'delivery', is_gift: true, hide_sender: true, card_message: 'من سالم' }}
                errors={{ 'preparation.recipient_phone': 'هاتف المستلم مطلوب.', 'preparation.card_message': 'رسالة البطاقة قد تحتوي على اسم المرسل' }}
            />,
        );

        expect(screen.getByText('هاتف المستلم مطلوب.')).toBeInTheDocument();
        expect(screen.getByTestId('card-identity-confirm')).toBeInTheDocument();
    });
});

/* ═══════════════════════ اللوحة ═══════════════════════ */

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
    next: ['قيد التجهيز', 'جاهز'],
    checks: {},
    print: { status: 'pending', label: 'بانتظار الطباعة على الصندوق', error: null },
    ...over,
});

const order = (over: Partial<PrepOrder> = {}): PrepOrder =>
    task({ key: 'order:CINV-000012', kind: 'order', prep_id: undefined, source_label: undefined, channel: 'pos', print: null, ...over });

const details = (o: PrepOrder, timelineUrl = vi.fn((n: string) => `/timeline/${n}`)) =>
    render(
        <PrepDetails
            order={o}
            gone={false}
            onClose={() => {}}
            onMove={() => {}}
            onToggle={() => {}}
            deliveryNoteUrl={(n) => `/delivery/${n}`}
            timelineUrl={timelineUrl}
        />,
    );

describe('مهمّةُ الفاتورة على اللوحة', () => {
    it('البطاقةُ تقول «فاتورة عميل» ورقمَها ومن أين ثبت سدادُها — بلا قائمة تحقّق', () => {
        render(<PrepCard order={task()} ageMs={0} onOpen={() => {}} onMove={() => {}} />);

        const card = screen.getByTestId('prep-card');
        expect(card).toHaveAttribute('data-kind', 'invoice');
        expect(within(card).getByTestId('prep-source')).toHaveTextContent('فاتورة عميل');
        expect(card).toHaveTextContent('CINV-000012');
        expect(card).toHaveTextContent('عبر رابط الدفع');
        expect(within(card).queryByTestId('prep-progress')).toBeNull();
        expect(checkProgress(task())).toEqual({ done: 0, total: 0 });
    });

    it('والمفتاحُ مصنَّف — طلبٌ ومهمّةُ فاتورةٍ برقمٍ واحد لا يتصادمان', () => {
        expect(idOf(task())).toBe('customer-invoice:12');
        expect(idOf(order())).toBe('order:CINV-000012');
        expect(idOf(order({ key: undefined }))).toBe('CINV-000012');
    });

    it('التفاصيلُ: البنودُ بكميّاتها بلا مربّعات، والورقتان ببابيهما، والسجلُّ بمفتاح المهمّة', () => {
        const timelineUrl = vi.fn((n: string) => `/timeline/${n}`);
        vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, json: async () => ({ events: [] }) })));
        details(task(), timelineUrl);

        const dialog = screen.getByTestId('prep-details');
        expect(within(dialog).getByTestId('prep-invoice-items')).toHaveTextContent('باقة ورد أبيض');
        expect(within(dialog).getByTestId('prep-invoice-items')).toHaveTextContent('×2');
        expect(within(dialog).queryByRole('checkbox')).toBeNull();

        expect(within(dialog).getByRole('link', { name: /طباعة سند التسليم/ })).toHaveAttribute('href', '/admin.preparation.invoiceDeliveryNote/12');
        expect(within(dialog).getByRole('link', { name: /طباعة الفاتورة الداخلية/ })).toHaveAttribute('href', '/admin.preparation.invoiceInternalPdf/12');
        expect(within(dialog).getByTestId('prep-print-status')).toHaveTextContent('بانتظار الطباعة على الصندوق');
        expect(within(dialog).queryByTestId('prep-requeue')).toBeNull();
        expect(timelineUrl).toHaveBeenCalledWith('customer-invoice:12');
        vi.unstubAllGlobals();
    });

    it('وورقةٌ لم تُطبع تُعاد إلى طابور الصندوق ببابها — بضغطة', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, json: async () => ({ events: [] }) })));
        details(task({ print: { status: 'failed', label: 'لم تُطبع', error: 'PRINTER_UNREACHABLE', confirmable: true, requeueable: true } }));

        await userEvent.click(screen.getByTestId('prep-requeue'));
        expect(vi.mocked(router.post).mock.calls[0][0]).toBe('/admin.preparation.invoiceRequeue/12');
        vi.unstubAllGlobals();
    });

    /*
     * فتحُ حوار الطباعة ليس ورقًا — واللوحةُ تقول «خرجت» عمّا لم يُؤكَّد، أو
     * عمّا طُبع بيدٍ منها فلا يطبعه الصندوق. والزرّان بما يقبله الخادم وحده.
     */
    it('حوارٌ لم يُؤكَّد: «خرجت الورقة» و«إعادة الإرسال» — كلٌّ ببابه', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, json: async () => ({ events: [] }) })));
        details(task({ print: { status: 'requested', label: 'فُتح حوار الطباعة على الصندوق — لم يُؤكَّد خروج الورقة', error: null, confirmable: true, requeueable: true } }));

        expect(screen.getByTestId('prep-print-status')).toHaveTextContent('لم يُؤكَّد خروج الورقة');
        await userEvent.click(screen.getByTestId('prep-print-confirm'));
        expect(screen.getByTestId('prep-print-confirm')).toHaveTextContent('خرجت الورقة');
        expect(vi.mocked(router.post).mock.calls[0][0]).toBe('/admin.preparation.invoicePrintConfirm/12');
        expect(screen.getByTestId('prep-requeue')).toBeInTheDocument();
        vi.unstubAllGlobals();
    });

    it('وورقةٌ تنتظر الصندوق تُقال «طبعتها يدويًا» — فلا يطبعها هو بعدُ', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, json: async () => ({ events: [] }) })));
        details(task({ print: { status: 'pending', label: 'بانتظار الطباعة على الصندوق', error: null, confirmable: true, requeueable: false } }));

        expect(screen.getByTestId('prep-print-confirm')).toHaveTextContent('طبعتها يدويًا — لا تطبعها على الصندوق');
        expect(screen.queryByTestId('prep-requeue')).toBeNull();
        vi.unstubAllGlobals();
    });

    it('وما يُطبع الآن أو خرج: لا زرَّ تأكيدٍ ولا إعادة', () => {
        vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, json: async () => ({ events: [] }) })));
        for (const status of ['claimed', 'printed']) {
            const { unmount } = details(task({ print: { status, label: 'حال', error: null, confirmable: false, requeueable: false } }));
            expect(screen.queryByTestId('prep-print-confirm')).toBeNull();
            expect(screen.queryByTestId('prep-requeue')).toBeNull();
            unmount();
        }
        vi.unstubAllGlobals();
    });

    it('والنقلُ من اللوحة ببابها وبمعرّفها — والطلبُ ببابه وبرقمه', async () => {
        Object.assign(pageProps, {
            orders: [task(), order({ next: ['قيد التجهيز'] })],
            filters: { when: null, type: null },
            counts: { all: 2, overdue: 0, today: 2, tomorrow: 0 },
            typeCounts: { all: 2, delivery: 2, pickup: 0 },
            columns: { waiting: ['جديد', 'مؤكّد'] },
            columnCounts: { waiting: 2 },
            truncated: null,
            fetchedAt: '2026-10-19T10:00:00+04:00',
        });
        const { default: Board } = await import('@/Pages/Admin/Preparation/Index');
        render(<Board />);

        // بطاقتان برقمٍ واحد — تُرسمان كلتاهما
        expect(screen.getAllByTestId('prep-card')).toHaveLength(2);

        const [invoiceCard, orderCard] = screen.getAllByTestId('prep-card');
        await userEvent.click(within(invoiceCard).getByRole('button', { name: 'قيد التجهيز' }));
        // و«قيد التجهيز» لا «جاهز»: «جاهز» على طلبٍ يسأل عن قائمة التحقّق أوّلًا
        await userEvent.click(within(orderCard).getByRole('button', { name: 'قيد التجهيز' }));

        const urls = vi.mocked(router.post).mock.calls.map((c) => c[0]);
        expect(urls).toContain('/admin.preparation.invoiceMove/12');
        expect(urls).toContain('/admin.preparation.move/CINV-000012');
    });
});

/* ═══════════════════════ صفحةُ الفاتورة ═══════════════════════ */

describe('تجهيزُ الفاتورة في صفحتها', () => {
    const panel = (over: Partial<PreparationPanelData> = {}): PreparationPanelData => ({
        enabled: true,
        attachable: false,
        editable: true,
        removable: true,
        activatable: false,
        paid: false,
        data: {
            ...EMPTY_PREPARATION,
            branch_id: 4,
            branch: 'فرع الخوير',
            fulfillment_type: 'delivery',
            scheduled_for: '2026-10-19T16:00',
            recipient_name: 'مريم',
        },
        active: false,
        status: null,
        activated_at: null,
        activation_source: null,
        activation_error: null,
        waiting_for: null,
        print: null,
        options: OPTIONS,
        ...over,
    });

    it('لم تُسدَّد بعد ← «بانتظار السداد الكامل» — ومسدَّدةٌ لم تُرسل لا تُقال كذلك', () => {
        const { rerender } = render(<PreparationPanel invoiceId={9} preparation={panel()} />);
        expect(screen.getByTestId('preparation-state')).toHaveTextContent('بانتظار السداد الكامل');

        rerender(<PreparationPanel invoiceId={9} preparation={panel({ paid: true, activatable: true })} />);
        expect(screen.getByTestId('preparation-state')).toHaveTextContent('الفاتورة مسدّدة — لم تُرسل إلى لوحة التجهيز بعد');
        expect(screen.getByTestId('preparation-activate')).toBeInTheDocument();
    });

    it('وفي اللوحة: «مؤكّد» تُقرأ «بانتظار التجهيز» كعمودها — بلا زرّ إرسالٍ ولا إلغاء', () => {
        render(<PreparationPanel invoiceId={9} preparation={panel({ active: true, status: 'مؤكّد', activation_source: 'paymob', paid: true, removable: false, print: { status: 'pending', label: 'بانتظار الطباعة على الصندوق', error: null } })} />);

        expect(screen.getByTestId('preparation-state')).toHaveTextContent('في لوحة التجهيز — بانتظار التجهيز · عبر رابط الدفع');
        expect(screen.queryByTestId('preparation-activate')).toBeNull();
        expect(screen.queryByText('إلغاء طلب التجهيز')).toBeNull();
        expect(screen.getByText(/بانتظار الطباعة على الصندوق/)).toBeInTheDocument();
    });
});
