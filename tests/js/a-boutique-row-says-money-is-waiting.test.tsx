import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { pageProps } from './setup';

vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

const { default: BoutiquesIndex } = await import('@/Pages/Admin/Boutiques/Index');

/**
 * صفُّ البوتيك يقول «بانتظار التسوية» ما دام مالٌ ينتظر — ولو صدرت ورقة.
 *
 * ═══ ولمَ ترتيبُ الشرطين يُحرَس ═══
 *
 * صار الشهرُ يُسوَّى على دفعات، فوجودُ ورقةٍ لا يعني أنّه أُغلق: تُصدَر في
 * العاشر ثمّ يُباع في الحادي عشر. فلو سبق «سُوّي هذا الشهر» سؤالَ الانتظار
 * لَقرأ صاحبُ المحلّ صفًّا أخضرَ على مالٍ لم يُدفع، فمرّ عليه ولم يفتحه —
 * ويبقى دَينُ البوتيك بلا ورقةٍ تحمله.
 */

const row = (over: Record<string, unknown> = {}) => ({
    id: 3, name: 'ورد الخوير', name_en: null, phone: null, contact_person: null,
    rate: 20, active: true, products_count: 4, gross: 150, pending: 0, settled: false,
    ...over,
});

const draw = (boutiques: Record<string, unknown>[]) => {
    Object.assign(pageProps, {
        translations: {},
        context: { currency: { code: 'OMR', name: 'ريال', symbol: 'ر.ع', rate: 1, is_base: true, active: true } },
        boutiques,
        period: '2027-02',
        periods: [{ value: '2027-02', label: 'فبراير 2027' }],
    });

    return render(<BoutiquesIndex />);
};

describe('حالةُ صفّ البوتيك', () => {
    beforeEach(() => {
        for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    });

    it('ينتظر وإن صدرت ورقةٌ قبله', () => {
        draw([row({ settled: true, pending: 100 })]);

        expect(screen.getByText('بانتظار التسوية')).toBeInTheDocument();
        expect(screen.queryByText('سُوّي هذا الشهر')).toBeNull();
    });

    it('وسُوّي حين لم يبقَ ما ينتظر', () => {
        draw([row({ settled: true, pending: 0 })]);

        expect(screen.getByText('سُوّي هذا الشهر')).toBeInTheDocument();
    });

    it('ولا مبيعات حين لا ورقةَ ولا انتظار', () => {
        draw([row({ settled: false, pending: 0, gross: 0 })]);

        expect(screen.getByText('لا مبيعات')).toBeInTheDocument();
    });
});
