import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import PeriodCorrection, { EMPTY_CORRECTION, correctionReady, type Correction } from '@/Components/PeriodCorrection';
import { pageProps } from './setup';

/**
 * تصحيحُ قيدٍ من شهرٍ آخر يُسأل عنه قبل الإرسال — أين يُسجَّل ولماذا.
 *
 * ═══ ما كان ═══
 *
 * حذفُ مصروفٍ من سبتمبر في أكتوبر يُرسَل كما يُرسل غيرُه، فيُطرح من أكتوبر
 * بلا سؤال. والخادمُ صار يردّه بلا اختيارٍ وسبب (`BooksPeriod::resolve`)،
 * فإن لم تسأل الشاشةُ أوّلًا صار زرُّ الحذف يُردّ ولا يقول لماذا.
 */

const crossing = (over: Partial<Correction> = {}): Correction => ({
    crosses: true,
    entry_date: '2026-09-30',
    status: 'open',
    original_allowed: true,
    closed_through: null,
    ...over,
});

describe('سؤالُ تصحيح الفترة السابقة', () => {
    beforeEach(() => {
        Object.assign(pageProps, { translations: {} });
    });

    it('لا يظهر لقيدٍ في الشهر الجاري', () => {
        const { container } = render(
            <PeriodCorrection correction={{ ...crossing(), crosses: false }} value={EMPTY_CORRECTION} onChange={() => {}} />,
        );

        expect(container).toBeEmptyDOMElement();
    });

    it('يعرض الخيارين ويرسل ما اختير', () => {
        const onChange = vi.fn();
        render(<PeriodCorrection correction={crossing()} value={EMPTY_CORRECTION} onChange={onChange} />);

        expect(screen.getByText(/2026-09-30/, { selector: 'p' })).toBeInTheDocument();
        fireEvent.click(screen.getByLabelText(/بتاريخ القيد الأصلي/));

        expect(onChange).toHaveBeenCalledWith({ correction: 'original', correction_reason: '' });
    });

    /** ولا يُعرض التأريخُ في شهرٍ مضى خيارًا ما دام لم يُحدَّد إقفال — ويقول لماذا */
    it('يعطّل التاريخ الأصليّ في فترةٍ مجهولة أو مقفلة ويقول السبب', () => {
        const { rerender } = render(
            <PeriodCorrection
                correction={crossing({ status: 'unknown', original_allowed: false })}
                value={EMPTY_CORRECTION}
                onChange={() => {}}
            />,
        );

        expect(screen.getByLabelText(/بتاريخ القيد الأصلي/)).toBeDisabled();
        expect(screen.getByText(/لم يُحدَّد تاريخ إقفال الدفاتر/)).toBeInTheDocument();
        expect(screen.getByLabelText(/في الفترة الحاليّة/)).not.toBeDisabled();

        rerender(
            <PeriodCorrection
                correction={crossing({ status: 'closed', original_allowed: false, closed_through: '2026-09-30' })}
                value={EMPTY_CORRECTION}
                onChange={() => {}}
            />,
        );

        expect(screen.getByText(/الدفاتر مقفلة حتى 2026-09-30/)).toBeInTheDocument();
    });

    it('لا يكتمل الطلبُ بلا اختيارٍ وسبب حين يعبر حدَّ شهر', () => {
        expect(correctionReady(crossing(), EMPTY_CORRECTION)).toBe(false);
        expect(correctionReady(crossing(), { correction: 'current', correction_reason: '  ' })).toBe(false);
        expect(correctionReady(crossing(), { correction: 'current', correction_reason: 'مكرّر' })).toBe(true);
        expect(correctionReady({ ...crossing(), crosses: false }, EMPTY_CORRECTION)).toBe(true);
        expect(correctionReady(undefined, EMPTY_CORRECTION)).toBe(true);
    });
});
