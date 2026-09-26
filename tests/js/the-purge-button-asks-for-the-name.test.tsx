import { fireEvent, render, screen } from '@testing-library/react';
import { router } from '@inertiajs/react';
import { describe, expect, it, vi } from 'vitest';

import { DangerZone } from '@/Pages/Platform/Businesses/Show';
import { pageProps } from './setup';

/**
 * زرُّ الحذف النهائيّ لا يُفتح إلّا بعد أن يُكتب الاسم.
 *
 * ═══ وما يُحرَس ═══
 *
 * أنّ الزرَّ مقفلٌ حتّى يُطابق المكتوبُ الاسمَ، وأنّ اسمًا مقاربًا لا يفتحه،
 * وأنّ النداء يقصد بابَ المحو (`purge`) لا بابَ التعطيل (`destroy`) — وهما
 * مساران متجاوران، وخطأٌ في الاسم هنا يمحو ما كان يُراد تعطيلُه.
 *
 * والخادمُ يقارن الاسمَ ثانيةً (`BusinessController::purge`) — فهذا الحقلُ
 * راحةٌ لمن يضغط لا حراسةٌ للبيانات، وله حرّاسه في PHP.
 */
describe('منطقةُ الخطر', () => {
    const open = () => {
        Object.assign(pageProps, { translations: {} });
        render(<DangerZone name="متجر الورد" id={7} />);
        fireEvent.click(screen.getByRole('button', { name: /حذف الشركة نهائيًا/ }));
    };

    const confirmButton = () => screen.getByRole('button', { name: /احذف نهائيًا/ });

    it('لا تُفتح إلّا بضغطة — والنافذةُ تقول اسم الشركة', () => {
        open();

        expect(screen.getAllByText(/متجر الورد/).length).toBeGreaterThan(0);
        expect(screen.getByText(/لا يمكن التراجع/)).toBeInTheDocument();
        expect(screen.getByLabelText('اسم الشركة للتأكيد')).toBeInTheDocument();
    });

    it('والزرُّ مقفلٌ ما دام الاسمُ لم يُكتب', () => {
        open();
        expect(confirmButton()).toBeDisabled();
    });

    it('واسمٌ مقاربٌ لا يفتحه', () => {
        open();
        fireEvent.change(screen.getByLabelText('اسم الشركة للتأكيد'), { target: { value: 'متجر الور' } });
        expect(confirmButton()).toBeDisabled();
    });

    it('والاسمُ الكاملُ يفتحه، ومسافاتُ النسخ تُغتفر', () => {
        open();
        fireEvent.change(screen.getByLabelText('اسم الشركة للتأكيد'), { target: { value: '  متجر الورد ' } });
        expect(confirmButton()).toBeEnabled();
    });

    it('والنداءُ يقصد بابَ المحو لا بابَ التعطيل', () => {
        const del = vi.mocked(router.delete);
        del.mockClear();

        open();
        fireEvent.change(screen.getByLabelText('اسم الشركة للتأكيد'), { target: { value: 'متجر الورد' } });
        fireEvent.click(confirmButton());

        expect(del).toHaveBeenCalledTimes(1);
        const [url, opts] = del.mock.calls[0] as [string, { data?: { confirm?: string } }];

        expect(url).toContain('super-admin.businesses.purge');
        expect(url).not.toContain('destroy');
        expect(opts?.data?.confirm).toBe('متجر الورد');
    });
});
