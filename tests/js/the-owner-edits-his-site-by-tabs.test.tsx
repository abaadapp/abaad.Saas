import { act, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { router as core } from '@inertiajs/core';
import { router } from '@inertiajs/react';

import { pageProps } from './setup';

vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

import type { EditorField, EditorRow } from '@/Pages/Admin/Website/ThemeEditor';
import type { CatalogTools } from '@/Pages/Admin/Website/theme/CatalogTools';
import { EDITOR_TABS, tabOf } from '@/Pages/Admin/Website/theme/editorTabs';

const { default: ThemeEditor } = await import('@/Pages/Admin/Website/ThemeEditor');

/**
 * محرّرُ الواجهة بالتبويبات — لمتجرٍ تصله لوحتا الفئات وحده (`catalogTools`).
 *
 * ═══ وما يُحرَس ═══
 *
 * أنّ التبويبات ترتيبٌ للعين لا يمسّ شيئًا: كلُّ صفٍّ في تبويبٍ واحد لا يُسقَط
 * ولا يتكرّر، وما كُتب يبقى حين يُبدَّل التبويب، و«احفظ» يحفظ مفاتيحَ صفّه
 * وحدها، وفتحُ الشاشة وتصفّحُها والبحثُ فيها لا يُرسل شيئًا، والترتيبُ يُكتب
 * كما كانت الشاشةُ القديمة تكتبه حرفًا. ومن لم تصله الحمولةُ يرى الشاشةَ القديمة.
 */

const text = (key: string, label: string): EditorField => ({ key, kind: 'text', label });

const row = (over: Partial<EditorRow> & { key: string }): EditorRow => ({
    group: 'advanced',
    label: over.key,
    hint: 'وصفُ الصفّ',
    fixed: true,
    on: true,
    silent: null,
    source: null,
    fields: [],
    ...over,
});

// الصفوفُ الستّة عشر كما يرسلها `PageEditor::rows` — وصفٌّ مجهولٌ يُضاف غدًا
const ROWS: EditorRow[] = [
    row({ key: 'head', label: 'رأس المتجر', fields: [text('store_announcement_ar', 'نص الشريط بالعربية')] }),
    row({ key: 'vbar', label: 'الشريط المصوّر', fields: [text('store_visual_bar', 'بطاقات الشريط')] }),
    row({ key: 'collections', label: 'مجموعات المتجر', group: 'main', fields: [text('store_collections', 'المجموعات')] }),
    row({ key: 'menu', label: 'القائمة الرئيسية والصفحات', group: 'main', fields: [text('store_menu', 'عناصر القائمة')] }),
    row({ key: 'images', label: 'عرض الصور', fields: [text('store_catalog_fit', 'صور المنتجات والفئات')] }),
    row({
        key: 'hero',
        label: 'الواجهة',
        group: 'main',
        fields: [text('store_hero_title', 'عنوان الواجهة — العربية'), { key: 'store_hero_sub', kind: 'textarea', label: 'وصف الواجهة — العربية' }],
    }),
    ...['cats', 'best', 'new', 'banner', 'block', 'picks', 'about', 'reviews'].map((key) =>
        row({ key, label: `قسم ${key}`, fixed: false, fields: key === 'about' ? [{ key: 'store_about', kind: 'textarea', label: 'نبذة النشاط — العربية' }] : [] }),
    ),
    row({ key: 'upsells', label: 'أضف مع طلبك', fields: [text('store_upsells', 'أصناف تُقترح')] }),
    row({
        key: 'foot',
        label: 'التذييل',
        group: 'main',
        fields: [text('store_tagline', 'سطر التذييل'), text('store_whatsapp', 'واتساب')],
        source: { label: 'هاتفك وبريدك', route: 'admin.settings.index' },
    }),
    row({ key: 'mystery', label: 'صفٌّ يُضاف غدًا', fields: [text('store_mystery', 'حقلٌ جديد')] }),
];

const VALUES: Record<string, string> = Object.fromEntries(ROWS.flatMap((r) => r.fields.map((f) => [f.key, ''])));
VALUES.store_hero_title = 'ورودٌ لكلّ مناسبة';

const TOOLS: CatalogTools = { categories: [], new_arrivals: [] };

const PAGES = [
    { key: 'home', path: '/', fixed: true, on: true, blocked: null },
    { key: 'shop', path: '/shop', fixed: true, on: true, blocked: null },
    { key: 'about', path: '/about', fixed: true, on: true, blocked: null },
    { key: 'contact', path: '/contact', fixed: true, on: true, blocked: null },
];

const draw = (catalogTools: CatalogTools | null = TOOLS) => {
    for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    Object.assign(pageProps, { translations: {} });

    return render(
        <ThemeEditor
            theme="ribbon"
            site={{ name: 'RIBBON', published: true, url: 'https://ribbon.abaadapp.om', slug: 'ribbon', host: 'abaadapp.om' }}
            publishing={null}
            pages={PAGES}
            rows={ROWS}
            values={VALUES}
            order={['cats', 'best', 'new']}
            maxFeatured={4}
            products={[]}
            catalogTools={catalogTools}
        />,
    );
};

/** الصفوفُ المرسومةُ في اللوحة الظاهرة — بمفاتيحها */
const shownRows = () =>
    [...document.querySelectorAll('[role="tabpanel"] li[data-testid^="row-"]')].map((li) => li.getAttribute('data-testid')!.slice('row-'.length));

const openTab = (id: string) => fireEvent.click(screen.getByTestId(`tab-${id}`));
const openRow = (label: string) => fireEvent.click(screen.getByText(label));

let posted: ReturnType<typeof vi.spyOn>;

describe('محرّر الواجهة بالتبويبات', () => {
    beforeEach(() => {
        vi.mocked(router.post).mockClear();
        posted = vi.spyOn(core, 'post').mockImplementation((() => {}) as never);
    });
    afterEach(() => {
        posted.mockRestore();
        window.history.pushState({}, '', '/');
    });

    it('كلُّ صفٍّ في تبويبٍ واحد — لا يُسقَط ولا يتكرّر، والمجهولُ في «متقدّمة»', () => {
        draw();
        expect(screen.getByTestId('editor-tabs')).toBeInTheDocument();

        const seen: Record<string, string[]> = {};
        for (const tab of EDITOR_TABS) {
            openTab(tab.id);
            expect(screen.getByTestId(`tabpanel-${tab.id}`)).toBeInTheDocument();
            seen[tab.id] = shownRows();
        }

        const all = Object.values(seen).flat();
        expect(all.sort()).toEqual(ROWS.map((r) => r.key).sort());
        expect(new Set(all).size).toBe(all.length);

        expect(seen.home).toEqual(['hero', 'cats', 'best', 'new', 'banner', 'block', 'picks', 'about', 'reviews']);
        expect(seen.pages).toEqual(['menu']);
        expect(seen.links).toEqual(['head', 'vbar', 'collections']);
        expect(seen.identity).toEqual(['images']);
        expect(seen.contact).toEqual(['foot']);
        expect(seen.advanced).toEqual(['upsells', 'mystery']);
        // والقسمةُ نفسُها في الدالّة التي تقرؤها الشاشة
        expect(ROWS.filter((r) => tabOf(r) === 'advanced').map((r) => r.key)).toEqual(['upsells', 'mystery']);
    });

    it('وكلُّ حقلٍ حاليٍّ يُرسم في صفّه حين يُفتح — لا حقلَ يغيب', () => {
        draw();
        for (const r of ROWS.filter((x) => x.fields.length > 0)) {
            openTab(tabOf(r));
            openRow(r.label);
            for (const f of r.fields) expect(screen.getByText(f.label)).toBeInTheDocument();
            openRow(r.label);
        }
    });

    it.each([null])('ومن لم تصله الحمولة (%s) يرى الشاشةَ القديمة كما كانت', (tools) => {
        draw(tools);
        expect(screen.queryByTestId('editor-tabs')).toBeNull();
        expect(screen.queryByTestId('editor-search')).toBeNull();
        expect(screen.getByTestId('rows-main')).toBeInTheDocument();
        expect(screen.getByTestId('advanced-toggle')).toBeInTheDocument();
    });

    it('وما كُتب يبقى حين يُبدَّل التبويب — ولا يُرسَل شيءٌ حتى «احفظ»', () => {
        draw();
        openRow('الواجهة');
        const title = screen.getByDisplayValue('ورودٌ لكلّ مناسبة');
        fireEvent.change(title, { target: { value: 'هدايا الخريف' } });
        expect(screen.getByTestId('tab-home-unsaved')).toBeInTheDocument();

        openTab('contact');
        openRow('التذييل');
        fireEvent.change(screen.getByLabelText('سطر التذييل'), { target: { value: 'سطرٌ جديد' } });
        openTab('pages');
        openTab('home');

        // صفٌّ واحدٌ مفتوحٌ في الشاشة كما كان — يُفتح ثانيةً فيجد ما كُتب فيه
        openRow('الواجهة');
        expect(screen.getByDisplayValue('هدايا الخريف')).toBeInTheDocument();
        openTab('contact');
        openRow('التذييل');
        expect(screen.getByDisplayValue('سطرٌ جديد')).toBeInTheDocument();
        expect(screen.getByTestId('tab-home-unsaved')).toBeInTheDocument();
        expect(screen.getByTestId('tab-contact-unsaved')).toBeInTheDocument();

        expect(posted).not.toHaveBeenCalled();
        expect(router.post).not.toHaveBeenCalled();
    });

    it('و«احفظ» في صفٍّ يحفظ مفاتيحَه وحدها — وما كُتب في غيره يبقى غيرَ محفوظ', () => {
        draw();
        openTab('contact');
        openRow('التذييل');
        fireEvent.change(screen.getByLabelText('سطر التذييل'), { target: { value: 'سطرٌ جديد' } });

        openTab('home');
        openRow('الواجهة');
        fireEvent.change(screen.getByDisplayValue('ورودٌ لكلّ مناسبة'), { target: { value: 'هدايا الخريف' } });
        fireEvent.click(screen.getByTestId('row-hero-save'));

        expect(posted).toHaveBeenCalledTimes(1);
        const data = posted.mock.calls[0][1] as Record<string, string>;
        expect(Object.keys(data).sort()).toEqual(['store_hero_sub', 'store_hero_title']);
        expect(data.store_hero_title).toBe('هدايا الخريف');
        expect(screen.getByTestId('tab-contact-unsaved')).toBeInTheDocument();
    });

    it('وفتحُ الصفوف وإغلاقُها والتنقّلُ بين التبويبات بلا كتابةٍ لا يُرسل ولا يوسم شيئًا', () => {
        draw();
        for (const tab of EDITOR_TABS) {
            openTab(tab.id);
            for (const key of shownRows()) {
                const li = screen.getByTestId(`row-${key}`);
                const label = ROWS.find((r) => r.key === key)!.label;
                fireEvent.click(within(li).getByText(label));
                fireEvent.click(within(li).getByText(label));
            }
            expect(screen.queryByTestId(`tab-${tab.id}-unsaved`)).toBeNull();
        }
        expect(posted).not.toHaveBeenCalled();
        expect(router.post).not.toHaveBeenCalled();
    });

    it('والبحثُ يعرض الصفَّ المطابِق باسمه أو باسم حقله — ولا يمسّ قيمة', () => {
        draw();
        const search = screen.getByTestId('editor-search');

        fireEvent.change(search, { target: { value: 'التذييل' } });
        expect(screen.getByTestId('tabpanel-search')).toBeInTheDocument();
        expect(shownRows()).toEqual(['foot']);

        fireEvent.change(search, { target: { value: 'واتساب' } });
        expect(shownRows()).toEqual(['foot']);

        // بلا همزةٍ ولا تاء مربوطة
        fireEvent.change(search, { target: { value: 'اضف مع طلبك' } });
        expect(shownRows()).toEqual(['upsells']);

        fireEvent.change(search, { target: { value: 'لا شيء يطابق هذا' } });
        expect(screen.getByTestId('editor-search-empty')).toBeInTheDocument();

        openTab('home');
        expect((search as HTMLInputElement).value).toBe('');
        expect(screen.getByTestId('tabpanel-home')).toBeInTheDocument();
        openRow('الواجهة');
        expect(screen.getByDisplayValue('ورودٌ لكلّ مناسبة')).toBeInTheDocument();
        expect(posted).not.toHaveBeenCalled();
        expect(router.post).not.toHaveBeenCalled();
    });

    it('ويُفتح على تبويب الصفّ الذي جاء به الرابط (`?row=foot`) والصفُّ مفتوح', () => {
        window.history.pushState({}, '', '/admin/website/editor?row=foot');
        draw();
        expect(screen.getByTestId('tab-contact')).toHaveAttribute('aria-selected', 'true');
        expect(screen.getByLabelText('سطر التذييل')).toBeInTheDocument();
    });

    it('والترتيبُ يُكتب كما كانت الشاشةُ القديمة تكتبه — حرفًا', () => {
        const written = (tools: CatalogTools | null) => {
            vi.mocked(router.post).mockClear();
            const view = draw(tools);
            if (! tools) fireEvent.click(screen.getByTestId('advanced-toggle'));
            fireEvent.click(screen.getByRole('button', { name: 'أنزل قسم cats' }));
            fireEvent.click(screen.getByRole('switch', { name: 'قسم banner' }));
            const calls = vi.mocked(router.post).mock.calls.map((c) => c[1]);
            act(() => view.unmount());

            return calls;
        };

        const classic = written(null);
        const tabbed = written(TOOLS);
        expect(classic.length).toBeGreaterThan(0);
        expect(tabbed).toEqual(classic);
        expect((tabbed[0] as Record<string, string>).store_sections.startsWith('best,cats,new')).toBe(true);
    });

    it('و«الصفحات والقائمة» تشير إلى أبواب الصفحات الثابتة — و«من نحن» إلى قسم «عنّا» نفسِه', () => {
        draw();
        openTab('pages');
        const doors = screen.getByTestId('pages-doors');
        expect(within(doors).getByRole('link', { name: /منتجاتك وفئاتك/ })).toHaveAttribute('href', '/admin.products.index');
        expect(within(doors).getByRole('link', { name: /بيانات النشاط/ })).toHaveAttribute('href', '/admin.settings.index');

        fireEvent.click(within(doors).getByRole('button', { name: /قسم «عنّا»/ }));
        expect(screen.getByTestId('tab-home')).toHaveAttribute('aria-selected', 'true');
        expect(screen.getByText('نبذة النشاط — العربية')).toBeInTheDocument();
        expect(router.visit).not.toHaveBeenCalled();
    });

    it('و«الهوية والصور» تقول إنّ الشعار والألوان من القالب — ولا تخترع لهما حقلًا', () => {
        draw();
        openTab('identity');
        expect(screen.getByTestId('identity-note')).toHaveTextContent('RIBBON');
        expect(shownRows()).toEqual(['images']);
        expect(screen.getByTestId('tabpanel-identity').querySelectorAll('input, textarea, select')).toHaveLength(0);
    });
});
