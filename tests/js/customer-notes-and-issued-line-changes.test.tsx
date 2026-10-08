import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest';

import { CorrectItemDialog, correctionLabel, type OrderEditRecord } from '@/Components/InvoiceCorrection';
import { pageProps } from './setup';

/* الصفحةُ تُختبر لا القشرةُ حولها */
vi.mock('@/Layouts/AdminLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <>{children}</> }));
vi.mock('@/Layouts/PosLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <>{children}</> }));

/**
 * ملاحظاتُ العميل وتعديلُ أصناف الفاتورة — ما يُرى.
 *
 * والحكمُ في الخادم: `CustomerNotesAndIssuedLineChangesTest`. وهنا: قاعدةُ
 * «الإنجليزيّة وحدها» في الصفحة (`ribbon-notes.js`) مثلُ قاعدة الخادم، وكلُّ
 * ملاحظةٍ باسمها في شاشة الطلب والصندوق، وعنوانُها بلغة الشاشة ونصُّها كما
 * كُتب، والأزرارُ لمن فُتحت له الميزة وحده.
 */

type W = Window & {
    RBNotes: {
        english: (s: string) => boolean;
        mount: (f: HTMLTextAreaElement, e: HTMLElement, msg: string, max?: number) => { take: () => string | null; clear: () => void };
    };
    RBUpsells: { compose: (main: Record<string, unknown>, picks: unknown[]) => { lines: Record<string, unknown>[] } };
};
const w = () => window as unknown as W;

beforeAll(() => {
    for (const file of ['ribbon-notes.js', 'ribbon-upsells.js']) {
        new Function(readFileSync(resolve(__dirname, '../../resources/js/store', file), 'utf8'))();
    }
});

afterEach(() => {
    for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
});

describe('ملاحظةُ العميل في الموقع — الإنجليزيّة وحدها', () => {
    it('القاعدةُ قاعدةُ الخادم', () => {
        for (const ok of ['No plastic wrapping', 'White flowers only, 2 ribbons!', 'Please call @ 4pm (gate #3)', 'Thanks 😀']) {
            expect(w().RBNotes.english(ok), ok).toBe(true);
        }
        for (const no of ['بدون تغليف بلاستيك', 'ورد أبيض فقط', 'Café', 'Call at ٥', 'Hi ، ok', 'Привет']) {
            expect(w().RBNotes.english(no), no).toBe(false);
        }

        const server = readFileSync(resolve(__dirname, '../../app/Support/NotesAndEdits.php'), 'utf8');
        expect(server).toContain("NOT_ENGLISH = '/[^\\P{L}A-Za-z]|\\p{Arabic}/u'");
    });

    it('نصٌّ عربيٌّ يُردّ تحت الخانة ولا يدخل السلّة — والفارغُ لا خطأ فيه', () => {
        const field = document.createElement('textarea');
        const error = document.createElement('p');
        error.hidden = true;
        const note = w().RBNotes.mount(field, error, 'يرجى كتابة الملاحظة باللغة الإنجليزية فقط.', 500);

        expect(note.take()).toBe('');
        expect(error.hidden).toBe(true);

        field.value = 'بدون تغليف';
        expect(note.take()).toBeNull();
        expect(error.hidden).toBe(false);
        expect(error.textContent).toBe('يرجى كتابة الملاحظة باللغة الإنجليزية فقط.');

        field.value = '  No wrapping  ';
        expect(note.take()).toBe('No wrapping');
        expect(error.hidden).toBe(true);
    });

    it('وملاحظةُ الصنف تبقى معه ولا تُلصق بإضافةٍ اختيرت معه', () => {
        const r = w().RBUpsells.compose({ id: 1, variant_id: null, qty: 2, note: 'No wrapping' }, [
            { id: 9, needs: false, variant: null, gift: false, note: '', card: null },
        ]);

        expect(r.lines).toEqual([
            { id: 1, variant_id: null, qty: 2, note: 'No wrapping' },
            { id: 9, variant_id: null, qty: 1 },
        ]);
    });

    it('الخانتان حرّتان — لا خيارَ جاهزًا ولا اقتراح', () => {
        const product = readFileSync(resolve(__dirname, '../../resources/views/store/ribbon/product.blade.php'), 'utf8');
        const checkout = readFileSync(resolve(__dirname, '../../resources/views/store/ribbon/checkout.blade.php'), 'utf8');
        const box = (html: string, id: string) => html.slice(html.indexOf(id), html.indexOf('</div>', html.indexOf(id)));

        for (const b of [box(product, 'rb-product-note-box'), box(checkout, 'rb-order-notes-box')]) {
            expect(b).toContain('<textarea');
            for (const choice of ['<select', 'radio', 'checkbox', '<option', 'datalist']) {
                expect(b).not.toContain(choice);
            }
        }
    });
});

const item = (over: Record<string, unknown> = {}) => ({ id: 1, product_id: 3, name: 'بوكيه أبيض', qty: 1, price: 20, total: 20, note: null, card_line: false, ...over });

const detail = (over: Record<string, unknown> = {}) => ({
    id: 'INV-7',
    customer: 'Maryam',
    customer_language: null,
    customer_alert: null,
    employee: 'الموقع',
    branch: 'الرئيسي',
    date: '2027-03-10 10:00',
    payment: 'نقدي',
    payment_status: 'مدفوع',
    notes: 'Please call before delivery',
    recipient_name: null,
    recipient_phone: null,
    fulfillment_type: 'delivery',
    scheduled_for: null,
    occasion_type: null,
    card_message: 'Happy birthday',
    card_align: null,
    card_file: null,
    card_file_name: null,
    sender_name: null,
    hide_sender: false,
    is_gift: false,
    delivery_address: 'Al Khuwair',
    delivery_notes: 'Ring twice',
    internal_notes: 'VIP customer',
    balance_due: 0,
    status: 'مكتمل',
    next_statuses: [],
    channel: 'website',
    occasions: [],
    fulfillments: [],
    subtotal: 21.5,
    discount: 0,
    tax: 0,
    delivery: 0,
    total: 21.5,
    items: [
        item({ note: 'No plastic wrapping' }),
        item({ id: 2, product_id: 4, name: 'كرت هدية', price: 1.5, total: 1.5, note: 'Happy birthday', card_line: true }),
    ],
    payment_methods: ['نقدي'],
    edits: [],
    ...over,
});

const catalog = [{ id: 3, name: 'بوكيه أبيض', price: 20, variants: [], addons: [] }];

const adminScreen = async (order: Record<string, unknown>, lineEdit: unknown, translations: Record<string, string> = {}) => {
    Object.assign(pageProps, {
        translations,
        context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 } },
        auth: { abilities: ['orders'], mayActions: ['order.edit'] },
        order,
        lineEdit,
        invoiceEdit: { can: true, reason: null },
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

describe('شاشةُ الطلب — كلُّ ملاحظةٍ باسمها', () => {
    it('ملاحظةُ المنتج تحت بندها، ورسالةُ الكرت باسمها، والطلبُ والتوصيلُ والداخليّةُ كلٌّ في موضعه', async () => {
        await adminScreen(detail(), { lines: true, notes: true, catalog });

        const notes = screen.getAllByTestId('item-note').map((n) => n.textContent);
        expect(notes).toEqual(['ملاحظة المنتج: No plastic wrapping', 'رسالة الكرت: Happy birthday']);
        expect(screen.getByTestId('order-notes').textContent).toBe('Please call before delivery');
        expect(screen.getByText('Ring twice')).toBeTruthy();
        expect(screen.getByTestId('internal-notes').textContent).toContain('VIP customer');
        // ورسالةُ الكرت لا تُعدَّل من «ملاحظة المنتج»
        expect(screen.getAllByTestId('edit-item-note')).toHaveLength(1);
    });

    it('وبالإنجليزيّة: العناوينُ تُترجم، ونصُّ العميل كما كتبه', async () => {
        await adminScreen(
            detail({ items: [item({ note: 'لا تغليف' })] }),
            { lines: true, notes: true, catalog },
            { 'ملاحظة المنتج': 'Product note', 'ملاحظات الطلب': 'Order notes' },
        );

        expect(screen.getByTestId('item-note').textContent).toBe('Product note: لا تغليف');
        expect(screen.getByText('Order notes')).toBeTruthy();
    });

    it('زرُّ الإضافة والاستبدال لمن فُتحت له الميزة — ولا شيءَ لسواه', async () => {
        const { unmount } = await adminScreen(detail(), { lines: true, notes: true, catalog });
        expect(screen.getByTestId('add-line')).toBeTruthy();
        unmount();

        await adminScreen(detail(), null);
        expect(screen.queryByTestId('add-line')).toBeNull();
        expect(screen.queryByTestId('edit-item-note')).toBeNull();
    });

    it('نافذةُ الإضافة لا سعرَ يُكتب فيها، وتسأل عن الفرق في الفاتورة المدفوعة', async () => {
        await adminScreen(detail(), { lines: true, notes: true, catalog });
        fireEvent.click(screen.getByTestId('add-line'));

        const dialog = within(screen.getByTestId('line-dialog'));
        expect(dialog.queryByLabelText(/السعر/)).toBeNull();
        expect(dialog.getByText('فرق الفاتورة المدفوعة')).toBeTruthy();
        expect(dialog.getByText('سبب التعديل')).toBeTruthy();
    });

    it('حُصِّل الآن أو رُدّ الآن يسألان عن الوسيلة — والبقاءُ على العميل لا يسأل', async () => {
        await adminScreen(detail({ payment_methods: ['نقدي', 'بطاقة'] }), { lines: true, notes: true, catalog });
        fireEvent.click(screen.getByTestId('add-line'));

        const dialog = screen.getByTestId('line-dialog');
        const settle = dialog.querySelector('select[name="settle"]') as HTMLSelectElement;
        const method = () => dialog.querySelector('select[name="payment_method"]') as HTMLSelectElement | null;
        expect(method()).toBeNull();

        fireEvent.change(settle, { target: { value: 'collected' } });
        expect(within(dialog).getByText('وسيلة التحصيل')).toBeTruthy();
        expect(method()?.value).toBe('نقدي');

        fireEvent.change(settle, { target: { value: 'refunded' } });
        expect(within(dialog).getByText('وسيلة الردّ')).toBeTruthy();

        fireEvent.change(settle, { target: { value: 'due' } });
        expect(method()).toBeNull();
    });

    it('المتبقّي على العميل يُرى ويُحصَّل', async () => {
        await adminScreen(detail({ balance_due: 31.5 }), { lines: false, notes: true, catalog: [] });

        expect(screen.getByTestId('balance-due').textContent).toContain('المتبقّي على العميل');
    });
});

describe('تصحيحُ الكمّيّة في فاتورةٍ مدفوعة — يسأل عن الفرق كما تسأل الإضافة', () => {
    const draw = (settle?: { methods: string[] }) => {
        Object.assign(pageProps, { translations: {}, auth: { abilities: ['orders'], mayActions: ['order.edit'] } });
        render(<CorrectItemDialog url="/fix" item={{ id: 1, name: 'باقة ورد', qty: 2 }} settle={settle} onClose={() => {}} />);
        const settleBox = () => document.querySelector('select[name="settle"]') as HTMLSelectElement | null;
        const methodBox = () => document.querySelector('select[name="payment_method"]') as HTMLSelectElement | null;
        const qty = (v: string) => fireEvent.change(screen.getByRole('spinbutton'), { target: { value: v } });

        return { settleBox, methodBox, qty };
    };
    const choices = (box: HTMLSelectElement | null) => Array.from(box?.options ?? []).map((o) => o.value).filter((v) => v !== '' && v !== '__empty__');

    it('زيادةٌ: يبقى أو حُصِّل الآن — ووسيلةٌ للتحصيل', () => {
        const { settleBox, methodBox, qty } = draw({ methods: ['نقدي', 'بطاقة'] });
        expect(settleBox()).toBeNull();

        qty('3');
        expect(choices(settleBox())).toEqual(['due', 'collected']);
        expect(methodBox()).toBeNull();

        fireEvent.change(settleBox()!, { target: { value: 'collected' } });
        expect(screen.getByText('وسيلة التحصيل')).toBeTruthy();
        expect(methodBox()?.value).toBe('نقدي');
    });

    it('نقصٌ: رُدّ الآن وحده — ووسيلةٌ للردّ', () => {
        const { settleBox, qty } = draw({ methods: ['نقدي'] });

        qty('1');
        expect(choices(settleBox())).toEqual(['refunded']);
        fireEvent.change(settleBox()!, { target: { value: 'refunded' } });
        expect(screen.getByText('وسيلة الردّ')).toBeTruthy();
    });

    it('ونشاطٌ لم تُفتح له الميزة لا يُسأل فيه شيء', () => {
        const { settleBox, qty } = draw(undefined);

        qty('3');
        expect(settleBox()).toBeNull();
        expect(screen.queryByText('فرق الفاتورة المدفوعة')).toBeNull();
    });
});

describe('صندوقُ البيع — الملاحظاتُ كلٌّ باسمها', () => {
    it('الطلبُ والتوصيلُ والداخليّةُ ورسالةُ الكرت بطاقاتٌ منفصلة', async () => {
        Object.assign(pageProps, {
            translations: {},
            context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 } },
            auth: { abilities: ['pos'], mayActions: [] },
            order: detail(),
            canEdit: false,
            lineEdit: null,
        });
        const { default: PosOrderDetails } = await import('@/Pages/Pos/OrderDetails');
        render(<PosOrderDetails />);

        expect(within(screen.getByTestId('order-notes')).getByText('ملاحظات الطلب')).toBeTruthy();
        expect(within(screen.getByTestId('delivery-notes')).getByText('Ring twice')).toBeTruthy();
        expect(within(screen.getByTestId('internal-notes')).getByText('VIP customer')).toBeTruthy();
        expect(within(screen.getByTestId('card-message')).getByText('Happy birthday')).toBeTruthy();
        expect(screen.getAllByTestId('item-note')[0].textContent).toBe('ملاحظة المنتج: No plastic wrapping');
        expect(screen.queryByTestId('add-line')).toBeNull();
    });
});

describe('سجلُّ التعديل — اسمُ العملية بلغة الشاشة', () => {
    const edit = (over: Partial<OrderEditRecord>): OrderEditRecord => ({
        kind: 'بند', subject: 'باقة', qty_before: 1, qty_after: 2, value_before: null, value_after: null,
        total_before: 10, total_after: 20, reason: 'سبب', by: 'سعود', at: '2027-03-10 10:00', ...over,
    });
    const t = (s: string) => ({ 'أُضيف': 'Added', 'استُبدل': 'Replaced', 'ملاحظة المنتج': 'Product note', 'تحصيل المتبقّي': 'Balance collected', 'ردّ الفرق للعميل': 'Difference refunded' })[s] ?? s;

    it('إضافةٌ واستبدالٌ وملاحظةٌ وتحصيل', () => {
        expect(correctionLabel(edit({ kind: 'صنف مضاف', value_after: 'زنبق ×1' }), t)).toBe('Added «زنبق ×1»');
        expect(correctionLabel(edit({ kind: 'صنف مستبدل', value_before: 'باقة ×2', value_after: 'زنبق ×1' }), t)).toBe('Replaced «باقة ×2» ← «زنبق ×1»');
        expect(correctionLabel(edit({ kind: 'ملاحظة منتج', value_before: null, value_after: 'No wrapping' }), t)).toBe('Product note «باقة»: — ← No wrapping');
        expect(correctionLabel(edit({ kind: 'تحصيل متبقّي', value_before: '31.5', value_after: 'نقدي' }), t)).toBe('Balance collected: 31.5 — نقدي');
        expect(correctionLabel(edit({ kind: 'ردّ فرق', value_before: '10.5', value_after: 'نقدي' }), t)).toBe('Difference refunded: 10.5 — نقدي');
    });
});
