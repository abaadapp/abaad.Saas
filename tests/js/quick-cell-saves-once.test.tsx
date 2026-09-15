import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { router } from '@inertiajs/react';
import QuickCell from '@/Pages/Admin/Products/partials/QuickCell';
import { enterEndsTypingOnTouch } from '@/lib/enter-key';

/**
 * خليةٌ تُحفظ بـEnter وبمغادرة الحقل — فهل تُحفظ مرّتين؟
 *
 * صارت Enter على الأجهزة اللمسية تُنهي التركيز لتُغلق اللوحة. وهذه الخليّة
 * تحفظ في الحالين: `onKeyDown` و`onBlur`. فلو وصلت الضغطةُ إلى الاثنين
 * لَأُرسل طلبان — وسطران في سجلّ النشاط عن تعديلٍ واحد، وربّما تسابقٌ على
 * الكميّة نفسها.
 *
 * والجوابُ لا يُستنتج من ترتيب React: يُقاس.
 */
describe('الخليّة السريعة', () => {
    it('تُحفظ مرّةً واحدة حين تُغلق Enter اللوحةَ', async () => {
        window.matchMedia = vi.fn().mockReturnValue({ matches: true }) as never;
        enterEndsTypingOnTouch();

        const user = userEvent.setup();

        render(<QuickCell id={7} field="quantity" value={3} display="٣" />);

        await user.click(screen.getByRole('button'));

        const input = screen.getByRole('spinbutton');
        await user.clear(input);
        await user.type(input, '9');
        await user.keyboard('{Enter}');

        expect(router.patch).toHaveBeenCalledTimes(1);
        expect(router.patch).toHaveBeenCalledWith(
            expect.stringContaining('admin.products.quick'),
            { quantity: 9 },
            expect.anything(),
        );
    });

    it('وتُفتح من جديد فتُحفظ من جديد', async () => {
        window.matchMedia = vi.fn().mockReturnValue({ matches: true }) as never;
        enterEndsTypingOnTouch();

        const user = userEvent.setup();

        render(<QuickCell id={7} field="quantity" value={3} display="٣" />);

        for (const next of ['9', '4']) {
            await user.click(screen.getByRole('button'));

            const input = screen.getByRole('spinbutton');
            await user.clear(input);
            await user.type(input, next);
            await user.keyboard('{Enter}');
        }

        // مرّةٌ لكلّ جلسة تحرير — لا قفلٌ يبقى مغلقًا فتموت الخليّة بعد أوّل حفظ
        expect(router.patch).toHaveBeenCalledTimes(2);
        expect(router.patch).toHaveBeenLastCalledWith(
            expect.anything(),
            { quantity: 4 },
            expect.anything(),
        );
    });
});
