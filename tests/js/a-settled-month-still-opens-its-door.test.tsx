import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { pageProps } from './setup';

vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

const { default: BoutiqueShow } = await import('@/Pages/Admin/Boutiques/Show');

/**
 * شهرٌ صدرت فيه ورقةٌ يبقى بابُه مفتوحًا لما بِيع بعدها.
 *
 * ═══ ولمَ حارسٌ للزرّ نفسِه ═══
 *
 * كان يختفي متى وُجدت ورقةٌ لهذا الشهر، وكان صحيحًا يومَ كانت الورقةُ
 * تأخذ الشهرَ كلَّه. وصارت تأخذ ما لم يُؤخَذ — فاختفاؤه يعني أنّ ما بِيع
 * بعدها لا يجد بابًا يُصدَر منه: يبقى دَينُ البوتيك في الشاشة ولا ورقةَ
 * تحمله، وهو العطبُ نفسُه الذي فُتح الشهرُ الجاري لأجله.
 */

const statement = (over: Record<string, unknown> = {}) => ({
    partial: false, period: '2027-02', from: '2027-02-01', to: '2027-02-28',
    lines: [], quantity: 1, gross: 50, commission: 10, net: 40, lines_count: 1,
    ...over,
});

const paper = {
    id: 1, number: 'BQ-2027-02-1', net: 40, gross: 50, commission: 10,
    issued_at: '2027-02-10 10:00', paid: false, expense_reference: 'BQ-2027-02-1',
};

const draw = (props: Record<string, unknown>) => {
    Object.assign(pageProps, {
        translations: {},
        context: { currency: { code: 'OMR', name: 'ريال', symbol: 'ر.ع', rate: 1, is_base: true, active: true } },
        boutique: {
            id: 3, name: 'ورد الخوير', name_en: null, phone: null,
            contact_person: null, rate: 20, active: true, notes: null,
        },
        period: '2027-02',
        periods: [{ value: '2027-02', label: 'فبراير 2027' }],
        settlement: null,
        history: [],
        ...props,
    });

    return render(<BoutiqueShow />);
};

const issueButton = () => screen.queryByRole('button', { name: /أصدر التسوية/ });

describe('بابُ التسوية في شهرٍ صدرت فيه ورقة', () => {
    beforeEach(() => {
        for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    });

    it('يبقى مفتوحًا لما بِيع بعد الورقة', () => {
        draw({ statement: statement(), settlement: paper });

        expect(screen.getByText(/BQ-2027-02-1/)).toBeInTheDocument();
        expect(issueButton()).toBeEnabled();
    });

    it('والشهرُ الجاري يُصدَر — ويُقال إنّ بعده بقيّة', () => {
        draw({ statement: statement({ partial: true }) });

        expect(issueButton()).toBeEnabled();
        expect(screen.getByText(/ما زال يبيع/)).toBeInTheDocument();
    });

    it('ولا يُصدَر ما لا بيعَ فيه', () => {
        draw({ statement: statement({ lines_count: 0, gross: 0, net: 0, commission: 0 }) });

        expect(issueButton()).toBeDisabled();
    });

    it('وشهرٌ حملت ورقتُه كلَّ ما فيه لا يُعرض له زرّ', () => {
        draw({ statement: statement({ lines_count: 0, gross: 0, net: 0, commission: 0 }), settlement: paper });

        expect(issueButton()).toBeNull();
        expect(screen.getByText(/BQ-2027-02-1/)).toBeInTheDocument();
    });
});
