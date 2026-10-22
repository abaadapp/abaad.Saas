import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { Table, TableBody } from '@/Components/ui/table';
import { AddonRows, IncludedAddons, lineAmount, type LineWithAddons } from '@/Pages/Admin/Orders/ItemAddons';
import { pageProps } from './setup';

/**
 * إضافاتُ البند تحته في صفحة الطلب — بقاعدة الورق (`ItemAddons`).
 *
 * ═══ ما يُحرس ═══
 *
 *   - المجبيّةُ فوق الثمن صفوفٌ بكميّتها وثمنها ومجموعها، ومبلغُ البند ثمنُه
 *     وحده — فيجمع العمودُ إلى ما دُفع ولا يُكتب المالُ مرّتين.
 *   - لقطاتٌ لا تطابق ما جُبي: صفٌّ واحدٌ بما جُبي. وما داخل الثمن بلا مال.
 *   - إضافةٌ من رفّ بوتيك: الموظّفُ يرى صنفَها ورفَّها — بلا تكلفة.
 *
 * والحسبةُ نفسُها في الخادم (`Demo::orderDetails`)، وتحرسها
 * `AnOrderNamesEachItemsAddonsUnderItTest`.
 */

const m = (v: number) => `${v.toFixed(3)} ر.ع`;

const rose: LineWithAddons = {
    total: 27, own_total: 20, addons_mode: 'priced', addons_total: 7,
    addons: [
        { name: 'شوكولاتة', qty: 2, unit: 3, total: 6 },
        { name: 'فضّة', qty: 1, unit: 1, total: 1, from: { product: 'قطعة فضّة', boutique: 'بوتيك لمى' } },
    ],
};

const inTable = (node: React.ReactNode) => render(<Table><TableBody>{node}</TableBody></Table>);

describe('إضافاتُ البند في صفحة الطلب', () => {
    it('المجبيّةُ صفوفٌ بمالها، ومبلغُ البند ثمنُه وحده — ٢٠ + ٦ + ١ = ٢٧', () => {
        Object.assign(pageProps, { context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 } } });
        inTable(<AddonRows line={rose} money={m} extraCell={false} />);

        const rows = screen.getAllByTestId('addon-row');
        expect(rows).toHaveLength(2);
        expect(rows[0].textContent).toContain('شوكولاتة');
        expect(rows[0].textContent).toContain('3.000');
        expect(rows[0].textContent).toContain('6.000');
        expect(lineAmount(rose)).toBe(20);
        expect(lineAmount(rose) + rose.addons!.reduce((s, a) => s + a.total, 0)).toBe(rose.total);
    });

    it('ورفُّ البوتيك يُسمّى للموظّف — بلا تكلفة', () => {
        inTable(<AddonRows line={rose} money={m} extraCell />);
        expect(screen.getByTestId('addon-from').textContent).toContain('بوتيك لمى');
        expect(screen.getByTestId('addon-from').textContent).toContain('قطعة فضّة');
        // وخانةُ التصحيح الفارغة تُبقي الأعمدة في صفّها
        expect(screen.getAllByTestId('addon-row')[0].querySelectorAll('td')).toHaveLength(5);
    });

    it('لقطاتٌ لا تطابق ما جُبي: صفٌّ واحدٌ بما جُبي', () => {
        const old: LineWithAddons = {
            total: 25, own_total: 20, addons_mode: 'bundled', addons_total: 5,
            addons: [{ name: 'شوكولاتة', qty: 1, total: 3 }, { name: 'بطاقة', qty: 2, total: 2 }],
        };
        inTable(<AddonRows line={old} money={m} extraCell={false} />);
        expect(screen.getAllByTestId('addon-row')).toHaveLength(1);
        expect(screen.getByTestId('addon-row').textContent).toContain('بطاقة × 2');
        expect(screen.getByTestId('addon-row').textContent).toContain('5.000');
        expect(lineAmount(old)).toBe(20);
    });

    it('وما داخل الثمن يُسمّى بلا مال، ومبلغُ البند كما كان', () => {
        const budget: LineWithAddons = { total: 35, own_total: 35, addons_mode: 'included', addons_total: 0, addons: [{ name: 'ورد أحمر', qty: 3, total: 15 }] };
        render(<IncludedAddons line={budget} />);
        expect(screen.getByTestId('addon-included').textContent).toContain('ورد أحمر');
        expect(screen.getByTestId('addon-included').textContent).toContain('ضمن السعر');
        expect(screen.getByTestId('addon-included').textContent).not.toContain('15');
        expect(lineAmount(budget)).toBe(35);

        inTable(<AddonRows line={budget} money={m} extraCell={false} />);
        expect(screen.queryByTestId('addon-row')).toBeNull();
    });

    it('وطلبٌ قديمٌ بلا طريقة: مبلغُه كما كان', () => {
        expect(lineAmount({ total: 14 })).toBe(14);
    });
});
