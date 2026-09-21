import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import PosLayout from '@/Layouts/PosLayout';
import { pageProps } from './setup';

/**
 * لوحةُ التجهيز بابُها من شريط الصندوق — لمن يملك قسمَها، ولا تُرسم لغيره.
 *
 * الإخفاءُ عرضٌ لقرارٍ يقيسه الخادم (`ability` على المسار)؛ ما يُقاس هنا
 * أنّ الرابطَ موجودٌ بجانب «العملاء» ويقصد صفحةَ اللوحة نفسَها.
 */
describe('شريطُ الصندوق ولوحةُ التجهيز', () => {
    const layout = (abilities: string[]) => {
        Object.assign(pageProps, {
            auth: { abilities, mayActions: [], user: { name: 'كاشير', avatar: null, roleLabel: 'كاشير' } },
            context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 }, currencies: [] },
            locale: 'ar',
            csrf: 'x',
            flash: {},
        });

        // `route().current()` يقرؤها الشريط ليعلّم الرابطَ النشط — وبديلُ setup لا يحملها
        const base = globalThis.route as unknown as (...a: unknown[]) => string;
        const withCurrent = ((...a: unknown[]) => (a.length ? base(...a) : { current: () => 'pos.index' })) as never;
        globalThis.route = withCurrent;

        return render(<PosLayout title="اختبار"><div /></PosLayout>);
    };

    it('من يملك «التجهيز» يرى الرابطَ بجانب العملاء', () => {
        layout(['pos', 'preparation']);
        const link = screen.getByRole('link', { name: /لوحة التجهيز/ });
        expect(link).toHaveAttribute('href', '/admin.preparation.index');

        const labels = screen.getAllByRole('link').map((l) => l.textContent?.trim());
        expect(labels.indexOf('لوحة التجهيز')).toBe(labels.indexOf('العملاء') + 1);
    });

    it('ومن لا يملكه لا يراه', () => {
        layout(['pos']);
        expect(screen.queryByRole('link', { name: /لوحة التجهيز/ })).toBeNull();
        expect(screen.getByRole('link', { name: /العملاء/ })).toBeInTheDocument();
    });
});
