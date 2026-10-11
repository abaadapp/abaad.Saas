import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { router } from '@inertiajs/react';

import { pageProps } from './setup';

vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

import type { EditorRow } from '@/Pages/Admin/Website/ThemeEditor';
import type { CatalogCategory, CatalogTools } from '@/Pages/Admin/Website/theme/CatalogTools';

const { default: ThemeEditor } = await import('@/Pages/Admin/Website/ThemeEditor');
const { CategoriesPanel } = await import('@/Pages/Admin/Website/theme/CatalogTools');

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
        { id: 1, name: 'ورد', name_en: 'Roses', shown_count: 3, card_hidden: false, card_image: null, sort_order: null },
        { id: 2, name: 'هدايا', name_en: null, shown_count: 0, card_hidden: false, card_image: null, sort_order: null },
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

/* ═══════════ بطاقةُ القسم: تُخفى وتلبس صورة ═══════════ */

describe('بطاقةُ القسم في «تسوّق حسب الفئة»', () => {
    beforeEach(() => vi.mocked(router.visit).mockClear());
    afterEach(() => vi.unstubAllGlobals());

    const cat = (over: Partial<CatalogCategory> = {}): CatalogCategory => ({
        id: 1, name: 'الإضافات', name_en: null, shown_count: 10, card_hidden: false, card_image: null, sort_order: null, ...over,
    });

    const answer = (category: Partial<CatalogCategory>, ok = true, status = 200, errors?: Record<string, string[]>) =>
        vi.fn(async () => ({
            ok,
            status,
            json: async () => (ok ? { ok: true, category: { id: 1, card_hidden: false, card_image: null, ...category } } : { message: 'x', errors }),
        }));

    const panel = (categories: CatalogCategory[], onCardChange = vi.fn()) => {
        for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
        Object.assign(pageProps, { translations: {} });
        render(<CategoriesPanel categories={categories} onCardChange={onCardChange} />);

        return onCardChange;
    };

    it('تُخفى البطاقةُ بضغطة — فيُقال إنّ أصنافها باقية، وتُعاد المعاينة', async () => {
        const fetch = answer({ card_hidden: true });
        vi.stubGlobal('fetch', fetch);
        const repaint = panel([cat()]);

        fireEvent.click(screen.getByRole('button', { name: /إخفاء البطاقة/ }));

        const row = screen.getByTestId('catalog-category-1');
        expect(await within(row).findByText('مخفيّة من «تسوّق حسب الفئة» — منتجاتها تبقى في المتجر')).toBeInTheDocument();
        expect(within(row).getByText(/10 منتج مفعّل ومنشور/)).toBeInTheDocument();
        expect(within(row).getByRole('button', { name: /إظهار البطاقة/ })).toHaveAttribute('aria-pressed', 'true');

        const [url, init] = fetch.mock.calls[0] as unknown as [string, RequestInit];
        expect(url).toBe('/admin.products.categories.card/1');
        expect(init.method).toBe('PATCH');
        expect(JSON.parse(String(init.body))).toEqual({ card_hidden: true });
        expect(repaint).toHaveBeenCalledTimes(1);
        expect(router.visit).not.toHaveBeenCalled();
    });

    it('والمخفيّةُ تُظهَر بالزرّ نفسه', async () => {
        const fetch = answer({ card_hidden: false });
        vi.stubGlobal('fetch', fetch);
        panel([cat({ card_hidden: true })]);

        fireEvent.click(screen.getByRole('button', { name: /إظهار البطاقة/ }));

        expect(await screen.findByText('تظهر في الموقع')).toBeInTheDocument();
        expect(JSON.parse(String((fetch.mock.calls[0] as unknown as [string, RequestInit])[1].body))).toEqual({ card_hidden: false });
    });

    it('وتُرفع لها صورة فتظهر — وتُزال فتعود الخطوط', async () => {
        const upload = answer({ card_image: '/storage/website/5/a.jpg' });
        vi.stubGlobal('fetch', upload);
        const repaint = panel([cat()]);

        const file = new File(['x'], 'roses.jpg', { type: 'image/jpeg' });
        fireEvent.change(screen.getByTestId('catalog-card-file-1'), { target: { files: [file] } });

        expect(await screen.findByTestId('catalog-card-image-1')).toHaveAttribute('src', '/storage/website/5/a.jpg');
        const [url, init] = upload.mock.calls[0] as unknown as [string, RequestInit];
        expect(url).toBe('/admin.products.categories.cardImage/1');
        expect(init.method).toBe('POST');
        expect((init.body as FormData).get('image')).toBe(file);
        // والملفُّ يُرسَل multipart — لا يُكتب له نوعٌ يكسر حدوده
        expect((init.headers as Record<string, string>)['Content-Type']).toBeUndefined();
        expect(screen.getByRole('button', { name: /استبدل الصورة/ })).toBeInTheDocument();

        const clear = answer({ card_image: null });
        vi.stubGlobal('fetch', clear);
        fireEvent.click(screen.getByRole('button', { name: /أزل صورة البطاقة/ }));

        await waitFor(() => expect(screen.queryByTestId('catalog-card-image-1')).toBeNull());
        const [clearUrl, clearInit] = clear.mock.calls[0] as unknown as [string, RequestInit];
        expect(clearUrl).toBe('/admin.products.categories.cardImage.clear/1');
        expect(clearInit.method).toBe('DELETE');
        expect(repaint).toHaveBeenCalledTimes(2);
    });

    it('ويقول خطأَ الخادم نفسَه — ولا يتبدّل شيء', async () => {
        vi.stubGlobal('fetch', answer({}, false, 422, { image: ['الملفّ صورة — PNG أو JPG أو WEBP'] }));
        const repaint = panel([cat()]);

        fireEvent.change(screen.getByTestId('catalog-card-file-1'), { target: { files: [new File(['x'], 'menu.pdf')] } });

        expect(await screen.findByRole('alert')).toHaveTextContent('الملفّ صورة — PNG أو JPG أو WEBP');
        expect(screen.queryByTestId('catalog-card-image-1')).toBeNull();
        expect(repaint).not.toHaveBeenCalled();
    });

    it('والمحرّرُ يُعيد معاينتَه بعد كلّ تبديلٍ في البطاقة', () => {
        const editor = readFileSync(resolve(process.cwd(), 'resources/js/Pages/Admin/Website/ThemeEditor.tsx'), 'utf8');

        expect(editor).toContain('<CategoriesPanel categories={catalogTools.categories} onCardChange={paint} />');
    });
});

/* ═══════════ ترتيبُ الفئات بالسهمين ═══════════ */

describe('ترتيبُ الفئات في الموقع', () => {
    beforeEach(() => vi.mocked(router.visit).mockClear());
    afterEach(() => vi.unstubAllGlobals());

    const c = (id: number, name: string, sort_order: number | null = null): CatalogCategory => ({
        id, name, name_en: null, shown_count: 2, card_hidden: false, card_image: null, sort_order,
    });

    const panel = (categories: CatalogCategory[]) => {
        for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
        Object.assign(pageProps, { translations: {} });
        const repaint = vi.fn();
        render(<CategoriesPanel categories={categories} onCardChange={repaint} />);

        return repaint;
    };

    const order = () => screen.getAllByTestId(/^catalog-category-\d+$/).map((r) => Number(r.dataset.testid!.split('-').pop()));

    it('يعرضها بترتيب الموقع — المرتَّبُ برقمه، ثمّ الباقي بالاسم', () => {
        panel([c(1, 'الإضافات'), c(2, 'منتجات', 2), c(3, 'باقات', 1), c(4, 'أزهار')]);

        expect(order()).toEqual([3, 2, 4, 1]);
    });

    it('يُنزل فئةً بسهم — فيُكتب الترتيبُ كلُّه، ويُرى فورًا، وتُعاد المعاينة', async () => {
        const fetch = vi.fn(async () => ({ ok: true, status: 200, json: async () => ({ ok: true, ids: [2, 1, 3] }) }));
        vi.stubGlobal('fetch', fetch);
        const repaint = panel([c(1, 'الإضافات'), c(2, 'باقات'), c(3, 'منتجات')]);

        fireEvent.click(screen.getByRole('button', { name: 'تحريك لأسفل الإضافات' }));

        expect(order()).toEqual([2, 1, 3]);
        const [url, init] = fetch.mock.calls[0] as unknown as [string, RequestInit];
        expect(url).toBe('/admin.products.categories.order');
        expect(init.method).toBe('PUT');
        expect(JSON.parse(String(init.body))).toEqual({ ids: [2, 1, 3] });
        await waitFor(() => expect(repaint).toHaveBeenCalledTimes(1));
        expect(router.visit).not.toHaveBeenCalled();
    });

    it('ويرفع بالسهم الآخر — والسهمُ عند الطرف معطَّل', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, status: 200, json: async () => ({ ok: true }) })));
        panel([c(1, 'الإضافات'), c(2, 'باقات'), c(3, 'منتجات')]);

        expect(screen.getByRole('button', { name: 'تحريك لأعلى الإضافات' })).toBeDisabled();
        expect(screen.getByRole('button', { name: 'تحريك لأسفل منتجات' })).toBeDisabled();

        fireEvent.click(screen.getByRole('button', { name: 'تحريك لأعلى منتجات' }));
        expect(order()).toEqual([1, 3, 2]);
        await waitFor(() => expect(screen.getByRole('button', { name: 'تحريك لأسفل باقات' })).toBeDisabled());
    });

    it('وإن رُدّ الترتيبُ عاد كما كان — وقيل لماذا', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(async () => ({
                ok: false,
                status: 422,
                json: async () => ({ message: 'x', errors: { 'ids.2': ['في الترتيب فئةٌ ليست في متجرك — حدّث الصفحة.'] } }),
            })),
        );
        const repaint = panel([c(1, 'الإضافات'), c(2, 'باقات'), c(3, 'منتجات')]);

        fireEvent.click(screen.getByRole('button', { name: 'تحريك لأسفل الإضافات' }));

        expect(await screen.findByTestId('catalog-order-error')).toHaveTextContent('في الترتيب فئةٌ ليست في متجرك — حدّث الصفحة.');
        expect(order()).toEqual([1, 2, 3]);
        expect(repaint).not.toHaveBeenCalled();
    });
});

/* ═══════════ الاسمُ والحذفُ وتلميحُ الصورة — من صفّ الفئة ═══════════ */

describe('اسمُ الفئة وحذفُها من المحرّر', () => {
    beforeEach(() => vi.mocked(router.visit).mockClear());
    afterEach(() => vi.unstubAllGlobals());

    const c = (id: number, name: string, shown_count = 2, name_en: string | null = null): CatalogCategory => ({
        id, name, name_en, shown_count, card_hidden: false, card_image: null, sort_order: null,
    });

    const panel = (categories: CatalogCategory[]) => {
        for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
        Object.assign(pageProps, { translations: {} });
        const repaint = vi.fn();
        render(<CategoriesPanel categories={categories} onCardChange={repaint} />);

        return repaint;
    };

    const reply = (ok: boolean, body: unknown, status = ok ? 200 : 422) => vi.fn(async () => ({ ok, status, json: async () => body }));

    it('يُعدَّل الاسمُ بالعربيّة والإنجليزيّة من صفّه — بالباب القائم، وتُعاد المعاينة', async () => {
        const fetch = reply(true, { ok: true, category: { id: 1, name: 'منتجاتنا', name_en: 'Our products' } });
        vi.stubGlobal('fetch', fetch);
        const repaint = panel([c(1, 'منتجات', 6, 'Products')]);

        fireEvent.click(screen.getByRole('button', { name: 'تعديل الاسم — منتجات' }));
        const form = screen.getByTestId('catalog-rename-form-1');
        expect(within(form).getByLabelText('اسم القسم — منتجات')).toHaveValue('منتجات');
        expect(within(form).getByLabelText('الاسم بالإنجليزية (اختياري) — منتجات')).toHaveValue('Products');

        fireEvent.change(within(form).getByLabelText('اسم القسم — منتجات'), { target: { value: 'منتجاتنا' } });
        fireEvent.change(within(form).getByLabelText('الاسم بالإنجليزية (اختياري) — منتجات'), { target: { value: 'Our products' } });
        fireEvent.click(within(form).getByRole('button', { name: 'حفظ' }));

        const row = screen.getByTestId('catalog-category-1');
        expect(await within(row).findByText('منتجاتنا')).toBeInTheDocument();
        expect(within(row).getByText('Our products')).toBeInTheDocument();
        expect(screen.queryByTestId('catalog-rename-form-1')).toBeNull();

        const [url, init] = fetch.mock.calls[0] as unknown as [string, RequestInit];
        expect(url).toBe('/admin.products.categories.update/1');
        expect(init.method).toBe('PATCH');
        expect(JSON.parse(String(init.body))).toEqual({ name: 'منتجاتنا', name_en: 'Our products' });
        expect(repaint).toHaveBeenCalledTimes(1);
        expect(router.visit).not.toHaveBeenCalled();
    });

    it('واسمٌ مكرَّر يُقال سببُه في صفّه — ويبقى الاسمُ كما كان', async () => {
        vi.stubGlobal('fetch', reply(false, { message: 'x', errors: { name: ['يوجد قسمٌ بهذا الاسم.'] } }));
        const repaint = panel([c(1, 'منتجات'), c(2, 'باقات')]);

        fireEvent.click(screen.getByRole('button', { name: 'تعديل الاسم — منتجات' }));
        fireEvent.change(screen.getByLabelText('اسم القسم — منتجات'), { target: { value: 'باقات' } });
        fireEvent.click(screen.getByRole('button', { name: 'حفظ' }));

        expect(await screen.findByTestId('catalog-naming-error-1')).toHaveTextContent('يوجد قسمٌ بهذا الاسم.');
        expect(within(screen.getByTestId('catalog-category-1')).getByText('منتجات')).toBeInTheDocument();
        expect(repaint).not.toHaveBeenCalled();
    });

    it('والفارغةُ وحدها لها زرُّ حذف — بتأكيدٍ من نافذة النظام، ثمّ تغيب', async () => {
        const fetch = reply(true, { ok: true, id: 2 });
        vi.stubGlobal('fetch', fetch);
        const repaint = panel([c(1, 'منتجات', 6), c(2, 'خدمات', 0)]);

        expect(screen.queryByRole('button', { name: 'حذف الفئة — منتجات' })).toBeNull();

        fireEvent.click(screen.getByRole('button', { name: 'حذف الفئة — خدمات' }));
        expect(fetch).not.toHaveBeenCalled();
        fireEvent.click(await screen.findByRole('button', { name: 'حذف الفئة' }));

        await waitFor(() => expect(screen.queryByTestId('catalog-category-2')).toBeNull());
        const [url, init] = fetch.mock.calls[0] as unknown as [string, RequestInit];
        expect(url).toBe('/admin.products.categories.destroy/2');
        expect(init.method).toBe('DELETE');
        expect(repaint).toHaveBeenCalledTimes(1);
    });

    it('وفئةٌ فيها منتجٌ مخفيّ يردّها الخادمُ — فيُقال السببُ وتبقى', async () => {
        const why = 'لا تُحذف فئةٌ فيها منتجات — حتّى المطفأة أو غير المنشورة أو المحذوفة. انقلها إلى فئةٍ أخرى أوّلًا.';
        vi.stubGlobal('fetch', reply(false, { message: 'x', errors: { category: [why] } }));
        panel([c(2, 'عروض', 0)]);

        fireEvent.click(screen.getByRole('button', { name: 'حذف الفئة — عروض' }));
        fireEvent.click(await screen.findByRole('button', { name: 'حذف الفئة' }));

        expect(await screen.findByTestId('catalog-naming-error-2')).toHaveTextContent(why);
        expect(screen.getByTestId('catalog-category-2')).toBeInTheDocument();
    });

    it('ومقاسُ الصورة يُقال مرّةً فوق القائمة — والمصغّرةُ بشكل البطاقة', () => {
        panel([{ ...c(1, 'منتجات'), card_image: '/storage/website/5/a.jpg' }, c(2, 'خدمات', 0)]);

        expect(screen.getAllByTestId('catalog-card-hint')).toHaveLength(1);
        expect(screen.getByTestId('catalog-card-hint')).toHaveTextContent('الأفضل صورة أفقية 4:3 — مثل 1200×900. الطولية تُقصّ من أعلاها وأسفلها.');
        expect(screen.getByTestId('catalog-card-image-1').className).toContain('w-[53px]');
    });
});

/* ═══════════ عمودُ المحرّر ضيّق: الفئةُ في سطرها والأزرارُ تحتها ═══════════ */

describe('صفُّ الفئة في عمودٍ ضيّق', () => {
    it('السطرُ الأوّل للفئة وحدها — ورابطُ المنتجات مع الأزرار لا بجانب الاسم', () => {
        for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
        Object.assign(pageProps, { translations: {} });
        render(
            <CategoriesPanel
                categories={[{ id: 1, name: 'خدمات', name_en: 'Services', shown_count: 6, card_hidden: false, card_image: null, sort_order: null }]}
            />,
        );

        const text = screen.getByTestId('catalog-text-1');
        // الاسمُ يلتفّ ولا يُقصّ، والحالُ والعددُ في سطرين لا يتراكبان
        expect(within(text).getByText('خدمات').className).not.toContain('truncate');
        expect(within(text).getByText('تظهر في الموقع')).toBeInTheDocument();
        expect(within(text).getByText('6 منتج مفعّل ومنشور').tagName).toBe('P');
        // والرابطُ في سطر الأزرار — لا في سطر الفئة
        expect(within(text).queryByRole('link', { name: /عرض المنتجات/ })).toBeNull();
        expect(within(screen.getByTestId('catalog-card-1')).getByRole('link', { name: /عرض المنتجات/ })).toBeInTheDocument();
        // والسهمان فوق بعضهما ليتّسع الاسم
        expect(screen.getByTestId('catalog-order-1').className).toContain('flex-col');
    });
});
