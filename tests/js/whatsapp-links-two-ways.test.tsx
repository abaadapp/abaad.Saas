import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { router } from '@inertiajs/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { EmbeddedConfig } from '@/Pages/Admin/Integrations/partials/EmbeddedSignup';
import WhatsappLinkCard, { STEPS, type WhatsappLink } from '@/Pages/Admin/Integrations/partials/WhatsappLinkCard';
import WhatsAppMerchantLinkSwitch, { type WhatsAppLinkState } from '@/Pages/Platform/Settings/partials/WhatsAppMerchantLinkSwitch';

/**
 * ‏‏ربطُ واتساب بطريقتين — رقمُ API مخصّص، أو البقاءُ في تطبيق واتساب للأعمال — وما ستطلبه Meta
 * خطوةً خطوة، ورسومُها على التاجر نفسه. ومديرُ المنصّة يختار طريقَ التجّار الجدد من شاشته.
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
    state: 'disconnected', label: 'غير مربوط', waba_id: null, meta_business_id: null, phone_number_id: null,
    display_phone_number: null, coexistence: false, connected_at: null, connected_by: null, last_webhook_at: null,
    expires_at: null, days_left: null, alert_days: null, error_code: null, error_message: null,
};

const card = (config: Partial<EmbeddedConfig> = {}, extra: Record<string, unknown> = {}) =>
    render(<WhatsappLinkCard link={LINK} config={{ ...CONFIG, ...config }} mayManage {...extra} />);

describe('التاجر يختار كيف يربط رقمه', () => {
    it('بطاقتان: رقم API مخصّص افتراضًا، والبقاء في التطبيق «قريبًا» ما لم يُفتح', () => {
        card();

        const api = screen.getByTestId('link-kind-cloud_api');
        const app = screen.getByTestId('link-kind-coexistence');
        expect(api).toHaveTextContent('رقم API مخصّص للنظام');
        expect(api.querySelector('input')).toBeChecked();
        expect(app).toHaveTextContent('أبقِ رقمك في تطبيق واتساب للأعمال');
        expect(app).toHaveTextContent('قريبًا');
        expect(app.querySelector('input')).toBeDisabled();
        expect(screen.getByTestId('connect-whatsapp')).toHaveTextContent('ربط واتساب API');
    });

    it('تعرض ما ستطلبه Meta لرقم API — ثلاث خطوات آخرها رمز التحقّق بخطوتين', () => {
        card();

        const steps = screen.getByTestId('link-steps');
        expect(steps).toHaveAttribute('data-kind', 'cloud_api');
        expect(within(steps).getAllByRole('listitem')).toHaveLength(3);
        expect(steps).toHaveTextContent(STEPS.cloud_api[2]);
        expect(steps).toHaveTextContent('رمز التحقّق بخطوتين');
        expect(steps).not.toHaveTextContent('2.24.17');
    });

    it('واختيارُ التطبيق حين يُفتح يبدّل الخطوات وشرطَ الإصدار ونصَّ الزرّ', async () => {
        card({ coexistence_open: true });
        const user = userEvent.setup();

        await user.click(screen.getByTestId('link-kind-coexistence').querySelector('input')!);

        const steps = screen.getByTestId('link-steps');
        expect(steps).toHaveAttribute('data-kind', 'coexistence');
        expect(steps).toHaveTextContent('أكّده من تطبيق واتساب للأعمال');
        expect(steps).toHaveTextContent('2.24.17');
        expect(screen.getByTestId('link-kind-coexistence')).not.toHaveTextContent('قريبًا');
        expect(screen.getByTestId('connect-whatsapp')).toHaveTextContent('ربط واتساب مع التطبيق');
    });

    it('تقول قبل الربط إنّ رسوم Meta على التاجر — وأوّل 1,000 ردّ مجّانًا — برابطٍ إلى الفوترة', () => {
        card();

        const billing = screen.getByTestId('link-billing');
        expect(billing).toHaveTextContent('على حسابك أنت مباشرة، لا على أبعاد');
        expect(billing).toHaveTextContent('1,000');
        const a = within(billing).getByRole('link');
        expect(a).toHaveAttribute('href', 'https://business.facebook.com/billing_hub');
        expect(a).toHaveAttribute('target', '_blank');
        expect(a.getAttribute('rel')).toContain('noopener');
    });

    it('ولا بطاقات ولا خطوات حين يربط التاجر تطبيقه، أو والربط بضغطة غير مهيّأ، أو لمن لا يدير', () => {
        const own = { enabled: true, connection: null, may_manage: true } as unknown;
        const a = card({}, { ownApp: own });
        expect(screen.queryByTestId('link-kind')).toBeNull();
        a.unmount();

        const b = card({ configured: false });
        expect(screen.queryByTestId('link-kind')).toBeNull();
        expect(screen.queryByTestId('link-billing')).toBeNull();
        b.unmount();

        render(<WhatsappLinkCard link={LINK} config={CONFIG} mayManage={false} />);
        expect(screen.queryByTestId('link-kind')).toBeNull();
    });
});

const STATE: WhatsAppLinkState = {
    mode: 'own_app', default: 'own_app', platform: null, readable: true,
    config_id: '', config_source: 'none', app_id_ready: true, app_secret_ready: true, ready: false, history: [],
};

describe('مدير المنصّة يختار طريق التجّار الجدد', () => {
    beforeEach(() => vi.mocked(router.post).mockClear());

    it('تعرض الطريق الساري وجاهزيّة الربط بضغطة بندًا بندًا — والنقلُ مقفلٌ حتى تكتمل', () => {
        render(<WhatsAppMerchantLinkSwitch state={STATE} />);

        expect(screen.getByTestId('wa-link-mode')).toHaveTextContent('تطبيق التاجر على Meta');
        expect(screen.getByTestId('wa-link-ready-app')).toHaveAttribute('data-ok', '1');
        expect(screen.getByTestId('wa-link-ready-secret')).toHaveAttribute('data-ok', '1');
        expect(screen.getByTestId('wa-link-ready-config')).toHaveAttribute('data-ok', '0');
        expect(screen.getByTestId('wa-link-flip')).toBeDisabled();
        expect(screen.getByTestId('wa-link-not-ready')).toBeInTheDocument();
    });

    it('يحفظ رقم الإعداد أرقامًا فقط', async () => {
        render(<WhatsAppMerchantLinkSwitch state={STATE} />);
        const user = userEvent.setup();

        await user.type(screen.getByTestId('wa-link-config-input'), ' 2177-8217 89438050x');
        await user.click(screen.getByTestId('wa-link-config-save'));

        expect(router.post).toHaveBeenCalledTimes(1);
        expect(vi.mocked(router.post).mock.calls[0][0]).toContain('super-admin.whatsapp.merchant-link.config');
        expect(vi.mocked(router.post).mock.calls[0][1]).toEqual({ config_id: '217782178943805' + '0' });
    });

    it('ينقل التجّار الجدد إلى تطبيق أبعاد بعد التأكيد — ولا شيء بلا تأكيد', async () => {
        // ‏‏وما بعد نجاح تجربة الفوترة — والقفلُ قبلها في `who-pays-for-whatsapp`
        const launched = { launched: true, passed: null, trial: null, kinds: [], defaults: { messages: 15, hours: 72, max_messages: 50, max_hours: 168 } };
        render(<WhatsAppMerchantLinkSwitch state={{ ...STATE, ready: true, config_id: '2177821789438050', config_source: 'platform', billing: launched }} />);
        const user = userEvent.setup();

        await user.click(screen.getByTestId('wa-link-flip'));
        let dialog = screen.getByRole('dialog');
        expect(dialog).toHaveTextContent('الوصلات القائمة لا تتغيّر');
        await user.click(within(dialog).getByRole('button', { name: 'إلغاء' }));
        expect(router.post).not.toHaveBeenCalled();

        await user.click(screen.getByTestId('wa-link-flip'));
        dialog = screen.getByRole('dialog');
        await user.click(within(dialog).getByRole('button', { name: 'نقل' }));
        expect(router.post).toHaveBeenCalledTimes(1);
        expect(vi.mocked(router.post).mock.calls[0][0]).toContain('super-admin.whatsapp.merchant-link.mode');
        expect(vi.mocked(router.post).mock.calls[0][1]).toEqual({ mode: 'abaad_app' });
    });

    it('ومن طريق أبعاد يُعيدهم إلى تطبيق التاجر، ويعرض آخر التغييرات وتعذّر القراءة', async () => {
        render(
            <WhatsAppMerchantLinkSwitch
                state={{
                    ...STATE, mode: 'abaad_app', platform: 'abaad_app', ready: true, readable: false, config_id: '2177821789438050', config_source: 'platform',
                    history: [{ at: '2026-11-23T10:00:00+04:00', by: 'مدير المنصّة', what: 'غيّر طريقة ربط واتساب للتجّار — كانت «تطبيق التاجر على Meta» وصارت «تطبيق أبعاد (بضغطة)»' }],
                }}
            />,
        );
        const user = userEvent.setup();

        expect(screen.getByTestId('wa-link-mode')).toHaveTextContent('تطبيق أبعاد (بضغطة)');
        expect(screen.getByTestId('wa-link-unreadable')).toBeInTheDocument();
        expect(screen.getByTestId('wa-link-history')).toHaveTextContent('مدير المنصّة');
        expect(screen.getByTestId('wa-link-flip')).toBeEnabled();

        await user.click(screen.getByTestId('wa-link-flip'));
        await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'إعادة' }));
        expect(vi.mocked(router.post).mock.calls[0][1]).toEqual({ mode: 'own_app' });
    });
});
