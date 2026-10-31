import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import type { EmbeddedConfig } from '@/Pages/Admin/Integrations/partials/EmbeddedSignup';
import type { OwnAppView } from '@/Pages/Admin/Integrations/partials/OwnAppLink';
import WhatsappLinkCard, { pillFor, type WhatsappLink } from '@/Pages/Admin/Integrations/partials/WhatsappLinkCard';
import { BillingReviewBlock, type BillingReview } from '@/Pages/Platform/Businesses/partials/WhatsAppCard';

/**
 * التاجرُ يربط واتسابه بتطبيقه هو عند ميتا — والشاشةُ تمشيه خطوةً خطوة.
 *
 *  • لا زرَّ لتطبيق أبعاد ولا اختيارَ تعايش: الدليلُ والنموذجُ مكانهما.
 *  • «تحقّق وحفظ» لا يُضغط قبل أن يُقرّ التاجر أنّ رسوم ميتا عليه.
 *  • وبعد الربط: عنوانُ الإشعارات ورمزُ التحقّق للنسخ، وحالُ الفوترة كما هي —
 *    وما يُرسله دليلًا، فإقرارُه لا يفتح الإرسال.
 *  • وفريقُ أبعاد لا يؤكّد الفوترة بزرّ: يُسمّي الدليلَ ومرجعَه ويُقرّ بما رأى.
 *
 * والخادمُ يحكم بها كلِّها: `EveryMerchantBringsItsOwnMetaAppTest`.
 */

const CONFIG: EmbeddedConfig = {
    configured: true,
    app_id: '1082481610822941',
    config_id: '2177821789438050',
    graph_version: 'v26.0',
    feature_type: 'whatsapp_business_app_onboarding',
    session_info_version: '3',
};

const LINK: WhatsappLink = {
    state: 'disconnected', label: 'غير متصل', waba_id: null, meta_business_id: null,
    phone_number_id: null, display_phone_number: null, coexistence: false,
    connected_at: null, connected_by: null, last_webhook_at: null,
    expires_at: null, days_left: null, alert_days: null, error_code: null, error_message: null,
};

const LINKED: OwnAppView = {
    enabled: true,
    connection: {
        app_id: '5550000000001', waba_id: '7770000000001', phone_number_id: '8880000000001',
        callback_url: 'https://app.abaadapp.om/webhooks/whatsapp/m/KEY0123456789KEY0123456789KEY0123456789',
        verify_token: 'VERIFY-0123456789-VERIFY',
        webhook_verified_at: null, last_webhook_at: null, app_webhook: null,
        billing: {
            state: 'unproven', owner: null, checked_at: '2026-10-31 20:00', reviewed_at: null, stopped: null,
            evidence: ['فاتورة أو إيصال من ميتا لرسائل هذا الحساب باسم نشاط التاجر'],
        },
    },
};

describe('ربط التاجر بتطبيقه على Meta', () => {
    it('يعرض الدليلَ والنموذج — لا زرَّ لتطبيق أبعاد ولا اختيارَ تعايش', () => {
        render(<WhatsappLinkCard link={LINK} config={CONFIG} mayManage ownApp={{ enabled: true, connection: null }} />);

        expect(screen.getByTestId('own-app-guide')).toHaveTextContent('أنشئ تطبيقك');
        expect(screen.getByTestId('own-app-guide')).toHaveTextContent('التعايش لا تتيحه ميتا إلّا عبر شريكٍ تقنيّ');
        expect(screen.getByTestId('own-app-form')).toBeInTheDocument();
        expect(screen.queryByTestId('connect-whatsapp')).toBeNull();
        expect(screen.queryByTestId('link-kind')).toBeNull();
    });

    it('لا يُحفظ قبل أن يُقرّ التاجر أنّ رسوم ميتا عليه', async () => {
        render(<WhatsappLinkCard link={LINK} config={CONFIG} mayManage ownApp={{ enabled: true, connection: null }} />);

        expect(screen.getByTestId('own-app-submit')).toBeDisabled();
        await userEvent.setup().click(screen.getByTestId('own-app-billing-ack'));
        expect(screen.getByTestId('own-app-submit')).toBeEnabled();
    });

    it('بعد الربط: العنوانُ ورمزُ التحقّق للنسخ، والفوترةُ التي لم تثبت تُقال ولا تُخفى', () => {
        render(<WhatsappLinkCard link={{ ...LINK, state: 'billing_unproven' }} config={CONFIG} mayManage ownApp={LINKED} />);

        expect(pillFor('billing_unproven')).toBe('action');
        expect(screen.getByTestId('link-line')).toHaveTextContent('لم يثبت أنّ رسائل حسابك تُحتسب عليك');
        expect(screen.getByTestId('own-app-callback')).toHaveTextContent('/webhooks/whatsapp/m/');
        expect(screen.getByTestId('own-app-verify')).toHaveTextContent('VERIFY-0123456789-VERIFY');
        expect(screen.getByTestId('own-app-billing')).toHaveTextContent('بانتظار مراجعة دليلٍ من ميتا');
        expect(screen.getByTestId('own-app-billing-evidence')).toHaveTextContent('إقرارك وحده لا يكفي');
        expect(screen.getByTestId('own-app-billing-evidence')).toHaveTextContent('فاتورة أو إيصال من ميتا');
        expect(screen.getByTestId('own-app-webhook')).toHaveTextContent('لم تُضبط بعد');
        // والسرُّ والرمزُ لا يعودان إلى الشاشة: حقلاهما فارغان
        expect(screen.getByTestId('own-app-secret')).toHaveValue('');
        expect(screen.getByTestId('own-app-token')).toHaveValue('');
    });

    it('ومن ليس صاحبَ الحساب يرى الحال ولا يرى النموذج ولا رمزَ التحقّق', () => {
        render(
            <WhatsappLinkCard
                link={{ ...LINK, state: 'connected' }}
                config={CONFIG}
                mayManage={false}
                ownApp={{ ...LINKED, connection: { ...LINKED.connection!, verify_token: null, billing: { state: 'confirmed', owner: 'نشاط التاجر', checked_at: null, reviewed_at: '2026-10-31' } } }}
            />,
        );

        expect(screen.queryByTestId('own-app-form')).toBeNull();
        expect(screen.queryByTestId('own-app-verify')).toBeNull();
        expect(screen.getByTestId('own-app-billing')).toHaveTextContent('نشاط التاجر');
        expect(screen.queryByTestId('own-app-billing-evidence')).toBeNull();
    });

    it('فريقُ أبعاد لا يؤكّد الفوترة بزرّ — يُسمّي الدليلَ ومرجعَه ويُقرّ بما رأى', async () => {
        const review: BillingReview = {
            own_app: true, billing_state: 'unproven', billing_owner: 'نشاط التاجر', awaits_billing: true, may_confirm: true,
            facts: { ownership: 'SELF', funding: 'unreadable', abaad_access: 'none', partner_owned: false, read_at: '2026-10-31 20:00' },
            blocker: null, stopped: null, evidence: null,
            kinds: [{ key: 'meta_invoice', label: 'فاتورة من ميتا' }, { key: 'payment_settings', label: 'صفحة الفوترة' }],
        };
        const user = userEvent.setup();
        render(<BillingReviewBlock businessId={7} review={review} />);

        expect(screen.getByTestId('whatsapp-billing-facts')).toHaveTextContent('لا تُطلعنا ميتا عليها');
        expect(screen.getByTestId('whatsapp-billing-confirm')).toBeDisabled();

        await user.selectOptions(screen.getByTestId('whatsapp-billing-kind'), 'meta_invoice');
        await user.type(screen.getByTestId('whatsapp-billing-reference'), 'FBADS-0042');
        expect(screen.getByTestId('whatsapp-billing-confirm')).toBeDisabled();

        await user.click(screen.getByTestId('whatsapp-billing-checked'));
        expect(screen.getByTestId('whatsapp-billing-confirm')).toBeEnabled();
    });

    it('وما تمنعه ميتا يُقال ولا يُعرض معه نموذجُ التأكيد لوصلةٍ رُوجعت', () => {
        render(
            <BillingReviewBlock
                businessId={7}
                review={{
                    own_app: true, billing_state: 'confirmed', billing_owner: 'نشاط التاجر', awaits_billing: false, may_confirm: false,
                    facts: { ownership: 'SELF', funding: 'present', abaad_access: 'none', partner_owned: false, read_at: null },
                    blocker: null, stopped: null,
                    evidence: { kind: 'فاتورة من ميتا', reference: 'FBADS-0042', reviewed_by: 'المنصّة', reviewed_at: '2026-10-31 20:05' },
                    kinds: [],
                }}
            />,
        );

        expect(screen.getByTestId('whatsapp-billing')).toHaveTextContent('رُوجع دليلها — على التاجر');
        expect(screen.getByTestId('whatsapp-billing-evidence')).toHaveTextContent('FBADS-0042');
        expect(screen.queryByTestId('whatsapp-billing-review')).toBeNull();
    });
});
