import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it } from 'vitest';

import Checkout from '@/Pages/Admin/Website/theme/sections/Checkout';
import Fields from '@/Pages/Admin/Website/theme/sections/Fields';
import Orders, { type OrderDesk } from '@/Pages/Admin/Website/theme/sections/Orders';
import Terms from '@/Pages/Admin/Website/theme/sections/Terms';
import { fillMessage, readDeliveryPlan, readOrderSchedule, writeOrderSchedule } from '@/Pages/Admin/Website/theme/sections/schedule';
import { pageProps } from './setup';
import { themeForm } from './theme-form';

/**
 * «المتجر والطلبات» — متى يُستقبل الطلب، والشروط، ومواعيدُ التسليم في شاشة صاحب المتجر.
 *
 * ═══ ما يُحرس ═══
 *
 *   - رسالةُ الإغلاق تُعاين كما يملؤها الخادم: المتغيّران بموعد الفتح، والسطرُ
 *     الذي يذكرهما يُسقط حين لا يُعرف موعد — حرفًا كـ`OrderWindow::fill`.
 *   - الجدولُ يُقرأ ويُكتب بصيغة الخادم، وما لا يُفهم يُقرأ فراغًا.
 *   - الحالةُ الآن وسببُها تُقال، ومحرّرُ الأيّام لا يظهر إلّا لمن فتح الجدولة.
 *   - لا نصَّ شروطٍ مقترح: الخانتان فارغتان حتّى يكتب.
 *   - جدولُ التسليم يُخفي السطورَ الحرّة، ويُقفل حقلَي الموعد ووقته.
 */

const reset = () => {
    for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    Object.assign(pageProps, {
        translations: {},
        dir: 'rtl',
        auth: { abilities: ['website'], mayActions: [] },
        context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 } },
    });
};

const DESK: OrderDesk = {
    open: false,
    why: 'schedule',
    now: 'الأربعاء 10 فبراير · 10:00 م',
    next: 'الخميس 11 فبراير · 9:00 ص',
    preview: { day_ar: 'الخميس 11 فبراير', hour_ar: '9:00 ص', day_en: 'Thursday 11 February', hour_en: '9:00 AM' },
};

beforeEach(reset);

describe('رسالةُ الإغلاق كما يملؤها الخادم', () => {
    it('يملأ المتغيّرين بموعد الفتح', () => {
        expect(fillMessage('نعود {next_open_date} الساعة {next_open_time}.', { date: 'الخميس 11 فبراير', time: '9:00 ص' }))
            .toBe('نعود الخميس 11 فبراير الساعة 9:00 ص.');
    });

    it('يُسقط السطرَ الذي يذكر موعدًا لا يُعرف، ويبقي ما سواه', () => {
        expect(fillMessage('نعتذر عن استقبال الطلبات حاليًا.\r\nنعود {next_open_date}.', null)).toBe('نعتذر عن استقبال الطلبات حاليًا.');
        expect(fillMessage('نعود {next_open_time}.', null)).toBeNull();
    });
});

describe('الجدولُ بصيغة الخادم', () => {
    it('يقرأ ما لا يُفهم فراغًا بأيّامه السبعة', () => {
        const s = readOrderSchedule('not json');

        expect(s.week).toHaveLength(7);
        expect(s.week.every((d) => d.length === 0)).toBe(true);
        expect(readDeliveryPlan('').delivery).toHaveLength(7);
    });

    it('يكتب ما يقرأ', () => {
        const week = Array.from({ length: 7 }, () => [] as { from: string; to: string }[]);
        week[3] = [{ from: '20:00', to: '02:00' }];
        const s = { week, starts: '2027-02-12', ends: null, closed: [{ from: '2027-02-18', to: '2027-02-18' }] };

        expect(readOrderSchedule(writeOrderSchedule(s))).toEqual(s);
    });
});

describe('«استقبال الطلبات»', () => {
    it('يقول الحالةَ الآن وسببَها وموعدَ الفتح', () => {
        render(<Orders form={themeForm()} desk={DESK} />);

        const status = screen.getByTestId('order-desk-status');
        expect(status.textContent).toContain('لا يستقبل الطلبات الآن');
        expect(status.textContent).toContain('الخميس 11 فبراير · 9:00 ص');
    });

    it('لا يُظهر محرّرَ الأيّام إلّا لمن فتح الجدولة، ويكتب فترتَه في مفتاحه', () => {
        const { rerender } = render(<Orders form={themeForm()} desk={DESK} />);
        expect(screen.queryByTestId('order-schedule')).toBeNull();

        const form = themeForm({ store_order_schedule_on: true });
        rerender(<Orders form={form} desk={DESK} />);

        fireEvent.click(screen.getAllByText('أضف فترة')[0]);
        const [key, json] = (form.setData as unknown as { mock: { calls: [string, string][] } }).mock.calls.at(-1)!;
        expect(key).toBe('store_order_schedule');
        // السبتُ أوّلُ ما يعرضه المحرّر
        expect(readOrderSchedule(json).week[6]).toEqual([{ from: '09:00', to: '21:00' }]);
    });

    it('يعاين رسالتيه بلغتيهما — بموعد الفتح، وحين يُغلق يدويًّا', () => {
        render(
            <Orders
                form={themeForm({
                    store_closed_message_on: true,
                    store_closed_message: 'نعتذر.\nنعود {next_open_date} {next_open_time}.',
                    store_closed_message_en: 'Sorry.\nBack {next_open_date} {next_open_time}.',
                })}
                desk={DESK}
            />,
        );

        const preview = screen.getByTestId('closed-message-preview').textContent ?? '';
        expect(preview).toContain('نعود الخميس 11 فبراير 9:00 ص.');
        expect(preview).toContain('Back Thursday 11 February 9:00 AM.');
        expect(preview).toContain('حين يُغلق يدويًا');
        expect(preview).not.toContain('{next_open_date}');
    });
});

describe('«شروط وأحكام الطلبات الإلكترونية»', () => {
    it('لا يقترح نصًّا قانونيًّا — الخانتان فارغتان حتّى يكتب', () => {
        render(<Terms form={themeForm()} terms={{ version: null, updated: null, ar: '', en: '' }} />);

        const boxes = screen.getAllByRole('textbox') as HTMLTextAreaElement[];
        expect(boxes).toHaveLength(2);
        expect(boxes.every((b) => b.value === '' && b.placeholder === '')).toBe(true);
        expect(screen.getByTestId('terms-version').textContent).toContain('لم تُكتب شروط بعد.');
    });

    it('يقول الإصدارَ ويعاين النصّين بخانة موافقتهما', () => {
        render(
            <Terms
                form={themeForm({ store_terms: 'شرطٌ أوّل', store_terms_en: 'First term' })}
                terms={{ version: 3, updated: 'الأربعاء 10 فبراير · 9:00 ص', ar: 'شرطٌ أوّل', en: 'First term' }}
            />,
        );

        expect(screen.getByTestId('terms-version').textContent).toContain('الإصدار 3');
        const preview = screen.getByTestId('terms-preview').textContent ?? '';
        expect(preview).toContain('شرطٌ أوّل');
        expect(preview).toContain('قرأت الشروط والأحكام وأوافق عليها.');
        expect(preview).toContain('I have read and agree to the Terms & Conditions.');
    });
});

describe('مواعيدُ التسليم المنظّمة', () => {
    it('تُخفي السطورَ الحرّة وتُظهر الجدولَ لمن فتحه', () => {
        const { rerender } = render(<Checkout form={themeForm()} gatewayReady />);
        expect(screen.getByLabelText('أوقات التسليم')).toBeTruthy();
        expect(screen.queryByTestId('delivery-plan')).toBeNull();

        rerender(<Checkout form={themeForm({ store_delivery_schedule_on: true })} gatewayReady />);
        expect(screen.queryByLabelText('أوقات التسليم')).toBeNull();
        expect(screen.getByTestId('delivery-plan')).toBeTruthy();
    });

    it('تنسخ فتراتِ يومٍ إلى كلّ الأيّام بمعرّفاتها', () => {
        const plan = { delivery: Array.from({ length: 7 }, () => []) as unknown[][], pickup: null, closed: [], lead: 0 };
        plan.delivery[6] = [{ id: 'am01', from: '09:00', to: '12:00', ar: 'الصباح', en: 'Morning' }];
        const form = themeForm({ store_delivery_schedule_on: true, store_delivery_schedule: JSON.stringify(plan) });
        render(<Checkout form={form} gatewayReady />);

        fireEvent.click(screen.getAllByText('انسخها إلى كل الأيام')[0]);

        const [key, json] = (form.setData as unknown as { mock: { calls: [string, string][] } }).mock.calls.at(-1)!;
        expect(key).toBe('store_delivery_schedule');
        expect(readDeliveryPlan(json).delivery.map((d) => d.map((s) => s.id))).toEqual(Array.from({ length: 7 }, () => ['am01']));
    });

    it('يُقفل حقلَي الموعد ووقته ما دام الجدولُ يحكمهما', () => {
        render(<Fields form={themeForm({ store_delivery_schedule_on: true })} />);

        expect((screen.getByLabelText('موعد التسليم') as HTMLButtonElement).disabled).toBe(true);
        expect((screen.getByLabelText('وقت التسليم') as HTMLButtonElement).disabled).toBe(true);
        expect((screen.getByLabelText('المنطقة') as HTMLButtonElement).disabled).toBe(false);
    });
});
