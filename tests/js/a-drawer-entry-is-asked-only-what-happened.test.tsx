import { useState } from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { pageProps } from './setup';

/**
 * قيدٌ مبسّط من الدرج — يُسأل الموظّف عمّا حدث، لا عن جهةٍ ولا تاريخ.
 *
 * ═══ ما يُحرس ═══
 *
 * ١) الزرُّ يتبع ما قاله الخادم: `movement` فارغٌ ⇒ لا زرّ.
 * ٢) نقطةُ البيع لا تسأل «من أين خرج المال؟» ولا عن التاريخ، وتقول إنّه
 *    من الدرج — وتُرسل إلى بابها لا إلى باب المالية.
 * ٣) والمالية كما كانت: الجهةُ والتاريخُ يُسألان، والبابُ بابُها.
 */

const sent: string[] = [];
// النوعُ يُختار من قائمةٍ لا يحرّكها jsdom — فيُبدأ به النموذجُ هنا
let preset: Record<string, unknown> = {};

vi.mock('@inertiajs/react', async () => {
    const actual = await vi.importActual<Record<string, unknown>>('@inertiajs/react');

    const useForm = (initial: Record<string, unknown>) => {
        const [data, setData] = useState({ ...initial, ...preset });

        return {
            data,
            errors: {} as Record<string, string>,
            processing: false,
            setData: (key: string, value: unknown) => setData((d) => ({ ...d, [key]: value })),
            post: (url: string) => sent.push(url),
            reset: () => {},
            clearErrors: () => {},
        };
    };

    return {
        ...actual,
        usePage: () => ({ props: pageProps, url: '/', component: 'Test' }),
        Head: () => null,
        useForm,
    };
});

vi.mock('@/Layouts/PosLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <div>{children}</div> }));
vi.mock('@/hooks/useLiveStock', () => ({ default: (_url: string, p: unknown[]) => ({ products: p, updatedAt: null }) }));

const MovementForm = (await import('@/Components/MovementForm')).default;
const { default: PosIndex } = await import('@/Pages/Pos/Index');

const kinds = [
    { value: 'expense', label: 'مصروف', hint: 'مالٌ خرج', asks: 'من أين خرج المال؟', direction: 'مصروف' },
    { value: 'cash_to_bank', label: 'تحويل من الصندوق إلى البنك', hint: 'ينتقل', asks: null, direction: 'تحويل' },
];

const till = (movement: unknown) => {
    Object.assign(pageProps, {
        products: [],
        categories: [{ value: 'الكل', label: 'الكل' }],
        customers: [],
        addons: [],
        coupons: [],
        resumeCart: null,
        settings: { redeemMaxPct: 50, earnRate: 1, redeemMin: 100 },
        orderOptions: undefined,
        customOrder: { templates: [] },
        movement,
        context: { currency: { code: 'OMR', name: 'ريال', symbol: 'ر.ع', rate: 1, is_base: true, active: true, decimals: 3 } },
    });
    localStorage.clear();

    return render(<PosIndex />);
};

beforeEach(() => {
    sent.length = 0;
    preset = {};
});

describe('زرُّ القيد في الصندوق', () => {
    it('يغيب حين لا يملك الواقفُ الفعل', () => {
        till(null);
        expect(screen.queryByTestId('pos-movement')).toBeNull();
    });

    it('ويظهر لمن يملكه، ويفتح «ماذا حدث؟»', async () => {
        till({ kinds, expenseTypes: ['كهرباء'] });

        await userEvent.setup().click(screen.getByTestId('pos-movement'));

        expect(screen.getByRole('dialog')).toHaveTextContent('قيد مبسّط — ماذا حدث؟');
        expect(screen.getByRole('dialog')).toHaveTextContent('نوع الحركة');
    });
});

describe('النموذجُ في بابيه', () => {
    const draw = (pos: boolean) =>
        render(
            <MovementForm pos={pos} movements={kinds} expenseTypes={['كهرباء']} today="2027-03-17" onCancel={() => {}} onSuccess={() => {}} />,
        );

    it('في الصندوق: الدرجُ جهتُه بلا سؤال، ولا تاريخ، وبابُه بابُ الصندوق', async () => {
        preset = { kind: 'expense', amount: '5' };
        draw(true);

        expect(screen.getByTestId('movement-drawer')).toHaveTextContent('من درج الصندوق');
        expect(screen.queryByText('من أين خرج المال؟')).toBeNull();
        expect(screen.queryByText('التاريخ')).toBeNull();

        await userEvent.setup().click(screen.getByRole('button', { name: 'حفظ' }));
        expect(sent).toEqual(['/pos.movements.store']);
    });

    it('والتحويلُ في الصندوق لا يُقال إنّه «من الدرج» وحده — النوعُ يحدّد جهتيه', () => {
        preset = { kind: 'cash_to_bank' };
        draw(true);

        expect(screen.queryByTestId('movement-drawer')).toBeNull();
        expect(screen.getByText(/المال ينتقل بين الصندوق والبنك/)).toBeInTheDocument();
    });

    it('وفي المالية كما كانت: الجهةُ والتاريخُ يُسألان، وبابُها بابُها', async () => {
        preset = { kind: 'expense', amount: '5' };
        draw(false);

        expect(screen.getByText('من أين خرج المال؟')).toBeInTheDocument();
        expect(screen.getByText('التاريخ')).toBeInTheDocument();
        expect(screen.queryByTestId('movement-drawer')).toBeNull();

        await userEvent.setup().click(screen.getByRole('button', { name: 'حفظ' }));
        expect(sent).toEqual(['/admin.finance.store']);
    });
});
