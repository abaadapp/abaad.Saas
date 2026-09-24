import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { pageProps } from './setup';

vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

import type { PublishState } from '@/Pages/Admin/Website/theme/PublishBar';

const { default: PublishBar } = await import('@/Pages/Admin/Website/theme/PublishBar');

/**
 * شريطُ النشر يقول حالَ المتجر — لا يزيّنها.
 *
 * ═══ وما يُحرَس ═══
 *
 * أنّ الشاشة تقول ما يقع: «تغييراتٌ غير منشورة» حين تكون، و«الموقع محدّث»
 * حين لا تكون — ولا زرَّ نشرٍ يُضغط على لا شيء. وأنّ ما تغيّر يُسمّى لا
 * يُعدّ: «٣ تغييرات» تُقلق ولا تُفيد.
 *
 * وأنّ سجلَّ النشرات يقول ما **لا** تُعيده الاستعادة — فمن استعاد تصميمًا
 * قديمًا قد يظنّ أنّه استعاد معه رسمَ توصيلٍ قديمًا، وذلك مالٌ يُحصَّل من
 * زبونه.
 */

const state = (over: Partial<PublishState> = {}): PublishState => ({
    changed: 0,
    fields: [],
    revision: 3,
    published_at: '2026-09-25 10:00',
    versions: [],
    ...over,
});

const draw = (s: PublishState) => {
    for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    Object.assign(pageProps, { translations: {} });

    return render(<PublishBar state={s} />);
};

describe('شريط النشر', () => {
    it('يقول «الموقع محدّث» حين لا شيءَ ينتظر — ولا يُتاح النشر', () => {
        draw(state());

        expect(screen.getByTestId('up-to-date')).toBeTruthy();
        expect(screen.queryByTestId('unpublished')).toBeNull();
        expect(screen.getByTestId('publish').hasAttribute('disabled')).toBe(true);
    });

    it('ويقول «تغييرات غير منشورة» ويُسمّيها — لا يعدُّها وحدَها', () => {
        draw(state({ changed: 3, fields: ['العنوان الكبير', 'نبذتك', 'سطر التذييل'] }));

        expect(screen.getByTestId('unpublished')).toBeTruthy();
        expect(screen.queryByTestId('up-to-date')).toBeNull();
        expect(screen.getByTestId('publish').hasAttribute('disabled')).toBe(false);

        const bar = screen.getByTestId('publish-bar').textContent ?? '';
        expect(bar).toContain('العنوان الكبير');
        expect(bar).toContain('نبذتك');
        expect(bar).toContain('سطر التذييل');
    });

    it('ولا سجلَّ يُفتح على فراغ', () => {
        draw(state());

        expect(screen.queryByTestId('open-history')).toBeNull();
    });

    it('والسجلُّ يقول من نشر ومتى، ولا يعرض استعادةً للمنشورة الآن', async () => {
        draw(state({
            versions: [
                { id: 9, number: 2, at: '2026-09-25 10:00', by: 'سعود', note: 'عنوانٌ جديد', current: true },
                { id: 8, number: 1, at: '2026-09-24 09:00', by: 'سعود', note: null, current: false },
            ],
        }));

        fireEvent.click(screen.getByTestId('open-history'));
        await waitFor(() => screen.getByTestId('history'));

        const box = screen.getByTestId('history').textContent ?? '';
        expect(box).toContain('سعود');
        expect(box).toContain('2026-09-24 09:00');
        expect(box).toContain('عنوانٌ جديد');

        // المنشورةُ الآن لا تُستعاد — استعادةُ ما هو قائمٌ فعلٌ بلا معنى
        expect(screen.queryByTestId('restore-2')).toBeNull();
        expect(screen.getByTestId('restore-1')).toBeTruthy();
    });

    it('ويقول ما لا تُعيده الاستعادة — الأسعار والمخزون والدفع والتوصيل', async () => {
        draw(state({
            versions: [{ id: 8, number: 1, at: '2026-09-24 09:00', by: null, note: null, current: false }],
        }));

        fireEvent.click(screen.getByTestId('open-history'));
        await waitFor(() => screen.getByTestId('history'));

        const box = screen.getByTestId('history').textContent ?? '';
        expect(box).toContain('أسعارًا');
        expect(box).toContain('مخزونًا');
        expect(box).toContain('توصيل');
    });
});
