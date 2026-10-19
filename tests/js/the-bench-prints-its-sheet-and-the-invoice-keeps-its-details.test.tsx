import { afterEach, describe, expect, it, vi } from 'vitest';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { pageProps } from './setup';

import type { PrepOrder } from '@/Pages/Admin/Preparation/partials/types';
import { invoiceTotals, withAddons } from '@/lib/invoice-totals';

/**
 * ورقةُ التجهيز، وتفاصيلُ بند الفاتورة، وأسماءٌ لا تنقلب — من جهة الشاشة.
 *
 * ═══ ما يُحرس ═══
 *
 *   - «طباعة تفاصيل التجهيز» لكلّ بطاقة: الطلبُ برقمه، ومهمّةُ الفاتورة بمعرّفها —
 *     ولا تُطبع وحدها عند فتح النافذة.
 *   - الاسمُ معزولٌ باتّجاهه والكميّةُ من اليسار — على البطاقة وفي التفاصيل.
 *   - الإضافةُ المدفوعةُ مع صنفها تدخل ملخّصَ الفاتورة مرّةً، والمشمولةُ لا تدخله،
 *     والبندُ اليدويُّ بلا إضافات.
 *   - تفاصيلُ البند: الإضافةُ بكميّتها و«ضمن السعر»، والخيارُ يُكتب ويُحذف.
 *   - رفعُ حظر البيع وارتدادُ الشيك لا يُرسمان لمن يردّه الخادم.
 */

const printed: { routeName: string; number: string; label: string }[] = [];

vi.mock('@/Components/PrintReceiptButton', () => ({
    default: ({ routeName, number, label }: { routeName: string; number: string; label: string }) => {
        printed.push({ routeName, number, label });

        return <button type="button" data-route={routeName} data-number={number}>{label}</button>;
    },
}));
vi.mock('@/Layouts/AdminLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <>{children}</> }));
vi.mock('@/Components/PaperFrame', () => ({ default: () => null }));

const PrepDetails = (await import('@/Pages/Admin/Preparation/partials/PrepDetails')).default;
const PrepCard = (await import('@/Pages/Admin/Preparation/partials/PrepCard')).default;
const LineDetails = (await import('@/Pages/Admin/CustomerInvoices/partials/LineDetails')).default;

afterEach(() => {
    printed.length = 0;
});

const order = (over: Partial<PrepOrder> = {}): PrepOrder => ({
    key: 'order:INV-000777',
    kind: 'order',
    number: 'INV-000777',
    status: 'مؤكد',
    customer: 'سالم',
    fulfillment: 'delivery',
    scheduled_for: '2026-10-20 16:00',
    scheduled: null,
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
    items: [{ id: 3, name: 'باقة ورد', qty: 2, note: null, image: null, addons: [{ id: 9, name: 'Chocolate', qty: 2 }] }],
    next: [],
    checks: {},
    ...over,
});

const details = (o: PrepOrder) =>
    render(
        <PrepDetails
            order={o}
            gone={false}
            onClose={() => {}}
            onMove={() => {}}
            onToggle={() => {}}
            deliveryNoteUrl={(n) => `/delivery/${n}`}
            timelineUrl={(n) => `/timeline/${n}`}
        />,
    );

describe('«طباعة تفاصيل التجهيز»', () => {
    it('للطلب: بابُ ورقته برقمه', () => {
        globalThis.fetch = vi.fn(() => Promise.resolve(new Response('{"events":[]}'))) as never;
        details(order());

        const sheet = printed.find((p) => p.label === 'طباعة تفاصيل التجهيز');
        expect(sheet).toEqual({ routeName: 'admin.preparation.sheet', number: 'INV-000777', label: 'طباعة تفاصيل التجهيز' });
    });

    it('ولمهمّة الفاتورة: بابُ ورقتها بمعرّف المهمّة لا برقم الفاتورة', () => {
        globalThis.fetch = vi.fn(() => Promise.resolve(new Response('{"events":[]}'))) as never;
        details(order({ key: 'customer-invoice:12', kind: 'invoice', prep_id: 12, number: 'CINV-000012', print: null }));

        const sheet = printed.find((p) => p.label === 'طباعة تفاصيل التجهيز');
        expect(sheet).toEqual({ routeName: 'admin.preparation.invoiceSheet', number: '12', label: 'طباعة تفاصيل التجهيز' });
    });

    it('والاسمُ معزولٌ باتّجاهه والكميّةُ من اليسار — في التفاصيل وعلى البطاقة', () => {
        globalThis.fetch = vi.fn(() => Promise.resolve(new Response('{"events":[]}'))) as never;
        details(order());

        const addon = screen.getByTestId('prep-check-addon:9').closest('label')!;
        expect(within(addon).getByText('Chocolate').tagName).toBe('BDI');
        expect(within(addon).getByText('×2')).toHaveAttribute('dir', 'ltr');

        const { container } = render(<PrepCard order={order()} ageMs={0} onOpen={() => {}} onMove={() => {}} />);
        const line = within(container).getByTestId('prep-items').querySelector('li')!;
        expect(line.querySelector('bdi')).toHaveTextContent('باقة ورد');
        expect(line.querySelector('[dir="ltr"]')).toHaveTextContent('×2');
    });
});

describe('الإضافةُ المدفوعةُ مع صنفها في ملخّص الفاتورة', () => {
    const catalog = [{ id: 5, price: 3.5 }, { id: 6, price: 1.5 }];
    const line = {
        product_id: '1', quantity: '1', unit_price: '25', discount: '0', tax_rate: '5',
        addons: [
            { addon_id: '5', quantity: '2', included: false },
            { addon_id: '6', quantity: '1', included: true },
        ],
    };

    it('تُحسب مرّةً بثمنها وضريبة صنفها، والمشمولةُ لا تُحسب', () => {
        const t = invoiceTotals(withAddons([line], catalog), false);

        expect(t.subtotal).toBeCloseTo(32, 3);
        expect(t.tax).toBeCloseTo(1.6, 3);
        expect(t.total).toBeCloseTo(33.6, 3);
    });

    it('والبندُ اليدويُّ بلا إضافات — ولو حُملت معه', () => {
        expect(withAddons([{ ...line, product_id: '' }], catalog)).toHaveLength(1);
    });
});

describe('تفاصيلُ البند', () => {
    const value = {
        variant_id: '',
        note: 'تغليف ذهبي',
        options: [{ label: 'اللون', value: 'أحمر' }],
        addons: [{ addon_id: '5', quantity: '2', included: false }],
    };
    const addons = [{ id: 5, name: 'شوكولاتة', name_en: 'Chocolate', price: 3.5 }];

    it('الإضافةُ بكميّتها وثمنها للعلم، و«ضمن السعر» يُقلَب', async () => {
        const changed = vi.fn();
        render(<LineDetails value={value} onChange={changed} variants={[]} addons={addons} onVariant={() => {}} money={(v) => v.toFixed(3)} label={(n) => n} />);

        const row = screen.getByTestId('line-addon');
        expect(row).toHaveTextContent('شوكولاتة');
        expect(row).toHaveTextContent('7.000');

        await userEvent.click(within(row).getByRole('checkbox'));
        expect(changed).toHaveBeenCalledWith({ addons: [{ addon_id: '5', quantity: '2', included: true }] });
    });

    it('والخيارُ يُحذف، والبندُ اليدويُّ لا يُعرض له مقاسٌ ولا إضافة', async () => {
        const changed = vi.fn();
        render(<LineDetails value={value} onChange={changed} variants={null} addons={null} onVariant={() => {}} money={(v) => v.toFixed(3)} label={(n) => n} />);

        expect(screen.queryByTestId('line-addon')).toBeNull();
        expect(screen.queryByText('المقاس')).toBeNull();

        await userEvent.click(within(screen.getByTestId('line-option')).getByRole('button'));
        expect(changed).toHaveBeenCalledWith({ options: [] });
    });
});

describe('ما يردّه الخادم لا يُرسم', () => {
    it('رفعُ حظر البيع لمن لا يملك تجاوزَه: يُقال من يرفعه ولا مفتاحَ يُطفئه', async () => {
        const Show = (await import('@/Pages/Admin/Customers/Show')).default;
        Object.assign(pageProps, {
            auth: { abilities: ['customers'], mayActions: [] },
            customer: { id: 7, name: 'شركة الخليج', alert_type: 'block', alert_reason: 'شيكات', phone: '9', customer_type: 'فرد' },
            orders: [], addresses: [], branches: [],
            customerFlags: { alerts: true, blocking: true },
            paymentLinks: { ready: false, can: false, can_cancel: false, settings_url: null, links: [] },
        });

        render(<Show />);

        expect(screen.queryByTestId('customer-block-locked')).not.toBeNull();
        expect(screen.queryByRole('switch', { name: /تفعيل التنبيه/ })).toBeNull();
    });
});
