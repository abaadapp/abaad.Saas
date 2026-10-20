import { describe, expect, it } from 'vitest';

import { blankPage, convertKind, type CustomPage, parsePages, serializePages, withoutEmptyBlocks } from '@/Pages/Admin/Website/theme/pages';
import { matches, normalize } from '@/Pages/Admin/Website/theme/ProductPicker';
import { safeUrl } from '@/Pages/Admin/Website/theme/StoreTargets';

/**
 * الصفحةُ من وحدات — وقواعدُ المحرّر التي تُرآة الخادم (`Store\StoreCustomPages`).
 *
 * ═══ وما يُحرَس ═══
 *
 *  • صفحةٌ كُتبت قبل الوحدات (صورةٌ رئيسيّةٌ وأقسامٌ وزرّ — #133) تُقرأ وحداتٍ بالترتيب نفسِه.
 *  • وتبديلُ النوع يُبقي ما يناسب ويحوّل ما يتحوّل، ويعدّ ما يضيع ليُسأل عنه.
 *  • والوحدةُ الفارغةُ لا تُرسَل، والرابطُ المكتوبُ آمنٌ وحده، والبحثُ بلغتين.
 */

const page = (over: Partial<CustomPage> = {}): CustomPage => ({
    slug: 'p', kind: 'mixed', title_ar: 'صفحة', title_en: '', intro_ar: '', intro_en: '', blocks: [], visible: true, ...over,
});

describe('ما كُتب قبل الوحدات', () => {
    it('صورةٌ رئيسيّةٌ وأقسامٌ وزرّ ← وحداتٌ بالترتيب نفسِه، ونوعُها «كتابة وصور»', () => {
        const [p] = parsePages(JSON.stringify([{
            slug: 'services', title_ar: 'خدماتنا', title_en: '', intro_ar: 'نبذة', intro_en: '',
            image: '/storage/website/1/hero.jpg', fit: 'contain',
            sections: [{ image: '', title_ar: 'التنسيق', title_en: '', body_ar: 'نص', body_en: '' }],
            cta: { label_ar: 'تسوّق', label_en: '', target: { type: 'shop', value: '' } },
            visible: true,
        }]));

        expect(p.kind).toBe('textimage');
        expect(p.blocks.map((b) => b.type)).toEqual(['image', 'textimage', 'button']);
        expect(p.blocks[1]).toMatchObject({ layout: 'side', title_ar: 'التنسيق' });
    });

    it('والعابثُ يسقط ولا يُسقط الشاشة', () => {
        expect(parsePages('{oops')).toEqual([]);
        expect(parsePages(JSON.stringify([{ slug: 'x', kind: 'nope', blocks: [{ type: 'script' }, { type: 'text', body_ar: 'ن' }] }]))[0])
            .toMatchObject({ kind: 'mixed', blocks: [{ type: 'text', body_ar: 'ن' }] });
        expect(serializePages([])).toBe('');
    });
});

describe('تبديلُ نوع الصفحة', () => {
    it('قسمُ النصّ والصورة في «كتابة» عنوانٌ وفقرة — وصورتُه تُعدّ', () => {
        const { page: next, lost } = convertKind(page({
            blocks: [{ type: 'textimage', image: '/a.png', layout: 'side', title_ar: 'عن', title_en: '', body_ar: 'نص', body_en: '' }],
        }), 'text');

        expect(next.kind).toBe('text');
        expect(next.blocks.map((b) => b.type)).toEqual(['heading', 'text']);
        expect(lost).toEqual(['image']);
    });

    it('والعنوانُ والفقرةُ في «كتابة وصور» قسمان بلا صورة — لا يضيع حرف', () => {
        const { page: next, lost } = convertKind(page({
            kind: 'text',
            blocks: [{ type: 'heading', text_ar: 'عن', text_en: '' }, { type: 'text', body_ar: 'نص', body_en: '' }],
        }), 'textimage');

        expect(next.blocks.map((b) => b.type)).toEqual(['textimage', 'textimage']);
        expect(lost).toEqual([]);
    });

    it('والمنتجاتُ لا تُعرض في «صور» — تُعدّ، والزرُّ يبقى في كلّ نوع', () => {
        const { page: next, lost } = convertKind(page({
            blocks: [
                { type: 'products', title_ar: '', title_en: '', products: [1] },
                { type: 'button', label_ar: 'تسوّق', label_en: '', target: { type: 'shop', value: '' } },
            ],
        }), 'images');

        expect(next.blocks.map((b) => b.type)).toEqual(['button']);
        expect(lost).toEqual(['products']);
    });

    it('و«محتوى متنوّع» يقبل كلَّ شيء', () => {
        const blocks = page({ kind: 'images', blocks: [{ type: 'gallery', images: ['/a.png'], fit: 'contain' }] });

        expect(convertKind(blocks, 'mixed').lost).toEqual([]);
    });
});

describe('ما يُرسَل', () => {
    it('الوحدةُ الفارغةُ لا تُرسَل — وزرُّ العرض بلا نصٍّ بلا وجهة', () => {
        const raw = serializePages([page({
            blocks: [
                { type: 'heading', text_ar: '', text_en: '' },
                { type: 'text', body_ar: 'فقرة', body_en: '' },
                { type: 'promo', image: '', title_ar: 'عرض', title_en: '', body_ar: '', body_en: '', label_ar: '', label_en: '', target: { type: 'shop', value: '' } },
            ],
        })]);

        const [p] = parsePages(withoutEmptyBlocks(raw));
        expect(p.blocks.map((b) => b.type)).toEqual(['text', 'promo']);
        expect(p.blocks[1]).toMatchObject({ target: null });
    });

    it('والصفحةُ الجديدةُ بنوعها واسمها — ورابطُها من اسمها الإنجليزيّ', () => {
        expect(blankPage(['our-services'], { ar: 'خدماتنا', en: 'Our services' }, 'text')).toMatchObject({
            slug: 'our-services-2', kind: 'text', title_ar: 'خدماتنا', blocks: [],
        });
    });

    it('والرابطُ المكتوبُ آمنٌ وحده — كقاعدة الخادم', () => {
        expect(safeUrl('https://instagram.com/ribbon')).toBe('https://instagram.com/ribbon');
        expect(safeUrl('/shop?cat=3')).toBe('/shop?cat=3');
        expect(safeUrl('javascript:alert(1)')).toBeNull();
        expect(safeUrl('//evil.example')).toBeNull();
        expect(safeUrl('https://a.example/x y')).toBeNull();
        expect(safeUrl('data:text/html,x')).toBeNull();
    });
});

describe('البحثُ بلغتين', () => {
    const rose = { id: 1, name: 'باقة وَرْد أحمر', name_en: 'Red Roses', category: 'باقات', image: null };

    it('بالعربيّة بلا تشكيلٍ ولا همزات، وبالإنجليزيّة بلا حالة الحروف، وبالفئة', () => {
        expect(normalize('أَحْمَر')).toBe('احمر');
        expect(matches(rose, 'ورد')).toBe(true);
        expect(matches(rose, 'roses')).toBe(true);
        expect(matches(rose, 'red باقة')).toBe(true);
        expect(matches(rose, 'باقات')).toBe(true);
        expect(matches(rose, 'tulip')).toBe(false);
    });
});
