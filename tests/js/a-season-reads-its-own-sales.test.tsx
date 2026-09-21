import { router } from '@inertiajs/react';
import { act, fireEvent, render, renderHook, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { usePosCart } from '@/hooks/usePosCart';
import Performance, { type SeasonPerformance } from '@/Pages/Admin/Seasons/Performance';
import { pageProps } from './setup';

/**
 * أداءُ الموسم يرسم ما نُسب إليه وحدَه — وبمجمل الربح لا صافيه، وبالقنوات
 * التي باعت لا بكلّ قناةٍ يعرفها النظام.
 */
const currency = { code: 'OMR', name: 'ريال عماني', symbol: 'ر.ع', rate: 1, is_base: true, active: true, decimals: 3 };

const data = (over: Partial<SeasonPerformance> = {}): SeasonPerformance => ({
    summary: { sales: 120.5, cogs: 48.2, gross_profit: 72.3, margin: 60, orders: 7, units: 19 },
    channels: [{ key: 'pos', label: 'نقطة البيع', sales: 120.5, orders: 7, gross_profit: 72.3 }],
    top: [
        { product_id: 1, name: 'باقة ورد', units: 12, sales: 100, gross_profit: 60 },
        { product_id: 2, name: 'زهرة', units: 7, sales: 20.5, gross_profit: 12.3 },
    ],
    unattributed: { orders: 0, units: 0 },
    channel: null,
    ...over,
});

beforeEach(() => {
    Object.assign(pageProps, { translations: {} });
    vi.mocked(router.get).mockClear();
});

describe('أداءُ الموسم', () => {
    it('يرسم البطاقات الستّ بأرقام الخادم كما هي', () => {
        render(<Performance seasonId={3} data={data()} currency={currency} />);

        // العناوينُ تظهر في البطاقات وفي رؤوس الجدولين — فتُقرأ البطاقاتُ وحدَها
        const cards = screen.getByText('هامش مجمل الربح').closest('.grid') as HTMLElement;
        for (const label of ['إجمالي المبيعات', 'تكلفة البضاعة المباعة', 'مجمل الربح', 'هامش مجمل الربح', 'عدد الطلبات', 'الكمية المباعة']) {
            expect(within(cards).getByText(label)).toBeInTheDocument();
        }
        expect(within(cards).getByText('120.500 ر.ع')).toBeInTheDocument();
        expect(within(cards).getByText('48.200 ر.ع')).toBeInTheDocument();
        expect(within(cards).getByText('72.300 ر.ع')).toBeInTheDocument();
        expect(within(cards).getByText('60.0%')).toBeInTheDocument();
        expect(within(cards).getByText('7')).toBeInTheDocument();
        expect(within(cards).getByText('19')).toBeInTheDocument();
    });

    it('ولا يدّعي صافيَ ربح — يقول إنّه يحتاج مصروفاتٍ منسوبة', () => {
        render(<Performance seasonId={3} data={data()} currency={currency} />);

        expect(screen.queryByText('صافي الربح')).not.toBeInTheDocument();
        expect(screen.getByText(/صافي ربح الموسم يحتاج ربط المصروفات بالموسم/)).toBeInTheDocument();
    });

    it('والقنواتُ ما باع منها فقط — لا صفَّ للموقع ولا صفرًا باسمه', () => {
        render(<Performance seasonId={3} data={data()} currency={currency} />);

        const table = screen.getByTestId('season-channels');
        expect(within(table).getByText('نقطة البيع')).toBeInTheDocument();
        expect(within(table).queryByText('الموقع الإلكتروني')).not.toBeInTheDocument();
        expect(within(table).getAllByRole('row')).toHaveLength(2);
    });

    it('وأفضلُ المنتجات بالاسم كما بيع، والأعلى أوّلًا', () => {
        render(<Performance seasonId={3} data={data()} currency={currency} />);

        const rows = within(screen.getByTestId('season-top')).getAllByRole('row').slice(1);
        expect(rows.map((r) => r.textContent)).toEqual([
            expect.stringContaining('باقة ورد'),
            expect.stringContaining('زهرة'),
        ]);
        expect(rows[0].textContent).toContain('12');
        expect(rows[0].textContent).toContain('100.000 ر.ع');
    });

    it('واختيارُ قناةٍ يطلب الصفحةَ بها ويُبقي الموضع', () => {
        render(<Performance seasonId={3} data={data()} currency={currency} />);

        fireEvent.click(screen.getByRole('button', { name: 'نقطة البيع' }));
        expect(router.get).toHaveBeenCalledWith('/admin.seasons.show/3', { channel: 'pos' }, expect.objectContaining({ preserveScroll: true }));

        fireEvent.click(screen.getByRole('button', { name: 'كل القنوات' }));
        expect(router.get).toHaveBeenLastCalledWith('/admin.seasons.show/3', {}, expect.objectContaining({ preserveScroll: true }));
    });

    it('والقناةُ المختارة مضغوطة', () => {
        render(<Performance seasonId={3} data={data({ channel: 'pos' })} currency={currency} />);

        expect(screen.getByRole('button', { name: 'نقطة البيع' })).toHaveAttribute('aria-pressed', 'true');
        expect(screen.getByRole('button', { name: 'كل القنوات' })).toHaveAttribute('aria-pressed', 'false');
    });

    it('وما لم يُنسب يُقال عددًا لا مالًا — ولا يُقال حين لا شيء', () => {
        const { rerender } = render(<Performance seasonId={3} data={data()} currency={currency} />);
        expect(screen.queryByTestId('season-unattributed')).not.toBeInTheDocument();

        rerender(<Performance seasonId={3} data={data({ unattributed: { orders: 4, units: 9 } })} currency={currency} />);
        expect(screen.getByTestId('season-unattributed').textContent).toContain('4');
        expect(screen.getByTestId('season-unattributed').textContent).toContain('9');
    });

    it('وموسمٌ بلا بيعات يقولها ولا يرسم شريطَ قنوات', () => {
        render(
            <Performance
                seasonId={3}
                data={data({ summary: { sales: 0, cogs: 0, gross_profit: 0, margin: 0, orders: 0, units: 0 }, channels: [], top: [] })}
                currency={currency}
            />,
        );

        expect(screen.getAllByText('لا مبيعات منسوبة لهذا الموسم بعد.')).toHaveLength(2);
        expect(screen.queryByRole('group', { name: 'قناة البيع' })).not.toBeInTheDocument();
    });
});

/**
 * سلّةُ الصندوق تحمل موسمَ البند إلى الخادم كما اختير — ولا تنقله ولا تخمّنه.
 */
describe('سلّةُ الصندوق وموسمُ البند', () => {
    const onToast = vi.fn();
    let fetchMock: ReturnType<typeof vi.fn>;

    const cart = () =>
        renderHook(() =>
            usePosCart({
                products: [],
                customers: [],
                coupons: [],
                loyalty: { redeemMaxPct: 50, earnRate: 1, redeemMin: 100 },
                resume: null,
                currency: { code: 'OMR', name: 'ريال', symbol: 'ر.ع', rate: 1, is_base: true, active: true },
                onToast,
            }),
        );

    beforeEach(() => {
        localStorage.clear();
        fetchMock = vi.fn(async () => ({ ok: true, status: 200, json: async () => ({ ok: true, invoice: 'INV-1', points_earned: 0 }) }));
        vi.stubGlobal('fetch', fetchMock);
    });
    afterEach(() => {
        vi.unstubAllGlobals();
        onToast.mockReset();
    });

    it('البندُ يحمل موسمَه إلى الدفع، والمضافُ من «الكل» يحمل لا شيء', async () => {
        const { result } = cart();
        act(() => result.current.add({ key: 'p7', id: 7, name: 'باقة', price: 5, stock: 10, season_id: 3 }));
        act(() => result.current.add({ key: 'p8', id: 8, name: 'زهرة', price: 1, stock: 10, season_id: null }));
        act(() => result.current.add({ key: 'p9', id: 9, name: 'دبّ', price: 2, stock: 10 }));

        await act(async () => {
            await result.current.checkoutSale('نقدي');
        });

        const body = JSON.parse((fetchMock.mock.calls[0] as [string, { body: string }])[1].body);
        expect(body.items.map((i: { id: number; season_id: number | null }) => [i.id, i.season_id])).toEqual([[7, 3], [8, null], [9, null]]);
    });

    it('وضغطةٌ ثانية على البند نفسه تزيد كميّته ولا تنقله من موسمه', async () => {
        const { result } = cart();
        act(() => result.current.add({ key: 'p7', id: 7, name: 'باقة', price: 5, stock: 10, season_id: 3 }));
        act(() => result.current.add({ key: 'p7', id: 7, name: 'باقة', price: 5, stock: 10, season_id: null }));

        expect(result.current.items).toHaveLength(1);
        expect(result.current.items[0].qty).toBe(2);
        expect(result.current.items[0].season_id).toBe(3);
    });

    it('والسلّةُ المستأنَفة تعود بموسمها', () => {
        const { result } = renderHook(() =>
            usePosCart({
                products: [],
                customers: [],
                coupons: [],
                loyalty: { redeemMaxPct: 50, earnRate: 1, redeemMin: 100 },
                resume: { id: 5, customer: null, items: [{ id: 7, name: 'باقة', price: 5, qty: 2, season_id: 3 }] },
                currency: { code: 'OMR', name: 'ريال', symbol: 'ر.ع', rate: 1, is_base: true, active: true },
                onToast,
            }),
        );

        expect(result.current.items[0].season_id).toBe(3);
    });
});

/**
 * شاشةُ الصندوق تنسب البندَ للموسم المختار إن كان من أصنافه — ولا تخمّن من «الكل».
 *
 * الدالّةُ مُصدَّرةٌ من الصفحة وتُقاس وحدَها، ويُحرَس نصًّا أنّ موضعَي
 * الإضافة إلى السلّة كليهما يمرّان بها — فلا يُضاف بندٌ من بابٍ بلا موسم.
 */
describe('شاشةُ الصندوق وموسمُ البند', () => {
    it('الموسمُ المختارُ يُنسب لصنفٍ من أصنافه فقط، و«الكل» لا يُنسب', async () => {
        const { seasonOfLine } = await import('@/Pages/Pos/Index');
        const ids = new Set([7, 8]);
        expect(seasonOfLine(3, ids, 7)).toBe(3);
        expect(seasonOfLine(3, ids, 9)).toBeNull();
        expect(seasonOfLine(null, ids, 7)).toBeNull();
    });

    it('وموضعا الإضافة إلى السلّة كلاهما يحملان موسمَ البند', async () => {
        const fs = await import('node:fs');
        const path = await import('node:path');
        const src = fs.readFileSync(path.resolve(process.cwd(), 'resources/js/Pages/Pos/Index.tsx'), 'utf8');
        const adds = src.match(/cart\.add\(\{[\s\S]*?\}\);/g) ?? [];
        const productAdds = adds.filter((a) => a.includes('id: p.id'));
        expect(productAdds).toHaveLength(2);
        productAdds.forEach((a) => expect(a).toContain('season_id: seasonFor(p.id)'));
    });
});

/**
 * صفُّ الموسم في تقرير «أداء المواسم» — أرقامُه كما وصلت، واسمُه يفتح صفحته.
 */
describe('صفُّ الموسم في التقرير', () => {
    it('يكتب الأرقامَ الستّة ويفتح الموسمَ بعينه', async () => {
        const { SeasonReportLine } = await import('@/Pages/Admin/Reports/Seasons');
        const m = (v: number) => `${v.toFixed(3)} ر.ع`;
        render(
            <table>
                <tbody>
                    <SeasonReportLine
                        locale="ar"
                        money={m}
                        row={{
                            id: 3, name: 'رمضان', starts_at: '2027-01-20', ends_at: '2027-02-20', dates: '2027-01-20 — 2027-02-20',
                            status: 'active', statusLabel: 'نشط',
                            sales: 30, cogs: 12, gross_profit: 18, margin: 60, orders: 2, units: 3,
                        }}
                    />
                </tbody>
            </table>,
        );

        expect(screen.getByRole('link', { name: 'رمضان' })).toHaveAttribute('href', '/admin.seasons.show/3');
        expect(screen.getByText('نشط')).toBeInTheDocument();
        const cells = screen.getAllByRole('cell').map((c) => c.textContent);
        expect(cells.slice(2)).toEqual(['30.000 ر.ع', '12.000 ر.ع', '18.000 ر.ع', '60.0%', '2', '3']);
    });

    it('والخسارةُ تُلوَّن حمراء', async () => {
        const { SeasonReportLine } = await import('@/Pages/Admin/Reports/Seasons');
        render(
            <table>
                <tbody>
                    <SeasonReportLine
                        locale="ar"
                        money={(v) => String(v)}
                        row={{ id: 3, name: 'خاسر', starts_at: '2027-01-20', ends_at: '2027-02-20', dates: '', status: 'ended', statusLabel: 'منتهي', sales: 10, cogs: 14, gross_profit: -4, margin: -40, orders: 1, units: 1 }}
                    />
                </tbody>
            </table>,
        );

        expect(screen.getByText('-4').className).toContain('text-[#b91c1c]');
    });
});
