import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { router } from '@inertiajs/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import OwnNumbersHealth, { type OwnNumberRow } from '@/Pages/Platform/Settings/partials/OwnNumbersHealth';

/**
 * أرقامُ المتاجر المستقلّة في لوحة المنصّة — أيصلنا ما يكتبه زبائنُها؟
 *
 * الشاشةُ تقرأ ما قاله الخادم ولا تحكم بنفسها: «وصلت حالةُ تسليم» ليست
 * «وصلت رسالة عميل». والفحصان زرّان يقرآن من ميتا ولا يغيّران شيئًا.
 */

const ROWS: OwnNumberRow[] = [
    {
        business_id: 41,
        business: 'محل الورد',
        phone: '+968 7000 0001',
        inbox: true,
        health: {
            key: 'wrong_app',
            label: 'يحتاج إعادة تفويض عبر تطبيق أبعاد',
            hint: 'رمزُ هذا الربط صادرٌ من تطبيقٍ غير Abaad Messaging.',
            tone: 'danger',
            checked_at: '2026-10-28 14:00',
            last_status_at: null,
            last_inbound_at: null,
            inbound_problem: null,
        },
    },
    {
        business_id: 42,
        business: 'محل الجار',
        phone: '+968 7000 0002',
        inbox: false,
        health: {
            key: 'status_received',
            label: 'تم استقبال Webhook لحالة تسليم',
            hint: 'وصلت حالاتُ تسليمٍ لما أرسله المتجر، ولم تصل رسالةُ زبونٍ بعد.',
            tone: 'info',
            checked_at: null,
            last_status_at: '2026-10-28 15:00',
            last_inbound_at: null,
            inbound_problem: null,
        },
    },
];

describe('أرقامُ المتاجر المستقلّة عند المنصّة', () => {
    beforeEach(() => vi.mocked(router.post).mockClear());

    it('تعرض لكلّ متجرٍ حالَه كما قالها الخادم — وحالةُ التسليم ليست رسالةَ عميل', () => {
        render(<OwnNumbersHealth rows={ROWS} />);

        const [first, second] = screen.getAllByTestId('own-number-row');
        expect(first).toHaveAttribute('data-state', 'wrong_app');
        expect(first.textContent).toContain('يحتاج إعادة تفويض عبر تطبيق أبعاد');

        expect(second).toHaveAttribute('data-state', 'status_received');
        expect(second.textContent).toContain('2026-10-28 15:00');
        expect(second.textContent).not.toContain('رسالة عميل فعلية');
        expect(second.textContent).toContain('مغلق');
    });

    it('فحصُ الربط يطرق باب المتجر نفسه — وفحصُ التطبيق بابَه', async () => {
        render(<OwnNumbersHealth rows={ROWS} />);
        const user = userEvent.setup();

        await user.click(within(screen.getAllByTestId('own-number-row')[1]).getByTestId('own-number-check'));
        expect(router.post).toHaveBeenCalledWith(route('super-admin.businesses.whatsapp.check', 42), {}, expect.objectContaining({ preserveScroll: true }));

        await user.click(screen.getByTestId('app-webhook-check'));
        expect(router.post).toHaveBeenCalledWith(route('super-admin.whatsapp.app-webhook.check'), {}, expect.objectContaining({ preserveScroll: true }));
        expect(router.post).toHaveBeenCalledTimes(2);
    });

    it('وتقول حين لا متجرَ على رقمه', () => {
        render(<OwnNumbersHealth rows={[]} />);

        expect(screen.getByTestId('own-numbers-health').textContent).toContain('لا متجر مربوطٌ برقمه المستقل بعد');
        expect(screen.queryByTestId('own-number-row')).toBeNull();
    });
});
