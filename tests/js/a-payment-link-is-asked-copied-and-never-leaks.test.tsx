import { fireEvent, render, screen, within } from '@testing-library/react';
import { router } from '@inertiajs/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { pageProps } from './setup';

vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

const { default: PaymentLinksCard, LINK_STATUS } = await import('@/Components/PaymentLinks');
const { default: PaymentLinksPage } = await import('@/Pages/Admin/Finance/PaymentLinks');
const { default: Paymob } = await import('@/Pages/Admin/Integrations/Paymob');

type Row = import('@/Components/PaymentLinks').PaymentLinkRow;

/**
 * روابطُ الدفع على الشاشة — تُطلب وتُنسخ وتُشارك، ولا يخرج منها سرّ.
 *
 * ═══ ما يُحرس ═══
 *
 *   - الفاتورةُ تطلب باقيها: الزرُّ لمن يملك التحصيل، والمبلغُ باقيها.
 *   - Paymob غير مكتملة: «أكمل إعداد Paymob أولًا» ورابطُ إعداداتها — ولا زرّ.
 *   - الحالُ بكلماتٍ يفهمها التاجر: بانتظار الدفع / تم الدفع / منتهٍ / ملغى.
 *   - النسخُ والمشاركةُ تحمل الرابطَ القصير وحده — لا مفتاحَ ولا رمز.
 *   - الإلغاءُ للمعلَّق وحده، وبتأكيد.
 *   - المالية: الجدولُ والتسويةُ ورصيدُ «تحت التسوية».
 *   - الإعداد: مفتاحُ API حقلٌ فارغٌ دائمًا، والبيئةُ تُختار صراحةً.
 */

const row = (over: Partial<Row> = {}): Row => ({
    id: 1, reference: 'pay-abc', customer: { id: 7, name: 'وزارة الثقافة' }, invoice: { id: 3, number: 'INV-000003' },
    description: null, amount: 105, currency: 'OMR', status: 'pending', is_live: true, url: 'https://paymob.link/abc123',
    created_at: '2026-10-11 10:00', expires_at: '2026-10-18 23:59', paid_at: null, payment: null, error: null, review: false,
    ...over,
});

const panel = (over: Record<string, unknown> = {}) => ({ ready: true, can: true, settings_url: '/admin/integrations/paymob', links: [] as Row[], ...over });

const reset = (props: Record<string, unknown> = {}) => {
    for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    Object.assign(pageProps, {
        translations: {}, context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 } },
        auth: { abilities: ['finance'], mayActions: [] }, flash: { toast: null, status: null }, ...props,
    });
};

beforeEach(() => {
    reset();
    vi.mocked(router.post).mockClear();
});

afterEach(() => vi.restoreAllMocks());

describe('بطاقةُ روابط الفاتورة', () => {
    it('تطلب باقيَ الفاتورة — والمبلغُ باقيها كاملًا', () => {
        render(<PaymentLinksCard panel={panel()} customerId={7} invoiceId={3} outstanding={105} title="روابط الدفع" action="إنشاء رابط دفع" />);

        fireEvent.click(screen.getByRole('button', { name: 'إنشاء رابط دفع' }));
        const form = screen.getByTestId('payment-link-form');
        expect(within(form).getByRole('textbox', { name: 'المبلغ' })).toHaveValue('105.000');
        expect(within(form).getByText(/الباقي/)).toHaveTextContent('105.000');
    });

    it('وPaymob غيرُ مكتملة: «أكمل إعداد Paymob أولًا» ورابطُ إعداداتها — ولا زرّ', () => {
        render(<PaymentLinksCard panel={panel({ ready: false })} customerId={7} invoiceId={3} outstanding={105} title="روابط الدفع" action="إنشاء رابط دفع" />);

        const note = screen.getByTestId('payment-links-not-configured');
        expect(note).toHaveTextContent('أكمل إعداد Paymob أولًا.');
        expect(within(note).getByRole('link', { name: 'إعدادات Paymob' })).toHaveAttribute('href', '/admin/integrations/paymob');
        expect(screen.queryByRole('button', { name: 'إنشاء رابط دفع' })).toBeNull();
    });

    it('ومن لا يملك التحصيل لا يرى الزرَّ ولا الدعوةَ إلى الإعداد', () => {
        render(<PaymentLinksCard panel={panel({ can: false, ready: false })} customerId={7} title="روابط الدفع" action="طلب دفعة" />);

        expect(screen.queryByRole('button', { name: 'طلب دفعة' })).toBeNull();
        expect(screen.queryByTestId('payment-links-not-configured')).toBeNull();
    });

    it('وفاتورةٌ مسدَّدة لا تطلب شيئًا', () => {
        render(<PaymentLinksCard panel={panel()} customerId={7} invoiceId={3} outstanding={0} allowCreate={false} title="روابط الدفع" action="إنشاء رابط دفع" />);

        expect(screen.queryByRole('button', { name: 'إنشاء رابط دفع' })).toBeNull();
    });

    it('الحالُ بكلماتٍ يفهمها التاجر', () => {
        render(
            <PaymentLinksCard
                panel={panel({
                    links: [
                        row({ id: 1, status: 'pending' }),
                        row({ id: 2, status: 'paid', paid_at: '2026-10-12 09:00', payment: { id: 9, number: 'RCPT-000009' } }),
                        row({ id: 3, status: 'expired', url: null }),
                        row({ id: 4, status: 'cancelled', url: null }),
                    ],
                })}
                customerId={7}
                title="روابط الدفع"
                action="طلب دفعة"
            />,
        );

        const labels = screen.getAllByTestId('payment-link-status').map((b) => b.textContent);
        expect(labels).toEqual(['بانتظار الدفع', 'تم الدفع', 'منتهٍ', 'ملغى']);
        expect(LINK_STATUS.failed.label).toBe('تعذّر إنشاء الرابط');
        expect(screen.getByRole('link', { name: /RCPT-000009/ })).toBeInTheDocument();
        // والمنتهي والملغى بلا رابطٍ يُنسخ
        expect(screen.getAllByTestId('payment-link-actions')).toHaveLength(2);
    });

    it('النسخُ والمشاركةُ تحمل الرابطَ القصير وحده — لا سرّ', () => {
        render(<PaymentLinksCard panel={panel({ links: [row()] })} customerId={7} title="روابط الدفع" action="طلب دفعة" />);

        const share = screen.getByTestId('payment-link-whatsapp').getAttribute('href') ?? '';
        expect(share.startsWith('https://wa.me/?text=')).toBe(true);
        const text = decodeURIComponent(share.split('text=')[1]);
        expect(text).toContain('https://paymob.link/abc123');
        expect(text).toContain('105.000');
        expect(document.body.innerHTML).not.toMatch(/api_key|secret|hmac|token|tok-/i);
        expect(screen.getByRole('link', { name: 'فتح الرابط' })).toHaveAttribute('href', 'https://paymob.link/abc123');
    });

    it('الإلغاءُ للمعلَّق وحده — وبتأكيدٍ من نافذة النظام', async () => {
        render(<PaymentLinksCard panel={panel({ links: [row(), row({ id: 2, status: 'paid' })] })} customerId={7} title="روابط الدفع" action="طلب دفعة" />);

        const cancel = screen.getAllByTestId('payment-link-cancel');
        expect(cancel).toHaveLength(1);

        fireEvent.click(cancel[0]);
        expect(router.post).not.toHaveBeenCalled();
        fireEvent.click(await screen.findByRole('button', { name: 'إلغاء الرابط' }));
        await vi.waitFor(() => expect(router.post).toHaveBeenCalled());
        expect(vi.mocked(router.post).mock.calls[0][0]).toBe('/admin.finance.paymentLinks.cancel/1');
    });

    it('والرابطُ الذي أُنشئ الآن يُعرض للنسخ فورًا', () => {
        reset({ flash: { toast: null, status: null, paymentLink: row() } });
        render(<PaymentLinksCard panel={panel()} customerId={7} title="روابط الدفع" action="طلب دفعة" />);

        const made = screen.getByTestId('payment-link-created');
        expect(made).toHaveTextContent('تم إنشاء رابط الدفع');
        expect(within(made).getByRole('textbox', { name: 'رابط الدفع' })).toHaveValue('https://paymob.link/abc123');
    });
});

describe('المالية ← روابط الدفع', () => {
    const page = (over: Record<string, unknown> = {}) => {
        reset({
            ...panel({ links: [row(), row({ id: 2, status: 'paid', invoice: null, payment: { id: 9, number: 'RCPT-000009' } })] }),
            status: null, counts: { pending: 1, paid: 1 }, customers: [{ value: '7', label: 'وزارة الثقافة' }],
            clearing: 105, settlements: [{ id: 1, settled_on: '2026-10-13', gross: 100, fee: 2, net: 98, bank: 'بنك مسقط', reference: 'PMB-1', notes: null }],
            bankAccounts: [{ value: '4', label: 'بنك مسقط' }],
            ...over,
        });

        return render(<PaymentLinksPage />);
    };

    it('الجدولُ والزرُّ ورصيدُ «تحت التسوية» وتاريخُ التسويات', () => {
        page();

        expect(screen.getByTestId('payment-links-new')).toHaveTextContent('رابط دفع');
        expect(screen.getAllByTestId('payment-link-row')).toHaveLength(2);
        expect(screen.getByText('بلا فاتورة')).toBeInTheDocument();
        expect(screen.getByTestId('paymob-settlements')).toHaveTextContent('105.000');
        const settled = screen.getByTestId('paymob-settlements-table');
        expect(settled).toHaveTextContent('98.000');
        expect(settled).toHaveTextContent('2.000');
        expect(screen.getByTestId('paymob-settle-open')).toBeEnabled();
    });

    it('وغيرُ المكتملة: لا زرّ، و«أكمل إعداد Paymob أولًا»', () => {
        page({ ready: false });

        expect(screen.queryByTestId('payment-links-new')).toBeNull();
        expect(screen.getByTestId('payment-links-not-configured')).toBeInTheDocument();
    });
});

describe('إعدادُ Paymob ← روابط الدفع', () => {
    const gateway = (over: Record<string, unknown> = {}) => ({
        active: true, public_key: 'pk_live_1', card_integration_id: '111', omannet_integration_id: '', apple_pay_integration_id: '',
        omannet: false, has_secret: true, has_hmac: true, state: 'ready', card_ready: true, apple_pay: false, checkout: true, online: true,
        webhook: 'https://app.abaadapp.com/webhooks/paymob', has_api_key: true, mode: 'test', links_ready: true, ...over,
    });

    it('مفتاحُ API حقلٌ فارغٌ يقول «مضبوط» — والبيئةُ مختارةٌ صراحةً', () => {
        reset({ gateway: gateway() });
        render(<Paymob />);

        const box = screen.getByTestId('paymob-links-settings');
        expect(screen.getByTestId('paymob-api-key')).toHaveValue('');
        expect(box).toHaveTextContent('مضبوط');
        expect(within(box).getByRole('radio', { name: 'تجريبي (Test)' })).toBeChecked();
        expect(within(box).getByRole('radio', { name: 'حقيقي (Live)' })).not.toBeChecked();
    });

    it('وبلا بيئةٍ مكتوبة لا يُختار شيءٌ من عنده', () => {
        reset({ gateway: gateway({ mode: null, has_api_key: false, links_ready: false }) });
        render(<Paymob />);

        const box = screen.getByTestId('paymob-links-settings');
        for (const radio of within(box).getAllByRole('radio')) {
            expect(radio).not.toBeChecked();
        }
    });
});
