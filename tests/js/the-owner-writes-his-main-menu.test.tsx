import { fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { router as core } from '@inertiajs/core';

import { pageProps } from './setup';

vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

import type { EditorRow } from '@/Pages/Admin/Website/ThemeEditor';
import { serializeCollections } from '@/Pages/Admin/Website/theme/CollectionsField';
import { parsePages, serializePages } from '@/Pages/Admin/Website/theme/CustomPagesField';
import { BUILT_INS, type MenuItem, parseMenu, serializeMenu } from '@/Pages/Admin/Website/theme/MenuField';

const { default: ThemeEditor } = await import('@/Pages/Admin/Website/ThemeEditor');

/**
 * «القائمة الرئيسية» في محرّر الواجهة — الصفحاتُ الأربعُ بترتيب صاحبها وروابطُه بينها.
 *
 * ═══ وما يُحرَس ═══
 *
 * أنّ الصفَّ تحت رأس المتجر وفوق الواجهة، وأنّ الرابطَ يُضاف ويُسمّى بلغتيه
 * وتُختار وجهتُه من قائمةٍ فيها صفحاتُه ومجموعاتُه وفئاتُه، ويُخفى ويُحذف
 * بتأكيد، وأنّ الصفحاتِ الأربعَ تُرتَّب ولا تُحذف، وأنّ صفحةً تُنشأ من القائمة
 * نفسِها وتُحفظ معها — و«احفظ» يُرسل ما تبدّل وحده.
 *
 * وقواعدُ الحفظ في الخادم: `ARibbonMenuTakesItsOwnersLinksAndPagesTest`.
 */

const row = (over: Partial<EditorRow> & { key: string }): EditorRow => ({
    label: over.key, hint: '', fixed: true, on: true, silent: null, source: null, fields: [], ...over,
});

const ROWS = [
    row({ key: 'vbar', label: 'الشريط المصوّر' }),
    row({ key: 'head', label: 'رأس المتجر' }),
    row({ key: 'menu', label: 'القائمة الرئيسية', fields: [{ key: 'store_menu', kind: 'menu', label: 'روابط القائمة', max: 3 }] }),
    row({ key: 'hero', label: 'الواجهة' }),
    row({ key: 'pages', label: 'الصفحات المخصّصة', fields: [{ key: 'store_custom_pages', kind: 'pages', label: 'الصفحات', max: 4, max_sections: 3 }] }),
    row({ key: 'foot', label: 'التذييل' }),
];

const SERVICES = { slug: 'services', title_ar: 'خدماتنا', title_en: 'Our services', intro_ar: '', intro_en: '', image: '', sections: [], cta: null, visible: true };
const OCCASIONS = { slug: 'occasions', title_ar: 'المناسبات', title_en: 'Occasions', desc_ar: '', desc_en: '', image: '', visible: true, in_row: true, products: [38] };

const draw = (menu = '', pages = serializePages([SERVICES])) => {
    for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    Object.assign(pageProps, { translations: {} });

    render(
        <ThemeEditor
            theme="ribbon"
            site={{ name: 'RIBBON', published: true, url: 'https://ribbon.abaadapp.om', slug: 'ribbon', host: 'abaadapp.om' }}
            publishing={null}
            pages={[
                { key: 'home', path: '/', fixed: true, on: true, blocked: null },
                { key: 'shop', path: '/shop', fixed: true, on: true, blocked: null },
                { key: 'about', path: '/about', fixed: false, on: true, blocked: null },
                { key: 'contact', path: '/contact', fixed: false, on: false, blocked: null },
            ]}
            rows={ROWS}
            values={{ store_menu: menu, store_custom_pages: pages, store_collections: serializeCollections([OCCASIONS]) }}
            order={[]}
            maxFeatured={4}
            products={[{ id: 38, name: 'ورد أحمر', image: null }]}
            shortcutCategories={[{ id: 11, name: 'باقات', shown: 3 }]}
        />,
    );
};

const capture = () => {
    const sent: { data: Record<string, unknown> }[] = [];
    vi.spyOn(core, 'post').mockImplementation(((_url: string, data: Record<string, unknown>, options: Record<string, unknown>) => {
        sent.push({ data });
        (options as { onSuccess?: () => void }).onSuccess?.();
        (options as { onFinish?: () => void }).onFinish?.();
    }) as never);

    return sent;
};

const openMenu = () => fireEvent.click(screen.getByText('القائمة الرئيسية'));
const saveIn = (key: string) => fireEvent.click(within(screen.getByTestId(`row-${key}`)).getByRole('button', { name: 'احفظ' }));
const last = (sent: { data: Record<string, unknown> }[]) => sent[sent.length - 1].data;
const keysOf = (items: MenuItem[]) => items.map((i) => (i.type === 'page' ? i.key : `link:${i.id}`));

afterEach(() => vi.restoreAllMocks());

describe('قراءةُ القائمة وكتابتُها', () => {
    it('الفراغُ القائمةُ القديمة — والقديمةُ نفسُها تُكتب فراغًا', () => {
        expect(keysOf(parseMenu(''))).toEqual(BUILT_INS);
        expect(keysOf(parseMenu('{oops'))).toEqual(BUILT_INS);
        expect(serializeMenu(parseMenu(''))).toBe('');
    });

    it('والغائبُ من الصفحات الأربع يُلحق آخرَها — والعابثُ يسقط', () => {
        const items = parseMenu(JSON.stringify([
            { type: 'page', key: 'shop' },
            { type: 'link', id: 'a1', title_ar: 'خدماتنا', title_en: '', target: { type: 'page', value: 'services' }, visible: true },
            { type: 'link', id: 'bad id', title_ar: 'x', target: { type: 'shop', value: '' } },
            { type: 'link', id: 'a2', title_ar: 'خارج', target: { type: 'url', value: 'https://x' } },
            { type: 'page', key: 'admin' },
        ]));

        expect(keysOf(items)).toEqual(['shop', 'link:a1', 'home', 'about', 'contact']);
    });
});

describe('«القائمة الرئيسية» في المحرّر', () => {
    it('صفُّها تحت رأس المتجر وفوق الواجهة', () => {
        draw();

        const rows = screen.getAllByTestId(/^row-/).map((li) => li.dataset.testid);
        expect(rows.slice(0, 4)).toEqual(['row-vbar', 'row-head', 'row-menu', 'row-hero']);
    });

    it('يُضاف رابطٌ ويُسمّى بلغتيه وتُختار وجهتُه — و«احفظ» يُرسل القائمةَ وحدها', () => {
        const sent = capture();
        draw();
        openMenu();

        fireEvent.click(screen.getByTestId('menu-add-link'));
        // والرابطُ الجديدُ قبل «من نحن»: بعد الرئيسية والمتجر حيث يُتوقَّع
        const select = screen.getByTestId('menu-link-2-target') as HTMLSelectElement;
        const values = Array.from(select.options).map((o) => o.value);
        expect(values).toEqual(['home', 'shop', 'best', 'about', 'page:services', 'collection:occasions', 'category:11', 'product:38']);
        expect(values).not.toContain('contact');

        fireEvent.change(select, { target: { value: 'collection:occasions' } });
        fireEvent.change(screen.getByTestId('menu-link-2-title-ar'), { target: { value: 'المناسبات' } });
        fireEvent.change(screen.getByTestId('menu-link-2-title-en'), { target: { value: 'Occasions' } });

        saveIn('menu');
        expect(Object.keys(last(sent))).toEqual(['store_menu']);
        const items = parseMenu(String(last(sent).store_menu));
        expect(keysOf(items).map((k) => (k.startsWith('link:') ? 'link' : k))).toEqual(['home', 'shop', 'link', 'about', 'contact']);
        expect(items[2]).toMatchObject({ title_ar: 'المناسبات', title_en: 'Occasions', target: { type: 'collection', value: 'occasions' }, visible: true });
    });

    it('والصفحاتُ الأربعُ تُرتَّب ولا تُحذف — والرابطُ يُخفى ويُحذف بتأكيد', () => {
        const sent = capture();
        draw(serializeMenu([
            { type: 'page', key: 'home' }, { type: 'page', key: 'shop' },
            { type: 'link', id: 'svc', title_ar: 'خدماتنا', title_en: '', target: { type: 'page', value: 'services' }, visible: true },
            { type: 'link', id: 'off', title_ar: 'عروضنا', title_en: '', target: { type: 'shop', value: '' }, visible: true },
            { type: 'page', key: 'about' }, { type: 'page', key: 'contact' },
        ]));
        openMenu();

        // «المتجر» أوّلًا — ولا زرَّ حذفٍ للصفحات الأربع
        fireEvent.click(screen.getByRole('button', { name: 'تحريك لأعلى المتجر' }));
        expect(within(screen.getByTestId('menu-page-home')).queryByRole('button', { name: /احذف/ })).toBeNull();
        // و«تواصل معنا» المطفأةُ يُقال أين تُشغَّل
        expect(screen.getByTestId('menu-page-contact-state').textContent).toContain('«الصفحات»');

        fireEvent.click(screen.getByTestId('menu-link-2-visible'));
        fireEvent.click(screen.getByText('عروضنا'));
        fireEvent.click(screen.getByTestId('menu-link-3-delete'));
        expect(screen.getAllByTestId(/^menu-link-\d+$/)).toHaveLength(2);
        fireEvent.click(screen.getByTestId('menu-link-3-delete-confirm'));

        saveIn('menu');
        const items = parseMenu(String(last(sent).store_menu));
        expect(keysOf(items)).toEqual(['shop', 'home', 'link:svc', 'about', 'contact']);
        expect(items[2]).toMatchObject({ visible: false });
    });

    it('وصفحةٌ جديدةٌ تُنشأ من القائمة نفسِها — وتُحفظ معها', () => {
        const sent = capture();
        draw('', '');
        openMenu();

        fireEvent.click(screen.getByTestId('menu-add-page'));
        expect(screen.getByTestId('menu-page-created')).toBeTruthy();

        saveIn('menu');
        expect(Object.keys(last(sent)).sort()).toEqual(['store_custom_pages', 'store_menu']);
        const [page] = parsePages(String(last(sent).store_custom_pages));
        expect(page).toMatchObject({ title_ar: 'صفحة جديدة', title_en: 'New page', slug: 'new-page', visible: true });
        const link = parseMenu(String(last(sent).store_menu)).find((i) => i.type === 'link');
        expect(link).toMatchObject({ target: { type: 'page', value: 'new-page' } });

        // وحفظٌ بعده لا يُعيد إرسالَ ما حُفظ
        saveIn('menu');
        expect(Object.keys(last(sent))).toEqual(['store_menu']);
    });

    it('ووجهةٌ لا تُفتح يُقال عنها — ويقف عند حدّه — ويعود إلى القائمة الأصليّة', () => {
        const sent = capture();
        draw(serializeMenu([
            { type: 'page', key: 'home' },
            { type: 'link', id: 'gone', title_ar: 'محذوفة', title_en: '', target: { type: 'page', value: 'gone' }, visible: true },
            { type: 'link', id: 'b', title_ar: 'ب', title_en: '', target: { type: 'shop', value: '' }, visible: true },
            { type: 'link', id: 'c', title_ar: 'ج', title_en: '', target: { type: 'best', value: '' }, visible: true },
            { type: 'page', key: 'shop' }, { type: 'page', key: 'about' }, { type: 'page', key: 'contact' },
        ]));
        openMenu();

        expect(screen.getByTestId('menu-link-1-unreachable')).toBeTruthy();
        expect(screen.queryByTestId('menu-link-2-unreachable')).toBeNull();
        expect((screen.getByTestId('menu-add-link') as HTMLButtonElement).disabled).toBe(true);

        fireEvent.click(screen.getByTestId('menu-reset'));
        saveIn('menu');
        expect(last(sent).store_menu).toBe('');
    });
});
