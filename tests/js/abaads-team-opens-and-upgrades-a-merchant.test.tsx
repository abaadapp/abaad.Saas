import { useState } from 'react';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { router } from '@inertiajs/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import NewSubscriptionFields, { type NewSubscriptionOptions, type NewSubscriptionValues } from '@/Pages/Platform/Businesses/partials/NewSubscriptionFields';
import { UpgradeForm } from '@/Pages/Platform/Businesses/Show';

/**
 * فريقُ أبعاد يفتح اشتراكَ التاجر ويرقّيه — ويرى ما سيُفوتَر قبل أن يضغط.
 *
 *  • ثلاثُ طرق: تجربةُ الأساسيّة بلا فاتورة، ومدفوعٌ بانتظار السداد، ومنحةٌ بتاريخٍ وسبب.
 *  • الدوراتُ الثلاث بسعر الباقة في سياسة الحساب، ونصفُ السنة ستّةُ أشهرٍ بسعر الشهر.
 *  • المشمولُ في الباقة يُذكر ولا يُباع، والمدفوعُ يُختار ويدخل الإجماليّ بفاتورته.
 *  • والسياسةُ السابقة لا إضافاتٍ تُطلب فيها.
 *  • الترقيةُ في الحساب نفسِه بخطوتين: تُقرأ الباقةُ والمبلغ، ثمّ تُؤكَّد.
 *
 * والخادمُ يحكم بها كلِّها: `AbaadsTeamOpensAndGrowsEveryMerchantsSubscriptionTest`.
 */

const currency = { code: 'OMR', name: 'ريال عماني', symbol: 'ر.ع', decimals: 3, rate: 1, is_base: true, active: true } as never;

/** القائمةُ المنسدلة كما يقرؤها النموذج — `select` الخفيّة التي تحمل الخيار */
const nativeSelect = (value: string) => [...document.querySelectorAll('select')].find((s) => [...s.options].some((o) => o.value === value))!;

const OPTIONS: NewSubscriptionOptions = {
    policy: 'addons_v1',
    trial_days: 14,
    basic_plan_id: 1,
    cycles: [
        { value: 'monthly', label: 'شهري' },
        { value: 'semiannual', label: 'نصف سنوي' },
        { value: 'yearly', label: 'سنوي' },
    ],
    plans: [
        {
            id: 1, name: 'الأساسية', prices: { monthly: 9.9, semiannual: 59.4, yearly: 99 }, included: [],
            eligible: [
                { id: 10, name: 'نظام الكاشير ونقاط البيع POS', pricing: 'paid', counted: false, prices: { monthly: 9.9, semiannual: 59.4, yearly: 99 } },
                { id: 40, name: 'برنامج الولاء والمكافآت', pricing: 'paid', counted: false, prices: { monthly: 3.9, semiannual: 23.4, yearly: 39 } },
            ],
        },
        {
            id: 2, name: 'الاحترافية', prices: { monthly: 29.9, semiannual: 179.4, yearly: 299 },
            included: [{ id: 10, name: 'نظام الكاشير ونقاط البيع POS' }, { id: 40, name: 'برنامج الولاء والمكافآت' }],
            eligible: [
                { id: 60, name: 'فرع إضافي', pricing: 'paid', counted: true, prices: { monthly: 9.9, semiannual: 59.4, yearly: 99 } },
                { id: 90, name: 'التكامل مع الأنظمة الخارجية API', pricing: 'quote', counted: false, prices: null },
            ],
        },
    ],
};

function Screen({ options = OPTIONS, planId = '2', start = {} }: { options?: NewSubscriptionOptions; planId?: string; start?: Partial<NewSubscriptionValues> }) {
    const [values, setValues] = useState<NewSubscriptionValues>({ subscription_mode: 'trial', cycle: '', grant_until: '', grant_reason: '', addons: [], ...start });

    // داخل نموذجٍ كما في الشاشة — فتُرسم القائمةُ الخفيّة التي يحملها النموذج
    return (
        <form>
            <NewSubscriptionFields
                options={options}
                planId={planId}
                values={values}
                set={(key, value) => setValues((v) => ({ ...v, [key]: value }))}
                errors={{}}
                currency={currency}
            />
        </form>
    );
}

describe('a merchant opened by the Abaad team', () => {
    it('starts as a Basic trial with no invoice', () => {
        render(<Screen />);
        expect(screen.getByTestId('new-subscription-summary')).toHaveTextContent('تجربة الأساسية 14 يومًا — بلا فاتورة ولا دفع.');
        expect(screen.queryByTestId('new-subscription-addons')).toBeNull();
    });

    it('a paid plan offers three cycles at its policy price, names what it includes, and totals the plan with the chosen add-ons', async () => {
        const user = userEvent.setup();
        render(<Screen />);
        await user.click(screen.getByTestId('mode-paid'));

        // الدوراتُ الثلاث بسعر الباقة في سياسة الحساب — يقرؤها الملخّص
        const summaryOf = () => screen.getByTestId('new-subscription-summary').textContent ?? '';
        for (const [cycle, price] of [['monthly', '29.900'], ['yearly', '299.000'], ['semiannual', '179.400']] as const) {
            await user.selectOptions(nativeSelect(cycle), cycle);
            expect(summaryOf()).toContain(price);
        }

        const addons = screen.getByTestId('new-subscription-addons');
        expect(addons).toHaveTextContent('مشمولة في الباقة: نظام الكاشير ونقاط البيع POS، برنامج الولاء والمكافآت');
        // المشمولُ لا يُختار — المختارُ ما يُباع في الباقة وحده
        expect(within(addons).queryByText('نظام الكاشير ونقاط البيع POS', { selector: 'li span' })).toBeNull();

        await user.click(within(screen.getByTestId('new-addon-60')).getByRole('checkbox'));
        await user.clear(within(screen.getByTestId('new-addon-60')).getByLabelText('العدد'));
        await user.type(within(screen.getByTestId('new-addon-60')).getByLabelText('العدد'), '2');

        const summary = screen.getByTestId('new-subscription-summary');
        expect(summary).toHaveTextContent('الاحترافية · نصف سنوي');
        expect(screen.getByTestId('new-subscription-total').textContent).toMatch(/298\.200/);
        expect(screen.getByTestId('new-subscription-total')).toHaveTextContent('2 فاتورة');
        expect(summary).toHaveTextContent('«بانتظار السداد»');
    });

    it('an add-on on request has no total until its price is written', async () => {
        const user = userEvent.setup();
        render(<Screen start={{ subscription_mode: 'paid', cycle: 'monthly' }} />);
        await user.click(within(screen.getByTestId('new-addon-90')).getByRole('checkbox'));
        expect(screen.getByTestId('new-subscription-summary')).toHaveTextContent('يحتاج سعرًا');
        expect(screen.queryByTestId('new-subscription-total')).toBeNull();

        await user.type(within(screen.getByTestId('new-addon-90')).getByLabelText('سعر الوحدة'), '15');
        expect(screen.getByTestId('new-subscription-total').textContent).toMatch(/44\.900/);
    });

    it('a grant asks for its date and reason and says the account opens without payment until then', async () => {
        const user = userEvent.setup();
        render(<Screen />);
        await user.click(screen.getByTestId('mode-grant'));
        expect(screen.getByTestId('grant-until')).toBeInTheDocument();
        expect(screen.getByTestId('grant-reason')).toBeInTheDocument();
        await user.type(screen.getByTestId('grant-until'), '2026-12-01');
        expect(screen.getByTestId('new-subscription-summary')).toHaveTextContent('بلا سداد حتى 2026-12-01');
    });

    it('on the legacy policy no add-on is offered — every plan feature is included at its price', async () => {
        const user = userEvent.setup();
        render(<Screen options={{ ...OPTIONS, policy: 'legacy' }} />);
        await user.click(screen.getByTestId('mode-paid'));
        expect(screen.getByTestId('new-subscription-policy')).toHaveTextContent('السابقة');
        expect(screen.queryByTestId('new-subscription-addons')).toBeNull();
    });
});

describe('upgrading a merchant in its own account', () => {
    beforeEach(() => vi.mocked(router.post).mockClear());

    const upgrades = [
        { id: 2, name: 'الاحترافية', prices: { monthly: 29.9, semiannual: 179.4, yearly: 299 } },
        { id: 3, name: 'المؤسسات', prices: null },
    ];

    it('shows the plan and amount before issuing the invoice, then posts the plan and cycle', async () => {
        const user = userEvent.setup();
        render(<form><UpgradeForm businessId={5} upgrades={upgrades} disabled={false} currency={currency} /></form>);
        await user.selectOptions(nativeSelect('2'), '2');
        await user.selectOptions(nativeSelect('semiannual'), 'semiannual');
        await user.click(screen.getByTestId('upgrade-ask'));
        expect(router.post).not.toHaveBeenCalled();

        expect(screen.getByTestId('upgrade-confirm').textContent).toMatch(/الاحترافية.*179\.400/);
        await user.click(screen.getByTestId('upgrade-confirm'));
        const [url, body] = vi.mocked(router.post).mock.calls[0] as [string, Record<string, string>];
        expect(url).toBe('/super-admin.businesses.upgrade/5');
        expect(body).toEqual({ plan_id: '2', cycle: 'semiannual' });
    });

    it('a plan without an approved price, or an invoice already waiting, issues nothing', async () => {
        const user = userEvent.setup();
        const { unmount } = render(<form><UpgradeForm businessId={5} upgrades={upgrades} disabled={false} currency={currency} /></form>);
        await user.selectOptions(nativeSelect('3'), '3');
        expect(screen.getByTestId('upgrade-ask')).toBeDisabled();
        unmount();

        render(<form><UpgradeForm businessId={5} upgrades={upgrades} disabled currency={currency} /></form>);
        await user.selectOptions(nativeSelect('2'), '2');
        expect(screen.getByTestId('upgrade-ask')).toBeDisabled();
    });
});
