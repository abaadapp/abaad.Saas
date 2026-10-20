import { fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { router as core } from '@inertiajs/core';
import { router } from '@inertiajs/react';

import { pageProps } from './setup';

vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

import type { EditorRow } from '@/Pages/Admin/Website/ThemeEditor';
import PublishBar from '@/Pages/Admin/Website/theme/PublishBar';

const { default: ThemeEditor } = await import('@/Pages/Admin/Website/ThemeEditor');

/**
 * ما كتبه صاحبُ المتجر لا يضيع بلا سؤال — ويُتراجَع عمّا لم يُنشر.
 *
 * ═══ وما يُحرَس ═══
 *
 *  • صفٌّ فيه تعديلٌ لم يُحفظ يُوسم «غير محفوظ»، ويزول الوسمُ بالحفظ.
 *  • ومن أغلق الصفحةَ أو انتقل منها وفيها ما لم يُحفظ يُسأل (`beforeunload`،
 *    و`router.on('before')` للانتقال داخل الشاشات) — ولا يُسأل وكلُّ شيءٍ محفوظ.
 *  • و«تراجع عن التعديلات غير المنشورة» في شريط النشر: يسأل، ثمّ يُرسل إلى بابه
 *    ويُعيد بناء المحرّر — ولا يظهر حين لا تغيير.
 */

const HERO: EditorRow = {
    key: 'hero', label: 'الواجهة', hint: '', fixed: true, group: 'main', on: true, silent: null, source: null,
    fields: [{ key: 'store_hero_title', kind: 'text', label: 'عنوان الواجهة — العربية' }],
};

const draw = () => {
    for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    Object.assign(pageProps, { translations: {} });

    render(
        <ThemeEditor
            theme="ribbon"
            site={{ name: 'RIBBON', published: true, url: 'https://ribbon.abaadapp.om', slug: 'ribbon', host: 'abaadapp.om' }}
            publishing={null}
            pages={[{ key: 'home', path: '/', fixed: true, on: true, blocked: null }]}
            rows={[HERO]}
            values={{ store_hero_title: 'العنوان' }}
            order={[]}
            maxFeatured={4}
            products={[]}
        />,
    );

    fireEvent.click(screen.getByText('الواجهة'));
};

afterEach(() => vi.restoreAllMocks());

describe('ما لم يُحفظ', () => {
    it('يُوسم صفُّه «غير محفوظ» ويُسأل قبل المغادرة — ويزول الوسمُ والسؤالُ بالحفظ', () => {
        const listen = vi.spyOn(window, 'addEventListener');
        vi.spyOn(core, 'post').mockImplementation(((_url: string, _data: unknown, options: Record<string, unknown>) => {
            (options as { onSuccess?: () => void }).onSuccess?.();
            (options as { onFinish?: () => void }).onFinish?.();
        }) as never);
        draw();

        expect(screen.queryByTestId('row-hero-unsaved')).toBeNull();
        expect(listen.mock.calls.some(([type]) => type === 'beforeunload')).toBe(false);

        fireEvent.change(screen.getByLabelText('عنوان الواجهة — العربية'), { target: { value: 'عنوانٌ جديد' } });
        expect(screen.getByTestId('row-hero-unsaved')).toBeTruthy();
        expect(listen.mock.calls.some(([type]) => type === 'beforeunload')).toBe(true);
        expect(vi.mocked(router.on).mock.calls.some(([type]) => type === 'before')).toBe(true);

        fireEvent.click(screen.getByTestId('row-hero-save'));
        expect(screen.queryByTestId('row-hero-unsaved')).toBeNull();

        // والعودةُ إلى ما حُفظ تُزيل الوسمَ كذلك — لا «غير محفوظ» عن لا شيء
        fireEvent.change(screen.getByLabelText('عنوان الواجهة — العربية'), { target: { value: 'آخر' } });
        fireEvent.change(screen.getByLabelText('عنوان الواجهة — العربية'), { target: { value: 'عنوانٌ جديد' } });
        expect(screen.queryByTestId('row-hero-unsaved')).toBeNull();
    });
});

describe('«تراجع عن التعديلات غير المنشورة»', () => {
    const state = (changed: number) => ({ changed, fields: changed ? ['عنوان الواجهة'] : [], revision: 4, published_at: null, versions: [] });

    it('يسأل ثمّ يُرسل إلى بابه ويُعيد بناء المحرّر', async () => {
        render(<PublishBar state={state(1)} />);

        fireEvent.click(screen.getByTestId('discard'));
        const dialog = await screen.findByRole('dialog');
        expect(dialog.textContent).toContain('ولا يتغيّر شيءٌ عندهم');
        fireEvent.click(within(dialog).getByRole('button', { name: 'تراجع' }));

        await vi.waitFor(() => expect(router.post).toHaveBeenCalled());
        const [url, , options] = vi.mocked(router.post).mock.calls.at(-1)!;
        expect(url).toBe('/admin.website.store.discard');
        expect(options).toMatchObject({ preserveState: false });
    });

    it('ولا يظهر حين لا تغييرَ يُتراجَع عنه', () => {
        render(<PublishBar state={state(0)} />);

        expect(screen.queryByTestId('discard')).toBeNull();
    });
});
