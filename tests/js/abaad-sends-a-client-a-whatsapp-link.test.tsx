import { act, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { router } from '@inertiajs/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { pageProps } from './setup';
import WhatsappLinkPage, { type ClientLink } from '@/Pages/Auth/WhatsappLink';
import { resetSdkForTests, type EmbeddedConfig } from '@/Pages/Admin/Integrations/partials/EmbeddedSignup';
import ClientLinkBox, { type ClientLinkView } from '@/Pages/Platform/Businesses/partials/ClientLinkBox';

/**
 * ‏‏«أرسل رابطًا لعميلك» — صندوقُه في صفحة المتجر عند فريق أبعاد، والصفحةُ التي يفتحها صاحبُ المتجر.
 */

const CONFIG: EmbeddedConfig = {
    configured: true, app_id: '1082481610822941', config_id: '2177821789438050', graph_version: 'v26.0',
    feature_type: 'whatsapp_business_app_onboarding', session_info_version: '3', coexistence_open: true,
};

const OPEN: ClientLink = { state: 'open', shop: 'محل الورد', expires: '2026-11-27 14:00', phone: null, needs_pin: false };

const props = pageProps as unknown as Record<string, unknown>;

const show = (link: Partial<ClientLink> = {}, extra: Record<string, unknown> = {}) => {
    const full = { ...OPEN, ...link };
    Object.assign(props, { link: full, config: full.state === 'open' ? CONFIG : null, year: 2026, flash: { toast: null, status: null }, errors: {}, locale: 'ar', ...extra });

    return render(<WhatsappLinkPage />);
};

let login: ReturnType<typeof vi.fn>;

beforeEach(() => {
    vi.mocked(router.post).mockClear();
    vi.mocked(router.delete).mockClear();
    resetSdkForTests();
    login = vi.fn();
});

afterEach(() => {
    document.querySelectorAll('script[src*="facebook"]').forEach((s) => s.remove());
    delete window.FB;
    for (const key of ['link', 'config', 'year', 'flash', 'errors', 'locale']) delete props[key];
});

/** عدّةُ ميتا حُمّلت — كما يفعل المتصفّح بعد تحميل النصّ */
const sdkReady = async () => {
    window.FB = { init: vi.fn(), login } as never;
    await act(async () => window.fbAsyncInit?.());
};

describe('صفحة الرابط عند صاحب المتجر', () => {
    it('تقول أيّ متجر وإلى متى، وتعرض الطريقتين وخطوات Meta', () => {
        show();

        expect(screen.getByTestId('client-link-shop')).toHaveTextContent('محل الورد');
        expect(screen.getByTestId('client-link-shop')).toHaveTextContent('2026-11-27 14:00');
        expect(screen.getByTestId('link-kind-cloud_api')).toBeInTheDocument();
        expect(screen.getByTestId('link-kind-coexistence')).toBeInTheDocument();
        expect(screen.getByTestId('link-steps')).toHaveAttribute('data-kind', 'cloud_api');
    });

    it('لا يُضغط الزرّ قبل أن يُقرّ أنّ المتجر متجره', async () => {
        show();
        expect(screen.getByTestId('client-link-connect')).toBeDisabled();

        await userEvent.setup().click(screen.getByTestId('client-link-confirm'));
        expect(screen.getByTestId('client-link-connect')).toBeEnabled();
    });

    it('تُحمَّل عدّة Meta مع الصفحة لا مع الضغطة — فلا يمنع الهاتف النافذة', () => {
        show();
        expect(document.querySelector('script[src="https://connect.facebook.net/en_US/sdk.js"]')).not.toBeNull();
    });

    it('ورابطٌ ميّت لا يحمّل من Meta شيئًا', () => {
        show({ state: 'expired', shop: null, expires: null });
        expect(document.querySelector('script[src*="facebook"]')).toBeNull();
    });

    it('يرسل الكود والطريقة والإقرار إلى الخادم وحده', async () => {
        show();
        await sdkReady();
        const user = userEvent.setup();
        await user.click(screen.getByTestId('link-kind-coexistence').querySelector('input')!);
        await user.click(screen.getByTestId('client-link-confirm'));
        await user.click(screen.getByTestId('client-link-connect'));

        expect(login).toHaveBeenCalledTimes(1);
        expect(login.mock.calls[0][1]).toMatchObject({ config_id: '2177821789438050', extras: { featureType: 'whatsapp_business_app_onboarding' } });
        act(() => login.mock.calls[0][0]({ authResponse: { code: 'CODE-123' } }));

        expect(vi.mocked(router.post).mock.calls[0][0]).toBe('/clientLink.connect');
        expect(vi.mocked(router.post).mock.calls[0][1]).toEqual({ code: 'CODE-123', waba_id: '', phone_number_id: '', type: 'coexistence', shop_confirmed: true });
    });

    it('وما ردّه الخادم يُكتب في الصفحة — خطأً أو انتظارًا', () => {
        const a = show({}, { errors: { phone_number_id: 'هذا الرقم مربوط بمتجر آخر' } });
        expect(screen.getByTestId('client-link-problem')).toHaveTextContent('هذا الرقم مربوط بمتجر آخر');
        a.unmount();

        show({}, { flash: { toast: null, status: 'انتظر دقائق' } });
        expect(screen.getByTestId('client-link-status')).toHaveTextContent('انتظر دقائق');
    });

    it('بعد الربط: الرقم ومتجره، ولا زرّ ربط', () => {
        show({ state: 'used', phone: '+968 9525 9066', expires: null });

        expect(screen.getByTestId('client-link-done')).toHaveTextContent('+968 9525 9066');
        expect(screen.getByTestId('client-link-done')).toHaveTextContent('رُبط رقمك');
        expect(screen.queryByTestId('client-link-connect')).toBeNull();
        expect(screen.queryByTestId('client-link-register')).toBeNull();
    });

    it('ورقمٌ جديد يطلب رمزه: ستّة أرقام لا غير', async () => {
        show({ state: 'used', phone: '+968 9525 9066', needs_pin: true });
        const user = userEvent.setup();

        expect(screen.getByTestId('client-link-activate')).toBeDisabled();
        await user.type(screen.getByTestId('client-link-pin'), '12a3456');
        expect(screen.getByTestId('client-link-pin')).toHaveValue('123456');
        expect(screen.getByTestId('client-link-activate')).toBeEnabled();
    });

    it.each([
        ['used', 'استُعمل هذا الرابط من قبل'],
        ['expired', 'انتهت مدّة هذا الرابط'],
        ['revoked', 'أُلغي هذا الرابط'],
        ['exhausted', 'استُنفدت محاولات هذا الرابط'],
        ['closed', 'لا يمكن الربط من هذا الرابط الآن'],
        ['missing', 'افتح الرابط الذي وصلك'],
    ] as const)('ورابطٌ لا يعمل يقول لماذا: %s', (state, text) => {
        show({ state, shop: null, expires: null });

        expect(screen.getByTestId('client-link-dead')).toHaveTextContent(text);
        expect(screen.queryByTestId('client-link-connect')).toBeNull();
        expect(screen.queryByTestId('client-link-shop')).toBeNull();
    });
});

const BOX: ClientLinkView = { can: true, why: null, hours: 72, link: null };
const LAST: NonNullable<ClientLinkView['link']> = {
    state: 'open', label: 'ينتظر العميل', created_by: 'عدي', created_at: '2026-11-24 14:00', expires_at: '2026-11-27 14:00',
    opened_at: null, attempts: 0, problem: null, connected_at: null, phone: null,
};

const box = (data: Partial<ClientLinkView> = {}, extra: Record<string, unknown> = {}, stillShared = false) => {
    Object.assign(props, { flash: { toast: null, status: null }, errors: {}, ...extra });

    return render(<ClientLinkBox businessId={41} data={{ ...BOX, ...data }} stillShared={stillShared} />);
};

describe('صندوق الرابط عند فريق أبعاد', () => {
    it('يُنشئ رابطًا لهذا المتجر بضغطة', async () => {
        box();
        await userEvent.setup().click(screen.getByTestId('client-link-create'));

        expect(vi.mocked(router.post).mock.calls[0][0]).toBe('/super-admin.businesses.whatsapp.clientLink.store/41');
    });

    it('يعرض العنوان الذي أُنشئ الآن بنسخه ونسخ رسالته وإرسالها', () => {
        box({}, { flash: { toast: null, status: null, whatsappClientLink: { url: 'https://app.test/connect/whatsapp/abc', message: 'مرحبًا، هذا رابطك' } } });

        expect(screen.getByTestId('client-link-url')).toHaveTextContent('https://app.test/connect/whatsapp/abc');
        expect(screen.getByTestId('client-link-made')).toHaveTextContent('لا يظهر مرّة أخرى');
        expect(screen.getByTestId('client-link-share')).toHaveAttribute('href', `https://wa.me/?text=${encodeURIComponent('مرحبًا، هذا رابطك')}`);
    });

    it('وبلا رابطٍ أُنشئ الآن لا عنوان — الخادم لا يحفظه', () => {
        box({ link: LAST });

        expect(screen.queryByTestId('client-link-made')).toBeNull();
        expect(screen.getByTestId('client-link-last')).toHaveAttribute('data-state', 'open');
        expect(screen.getByTestId('client-link-last')).toHaveTextContent('لم يُفتح بعد');
    });

    it('والجديد فوق رابطٍ مفتوح يُسأل عنه قبل أن يُلغي ما قبله', async () => {
        box({ link: LAST });
        const user = userEvent.setup();
        await user.click(screen.getByTestId('client-link-create'));

        expect(vi.mocked(router.post)).not.toHaveBeenCalled();
        expect(screen.getByText('الرابط المرسل قبله يتوقّف. متابعة؟')).toBeInTheDocument();
    });

    it('ومتجرٌ لا يُربط برابط يقول لماذا ويُقفل الزرّ', () => {
        box({ can: false, why: 'هذا المتجر ربط رقمه بتطبيقه على Meta — لا يُربط برابط.' });

        expect(screen.getByTestId('client-link-why')).toHaveTextContent('بتطبيقه على Meta');
        expect(screen.getByTestId('client-link-create')).toBeDisabled();
    });

    it('يقول ما وقع: آخر محاولة، أو الرقم الذي رُبط — ولا إلغاء لما استُعمل', () => {
        const a = box({ link: { ...LAST, problem: 'تعذّر إكمال الربط مع ميتا' } });
        expect(screen.getByTestId('client-link-last-problem')).toHaveTextContent('تعذّر إكمال الربط مع ميتا');
        expect(screen.getByTestId('client-link-revoke')).toBeInTheDocument();
        a.unmount();

        box({ link: { ...LAST, state: 'used', label: 'رُبط الرقم', phone: '+968 9525 9066', connected_at: '2026-11-24 15:00' } });
        expect(screen.getByTestId('client-link-last')).toHaveTextContent('+968 9525 9066');
        expect(screen.queryByTestId('client-link-revoke')).toBeNull();
        expect(screen.queryByTestId('client-link-still-shared')).toBeNull();
    });

    it('وبعد الربط ينبّه الفريقَ إن بقي الإرسال من رقم أبعاد', () => {
        box({ link: { ...LAST, state: 'used', label: 'رُبط الرقم', phone: '+968 9525 9066', connected_at: '2026-11-24 15:00' } }, {}, true);

        expect(screen.getByTestId('client-link-still-shared')).toHaveTextContent('API مستقل للمتجر');
    });
});
