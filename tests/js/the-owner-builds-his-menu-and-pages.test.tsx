import { fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { router as core } from '@inertiajs/core';

import { pageProps } from './setup';

vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

import type { EditorRow } from '@/Pages/Admin/Website/ThemeEditor';
import { serializeCollections } from '@/Pages/Admin/Website/theme/CollectionsField';
import { BUILT_INS, type MenuItem, orphanPages, parseMenu, serializeMenu } from '@/Pages/Admin/Website/theme/MenuField';
import { type CustomPage, parsePages, serializePages } from '@/Pages/Admin/Website/theme/pages';

const { default: ThemeEditor } = await import('@/Pages/Admin/Website/ThemeEditor');

/**
 * «القائمة الرئيسية والصفحات» — «+ إضافة قسم أو صفحة» ثمّ الاسمُ وحده.
 *
 * ═══ وما يُحرَس ═══
 *
 *  • يختار صاحبُ المتجر النوعَ ببطاقة: عنوانٌ فقط، أو صفحةُ كتابة، أو صور، أو كتابةٌ
 *    وصور، أو منتجات، أو محتوى متنوّع، أو رابط — ثمّ يكتب اسمَه ويضيف.
 *  • ولا رابطَ يُطلب ولا اسمٌ مختصرٌ يُرى: الصفحةُ تُنشأ برابطها الداخليّ وحدها،
 *    وخانةُ الرابط تظهر لنوع «رابط» حين يختار «رابط تكتبه» وحده.
 *  • والصفحةُ تُكتب في مكانها من القائمة: وحداتٌ تُضاف وتُرتَّب وتُحذف، والمنتجاتُ
 *    تُختار بصورها وبحثها، وتبديلُ النوع يسأل قبل أن يُسقط شيئًا.
 *  • والحذفُ من القائمة لا يُضيّع صفحة، والوحدةُ الفارغةُ لا تُرسَل ولا تردّ الحفظ.
 *
 * وقواعدُ الحفظ والرسم في الخادم: `ARibbonMenuItemIsATitleAPageOrALinkTest`.
 */

const row = (over: Partial<EditorRow> & { key: string }): EditorRow => ({
    label: over.key, hint: '', fixed: true, group: 'main', on: true, silent: null, source: null, fields: [], ...over,
});

const ROWS = [
    row({ key: 'hero', label: 'الواجهة' }),
    row({
        key: 'menu',
        label: 'القائمة الرئيسية والصفحات',
        fields: [
            { key: 'store_menu', kind: 'menu', label: 'عناصر القائمة', max: 4, max_pages: 3, max_blocks: 6, owns: ['store_custom_pages'] },
            { key: 'store_custom_pages', kind: 'pages', label: 'الصفحات' },
        ],
    }),
    row({ key: 'foot', label: 'التذييل' }),
];

const OCCASIONS = { slug: 'occasions', title_ar: 'المناسبات', title_en: 'Occasions', desc_ar: '', desc_en: '', image: '', visible: true, in_row: true, products: [38] };

const PRODUCTS = [
    { id: 36, name: 'بالون', name_en: 'Balloon', category: 'إضافات', image: null },
    { id: 38, name: 'ورد أحمر', name_en: 'Red roses', category: 'باقات', image: null },
];

const page = (over: Partial<CustomPage> = {}): CustomPage => ({
    slug: 'events', kind: 'mixed', title_ar: 'المناسبات', title_en: '', intro_ar: '', intro_en: '', blocks: [], visible: true, ...over,
});

const linkTo = (slug: string, title: string): MenuItem => ({ type: 'link', id: 'l1', title_ar: title, title_en: '', target: { type: 'page', value: slug }, visible: true });

const draw = (menu = '', pages = '') => {
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
                { key: 'contact', path: '/contact', fixed: false, on: true, blocked: null },
            ]}
            rows={ROWS}
            values={{ store_menu: menu, store_custom_pages: pages, store_collections: serializeCollections([OCCASIONS]) }}
            order={[]}
            maxFeatured={4}
            products={PRODUCTS}
            shortcutCategories={[{ id: 11, name: 'باقات', shown: 3 }]}
        />,
    );

    fireEvent.click(screen.getByText('القائمة الرئيسية والصفحات'));
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

const save = () => fireEvent.click(screen.getByTestId('row-menu-save'));
const last = (sent: { data: Record<string, unknown> }[]) => sent[sent.length - 1].data;
const sentMenu = (sent: { data: Record<string, unknown> }[]) => parseMenu(String(last(sent).store_menu));
const sentPages = (sent: { data: Record<string, unknown> }[]) => parsePages(String(last(sent).store_custom_pages));

/** «+ إضافة قسم أو صفحة» ← النوع ← الاسم ← «أضف» */
const add = (type: string, name: string) => {
    fireEvent.click(screen.getByTestId('menu-add'));
    fireEvent.click(screen.getByTestId(`menu-type-${type}`));
    fireEvent.change(screen.getByTestId('menu-new-name-ar'), { target: { value: name } });
};

afterEach(() => vi.restoreAllMocks());

describe('قراءةُ القائمة وكتابتُها', () => {
    it('العنوانُ يُقرأ بلا وجهة — والرابطُ بلا وجهةٍ يسقط', () => {
        const items = parseMenu(JSON.stringify([
            { type: 'label', id: 'a1', title_ar: 'المناسبات', title_en: '' },
            { type: 'link', id: 'a2', title_ar: 'بلا وجهة', title_en: '' },
        ]));

        expect(items.map((i) => i.type)).toEqual(['label', 'page', 'page', 'page', 'page']);
        expect(serializeMenu(parseMenu(''))).toBe('');
        expect(parseMenu('').map((i) => (i.type === 'page' ? i.key : ''))).toEqual(BUILT_INS);
    });

    it('والصفحةُ التي لا يقود إليها عنصرٌ تُعرف — لتُعاد أو تُحذف', () => {
        const pages = [page({ slug: 'a' }), page({ slug: 'b' })];

        expect(orphanPages([linkTo('a', 'أ')], pages).map((p) => p.slug)).toEqual(['b']);
    });
});

describe('«+ إضافة قسم أو صفحة»', () => {
    it('سبعُ بطاقات — والعنوانُ اسمٌ وحده بلا وجهةٍ ولا صفحة', () => {
        const sent = capture();
        draw();

        fireEvent.click(screen.getByTestId('menu-add'));
        for (const type of ['label', 'text', 'images', 'textimage', 'products', 'mixed', 'link']) {
            expect(screen.getByTestId(`menu-type-${type}`)).toBeTruthy();
        }

        fireEvent.click(screen.getByTestId('menu-type-label'));
        expect(screen.queryByTestId('menu-new-target')).toBeNull();
        fireEvent.change(screen.getByTestId('menu-new-name-ar'), { target: { value: 'المناسبات' } });
        fireEvent.click(screen.getByTestId('menu-new-add'));

        save();
        const item = sentMenu(sent).find((i) => i.type === 'label');
        expect(item).toMatchObject({ type: 'label', title_ar: 'المناسبات', visible: true });
        expect(item && 'target' in item).toBe(false);
        expect(parsePages(String(last(sent).store_custom_pages))).toEqual([]);
    });

    it('وصفحةُ الكتابة تُنشأ مع اسمها وتُكتب في مكانها — بلا رابطٍ يُكتب ولا اسمٍ مختصرٍ يُرى', () => {
        const sent = capture();
        draw();

        add('text', 'قصّتنا');
        fireEvent.click(screen.getByTestId('menu-new-add'));

        // والصفحةُ مفتوحةٌ تحت اسمها — لا خانةَ رابطٍ ولا «/page/…»
        expect(screen.queryByText(/\/page\//)).toBeNull();
        expect(screen.queryAllByTestId(/slug/)).toHaveLength(0);
        expect(screen.queryByTestId(/-url$/)).toBeNull();

        const editor = screen.getByTestId('menu-item-2-page');
        expect((within(editor).getByTestId('menu-item-2-page-kind-text') as HTMLElement).getAttribute('aria-checked')).toBe('true');
        expect((within(editor).getByTestId('menu-item-2-page-title-ar') as HTMLInputElement).value).toBe('قصّتنا');

        fireEvent.change(within(editor).getByTestId('menu-item-2-page-intro-ar'), { target: { value: 'منذ سنوات' } });
        fireEvent.click(within(editor).getByTestId('menu-item-2-page-add-text'));
        fireEvent.change(within(editor).getByTestId('menu-item-2-page-block-0-body'), { target: { value: 'فقرةٌ أولى' } });
        // وصفحةُ الكتابة لا تعرض أزرارَ الصور ولا المنتجات
        expect(within(editor).queryByTestId('menu-item-2-page-add-image')).toBeNull();
        expect(within(editor).queryByTestId('menu-item-2-page-add-products')).toBeNull();

        save();
        expect(Object.keys(last(sent)).sort()).toEqual(['store_custom_pages', 'store_menu']);
        const [p] = sentPages(sent);
        expect(p).toMatchObject({ kind: 'text', title_ar: 'قصّتنا', intro_ar: 'منذ سنوات', blocks: [{ type: 'text', body_ar: 'فقرةٌ أولى' }] });
        expect(sentMenu(sent).find((i) => i.type === 'link')).toMatchObject({ title_ar: 'قصّتنا', target: { type: 'page', value: p.slug } });
    });

    it('وصفحةُ المنتجات تبدأ بـ«اختيار المنتجات» — بحثٌ واختيارُ عدّةٍ معًا، بلا مجموعةٍ تُنشأ', () => {
        const sent = capture();
        draw();

        add('products', 'هدايا التخرّج');
        fireEvent.click(screen.getByTestId('menu-new-add'));
        fireEvent.click(screen.getByTestId('menu-item-2-page-add-products'));
        fireEvent.click(screen.getByTestId('chosen-menu-item-2-page-block-0-pick'));

        fireEvent.change(screen.getByTestId('picker-search'), { target: { value: 'Red' } });
        expect(screen.queryByTestId('picker-item-36')).toBeNull();
        fireEvent.click(screen.getByTestId('picker-item-38'));
        fireEvent.change(screen.getByTestId('picker-search'), { target: { value: '' } });
        fireEvent.click(screen.getByTestId('picker-item-36'));
        fireEvent.click(screen.getByTestId('picker-add'));

        save();
        expect(sentPages(sent)[0].blocks).toEqual([{ type: 'products', title_ar: '', title_en: '', products: [38, 36] }]);
        expect(last(sent).store_collections).toBeUndefined();
    });

    it('والرابطُ يختار وجهتَه من قائمة — وخانةُ الرابط لا تظهر إلّا لـ«رابط تكتبه»', () => {
        const sent = capture();
        draw();

        add('link', 'إنستغرام');
        const target = screen.getByTestId('menu-new-target') as HTMLSelectElement;
        expect(screen.queryByTestId('menu-new-target-url')).toBeNull();

        fireEvent.change(target, { target: { value: 'url' } });
        fireEvent.change(screen.getByTestId('menu-new-target-url'), { target: { value: 'javascript:alert(1)' } });
        expect(screen.getByTestId('menu-new-target-url-hint').textContent).toContain('الرابط غير صالح');

        fireEvent.change(screen.getByTestId('menu-new-target-url'), { target: { value: 'https://instagram.com/ribbon' } });
        expect(screen.getByTestId('menu-new-target-url-hint').textContent).not.toContain('غير صالح');
        fireEvent.click(screen.getByTestId('menu-new-add'));

        save();
        expect(sentMenu(sent).find((i) => i.type === 'link')).toMatchObject({ target: { type: 'url', value: 'https://instagram.com/ribbon' } });
    });
});

describe('الصفحةُ في مكانها من القائمة', () => {
    const MIXED = page({
        blocks: [
            { type: 'image', image: '/storage/website/1/a.png', fit: 'contain' },
            { type: 'text', body_ar: 'فقرة', body_en: '' },
        ],
    });

    it('تبديلُ النوع يسأل قبل أن يُسقط — والإلغاءُ يُبقي كلَّ شيء', async () => {
        const sent = capture();
        draw(serializeMenu([...parseMenu(''), linkTo('events', 'المناسبات')]), serializePages([MIXED]));
        fireEvent.click(screen.getByTestId('menu-item-4-open'));

        fireEvent.click(screen.getByTestId('menu-item-4-page-kind-text'));
        const dialog = await screen.findByRole('dialog');
        expect(dialog.textContent).toContain('صورة');
        fireEvent.click(within(dialog).getByRole('button', { name: 'إلغاء' }));

        save();
        expect(sentPages(sent)[0].kind).toBe('mixed');

        fireEvent.click(screen.getByTestId('menu-item-4-page-kind-text'));
        fireEvent.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'غيّر النوع' }));
        await screen.findByTestId('menu-item-4-page-add-heading');

        save();
        expect(sentPages(sent)[0]).toMatchObject({ kind: 'text', blocks: [{ type: 'text', body_ar: 'فقرة' }] });
    });

    it('والوحدةُ الفارغةُ يُقال إنّها لن تُحفظ — ولا تُرسَل', () => {
        const sent = capture();
        draw(serializeMenu([...parseMenu(''), linkTo('events', 'المناسبات')]), serializePages([page()]));
        fireEvent.click(screen.getByTestId('menu-item-4-open'));

        fireEvent.click(screen.getByTestId('menu-item-4-page-add-heading'));
        expect(screen.getByTestId('menu-item-4-page-block-0-empty')).toBeTruthy();

        save();
        expect(sentPages(sent)[0].blocks).toEqual([]);
    });

    it('واسمُ القسم يتبعه عنوانُ صفحته — ما دام اسمَه', () => {
        const sent = capture();
        draw(serializeMenu([...parseMenu(''), linkTo('events', 'المناسبات')]), serializePages([page()]));
        fireEvent.click(screen.getByTestId('menu-item-4-open'));

        fireEvent.change(screen.getByTestId('menu-item-4-name-ar'), { target: { value: 'مناسباتنا' } });

        save();
        expect(sentPages(sent)[0].title_ar).toBe('مناسباتنا');
    });

    it('والإزالةُ من القائمة لا تُضيّع الصفحة — تُعاد من «صفحات ليست في القائمة»', async () => {
        const sent = capture();
        draw(serializeMenu([...parseMenu(''), linkTo('events', 'المناسبات')]), serializePages([page()]));
        fireEvent.click(screen.getByTestId('menu-item-4-open'));

        fireEvent.click(screen.getByTestId('menu-item-4-delete'));
        fireEvent.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'أزل من القائمة' }));
        await screen.findByTestId('menu-orphan-events');

        save();
        expect(sentMenu(sent).some((i) => i.type === 'link')).toBe(false);
        expect(sentPages(sent).map((p) => p.slug)).toEqual(['events']);

        fireEvent.click(screen.getByTestId('menu-orphan-events-add'));
        save();
        expect(sentMenu(sent).find((i) => i.type === 'link')).toMatchObject({ target: { type: 'page', value: 'events' } });
    });

    it('وتُرتَّب العناصرُ كلُّها بسهمين — من الهاتف كما من الحاسوب', () => {
        const sent = capture();
        draw(serializeMenu([...parseMenu(''), linkTo('events', 'المناسبات')]), serializePages([page()]));

        fireEvent.click(screen.getByRole('button', { name: 'تحريك لأعلى المناسبات' }));
        fireEvent.click(screen.getByRole('button', { name: 'تحريك لأعلى المناسبات' }));

        save();
        expect(sentMenu(sent).map((i) => (i.type === 'page' ? i.key : 'events'))).toEqual(['home', 'shop', 'events', 'about', 'contact']);
    });
});
