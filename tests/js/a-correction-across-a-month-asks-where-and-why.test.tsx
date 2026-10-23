import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import PeriodCorrection, { EMPTY_CORRECTION, REASON_MAX, correctionReady, type Correction } from '@/Components/PeriodCorrection';
import { EMPTY_SCOPE, scopeMovesTheEntry } from '@/Pages/Admin/Expenses/ScopeFields';
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

    /** الاستعادةُ تُعيد القيدَ بيوم حذفه — فلا تُسمّيه «تاريخ القيد الأصليّ» */
    it('يسمّي تاريخَ الحذف باسمه في الاستعادة', () => {
        render(<PeriodCorrection subject="deletion" correction={crossing()} value={EMPTY_CORRECTION} onChange={() => {}} />);

        expect(screen.getByLabelText(/بتاريخ الحذف \(2026-09-30\)/)).toBeInTheDocument();
        expect(screen.queryByLabelText(/بتاريخ القيد الأصلي/)).not.toBeInTheDocument();
        expect(screen.getByText(/حُذف هذا المصروف من الدفتر بتاريخ 2026-09-30/)).toBeInTheDocument();
    });

    /** وسببٌ أطول ممّا يقبله الخادم يُقطع ذيلُه في القيد — فلا يُكتب أصلًا */
    it('لا يقبل سببًا أطولَ ممّا يحفظه القيد', () => {
        render(<PeriodCorrection correction={crossing()} value={EMPTY_CORRECTION} onChange={() => {}} />);

        expect(REASON_MAX).toBe(120);
        expect(screen.getByRole('textbox')).toHaveAttribute('maxLength', String(REASON_MAX));
    });

    /** وتغييرُ نطاقٍ لا يبدّل فرعَ القيد لا يمسّ الدفتر — فلا يُسأل عن سببٍ لا يُحفظ */
    it('يُسأل في النطاق حين يتبدّل فرعُ القيد وحده', () => {
        expect(scopeMovesTheEntry(5, { ...EMPTY_SCOPE, scope: 'branch', branch_id: '5' })).toBe(false);
        expect(scopeMovesTheEntry(null, { ...EMPTY_SCOPE, scope: 'split' })).toBe(false);
        expect(scopeMovesTheEntry(null, EMPTY_SCOPE)).toBe(false);
        expect(scopeMovesTheEntry(5, EMPTY_SCOPE)).toBe(true);
        expect(scopeMovesTheEntry(5, { ...EMPTY_SCOPE, scope: 'branch', branch_id: '7' })).toBe(true);
        expect(scopeMovesTheEntry(null, { ...EMPTY_SCOPE, scope: 'branch', branch_id: '3' })).toBe(true);
    });

    it('لا يكتمل الطلبُ بلا اختيارٍ وسبب حين يعبر حدَّ شهر', () => {
        expect(correctionReady(crossing(), EMPTY_CORRECTION)).toBe(false);
        expect(correctionReady(crossing(), { correction: 'current', correction_reason: '  ' })).toBe(false);
        expect(correctionReady(crossing(), { correction: 'current', correction_reason: 'مكرّر' })).toBe(true);
        expect(correctionReady({ ...crossing(), crosses: false }, EMPTY_CORRECTION)).toBe(true);
        expect(correctionReady(undefined, EMPTY_CORRECTION)).toBe(true);
    });
});
