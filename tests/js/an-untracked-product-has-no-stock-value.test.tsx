import { render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import InventoryIndex from '@/Pages/Admin/Inventory/Index';
import { pageProps } from './setup';

vi.mock('@/Layouts/AdminLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <div>{children}</div> }));
vi.mock('@/hooks/useLiveStock', () => ({ default: (_url: string, p: unknown[]) => ({ products: p, updatedAt: null }) }));

/**
 * صنفٌ لا رفَّ له لا قيمةَ مخزونٍ له — في صفّه على الشاشة.
 *
 * الشاشةُ تُعيد حسابَ القيمة من الكميّة (`cost × qty`) لتتبع العدَّ الحيّ، فكان
 * صنفٌ أُوقف تتبّعُه وبقيت له سبعٌ قديمةٌ يُقرأ «١٤ ر.ع» في عمود «قيمة
 * المخزون» والترويسةُ لا تعدّه. والخادمُ يقول `tracked` (`Demo::inventory`)،
 * وما يحسبه: `AnUntrackedProductIsNeverCountedAsStockTest`.
 */

const item = (over: Record<string, unknown>) => ({
    id: 1, name: 'ورد أحمر', label: 'ورد أحمر', sku: 'R-1', qty: 3, min: 10, status: 'منخفض',
    cost: 1.5, value: 4.5, tracked: true, updated: '2026-10-23', branches: [], ...over,
});

describe('قيمةُ المخزون في صفّها', () => {
    it('المتتبَّعُ بقيمته، وما أُوقف تتبّعُه وأمانةُ البوتيك بكميّتهما وقيمةٍ صفر', () => {
        Object.assign(pageProps, {
            translations: {},
            inventory: [
                item({}),
                item({ id: 2, name: 'تغليف', label: 'تغليف', sku: 'W-1', qty: 7, cost: 2, value: 0, tracked: false, status: 'غير مرتبط بالمخزون' }),
                item({ id: 3, name: 'عطر', label: 'عطر', sku: 'P-1', qty: 10, min: 2, cost: 5, value: 0, consigned: true, status: 'متوفر' }),
            ],
            pagination: { current_page: 1, last_page: 1, per_page: 25, total: 2, from: 1, to: 2 },
            filters: {}, sorts: [], summary: { count: 2, value: 4.5 }, branches: [], currentBranchId: null,
            context: { currency: { code: 'OMR', name: 'ريال', symbol: 'ر.ع', rate: 1, is_base: true, active: true, decimals: 3 } },
        });

        render(<InventoryIndex />);

        const wrap = screen.getAllByText('تغليف').map((el) => el.closest('tr')).find(Boolean)!;
        expect(within(wrap).getByText('7')).toBeInTheDocument();
        expect(wrap.textContent).not.toContain('14.000');
        expect(wrap.textContent).toContain('0.000');

        // وأمانةُ البوتيك على الرفّ بعشرها، ولا قيمةَ لها في مال المحلّ
        const perfume = screen.getAllByText('عطر').map((el) => el.closest('tr')).find(Boolean)!;
        expect(within(perfume).getByText('10')).toBeInTheDocument();
        expect(perfume.textContent).not.toContain('50.000');

        const rose = screen.getAllByText('ورد أحمر').map((el) => el.closest('tr')).find(Boolean)!;
        expect(rose.textContent).toContain('4.500');
    });
});
