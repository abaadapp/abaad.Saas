import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { router } from '@inertiajs/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { EmbeddedConfig } from '@/Pages/Admin/Integrations/partials/EmbeddedSignup';
import WhatsappLinkCard, { CoexistenceSyncBox, type CoexistenceSync, type WhatsappLink } from '@/Pages/Admin/Integrations/partials/WhatsappLinkCard';
import WhatsAppMerchantLinkSwitch, { type WhatsAppLinkState } from '@/Pages/Platform/Settings/partials/WhatsAppMerchantLinkSwitch';

/**
 * ‏‏رقمٌ بقي في تطبيق واتساب للأعمال — ما يراه التاجرُ من مزامنة جهاته ومحادثاته السابقة، ومتى
 * يُطلب منه أن يُبقي التطبيقَ مفتوحًا، ولماذا انفصل. ومديرُ المنصّة يفتح الخيارَ بعد النقل إلى تطبيق أبعاد.
 */

const SYNC: CoexistenceSync = {
    started_at: '2026-11-23T10:00:00+04:00',
    deadline: '2026-11-24T10:00:00+04:00',
    contacts: { state: 'pending', count: 0, error: null },
    history: { state: 'pending', progress: null, error: null },
    unlinked: null,
};

const box = (over: Partial<CoexistenceSync>) => render(<CoexistenceSyncBox sync={{ ...SYNC, ...over }} />);

describe('مزامنة تطبيق واتساب للأعمال كما يراها التاجر', () => {
    it('تُطلب الآن — ويُطلب منه أن يُبقي التطبيق مفتوحًا', () => {
        box({});
        expect(screen.getByTestId('coex-contacts')).toHaveTextContent('تُطلب الآن');
        expect(screen.getByTestId('coex-history')).toHaveTextContent('تُطلب بعد جهات الاتصال');
        expect(screen.getByTestId('coex-keep-open')).toHaveTextContent('أبقِ تطبيق واتساب للأعمال مفتوحًا');
    });

    it('تقول كم جهةً وصلت وكم نُقل من المحادثات', () => {
        box({ contacts: { state: 'requested', count: 312, error: null }, history: { state: 'importing', progress: 45, error: null } });
        expect(screen.getByTestId('coex-contacts')).toHaveTextContent('وصلت 312 جهة اتصال');
        expect(screen.getByTestId('coex-history')).toHaveTextContent('تُنقل الآن — 45%');
        expect(screen.getByTestId('coex-keep-open')).toBeInTheDocument();
    });

    it('اكتملت — فلا يُطلب منه شيء', () => {
        box({ contacts: { state: 'requested', count: 5, error: null }, history: { state: 'done', progress: 100, error: null } });
        expect(screen.getByTestId('coex-history')).toHaveTextContent('اكتملت');
        expect(screen.queryByTestId('coex-keep-open')).toBeNull();
    });

    it('رفضُ مشاركة المحادثات يُقال كما هو', () => {
        box({ contacts: { state: 'requested', count: 5, error: null }, history: { state: 'declined', progress: null, error: null } });
        expect(screen.getByTestId('coex-history')).toHaveTextContent('لم تُشارَك من التطبيق');
        expect(screen.queryByTestId('coex-keep-open')).toBeNull();
    });

    it('الفشلُ يُعاد وحده، وفواتُ اليوم يُقال معه ما يفعله', () => {
        const a = box({ contacts: { state: 'failed', count: 0, error: '131000 · Something went wrong' } });
        expect(screen.getByTestId('coex-contacts')).toHaveTextContent('نعيد المحاولة تلقائيًا');
        expect(screen.queryByTestId('coex-expired')).toBeNull();
        a.unmount();

        box({ contacts: { state: 'expired', count: 0, error: null }, history: { state: 'expired', progress: null, error: null } });
        expect(screen.getByTestId('coex-expired')).toHaveTextContent('مضت 24 ساعة');
        expect(screen.getByTestId('coex-expired')).toHaveTextContent('اربطه من جديد');
    });

    it('الرقمُ المنفصل يُقال سببُه وما يفعله — ولا تُعرض المزامنة', () => {
        box({ unlinked: { event: 'PARTNER_REMOVED', reason: 'لم يُفتح تطبيق واتساب للأعمال على الهاتف نحو 14 يومًا', at: '2026-11-23T10:00:00+04:00' } });
        expect(screen.getByTestId('coex-unlinked')).toHaveTextContent('انفصل الرقم عن أبعاد');
        expect(screen.getByTestId('coex-unlinked')).toHaveTextContent('14 يومًا');
        expect(screen.getByTestId('coex-unlinked')).toHaveTextContent('أعد الربط');
        expect(screen.queryByTestId('coex-contacts')).toBeNull();
        expect(screen.queryByTestId('coex-keep-open')).toBeNull();
    });

    it('بطاقةُ الربط تعرضها لرقم التطبيق وحده', () => {
        const config: EmbeddedConfig = { configured: true, app_id: '1', config_id: '2', graph_version: 'v26.0', feature_type: 'whatsapp_business_app_onboarding', session_info_version: '3' };
        const link: WhatsappLink = {
            state: 'connected', label: 'مربوط', waba_id: 'W', meta_business_id: null, phone_number_id: 'P', display_phone_number: '+968 9100 0001',
            coexistence: true, connected_at: null, connected_by: null, last_webhook_at: null, expires_at: null, days_left: null, alert_days: null,
            error_code: null, error_message: null, sync: SYNC,
        };
        const a = render(<WhatsappLinkCard link={link} config={config} mayManage />);
        expect(screen.getByTestId('coex-sync')).toBeInTheDocument();
        a.unmount();

        render(<WhatsappLinkCard link={{ ...link, coexistence: false, sync: null }} config={config} mayManage />);
        expect(screen.queryByTestId('coex-sync')).toBeNull();
    });
});

const STATE: WhatsAppLinkState = {
    mode: 'abaad_app', default: 'own_app', platform: 'abaad_app', readable: true,
    config_id: '2177821789438050', config_source: 'platform', app_id_ready: true, app_secret_ready: true, ready: true,
    coexistence: false, coexistence_fields: ['account_update', 'history', 'smb_app_state_sync', 'smb_message_echoes'], history: [],
};

describe('مدير المنصّة يفتح خيار البقاء في التطبيق', () => {
    beforeEach(() => vi.mocked(router.post).mockClear());

    it('مقفلٌ ما دام التجّار على تطبيقاتهم — ومعه حقولُ الإشعارات التي يحتاجها', () => {
        render(<WhatsAppMerchantLinkSwitch state={{ ...STATE, mode: 'own_app', platform: null }} />);
        expect(screen.getByTestId('wa-coex-state')).toHaveTextContent('مقفل');
        expect(screen.getByTestId('wa-coex-flip')).toBeDisabled();
        expect(screen.getByTestId('wa-coex-fields')).toHaveTextContent('smb_message_echoes');
        expect(screen.getByTestId('wa-coex-fields')).toHaveTextContent('بعد نقل التجّار الجدد');
    });

    it('يفتحه بعد التأكيد ثمّ يقفله', async () => {
        const user = userEvent.setup();
        const a = render(<WhatsAppMerchantLinkSwitch state={STATE} />);

        await user.click(screen.getByTestId('wa-coex-flip'));
        await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'فتح' }));
        expect(vi.mocked(router.post).mock.calls[0][0]).toContain('super-admin.whatsapp.merchant-link.coexistence');
        expect(vi.mocked(router.post).mock.calls[0][1]).toEqual({ enabled: true });
        a.unmount();

        render(<WhatsAppMerchantLinkSwitch state={{ ...STATE, coexistence: true }} />);
        expect(screen.getByTestId('wa-coex-state')).toHaveTextContent('مفتوح للتجّار');
        await user.click(screen.getByTestId('wa-coex-flip'));
        await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'إقفال' }));
        expect(vi.mocked(router.post).mock.calls[1][1]).toEqual({ enabled: false });
    });
});
