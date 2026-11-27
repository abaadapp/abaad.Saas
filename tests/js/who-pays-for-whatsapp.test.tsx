import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { router } from '@inertiajs/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import WhatsappBilling, { type BillingState, type MerchantBilling } from '@/Components/WhatsappBilling';
import WhatsappSender, { type SenderView } from '@/Components/WhatsappSender';
import WhatsAppBillingReview, { type PlatformBilling } from '@/Pages/Platform/Businesses/partials/WhatsAppBillingReview';
import OwnNumbersHealth, { type OwnNumberRow } from '@/Pages/Platform/Settings/partials/OwnNumbersHealth';
import WhatsAppBillingLock, { type BillingLockState } from '@/Pages/Platform/Settings/partials/WhatsAppBillingLock';
import WhatsAppMerchantLinkSwitch, { type WhatsAppLinkState } from '@/Pages/Platform/Settings/partials/WhatsAppMerchantLinkSwitch';
import WhatsAppSharedCost, { type SharedCost } from '@/Pages/Platform/Settings/partials/WhatsAppSharedCost';

/**
 * ‏‏على من رسومُ واتساب — ما يراه التاجر ومديرُ المنصّة، وما لا يُضغط قبل أوانه.
 *
 * والقرارُ على الخادم (`WhatsAppBillingLock`): هنا تُحرس الشاشةُ لا القفل.
 */

const MINE: MerchantBilling = {
    state: 'unverified', label: 'لم يتم التحقق', account: '1001', owner: null, text: 'لم تثبت جهة رسوم واتساب بعد', who: 'merchant',
    trial: false, may_request: true, may_retry: false, requested_at: null, proven_at: null,
};

const REVIEW: PlatformBilling = {
    state: 'unverified', label: 'لم يتم التحقق', locked: true, stopped: 'لم تثبت جهة رسوم واتساب بعد', account: '1001', owner: 'نشاط التاجر', owner_id: 'MB-SHOP',
    may_confirm: true, may_reopen: false, may_retry: false, requested_at: null, requested_by: null,
    facts: { owner_known: true, abaad_known: true, ownership: 'SELF', partner_owned: false, funding: 'unreadable', abaad_access: 'none', read_at: '2026-11-25 10:00' },
    evidence: null, estimate: null, classified: { total: 5, charged: 3, free: 1, unknown: 1, days: 30 }, trial: false,
    kinds: [{ key: 'payment_settings', label: 'صفحة الفوترة' }], events: [],
};

const LOCK: BillingLockState = {
    launched: false, passed: null, trial: null, kinds: [{ key: 'billing_charge', label: 'رسم ظهر' }],
    defaults: { messages: 15, hours: 72, max_messages: 50, max_hours: 168, max_phones: 5 },
};

const TRIAL: NonNullable<BillingLockState['trial']> = {
    id: 4, business_id: 7, business: 'متجر التجربة', waba_id: '1001', open: true, used: 2, limit: 15, until: '2026-11-28 10:00', opened_by: 'المدير', may_pass: true,
    phones: ['96891234567', '96895555555'],
    messages: [
        { id: 11, tail: 'AAAA1111', at: '2026-11-25 10:00', kind: 'إشعار من النظام', category: 'إشعار', status: 'delivered', delivered: true, charged: true, why_free: null },
        { id: 12, tail: 'BBBB2222', at: '2026-11-25 10:01', kind: 'محادثة', category: 'ردّ خدمة', status: 'delivered', delivered: true, charged: false, why_free: 'ضمن الردود المجانية الشهرية' },
        { id: 13, tail: 'CCCC3333', at: '2026-11-25 10:02', kind: 'حملة', category: 'تسويق', status: 'sent', delivered: false, charged: true, why_free: null },
        { id: 14, tail: 'DDDD4444', at: '2026-11-25 10:03', kind: 'أخرى', category: 'لم تقل Meta', status: 'delivered', delivered: true, charged: null, why_free: null },
    ],
};

const STATE: WhatsAppLinkState = {
    mode: 'own_app', default: 'own_app', platform: null, readable: true, config_id: '2177821789438050', config_source: 'platform',
    app_id_ready: true, app_secret_ready: true, ready: true, coexistence: false, pilot: [], pilot_max: 1, coexistence_fields: ['history'], history: [], billing: LOCK,
};

const last = (fn: unknown) => vi.mocked(fn as typeof router.post).mock.calls.at(-1);

beforeEach(() => {
    vi.mocked(router.post).mockClear();
    vi.mocked(router.delete).mockClear();
});

describe('التاجر', () => {
    it('يقرأ أنّ الرسوم عليه لا على أبعاد، وما بقي، ولا يُطلب منه ملفّ', () => {
        const { container } = render(<WhatsappBilling billing={MINE} mayManage />);
        expect(screen.getByTestId('wa-billing')).toHaveTextContent('أبعاد لا تدفعها عنك');
        expect(screen.getByTestId('wa-billing-text')).toHaveTextContent('ولا تخرج من رقم آخر');
        expect(screen.getByTestId('wa-billing-steps')).toHaveTextContent('أخفِ رقم البطاقة');
        expect(screen.getByTestId('wa-billing-steps')).toHaveTextContent('1001');
        expect(container.querySelector('input')).toBeNull();
        expect(screen.getByRole('link')).toHaveAttribute('href', expect.stringContaining('business.facebook.com/latest/billing_hub/payment_methods'));
    });

    it('يطلب التحقق بضغطة — ومن لا يدير الربط لا زرَّ له', async () => {
        const user = userEvent.setup();
        const { rerender } = render(<WhatsappBilling billing={MINE} mayManage={false} />);
        expect(screen.queryByTestId('wa-billing-request')).toBeNull();
        rerender(<WhatsappBilling billing={MINE} mayManage />);
        await user.click(screen.getByTestId('wa-billing-request'));
        expect(last(router.post)?.[0]).toBe('/admin.integrations.whatsapp.billing.request');
    });

    it('ويعيد المحاولة حين تُردّ وسيلةُ دفعه وحدها', async () => {
        const user = userEvent.setup();
        render(<WhatsappBilling billing={{ ...MINE, state: 'problem', label: 'مشكلة في الفوترة', may_request: false, may_retry: true, text: 'ردّت Meta رسالة' }} mayManage />);
        expect(screen.queryByTestId('wa-billing-request')).toBeNull();
        await user.click(screen.getByTestId('wa-billing-retry'));
        expect(last(router.post)?.[0]).toBe('/admin.integrations.whatsapp.billing.retry');
    });

    it('ومن ثبت يقرأ على من تُحتسب، ولا زرَّ ولا تحذير', () => {
        render(<WhatsappBilling billing={{ ...MINE, state: 'proven', label: 'الفوترة المباشرة للتاجر مثبتة', text: null, may_request: false, owner: 'شركة الورد', proven_at: '2026-11-25 10:00' }} mayManage />);
        expect(screen.getByTestId('wa-billing-proven')).toHaveTextContent('شركة الورد');
        expect(screen.queryByRole('button')).toBeNull();
        expect(screen.queryByTestId('wa-billing-text')).toBeNull();
    });

    it('ويظهر في بطاقة رقم الإرسال له وحده، ولو لم يكن فيها غيرُه', () => {
        const none: SenderView = { sends_from: 'own', phone: null, own_app: false, state: 'none', steps: [], blocking: null, checked_at: null, adopted_at: null, problem: null, shared_possible: false, history: [], billing: MINE };
        const { rerender, container } = render(<WhatsappSender sender={none} audience="merchant" mayManage />);
        expect(screen.getByTestId('wa-billing')).toBeInTheDocument();
        rerender(<WhatsappSender sender={none} audience="platform" />);
        expect(container).toBeEmptyDOMElement();
        rerender(<WhatsappSender sender={{ ...none, billing: null, own_app: true }} audience="merchant" mayManage />);
        expect(container).toBeEmptyDOMElement();
    });
});

describe('مراجعةُ مدير المنصّة', () => {
    it('لا تُثبت قبل أن يُكتب كلُّ شيء ويُقرّ بالثلاثة', async () => {
        const user = userEvent.setup();
        render(<WhatsAppBillingReview businessId={9} review={REVIEW} />);
        const go = screen.getByTestId('wa-billing-confirm');
        expect(go).toBeDisabled();
        await user.selectOptions(screen.getByTestId('wa-billing-kind'), 'payment_settings');
        await user.type(screen.getByTestId('wa-billing-reference'), 'صورة 25');
        await user.type(screen.getByTestId('wa-billing-waba'), '1001');
        await user.click(screen.getByTestId('wa-billing-evidence_checked'));
        await user.click(screen.getByTestId('wa-billing-evidence_owner'));
        expect(go).toBeDisabled();
        await user.click(screen.getByTestId('wa-billing-evidence_card'));
        expect(go).toBeEnabled();
    });

    it('تقول إنّ الملكيّة لا تكفي، وتعرض ما قرأته وما لم تُطلعها عليه Meta', () => {
        render(<WhatsAppBillingReview businessId={9} review={REVIEW} />);
        expect(screen.getByTestId('wa-billing-form')).toHaveTextContent('ملكية الحساب لا تكفي');
        expect(screen.getByTestId('wa-billing-facts')).toHaveTextContent('لا تُطلعنا Meta عليها');
        expect(screen.getByTestId('wa-billing-stopped')).toHaveTextContent('الإرسال متوقف');
        expect(screen.getByTestId('wa-cost-classified')).toHaveTextContent('محتسبة 3');
    });

    it('والتقديرُ يُقال تقديرًا، وكلفةٌ محجوبة لا تُكتب صفرًا', () => {
        const estimate = { state: 'cost_hidden' as const, cost: null, volume: 4, charged: 4, rows: [], from: '2026-10-26', to: '2026-11-25', read_at: null };
        render(<WhatsAppBillingReview businessId={9} review={{ ...REVIEW, estimate }} />);
        expect(screen.getByTestId('wa-cost-estimate')).toHaveTextContent('Meta لا تعرض كلفة هذا الحساب');
        expect(screen.getByTestId('wa-cost')).toHaveTextContent('ولا يُقبل دليلًا');
        expect(screen.getByTestId('wa-cost-estimate')).not.toHaveTextContent(/\b0\b/);
    });

    it('وما ثبت يُعاد فتحُه بسبب، ولا يُعرض نموذجُ إثباتٍ فوقه', async () => {
        const user = userEvent.setup();
        render(<WhatsAppBillingReview businessId={9} review={{ ...REVIEW, state: 'proven', label: 'مثبتة', stopped: null, may_reopen: true }} />);
        expect(screen.queryByTestId('wa-billing-form')).toBeNull();
        expect(screen.getByTestId('wa-billing-reopen')).toBeDisabled();
        await user.type(screen.getByTestId('wa-billing-reason'), 'اشتباه');
        await user.click(screen.getByTestId('wa-billing-reopen'));
        expect(last(router.post)).toEqual(['/super-admin.businesses.whatsapp.billing.reopen/9', { reason: 'اشتباه' }, expect.anything()]);
    });

    it('وربطٌ من قبل القفل يُقال إنّه لا يتوقّف، ولا نموذجَ قفلٍ له', () => {
        render(<WhatsAppBillingReview businessId={9} review={{ ...REVIEW, locked: false, stopped: null }} />);
        expect(screen.getByTestId('wa-billing-legacy')).toHaveTextContent('لا يتوقف إرساله');
        expect(screen.queryByTestId('wa-billing-form')).toBeNull();
    });

    it('وجدولُ الأرقام يحمل حالَ كلّ متجر — خمسٌ بأسمائها', () => {
        const health = { key: 'ready', label: 'جاهز', hint: '', tone: 'info', checked_at: null } as OwnNumberRow['health'];
        const states: BillingState[] = ['unverified', 'pending', 'proven', 'problem', 'recheck'];
        render(<OwnNumbersHealth rows={states.map((state, i) => ({ business_id: i + 1, business: 'م', phone: null, inbox: true, health, billing: { state, label: `حال ${state}`, locked: state !== 'unverified', own_app: false, text: null } }))} />);
        // ومن ثبت آخرًا
        expect(screen.getAllByTestId('own-number-billing').map((e) => e.getAttribute('data-state'))).toEqual(['unverified', 'pending', 'problem', 'recheck', 'proven']);
        expect(screen.getAllByTestId('own-number-billing')[0]).toHaveTextContent('ربط من قبل القفل');
    });

    describe('وعددُ من لم تثبت جهةُ رسومه', () => {
        const health = { key: 'ready', label: 'جاهز', hint: '', tone: 'info', checked_at: null } as OwnNumberRow['health'];
        const row = (id: number, billing: Partial<NonNullable<OwnNumberRow['billing']>> | null): OwnNumberRow => ({
            business_id: id, business: `متجر ${id}`, phone: null, inbox: true, health,
            billing: billing && { state: 'unverified', label: `حال ${billing.state ?? 'unverified'}`, locked: true, own_app: false, sends: false, text: null, ...billing },
        });

        it('يُعدّ من الصفوف نفسِها، بحالٍ لكلّ واحدة، ومن لم يثبت أوّلًا', () => {
            render(<OwnNumbersHealth rows={[row(1, { state: 'proven', sends: true }), row(2, {}), row(3, { state: 'problem' }), row(4, {}), row(5, { state: 'pending' }), row(6, { state: 'recheck' })]} />);
            const tally = screen.getByTestId('billing-tally');
            expect([tally.getAttribute('data-unproven'), tally.getAttribute('data-total')]).toEqual(['5', '6']);
            expect(tally).toHaveTextContent('لم تثبت جهة رسوم واتساب عند 5 من 6 من المتاجر');
            expect(screen.getAllByTestId('billing-tally-state').map((e) => [e.getAttribute('data-state'), e.getAttribute('data-count')]))
                .toEqual([['problem', '1'], ['recheck', '1'], ['unverified', '2'], ['pending', '1'], ['proven', '1']]);
            expect(screen.getAllByTestId('own-number-row')).toHaveLength(6);
            expect(screen.getAllByTestId('own-number-row')[5]).toHaveTextContent('متجر 1');
            expect(screen.queryByTestId('billing-tally-sending')).toBeNull();
        });

        it('ويُقال كم منها يُرسل لأنه رُبط قبل القفل — لا متجرَ التجربة ولا من بتطبيقه', () => {
            render(<OwnNumbersHealth rows={[
                row(1, { locked: false, sends: true }), row(2, { locked: false, sends: true }), row(3, { locked: false, sends: false }),
                row(4, { locked: true, sends: true }), row(5, { locked: false, own_app: true, sends: true, state: 'pending' }), row(6, { locked: false, sends: true, state: 'proven' }),
            ]} />);
            expect(screen.getByTestId('billing-tally-sending').getAttribute('data-count')).toBe('2');
            expect(screen.getByTestId('billing-tally-sending')).toHaveTextContent('لا تتوقف إلا بقرارك');
            expect(screen.getAllByText('بتطبيقه الخاص')).toHaveLength(1);
        });

        it('وثبوتُها عند الكلّ يُقال، وبلا متجرٍ مربوط لا عدد', () => {
            const { rerender } = render(<OwnNumbersHealth rows={[row(1, { state: 'proven' }), row(2, { state: 'proven', own_app: true })]} />);
            expect(screen.getByTestId('billing-tally')).toHaveTextContent('ثبتت جهة رسوم واتساب عند كل المتاجر (2)');
            rerender(<OwnNumbersHealth rows={[]} />);
            expect(screen.queryByTestId('billing-tally')).toBeNull();
            rerender(<OwnNumbersHealth rows={[row(9, null)]} />);
            expect(screen.queryByTestId('billing-tally')).toBeNull();
        });
    });
});

describe('قفلُ النقل والتجربة', () => {
    it('زرُّ النقل مطفأ حتى تنجح التجربة — وحالٌ لم يُقرأ قفل', () => {
        const { rerender } = render(<WhatsAppMerchantLinkSwitch state={STATE} />);
        expect(screen.getByTestId('wa-link-flip')).toBeDisabled();
        expect(screen.getByTestId('wa-link-locked')).toHaveTextContent('مقفل حتى تنجح تجربة حقيقية');
        rerender(<WhatsAppMerchantLinkSwitch state={{ ...STATE, billing: null }} />);
        expect(screen.getByTestId('wa-link-flip')).toBeDisabled();
        rerender(<WhatsAppMerchantLinkSwitch state={{ ...STATE, held: true }} />);
        expect(screen.getByTestId('wa-link-locked')).toHaveTextContent('لا يسري حتى يُفتح القفل');
        rerender(<WhatsAppMerchantLinkSwitch state={{ ...STATE, billing: { ...LOCK, launched: true } }} />);
        expect(screen.getByTestId('wa-link-flip')).toBeEnabled();
        expect(screen.queryByTestId('wa-link-locked')).toBeNull();
    });

    it('ومن نُقل يعود بلا قفل', () => {
        render(<WhatsAppMerchantLinkSwitch state={{ ...STATE, mode: 'abaad_app', billing: { ...LOCK, launched: true } }} />);
        expect(screen.getByTestId('wa-link-flip')).toBeEnabled();
    });

    it('لا تجربةَ بلا متجرٍ اختباريّ، ولا تُفتح بلا موافقةٍ على رسومٍ حقيقيّة', async () => {
        const user = userEvent.setup();
        const { rerender } = render(<WhatsAppBillingLock state={LOCK} pilot={[]} />);
        expect(screen.getByTestId('wa-trial-no-pilot')).toBeInTheDocument();
        expect(screen.queryByTestId('wa-trial-start')).toBeNull();
        rerender(<WhatsAppBillingLock state={LOCK} pilot={[{ id: 7, name: 'متجر التجربة' }]} />);
        expect(screen.getByTestId('wa-trial-start')).toBeDisabled();
        await user.click(screen.getByTestId('wa-trial-accept'));
        // ‏‏ولا تُفتح بلا رقم اختبار — رسائلُها لا تخرج إلّا إليه
        expect(screen.getByTestId('wa-trial-start')).toBeDisabled();
        await user.type(screen.getByTestId('wa-trial-phones'), '   ');
        expect(screen.getByTestId('wa-trial-start')).toBeDisabled();
        await user.clear(screen.getByTestId('wa-trial-phones'));
        await user.type(screen.getByTestId('wa-trial-phones'), '91234567');
        expect(screen.getByTestId('wa-trial-start')).toBeEnabled();
        expect(screen.getByTestId('wa-trial-phones')).toHaveValue('91234567');
    });

    it('والتجربةُ المفتوحة تقول إلى أيّ أرقامٍ وحدها تخرج رسائلُها', () => {
        const { rerender } = render(<WhatsAppBillingLock state={{ ...LOCK, trial: TRIAL }} pilot={[]} />);
        const list = screen.getByTestId('wa-trial-phones-list');
        expect(list).toHaveTextContent('+96891234567');
        expect(list).toHaveTextContent('+96895555555');
        rerender(<WhatsAppBillingLock state={{ ...LOCK, trial: { ...TRIAL, phones: [] } }} pilot={[]} />);
        expect(screen.getByTestId('wa-trial-phones-list')).toHaveTextContent('لا رقم، فلا تخرج رسالة');
    });

    it('ورسائلُ التجربة تُعرض كما صنّفتها Meta، ولا يُختار للدليل إلّا ما وصل واحتُسب', () => {
        render(<WhatsAppBillingLock state={{ ...LOCK, trial: TRIAL }} pilot={[]} />);
        const list = screen.getByTestId('wa-trial-messages-list');
        expect(list).toHaveTextContent('ضمن الردود المجانية الشهرية');
        expect(list).toHaveTextContent('لم تصل بعد');
        expect(within(list).getAllByRole('listitem')[3]).toHaveTextContent('لم تقل Meta');
        expect(screen.getByTestId('wa-trial-used')).toHaveTextContent('2 / 15');
        const options = within(screen.getByTestId('wa-pass-message')).getAllByRole('option').map((o) => (o as HTMLOptionElement).value);
        expect(options).toEqual(['', '11']);
    });

    it('ولا تُسجَّل النتيجةُ قبل أن يُرى الرسمُ عند التاجر ويُراجَع حسابُ أبعاد', async () => {
        const user = userEvent.setup();
        render(<WhatsAppBillingLock state={{ ...LOCK, trial: TRIAL }} pilot={[]} />);
        const go = screen.getByTestId('wa-pass-submit');
        await user.selectOptions(screen.getByTestId('wa-pass-message'), '11');
        await user.selectOptions(screen.getByTestId('wa-pass-kind'), 'billing_charge');
        await user.type(screen.getByTestId('wa-pass-waba'), '1001');
        await user.type(screen.getByTestId('wa-pass-reference'), 'رسم 25');
        await user.type(screen.getByTestId('wa-pass-abaad'), 'راجعته 18:00');
        await user.click(screen.getByTestId('wa-pass-merchant-checked'));
        expect(go).toBeDisabled();
        await user.click(screen.getByTestId('wa-pass-abaad-checked'));
        expect(go).toBeEnabled();
    });

    it('وإغلاقُها بتأكيد', async () => {
        const user = userEvent.setup();
        render(<WhatsAppBillingLock state={{ ...LOCK, trial: TRIAL }} pilot={[]} />);
        await user.click(screen.getByTestId('wa-trial-close'));
        expect(router.delete).not.toHaveBeenCalled();
        const dialog = await screen.findByRole('alertdialog').catch(() => screen.findByRole('dialog'));
        await user.click(within(dialog).getByRole('button', { name: 'أغلقها' }));
        expect(last(router.delete)?.[0]).toBe('/super-admin.whatsapp.billing-trial.close/4');
    });

    it('وبعد نجاحها يُعاد القفل بسبب، ولا نموذجَ تجربة', async () => {
        const user = userEvent.setup();
        render(<WhatsAppBillingLock state={{ ...LOCK, launched: true, passed: { business: 'متجر', at: '2026-11-25 10:00', by: 'المدير', kind: 'رسم', reference: 'R', amount: '0.04 USD' } }} pilot={[{ id: 7, name: 'م' }]} />);
        expect(screen.queryByTestId('wa-trial-open')).toBeNull();
        expect(screen.getByTestId('wa-lock-passed')).toHaveTextContent('0.04 USD');
        await user.type(screen.getByTestId('wa-lock-reason'), 'ظهر رسم');
        await user.click(screen.getByTestId('wa-lock-relock'));
        expect(last(router.post)).toEqual(['/super-admin.whatsapp.billing-lock.relock', { reason: 'ظهر رسم' }, expect.anything()]);
    });
});

describe('كلفةُ رقم أبعاد المشترك', () => {
    const COST: SharedCost = { linked: true, days: 30, estimate: null, classified: { total: 9, charged: 7, free: 2, unknown: 0, days: 30 }, by_category: [{ label: 'إشعار', n: 7 }], by_shop: [{ business: 'محل ورد', n: 7 }] };

    it('تُعرض عددًا لا مبلغًا حتى تُقرأ، ولا تظهر بلا رقمٍ مشترك', async () => {
        const user = userEvent.setup();
        const { rerender, container } = render(<WhatsAppSharedCost cost={COST} />);
        expect(screen.getByTestId('wa-shared-categories')).toHaveTextContent('إشعار 7');
        expect(screen.getByTestId('wa-shared-shops')).toHaveTextContent('محل ورد');
        expect(screen.queryByTestId('wa-cost-estimate')).toBeNull();
        await user.click(screen.getByTestId('wa-shared-read'));
        expect(last(router.post)?.[0]).toBe('/super-admin.whatsapp.shared-cost');
        rerender(<WhatsAppSharedCost cost={{ ...COST, linked: false }} />);
        expect(container).toBeEmptyDOMElement();
    });
});
