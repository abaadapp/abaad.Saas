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

    it('ويُفتح الملفُّ المرفق باسمه', () => {
        draw({ card_message: 'مرحبًا', card_file: '/admin/orders/5/gift-card', card_file_name: 'خطّي.png' });

        const link = screen.getByRole('link', { name: 'خطّي.png' });
        expect(link).toHaveAttribute('href', '/admin/orders/5/gift-card');
    });

    /** وطلبٌ بمرفقٍ بلا نصّ يُعرض — وإلّا ضاع الملفُّ الذي دُفع ثمنُه */
    it('ومرفقٌ بلا نصٍّ لا يختفي', () => {
        draw({ card_message: null, card_file: '/admin/orders/5/gift-card', card_file_name: 'تصميم.pdf' });

        expect(screen.getByRole('link', { name: 'تصميم.pdf' })).toBeInTheDocument();
        expect(screen.getByText('بطاقة الإهداء')).toBeInTheDocument();
    });

    /** وطلبٌ بلا كرتٍ أصلًا لا يعرض صندوقًا فارغًا */
    it('وطلبٌ بلا كرتٍ لا يعرض شيئًا', () => {
        draw({ card_message: null, card_file: null });

        expect(screen.queryByText('بطاقة الإهداء')).toBeNull();
    });
});
