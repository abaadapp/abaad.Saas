import { fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it } from 'vitest';

import TrashPanel from '@/Pages/Admin/Settings/panels/TrashPanel';
import { pageProps } from './setup';

/**
 * المحذوفات تقول ما في الدفتر — بقيّةُ حذفٍ قديم تُعرض، ومصروفٌ لم يُقيَّد قطّ يُسمّى باسمه.
 *
 * ═══ ما يُحرَس ═══
 *
 * محذوفٌ ما زال قيدُه حيًّا (`Books::stillCounts`) يحمل «ما زال في الدفتر»:
 * الشاشةُ تقرأ صفرًا والدفترُ مبلغًا، ولا يُصحَّح من تلقاء نفسه — فيُرى.
 * ومحذوفٌ عُكس قيدُه لا يحمله. وما يُدَّعى له شيءٌ في الدفتر لا زرَّ محوٍ له.
 *
 * واستعادةُ مدفوعٍ لم يُقيَّد قطّ من شهرٍ مضى تسأل بتاريخ صرفه لا «بتاريخ
 * الحذف» — لا حذفَ في الدفتر يُعاد.
 */
const expense = (over: Record<string, unknown> = {}) => ({
    id: 1,
    reference: 'EXP-1001',
    title: 'إيجار',
    amount: 300,
    spentAt: '2026-09-10',
    deletedAt: '2026-10-01 09:00',
    deletedBy: 'المالك',
    kept: true,
    daysLeft: null,
    correction: { crosses: false, entry_date: null, status: null, original_allowed: false, closed_through: null },
    correctionSubject: 'deletion' as const,
    stillCounted: false,
    ...over,
});

const panel = (expenses: ReturnType<typeof expense>[]) => (
    <TrashPanel products={[]} expenses={expenses} customers={[]} trashedBranches={[]} windowDays={90} />
);

describe('محذوفاتُ المصروفات', () => {
    beforeEach(() => {
        Object.assign(pageProps, { translations: {} });
    });

    it('يعرض «ما زال في الدفتر» لبقيّة حذفٍ قديم وحدها', () => {
        render(panel([expense({ id: 1, reference: 'EXP-1', stillCounted: true }), expense({ id: 2, reference: 'EXP-2' })]));

        expect(screen.getAllByTestId('still-counted')).toHaveLength(1);
        const row = screen.getByText('EXP-1').closest('tr')!;
        expect(within(row).getByTestId('still-counted')).toHaveTextContent('ما زال في الدفتر');
        // وما يبقى دليلًا لا زرَّ محوٍ له
        expect(screen.queryByTitle('محو نهائي')).not.toBeInTheDocument();
    });

    it('يسأل عن مصروفٍ لم يُقيَّد قطّ بتاريخ صرفه', () => {
        render(
            panel([
                expense({
                    kept: false,
                    daysLeft: 80,
                    correctionSubject: 'unposted',
                    correction: { crosses: true, entry_date: '2026-09-10', status: 'unknown', original_allowed: false, closed_through: null },
                }),
            ]),
        );

        fireEvent.click(screen.getByRole('button', { name: /استعادة/ }));

        const dialog = screen.getByTestId('restore-dialog');
        expect(within(dialog).getByText(/لم يُقيَّد هذا المصروف في الدفتر قطّ/)).toBeInTheDocument();
        expect(within(dialog).getByLabelText(/بتاريخ المصروف \(2026-09-10\)/)).toBeDisabled();
        expect(within(dialog).queryByLabelText(/بتاريخ الحذف/)).not.toBeInTheDocument();
    });
});
