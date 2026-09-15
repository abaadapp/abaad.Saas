import { describe, expect, it } from 'vitest';
import { expired, hasContent } from '@/site/content';
import { mapEmbed, videoEmbed, vimeoId, youtubeId } from '@/site/embed';
import { money } from '@/site/money';
import { whatsappUrl } from '@/site/commerce';
import { clone, sectionOf, store } from './helpers';
import type { DocSection } from '@/site/types';

const bare = (type: string, data: Record<string, unknown> = {}): DocSection => ({
    type,
    visible: true,
    source: null,
    data,
});

describe('ما فيه شيءٌ يُعرض', () => {
    it('قسمٌ يقرأ من النظام ولم يجد شيئًا لا يُعرض', () => {
        const section = sectionOf(store, 'featured_products');

        section.items = [];
        expect(hasContent(section, store)).toBe(false);

        section.items = [{ id: 1, name: 'x', excerpt: '', price: 1, was: null, final: 1, image: null, category_id: null }];
        expect(hasContent(section, store)).toBe(true);
    });

    it('معرضٌ بصفوفٍ فارغة لا يُعدّ صورًا', () => {
        expect(hasContent(bare('gallery', { images: [{ src: '', alt: '' }] }), store)).toBe(false);
        expect(hasContent(bare('gallery', { images: [{ src: 'https://x/1.jpg' }] }), store)).toBe(true);
    });

    it('أسئلةٌ بلا أجوبة لا تُعرض', () => {
        expect(hasContent(bare('faq', { items: [{ q: 'س', a: '' }] }), store)).toBe(true);
        expect(hasContent(bare('faq', { items: [{ q: '', a: '' }] }), store)).toBe(false);
    });

    it('واتساب بلا رقمٍ صالح لا يُعرض', () => {
        const empty = clone(store);

        empty.brand!.whatsapp = '';
        expect(hasContent(bare('whatsapp', { number: '' }), empty)).toBe(false);
        expect(hasContent(bare('whatsapp', { number: '968 9000 0000' }), empty)).toBe(true);
    });

    it('تواصلٌ يسقط على بيانات النشاط فيبقى معروضًا', () => {
        expect(hasContent(bare('contact', {}), store)).toBe(true);
    });

    it('الواجهة الرئيسية تُعرض دائمًا — لها اسم المتجر بديلًا', () => {
        expect(hasContent(bare('hero', {}), store)).toBe(true);
    });

    it('عرضٌ انتهى تاريخُه لا يُعرض', () => {
        expect(expired(bare('promo', { ends_at: '2020-01-01' }))).toBe(true);
        expect(expired(bare('promo', { ends_at: '2099-01-01' }))).toBe(false);
        expect(expired(bare('promo', { ends_at: '' }))).toBe(false);
        expect(expired(bare('promo', { ends_at: 'ليس تاريخًا' }))).toBe(false);
        expect(expired(bare('banner', { ends_at: '2020-01-01' }))).toBe(false);
    });
});

describe('روابط التاجر تصير إطارات', () => {
    it('يوتيوب بكلّ صيغه', () => {
        for (const url of [
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'https://youtu.be/dQw4w9WgXcQ',
            'https://www.youtube.com/embed/dQw4w9WgXcQ',
            'https://www.youtube.com/shorts/dQw4w9WgXcQ',
            'https://www.youtube.com/watch?list=X&v=dQw4w9WgXcQ',
        ]) {
            expect(youtubeId(url), url).toBe('dQw4w9WgXcQ');
        }
    });

    it('يوتيوب يُعرض بلا كعكات تتبّع', () => {
        const embed = videoEmbed('https://youtu.be/dQw4w9WgXcQ', 'فيديو');

        expect(embed).toMatchObject({ kind: 'iframe' });
        expect(embed && 'src' in embed && embed.src).toContain('youtube-nocookie.com/embed/dQw4w9WgXcQ');
    });

    it('فيميو ومقاطع الملفّات', () => {
        expect(vimeoId('https://vimeo.com/123456789')).toBe('123456789');
        expect(videoEmbed('https://cdn.test/a.mp4', '')).toMatchObject({ kind: 'file' });
    });

    it('مضيفٌ مجهول يُعرض رابطًا لا إطارًا', () => {
        expect(videoEmbed('https://strange.host/video/1', '')).toMatchObject({ kind: 'link' });
        expect(videoEmbed('', '')).toBeNull();
        expect(videoEmbed('javascript:alert(1)', '')).toBeNull();
    });

    it('الخريطة من العنوان المكتوب أوّلًا', () => {
        expect(mapEmbed('الخوير، مسقط', '')).toContain('output=embed');
        expect(mapEmbed('', 'https://maps.google.com/@23.588,58.3829,15z')).toContain('23.588,58.3829');
        expect(mapEmbed('', 'https://maps.app.goo.gl/abc')).toBeNull();
    });
});

describe('المال والواتساب', () => {
    it('العملة تُكتب كما ضبطها التاجر', () => {
        expect(money(12.5, store.currency)).toBe('12.500 ر.ع');
        expect(money(12.5, { code: 'AED', symbol: 'د.إ', rate: 1, is_base: true, decimals: 2, before: true })).toBe(
            'د.إ 12.50',
        );
    });

    it('بلا عملةٍ في المستند تُكتب بالافتراضيّ لا NaN', () => {
        expect(money(5, undefined)).toBe('5.000 ر.ع');
        expect(money(null, store.currency)).toBe('0.000 ر.ع');
    });

    it('رقمٌ قصيرٌ ليس رقم واتساب', () => {
        expect(whatsappUrl('123')).toBeNull();
        expect(whatsappUrl('')).toBeNull();
        expect(whatsappUrl('+968 9000 0000')).toBe('https://wa.me/96890000000');
        expect(whatsappUrl('96890000000', 'مرحبًا')).toContain('?text=%D9%85%D8%B1%D8%AD%D8%A8%D9%8B%D8%A7');
    });
});
