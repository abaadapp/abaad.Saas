import { fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { router as core } from '@inertiajs/core';

import { pageProps } from './setup';

vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

import type { EditorRow } from '@/Pages/Admin/Website/ThemeEditor';
import { serializeCollections } from '@/Pages/Admin/Website/theme/CollectionsField';
import { type BarCard, fromKey, parseBar, serializeBar, targetKey } from '@/Pages/Admin/Website/theme/VisualBarField';

const { default: ThemeEditor } = await import('@/Pages/Admin/Website/ThemeEditor');

/**
 * «الشريط المصوّر» في محرّر الواجهة — بطاقاتٌ بصورها فوق قائمة الصفحات.
 *
 * ═══ وما يُحرَس ═══
 *
 * أنّ الصفَّ أوّلُ الصفوف (الشريطُ أوّلُ ما يُرى)، وأنّه يُشغَّل ويُختار أين
 * يظهر، وأنّ البطاقةَ تُضاف وتُكتب بلغتيها وتُختار وجهتُها من قائمةٍ — لا رابطَ
 * يُكتب — وتُرتَّب وتُخفى وتُحذف بتأكيد، وأنّ «احفظ» يُرسل مفتاحَه وحده نصَّ
 * JSON. وأنّ وجهةً لا تُفتح يُقال عنها قبل الحفظ.
 *
 * وقواعدُ الحفظ نفسُها (الوجهةُ والصورةُ والحدّ) تُحرس في الخادم:
 * `TheRibbonVisualBarSitsAboveTheNavigationTest`.
 */

const row = (over: Partial<EditorRow> & { key: string }): EditorRow => ({
    label: over.key, hint: '', fixed: true, on: true, silent: null, source: null, fields: [], ...over,
});

const VBAR = row({ key: 'vbar', label: 'الشريط المصوّر', fields: [{ key: 'store_visual_bar', kind: 'visualbar', label: 'بطاقات الشريط', max: 3 }] });
const HEAD = row({ key: 'head', label: 'رأس المتجر', fields: [{ key: 'store_announcement_ar', kind: 'text', label: 'نص الشريط بالعربية' }] });
const COLLECTIONS = row({ key: 'collections', label: 'مجموعات المتجر', fields: [{ key: 'store_collections', kind: 'collections', label: 'المجموعات', max: 5, max_products: 5 }] });
const HERO = row({ key: 'hero', label: 'الواجهة' });
const FOOT = row({ key: 'foot', label: 'التذييل' });

const PRODUCTS = [{ id: 38, name: 'ورد أحمر', image: null }];
const CATS = [{ id: 11, name: 'باقات', shown: 3 }, { id: 12, name: 'فارغة', shown: 0 }];

const GRAD = { slug: 'graduation', title_ar: 'هدايا التخرّج', title_en: 'Graduation gifts', desc_ar: '', desc_en: '', image: '/storage/website/5/g.jpg', visible: true, in_row: true, products: [38] };

const card = (over: Partial<BarCard> = {}): BarCard => ({
    image: '', title_ar: 'بطاقة', title_en: 'Card', sub_ar: '', sub_en: '', cta_ar: '', cta_en: '',
    target: { type: 'shop', value: '' }, visible: true, ...over,
});

const draw = (bar = '', collections = serializeCollections([GRAD])) => {
    for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    Object.assign(pageProps, { translations: {} });

    render(
        <ThemeEditor
            theme="ribbon"
            site={{ name: 'RIBBON', published: true, url: 'https://ribbon.abaadapp.om', slug: 'ribbon', host: 'abaadapp.om' }}
            publishing={null}
            pages={[
                { key: 'home', path: '/', fixed: true, on: true, blocked: null },
                { key: 'about', path: '/about', fixed: false, on: true, blocked: null },
                { key: 'contact', path: '/contact', fixed: false, on: false, blocked: null },
            ]}
            rows={[VBAR, HEAD, HERO, COLLECTIONS, FOOT]}
            values={{ store_visual_bar: bar, store_collections: collections, store_announcement_ar: '' }}
            order={['cats']}
            maxFeatured={4}
            products={PRODUCTS}
            shortcutCategories={CATS}
        />,
    );
};

const capture = () => {
    const sent: { url: string; data: Record<string, unknown> }[] = [];
    vi.spyOn(core, 'post').mockImplementation(((url: string, data: Record<string, unknown>, options: Record<string, unknown>) => {
        sent.push({ url, data });
        (options as { onFinish?: () => void }).onFinish?.();
    }) as never);

    return sent;
};

const openBar = () => fireEvent.click(screen.getByText('الشريط المصوّر'));
const saveIn = (key: string) => fireEvent.click(within(screen.getByTestId(`row-${key}`)).getByRole('button', { name: 'احفظ' }));
const lastBar = (sent: { data: Record<string, unknown> }[]) => parseBar(String(sent[sent.length - 1].data.store_visual_bar));

afterEach(() => vi.restoreAllMocks());

describe('قراءةُ الشريط وكتابتُه', () => {
    it('مطفأٌ بلا بطاقاتٍ فراغ — والعابثُ يسقط', () => {
        expect(parseBar('')).toEqual({ on: false, pages: 'home', cards: [] });
        expect(parseBar('{oops')).toEqual({ on: false, pages: 'home', cards: [] });
        expect(parseBar('[1]').cards).toEqual([]);
        expect(serializeBar({ on: false, pages: 'home', cards: [] })).toBe('');
        expect(parseBar(JSON.stringify({ on: true, cards: [{ target: { type: 'url', value: 'https://x' } }] })).cards).toEqual([]);
    });

    it('والوجهةُ مفتاحٌ واحدٌ ذهابًا وإيابًا', () => {
        expect(targetKey({ type: 'collection', value: 'graduation' })).toBe('collection:graduation');
        expect(fromKey('category:11')).toEqual({ type: 'category', value: 11 });
        expect(fromKey('best')).toEqual({ type: 'best', value: '' });
    });
});

describe('«الشريط المصوّر» في المحرّر', () => {
    it('أوّلُ الصفوف — فوق رأس المتجر', () => {
        draw();

        const rows = screen.getAllByTestId(/^row-/).map((li) => li.dataset.testid);
        expect(rows.slice(0, 3)).toEqual(['row-vbar', 'row-head', 'row-hero']);
    });

    it('يُشغَّل ويُختار أين يظهر، وتُضاف بطاقةٌ بلغتيها ووجهتِها — و«احفظ» يُرسل مفتاحَه وحده', () => {
        const sent = capture();
        draw();
        openBar();

        expect(screen.getByTestId('visualbar-none')).toBeTruthy();
        fireEvent.click(screen.getByRole('switch', { name: 'شغّل الشريط' }));
        fireEvent.click(screen.getByTestId('visualbar-pages-browse'));
        fireEvent.click(screen.getByTestId('visualbar-add'));

        // والبطاقةُ الأولى تبدأ بأوّل مجموعةٍ واسمِها — ويُبدَّل ما شاء
        expect((screen.getByTestId('vcard-0-target') as HTMLSelectElement).value).toBe('collection:graduation');
        expect((screen.getByTestId('vcard-0-title-ar') as HTMLInputElement).value).toBe('هدايا التخرّج');
        fireEvent.change(screen.getByTestId('vcard-0-sub-ar'), { target: { value: 'لكلّ خرّيج' } });
        fireEvent.change(screen.getByTestId('vcard-0-cta-en'), { target: { value: 'Shop now' } });
        expect((screen.getByTestId('vcard-0-cta-en') as HTMLInputElement).dir).toBe('ltr');

        saveIn('vbar');
        expect(sent).toHaveLength(1);
        expect(Object.keys(sent[0].data)).toEqual(['store_visual_bar']);
        expect(lastBar(sent)).toEqual({
            on: true, pages: 'browse',
            cards: [card({ title_ar: 'هدايا التخرّج', title_en: 'Graduation gifts', sub_ar: 'لكلّ خرّيج', cta_en: 'Shop now', target: { type: 'collection', value: 'graduation' } })],
        });
    });

    it('والوجهةُ تُختار من قائمةٍ — صفحاتٌ ومجموعاتٌ وفئاتٌ ومنتجات، ولا خانةَ رابط', () => {
        draw(serializeBar({ on: true, pages: 'home', cards: [card()] }));
        openBar();
        fireEvent.click(screen.getByText('بطاقة'));

        const select = screen.getByTestId('vcard-0-target') as HTMLSelectElement;
        const values = Array.from(select.options).map((o) => o.value);
        // والرئيسيّةُ وجهةٌ كذلك منذ صارت الوجهةُ واحدةً للشريط والقائمة وزرّ الصفحة (`StoreTargets`)
        expect(values).toEqual(['home', 'shop', 'best', 'about', 'collection:graduation', 'category:11', 'category:12', 'product:38']);
        // و«تواصل معنا» مطفأةٌ فلا تُعرض وجهةً
        expect(values).not.toContain('contact');
        expect(screen.queryByRole('textbox', { name: /رابط|URL|link/i })).toBeNull();

        fireEvent.change(select, { target: { value: 'category:11' } });
        expect(screen.queryByTestId('vcard-0-unreachable')).toBeNull();
    });

    it('ووجهةٌ لا تُفتح الآن يُقال عنها في صفّها', () => {
        draw(serializeBar({ on: true, pages: 'home', cards: [
            card({ title_ar: 'إلى فئةٍ فارغة', target: { type: 'category', value: 12 } }),
            card({ title_ar: 'إلى مجموعةٍ حُذفت', target: { type: 'collection', value: 'gone' } }),
            card({ title_ar: 'إلى الكل' }),
        ] }));
        openBar();

        expect(screen.getByTestId('vcard-0-unreachable')).toBeTruthy();
        expect(screen.getByTestId('vcard-1-unreachable')).toBeTruthy();
        expect(screen.queryByTestId('vcard-2-unreachable')).toBeNull();
    });

    it('وتُرتَّب وتُخفى وتُحذف بتأكيد — ويقف عند حدّه', () => {
        const sent = capture();
        draw(serializeBar({ on: true, pages: 'home', cards: [card({ title_ar: 'أولى' }), card({ title_ar: 'ثانية' }), card({ title_ar: 'ثالثة' })] }));
        openBar();

        expect((screen.getByTestId('visualbar-add') as HTMLButtonElement).disabled).toBe(true);

        fireEvent.click(screen.getByRole('button', { name: 'تحريك لأعلى ثانية' }));
        fireEvent.click(screen.getByTestId('vcard-2-visible'));
        fireEvent.click(screen.getByText('أولى'));
        fireEvent.click(screen.getByTestId('vcard-1-delete'));
        expect(screen.getAllByTestId(/^vcard-\d+$/)).toHaveLength(3);
        fireEvent.click(screen.getByTestId('vcard-1-delete-confirm'));

        saveIn('vbar');
        const bar = lastBar(sent);
        expect(bar.cards.map((c) => c.title_ar)).toEqual(['ثانية', 'ثالثة']);
        expect(bar.cards[1].visible).toBe(false);
    });

    it('ومجموعةٌ أُنشئت للتوّ تُختار وجهةً قبل أن تُحفظ', () => {
        draw('', '');
        fireEvent.click(screen.getByText('مجموعات المتجر'));
        fireEvent.click(screen.getByTestId('collections-add'));
        fireEvent.change(screen.getByTestId('collection-0-title-ar'), { target: { value: 'العيد' } });
        fireEvent.change(screen.getByTestId('collection-0-slug'), { target: { value: 'eid' } });

        openBar();
        fireEvent.click(screen.getByTestId('visualbar-add'));
        expect((screen.getByTestId('vcard-0-target') as HTMLSelectElement).value).toBe('collection:eid');
    });
});
