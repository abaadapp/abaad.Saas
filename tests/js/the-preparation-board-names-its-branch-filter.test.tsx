import { afterEach, describe, expect, it, vi } from 'vitest';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { router } from '@inertiajs/react';

import PreparationIndex from '@/Pages/Admin/Preparation/Index';
import PreparationPanel, { type PreparationPanelData } from '@/Pages/Admin/CustomerInvoices/partials/PreparationPanel';
import { pageProps } from './setup';

vi.mock('@/Layouts/AdminLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <>{children}</> }));

/**
 * ما تقصّه اللوحةُ يُقال فوقها ويُلغى منها.
 *
 * العطبُ كان صمتًا: لوحةٌ مرشَّحةٌ بفرعٍ في جلسةٍ لا يراها أحد. فيُحرس هنا
 * أنّ الفرعَ اختيارٌ ظاهر، وأنّ إلغاءه يُسقطه من العنوان ويُبقي أخويه، وأنّ
 * الاستطلاعَ يحمل ما تعرضه اللوحةُ الآن، وأنّ ما فوق السقف يُبلغ.
 */

const BOARD = {
    context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 } },
    auth: { abilities: [], mayActions: [] },
    orders: [],
    filters: { when: 'today', type: 'delivery', branch: null as number | null, show: null as number | null },
    counts: { all: 0, overdue: 0, today: 0, tomorrow: 0 },
    typeCounts: { all: 0, delivery: 0, pickup: 0 },
    columns: {},
    columnCounts: {},
    truncated: null as { shown: number; total: number; more?: number | null } | null,
    fetchedAt: '2026-11-24T09:00:00+04:00',
    branches: [
        { id: 4, name: 'الخوير' },
        { id: 7, name: 'السيب' },
    ],
    limited: null as string[] | null,
    invoiceDetails: null,
};

function board(over: Partial<typeof BOARD> = {}) {
    Object.assign(pageProps, BOARD, over);

    return render(<PreparationIndex />);
}

const visited = () => vi.mocked(router.get).mock.calls.at(-1)?.[1];

afterEach(() => {
    vi.mocked(router.get).mockReset();
    vi.mocked(router.reload).mockReset();
    for (const k of Object.keys(BOARD)) delete (pageProps as Record<string, unknown>)[k];
    Object.assign(pageProps, { context: BOARD.context, auth: BOARD.auth });
});

describe('مُرشِّحُ الفرع على لوحة التجهيز', () => {
    it('الأصلُ كلُّ فروعه — واختيارُ فرعٍ يذهب إلى العنوان مع مرشّحَيه لا إلى الجلسة', async () => {
        board();
        const group = within(screen.getByTestId('prep-branches'));

        expect(group.getByRole('button', { name: 'كل الفروع' })).toHaveAttribute('aria-pressed', 'true');
        expect(screen.queryByTestId('prep-branch-filter')).toBeNull();

        await userEvent.click(group.getByRole('button', { name: 'السيب' }));
        expect(visited()).toEqual({ when: 'today', type: 'delivery', branch: 7 });
    });

    it('والمختارُ يُكتب فوق اللوحة ويُلغى بضغطة — ولا تُقال «لا طلبات» مطلقةً تحته', async () => {
        board({ filters: { when: null as unknown as string, type: null as unknown as string, branch: 7, show: 400 } });

        const bar = within(screen.getByTestId('prep-branch-filter'));
        expect(bar.getByText('تُعرض طلبات فرع «السيب» وحده')).toBeInTheDocument();
        expect(screen.getByText('لا طلبات تنتظر التجهيز في هذا الفرع')).toBeInTheDocument();

        await userEvent.click(bar.getByRole('button', { name: 'عرض كل الفروع' }));
        // وسقفُ «عرض المزيد» يخصّ ما كان معروضًا
        expect(visited()).toEqual({});
    });

    it('ومتجرٌ بفرعٍ واحد لا يُعرض له مُرشِّح', () => {
        board({ branches: [] });

        expect(screen.queryByTestId('prep-branches')).toBeNull();
        expect(screen.queryByTestId('prep-limited')).toBeNull();
    });

    it('ومن قُيِّد بفروعٍ يُقال له ما تعرضه لوحتُه — ومن زال فرعُه يُقال له', () => {
        const { unmount } = board({ branches: [], limited: ['الخوير'] });
        expect(screen.getByTestId('prep-limited')).toHaveTextContent('تعرض لوحتك طلبات فروعك: الخوير');
        unmount();

        board({ branches: [], limited: [] });
        expect(screen.getByTestId('prep-limited')).toHaveTextContent('لست مُسنَدًا إلى فرع قائم');
    });

    it('وما فوق السقف يُبلغ بـ«عرض المزيد» مع ما رُشّح به — وعند أقصاه لا زرّ', async () => {
        const { unmount } = board({
            filters: { when: 'today', type: 'delivery', branch: 4, show: null },
            truncated: { shown: 200, total: 205, more: 400 },
        });
        await userEvent.click(screen.getByTestId('prep-more'));
        expect(visited()).toEqual({ when: 'today', type: 'delivery', branch: 4, show: 400 });
        unmount();

        board({ truncated: { shown: 1000, total: 1200, more: null } });
        expect(screen.queryByTestId('prep-more')).toBeNull();
    });

    it('والاستطلاعُ يحمل نطاقَ اللوحة مع بطاقاتها — فلا يبقى على الشاشة ترشيحٌ زال', async () => {
        board();
        await userEvent.click(screen.getByTestId('prep-refresh'));

        const only = vi.mocked(router.reload).mock.calls.at(-1)?.[0]?.only ?? [];
        for (const prop of ['orders', 'counts', 'typeCounts', 'columnCounts', 'truncated', 'filters', 'branches', 'limited']) {
            expect(only).toContain(prop);
        }
    });
});

describe('صفحةُ الفاتورة', () => {
    it('تقول إنّ المهمّة ظهرت بتحصيلٍ مسجَّل', () => {
        const panel: PreparationPanelData = {
            enabled: true, attachable: false, editable: false, removable: false, activatable: false, paid: true,
            data: {
                branch_id: 4, branch: 'الخوير', fulfillment_type: 'pickup', scheduled_for: '2026-11-25T16:00', recipient_name: 'مريم',
                recipient_phone: '99887766', delivery_address: null, delivery_notes: null, internal_notes: null, is_gift: false,
                occasion_type: null, occasion_text: null, card_message: null, sender_name: null, hide_sender: false, card_identity_confirmed: false,
            } as unknown as PreparationPanelData['data'],
            active: true, status: 'مؤكّد', activated_at: '2026-11-24 09:00', activation_source: 'payment', activation_error: null,
            waiting_for: null, print: null, options: null,
        };
        render(<PreparationPanel invoiceId={1} preparation={panel} />);

        expect(screen.getByTestId('preparation-state')).toHaveTextContent('بتحصيل مسجَّل');
    });
});
