import { render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import PrepCard from '@/Pages/Admin/Preparation/partials/PrepCard';
import PrepDetails from '@/Pages/Admin/Preparation/partials/PrepDetails';
import type { PrepOrder } from '@/Pages/Admin/Preparation/partials/types';

import { pageProps } from './setup';

/**
 * ملاحظةُ الطلب العامّة على الطاولة — باسمها، وكما كُتبت.
 *
 * ═══ وأثقلُ ما يُحرَس ═══
 *
 * أنّ النصَّ لا يُترجَم. فيُوضع في المعجم ترجمةٌ لنصّ الملاحظة نفسِه: لو
 * مرّ النصُّ على `t()` لَتبدّل، فيسقط الاختبار. والعنوانُ وحده يُترجَم.
 */

const order = (over: Partial<PrepOrder> = {}): PrepOrder => ({
    number: 'INV-1',
    status: 'قيد التجهيز',
    customer: 'سارة',
    fulfillment: 'delivery',
    scheduled_for: '2026-09-23 14:00',
    scheduled: { date: '2026-09-23', time: '14:00', day: 'today', minutes_left: 120 },
    overdue: false,
    recipient: null,
    recipient_phone: null,
    address: null,
    occasion: null,
    card_message: null,
    sender: null,
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

const details = (o: PrepOrder) =>
    render(
        <PrepDetails
            order={o}
            gone={false}
            onClose={noop}
            onMove={noop}
            onToggle={noop}
            deliveryNoteUrl={(n) => `/note/${n}`}
            timelineUrl={(n) => `/timeline/${n}`}
        />,
    );

const card = (o: PrepOrder) => render(<PrepCard order={o} ageMs={0} onOpen={noop} onMove={noop} />);

beforeEach(() => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: true, json: async () => ({ events: [] }) }));
});

afterEach(() => {
    vi.unstubAllGlobals();
    Object.assign(pageProps, { translations: {} });
});

describe('تفاصيلُ التجهيز: كلُّ ملاحظةٍ باسمها', () => {
    it('تعرض ملاحظات الطلب وتعليمات التوصيل والداخليّة — كلٌّ تحت عنوانه', () => {
        details(
            order({
                order_notes: 'Please use white wrapping',
                delivery_notes: 'اتصل قبل الوصول',
                internal_notes: 'VIP customer',
            }),
        );

        expect(screen.getByTestId('prep-note-order')).toHaveTextContent('ملاحظات الطلب:');
        expect(screen.getByTestId('prep-note-order')).toHaveTextContent('Please use white wrapping');
        expect(screen.getByTestId('prep-note-delivery')).toHaveTextContent('تعليمات التوصيل:');
        expect(screen.getByTestId('prep-note-delivery')).toHaveTextContent('اتصل قبل الوصول');
        expect(screen.getByTestId('prep-note-internal')).toHaveTextContent('ملاحظات داخلية:');
        expect(screen.getByTestId('prep-note-internal')).toHaveTextContent('VIP customer');
    });

    it('ولا عنوانَ ولا صندوقَ لملاحظةٍ فارغة', () => {
        details(order({ delivery_notes: 'اتصل قبل الوصول' }));

        expect(screen.queryByTestId('prep-note-order')).toBeNull();
        expect(screen.queryByText('ملاحظات الطلب:')).toBeNull();
        expect(screen.getByTestId('prep-note-delivery')).toBeInTheDocument();
    });

    it('وطلبٌ بلا ملاحظاتٍ أصلًا لا يرسم الصندوق', () => {
        details(order());

        expect(screen.queryByTestId('prep-notes')).toBeNull();
    });

    it('العنوانُ يُترجَم والنصُّ لا — عربيًّا وإنجليزيًّا', () => {
        Object.assign(pageProps, {
            translations: {
                'ملاحظات الطلب': 'Order notes',
                // ترجمةٌ لنصّ الملاحظة نفسه — لو مرّ على `t()` لَظهرت
                'جهز الطلب قبل الساعة 5': 'TRANSLATED-AR',
                'Please write the name JOHN in English': 'TRANSLATED-EN',
            },
        });

        const { unmount } = details(order({ order_notes: 'جهز الطلب قبل الساعة 5' }));
        expect(screen.getByTestId('prep-note-order')).toHaveTextContent('Order notes:');
        expect(screen.getByTestId('prep-note-order')).toHaveTextContent('جهز الطلب قبل الساعة 5');
        expect(screen.queryByText('TRANSLATED-AR')).toBeNull();
        unmount();

        details(order({ order_notes: 'Please write the name JOHN in English' }));
        expect(screen.getByTestId('prep-note-order')).toHaveTextContent('Please write the name JOHN in English');
        expect(screen.queryByText('TRANSLATED-EN')).toBeNull();
    });

    it('وما كان يُعرض باقٍ: البطاقةُ والمُهدي وملاحظةُ البند', () => {
        details(
            order({
                order_notes: 'عامّة',
                card_message: 'كل عام وأنتِ بخير',
                sender: 'أحمد',
                items: [{ id: 7, name: 'باقة', qty: 1, note: 'بلا ورد أحمر', image: null, addons: [] }],
            }),
        );

        for (const text of ['كل عام وأنتِ بخير', 'أحمد', 'بلا ورد أحمر', 'عامّة']) {
            expect(screen.getAllByText(text, { exact: false }).length).toBeGreaterThan(0);
        }
    });
});

describe('البطاقةُ المختصرة: أبرزُ ملاحظةٍ وعدُّ الباقي', () => {
    const ITEM_NOTE = [{ id: 7, name: 'باقة', qty: 1, note: 'ملاحظة البند', image: null, addons: [] }];

    it('الداخليّةُ أوّلًا — ولو وُجدت ملاحظةُ الطلب', () => {
        card(order({ internal_notes: 'داخلية', order_notes: 'عامّة', items: ITEM_NOTE, delivery_notes: 'توصيل' }));

        expect(screen.getByText('داخلية')).toBeInTheDocument();
        expect(screen.queryByText('عامّة')).toBeNull();
    });

    it('ثمّ ملاحظةُ الطلب — قبل ملاحظة البند والتوصيل', () => {
        card(order({ order_notes: 'عامّة', items: ITEM_NOTE, delivery_notes: 'توصيل' }));

        expect(screen.getByText('عامّة')).toBeInTheDocument();
        expect(screen.queryByText('ملاحظة البند')).toBeNull();
    });

    it('ثمّ ملاحظةُ البند — ثمّ التوصيل', () => {
        card(order({ items: ITEM_NOTE, delivery_notes: 'توصيل' }));
        expect(screen.getByText('ملاحظة البند')).toBeInTheDocument();
    });

    it('والعدّادُ يحسب الأربعة: الداخليّة والعامّة والتوصيل والبند', () => {
        card(order({ internal_notes: 'داخلية', order_notes: 'عامّة', items: ITEM_NOTE, delivery_notes: 'توصيل' }));

        expect(screen.getByText('+3')).toBeInTheDocument();
    });

    it('وملاحظةُ الطلب وحدها تُعدّ مع غيرها', () => {
        card(order({ order_notes: 'عامّة', delivery_notes: 'توصيل' }));

        expect(screen.getByText('+1')).toBeInTheDocument();
    });

    it('والنصُّ على البطاقة لا يُترجَم كذلك', () => {
        Object.assign(pageProps, { translations: { 'Please write JOHN on the card': 'TRANSLATED' } });
        card(order({ order_notes: 'Please write JOHN on the card' }));

        expect(screen.getByText('Please write JOHN on the card')).toBeInTheDocument();
        expect(screen.queryByText('TRANSLATED')).toBeNull();
    });
});
