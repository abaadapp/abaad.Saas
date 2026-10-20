import type { ImageFit } from '@/Components/StoreImageField';
import { freshSlug } from './CollectionsField';
import { parseTarget, type StoreTargetValue } from './StoreTargets';

/**
 * الصفحاتُ المخصّصة كما يكتبها المحرّر — مرآةُ `Store\StoreCustomPages`.
 *
 * صفحةٌ عنوانٌ ونبذة، ثمّ وحداتٌ بترتيب صاحبها. ونوعُها (`kind`) ما يُعرض له من
 * الوحدات: «كتابة» عنوانٌ وفقرةٌ وزرّ، و«صور» صورةٌ ومعرضٌ وزرّ… و«محتوى متنوّع»
 * كلُّها. والقيمةُ نصُّ JSON في مفتاحٍ واحد (`store_custom_pages`) يقرؤه الخادمُ
 * بصرامة — وما كُتب قبل الوحدات (صورةٌ رئيسيّةٌ وأقسامٌ وزرّ) يُقرأ وحداتٍ
 * بالترتيب نفسِه، كما يقرؤه الخادم.
 */

export type PageKind = 'text' | 'images' | 'textimage' | 'products' | 'mixed';

export type BlockType = 'heading' | 'text' | 'image' | 'gallery' | 'textimage' | 'products' | 'collection' | 'button' | 'promo';

export const PAGE_KINDS: PageKind[] = ['text', 'images', 'textimage', 'products', 'mixed'];

/** نوعُ الصفحة ← ما يُضاف إليها — كما في الخادم (`StoreCustomPages::KINDS`) */
export const KIND_BLOCKS: Record<PageKind, BlockType[]> = {
    text: ['heading', 'text', 'button'],
    images: ['image', 'gallery', 'button'],
    textimage: ['image', 'textimage', 'button'],
    products: ['heading', 'text', 'products', 'collection', 'button'],
    mixed: ['heading', 'text', 'image', 'gallery', 'textimage', 'products', 'collection', 'button', 'promo'],
};

export type PageBlock =
    | { type: 'heading'; text_ar: string; text_en: string }
    | { type: 'text'; body_ar: string; body_en: string }
    | { type: 'image'; image: string; fit?: ImageFit }
    | { type: 'gallery'; images: string[]; fit?: ImageFit }
    | { type: 'textimage'; image: string; fit?: ImageFit; layout: 'side' | 'top'; title_ar: string; title_en: string; body_ar: string; body_en: string }
    | { type: 'products'; title_ar: string; title_en: string; products: number[] }
    | { type: 'collection'; slug: string; title_ar: string; title_en: string }
    | { type: 'button'; label_ar: string; label_en: string; target: StoreTargetValue }
    | {
          type: 'promo';
          image: string;
          fit?: ImageFit;
          title_ar: string;
          title_en: string;
          body_ar: string;
          body_en: string;
          label_ar: string;
          label_en: string;
          target: StoreTargetValue | null;
      };

export interface CustomPage {
    slug: string;
    kind: PageKind;
    title_ar: string;
    title_en: string;
    intro_ar: string;
    intro_en: string;
    blocks: PageBlock[];
    visible: boolean;
}

type Raw = Record<string, unknown>;

const str = (o: Raw, k: string) => String(o[k] ?? '');
const fitIn = (o: Raw) => (o.fit === 'contain' || o.fit === 'cover' ? { fit: o.fit as ImageFit } : {});
const ids = (v: unknown) =>
    (Array.isArray(v) ? v : []).map(Number).filter((n, i, all) => Number.isInteger(n) && n > 0 && all.indexOf(n) === i);

/** وحدةٌ محفوظة ← وحدة — أو `null` لما لا يُقرأ */
export function readBlock(b: Raw): PageBlock | null {
    switch (b.type) {
        case 'heading': return { type: 'heading', text_ar: str(b, 'text_ar'), text_en: str(b, 'text_en') };
        case 'text': return { type: 'text', body_ar: str(b, 'body_ar'), body_en: str(b, 'body_en') };
        case 'image': return { type: 'image', image: str(b, 'image'), ...fitIn(b) };
        case 'gallery': return { type: 'gallery', images: (Array.isArray(b.images) ? b.images : []).map(String).filter(Boolean), ...fitIn(b) };
        case 'textimage':
            return {
                type: 'textimage', image: str(b, 'image'), ...fitIn(b), layout: b.layout === 'top' ? 'top' : 'side',
                title_ar: str(b, 'title_ar'), title_en: str(b, 'title_en'), body_ar: str(b, 'body_ar'), body_en: str(b, 'body_en'),
            };
        case 'products': return { type: 'products', title_ar: str(b, 'title_ar'), title_en: str(b, 'title_en'), products: ids(b.products) };
        case 'collection': return { type: 'collection', slug: str(b, 'slug'), title_ar: str(b, 'title_ar'), title_en: str(b, 'title_en') };
        case 'button': {
            const target = parseTarget(b.target);

            return target ? { type: 'button', label_ar: str(b, 'label_ar'), label_en: str(b, 'label_en'), target } : null;
        }
        case 'promo':
            return {
                type: 'promo', image: str(b, 'image'), ...fitIn(b),
                title_ar: str(b, 'title_ar'), title_en: str(b, 'title_en'), body_ar: str(b, 'body_ar'), body_en: str(b, 'body_en'),
                label_ar: str(b, 'label_ar'), label_en: str(b, 'label_en'), target: parseTarget(b.target),
            };
        default: return null;
    }
}

/** ما كُتب قبل الوحدات ← وحدات: الصورةُ الرئيسيّة، ثمّ الأقسام، ثمّ الزرّ */
function legacyBlocks(p: Raw): PageBlock[] {
    const out: PageBlock[] = [];

    if (str(p, 'image') !== '') out.push({ type: 'image', image: str(p, 'image'), ...fitIn(p) });

    for (const s of Array.isArray(p.sections) ? p.sections : []) {
        if (s && typeof s === 'object') {
            const block = readBlock({ ...(s as Raw), type: 'textimage', layout: 'side' });
            if (block) out.push(block);
        }
    }

    const cta = p.cta && typeof p.cta === 'object' ? (p.cta as Raw) : null;

    if (cta && (str(cta, 'label_ar') !== '' || str(cta, 'label_en') !== '')) {
        const block = readBlock({ ...cta, type: 'button' });
        if (block) out.push(block);
    }

    return out;
}

/** النصُّ المحفوظ ← صفحات — وما لا يُقرأ يسقط ولا يُسقط الشاشة */
export function parsePages(raw: string | undefined): CustomPage[] {
    let list: unknown;

    try {
        list = raw ? JSON.parse(raw) : [];
    } catch {
        return [];
    }

    if (! Array.isArray(list)) return [];

    return list
        .filter((p): p is Raw => !! p && typeof p === 'object')
        .map((p) => {
            const legacy = ! ('blocks' in p);
            const kind = legacy ? 'textimage' : PAGE_KINDS.includes(p.kind as PageKind) ? (p.kind as PageKind) : 'mixed';
            const blocks = legacy
                ? legacyBlocks(p)
                : (Array.isArray(p.blocks) ? p.blocks : [])
                      .filter((b): b is Raw => !! b && typeof b === 'object')
                      .map(readBlock)
                      .filter((b): b is PageBlock => b !== null);

            return {
                slug: str(p, 'slug'), kind,
                title_ar: str(p, 'title_ar'), title_en: str(p, 'title_en'),
                intro_ar: str(p, 'intro_ar'), intro_en: str(p, 'intro_en'),
                blocks, visible: p.visible !== false,
            };
        });
}

/** الصفحات ← نصٌّ يُحفظ. والفراغُ «لا صفحات» */
export function serializePages(list: CustomPage[]): string {
    return list.length ? JSON.stringify(list) : '';
}

/** صفحةٌ جديدةٌ بنوعها واسمها — ورابطُها من اسمها الإنجليزيّ إن كان */
export function blankPage(taken: string[], title: { ar: string; en: string }, kind: PageKind = 'mixed'): CustomPage {
    return {
        slug: freshSlug(taken, title.en),
        kind,
        title_ar: title.ar, title_en: title.en, intro_ar: '', intro_en: '',
        blocks: [],
        visible: true,
    };
}

/** وحدةٌ جديدةٌ فارغةٌ بنوعها — والصورةُ «كاملة» كما يبدأ كلُّ ما يُرفع */
export function blankBlock(type: BlockType): PageBlock {
    switch (type) {
        case 'heading': return { type, text_ar: '', text_en: '' };
        case 'text': return { type, body_ar: '', body_en: '' };
        case 'image': return { type, image: '', fit: 'contain' };
        case 'gallery': return { type, images: [], fit: 'contain' };
        case 'textimage': return { type, image: '', fit: 'contain', layout: 'side', title_ar: '', title_en: '', body_ar: '', body_en: '' };
        case 'products': return { type, title_ar: '', title_en: '', products: [] };
        case 'collection': return { type, slug: '', title_ar: '', title_en: '' };
        case 'button': return { type, label_ar: '', label_en: '', target: { type: 'shop', value: '' } };
        case 'promo': return { type, image: '', fit: 'contain', title_ar: '', title_en: '', body_ar: '', body_en: '', label_ar: '', label_en: '', target: null };
    }
}

const has = (...values: string[]) => values.some((v) => v.trim() !== '');

/** أفي الوحدة شيءٌ يُرسم؟ — والفارغةُ تُطرح عند الحفظ ولا تُردّ (`withoutEmptyBlocks`) */
export function blockFilled(b: PageBlock): boolean {
    switch (b.type) {
        case 'heading': return has(b.text_ar, b.text_en);
        case 'text': return has(b.body_ar, b.body_en);
        case 'image': return has(b.image);
        case 'gallery': return b.images.length > 0;
        case 'textimage': return has(b.image, b.title_ar, b.title_en, b.body_ar, b.body_en);
        case 'products': return b.products.length > 0;
        case 'collection': return has(b.slug);
        case 'button': return has(b.label_ar, b.label_en);
        case 'promo': return has(b.image, b.title_ar, b.title_en, b.body_ar, b.body_en);
    }
}

/**
 * ما يُرسَل إلى الخادم — بلا وحدةٍ فارغة.
 *
 * وحدةٌ أُضيفت ولم يُكتب فيها شيءٌ لا تُحفظ ولا تردّ الحفظ: لا شيءَ فيها يضيع.
 * وزرُّ العرض بلا نصٍّ لا وجهةَ له تُرسَل.
 */
export function withoutEmptyBlocks(raw: string): string {
    if (raw === '') return raw;

    return serializePages(parsePages(raw).map((p) => ({
        ...p,
        blocks: p.blocks
            .filter(blockFilled)
            .map((b) => (b.type === 'promo' && ! has(b.label_ar, b.label_en) ? { ...b, target: null } : b)),
    })));
}

/**
 * تبديلُ نوع الصفحة — ما يناسب الجديدَ يبقى أو يتحوّل، وما لا يناسبه يُعدّ.
 *
 * فالقسمُ من نصٍّ وصورة يصير في «صفحة كتابة» عنوانًا وفقرة، وتُعدّ صورتُه؛
 * ويصير في «صفحة صور» صورةً، ويُعدّ نصُّه. والعنوانُ والفقرةُ يصيران في «كتابة
 * وصور» قسمًا بلا صورة — لا يضيع منهما حرف. و«محتوى متنوّع» يقبل كلَّ شيء.
 *
 * @returns الصفحةُ بنوعها الجديد، وما سيضيع منها — ليُسأل صاحبُها قبل أن يُطبَّق
 */
export function convertKind(page: CustomPage, to: PageKind): { page: CustomPage; lost: BlockType[] } {
    const allowed = KIND_BLOCKS[to];
    const ok = (t: BlockType) => allowed.includes(t);
    const out: PageBlock[] = [];
    const lost: BlockType[] = [];

    for (const b of page.blocks) {
        if (ok(b.type)) {
            out.push(b);
            continue;
        }

        if (! blockFilled(b)) continue;

        switch (b.type) {
            case 'heading':
                if (ok('textimage')) out.push({ ...blankBlock('textimage'), title_ar: b.text_ar, title_en: b.text_en } as PageBlock);
                else lost.push(b.type);
                break;
            case 'text':
                if (ok('textimage')) out.push({ ...blankBlock('textimage'), body_ar: b.body_ar, body_en: b.body_en } as PageBlock);
                else lost.push(b.type);
                break;
            case 'image':
                if (ok('gallery')) out.push({ type: 'gallery', images: [b.image], fit: b.fit });
                else lost.push(b.type);
                break;
            case 'gallery':
                if (ok('image')) b.images.forEach((image) => out.push({ type: 'image', image, fit: b.fit }));
                else lost.push(b.type);
                break;
            case 'textimage':
            case 'promo': {
                const words = has(b.title_ar, b.title_en, b.body_ar, b.body_en);

                if (ok('textimage')) {
                    out.push({ ...blankBlock('textimage'), image: b.image, fit: b.fit, title_ar: b.title_ar, title_en: b.title_en, body_ar: b.body_ar, body_en: b.body_en } as PageBlock);
                } else {
                    if (ok('heading') && has(b.title_ar, b.title_en)) out.push({ type: 'heading', text_ar: b.title_ar, text_en: b.title_en });
                    if (ok('text') && has(b.body_ar, b.body_en)) out.push({ type: 'text', body_ar: b.body_ar, body_en: b.body_en });
                    if (ok('image') && b.image !== '') out.push({ type: 'image', image: b.image, fit: b.fit });
                    if (b.image !== '' && ! ok('image')) lost.push('image');
                    if (words && ! ok('heading') && ! ok('text')) lost.push('text');
                }

                if (b.type === 'promo' && b.target && has(b.label_ar, b.label_en)) {
                    out.push({ type: 'button', label_ar: b.label_ar, label_en: b.label_en, target: b.target });
                }
                break;
            }
            default:
                lost.push(b.type);
        }
    }

    return { page: { ...page, kind: to, blocks: out }, lost };
}
