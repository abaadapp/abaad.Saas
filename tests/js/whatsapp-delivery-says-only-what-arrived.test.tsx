import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import WhatsappDelivery, { type Delivery } from '@/Pages/Admin/Integrations/partials/WhatsappDelivery';

/**
 * بطاقةُ تسليم واتساب — لا تقول «لم يصل شيء» وقد وصل بعضُها.
 *
 * والحالُ من الخادم (`WhatsAppHealth::delivery`)، واللونُ منها: أخضرُ لما
 * يصل، وأصفرُ لعميلٍ لم تصله رسالتُه والربطُ يعمل، وأحمرُ لما انقطع.
 */

const BASE: Delivery = {
    state: 'ok',
    window_hours: 24,
    attempts: 0,
    delivered: 0,
    failed: 0,
    pending: 0,
    customers: 0,
    failures: [],
    replies: null,
    log_url: null,
};

const BAD = {
    phone: '968•••••968',
    count: 2,
    event: 'تأكيد الطلب',
    template: 'ribbon_order_confirmed',
    sent_at: '2026-11-03 18:51',
    failed_at: '2026-11-03 19:51',
    code: '131026',
    error: 'Message undeliverable',
    explain: 'تعذّر تسليم الرسالة إلى هذا الرقم.',
    ours: false,
    status_label: 'فشلت',
};

const FAULT = { ...BAD, phone: '968•••••333', count: 1, code: '200', error: 'API access blocked.', explain: 'عطبٌ في ربط واتساب لدى أبعاد', ours: true };

function card(d: Partial<Delivery>) {
    render(<WhatsappDelivery delivery={{ ...BASE, ...d }} />);

    return screen.getByTestId('whatsapp-delivery');
}

describe('بطاقةُ تسليم واتساب', () => {
    it('لا شيء وصل: تقول «لم تصل أيٌّ» بالأحمر', () => {
        const box = card({ state: 'failing', attempts: 2, failed: 2, customers: 1, failures: [BAD] });

        expect(box).toHaveAttribute('data-state', 'failing');
        expect(box.className).toContain('bg-[#fef2f2]');
        expect(box.textContent).toContain('لم تصل أيٌّ من رسائل آخر يوم');
    });

    it('وصلت رسائلُ ثمّ عطبٌ عند أبعاد: خطرٌ بالأحمر — بلا «لم تصل أيٌّ»', () => {
        const box = card({ state: 'failing', attempts: 5, delivered: 3, failed: 2, customers: 2, failures: [FAULT, BAD] });

        expect(box.className).toContain('bg-[#fef2f2]');
        expect(box.textContent).not.toContain('أيٌّ');
        expect(box.textContent).not.toContain('ولم يُؤكَّد وصول أيّ رسالة');
        expect(box.textContent).toContain('توقّف التسليم بعد أن وصلت رسائل');
        expect(box.textContent).toContain('وصلت 3 رسالة في آخر يوم');
        expect(box.textContent).toContain('وصلت: 3');
        expect(box.textContent).toContain('لم تصل: 2');
    });

    it('عميلٌ برمز 131026 والربطُ يعمل: تحذيرٌ بالأصفر، والرقمُ محجوب', () => {
        const box = card({ state: 'partial', attempts: 16, delivered: 14, failed: 2, customers: 1, failures: [BAD] });

        expect(box.className).toContain('bg-[#fffbeb]');
        expect(box.textContent).toContain('واتساب يعمل — بعض الرسائل لم تصل إلى عملائها');
        expect(box.textContent).toContain('968•••••968');
        expect(box.textContent).toContain('131026 — Message undeliverable');
        expect(box.textContent).toContain('عملاء لم تصلهم رسائلهم: 1');
        expect(box.textContent).not.toContain('أيٌّ');
    });

    it('كلُّه وصل: بالأخضر بلا قائمة', () => {
        const box = card({ state: 'ok', attempts: 4, delivered: 4 });

        expect(box.className).toContain('bg-[#f0fdf4]');
        expect(box.textContent).toContain('التسليم يعمل');
        expect(box.querySelector('ul')).toBeNull();
    });
});
