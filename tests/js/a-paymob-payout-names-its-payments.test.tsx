import { fireEvent, render, screen, within } from '@testing-library/react';
import { router } from '@inertiajs/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { pageProps } from './setup';

vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

const { default: PaymentLinksPage } = await import('@/Pages/Admin/Finance/PaymentLinks');

type Payout = import('@/Pages/Admin/Finance/PaymentLinks').Payout;

/**
 * تحويلُ Paymob يسمّي مدفوعاتِه على الشاشة — فلا يدخل مالٌ البنكَ مرّتين.
 *
 * ═══ ما يُحرس ═══
 *
 *   - المدفوعاتُ في ثلاث مجموعات بحسب أين مالُها: تحت التسوية، في البنك منذ
 *     يوم البيع، تحتاج مراجعة — وسببُ المراجعة تحت المدفوعة.
 *   - الإجماليُّ مجموعُ المختار لا رقمٌ يُكتب، والصافي يُقترح منه ومن الرسوم.
 *   - اختيارُ بيعةٍ في البنك يقول إنّ مالَها لن يُضاف ثانية؛ واختيارُ ما يحتاج
 *     مراجعة يقول إنّ التسوية تُحفظ ولا تُرحَّل.
 *   - التسويةُ المعلّقة تقول لماذا، وتُفحص ثانيةً أو تُلغى قبل ترحيلها بتأكيد.
 */

const payout = (over: Partial<Payout>): Payout => ({
    key: 'intent:1', type: 'intent', id: 1, amount: 20, label: 'طلب الموقع W-1', paid_at: '2027-02-10', booked: 'clearing', reason: null,
    ...over,
});

const PAYOUTS: Payout[] = [
    payout({}),
    payout({ key: 'link:4', type: 'link', id: 4, amount: 30, label: 'رابط دفع — تحصيل RC-4' }),
    payout({ key: 'intent:2', id: 2, amount: 20, label: 'طلب الموقع OLD-2', booked: 'bank' }),
    payout({ key: 'intent:3', id: 3, amount: 15, label: 'دفعٌ من الموقع بلا طلب', booked: 'unknown', reason: 'مالٌ قُبض في Paymob ولا طلبَ له في المتجر' }),
];

const page = (over: Record<string, unknown> = {}) => {
    for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    Object.assign(pageProps, {
        translations: {}, context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 } },
        auth: { abilities: ['finance'], mayActions: [] }, flash: { toast: null, status: null },
        ready: true, can: true, can_cancel: true, settings_url: '/admin/integrations/paymob', links: [],
        status: null, counts: {}, customers: [], clearing: 50, payouts: PAYOUTS, settlements: [], bankAccounts: [{ value: '4', label: 'بنك مسقط' }],
        ...over,
    });

    return render(<PaymentLinksPage />);
};

const open = () => {
    fireEvent.click(screen.getByTestId('paymob-settle-open'));

    return screen.getByTestId('paymob-settle-form');
};

beforeEach(() => vi.mocked(router.post).mockClear());
afterEach(() => vi.restoreAllMocks());

describe('تسجيلُ تسوية Paymob', () => {
    it('المدفوعاتُ في مجموعاتها بحسب أين مالُها، وسببُ المراجعة ظاهر', () => {
        page();
        open();

        expect(within(screen.getByTestId('paymob-settle-group-clearing')).getAllByTestId('paymob-settle-pick')).toHaveLength(2);
        const bank = screen.getByTestId('paymob-settle-group-bank');
        expect(bank).toHaveTextContent('قُيّدت في البنك يوم البيع');
        expect(bank).toHaveTextContent('طلب الموقع OLD-2');
        const unknown = screen.getByTestId('paymob-settle-group-unknown');
        expect(unknown).toHaveTextContent('تحتاج مراجعة');
        expect(unknown).toHaveTextContent('مالٌ قُبض في Paymob ولا طلبَ له في المتجر');
    });

    it('الإجماليُّ مجموعُ المختار، والصافي يُقترح منه ومن الرسوم — ولا تسويةَ بلا مدفوعة', () => {
        page();
        const form = open();

        expect(within(form).getByTestId('paymob-settle-submit')).toBeDisabled();
        fireEvent.click(within(form).getByRole('checkbox', { name: 'طلب الموقع W-1' }));
        fireEvent.click(within(form).getByRole('checkbox', { name: 'رابط دفع — تحصيل RC-4' }));

        expect(within(form).getByTestId('paymob-settle-gross')).toHaveValue('50.000');
        fireEvent.change(within(form).getByRole('textbox', { name: 'الرسوم' }), { target: { value: '1.5' } });
        expect(within(form).getByRole('textbox', { name: 'الصافي' })).toHaveValue('48.500');
        expect(within(form).getByTestId('paymob-settle-submit')).toBeEnabled();
        expect(screen.queryByTestId('paymob-settle-bank-notice')).toBeNull();
        expect(screen.queryByTestId('paymob-settle-hold-notice')).toBeNull();
    });

    it('بيعةٌ مالُها في البنك منذ يوم البيع: لن يُضاف ثانية', () => {
        page();
        const form = open();

        fireEvent.click(within(form).getByRole('checkbox', { name: 'طلب الموقع OLD-2' }));

        expect(screen.getByTestId('paymob-settle-bank-notice')).toHaveTextContent('لن يُضاف مرّة ثانية');
        expect(within(form).getByTestId('paymob-settle-gross')).toHaveValue('20.000');
    });

    it('وما يحتاج مراجعة: تُحفظ التسوية ولا تُرحَّل', () => {
        page();
        const form = open();

        fireEvent.click(within(form).getByRole('checkbox', { name: 'دفعٌ من الموقع بلا طلب' }));

        expect(screen.getByTestId('paymob-settle-hold-notice')).toHaveTextContent('ستُحفظ التسوية ولا تُرحَّل');
    });

    it('ولا مدفوعةَ تنتظر: لا زرّ تسوية', () => {
        page({ payouts: [] });

        expect(screen.getByTestId('paymob-settle-open')).toBeDisabled();
    });
});

describe('التسويةُ المعلّقة', () => {
    const held = { id: 9, settled_on: '2027-02-13', gross: 35, fee: 1.05, net: 33.95, bank: 'بنك مسقط', reference: 'PMB-9', notes: null, items: 2,
        status: 'held', hold_reason: 'دفعٌ من الموقع بلا طلب: مالٌ قُبض في Paymob ولا طلبَ له في المتجر' };

    it('تقول لماذا لم تُرحَّل، وتُفحص ثانيةً', () => {
        page({ settlements: [held] });

        const status = screen.getByTestId('paymob-settlement-status');
        expect(status).toHaveTextContent('معلّقة للمراجعة — لم تُرحَّل');
        expect(within(status).getByTestId('paymob-settlement-hold-reason')).toHaveTextContent('ولا طلبَ له في المتجر');

        fireEvent.click(within(status).getByTestId('paymob-settlement-retry'));
        expect(vi.mocked(router.post).mock.calls[0][0]).toBe('/admin.finance.paymobSettlements.retry/9');
    });

    it('وتُلغى قبل ترحيلها بتأكيدٍ من النظام — لا بنافذة المتصفّح', async () => {
        const native = vi.spyOn(window, 'confirm');
        page({ settlements: [held] });

        fireEvent.click(screen.getByTestId('paymob-settlement-cancel'));
        expect(router.post).not.toHaveBeenCalled();
        fireEvent.click(await screen.findByRole('button', { name: 'إلغاء التسوية' }));

        await vi.waitFor(() => expect(vi.mocked(router.post).mock.calls[0]?.[0]).toBe('/admin.finance.paymobSettlements.cancel/9'));
        expect(native).not.toHaveBeenCalled();
    });

    it('والمرحّلةُ والملغاةُ بلا أزرار', () => {
        page({ settlements: [{ ...held, status: 'posted', hold_reason: null }, { ...held, id: 10, status: 'cancelled' }] });

        const [posted, cancelled] = screen.getAllByTestId('paymob-settlement-status');
        expect(posted).toHaveTextContent('مرحّلة');
        expect(cancelled).toHaveTextContent('أُلغيت قبل الترحيل');
        expect(screen.queryByTestId('paymob-settlement-retry')).toBeNull();
    });
});

describe('تحويلٌ سُجّل قبل التحديث', () => {
    const legacy = {
        id: 5, settled_on: '2027-02-11', gross: 30, fee: 0.9, net: 29.1, bank: 'بنك مسقط', reference: 'OLD-PMB', notes: null, items: 0,
        status: 'legacy', hold_reason: null, open: 30, attributed: [],
    };
    const suspects: Payout[] = [
        payout({ key: 'link:1', type: 'link', id: 1, amount: 30, label: 'رابط دفع — تحصيل RC-1', booked: 'unknown', reason: 'ربما حملها تحويلٌ سُجّل قبل التحديث', legacy: [5] }),
        payout({ key: 'link:2', type: 'link', id: 2, amount: 20, label: 'رابط دفع — تحصيل RC-2', booked: 'unknown', reason: 'ربما حملها تحويلٌ سُجّل قبل التحديث', legacy: [5] }),
        payout({ key: 'intent:9', id: 9, amount: 20, label: 'طلب الموقع W-9', booked: 'clearing', legacy: [] }),
    ];

    it('يقول إنّه لا يسمّي مدفوعاته وكم بقي منه غيرَ محدَّد', () => {
        page({ settlements: [legacy], payouts: suspects });

        const status = screen.getByTestId('paymob-settlement-status');
        expect(status).toHaveTextContent('سُجّلت قبل التحديث — لا تسمّي مدفوعاتها');
        expect(within(status).getByTestId('paymob-settlement-open')).toHaveTextContent('30.000');
    });

    it('ويُحدَّد ما حمله ممّا دُفع قبله وحده، بمستند، ولا يتجاوز ما بقي', () => {
        page({ settlements: [legacy], payouts: suspects });
        fireEvent.click(screen.getByTestId('paymob-settlement-name'));
        const form = screen.getByTestId('paymob-legacy-form');

        // ما دُفع بعده لا يُعرض
        expect(within(form).getAllByTestId('paymob-legacy-pick')).toHaveLength(2);
        expect(within(form).queryByRole('checkbox', { name: 'طلب الموقع W-9' })).toBeNull();

        fireEvent.click(within(form).getByRole('checkbox', { name: 'رابط دفع — تحصيل RC-1' }));
        expect(within(form).getByTestId('paymob-legacy-submit')).toBeDisabled();   // بلا مستند
        fireEvent.change(within(form).getByRole('textbox', { name: 'ما استندتَ إليه' }), { target: { value: 'كشف Paymob 11 فبراير' } });
        expect(within(form).getByTestId('paymob-legacy-submit')).toBeEnabled();

        fireEvent.click(within(form).getByRole('checkbox', { name: 'رابط دفع — تحصيل RC-2' }));
        expect(screen.getByTestId('paymob-legacy-over')).toHaveTextContent('أكبر مما بقي');
        expect(within(form).getByTestId('paymob-legacy-submit')).toBeDisabled();
    });

    it('وتحديدٌ خاطئ يُترك بتأكيدٍ من النظام', async () => {
        page({
            settlements: [{ ...legacy, open: 0, attributed: [{ id: 41, label: 'رابط دفع — تحصيل RC-1', amount: 30, reason: 'كشف Paymob', by: 'المالك', at: '2027-02-12 09:00' }] }],
            payouts: suspects,
        });
        fireEvent.click(screen.getByTestId('paymob-settlement-name'));
        expect(screen.getByTestId('paymob-legacy-attributed')).toHaveTextContent('كشف Paymob · المالك');

        fireEvent.click(screen.getByTestId('paymob-legacy-undo'));
        fireEvent.click(await screen.findByRole('button', { name: 'ترك التحديد' }));
        await vi.waitFor(() => expect(vi.mocked(router.post).mock.calls[0]?.[0]).toBe('/admin.finance.paymobSettlementItems.remove/41'));
    });

    it('والتسجيلُ الموقوفُ من المنصّة يقول ذلك ولا يفتح نافذةَ التسوية', () => {
        page({ payouts_paused: true });

        expect(screen.getByTestId('paymob-settle-paused')).toHaveTextContent('موقوف مؤقتًا');
        expect(screen.getByTestId('paymob-settle-open')).toBeDisabled();
    });
});

/*
 * وما ينتظر يُعرض دفعةً بعد دفعة: لا تختفي مدفوعةٌ خلف الأولى (كانت تُقطع عند
 * 300 بلا تتمّة). الزرُّ يجلب التاليةَ بالمؤشّر ويضيفها إلى ما يُختار منه.
 */
describe('ما ينتظر يُعرض دفعةً بعد دفعة', () => {
    it('بلا دفعةٍ تالية لا زرّ', () => {
        page();
        open();

        expect(screen.queryByTestId('paymob-payouts-more')).toBeNull();
    });

    it('الدفعةُ التالية تُجلب بالمؤشّر وتُضاف إلى ما يُختار منه', async () => {
        const next = payout({ key: 'link:9', type: 'link', id: 9, amount: 12, label: 'رابط دفع — تحصيل RC-9' });
        const fetched = vi.spyOn(globalThis, 'fetch').mockResolvedValue(
            new Response(JSON.stringify({ payouts: [next], next: null }), { status: 200, headers: { 'Content-Type': 'application/json' } }),
        );
        page({ payouts: [PAYOUTS[0]], payouts_next: '2027-02-10 10:00:00|intent|1', payouts_waiting: 2 });
        const form = open();

        expect(within(form).getByTestId('paymob-payouts-more')).toHaveTextContent('يُعرض 1 من 2 مدفوعة تنتظر');
        fireEvent.click(within(form).getByTestId('paymob-payouts-more-load'));

        expect(await within(form).findByText('رابط دفع — تحصيل RC-9')).toBeInTheDocument();
        expect(JSON.stringify(fetched.mock.calls[0][0])).toContain('admin.finance.paymobSettlements.candidates');
        expect(JSON.stringify(fetched.mock.calls[0][0])).toContain('2027-02-10 10:00:00|intent|1');
        expect(within(form).queryByTestId('paymob-payouts-more')).toBeNull();
        expect(within(screen.getByTestId('paymob-settle-group-clearing')).getAllByTestId('paymob-settle-pick')).toHaveLength(2);
    });
});
