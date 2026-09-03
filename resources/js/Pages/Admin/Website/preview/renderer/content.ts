import { whatsappUrl } from './commerce';
import { mapEmbed, videoEmbed } from './embed';
import { filled, rows, str } from './read';
import type { DocSection, SiteDocument } from './types';

/**
 * أفي القسم ما يُعرض؟
 *
 * قسمٌ لا شيء فيه يُرسم شريطًا فيه عنوانٌ وتحته بياض: «الأكثر مبيعًا» في
 * متجرٍ لم يبع بعد، و«معرض صور» بلا صور. وهذا في موقعٍ حقيقيّ عطبٌ يراه
 * الزبون، لا نقصٌ يعرف التاجر أنّه نقص.
 *
 * فالقاعدة واحدةٌ للطرفين وتُقرأ هنا: في المعاينة يبقى القسم ويُقال عنه ما
 * ينقصه — التاجر يحتاج أن يعرف أين هو ليملأه — وفي الموقع لا يُرسم أصلًا.
 * ولو كانت القاعدتان في موضعين لافترقتا، فرأى التاجر في معاينته قسمًا لا
 * يراه زبونُه.
 */
export function hasContent(section: DocSection, doc: SiteDocument): boolean {
    const s = section;
    const items = s.items ?? [];

    switch (s.type) {
        // ما يُقرأ من النظام: لا شيء فيه إن لم يجد ما يعرض
        case 'featured_products':
        case 'latest_products':
        case 'best_sellers':
        case 'categories':
        case 'testimonials':
            return items.length > 0;

        case 'gallery':
            return filled(rows<{ src: string }>(s, 'images'), ['src']).length > 0;

        case 'faq':
            return filled(rows<{ q: string; a: string }>(s, 'items'), ['q', 'a']).length > 0;

        case 'benefits':
            return filled(rows<{ title: string; text: string }>(s, 'items'), ['title', 'text']).length > 0;

        case 'stats':
            return filled(rows<{ value: string; label: string }>(s, 'items'), ['value', 'label']).length > 0;

        case 'video':
            return videoEmbed(str(s, 'url'), '') !== null;

        case 'map':
            return mapEmbed(str(s, 'address', doc.brand?.address ?? ''), str(s, 'url')) !== null || str(s, 'url') !== '';

        case 'social':
            return filled(rows<{ value: string }>(s, 'accounts'), ['value']).length > 0 || (doc.brand?.social?.length ?? 0) > 0;

        case 'whatsapp':
            return whatsappUrl(str(s, 'number', doc.brand?.whatsapp ?? '')) !== null;

        case 'contact':
            return (
                str(s, 'phone', doc.brand?.phone ?? '') !== '' ||
                str(s, 'email', doc.brand?.email ?? '') !== '' ||
                str(s, 'address', doc.brand?.address ?? '') !== '' ||
                whatsappUrl(str(s, 'whatsapp', doc.brand?.whatsapp ?? '')) !== null
            );

        case 'image_text':
            return str(s, 'title') !== '' || str(s, 'body') !== '' || str(s, 'image') !== '';

        case 'banner':
            return str(s, 'text') !== '';

        case 'promo':
            return str(s, 'title') !== '' || str(s, 'text') !== '' || str(s, 'image') !== '';

        // والواجهة الرئيسية لها عنوانٌ بديلٌ دائمًا — اسم المتجر
        default:
            return true;
    }
}

/**
 * أانتهى هذا العرض؟
 *
 * «خصم الجمعة» في موقعٍ يُقرأ في مارس ليس زينةً بل خطأ يُلام عليه التاجر.
 * والتاريخ في وصف القسم منذ بُني، ولم يكن يقرؤه شيء.
 */
export function expired(section: DocSection, now: number = Date.now()): boolean {
    if (section.type !== 'promo') return false;

    const ends = String(section.data?.ends_at ?? '').trim();

    if (ends === '') return false;

    const at = Date.parse(`${ends}T23:59:59`);

    return Number.isFinite(at) && at < now;
}
