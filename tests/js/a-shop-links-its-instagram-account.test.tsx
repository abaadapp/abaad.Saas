import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { router } from '@inertiajs/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { InstagramScreen, type InstagramLinkView, type InstagramPageView } from '@/Pages/Admin/Integrations/Instagram';
import InstagramPlatformCard, { type InstagramPlatformState } from '@/Pages/Platform/Settings/partials/InstagramPlatformCard';

/**
 * ‏‏إنستقرام كما يراه التاجر — ثلاثُ خطواتٍ وزرٌّ قبل الربط، وبعده الحسابُ ومتى يتجدّد ونقصُ الأذونات
 * والفصل. ومديرُ المنصّة يلصق العناوين في لوحة Meta ثمّ يفتح الربط.
 */

const LINK: InstagramLinkView = {
    state: 'connected', username: 'rose.shop', name: 'محل الورد', account_type: 'BUSINESS', picture: null,
    expires_at: '2027-01-20T10:00:00+04:00', last_webhook_at: null, connected_at: '2026-11-23T10:00:00+04:00',
    missing_permissions: [], subscribed: true, hears_phone: true, error: null,
};

const VIEW: InstagramPageView = { link: null, may_manage: true, scopes: [] };

beforeEach(() => {
    vi.mocked(router.post).mockClear();
});

describe('إنستقرام عند التاجر', () => {
    it('قبل الربط: ثلاثُ خطواتٍ وزرُّ تسجيل الدخول', async () => {
        const user = userEvent.setup();
        render(<InstagramScreen view={VIEW} />);

        expect(within(screen.getByTestId('ig-steps')).getAllByRole('listitem')).toHaveLength(3);
        await user.click(screen.getByTestId('ig-connect'));
        expect(vi.mocked(router.post).mock.calls[0][0]).toContain('admin.integrations.instagram.connect');
    });

    it('من لا يملك الإدارة لا يرى زرّ الربط', () => {
        render(<InstagramScreen view={{ ...VIEW, may_manage: false }} />);

        expect(screen.queryByTestId('ig-connect')).toBeNull();
    });

    it('مربوط: الحسابُ ومتى يتجدّد، ولم يصل شيءٌ بعد — والفصلُ بعد التأكيد', async () => {
        const user = userEvent.setup();
        render(<InstagramScreen view={{ ...VIEW, link: LINK }} />);

        expect(screen.getByTestId('ig-account')).toHaveTextContent('@rose.shop');
        expect(screen.getByTestId('ig-last-webhook')).toHaveTextContent('لم يصل شيء بعد');
        expect(screen.queryByTestId('ig-relink-button')).toBeNull();
        expect(screen.queryByTestId('ig-missing')).toBeNull();

        await user.click(screen.getByTestId('ig-disconnect'));
        await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'فصل' }));
        expect(vi.mocked(router.post).mock.calls.at(-1)?.[0]).toContain('admin.integrations.instagram.disconnect');
    });

    it('نقصُ الأذونات يُسمّى بكلماته، وانقطاعُ الإذن يطلب إعادة الربط بسببه', () => {
        const a = render(<InstagramScreen view={{ ...VIEW, link: { ...LINK, missing_permissions: ['instagram_business_manage_comments'] } }} />);
        expect(screen.getByTestId('ig-missing')).toHaveTextContent('التعليقات');
        expect(screen.getByTestId('ig-relink-button')).toBeInTheDocument();
        a.unmount();

        render(<InstagramScreen view={{ ...VIEW, link: { ...LINK, state: 'needs_relink', error: 'انتهى إذن إنستقرام — أعد ربط الحساب. (190)' } }} />);
        expect(screen.getByTestId('ig-relink')).toHaveTextContent('(190)');
        expect(screen.getByTestId('ig-relink-button')).toBeInTheDocument();
    });

    it('بعد الفصل يعود زرُّ الربط ويُقال ما كان', () => {
        render(<InstagramScreen view={{ ...VIEW, link: { ...LINK, state: 'revoked' } }} />);

        expect(screen.getByTestId('ig-connect')).toBeInTheDocument();
        expect(screen.getByTestId('ig-revoked')).toHaveTextContent('@rose.shop');
    });
});

const STATE: InstagramPlatformState = {
    mode: 'off', pilot: [], modes: ['off', 'pilot', 'all'], pilot_max: 50,
    app_id: '', secret_set: false, configured: false,
    redirect_uri: 'https://app.abaadapp.om/admin/integrations/instagram/callback',
    webhook_url: 'https://app.abaadapp.om/webhooks/instagram',
    verify_token: 'abc123',
    deauthorize_url: 'https://app.abaadapp.om/webhooks/instagram/deauthorize',
    deletion_url: 'https://app.abaadapp.om/webhooks/instagram/deletion',
    scopes: ['instagram_business_basic'], fields: ['comments', 'messages', 'messaging_postbacks'],
};

describe('إنستقرام عند المنصّة', () => {
    it('يعرض العناوين التي تُلصق في Meta، ولا يُفتح قبل التطبيق', () => {
        render(<InstagramPlatformCard state={STATE} />);

        expect(screen.getByTestId('ig-platform-urls')).toHaveTextContent('/admin/integrations/instagram/callback');
        expect(screen.getByTestId('ig-platform-urls')).toHaveTextContent('abc123');
        expect(screen.getByTestId('ig-platform-fields')).toHaveTextContent('messaging_postbacks');
        expect(screen.getByTestId('ig-mode-pilot')).toBeDisabled();
        expect(screen.getByTestId('ig-mode-all')).toBeDisabled();
    });

    it('يحفظ التطبيق، ثمّ يفتحه لمتاجر التجربة بعد التأكيد', async () => {
        const user = userEvent.setup();
        const a = render(<InstagramPlatformCard state={STATE} />);

        await user.type(screen.getByTestId('ig-app-id'), '1234567890');
        await user.type(screen.getByTestId('ig-app-secret'), 'shh');
        await user.click(screen.getByTestId('ig-app-save'));
        expect(vi.mocked(router.post).mock.calls[0][0]).toContain('super-admin.instagram.credentials');
        expect(vi.mocked(router.post).mock.calls[0][1]).toEqual({ app_id: '1234567890', app_secret: 'shh' });
        a.unmount();

        render(<InstagramPlatformCard state={{ ...STATE, configured: true, app_id: '1234567890', secret_set: true }} />);
        expect(screen.getByTestId('ig-app-secret')).toHaveAttribute('placeholder', 'السر محفوظ — اكتب سرًا جديدًا لتبديله');
        await user.click(screen.getByTestId('ig-mode-pilot'));
        await user.type(screen.getByTestId('ig-pilot'), '5');
        await user.click(screen.getByTestId('ig-mode-save'));
        await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'حفظ' }));
        expect(vi.mocked(router.post).mock.calls.at(-1)?.[0]).toContain('super-admin.instagram.mode');
        expect(vi.mocked(router.post).mock.calls.at(-1)?.[1]).toEqual({ mode: 'pilot', pilot: '5' });
    });
});
