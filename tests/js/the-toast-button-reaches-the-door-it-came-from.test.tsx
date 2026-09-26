import { render } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { router } from '@inertiajs/react';
import { pageProps } from './setup';

/**
 * زرُّ الإشعار يعود إلى البابِ الذي جاء منه — لا إلى بابٍ واحدٍ مفروض.
 *
 * كان الشريطُ يُرسل الإقرارَ بـ`delete` دائمًا وبلا حمولة. وهو يكفي الحذفَ
 * المفرد: عنوانُ الصنف في الرابط. أمّا الحذفُ الجماعيّ فيحتاج أن يُقال له على
 * مَن يُعاد — فبقي بلا زرّ: يقرأ التاجر «أعِد الحذف مؤكَّدًا» ولا يجد ما
 * يُعيده به، وسبيلُه الوحيد أن يحذفها واحدًا واحدًا.
 *
 * والحارسُ هنا على الشريط وحدَه: حمولةُ الخادم تحرسها
 * `AStockedProductIsNotDeletedTest`.
 */

/* ما حول الشريط أجسادٌ فارغة — المختبَرُ زرُّ الإشعار لا الشاشةُ حوله */
vi.mock('@/Components/Sidebar', () => ({ default: () => null }));
vi.mock('@/Components/Topbar', () => ({ default: () => null }));
vi.mock('@/Components/ImpersonationBar', () => ({ default: () => null }));
vi.mock('@/Components/SubscriptionBanner', () => ({ default: () => null }));
vi.mock('@/Components/SetupBanner', () => ({ default: () => null }));

/** ما يُمرَّر إلى sonner — الزرُّ لا يُنقر في jsdom، يُقرأ ثمّ يُنادى */
const shown: Array<{ msg: string; action?: { label: string; onClick: () => void } }> = [];

vi.mock('sonner', () => {
    const fn = (msg: string, opts?: { action?: { label: string; onClick: () => void } }) => {
        shown.push({ msg, action: opts?.action });
    };

    return { Toaster: () => null, toast: Object.assign(fn, { success: fn, error: fn, warning: fn }) };
});

async function show(toast: Record<string, unknown>) {
    Object.assign(pageProps, {
        flash: { toast },
        auth: { user: { name: 'المالك' }, abilities: [], mayActions: [] },
    });

    const { default: AdminLayout } = await import('@/Layouts/AdminLayout');

    render(<AdminLayout title="فحص">محتوى</AdminLayout>);
}

beforeEach(() => {
    shown.length = 0;
    vi.mocked(router.visit).mockClear();
});

describe('زرُّ الإقرار في شريط الإشعارات', () => {
    it('يُعيد الفعلَ الجماعيَّ بـpost ومعه من بقي', async () => {
        await show({
            msg: 'حُذف 1، وبقي «وردٌ أحمر» — فيها بضاعة.',
            type: 'warning',
            confirm: {
                url: '/admin.products.bulk',
                method: 'post',
                label: 'احذفها على أيّ حال',
                data: { action: 'delete', ids: [7, 9] },
            },
        });

        const action = shown.at(-1)?.action;
        expect(action?.label).toBe('احذفها على أيّ حال');

        action?.onClick();

        expect(router.visit).toHaveBeenCalledWith('/admin.products.bulk', expect.objectContaining({
            method: 'post',
            data: { ack_stock: true, action: 'delete', ids: [7, 9] },
        }));
    });

    it('ويبقى المفردُ delete بعنوانه وحدَه', async () => {
        /* توافقٌ: الرسائلُ القائمة لا تحمل `method` ولا `data` */
        await show({
            msg: '«وردٌ أحمر» في مخزونك منه 200',
            type: 'warning',
            confirm: { url: '/admin.products.destroy/7', label: 'احذفه على أيّ حال' },
        });

        shown.at(-1)?.action?.onClick();

        expect(router.visit).toHaveBeenCalledWith('/admin.products.destroy/7', expect.objectContaining({
            method: 'delete',
            data: { ack_stock: true },
        }));
    });

    it('ولا يُرسل الإقرارَ مع «تراجع» — الاستعادةُ لا تحتاج إقرارًا', async () => {
        await show({
            msg: 'تم حذف المنتج',
            type: 'success',
            undo: { url: '/admin.products.restore/7' },
        });

        shown.at(-1)?.action?.onClick();

        expect(router.post).toHaveBeenCalledWith('/admin.products.restore/7', {}, expect.anything());
        expect(router.visit).not.toHaveBeenCalled();
    });
});
