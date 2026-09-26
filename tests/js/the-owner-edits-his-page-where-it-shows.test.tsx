import { fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { router } from '@inertiajs/react';

import { pageProps } from './setup';

vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

import type { EditorRow } from '@/Pages/Admin/Website/ThemeEditor';

const { default: ThemeEditor } = await import('@/Pages/Admin/Website/ThemeEditor');

/**
 * محرّرُ صفحة الواجهة الخاصّة — الصفوفُ بترتيب ما يُرى، وكلُّ حقلٍ في صفّه.
 *
 * ═══ وما يُحرَس ═══
 *
 * أنّ الشاشة تقول ما يقع: صفٌّ مُشغَّلٌ ولا يظهر يقول لماذا، وآخرُ قسمٍ لا
 * يُطفأ لأنّ إطفاءه يُعيد السبعةَ كلَّها (انظر `StorePage::order` — الفراغُ
 * يعني «ما كان»)، وما لا يُكتب هنا يُقال أين يُكتب.
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

/** صفحاتُ القالب كما تردّها `StoreNav::rows` — أربعٌ كلُّها قائمة */
const PAGES = [
    { key: 'home', path: '/', fixed: true, on: true, blocked: null },
    { key: 'shop', path: '/shop', fixed: true, on: true, blocked: null },
    { key: 'about', path: '/about', fixed: false, on: true, blocked: null },
    { key: 'contact', path: '/contact', fixed: false, on: true, blocked: null },
];

const HERO = row({
    key: 'hero',
    label: 'الواجهة',
    fixed: true,
    fields: [{ key: 'store_headline', kind: 'text', label: 'العنوان الكبير' }],
});

const FOOT = row({
    key: 'foot',
    label: 'التذييل',
    fixed: true,
    fields: [{ key: 'store_tagline', kind: 'text', label: 'سطر التذييل' }],
});

const draw = (rows: EditorRow[], over: Record<string, unknown> = {}) => {
    for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    Object.assign(pageProps, { translations: {} });

    const order = rows.filter((r) => ! r.fixed && r.on).map((r) => r.key);

    return render(
        <ThemeEditor
            theme="ribbon"
            site={{ name: 'RIBBON', published: true, url: 'https://ribbon.abaadapp.om', slug: 'ribbon', host: 'abaadapp.om' }}
            /* والافتراضُ «لم يُفتح له النشر» — وهي حالُ كلّ متجرٍ قبل ترحيله */
            publishing={null}
            pages={PAGES}
            rows={rows}
            values={{ store_headline: '', store_tagline: '', store_banner_image: '' }}
            order={order}
            maxFeatured={4}
            products={[]}
            {...over}
        />,
    );
};

describe('محرّرُ صفحة المتجر', () => {
    beforeEach(() => {
        for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
        vi.mocked(router.visit).mockClear();
    });

    /**
     * ═══ والمعاينةُ ثلاثةُ مقاسات — والتابلتُ ليس زينةً بينها ═══
     *
     * شبكةُ البطاقات في قالب الواجهة تنكسر عند التابلت لا عند الهاتف: ثلاثٌ
     * في الصفّ تصير اثنتين. فمن عاين على الطرفين وحدَهما لم يرَ الحالَ التي
     * تنكسر فيها صفحتُه، ورآها زبونُه.
     *
     * ═══ ولمَ يُقاس العرضُ لا اسمُ الصنف ═══
     *
     * الحارسُ الأوّلُ كان يقرأ `className` ويطلب فيه `w-[820px]`. وكان
     * الصنفُ مكتوبًا فعلًا — ومعه `max-w-full`، وعمودُ المعاينة يقف عند
     * ٧٦٦ بكسل، فلا تسري الـ٨٢٠ أبدًا. فبقي الحارسُ أخضرَ وزرُّ «تابلت»
     * يُضيء ولا يُبدّل شيئًا: حارسٌ لا يستطيع أن يسقط ليس بحارس.
     *
     * فصار يقرأ عرضَ الإطار نفسِه — وهو ما يحكم استعلاماتِ القالب داخله.
     */
    it('ويعاين بثلاثة مقاسات — والإطارُ يأخذ عرضَ الجهاز فعلًا', () => {
        draw([HERO, FOOT]);

        const frame = () => screen.getByTestId('preview-frame') as HTMLIFrameElement;

        for (const key of ['desktop', 'tablet', 'phone']) {
            expect(screen.getByTestId(`preview-${key}`), key).toBeInTheDocument();
        }

        // ويبدأ على الكمبيوتر: أوّلُ ما يفتحه التاجرُ شاشتُه هو
        expect(screen.getByTestId('preview-desktop')).toHaveAttribute('aria-pressed', 'true');
        expect(frame().style.width).toBe('1280px');

        fireEvent.click(screen.getByTestId('preview-tablet'));
        expect(frame().style.width).toBe('820px');

        fireEvent.click(screen.getByTestId('preview-phone'));
        expect(frame().style.width).toBe('390px');
        expect(screen.getByTestId('preview-desktop')).toHaveAttribute('aria-pressed', 'false');
    });

    /**
     * والصندوقُ يأخذ من العرض بقدر المقياس.
     *
     * `transform` تُصغّر ما يُرسم ولا تُصغّر ما يشغله في التخطيط. فلولا أنّ
     * الصندوقَ يُضبط بالنسبة نفسِها لبقي تحت المعاينة وعن يسارها فراغٌ بعرض
     * الفرق — ولا أحدَ يفهم من أين جاء.
     */
    it('والصندوقُ يتبع الإطارَ مضروبًا في مقياسه', () => {
        draw([HERO, FOOT]);

        const stage = () => screen.getByTestId('preview-stage');
        const frame = () => screen.getByTestId('preview-frame') as HTMLIFrameElement;

        for (const key of ['desktop', 'tablet', 'phone'] as const) {
            fireEvent.click(screen.getByTestId(`preview-${key}`));

            const scale = Number(/scale\(([\d.]+)\)/.exec(frame().style.transform)?.[1]);
            const want = Number.parseInt(frame().style.width, 10);

            expect(Number.isFinite(scale), key).toBe(true);
            expect(stage().style.width, key).toBe(`${want * scale}px`);
        }
    });

    /**
     * ═══ وفي عمودٍ مقيسٍ يظهر التصغيرُ نفسُه ═══
     *
     * jsdom لا يرسم فلا يقيس، فـ`clientWidth` صفرٌ والمقياسُ واحدٌ دائمًا —
     * وعندها لا يفترق `want * scale` عن `want`، ولا `/ scale` عن `* scale`.
     * فيُعطى العمودُ عرضًا ليقع القياسُ الأوّلُ عليه عند التركيب.
     */
    it('وفي عمودٍ عرضُه ٥٤٠ يُصغَّر التابلتُ بنسبته', () => {
        const own = Object.getOwnPropertyDescriptor(HTMLElement.prototype, 'clientWidth');

        Object.defineProperty(HTMLElement.prototype, 'clientWidth', {
            configurable: true,
            get: () => 540,
        });

        try {
            draw([HERO, FOOT]);

            fireEvent.click(screen.getByTestId('preview-tablet'));

            const frame = screen.getByTestId('preview-frame') as HTMLIFrameElement;
            const stage = screen.getByTestId('preview-stage');
            const scale = 540 / 820;

            expect(frame.style.transform).toBe(`scale(${scale})`);

            // والصندوقُ يسع العمودَ تمامًا — لا ٨٢٠ تفيض منه
            expect(stage.style.width).toBe('540px');

            /*
                والارتفاعُ مقسومٌ ليعود بعد التصغير إلى ما يُرى — والقاسمُ
                يُقرأ لا النصُّ كلُّه: jsdom تُعيد صياغة `calc` بحذف فراغٍ.
            */
            expect(frame.style.height).toMatch(/^calc\(max\(480px, 70vh\) ?\/ ?/);
            expect(Number(/\/\s*([\d.]+)\)$/.exec(frame.style.height)?.[1])).toBeCloseTo(scale, 10);
            expect(stage.style.height).toBe('max(480px, 70vh)');
        } finally {
            if (own) Object.defineProperty(HTMLElement.prototype, 'clientWidth', own);
            else delete (HTMLElement.prototype as unknown as Record<string, unknown>).clientWidth;
        }
    });

    /** والمبدأُ يمينٌ لا يسار — الصفحةُ عربيّةٌ تبدأ من هناك */
    it('ويُصغَّر الإطارُ من يمينه', () => {
        draw([HERO, FOOT]);

        expect((screen.getByTestId('preview-frame') as HTMLIFrameElement).style.transformOrigin)
            .toBe('top right');
    });

    /**
     * ═══ ومنتقي الصفحات لا يعرض بابًا يردّ «غير موجود» ═══
     *
     * صفحاتُ القالب أربعٌ، ومنها ما يُطفئه صاحبُه وما لا محتوى له فيسقط من
     * قائمة زبونه. و`StoreNav::rows` تقول ذلك في `on` و`blocked` — وما سقط
     * عن الزبون يسقط عن المنتقي، وإلّا فتح صاحبُه بابًا على فراغ.
     */
    it('ولا يعرض المنتقي صفحةً مطفأةً ولا فارغة', () => {
        draw([HERO, FOOT], {
            pages: [
                { key: 'home', path: '/', fixed: true, on: true, blocked: null },
                { key: 'shop', path: '/shop', fixed: true, on: true, blocked: 'لا صنفَ معروضًا' },
                { key: 'about', path: '/about', fixed: false, on: false, blocked: null },
                { key: 'contact', path: '/contact', fixed: false, on: true, blocked: null },
            ],
        });

        const options = Array.from(
            screen.getByTestId('page-picker').querySelectorAll('option'),
        ).map((o) => o.textContent);

        expect(options).toEqual(['الرئيسية', 'تواصل معنا']);
    });

    /** والأربعُ القائماتُ يُعرضن كلُّهنّ بترتيب القائمة */
    it('ويعرض الأربعَ حين تكون كلُّها قائمة', () => {
        draw([HERO, FOOT]);

        const options = Array.from(
            screen.getByTestId('page-picker').querySelectorAll('option'),
        ).map((o) => o.getAttribute('value'));

        expect(options).toEqual(['home', 'shop', 'about', 'contact']);
    });

    /**
     * واختيارُ صفحةٍ ينقل إلى بابها هي.
     *
     * فمحتوى «المتجر» في المنتجات لا في أقسام هذه الصفحة — ومنتقٍ يُبدّل
     * الاسمَ ويترك الأقسامَ كما هي يُوهم صاحبَه أنّه يُحرّر ما لا يُحرَّر.
     */
    it('واختيارُ «المتجر» ينقل إلى المنتجات لا إلى أقسام الرئيسية', () => {
        draw([HERO, FOOT]);

        fireEvent.change(screen.getByTestId('page-picker'), { target: { value: 'shop' } });

        expect(router.visit).toHaveBeenLastCalledWith('/admin.products.index');
    });

    /** و«من نحن» نبذتُها قسمٌ في هذه الصفحة — فيُفتح صفُّها */
    it('و«من نحن» تفتح صفَّها في المحرّر نفسِه', () => {
        draw([HERO, FOOT]);

        fireEvent.change(screen.getByTestId('page-picker'), { target: { value: 'about' } });

        expect(vi.mocked(router.visit).mock.lastCall?.[0]).toBe('/admin.website.design/about');
    });

    /** الواجهةُ أوّلًا والتذييلُ آخرًا — وبينهما ترتيبُ صاحبه */
    it('يعرض الصفوف بترتيب ما يراه الزبون', () => {
        draw([HERO, row({ key: 'about', label: 'عنّا' }), row({ key: 'cats', label: 'الفئات' }), FOOT]);

        const keys = Array.from(document.querySelectorAll('[data-testid^="row-"]')).map((el) =>
            el.getAttribute('data-testid'),
        );

        expect(keys).toEqual(['row-hero', 'row-about', 'row-cats', 'row-foot']);
    });

    /**
     * وحقولُ الصفّ تُفتح بالضغط عليه — لا تُعرض كلُّها معًا.
     *
     * ثلاثون مقبضًا معروضةً في شاشةٍ واحدة هي الحالُ التي بُني هذا المحرّر
     * لأجلها: من أراد أن يبدّل سطرًا في تذييله لم يكن له موضعٌ يبحث فيه.
     */
    it('ولا يفتح إلّا حقولَ الصفّ الذي ضُغط', () => {
        draw([HERO, FOOT]);

        expect(screen.queryByLabelText('العنوان الكبير')).toBeNull();

        fireEvent.click(screen.getByText('الواجهة'));

        expect(screen.getByLabelText('العنوان الكبير')).toBeInTheDocument();
        expect(screen.queryByLabelText('سطر التذييل')).toBeNull();
    });

    /**
     * و«مُشغَّلٌ ولا يظهر» يُقال في صفّه.
     *
     * من شغّل «آراء الزبائن» ثمّ فتح متجره فلم يجدها يظنّ العطبَ في النظام.
     */
    it('ويقول للقسم المشتغل الذي لا يظهر لماذا', () => {
        draw([
            HERO,
            row({ key: 'reviews', label: 'آراء الزبائن', silent: 'لا رأيَ معروضًا بعد.' }),
            FOOT,
        ]);

        expect(screen.getByTestId('silent-reviews')).toHaveTextContent('لا رأيَ معروضًا بعد.');
    });

    /** ولا يُحذَّر عن صفٍّ أطفأه صاحبُه — هو يعرف لمَ أطفأه */
    it('ولا يحذّر عن قسمٍ مطفأ', () => {
        draw([
            HERO,
            row({ key: 'cats', label: 'الفئات' }),
            row({ key: 'reviews', label: 'آراء الزبائن', on: false, silent: null }),
            FOOT,
        ]);

        expect(screen.queryByTestId('silent-reviews')).toBeNull();
    });

    /**
     * ═══ ولا يُطفأ آخرُ قسم ═══
     *
     * قائمةٌ فارغةٌ تُقرأ «ما كان» في `StorePage::order` فتعود السبعةُ كلُّها.
     * فمن أطفأ آخرَ قسمٍ يراها ترجع — والمنعُ مع سببه أصدقُ من تركه يُجرّب.
     */
    it('ويمنع إطفاء آخر قسمٍ ويقول لماذا', () => {
        draw([HERO, row({ key: 'cats', label: 'الفئات' }), row({ key: 'about', label: 'عنّا', on: false }), FOOT]);

        expect(screen.getByRole('switch', { name: 'الفئات' })).toBeDisabled();
        expect(screen.getByTestId('last-section')).toBeInTheDocument();
    });

    /** واثنان مشتغلان يُطفأ أيُّهما شاء */
    it('واثنان مشتغلان يُطفأ أيُّهما', () => {
        draw([HERO, row({ key: 'cats', label: 'الفئات' }), row({ key: 'about', label: 'عنّا' }), FOOT]);

        expect(screen.getByRole('switch', { name: 'الفئات' })).toBeEnabled();
        expect(screen.queryByTestId('last-section')).toBeNull();
    });

    /** والواجهةُ والتذييلُ هويّةُ الصفحة لا قسمًا يُطفأ — فلا عينَ لهما */
    it('ولا يُطفئ الواجهة ولا التذييل', () => {
        draw([HERO, row({ key: 'cats', label: 'الفئات' }), FOOT]);

        expect(within(screen.getByTestId('row-hero')).queryByRole('switch')).toBeNull();
        expect(within(screen.getByTestId('row-foot')).queryByRole('switch')).toBeNull();
    });

    /**
     * وما لا يُكتب هنا يُقال أين يُكتب.
     *
     * وحقلٌ يُنسخ إلى المحرّر يعني عمودًا يُكتب من بابين — فيُشار إلى بابه.
     */
    it('ويُشير إلى باب المحتوى الذي لا يُكتب فيه', () => {
        draw([
            HERO,
            row({ key: 'cats', label: 'الفئات', source: { label: 'الأصناف والفئات', route: 'admin.products.index' } }),
            FOOT,
        ]);

        fireEvent.click(screen.getByText('الفئات'));

        expect(screen.getByRole('link', { name: /الأصناف والفئات/ })).toHaveAttribute(
            'href',
            '/admin.products.index',
        );
    });

    /** ومتجرٌ لم يُنشر يُحرَّر — فمن يجهّزه يرتّب صفحتَه قبل أن يفتحها */
    it('ويُفتح لمتجرٍ لم يُنشر بعد', () => {
        draw([HERO, FOOT], { site: { name: 'RIBBON', published: false, url: null, slug: null, host: 'abaadapp.om' } });

        expect(screen.getByText(/متجرك لا يفتحه أحدٌ بعد/)).toBeInTheDocument();
        expect(screen.getByText('الواجهة')).toBeInTheDocument();
    });
});
