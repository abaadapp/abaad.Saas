import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it } from 'vitest';

import OrdersIndex from '@/Pages/Admin/Orders/Index';
import type { Order } from '@/types/models';
import { pageProps } from './setup';

/**
 * قائمةُ المبيعات تقول لكلّ طلبٍ من أيّ بابٍ دخل.
 *
 * والسؤالُ الذي تجيبه: «أيبيع موقعي شيئًا، أم أبيع أنا وحدي؟» — وهو أوّلُ ما
 * يسأله من فتح متجرًا إلكترونيًّا، ولم يكن له جوابٌ في الشاشة التي فيه
 * جوابُه: القناةُ مكتوبةٌ في كلّ صفٍّ منذ أُنشئ العمود ولا تصل الشاشةَ أصلًا.
 */

const web: Order = {
    id: 'WEB-1', customer: 'مريم', employee: 'الموقع الإلكتروني', branch: 'الخوير',
    items_count: 2, total: 40, payment: 'نقدي', status: 'جديد', date: '2027-02-01 10:00',
    channel: 'website', channel_label: 'الموقع الإلكتروني',
};

const pos: Order = {
    id: 'POS-9', customer: 'سالم', employee: 'خالد', branch: 'الخوير',
    items_count: 1, total: 20, payment: 'نقدي', status: 'مكتمل', date: '2027-02-01 11:00',
    channel: 'pos', channel_label: 'نقطة البيع',
};

/** وطلبٌ سبق العمود: قناتُه فارغةٌ لا مخترعة */
const old: Order = {
    id: 'OLD-1', customer: 'قديم', employee: 'خالد', branch: 'الخوير',
    items_count: 1, total: 5, payment: 'نقدي', status: 'مكتمل', date: '2026-01-01 09:00',
    channel: 'unknown', channel_label: 'غير محدّدة',
};

/*
 * و`route()` بلا وسيطٍ تردّ مُوجِّهًا لا مسارًا.
 *
 * `Sidebar` تسأله `current()` لتعرف أيَّ عنصرٍ تُضيء — وبديلُ `setup.ts`
 * يردّ نصًّا دائمًا، فتسقط القشرةُ كلُّها قبل أن يُرسم الجدول.
 */
const base = globalThis.route as unknown as (...a: unknown[]) => string;
globalThis.route = ((...a: unknown[]) =>
    a.length ? base(...a) : { current: () => 'admin.orders.index' }) as never;

const draw = (over: Record<string, unknown> = {}) => {
    Object.assign(pageProps, {
        translations: {},
        context: {
            currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 },
            currencies: [], branches: [], branchId: null, branchName: '', tier: null, website: null,
        },
        auth: { abilities: ['*'], mayActions: [], isEmployee: false, user: { name: 'سعود', avatar: null, roleLabel: 'مدير نشاط', role: 'admin', businessId: 1 } },
        notifications: null, reportPages: [], locale: 'ar', csrf: 'x', flash: {},
        orders: [web, pos, old],
        pagination: { current_page: 1, last_page: 1, per_page: 10, total: 3, from: 1, to: 3, links: [] },
        filters: {},
        sorts: [],
        totalAmount: 65,
        totalCount: 3,
        cancelledCount: 0,
        websiteCount: 1,
        websiteAmount: 40,
        statusOptions: [{ value: 'جديد', label: 'جديد' }],
        channelOptions: [
            { value: 'website', label: 'الموقع الإلكتروني' },
            { value: 'pos', label: 'نقطة البيع' },
            { value: 'unknown', label: 'غير محدّدة' },
        ],
        ...over,
    });

    return render(<OrdersIndex />);
};

const rowOf = (id: string) => screen.getByText(id).closest('tr') as HTMLElement;

describe('قائمةُ المبيعات والمصدر', () => {
    beforeEach(() => {
        for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    });

    it('تسم كلَّ صفٍّ بالباب الذي دخل منه', () => {
        draw();

        /*
         * والرأسُ يُسمّى «المصدر».
         *
         * وسمٌ بلا رأسٍ يُقرأ عمودًا لا يُعرف ما هو: «الموقع الإلكتروني»
         * و«نقطة البيع» كلمتان تحت عمودٍ صامت.
         */
        expect(screen.getByRole('columnheader', { name: 'المصدر' })).toBeInTheDocument();

        expect(within(rowOf('WEB-1')).getByText('الموقع الإلكتروني')).toBeInTheDocument();
        expect(within(rowOf('POS-9')).getByText('نقطة البيع')).toBeInTheDocument();
        // وما لا قناةَ له يُقال «غير محدّدة» لا «نقطة البيع»: تاريخٌ لم يُكتب لا يُملأ بالظنّ
        expect(within(rowOf('OLD-1')).getByText('غير محدّدة')).toBeInTheDocument();
        expect(within(rowOf('OLD-1')).queryByText('نقطة البيع')).toBeNull();
    });

    /**
     * ولا تُقرأ الكلمةُ مرّتين في صفٍّ واحد.
     *
     * «الموظف» يكتب فيها الخادمُ «الموقع الإلكتروني» لأنّه لا بائعَ لها —
     * فلو بقيت مع عمود المصدر لقرأ التاجرُ العبارةَ نفسَها ملتصقةً بنفسها.
     */
    it('وموضعُ الموظّف خطٌّ لطلب الموقع — المصدرُ قاله', () => {
        draw();

        expect(within(rowOf('WEB-1')).getAllByText('الموقع الإلكتروني')).toHaveLength(1);
        expect(within(rowOf('POS-9')).getByText('خالد')).toBeInTheDocument();
    });

    /**
     * والمُرشِّحُ يُعرض ويُقرأ مطبَّقًا.
     *
     * فلو وصل من الخادم ولم تعرضه الشاشةُ شريحةً لَرشّح التاجرُ القناةَ ثمّ
     * نظر إلى شريطٍ يقول «كل المصادر» — فيظنّ أنّه يرى القائمة كاملة وهو
     * يرى ثلثَها.
     */
    it('وتعرض القناةَ المطبَّقة شريحةً باسمها', () => {
        draw({ filters: { channel: 'website' } });

        // الشريحةُ تحمل اسمَ المُرشِّح ثمّ قيمتَه المختارة
        const chip = screen.getByText(/^كل المصادر:$/).parentElement as HTMLElement;
        expect(chip).toHaveTextContent('الموقع الإلكتروني');
    });

    it('وتعرض القنواتِ خيارًا حين لا يُرشَّح بها بعد', async () => {
        const user = userEvent.setup();
        draw();

        await user.click(screen.getByRole('button', { name: /أضف فلتر/ }));
        expect(await screen.findByText('كل المصادر')).toBeInTheDocument();
    });

    /**
     * وما جاء من الموقع يُقرأ بجوار الإجمالي.
     *
     * والعدُّ من الخادم لا من الصفوف المعروضة: الصفحةُ عشرةٌ من مئة، فعدٌّ
     * فيها يقول رقمًا لا معنى له.
     */
    it('ويُقرأ عددُ الموقع ومبلغُه تحت الجدول', () => {
        draw({ websiteCount: 7, websiteAmount: 310.5 });

        const line = screen.getByText(/منها من الموقع الإلكتروني/).parentElement as HTMLElement;
        expect(line).toHaveTextContent('7');
        expect(line).toHaveTextContent('310.500');
    });

    /** ومتجرٌ بلا موقعٍ لا يُقال له «٠ من الموقع» — نصيحةٌ لم يطلبها */
    it('ويُخفى السطرُ حين لا يبيع الموقعُ شيئًا', () => {
        draw({ websiteCount: 0, websiteAmount: 0 });

        expect(screen.queryByText(/منها من الموقع الإلكتروني/)).toBeNull();
    });
});
