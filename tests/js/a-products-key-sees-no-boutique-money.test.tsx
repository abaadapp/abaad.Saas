import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { pageProps } from './setup';

vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

const { default: BoutiqueShow } = await import('@/Pages/Admin/Boutiques/Show');
const { default: BoutiquesIndex } = await import('@/Pages/Admin/Boutiques/Index');

/**
 * من لا يقرأ مالَ البوتيك يرى أصنافَه ولا رقمَ فيها — والخادمُ لا يُرسل له رقمًا أصلًا.
 *
 * ومن يقرأ ولا يُصدر لا يرى زرَّ الإصدار، ومن لا يسدّد لا يرى زرَّ السداد: ثلاثةُ
 * أسئلة لا واحد (`BoutiqueAccess`). والشاشةُ صورةٌ للخادم لا بديلٌ عنه.
 */
const context = { currency: { code: 'OMR', name: 'ريال', symbol: 'ر.ع', rate: 1, is_base: true, active: true } };
const statement = {
    partial: false, period: '2027-02', from: '2027-02-01', to: '2027-02-28', lines: [], quantity: 1,
    gross: 50, commission: 10, net: 40, lines_count: 1,
    components: { lines: [], due: 7, credit: 0, net: 7, count: 1 }, payable: 47,
};
const ledger = { owed_unsettled: 7, owed_issued: 0, owed: 7, standalone_unpaid: 0, credit: 5, movements: [] };
const paper = {
    id: 1, number: 'BQ-2027-02-1', kind: 'settlement', net: 40, gross: 50, commission: 10, issued_at: '2027-02-10 10:00',
    paid: false, expense_reference: 'BQ-2027-02-1', components_due: 7, components_net: 7, components_paid: false, standalone_paid: true,
};

const show = (props: Record<string, unknown>) => {
    Object.assign(pageProps, {
        translations: {}, context,
        boutique: { id: 3, name: 'ورد الخوير', name_en: null, phone: null, contact_person: null, rate: 20, active: true, notes: null, returns_terms: null, returns_terms_ref: null },
        period: '2027-02', periods: [{ value: '2027-02', label: 'فبراير 2027' }],
        settlement: paper, history: [], products: [{ id: 9, name: 'قطعة', sku: null, active: true, quantity: 4, stock: null }], catalog: { rows: [], more: false }, q: '',
        statement, ledger, returns: null, canSeeMoney: true, canIssue: true, canPay: true,
        ...props,
    });

    return render(<BoutiqueShow />);
};

beforeEach(() => {
    for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
});

describe('شاشةُ البوتيك لمن لا يقرأ المال', () => {
    it('لا كشفَ ولا ورقةَ ولا رصيدَ ولا نسبة — والأصنافُ كما كانت', () => {
        // ما يُرسله الخادمُ لمن لا يقرأ: أصفارٌ وفراغ
        show({ canSeeMoney: false, canIssue: false, canPay: false, statement: null, settlement: null, ledger: null, returns: null });
        expect(screen.queryByText(/إجمالي المبيعات/)).toBeNull();
        expect(screen.queryByText(/المستحق للبوتيك/)).toBeNull();
        expect(screen.queryByText(/BQ-2027-02-1/)).toBeNull();
        expect(screen.queryByTestId('boutique-balance')).toBeNull();
        expect(screen.queryByRole('button', { name: /أصدر التسوية/ })).toBeNull();
        expect(screen.queryByText(/20%/)).toBeNull();
        expect(screen.getByText('قطعة')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /إضافة منتجات/ })).toBeEnabled();
    });
});

describe('القراءةُ ليست إصدارًا والإصدارُ ليس سدادًا', () => {
    it('من يقرأ ولا يُصدر ولا يسدّد لا يرى الزرّين — ويرى الأرقام', () => {
        show({ canIssue: false, canPay: false });
        expect(screen.getByText(/BQ-2027-02-1/)).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /أصدر التسوية/ })).toBeNull();
        expect(screen.queryByTestId('boutique-pay-components')).toBeNull();
        expect(screen.queryByTestId('boutique-refund')).toBeNull();
        expect(screen.getByTestId('boutique-balance')).toBeInTheDocument();
    });

    it('من يسدّد يراهما', () => {
        show({ canIssue: false, canPay: true });
        expect(screen.getByTestId('boutique-pay-components')).toBeInTheDocument();
        expect(screen.getByTestId('boutique-refund')).toBeInTheDocument();
    });
});

describe('قائمةُ البوتيكات لمن لا يقرأ المال', () => {
    const draw = (over: Record<string, unknown>) => {
        Object.assign(pageProps, {
            translations: {}, context, period: '2027-02', periods: [{ value: '2027-02', label: 'فبراير 2027' }],
            boutiques: [{ id: 3, name: 'ورد الخوير', name_en: null, phone: null, contact_person: null, active: true, products_count: 4, ...over }],
            canSeeMoney: false,
        });

        return render(<BoutiquesIndex />);
    };

    it('لا نسبةَ ولا مبيعاتِ شهرٍ ولا «بوتيك جديد» ولا «تعديل»', () => {
        draw({});
        expect(screen.queryByText('نسبة المتجر')).toBeNull();
        expect(screen.queryByText('مبيعات الشهر')).toBeNull();
        expect(screen.queryByRole('button', { name: /بوتيك جديد/ })).toBeNull();
        expect(screen.queryByRole('button', { name: /تعديل/ })).toBeNull();
        expect(screen.getByText('ورد الخوير')).toBeInTheDocument();
    });

    it('ومن يقرأ يراها', () => {
        Object.assign(pageProps, {
            translations: {}, context, period: '2027-02', periods: [{ value: '2027-02', label: 'فبراير 2027' }], canSeeMoney: true,
            boutiques: [{ id: 3, name: 'ورد الخوير', name_en: null, phone: null, contact_person: null, active: true, products_count: 4, rate: 20, gross: 150, pending: 0, settled: false }],
        });
        render(<BoutiquesIndex />);
        expect(screen.getByRole('button', { name: /بوتيك جديد/ })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /تعديل/ })).toBeInTheDocument();
    });
});
