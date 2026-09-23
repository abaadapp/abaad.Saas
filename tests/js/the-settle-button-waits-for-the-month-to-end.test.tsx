import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { pageProps } from './setup';

/**
 * زرُّ «أصدِر التسوية» لا يعمل قبل أن يُطوى الشهر.
 *
 * ═══ ولِمَ يُعطَّل بدل أن يُردّ ═══
 *
 * الخادمُ يردّ الطلبَ على كلّ حال — وهو الحارسُ الذي يُعتمد عليه. لكنّ
 * زرًّا حيًّا يُضغط ثمّ يُردّ لا يُعلّم صاحبَه شيئًا: يظنّها عطبًا في
 * النظام لا قاعدةً في المحاسبة. فالشاشةُ تقولها قبل الضغط.
 *
 * و«أانتهى الشهر؟» يُقرأ من الخادم لا يُحسب هنا: متصفّحٌ على توقيتٍ آخر
 * يرى أوّلَ آذار والخادمُ ما زال في شباط.
 */

vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

const { default: Show } = await import('@/Pages/Admin/Boutiques/Show');

const line = {
    product_id: 1, name: 'عطر لمى', variant: null, rate: 20,
    quantity: 2, gross: 100, commission: 20, net: 80,
};

const draw = (closed: boolean, lines = [line]) => {
    for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];

    Object.assign(pageProps, {
        translations: {},
        auth: { abilities: ['products'], mayActions: [] },
        context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 }, hosted: ['boutiques'] },
        errors: {},
        boutique: {
            id: 7, name: 'بوتيك لمى', name_en: null, phone: null,
            contact_person: null, rate: 20, active: true, notes: null,
        },
        statement: {
            period: '2027-02', from: '2027-02-01', to: '2027-02-28', lines,
            quantity: 2, gross: 100, commission: 20, net: 80, lines_count: lines.length,
        },
        period: '2027-02',
        periods: [{ value: '2027-02', label: 'فبراير 2027' }],
        closed,
        settlement: null,
        history: [],
    });

    return render(<Show />);
};

const button = () => screen.getByRole('button', { name: 'أصدر التسوية' });

describe('زرُّ التسوية', () => {
    beforeEach(() => {
        for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    });

    it('يُعطَّل ما دام الشهرُ يبيع — ويُقال السبب', () => {
        draw(false);

        expect(button()).toBeDisabled();
        expect(
            screen.getByText(/الشهرُ لم ينتهِ بعد/),
        ).toBeInTheDocument();
    });

    it('ويعمل إذا طُوي الشهر', () => {
        draw(true);

        expect(button()).toBeEnabled();
        expect(screen.queryByText(/الشهرُ لم ينتهِ بعد/)).toBeNull();
    });

    /** وشهرٌ طُوي بلا بيعٍ يبقى معطَّلًا — الحارسان لا يُلغي أحدُهما الآخر */
    it('ولا يعمل لشهرٍ طُوي بلا بيع', () => {
        draw(true, []);

        expect(button()).toBeDisabled();
        expect(screen.getByText(/لا تسوية بلا بيع/)).toBeInTheDocument();
    });
});
