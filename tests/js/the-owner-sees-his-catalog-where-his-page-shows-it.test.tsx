import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { router } from '@inertiajs/react';

import { pageProps } from './setup';

vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

import type { EditorRow } from '@/Pages/Admin/Website/ThemeEditor';
import type { CatalogTools } from '@/Pages/Admin/Website/theme/CatalogTools';

const { default: ThemeEditor } = await import('@/Pages/Admin/Website/ThemeEditor');

/**
 * لوحتا «تسوّق حسب الفئة» و«وصل حديثًا» في محرّر الواجهة.
 *
 * ═══ وما يُحرَس ═══
 *
 * أنّ المتجر الذي لم تصله الحمولةُ يبقى على سطر «يُكتب في…» كما كان، وأنّ
 * من وصلته يرى فئاتِه وحالَها وأحدثَ أصنافه — ويضيف فئةً بالباب القائم
 * (`admin.products.categories.store`) دون أن يغادر المحرّر أو يفقد ما كتبه.
 */

const row = (over: Partial<EditorRow> & { key: string }): EditorRow => ({
    label: over.key,
    hint: 'وصفُ الصفّ',
    fixed: false,
    on: true,
    silent: null,
    source: null,
    fields: [],
    ...over,
});

const PAGES = [
    { key: 'home', path: '/', fixed: true, on: true, blocked: null },
    { key: 'shop', path: '/shop', fixed: true, on: true, blocked: null },
];

const HERO = row({
    key: 'hero',
    label: 'الواجهة',
    fixed: true,
    fields: [{ key: 'store_headline', kind: 'text', label: 'العنوان الكبير' }],
});
const CATS = row({ key: 'cats', label: 'تسوّق حسب الفئة', source: { label: 'الأصناف والفئات', route: 'admin.products.index' } });
const NEW = row({ key: 'new', label: 'وصل حديثًا', source: { label: 'الأصناف', route: 'admin.products.index' } });
const FOOT = row({ key: 'foot', label: 'التذييل', fixed: true });

const TOOLS: CatalogTools = {
    categories: [
        { id: 1, name: 'ورد', name_en: 'Roses', shown_count: 3 },
        { id: 2, name: 'هدايا', name_en: null, shown_count: 0 },
    ],
    new_arrivals: [
        { id: 40, name: 'باقة الربيع', image: '/storage/p/40.jpg', category: 'ورد' },
        { id: 39, name: 'علبة شوكولاتة', image: null, category: null },
    ],
};

const draw = (catalogTools?: CatalogTools | null) => {
    for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    Object.assign(pageProps, { translations: {} });

    return render(
        <ThemeEditor
            theme="ribbon"
            site={{ name: 'RIBBON', published: true, url: 'https://ribbon.abaadapp.om', slug: 'ribbon', host: 'abaadapp.om' }}
            publishing={null}
            pages={PAGES}
            rows={[HERO, CATS, NEW, FOOT]}
            values={{ store_headline: '' }}
            order={['cats', 'new']}
            maxFeatured={4}
            products={[]}
            {...(catalogTools === undefined ? {} : { catalogTools })}
        />,
    );
};

const openRow = (label: string) => fireEvent.click(screen.getByText(label));

describe('لوحتا الفئات و«وصل حديثًا»', () => {
    beforeEach(() => vi.mocked(router.visit).mockClear());
    afterEach(() => vi.unstubAllGlobals());

    /* ═══════════ من لم تصله الحمولة: الشاشةُ كما كانت ═══════════ */

    it.each([undefined, null])('وبلا حمولة (%s) يبقى الصفّان على سطر «يُكتب في…»', (tools) => {
        draw(tools);

        openRow('تسوّق حسب الفئة');
        expect(screen.queryByTestId('catalog-categories')).toBeNull();
        expect(screen.getByRole('link', { name: /الأصناف والفئات/ })).toHaveAttribute('href', '/admin.products.index');

        openRow('وصل حديثًا');
        expect(screen.queryByTestId('catalog-new-arrivals')).toBeNull();
        expect(screen.getByText('محتوى هذا القسم يُكتب في:')).toBeInTheDocument();
    });

    /* ═══════════ «تسوّق حسب الفئة» ═══════════ */

    it('ويعرض كلَّ فئةٍ بحالها — وبابَ منتجاتها بمرشِّح الاسم', () => {
        const route = vi.spyOn(globalThis as unknown as { route: (...a: unknown[]) => string }, 'route');
        draw(TOOLS);
        openRow('تسوّق حسب الفئة');

        const panel = screen.getByTestId('catalog-categories');
        expect(within(panel).getByText('الفئات في الموقع')).toBeInTheDocument();
        expect(screen.queryByText('محتوى هذا القسم يُكتب في:')).toBeNull();

        const roses = screen.getByTestId('catalog-category-1');
        expect(within(roses).getByText('Roses')).toBeInTheDocument();
        expect(within(roses).getByText('تظهر في الموقع')).toBeInTheDocument();
        expect(within(roses).getByText(/3 منتج مفعّل ومنشور/)).toBeInTheDocument();
        // والمرشِّحُ القائم بالاسم (`category=`) — لا معرّفٌ يُخترع له مرشِّح
        expect(route).toHaveBeenCalledWith('admin.products.index', { category: 'ورد' });
        expect(decodeURI(within(roses).getByRole('link', { name: /عرض المنتجات/ }).getAttribute('href') ?? '')).toBe(
            '/admin.products.index/ورد',
        );
        route.mockRestore();

        const gifts = screen.getByTestId('catalog-category-2');
        expect(within(gifts).getByText('لن تظهر — لا يوجد منتج مفعّل ومنشور')).toBeInTheDocument();

        expect(within(panel).getByRole('link', { name: /إضافة منتج/ })).toHaveAttribute('href', '/admin.products.create');
        expect(within(panel).getByRole('link', { name: /إدارة كل المنتجات/ })).toHaveAttribute('href', '/admin.products.index');
    });

    it('ويضيف فئةً بالباب القائم — فتظهر «لن تظهر» ولا يغادر المحرّر ولا يُمحى ما كُتب', async () => {
        const fetch = vi.fn(async () => ({
            ok: true,
            status: 200,
            json: async () => ({ ok: true, category: { id: 9, name: 'شوكولاتة', name_en: 'Chocolate' } }),
        }));
        vi.stubGlobal('fetch', fetch);

        draw(TOOLS);

        // ما كُتب في الواجهة ولم يُحفظ
        openRow('الواجهة');
        fireEvent.change(screen.getByLabelText('العنوان الكبير'), { target: { value: 'ورد العيد' } });

        openRow('تسوّق حسب الفئة');
        fireEvent.click(screen.getByRole('button', { name: /إضافة قسم/ }));
        fireEvent.change(screen.getByLabelText('اسم القسم'), { target: { value: 'شوكولاتة' } });
        fireEvent.change(screen.getByLabelText('الاسم بالإنجليزية (اختياري)'), { target: { value: 'Chocolate' } });
        fireEvent.click(screen.getByRole('button', { name: 'إضافة' }));

        const made = await screen.findByTestId('catalog-category-9');
        expect(within(made).getByText('لن تظهر — لا يوجد منتج مفعّل ومنشور')).toBeInTheDocument();

        expect(fetch).toHaveBeenCalledTimes(1);
        const [url, init] = fetch.mock.calls[0] as unknown as [string, RequestInit];
        expect(url).toBe('/admin.products.categories.store');
        expect(init.method).toBe('POST');
        expect(JSON.parse(String(init.body))).toEqual({ name: 'شوكولاتة', name_en: 'Chocolate' });

        expect(router.visit).not.toHaveBeenCalled();
        openRow('الواجهة');
        expect(screen.getByLabelText('العنوان الكبير')).toHaveValue('ورد العيد');
    });

    it('ويقول خطأَ الخادم نفسَه حين يُردّ', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(async () => ({
                ok: false,
                status: 422,
                json: async () => ({ message: 'x', errors: { name: ['يوجد قسمٌ بهذا الاسم.'] } }),
            })),
        );

        draw(TOOLS);
        openRow('تسوّق حسب الفئة');
        fireEvent.click(screen.getByRole('button', { name: /إضافة قسم/ }));
        fireEvent.change(screen.getByLabelText('اسم القسم'), { target: { value: 'ورد' } });
        fireEvent.click(screen.getByRole('button', { name: 'إضافة' }));

        expect(await screen.findByRole('alert')).toHaveTextContent('يوجد قسمٌ بهذا الاسم.');
        // ولم يُضَف شيء
        await waitFor(() => expect(screen.getAllByTestId(/^catalog-category-/)).toHaveLength(2));
    });

    /* ═══════════ «وصل حديثًا» ═══════════ */

    it('ويعرض الأحدثَ بمواضعه — ويقول القاعدة', () => {
        draw(TOOLS);
        openRow('وصل حديثًا');

        const panel = screen.getByTestId('catalog-new-arrivals');
        expect(within(panel).getByText('يظهر هنا تلقائيًا آخر 4 منتجات مفعّلة ومنشورة أضفتها إلى المتجر.')).toBeInTheDocument();
        expect(within(panel).getByText('تعديل منتج قديم لا يجعله «وصل حديثًا»؛ الترتيب حسب وقت إضافته للنظام.')).toBeInTheDocument();

        const first = screen.getByTestId('catalog-arrival-40');
        expect(within(first).getByText('1')).toBeInTheDocument();
        expect(within(first).getByText('ورد')).toBeInTheDocument();
        expect(first.querySelector('img')).toHaveAttribute('src', '/storage/p/40.jpg');
        expect(within(screen.getByTestId('catalog-arrival-39')).getByText('2')).toBeInTheDocument();

        expect(within(panel).getByRole('link', { name: /إضافة منتج جديد/ })).toHaveAttribute('href', '/admin.products.create');
        expect(within(panel).getByRole('link', { name: /إدارة المنتجات/ })).toHaveAttribute('href', '/admin.products.index');
        // ولا سحبَ ولا «اجعله جديدًا»
        expect(panel.querySelector('[draggable="true"]')).toBeNull();
    });

    it('وبلا صنفٍ معروض يقول ذلك ويدلّ على الإضافة', () => {
        draw({ categories: [], new_arrivals: [] });
        openRow('وصل حديثًا');

        expect(screen.getByTestId('catalog-new-arrivals-empty')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: /إضافة منتج جديد/ })).toHaveAttribute('href', '/admin.products.create');
    });
});
