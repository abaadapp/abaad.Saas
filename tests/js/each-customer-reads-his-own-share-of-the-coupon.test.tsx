import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { LimitsDialog, limitsLine } from '@/Pages/Admin/Marketing/Coupons';
import type { Coupon } from '@/types/models';
import { pageProps } from './setup';

/**
 * شاشةُ الكوبونات تفرّق بين الحدّين — ولا تعرض أحدَهما مكانَ الآخر.
 *
 * ═══ العطبُ الذي يحرسه هذا الملفّ ═══
 *
 * «استُخدم ٣ / ١٠٠» عدٌّ إجماليّ لكلّ الناس، و«لكل زبون ٢» حدٌّ لكلّ واحد.
 * فلو قُرئ الأوّلُ على أنّه الثاني لَظنّ التاجرُ كودَه انتهى وهو يعمل لزبونٍ
 * جديد؛ ولو غاب الثاني من السطر لَظنّه بلا حدٍّ وهو مقيَّدٌ بمرّة — فيطبعه
 * على لافتةٍ ويُردّ زبائنُه عند الصندوق.
 *
 * والترجمةُ تردّ المفتاحَ نفسَه، والمفتاحُ هو النصُّ العربيّ — فالمقروءُ هنا
 * هو ما يقرؤه التاجر.
 */
const t = (k: string) => k;
const currency = {
    code: 'OMR',
    name: 'ريال عماني',
    symbol: 'ر.ع',
    decimals: 3,
    rate: 1,
    is_base: true,
    active: true,
};

const row = (over: Partial<Coupon> = {}): Coupon => ({
    id: 1,
    code: 'SAVE10',
    type: 'مبلغ',
    value: 10,
    min_order: 0,
    max_uses: null,
    per_customer_limit: null,
    per_customer_since: null,
    used_count: 0,
    expires: '',
    expired: false,
    exhausted: false,
    active: true,
    usable: true,
    display: '10',
    ...over,
});

describe('سطرُ حدودِ الكوبون', () => {
    it('كوبونٌ بلا حدود يقول عدَّه وحدَه', () => {
        expect(limitsLine(row({ used_count: 3 }), t, currency)).toBe('استُخدم 3');
    });

    it('والحدُّ الإجماليُّ بشُرطةٍ مائلة بعد العدّ — كما كان', () => {
        expect(limitsLine(row({ used_count: 3, max_uses: 100 }), t, currency)).toBe('استُخدم 3 / 100');
    });

    it('والحدُّ لكلّ زبون يُقال باسمه لا بالشُّرطة المائلة', () => {
        const line = limitsLine(row({ used_count: 3, per_customer_limit: 2 }), t, currency);

        expect(line).toBe('استُخدم 3 · لكل زبون 2');
        expect(line).not.toContain('/ 2');
    });

    it('والحدّان معًا يُقرآن منفصلين', () => {
        expect(limitsLine(row({ used_count: 3, max_uses: 100, per_customer_limit: 2 }), t, currency)).toBe(
            'استُخدم 3 / 100 · لكل زبون 2',
        );
    });

    it('وكوبونٌ بلا حدٍّ لكلّ زبون لا يُكتب له «لكل زبون»', () => {
        expect(limitsLine(row({ used_count: 3, max_uses: 100 }), t, currency)).not.toContain('لكل زبون');
    });

    it('والحدُّ الأدنى والانتهاءُ يبقيان في موضعيهما', () => {
        expect(
            limitsLine(row({ min_order: 20, used_count: 1, per_customer_limit: 2, expires: '2027-01-01' }), t, currency),
        ).toBe('حد أدنى 20.000 ر.ع · استُخدم 1 · لكل زبون 2 · ينتهي 2027-01-01');
    });
});

describe('نافذةُ حدودِ الكوبون', () => {
    it('تفتح على حدَّي الكوبون بعينه — لا على حدود غيره', () => {
        Object.assign(pageProps, { translations: {} });

        render(
            <LimitsDialog
                coupon={row({ code: 'SAVE10', max_uses: 100, per_customer_limit: 2 })}
                onClose={() => {}}
            />,
        );

        expect(screen.getByText('SAVE10')).toBeInTheDocument();
        expect(screen.getByLabelText('أقصى عدد استخدامات (إجمالًا)')).toHaveValue(100);
        expect(screen.getByLabelText('الحد لكل زبون')).toHaveValue(2);
    });

    it('وتقول من أيّ يومٍ يُحسب الحدُّ، وأنّ إطفاءَه لا يُصفّر شيئًا', () => {
        render(
            <LimitsDialog
                coupon={row({ per_customer_limit: 2, per_customer_since: '2027-03-08' })}
                onClose={() => {}}
            />,
        );

        expect(screen.getByText(/2027-03-08/)).toBeInTheDocument();
        expect(screen.getByText(/لا يُصفّر/)).toBeInTheDocument();
    });

    it('وكودٌ بلا حدٍّ بعد تقول له متى يبدأ العدُّ إن ضبطه', () => {
        render(<LimitsDialog coupon={row()} onClose={() => {}} />);

        expect(screen.getByText(/من لحظة تفعيله/)).toBeInTheDocument();
        expect(screen.getByLabelText('الحد لكل زبون')).toHaveValue(null);
    });

    it('ولا تُرسم نافذةٌ بلا كوبون', () => {
        render(<LimitsDialog coupon={null} onClose={() => {}} />);

        expect(screen.queryByText('حدود الكوبون')).not.toBeInTheDocument();
    });
});
