import { fireEvent } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { Site } from '@/site/Site';
import { ProductView } from '@/site/ProductView';
import { LAYOUT_DEFAULTS, layoutOf, variant } from '@/site/layout';
import { clone, draw, homeOf, sectionOf, store, withSections } from './helpers';
import type { DocProduct, DocSection, SiteDocument, Tokens } from '@/site/types';

/**
 * القالبُ بنيةٌ لا لوحةُ ألوان.
 *
 * وهذا الملفّ هو ما يمنع عودةَ ما كان: قوالبُ تختلف ألوانُها ولا يختلف
 * شيءٌ آخر. فهو يسأل عن **الوسم** لا عن اللون — أترسم الترويسةُ التجارية
 * شريطَ أقسامٍ ثانيًا؟ أتضع الواجهةُ المشطورة عمودين؟ أتختلف بطاقةُ المنتج
 * في القوالب الأربعة؟ — ولو صارت القوالبُ يومًا ألوانًا فقط لسقط هنا.
 */

/** رموزٌ كاملة: ألوانُ المستند كما هي، وتخطيطٌ يُملى في الاختبار */
function withTokens(doc: SiteDocument, layout: Partial<Tokens>): SiteDocument {
    const next = clone(doc);

    next.tokens = { ...next.tokens, ...layout } as Tokens;

    return next;
}

const html = (doc: SiteDocument) => draw(<Site doc={doc} mode="live" />).container.innerHTML;

describe('رموز التخطيط', () => {
    it('مستندٌ بلا رموز تخطيط يأخذ الافتراضيّ — وهو رسمُ ما قبل الطبقة', () => {
        expect(layoutOf({ tokens: store.tokens })).toEqual(LAYOUT_DEFAULTS);
    });

    it('قيمةٌ لا يعرفها النظام لا تُرسم شيئًا غريبًا — تعود إلى افتراضيّها', () => {
        expect(layoutOf({ tokens: { ...store.tokens, hero: 'قالبٌ لا وجود له' } as Tokens }).hero).toBe('classic');
    });

    it('«شبكة صور» القديمة تُقرأ أغلفةً — اسمٌ تغيّر عندنا لا عند التاجر', () => {
        const section = { type: 'categories', visible: true, source: null, data: { style: 'grid' } } as DocSection;

        expect(variant(section, 'categories', 'cards', 'style')).toBe('covers');
    });

    it('اختيارُ القسم يعلو على قالبه، و«auto» تتبع القالب', () => {
        const chosen = { type: 'hero', visible: true, source: null, data: { layout: 'split' } } as DocSection;
        const auto = { type: 'hero', visible: true, source: null, data: { layout: 'auto' } } as DocSection;

        expect(variant(chosen, 'hero', 'editorial')).toBe('split');
        expect(variant(auto, 'hero', 'editorial')).toBe('editorial');
    });

    it('الكثافةُ والسلّم يخرجان سمتين على جذر الموقع — تقرؤهما الأنماط', () => {
        const doc = withTokens(store, { density: 'spacious', scale: 'editorial' });
        const { container } = draw(<Site doc={doc} mode="live" />);
        const root = container.querySelector('.w-site')!;

        expect(root.getAttribute('data-density')).toBe('spacious');
        expect(root.getAttribute('data-scale')).toBe('editorial');
    });
});

describe('الترويسة تتبدّل بنيتُها', () => {
    const headerDoc = (header: string, extra: Partial<DocSection['data']> = {}) => {
        const doc = withTokens(store, { header });
        const slot = doc.globals.find((g) => g.slot === 'header')!;

        slot.data = { ...slot.data, ...extra };

        return doc;
    };

    it('التجارية سطران: شعارٌ فوق وشريطُ أقسامٍ تحته', () => {
        const { container } = draw(<Site doc={headerDoc('commerce')} mode="live" />);

        expect(container.querySelector('header nav.w-rail')).not.toBeNull();
    });

    it('البسيطة سطرٌ واحد بلا شريط', () => {
        const { container } = draw(<Site doc={headerDoc('minimal')} mode="live" />);

        expect(container.querySelector('header nav.w-rail')).toBeNull();
        expect(container.querySelector('header nav.w-nav-desktop')).not.toBeNull();
    });

    it('البحث لا يُرسم إلّا حين يُطلب — ويذهب إلى صفحة المتجر لأنّه يعمل', () => {
        const off = headerDoc('commerce', { show_search: false });

        expect(draw(<Site doc={off} mode="live" />).container.querySelector('input[name="q"]')).toBeNull();

        const { container } = draw(<Site doc={headerDoc('commerce', { show_search: true })} mode="live" />);
        const form = container.querySelector('header form[role="search"]');

        expect(form).not.toBeNull();
        expect(form!.getAttribute('action')).toBe('/shop');
    });

    it('وفي المعاينة ليس نموذجًا — لئلّا يخرج التاجر من محرّره', () => {
        const { container } = draw(<Site doc={headerDoc('commerce', { show_search: true })} mode="edit" />);

        expect(container.querySelector('header form')).toBeNull();
        expect(container.querySelector('header input[name="q"]')).not.toBeNull();
    });
});

describe('الواجهة الرئيسية خمسةُ بناءات', () => {
    const heroDoc = (hero: string) => {
        const doc = withTokens(store, { hero });

        return withSections(doc, [sectionOf(store, 'hero')]);
    };

    it('المشطورةُ عمودان، والتحريريّةُ عنوانُ عرض، والكلاسيكيةُ طبقةُ تعتيم', () => {
        expect(draw(<Site doc={heroDoc('split')} mode="live" />).container.querySelector('.w-hero-split')).not.toBeNull();
        expect(
            draw(<Site doc={heroDoc('editorial')} mode="live" />).container.querySelector('main h1.w-display'),
        ).not.toBeNull();
        expect(draw(<Site doc={heroDoc('classic')} mode="live" />).container.querySelector('.w-hero-split')).toBeNull();
    });

    it('الخمسةُ لا يتشابه اثنان منها في وسمها', () => {
        const shapes = ['classic', 'centered', 'split', 'editorial', 'showcase'];
        const drawn = shapes.map((shape) => html(heroDoc(shape)));

        expect(new Set(drawn).size).toBe(shapes.length);
    });

    it('وعنوانُ الواجهة يبقى h1 واحدةً في كلّ بناء', () => {
        for (const shape of ['classic', 'centered', 'split', 'editorial', 'showcase']) {
            const { container } = draw(<Site doc={heroDoc(shape)} mode="live" />);

            expect(container.querySelectorAll('h1').length).toBe(1);
        }
    });
});

describe('بطاقة المنتج والشبكة', () => {
    const productsDoc = (tokens: Partial<Tokens>) => {
        const doc = withTokens(store, tokens);

        return withSections(doc, [sectionOf(store, 'featured_products')]);
    };

    it('الأشكالُ الخمسة لا يتشابه اثنان منها', () => {
        const drawn = ['plain', 'soft', 'commerce', 'editorial', 'bare'].map((card) => html(productsDoc({ card })));

        expect(new Set(drawn).size).toBe(5);
    });

    /**
     * الخصمُ يُقال في الشكلين، ويُقال بطريقتين.
     *
     * التجاريّةُ تصرخ به: شارةٌ ملوّنة فوق الصورة. والتحريريّةُ تقوله كلمةً
     * فوق الاسم — لأنّ بقعةً ملوّنة فوق صورةٍ كبيرة تكسر هدوءَ القالب كلِّه.
     * والمُخفى هو **الشارة** لا الخبر: زبونٌ لا يعرف أنّ الصنف مخفَّض خسارةٌ
     * للتاجر مهما كان قالبُه أنيقًا.
     */
    it('الخصمُ شارةٌ في التجاريّة وكلمةٌ في التحريريّة — ولا يغيب عن أيّهما', () => {
        const withSale = (card: string) => {
            const doc = productsDoc({ card });
            const section = homeOf(doc).sections[0];

            section.items = [
                {
                    id: 1,
                    name: 'باقة ورد',
                    excerpt: '',
                    price: 10,
                    was: 10,
                    final: 8,
                    image: null,
                    category_id: null,
                } satisfies DocProduct,
            ];

            const { container } = draw(<Site doc={doc} mode="live" />);

            return {
                text: container.textContent ?? '',
                // داخل البطاقة وحدها: عناوينُ أعمدة التذييل سطورٌ فوقيّةٌ أيضًا
                eyebrows: container.querySelectorAll('.w-card .w-eyebrow').length,
            };
        };

        const commerce = withSale('commerce');
        const editorial = withSale('editorial');

        expect(commerce.text).toContain('20٪');
        expect(commerce.eyebrows).toBe(0);

        expect(editorial.text).toContain('خصم 20٪');
        expect(editorial.eyebrows).toBeGreaterThan(0);
    });

    it('الشبكةُ التحريرية تُعلّم أوّلَ بطاقةٍ لتكبر — والكلاسيكيةُ لا', () => {
        expect(
            draw(<Site doc={productsDoc({ grid: 'editorial' })} mode="live" />).container.querySelector(
                '.w-grid-editorial',
            ),
        ).not.toBeNull();
        expect(
            draw(<Site doc={productsDoc({ grid: 'classic' })} mode="live" />).container.querySelector(
                '.w-grid-editorial',
            ),
        ).toBeNull();
    });
});

describe('التصنيفات والتذييل', () => {
    it('أشكالُ التصنيفات الخمسة لا يتشابه اثنان منها', () => {
        const drawn = ['cards', 'pills', 'covers', 'tiles', 'list'].map((categories) => {
            const doc = withTokens(store, { categories });

            return html(withSections(doc, [sectionOf(store, 'categories')]));
        });

        expect(new Set(drawn).size).toBe(5);
    });

    it('التذييلُ أربعةُ أشكال — والنحيفُ سطرٌ لا أعمدة', () => {
        const drawn = ['columns', 'minimal', 'brand', 'split'].map((footer) => html(withTokens(store, { footer })));

        expect(new Set(drawn).size).toBe(4);
    });
});

describe('القوالبُ الأربعة تُرى أربعةً', () => {
    /** رموزُ القوالب الأربعة كما يكتبها `App\\Support\\Website\\Templates` */
    const TEMPLATES: Record<string, Partial<Tokens>> = {
        atelier: {
            width: 'wide',
            density: 'spacious',
            scale: 'editorial',
            heading: 'editorial',
            header: 'editorial',
            hero: 'editorial',
            card: 'editorial',
            grid: 'large',
            ratio: 'portrait',
            categories: 'tiles',
            footer: 'brand',
            surface_style: 'flat',
            heading_font: 'amiri',
        },
        market: {
            width: 'wide',
            density: 'compact',
            scale: 'compact',
            heading: 'start',
            header: 'commerce',
            hero: 'showcase',
            card: 'commerce',
            grid: 'dense',
            ratio: 'square',
            categories: 'pills',
            footer: 'columns',
            surface_style: 'bordered',
        },
        bloom: {
            width: 'normal',
            density: 'balanced',
            scale: 'editorial',
            heading: 'start',
            header: 'centered',
            hero: 'split',
            card: 'soft',
            grid: 'editorial',
            ratio: 'landscape',
            categories: 'covers',
            footer: 'split',
            surface_style: 'raised',
        },
        mono: {
            width: 'narrow',
            density: 'spacious',
            scale: 'balanced',
            heading: 'start',
            header: 'minimal',
            hero: 'centered',
            card: 'plain',
            grid: 'classic',
            ratio: 'portrait',
            categories: 'list',
            footer: 'minimal',
            surface_style: 'flat',
        },
    };

    it('لا يتشابه قالبان في وسم الصفحة الرئيسية كلِّها', () => {
        const drawn = Object.values(TEMPLATES).map((tokens) => html(withTokens(store, tokens)));

        expect(new Set(drawn).size).toBe(4);
    });

    it('ولا في ترويسته ولا في واجهته ولا في تذييله — كلٌّ على حدة', () => {
        const part = (tokens: Partial<Tokens>, selector: string) =>
            draw(<Site doc={withTokens(store, tokens)} mode="live" />).container.querySelector(selector)?.innerHTML ??
            '';

        // و`.w-site > footer` لا `footer`: «آراء العملاء» فيها `footer` لكلّ اقتباس
        for (const selector of ['header', 'main > div:first-child', '.w-site > footer']) {
            const drawn = Object.values(TEMPLATES).map((tokens) => part(tokens, selector));

            expect(new Set(drawn).size, `${selector} يتشابه في قالبين`).toBe(4);
        }
    });

    it('وكلُّها تبقى موقعًا واحدًا: ترويسةٌ ومتنٌ وتذييلٌ وh1 واحدة', () => {
        for (const [name, tokens] of Object.entries(TEMPLATES)) {
            const { container } = draw(<Site doc={withTokens(store, tokens)} mode="live" />);

            expect(container.querySelector('header'), name).not.toBeNull();
            expect(container.querySelector('main'), name).not.toBeNull();
            expect(container.querySelector('footer'), name).not.toBeNull();
            expect(container.querySelectorAll('h1').length, name).toBe(1);
        }
    });
});

describe('صفحة المتجر', () => {
    const catalogDoc = () => {
        const doc = clone(store);
        const products = doc.data!.products!;
        const section: DocSection = {
            type: 'product_catalog',
            visible: true,
            source: 'products',
            data: { title: 'كلّ المنتجات', columns: '4', layout: 'auto' },
            items: products,
        };

        return { doc: withSections(doc, [section]), products };
    };

    it('تعرض الكتالوج كلَّه وعدده، ومعه بحثٌ وترتيب', () => {
        const { doc, products } = catalogDoc();
        const { container } = draw(<Site doc={doc} mode="live" />);

        expect(container.querySelectorAll('main article').length).toBe(products.length);
        expect(container.textContent).toContain(`${products.length}`);
        expect(container.querySelector('main input[type="search"]')).not.toBeNull();
        expect(container.querySelector('main select')).not.toBeNull();
    });

    it('والبحثُ يرشّح ما يُعرض فعلًا', () => {
        const { doc, products } = catalogDoc();
        const { container } = draw(<Site doc={doc} mode="live" />);
        const box = container.querySelector('main input[type="search"]') as HTMLInputElement;

        fireEvent.change(box, { target: { value: products[0].name } });

        const shown = [...container.querySelectorAll('main article h3')].map((h) => h.textContent);

        expect(shown).toContain(products[0].name);
        expect(shown.length).toBeLessThanOrEqual(products.length);
    });

    it('وكلمةٌ لا يبيعها أحدٌ تقول ذلك بدل شبكةٍ فارغة', () => {
        const { doc } = catalogDoc();
        const { container } = draw(<Site doc={doc} mode="live" />);

        fireEvent.change(container.querySelector('main input[type="search"]') as HTMLInputElement, {
            target: { value: 'مركبةٌ فضائية' },
        });

        expect(container.querySelectorAll('main article').length).toBe(0);
        expect(container.textContent).toContain('لا منتج يطابق بحثك');
    });

    it('ولا تُعرض أقسامٌ لا منتجَ لها في هذه الصفحة', () => {
        const { doc } = catalogDoc();
        const home = homeOf(doc);

        home.sections[0].items = (home.sections[0].items as DocProduct[]).map((p) => ({ ...p, category_id: 1 }));

        const { container } = draw(<Site doc={doc} mode="live" />);
        const chips = [...container.querySelectorAll('main button[aria-pressed]')].map((b) => b.textContent);

        expect(chips).toContain('باقات');
        expect(chips).not.toContain('نباتات داخلية');
    });
});

describe('صفحة المنتج — تصميمُها جاهز', () => {
    it('تعرض الاسم والسعر وزرَّ الطلب والمقترحات', () => {
        const products = store.data!.products!;
        const doc = withSections(store, []);
        const { container } = draw(
            <Site doc={doc} mode="live">
                <ProductView product={products[0]} doc={doc} mode="live" related={products.slice(1)} />
            </Site>,
        );

        expect(container.textContent).toContain(products[0].name);
        expect(container.querySelector('main a[href^="https://wa.me/"]')).not.toBeNull();
        expect(container.querySelectorAll('main article').length).toBe(products.length - 1);
    });

    it('ولا تُظهر ثمنًا لمن أطفأ الأسعار', () => {
        const doc = withSections(store, []);
        const product = doc.data!.products![0];

        doc.commerce = { show_prices: false, allow_orders: false };

        const { container } = draw(
            <Site doc={doc} mode="live">
                <ProductView product={product} doc={doc} mode="live" />
            </Site>,
        );

        expect(container.textContent).toContain(product.name);
        expect(container.textContent).not.toContain(String(product.final));
    });
});

/**
 * ═══════════════════ الصنعة ═══════════════════
 *
 * ما سبق يحرس أن تكون القوالبُ **مختلفة**. وهذا يحرس أن تكون **جيّدة**:
 * أن تُرسم الواجهةُ بلا صورةٍ تصميمًا لا نقصًا، وأن يكون لغلاف التصنيف صورةٌ
 * حقيقيّة، وألّا يعود تباعدُ الحروف إلى نصٍّ عربيّ.
 */
describe('الصنعة — لا فراغَ ولا زينةَ كاذبة', () => {
    /*
     * وصفحاتُ الموقع تبقى كما هي.
     *
     * `withSections` تُبقي صفحةً واحدة، فتختفي صفحةُ المتجر — ورابطُ التصنيف
     * يقصدها. فما يُبدَّل هنا أقسامُ الرئيسية وحدها، ويبقى الموقعُ موقعًا.
     */
    const onHome = (doc: SiteDocument, sections: DocSection[]) => {
        const next = clone(doc);

        homeOf(next).sections = sections;

        return next;
    };

    const firstSection = (doc: SiteDocument) =>
        draw(<Site doc={doc} mode="live" />).container.querySelector('main section')!;

    const heroDocWith = (data: Record<string, unknown>, tokens: Partial<Tokens> = {}) => {
        const hero = sectionOf(store, 'hero');

        hero.data = { ...hero.data, ...data };

        return onHome(withTokens(store, tokens), [hero]);
    };

    it('الواجهةُ بلا صورةٍ سطحٌ مقصود — لا أيقونةٌ في مستطيلٍ رماديّ', () => {
        for (const hero of ['classic', 'editorial', 'showcase', 'split'] as const) {
            const first = firstSection(heroDocWith({ image: '' }, { hero }));
            // في بعضها السطحُ هو القسم نفسُه، وفي بعضها خانةٌ داخله
            const washed = first.matches('.w-wash') || first.querySelector('.w-wash') !== null;

            expect(washed, hero).toBe(true);
            // ولا أيقونةَ كيسٍ ولا أيَّ رسمٍ آخرَ يقوم مقام الصورة
            expect(first.querySelectorAll('svg').length, hero).toBe(0);
            expect(first.querySelectorAll('img').length, hero).toBe(0);
        }
    });

    it('السطرُ الفوقيّ لا يُخترع — يُرسم إن كتبه التاجر وحده', () => {
        expect(firstSection(heroDocWith({ eyebrow: '' })).querySelector('.w-eyebrow')).toBeNull();
        expect(firstSection(heroDocWith({ eyebrow: 'مجموعة الأعياد' })).textContent).toContain('مجموعة الأعياد');
    });

    /* ------------------------------ التصنيفات ------------------------------ */

    const categoriesDoc = (style: string, image: string | null) => {
        const section = sectionOf(store, 'categories');

        section.data = { ...section.data, style };
        section.items = [
            { id: 1, name: 'باقات', icon: '', color: 'primary', image },
            { id: 2, name: 'مناسبات', icon: '', color: 'primary', image },
        ];

        return onHome(store, [section]);
    };

    it('غلافُ التصنيف صورةُ صنفٍ فيه — لا بلاطةُ لون', () => {
        for (const style of ['tiles', 'covers', 'cards', 'pills', 'list'] as const) {
            const shots = [...firstSection(categoriesDoc(style, '/storage/bouquet.jpg')).querySelectorAll('img')].map(
                (i) => i.getAttribute('src'),
            );

            expect(shots, style).toContain('/storage/bouquet.jpg');
        }
    });

    it('وما لا صنفَ مصوَّرَ فيه يأخذ السطحَ نفسه — لا فراغًا', () => {
        const section = firstSection(categoriesDoc('tiles', null));

        expect(section.querySelectorAll('img').length).toBe(0);
        expect(section.querySelectorAll('.w-wash').length).toBeGreaterThan(0);
        expect(section.textContent).toContain('باقات');
    });

    it('التصنيفُ يُنقر إلى صفحة المتجر مرشَّحًا باسمه — لا زرٌّ لا يفتح شيئًا', () => {
        const href = firstSection(categoriesDoc('covers', null))
            .querySelector('a[href*="q="]')
            ?.getAttribute('href');

        expect(href).toBe(`/shop?q=${encodeURIComponent('باقات')}`);
    });

    /* ------------------------------ الإيقاع ------------------------------ */

    it('إيقاعُ القسم يتبع شبكته — الكبيرةُ تتنفّس والكثيفةُ تتراصّ', () => {
        const padOf = (grid: string) =>
            firstSection(onHome(withTokens(store, { grid }), [sectionOf(store, 'featured_products')]))
                .getAttribute('style') ?? '';

        expect(padOf('large')).toContain('--w-pad-loose');
        expect(padOf('dense')).not.toContain('--w-pad-loose');
    });

    it('السلّمان الجديدان يصلان جذرَ الموقع، والمجهولُ يعود إلى المتوازن', () => {
        const scaleOf = (scale: string) =>
            draw(<Site doc={withTokens(store, { scale })} mode="live" />)
                .container.querySelector('.w-site')!
                .getAttribute('data-scale');

        expect(scaleOf('display')).toBe('display');
        expect(scaleOf('precise')).toBe('precise');
        expect(scaleOf('سلّمٌ لا وجود له')).toBe('balanced');
    });

    it('لونُ العلامة لا يملأ قسمًا في كلّ قالب — بل حيث يكون من هيئته', () => {
        const promoOf = (surface_style: string) =>
            firstSection(onHome(withTokens(store, { surface_style }), [sectionOf(store, 'promo')]))
                .getAttribute('style') ?? '';

        // المحدَّدُ والمرفوعُ يملآن — وهما ما كان عليه الافتراضيّ
        expect(promoOf('bordered')).toContain('var(--w-primary)');
        expect(promoOf('raised')).toContain('var(--w-primary)');
        // والمسطَّحُ يترك اللونَ لزرِّه
        expect(promoOf('flat')).not.toContain('var(--w-primary)');
    });

    it('البطاقةُ العارية تطلب بسطرٍ تحته خطّ، والتجاريّةُ بزرٍّ ممتلئ', () => {
        const cardOf = (card: string) =>
            firstSection(onHome(withTokens(store, { card }), [sectionOf(store, 'featured_products')]))
                .querySelector('.w-card')?.innerHTML ?? '';

        expect(cardOf('bare')).toContain('border-bottom');
        expect(cardOf('commerce')).toContain('--w-btn-bg');
        expect(cardOf('commerce')).not.toContain('border-bottom');
    });
});

/**
 * حارسُ الخطّ العربيّ.
 *
 * `letter-spacing` هو ما تفعله المواقع الإنجليزية في السطور الصغيرة، وهو في
 * العربية عطب: الخطُّ متّصل، فالمسافةُ المقحمة تُباعد حرفين موصولين وتُبقي
 * وصلتَهما ممدودة — تُقرأ الكلمةُ ممزّقة. وقد دخل مرّةً في الترويسة وفي
 * بطاقة المنتج، ولا يظهر في اختبارٍ يسأل عن الوسم لأنّه يُقرأ بالعين لا
 * بالاستعلام. فيُسأل عنه في مصدره.
 */
describe('حارسُ الخطّ العربيّ', () => {
    it('لا تباعدَ حروفٍ مكتوبًا في أيّ مكوّنٍ من مكوّنات العارض', async () => {
        const { readFileSync, readdirSync } = await import('node:fs');
        const { join } = await import('node:path');
        const dir = join(process.cwd(), 'src/site');

        const guilty = readdirSync(dir)
            .filter((f) => f.endsWith('.tsx') || f.endsWith('.ts'))
            .filter((f) => readFileSync(join(dir, f), 'utf8').includes('letterSpacing'));

        expect(guilty).toEqual([]);
    });
});
