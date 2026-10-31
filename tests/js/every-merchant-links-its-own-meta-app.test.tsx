import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import type { EmbeddedConfig } from '@/Pages/Admin/Integrations/partials/EmbeddedSignup';
import type { OwnAppView } from '@/Pages/Admin/Integrations/partials/OwnAppLink';
import WhatsappLinkCard, { pillFor, type WhatsappLink } from '@/Pages/Admin/Integrations/partials/WhatsappLinkCard';

/**
 * التاجرُ يربط واتسابه بتطبيقه هو عند ميتا — والشاشةُ تمشيه خطوةً خطوة.
 *
 *  • لا زرَّ لتطبيق أبعاد ولا اختيارَ تعايش: الدليلُ والنموذجُ مكانهما.
 *  • «تحقّق وحفظ» لا يُضغط قبل أن يُقرّ التاجر أنّ رسوم ميتا عليه.
 *  • وبعد الربط: عنوانُ الإشعارات ورمزُ التحقّق للنسخ، وحالُ الفوترة كما هي.
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
        billing: { state: 'unproven', owner: null, checked_at: '2026-10-31 20:00' },
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
        expect(screen.getByTestId('own-app-billing')).toHaveTextContent('لم يثبت أنّها عليك');
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
                ownApp={{ ...LINKED, connection: { ...LINKED.connection!, verify_token: null, billing: { state: 'merchant', owner: 'نشاط التاجر', checked_at: null } } }}
            />,
        );

        expect(screen.queryByTestId('own-app-form')).toBeNull();
        expect(screen.queryByTestId('own-app-verify')).toBeNull();
        expect(screen.getByTestId('own-app-billing')).toHaveTextContent('نشاط التاجر');
    });
});
