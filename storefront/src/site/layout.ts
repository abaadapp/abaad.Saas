import type { DocSection, Tokens } from './types';

/**
 * رموز التخطيط — القالب بنيةٌ لا لوحةُ ألوان.
 *
 * كان القالب ستّة رموز: لونٌ وخلفيةٌ ونصٌّ وخطٌّ وحافّةٌ وزرّ. وكان ذلك يكفي
 * ليبدو موقعان مختلفين من بعيد، ولا يكفي ليكونا مختلفين: الترويسةُ واحدة
 * والواجهةُ واحدة وبطاقةُ المنتج واحدة، فالقوالبُ كلُّها صفحةٌ واحدة بألوانٍ
 * متبدّلة. والتاجر يرى ذلك في ثانية.
 *
 * فهذه طبقةٌ ثانية بجانب `Theme`: ثلاثة عشر رمزًا تصف **كيف تُبنى الصفحة**
 * لا بأيّ لونٍ تُصبغ — عرضُ المحتوى، وكثافةُ الفراغ، وسلّمُ العناوين، وشكلُ
 * الترويسة والواجهة والبطاقة والشبكة والتصنيفات والتذييل.
 *
 * وثلاث قواعد تحكمها:
 *
 * ١) **العارض واحد.** لا `AtelierSite.tsx` ولا `MarketSite.tsx`: الرسمُ
 *    واحدٌ يقرأ هذه الرموز. فإصلاحُ عطبٍ في بطاقة المنتج إصلاحٌ واحد يصل
 *    القوالبَ كلَّها، وقسمٌ يُضاف يعمل فيها كلِّها.
 *
 * ٢) **الافتراضيُّ هو رسمُ الأمس حرفيًّا.** موقعٌ نُشر قبل هذه الطبقة يصل
 *    بلا رموزها، فيأخذ `LAYOUT_DEFAULTS` — وهي بالضبط ما كان يُرسم به.
 *    فلا يستيقظ تاجرٌ على موقعٍ غيّرناه تحته.
 *
 * ٣) **القسم يعلو على القالب.** حقلُ `layout` في القسم قيمتُه `auto` فيتبع
 *    قالبه، أو قيمةٌ صريحة فيخالفه. فالتاجر يبدّل واجهتَه وحدها بلا أن
 *    يبدّل قالبه كلَّه.
 */

export interface LayoutTokens {
    /** عرضُ المحتوى الأقصى */
    width: 'narrow' | 'normal' | 'wide' | 'full';
    /** كثافةُ الفراغ بين الأقسام وداخلها */
    density: 'compact' | 'balanced' | 'spacious';
    /** سلّمُ الخطّ وصوتُه — حجمًا ووزنًا وتباعدًا وعرضَ سطر */
    scale: 'compact' | 'balanced' | 'editorial' | 'display' | 'precise';
    /** موضعُ عنوان القسم وزينتُه */
    heading: 'center' | 'start' | 'editorial';
    header: 'minimal' | 'centered' | 'commerce' | 'editorial';
    hero: 'classic' | 'centered' | 'split' | 'editorial' | 'showcase';
    card: 'plain' | 'soft' | 'commerce' | 'editorial' | 'bare';
    grid: 'classic' | 'dense' | 'large' | 'editorial';
    /** نسبةُ صورة المنتج */
    ratio: 'square' | 'portrait' | 'landscape';
    categories: 'cards' | 'pills' | 'covers' | 'tiles' | 'list';
    footer: 'columns' | 'minimal' | 'brand' | 'split';
    /** شكلُ البطاقات والأسطح: حدٌّ أم ظلٌّ أم لا شيء */
    surface_style: 'bordered' | 'flat' | 'raised';
    /** خطُّ العناوين — وفراغٌ يعني خطَّ النصّ نفسه */
    heading_font: string;
}

/** ما كان يُرسم قبل هذه الطبقة — فيبقى كما كان لمن لم يختر */
export const LAYOUT_DEFAULTS: LayoutTokens = {
    width: 'normal',
    density: 'balanced',
    scale: 'balanced',
    heading: 'center',
    header: 'minimal',
    hero: 'classic',
    card: 'plain',
    grid: 'classic',
    ratio: 'square',
    categories: 'cards',
    footer: 'columns',
    surface_style: 'bordered',
    heading_font: '',
};

/** القيم المقبولة لكلّ رمز — وما ليس منها يعود إلى افتراضيّه */
const ALLOWED: Record<Exclude<keyof LayoutTokens, 'heading_font'>, readonly string[]> = {
    width: ['narrow', 'normal', 'wide', 'full'],
    density: ['compact', 'balanced', 'spacious'],
    scale: ['compact', 'balanced', 'editorial', 'display', 'precise'],
    heading: ['center', 'start', 'editorial'],
    header: ['minimal', 'centered', 'commerce', 'editorial'],
    hero: ['classic', 'centered', 'split', 'editorial', 'showcase'],
    card: ['plain', 'soft', 'commerce', 'editorial', 'bare'],
    grid: ['classic', 'dense', 'large', 'editorial'],
    ratio: ['square', 'portrait', 'landscape'],
    categories: ['cards', 'pills', 'covers', 'tiles', 'list'],
    footer: ['columns', 'minimal', 'brand', 'split'],
    surface_style: ['bordered', 'flat', 'raised'],
};

/**
 * الأسماءُ المهجورة تُقرأ ولا تُكتب.
 *
 * «شبكة صور» في التصنيفات كانت `grid`، وصارت `covers` حين صار للشبكة معنًى
 * آخر في رموز المنتجات. وموقعٌ حفظ `grid` قبل التبديل لا يُرسم بطاقاتٍ
 * لأنّ اسمًا تغيّر عندنا.
 *
 * و`simple` و`full` لا تُترجَمان — تسقطان. وهما من مفردات ما قبل هذه
 * الطبقة كتبتهما القوالبُ في بيانات الترويسات، لا اختيارًا اختاره تاجر.
 * فالساقطُ يتبع قالبه: موقعٌ على قالبٍ قديم يبقى كما كان (رمزُه `minimal`
 * وهو `simple` بحرفه)، ومن بدّل قالبه أخذ ترويسة قالبه الجديد — وهو ما
 * يقصده حين يبدّل. ولو تُرجمتا لَبقيت ترويسةُ الأمس على كلّ موقعٍ بُني قبل
 * اليوم مهما بدّل صاحبُه قالبه.
 */
const ALIASES: Record<string, string> = { grid: 'covers' };

/** عرضُ المحتوى بالبكسل — و`full` بلا حدّ */
export const WIDTH_PX: Record<LayoutTokens['width'], string> = {
    narrow: '980px',
    normal: '1120px',
    wide: '1320px',
    full: '100%',
};

/** نسبةُ صورة المنتج كما تُكتب في CSS */
export const RATIO: Record<LayoutTokens['ratio'], string> = {
    square: '1 / 1',
    portrait: '3 / 4',
    landscape: '4 / 3',
};

function one<K extends Exclude<keyof LayoutTokens, 'heading_font'>>(key: K, value: unknown): LayoutTokens[K] {
    const raw = typeof value === 'string' ? (ALIASES[value] ?? value) : '';

    return (ALLOWED[key].includes(raw) ? raw : LAYOUT_DEFAULTS[key]) as LayoutTokens[K];
}

/**
 * رموزُ تخطيط هذا الموقع — من `tokens` نفسها التي تحمل ألوانه.
 *
 * وحقيبةٌ واحدة لا حقيبتان: اللقطة المنشورة تحمل `tokens`، فدخلت الرموزُ
 * الجديدة فيها بلا حقلٍ جديد في العقد ولا نسخةٍ ثانية منه.
 */
export function layoutOf(doc: { tokens?: Partial<Tokens> }): LayoutTokens {
    const t: Partial<Tokens> = doc.tokens ?? {};

    return {
        width: one('width', t.width),
        density: one('density', t.density),
        scale: one('scale', t.scale),
        heading: one('heading', t.heading),
        header: one('header', t.header),
        hero: one('hero', t.hero),
        card: one('card', t.card),
        grid: one('grid', t.grid),
        ratio: one('ratio', t.ratio),
        categories: one('categories', t.categories),
        footer: one('footer', t.footer),
        surface_style: one('surface_style', t.surface_style),
        heading_font: typeof t.heading_font === 'string' ? t.heading_font : '',
    };
}

/**
 * ما اختاره القسم لنفسه، وإلّا فما اختاره قالبُه.
 *
 * و`auto` ليست قيمةً ثالثة بل غيابُ اختيار: التاجر الذي لم يفتح «التخطيط»
 * يتبع قالبه، ومن بدّل قالبه بعدها يتبدّل قسمُه معه. ومن اختار صراحةً يبقى
 * على اختياره مهما بدّل — وهو ما يقصده حين يختار.
 */
export function variant<K extends Exclude<keyof LayoutTokens, 'heading_font'>>(
    section: DocSection,
    key: K,
    fallback: LayoutTokens[K],
    /** اسمُ الحقل في القسم — و«التصنيفات» تسمّيه `style` منذ ما قبل هذه الطبقة */
    field: string = 'layout',
): LayoutTokens[K] {
    const raw = section.data?.[field];
    const value = typeof raw === 'string' ? (ALIASES[raw] ?? raw) : '';

    return (ALLOWED[key].includes(value) ? value : fallback) as LayoutTokens[K];
}
