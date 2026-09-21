import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import Topbar from '@/Components/Topbar';
import { pageProps } from './setup';

/**
 * الشريطُ العلويّ يرسم زرَّ الموقع وزرَّ الصندوق لمن مُنح قسمَهما وحده.
 *
 * كان الكاشيرُ — بلا «الموقع» في صلاحيّاته — يرى الكرةَ الأرضيّة وتفتح له
 * موقعَ المتجر أو شاشةَ ضبطه. الإخفاءُ عرضٌ لقرارٍ يقيسه الخادم؛ ما يُقاس
 * هنا أنّ الزرَّ يتبع `auth.abilities` لا الدور.
 */
describe('الشريطُ العلويّ وما يُمنح', () => {
    const topbar = (abilities: string[], website: string | null = 'https://flowers.abaadapp.om') => {
        Object.assign(pageProps, {
            auth: {
                abilities,
                mayActions: [],
                isEmployee: true,
                user: { name: 'كاشير', avatar: null, roleLabel: 'كاشير', role: 'cashier', businessId: 1 },
            },
            context: { website, branches: [], branchId: null, branchName: '', currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 }, currencies: [] },
            notifications: null,
            reportPages: [],
            locale: 'ar',
            csrf: 'x',
            flash: {},
        });

        const base = globalThis.route as unknown as (...a: unknown[]) => string;
        globalThis.route = ((...a: unknown[]) => (a.length ? base(...a) : { current: () => 'admin.products.index' })) as never;

        return render(<Topbar onMenuClick={() => {}} />);
    };

    it('من لا يملك «الموقع» لا يرى زرَّه — ولو كان للمتجر موقع', () => {
        topbar(['pos', 'products']);
        expect(screen.queryByTitle('الموقع الإلكتروني')).toBeNull();
        expect(screen.queryByTitle('أضف الموقع الإلكتروني')).toBeNull();
    });

    it('ومن يملكه يرى موقعَ المتجر', () => {
        topbar(['website']);
        expect(screen.getByTitle('الموقع الإلكتروني')).toHaveAttribute('href', 'https://flowers.abaadapp.om');
    });

    it('ومن يملكه ولا موقعَ بعد يُدلّ على ضبطه', () => {
        topbar(['website'], null);
        expect(screen.getByTitle('أضف الموقع الإلكتروني')).toBeInTheDocument();
    });

    it('وزرُّ الصندوق لمن يملك «نقطة البيع» وحده', () => {
        topbar(['products']);
        expect(screen.queryByTitle('نقطة البيع')).toBeNull();

        topbar(['pos']);
        expect(screen.getByTitle('نقطة البيع')).toBeInTheDocument();
    });
});
