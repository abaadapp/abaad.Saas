import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { pageProps } from './setup';

/**
 * فاتورةُ العميل تسأل قبل أن تصرف النقاط — بعرضٍ حسبه الخادم.
 *
 * التنبيهُ يقول الرصيدَ وما يُخصم من هذه الفاتورة، ويُجاب بـ«استخدام النقاط»
 * أو «بدون خصم». ولا يُطبَّق قبل الجواب، وجوابٌ يُغيَّر.
 */

const offer = { eligible: true, reason: null, balance: 1000, points: 1000, discount: 10 };

const show = async (props: Record<string, unknown>) => {
    Object.assign(pageProps, {
        translations: {},
        context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 } },
    });
    const { LoyaltyPrompt } = await import('@/Pages/Admin/CustomerInvoices/Create');

    return render(<LoyaltyPrompt offer={offer} choice="ask" onChoose={() => {}} {...props} />);
};

describe('تنبيهُ النقاط في فاتورة العميل', () => {
    beforeEach(() => {
        for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    });

    it('يعرض الرصيدَ وما يُخصم، ويسأل بزرّين', async () => {
        const onChoose = vi.fn();
        await show({ onChoose });

        const box = screen.getByTestId('invoice-loyalty');
        expect(box).toHaveTextContent('1000');
        expect(box).toHaveTextContent(/10[.,]000/);

        fireEvent.click(screen.getByRole('button', { name: 'استخدام النقاط' }));
        expect(onChoose).toHaveBeenLastCalledWith('use');

        fireEvent.click(screen.getByRole('button', { name: 'بدون خصم' }));
        expect(onChoose).toHaveBeenLastCalledWith('skip');
    });

    it('وبعد الجواب يقول ما اختير ويُتيح الرجوع', async () => {
        const onChoose = vi.fn();
        await show({ choice: 'use', onChoose });

        expect(screen.getByTestId('invoice-loyalty-choice')).toHaveTextContent('يُخصم من رصيده عند إصدار الفاتورة');
        fireEvent.click(screen.getByRole('button', { name: 'إلغاء' }));
        expect(onChoose).toHaveBeenLastCalledWith('skip');
    });

    it('وعميلٌ غيرُ مؤهَّل يُقال سببُه بلا زرّ', async () => {
        await show({ offer: { ...offer, eligible: false, reason: 'below_min_invoice', points: 0, discount: 0 } });

        expect(screen.queryByTestId('invoice-loyalty')).toBeNull();
        expect(screen.getByTestId('invoice-loyalty-reason')).toHaveTextContent('دون الحدّ الأدنى');
        expect(screen.queryByRole('button', { name: 'استخدام النقاط' })).toBeNull();
    });

    it('وخطأُ الخادم على حقل النقاط يُعرض تحت التنبيه', async () => {
        await show({ error: 'تغيّر رصيد نقاط العميل' });

        expect(screen.getByTestId('invoice-loyalty')).toHaveTextContent('تغيّر رصيد نقاط العميل');
    });
});
