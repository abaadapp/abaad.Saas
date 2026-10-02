import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import PrepDetails from '@/Pages/Admin/Preparation/partials/PrepDetails';
import type { PrepOrder } from '@/Pages/Admin/Preparation/partials/types';

/**
 * من يكتب الكرتَ يقرؤه كما رُتّب — لا كما اعتاد هو.
 *
 * ═══ ولمَ حارسٌ للمحاذاة ═══
 *
 * الترتيبُ أوّلُ ما يُرى على الكرت، والزبونُ دفع ثمنَه ليختاره. ونصٌّ
 * وُسِّط في الموقع ثمّ كُتب على اليمين في المشغل كرتٌ آخر — ولا يُكتشف
 * أبدًا: الطلبُ يخرج، ولا شاشةَ تُخطئ، ولا أحدَ يُقارن.
 *
 * والأسطرُ كذلك: بيتُ شعرٍ في سطرين يُقرأ سطرًا واحدًا بلا `pre-wrap`.
 */

const order = (over: Partial<PrepOrder> = {}): PrepOrder => ({
    number: 'INV-1',
    status: 'قيد التجهيز',
    customer: 'مريم',
    fulfillment: 'delivery',
    scheduled_for: '2027-02-03 10:00',
    scheduled: { date: '2027-02-03', time: '10:00', day: 'today', minutes_left: 120 },
    overdue: false,
    recipient: 'أمّي',
    recipient_phone: '96899220002',
    address: null,
    occasion: null,
    card_message: null,
    sender: 'مريم',
    hide_sender: false,
    delivery_notes: null,
    internal_notes: null,
    order_notes: null,
    branch: null,
    items: [{ id: 7, name: 'باقة', qty: 1, note: null, image: null, addons: [] }],
    next: ['جاهز'],
    checks: {},
    ...over,
});

const noop = () => {};

const draw = (over: Partial<PrepOrder>) =>
    render(
        <PrepDetails
            order={order(over)}
            gone={false}
            onClose={noop}
            onMove={noop}
            onToggle={noop}
            deliveryNoteUrl={() => '/admin/orders/5/delivery-note'}
            timelineUrl={() => '/admin/orders/5/timeline'}
        />,
    );

describe('كرتُ الهدية على طاولة التجهيز', () => {
    it('يُعرض النصّ بالمحاذاة التي اختارها صاحبُه', () => {
        draw({ card_message: 'كل عام وأنتِ بخير', card_align: 'center' });

        const text = screen.getByText('كل عام وأنتِ بخير');
        expect(text).toHaveStyle({ textAlign: 'center' });
    });

    /** وبلا محاذاةٍ يُقرأ من اليمين — العربيّةُ تُكتب من اليمين */
    it('وبلا محاذاةٍ يُقرأ من اليمين', () => {
        draw({ card_message: 'مرحبًا', card_align: null });

        expect(screen.getByText('مرحبًا')).toHaveStyle({ textAlign: 'right' });
    });

    /** والأسطرُ تبقى أسطرًا — لا تُطوى إلى سطرٍ واحد */
    it('والأسطر تبقى أسطرًا', () => {
        draw({ card_message: 'كل عام\nوأنتِ بخير' });

        expect(screen.getByText(/كل عام/)).toHaveClass('whitespace-pre-wrap');
    });

    /**
     * ورابطُ الملفّ المرفق حُذف من التفاصيل (قرارُ المالك 2026-10-02) —
     * والباقي كما هو: النصُّ والمُهدي يُعرضان.
     */
    it('لا رابطَ للملفّ المرفق — والنصُّ والمُهدي باقيان', () => {
        draw({ card_message: 'مرحبًا', card_file: '/admin/orders/5/gift-card', card_file_name: 'خطّي.png' });

        expect(screen.queryByRole('link', { name: 'خطّي.png' })).toBeNull();
        expect(screen.queryByText('خطّي.png')).toBeNull();
        expect(document.querySelector('a[href="/admin/orders/5/gift-card"]')).toBeNull();
        expect(screen.getByText('مرحبًا')).toBeInTheDocument();
        expect(screen.getByText(/من: مريم/)).toBeInTheDocument();
    });

    /** وطلبٌ بمرفقٍ بلا نصّ يبقى صندوقُه — يقول إنّ للطلب كرتًا */
    it('ومرفقٌ بلا نصٍّ يبقى صندوقُه بلا رابط', () => {
        draw({ card_message: null, card_file: '/admin/orders/5/gift-card', card_file_name: 'تصميم.pdf' });

        expect(screen.getByText('بطاقة الإهداء')).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'تصميم.pdf' })).toBeNull();
    });

    /** وطلبٌ بلا كرتٍ أصلًا لا يعرض صندوقًا فارغًا */
    it('وطلبٌ بلا كرتٍ لا يعرض شيئًا', () => {
        draw({ card_message: null, card_file: null });

        expect(screen.queryByText('بطاقة الإهداء')).toBeNull();
    });
});
