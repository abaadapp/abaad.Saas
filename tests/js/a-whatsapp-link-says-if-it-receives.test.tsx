import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { router } from '@inertiajs/react';
import { describe, expect, it } from 'vitest';
import WhatsAppCard, { type BusinessWhatsApp } from '@/Pages/Platform/Businesses/partials/WhatsAppCard';

/**
 * بطاقةُ واتساب المتجر في لوحة المنصّة — «متّصل» ليس «يستقبل».
 *
 * فريقُ أبعاد يقرأ هنا أيُّ تطبيقٍ أصدر رمزَ المتجر وهل يصلنا ما يكتبه زبائنه،
 * ويضغط «فحص الربط» فيُقرأ ذلك من ميتا — قراءةً لا غير.
 */

const DATA: BusinessWhatsApp = {
    enabled: true,
    mode: 'business_own',
    effective_mode: 'business_own',
    own_allowed: true,
    shared_allowed: false,
    limit_override: null,
    platform_default: 300,
    usage: { used: 0, limit: 300, unlimited: false, remaining: 300, percentage: 0, credits: 0, monthly_exhausted: false, is_exhausted: false },
    packs: [],
    pack_offer: { size: 500, price: 5 },
    own_connection: { status: 'active', usable: true, display_phone_number: '+968 7679 0720', expires_at: null },
    own_health: {
        last_webhook_at: null,
        last_error: null,
        last_error_at: null,
        link: {
            key: 'wrong_app',
            label: 'تطبيق Meta غير مطابق',
            hint: 'رمزُ هذا الربط صادرٌ من تطبيقٍ غير Abaad Messaging، فإشعاراتُ الرقم لا تصل أبعاد.',
            tone: 'danger',
            checked_at: '2026-10-28 14:00',
        },
        can_check: true,
    },
    own_eligible: true,
    own_needs_addon: false,
    inbox_enabled: true,
    inbox_available: true,
};

describe('بطاقةُ واتساب المتجر عند المنصّة', () => {
    it('تقول إنّ رمز المتجر من تطبيقٍ غير أبعاد — وإنّ «متّصل» لا يعني «يستقبل»', () => {
        render(<WhatsAppCard businessId={5} data={DATA} />);

        const box = screen.getByTestId('whatsapp-link-health');
        expect(box).toHaveAttribute('data-state', 'wrong_app');
        expect(box.textContent).toContain('تطبيق Meta غير مطابق');
        expect(box.textContent).toContain('Abaad Messaging');
        expect(box.textContent).toContain('قراءة عند ميتا فقط');
    });

    it('فحصُ الربط يطرق باب الفحص وحده', async () => {
        render(<WhatsAppCard businessId={5} data={DATA} />);

        await userEvent.setup().click(screen.getByTestId('whatsapp-link-check'));

        expect(router.post).toHaveBeenCalledWith(
            route('super-admin.businesses.whatsapp.check', 5),
            {},
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('ولا زرَّ فحصٍ لوصلةٍ بلا رقمٍ ورمز', () => {
        render(<WhatsAppCard businessId={5} data={{ ...DATA, own_health: { ...DATA.own_health!, can_check: false } }} />);

        expect(screen.queryByTestId('whatsapp-link-check')).toBeNull();
    });

    it('وتقول «تم إثبات الاستقبال» حين يقولها الخادم فقط', () => {
        const proven = { key: 'receiving', label: 'تم إثبات استقبال Webhook فعلي', hint: 'آخرُ إشعارٍ وصل: 2026-10-28 15:00', tone: 'success', checked_at: null };
        render(<WhatsAppCard businessId={5} data={{ ...DATA, own_health: { ...DATA.own_health!, link: proven } }} />);

        expect(screen.getByTestId('whatsapp-link-health')).toHaveAttribute('data-state', 'receiving');
        expect(screen.getByTestId('whatsapp-link-health').textContent).toContain('تم إثبات استقبال');
    });
});
