import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import type { EmbeddedConfig } from '@/Pages/Admin/Integrations/partials/EmbeddedSignup';
import WhatsappLinkCard, { type WhatsappLink } from '@/Pages/Admin/Integrations/partials/WhatsappLinkCard';
import { pageProps } from './setup';

vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

const { default: IntegrationsWhatsapp } = await import('@/Pages/Admin/Integrations/Whatsapp');

/**
 * متجرٌ أُغلق عنه رقمُ أبعاد — تقول شاشتُه «غير مربوط»، ولا بابَ فيها إليه.
 *
 * كانت بطاقةُ الربط تقول لكلّ متجرٍ لم يربط رقمه «تخرج رسائلك من رقم أبعاد
 * المشترك»، وفي الشاشة زرُّ «أرسل عبر أبعاد». ومن أُغلق عنه لا تخرج رسائله
 * من رقم أبعاد ولا يُعرض له زرُّه. والقرارُ في الخادم:
 * `AShopClosedToAbaadsNumberSpeaksOnlyFromItsOwnTest`.
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

describe('بطاقةُ الربط', () => {
    it('من أُغلق عنه رقمُ أبعاد يُقال له «غير مربوط» — لا «تخرج من رقم أبعاد»', () => {
        render(<WhatsappLinkCard link={LINK} config={CONFIG} mayManage sharedAllowed={false} />);

        const line = screen.getByTestId('link-line').textContent ?? '';
        expect(line).toContain('واتساب غير مربوط');
        expect(line).not.toContain('رقم أبعاد');
        // وبابُ الربط القائم حاضر
        expect(screen.getByRole('button', { name: /ربط WhatsApp Business/ })).toBeInTheDocument();
    });

    it('وغيرُه كما كان', () => {
        render(<WhatsappLinkCard link={LINK} config={CONFIG} mayManage />);

        expect(screen.getByTestId('link-line').textContent).toContain('تخرج رسائلك من رقم أبعاد المشترك');
    });
});

const draw = (over: Record<string, unknown>) => {
    for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    Object.assign(pageProps, {
        translations: {},
        dir: 'rtl',
        auth: { abilities: [], mayActions: [] },
        automation: {
            readiness: { ready: true, connected: true, steps: [] },
            global_enabled: true,
            enabled: true,
            mode: 'abaad_shared',
            effective_mode: 'business_own',
            sending_via: 'رقم المتجر',
            own_allowed: true,
            own_connection: { status: 'active', usable: true, display_phone_number: '+968 9525 9066' },
            shared_active: false,
            shared_allowed: false,
            embedded: CONFIG,
            link: { ...LINK, state: 'connected' },
            auto_reply: null,
            branches: [],
            may_manage: true,
            usage: null,
            pack: null,
            events: [],
            ...over,
        },
    });

    render(<IntegrationsWhatsapp />);
};

describe('شاشةُ الربط', () => {
    it('من أُغلق عنه رقمُ أبعاد لا زرَّ له إليه — ورقمُه هو ما تخرج منه الرسائل', () => {
        draw({});

        expect(screen.queryByRole('button', { name: /أرسل عبر أبعاد/ })).toBeNull();
        expect(screen.getByText('رقم متجرك')).toBeInTheDocument();
    });

    it('ومن لم يُغلق عنه يبقى زرُّه', () => {
        draw({ mode: 'business_own', shared_allowed: true, shared_active: true });

        expect(screen.getByRole('button', { name: /أرسل عبر أبعاد بدلًا منه/ })).toBeInTheDocument();
    });
});
