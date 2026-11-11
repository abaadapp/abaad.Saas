import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { pageProps } from './setup';

vi.mock('@/Layouts/PosLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <div>{children}</div> }));
vi.mock('@/hooks/useLiveStock', () => ({ default: (_url: string, p: unknown[]) => ({ products: p, updatedAt: null }) }));

/**
 * الصندوقُ يسأل قبل أن يصرف النقاط — ويصرفها بقيمة التاجر.
 *
 * عميلٌ مؤهَّل يُعرض رصيدُه وما يستطيع خصمَه، ومعه «استخدام النقاط» و«بدون
 * خصم». ولا خصمَ قبل الجواب. والأرقامُ من إعدادات المتجر لا من ثابتٍ في الشاشة.
 */

const CUSTOMER = { id: 1, name: 'مريم', label: 'مريم — 96899110001', phone: '96899110001', points: 500, language: 'ar' };

const draw = (settings: Record<string, unknown> = {}, over: Record<string, unknown> = {}) => {
    Object.assign(pageProps, {
        translations: {},
        products: [],
        categories: [{ value: 'الكل', label: 'الكل' }],
        seasons: [],
        customers: [CUSTOMER],
        addons: [],
        coupons: [],
        resumeCart: { id: null, customer: 'مريم', items: [{ id: 1, name: 'باقة ورد', price: 20, qty: 2 }] },
        settings: { loyaltyEnabled: true, redeemMaxPct: 50, earnRate: 1, redeemMin: 100, ...settings },
        orderOptions: undefined,
        customOrder: { templates: [] },
        context: { currency: { code: 'OMR', name: 'ريال', symbol: 'ر.ع', rate: 1, is_base: true, active: true, decimals: 3 } },
        ...over,
    });
    localStorage.clear();
};

const open = async () => {
    const { default: PosIndex } = await import('@/Pages/Pos/Index');

    return render(<PosIndex />);
};

describe('تنبيهُ النقاط في الصندوق', () => {
    beforeEach(() => {
        for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    });

    it('يعرض الرصيدَ وما يُخصم، بزرّين، ولا يخصم قبل الجواب', async () => {
        draw();
        await open();

        const offer = screen.getByTestId('redeem-offer');
        // خمسمئة نقطةٍ بالصرف القديم = خمسة ريالات، دون سقف العشرين
        expect(offer).toHaveTextContent('500');
        expect(offer).toHaveTextContent(/5[.,]000/);
        expect(screen.getByRole('button', { name: 'استخدام النقاط' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'بدون خصم' })).toBeInTheDocument();
        expect(screen.queryByTestId('redeem-line')).toBeNull();
    });

    it('«بدون خصم» يُغلق التنبيه ولا يخصم — ويبقى بابُ الرجوع', async () => {
        draw();
        await open();

        fireEvent.click(screen.getByRole('button', { name: 'بدون خصم' }));

        expect(screen.queryByTestId('redeem-offer')).toBeNull();
        expect(screen.queryByTestId('redeem-line')).toBeNull();
        expect(screen.getByRole('button', { name: 'استخدام النقاط' })).toBeInTheDocument();
    });

    it('يصرف بقيمة التاجر: عشرُ نقاطٍ بنصف ريال', async () => {
        draw({ redeemPoints: 10, redeemValue: 0.5, redeemMin: 10 });
        await open();

        // سقفُ الفاتورة عشرون = أربعمئة نقطة، والرصيدُ خمسمئة
        fireEvent.click(screen.getByRole('button', { name: 'استخدام النقاط' }));

        const line = screen.getByTestId('redeem-line');
        expect(line).toHaveTextContent('400 نقطة');
        expect(line).toHaveTextContent(/20[.,]000/);
        expect(line).toHaveTextContent(/يبقى 100 نقطة/);
    });

    it('والاستبدالُ المطفأ لا يعرض تنبيهًا ويقول لماذا', async () => {
        draw({ redeemEnabled: false });
        await open();

        expect(screen.queryByTestId('redeem-offer')).toBeNull();
        expect(screen.queryByRole('button', { name: 'استخدام النقاط' })).toBeNull();
        expect(screen.getByTestId('redeem-reason')).toHaveTextContent('الاستبدال متوقّف');
    });

    it('وفاتورةٌ دون الحدّ الأدنى لا تعرض تنبيهًا', async () => {
        draw({ redeemMinInvoice: 50 });
        await open();

        expect(screen.queryByTestId('redeem-offer')).toBeNull();
        expect(screen.getByTestId('redeem-reason')).toHaveTextContent('دون الحدّ الأدنى');
    });
});
