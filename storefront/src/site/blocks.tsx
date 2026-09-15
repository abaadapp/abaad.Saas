import Catalog from './Catalog';
import { whatsappUrl } from './commerce';
import { mapEmbed, videoEmbed } from './embed';
import { AtSign, Mail, MapPin, Phone, Play, Star, BENEFIT_ICONS } from './icons';
import { layoutOf, RATIO, variant } from './layout';
import { gridOf, ProductCard } from './ProductCard';
import { Band, Cta, Empty, Grid, Heading, Link, Media, Stars } from './primitives';
import { filled, num, rows, str } from './read';
import type { DocCategory, DocProduct, DocReview, DocSection, Mode, SiteDocument } from './types';

/**
 * رسمُ الأقسام — قسمٌ واحدٌ لكلّ نوع، بلا قالبٍ ولا نسخة.
 *
 * القالبُ رموزٌ لا كود (`layout.ts` و`tokens.ts`)، فرسمُ القسم واحدٌ في
 * القوالب كلِّها وتتبدّل بنيتُه بتبدّل رموزه. ولولا ذلك لصار إصلاحُ عطبٍ في
 * بطاقة المنتج أربعةَ إصلاحاتٍ يُنسى رابعُها، ولصار قسمٌ يُضاف أربعَ نسخ.
 *
 * والفرق بين هذا وبين «قالبٌ بألوان» أنّ الرمز هنا يبدّل **ما يُرسم** لا
 * لونَه: الواجهةُ خمسةُ بناءاتٍ لا واحدٌ بخلفيّاتٍ مختلفة، والتصنيفاتُ
 * خمسةٌ، والشبكةُ أربع. فصورةُ القوالب الأربع جنبًا إلى جنب أربعةُ مواقع.
 *
 * وكلّ لونٍ هنا من `var(--w-…)`.
 */

export interface BlockProps {
    section: DocSection;
    doc: SiteDocument;
    mode: Mode;
    /** أوّل قسمٍ في الصفحة — صورتُه تُحمَّل فورًا لا تأجيلًا */
    first?: boolean;
}

/* ------------------------------ الواجهة ------------------------------ */

/**
 * ارتفاعُ الواجهات الجديدة — أوسعُ ممّا كان، ومقيَّدٌ بالشاشة.
 *
 * `--w-hero-*` أرقامٌ ثابتة وُضعت للواجهة الكلاسيكية وتبقى لها كما هي —
 * موقعٌ قائمٌ عليها لا يطول تحته. أمّا الأشكالُ الأربعة الأخرى فواجهاتٌ
 * تُتأمَّل لا لافتاتٌ تُقرأ: تأخذ نسبةً من ارتفاع الشاشة بحدَّين، فتملأ
 * حاسوبًا واسعًا ولا تبتلع هاتفًا.
 */
const TALL: Record<string, string> = {
    small: 'clamp(360px, 52vh, 500px)',
    medium: 'clamp(440px, 66vh, 620px)',
    large: 'clamp(520px, 80vh, 760px)',
};

/** لونُ العلامة ممزوجًا بخلفيّتها — لسطحٍ يُسند صورةً أو يقوم مقامها */
const TINT = 'color-mix(in srgb, var(--w-primary) 14%, var(--w-bg))';

/** ما يقرؤه كلُّ شكلٍ من أشكال الواجهة — مقروءًا مرّةً لا خمسًا */
function heroText(s: DocSection, doc: SiteDocument) {
    const height = str(s, 'height', 'medium');

    return {
        title: str(s, 'title', doc.brand?.name || doc.name),
        subtitle: str(s, 'subtitle'),
        /** سطرٌ صغير فوق العنوان — وما لم يكتبه التاجر لا يُخترع له */
        eyebrow: str(s, 'eyebrow'),
        image: str(s, 'image'),
        ctaLabel: str(s, 'cta_label'),
        ctaHref: str(s, 'cta_href', '/'),
        align: str(s, 'align', 'center'),
        height: `var(--w-hero-${height}, var(--w-hero-medium))`,
        tall: TALL[height] ?? TALL.medium,
        overlay: { none: 0, light: 0.25, medium: 0.45, strong: 0.65 }[str(s, 'overlay', 'medium')] ?? 0.45,
    };
}

/**
 * العنوانُ الكبير — حجمُه ووزنُه وارتفاعُ سطره وعرضُه من سلّم القالب.
 *
 * والتوسيطُ ليس هنا: حاويتُه شبكةٌ تُوسّط ما فيها (`justify-items`)، وعرضُ
 * السطر `max-width` يُوسَّط معها. ولو كُتب هنا `margin-inline: auto` لأبطلته
 * `margin: 0` بعده — وهو ما كان.
 */
function HeroTitle({ text }: { text: string }) {
    return (
        <h1
            className="w-display"
            style={{
                fontSize: 'var(--w-h1)',
                fontWeight: 'var(--w-h1-weight)' as unknown as number,
                lineHeight: 'var(--w-h1-lh)',
                maxWidth: 'var(--w-h1-measure)',
                margin: 0,
            }}
        >
            {text}
        </h1>
    );
}

function HeroLead({ text, muted = true }: { text: string; muted?: boolean }) {
    return (
        <p
            style={{
                color: muted ? 'var(--w-muted)' : 'inherit',
                fontSize: 'var(--w-lead)',
                lineHeight: 'var(--w-lead-lh)',
                maxWidth: 'var(--w-lead-measure)',
                margin: 0,
                opacity: muted ? 1 : 0.92,
            }}
        >
            {text}
        </p>
    );
}

/**
 * الواجهة الرئيسية — خمسةُ بناءات.
 *
 * وهي أوّلُ ما يُرى من الموقع، فهي أوّلُ ما يُحكم به عليه. وواجهةٌ واحدة
 * بصورةٍ خلفها وتعتيمٍ فوقها تصلح لكلّ متجر — وهذا عيبُها: لا تصلح لأيٍّ
 * منها بعينه. فصارت خمسًا:
 *
 * `classic` صورةٌ خلفيةٌ وتعتيمٌ ونصٌّ فوقها — وهو ما كان، ولمن لم يختر.
 * `centered` كلمةٌ هادئة في بياضٍ واسع، ثمّ شريطُ صورةٍ بعرض الشاشة تحتها.
 *   الكلمةُ أوّلًا والصورةُ تصديقٌ لها — للقالب الذي يترك المنتج يتكلّم.
 * `split` نصٌّ وصورةٌ متجاوران بعرضين غير متساويين، وخلف الصورة كتلةُ لونٍ
 *   مزاحة تسندها — تركيبٌ يُقرأ مصنوعًا لا مصفوفًا.
 * `editorial` صورةٌ تملأ العرض وتطول مع الشاشة، ونصٌّ في أسفلها بخطّ
 *   العناوين وتدرّجٍ لا تعتيمٍ مسطّح — غلافُ مجلّةٍ لا لافتةٌ فوق صورة.
 * `showcase` لوحةُ عرضٍ وصورةٌ كبيرة في إطارٍ واحد بعرض الشاشة — واجهةُ
 *   حملةٍ في متجرٍ كبير: تقول «هذا العرض» لا «هذا نحن».
 *
 * ═══ ولا أيقونةَ مكانَ صورةٍ غائبة ═══
 *
 * كانت الواجهةُ بلا صورةٍ مستطيلًا رماديًّا فيه أيقونةُ كيس. والتاجر الذي
 * يختار قالبَه قبل أن يرفع صورَه يرى ذلك أوّلَ ما يرى، فيحكم على القالب.
 * فصار البديلُ سطحًا مقصودًا من لون علامته (`.w-wash`) والكلمةُ عليه — واجهةٌ
 * تامّةٌ بلا صورة، لا واجهةٌ تنتظرها.
 */
function Hero(props: BlockProps) {
    const { section: s, doc, mode } = props;
    const shape = variant(s, 'hero', layoutOf(doc).hero);
    const x = heroText(s, doc);

    const cta = x.ctaLabel ? <Cta label={x.ctaLabel} href={x.ctaHref} mode={mode} /> : null;

    /*
     * ولونُ العلامة لا يُكتب فوق صورةٍ معتّمة.
     *
     * بنّيُّ القالب التحريريّ على تدرّجٍ أسودَ سطرٌ لا يكاد يُقرأ — واللونُ
     * هنا زينةٌ لا معنى. ففوق الصورة يأخذ لونَ ما حوله (أبيض)، وعلى السطح
     * الفاتح يأخذ لون العلامة.
     */
    const brow = (onImage?: boolean) =>
        x.eyebrow ? (
            <span className="w-eyebrow" style={{ color: onImage ? 'inherit' : 'var(--w-primary)' }}>
                {x.eyebrow}
            </span>
        ) : null;

    const eyebrow = brow();

    /* ------------------------------ تحريريّة ------------------------------ */

    if (shape === 'editorial') {
        return (
            <section
                className={x.image ? undefined : 'w-wash'}
                style={{
                    position: 'relative',
                    minHeight: x.tall,
                    display: 'flex',
                    alignItems: 'flex-end',
                    padding: 'var(--w-pad)',
                    background: x.image ? `url(${x.image}) center/cover` : undefined,
                }}
            >
                {/*
                 * تدرّجٌ لا تعتيمٌ مسطّح: الطبقةُ الرماديّة فوق الصورة كلِّها
                 * تُطفئ الصورة لتُقرأ الكلمة. والتدرّجُ من الأسفل يُبقي أعلى
                 * الصورة كما صوّرها صاحبُها ويُقرئ ما تحته.
                 */}
                {x.image && (
                    <span
                        aria-hidden
                        style={{
                            position: 'absolute',
                            inset: 0,
                            background: `linear-gradient(to top, rgba(0,0,0,${Math.min(x.overlay + 0.3, 0.92)}) 0%, rgba(0,0,0,${x.overlay * 0.4}) 48%, rgba(0,0,0,0) 82%)`,
                        }}
                    />
                )}
                <div
                    style={{
                        position: 'relative',
                        width: '100%',
                        maxWidth: 'var(--w-content)',
                        margin: '0 auto',
                        color: x.image ? '#fff' : 'inherit',
                        display: 'grid',
                        gap: 18,
                        justifyItems: 'start',
                    }}
                >
                    {brow(!!x.image) ?? (
                        <span
                            aria-hidden
                            style={{ display: 'block', width: 52, height: 1, background: 'currentColor', opacity: 0.7 }}
                        />
                    )}
                    <HeroTitle text={x.title} />
                    {x.subtitle && <HeroLead text={x.subtitle} muted={!x.image} />}
                    {x.ctaLabel && <Cta label={x.ctaLabel} href={x.ctaHref} mode={mode} ghost={!!x.image} />}
                </div>
            </section>
        );
    }

    /* ------------------------------ مشطورة ------------------------------ */

    if (shape === 'split') {
        /*
         * كتلةُ لونٍ مزاحةٌ خلف الصورة.
         *
         * صورةٌ في مستطيلٍ وسط بياضٍ تُقرأ ملفًّا مرفوعًا؛ وكتلةٌ تطلّ من
         * خلفها تُقرأ تركيبًا مقصودًا. وهي بالمنطق لا بالجهة: `inline-end`
         * تنقلب مع اتّجاه الصفحة، فلا يخرج التركيبُ من إطاره في الإنجليزية.
         */
        const media = (
            <div style={{ position: 'relative', paddingInlineEnd: 18, paddingBottom: 18 }}>
                <span
                    aria-hidden
                    style={{
                        position: 'absolute',
                        insetInlineEnd: 0,
                        bottom: 0,
                        width: '76%',
                        height: '76%',
                        background: TINT,
                        borderRadius: 'var(--w-radius)',
                    }}
                />
                <div style={{ position: 'relative' }}>
                    <Media src={x.image || null} alt={x.title} ratio="4 / 5" eager />
                </div>
            </div>
        );

        const text = (
            <div style={{ display: 'grid', gap: 18, alignContent: 'center', justifyItems: 'start' }}>
                {eyebrow}
                <HeroTitle text={x.title} />
                {x.subtitle && <HeroLead text={x.subtitle} />}
                {cta}
            </div>
        );

        return (
            <section style={{ padding: 'var(--w-pad-loose)' }}>
                <div
                    className="w-hero-split"
                    data-flip={x.align === 'end' ? '1' : undefined}
                    style={{ maxWidth: 'var(--w-content)', margin: '0 auto' }}
                >
                    {x.align === 'end' ? media : text}
                    {x.align === 'end' ? text : media}
                </div>
            </section>
        );
    }

    /* ------------------------------ لوحة عرض ------------------------------ */

    if (shape === 'showcase') {
        return (
            <section style={{ padding: 'var(--w-pad-tight)' }}>
                <div
                    className="w-hero-promo"
                    style={{
                        maxWidth: 'var(--w-content)',
                        margin: '0 auto',
                        borderRadius: 'var(--w-radius)',
                        overflow: 'hidden',
                        border: '1px solid var(--w-card-border)',
                        boxShadow: 'var(--w-card-shadow)',
                    }}
                >
                    <div
                        className="w-wash"
                        style={{
                            padding: 'clamp(26px, 4.5vw, 54px)',
                            display: 'grid',
                            gap: 16,
                            alignContent: 'center',
                            justifyItems: 'start',
                        }}
                    >
                        {eyebrow}
                        <HeroTitle text={x.title} />
                        {x.subtitle && <HeroLead text={x.subtitle} />}
                        {cta}
                    </div>

                    {/*
                     * والصورةُ تملأ خانتها لا نسبةً محجوزة.
                     *
                     * اللوحةُ إلى جانبها قد تطول بسطرٍ زائد في العنوان، فصورةٌ
                     * بنسبةٍ ثابتة تترك تحتها شريطًا فارغًا داخل الإطار نفسه.
                     * و`cover` يقصّ الصورة ولا يترك فراغًا — وهو ما تفعله
                     * واجهاتُ المتاجر كلُّها.
                     */}
                    <div
                        className={x.image ? 'w-shot' : 'w-shot w-wash'}
                        style={{ minHeight: 'clamp(220px, 38vh, 420px)', borderRadius: 0 }}
                    >
                        {x.image && (
                            <img
                                src={x.image}
                                alt={x.title}
                                loading="eager"
                                decoding="async"
                                style={{
                                    position: 'absolute',
                                    inset: 0,
                                    width: '100%',
                                    height: '100%',
                                    objectFit: 'cover',
                                    display: 'block',
                                }}
                            />
                        )}
                    </div>
                </div>
            </section>
        );
    }

    /* ------------------------------ وسطيّة ------------------------------ */

    if (shape === 'centered') {
        return (
            <section>
                <div style={{ padding: 'var(--w-pad-loose)', textAlign: 'center' }}>
                    <div style={{ maxWidth: 780, margin: '0 auto', display: 'grid', gap: 18, justifyItems: 'center' }}>
                        {eyebrow}
                        <HeroTitle text={x.title} />
                        {x.subtitle && <HeroLead text={x.subtitle} />}
                        {cta}
                    </div>
                </div>

                {/* الصورةُ شريطٌ بعرض الشاشة تحت الكلمة — تصديقٌ لها لا خلفيةٌ تُقرأ فوقها */}
                {x.image && (
                    <div
                        role="img"
                        aria-label={x.title}
                        style={{ minHeight: x.tall, background: `url(${x.image}) center/cover` }}
                    />
                )}
            </section>
        );
    }

    /* ------------------------------ كلاسيكية ------------------------------ */

    /*
     * وهذه لا تتبدّل.
     *
     * `classic` هي افتراضيُّ `Layout` — أي ما يُرسم به كلُّ موقعٍ بُني قبل
     * طبقة البنية. فتبديلُ تركيبها أو ارتفاعها يوقظ تاجرًا على واجهةٍ غير
     * التي نام عليها، وهو ما لا يجوز لأجل صنعةٍ أحسن. والذي تحسّن فيها ما
     * لا يُرى فرقًا: الصورةُ الغائبة صارت سطحًا من لون علامته لا رمادًا.
     */
    return (
        <section
            className={x.image ? undefined : 'w-wash'}
            style={{
                minHeight: x.height,
                display: 'flex',
                alignItems: 'center',
                justifyContent: x.align === 'center' ? 'center' : x.align === 'end' ? 'flex-end' : 'flex-start',
                padding: 'var(--w-pad)',
                position: 'relative',
                background: x.image ? `url(${x.image}) center/cover` : undefined,
                textAlign: x.align === 'center' ? 'center' : 'start',
            }}
        >
            {x.image && (
                <span aria-hidden style={{ position: 'absolute', inset: 0, background: `rgba(0,0,0,${x.overlay})` }} />
            )}
            <div style={{ position: 'relative', maxWidth: 640, color: x.image ? '#fff' : 'inherit' }}>
                {/* والسطرُ الفوقيّ يُرسم هنا أيضًا: من كتبه أراده، ومن لم يكتبه لا يتبدّل موقعُه */}
                {brow(!!x.image) && <div style={{ marginBottom: 12 }}>{brow(!!x.image)}</div>}
                <h1 style={{ fontSize: 'var(--w-h1)', fontWeight: 800, margin: 0, lineHeight: 1.3 }}>{x.title}</h1>
                {x.subtitle && (
                    <p style={{ fontSize: 17, marginTop: 14, opacity: 0.92, lineHeight: 1.85 }}>{x.subtitle}</p>
                )}
                {cta && <div style={{ marginTop: 22 }}>{cta}</div>}
            </div>
        </section>
    );
}

/* ------------------------------ محتوى ------------------------------ */

function ImageText({ section: s, doc, mode, first }: BlockProps) {
    const start = str(s, 'side', 'start') === 'start';
    const heading = layoutOf(doc).heading;
    const media = <Media src={str(s, 'image') || null} alt={str(s, 'title')} eager={first} />;
    const text = (
        <div>
            {heading === 'editorial' && (
                <span
                    aria-hidden
                    style={{ display: 'block', width: 46, height: 1, background: 'var(--w-primary)', marginBottom: 18 }}
                />
            )}
            <h2
                style={{
                    fontSize: 'var(--w-h2)',
                    fontWeight: heading === 'editorial' ? 500 : 800,
                    margin: 0,
                    lineHeight: 1.4,
                }}
            >
                {str(s, 'title')}
            </h2>
            <p style={{ color: 'var(--w-muted)', marginTop: 12, lineHeight: 2, fontSize: 15 }}>{str(s, 'body')}</p>
            {str(s, 'cta_label') && (
                <div style={{ marginTop: 18 }}>
                    <Cta label={str(s, 'cta_label')} href={str(s, 'cta_href', '/')} mode={mode} ghost />
                </div>
            )}
        </div>
    );

    return (
        <Band>
            <div
                style={{
                    display: 'grid',
                    gap: 32,
                    gridTemplateColumns: 'repeat(auto-fit, minmax(min(260px, 100%), 1fr))',
                    alignItems: 'center',
                }}
            >
                {start ? media : text}
                {start ? text : media}
            </div>
        </Band>
    );
}

function Banner({ section: s, mode }: BlockProps) {
    return (
        <Band tone={str(s, 'tone', 'primary') === 'soft' ? 'surface' : 'primary'} tight>
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', gap: 18, flexWrap: 'wrap' }}>
                <strong style={{ fontSize: 16, lineHeight: 1.6 }}>{str(s, 'text')}</strong>
                {str(s, 'cta_label') && (
                    <Cta label={str(s, 'cta_label')} href={str(s, 'cta_href', '/')} mode={mode} ghost />
                )}
            </div>
        </Band>
    );
}

function Gallery({ section: s, doc, mode, first }: BlockProps) {
    const list = filled(rows<{ src: string; alt: string }>(s, 'images'), ['src']);
    const columns = Number(str(s, 'columns', '3')) || 3;
    const heading = layoutOf(doc).heading;

    if (list.length === 0) {
        return (
            <Band>
                <Heading title={str(s, 'title')} variant={heading} />
                <Empty text="لا صور بعد — أضف صورًا من محلّك أو من أعمالك" mode={mode} />
            </Band>
        );
    }

    return (
        <Band>
            <Heading title={str(s, 'title')} variant={heading} />
            <Grid columns={columns} min={200} center={heading === 'center'}>
                {list.map((g, i) => (
                    <Media key={i} src={g.src} alt={g.alt} ratio="1 / 1" eager={first && i === 0} />
                ))}
            </Grid>
        </Band>
    );
}

function Video({ section: s, doc, mode }: BlockProps) {
    const title = str(s, 'title');
    const embed = videoEmbed(str(s, 'url'), title || 'فيديو');

    const frame = (children: React.ReactNode) => (
        <div
            style={{
                aspectRatio: '16 / 9',
                background: '#000',
                borderRadius: 'var(--w-radius)',
                overflow: 'hidden',
                maxWidth: 880,
                margin: '0 auto',
            }}
        >
            {children}
        </div>
    );

    return (
        <Band>
            <Heading title={title} variant={layoutOf(doc).heading} />
            {embed === null && <Empty text="ألصق رابط الفيديو ليظهر هنا" mode={mode} />}

            {embed?.kind === 'iframe' &&
                frame(
                    <iframe
                        src={embed.src}
                        title={embed.title}
                        loading="lazy"
                        allow="accelerometer; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                        allowFullScreen
                        referrerPolicy="strict-origin-when-cross-origin"
                        style={{ width: '100%', height: '100%', border: 0, display: 'block' }}
                    />,
                )}

            {embed?.kind === 'file' &&
                frame(
                    <video
                        controls
                        preload="metadata"
                        style={{ width: '100%', height: '100%', display: 'block', objectFit: 'contain' }}
                    >
                        <source src={embed.src} />
                    </video>,
                )}

            {/* مضيفٌ لا نعرفه لا يُوضع في إطار — يُفتح رابطًا في تبويبٍ جديد */}
            {embed?.kind === 'link' && (
                <div style={{ textAlign: 'center' }}>
                    <Link
                        href={embed.href}
                        mode={mode}
                        external
                        style={{
                            display: 'inline-flex',
                            alignItems: 'center',
                            gap: 8,
                            minHeight: 44,
                            padding: '0 20px',
                            borderRadius: 'var(--w-radius)',
                            border: '1.5px solid var(--w-border)',
                            fontWeight: 700,
                            fontSize: 14,
                        }}
                    >
                        <Play size={16} />
                        مشاهدة الفيديو
                    </Link>
                </div>
            )}
        </Band>
    );
}

function Faq({ section: s, doc, mode }: BlockProps) {
    const list = filled(rows<{ q: string; a: string }>(s, 'items'), ['q', 'a']);

    return (
        <Band>
            <Heading title={str(s, 'title')} variant={layoutOf(doc).heading} />
            {list.length === 0 ? (
                <Empty text="أضف أسئلةً يسألها زبائنك" mode={mode} />
            ) : (
                <div className="w-faq" style={{ maxWidth: 720, margin: '0 auto', display: 'grid', gap: 12 }}>
                    {list.map((f, i) => (
                        <details
                            key={i}
                            style={{
                                border: '1px solid var(--w-border)',
                                borderRadius: 'var(--w-radius)',
                                padding: '14px 18px',
                                background: 'var(--w-bg)',
                            }}
                        >
                            <summary>{f.q}</summary>
                            <p style={{ color: 'var(--w-muted)', margin: '10px 0 0', fontSize: 14, lineHeight: 1.95 }}>
                                {f.a}
                            </p>
                        </details>
                    ))}
                </div>
            )}
        </Band>
    );
}

function Stats({ section: s }: BlockProps) {
    const list = filled(rows<{ value: string; label: string }>(s, 'items'), ['value', 'label']);

    if (list.length === 0) return null;

    return (
        <Band tone="surface" tight>
            <Grid columns={Math.min(4, list.length)} min={140}>
                {list.map((x, i) => (
                    <div key={i} style={{ textAlign: 'center' }}>
                        <p style={{ fontSize: 30, fontWeight: 800, margin: 0, color: 'var(--w-primary)' }}>{x.value}</p>
                        <p style={{ color: 'var(--w-muted)', fontSize: 13, margin: '4px 0 0' }}>{x.label}</p>
                    </div>
                ))}
            </Grid>
        </Band>
    );
}

function Benefits({ section: s, doc, mode }: BlockProps) {
    const list = filled(rows<{ icon: string; title: string; text: string }>(s, 'items'), ['title', 'text']);
    const heading = layoutOf(doc).heading;

    if (list.length === 0) {
        return (
            <Band>
                <Heading title={str(s, 'title')} variant={heading} />
                <Empty text="أضف ما يميّزك — توصيلٌ سريع، ضمان، خدمةٌ قريبة" mode={mode} />
            </Band>
        );
    }

    return (
        <Band>
            <Heading title={str(s, 'title')} variant={heading} />
            <Grid columns={Math.min(3, list.length)} min={220} center={heading === 'center'}>
                {list.map((b, i) => {
                    const Icon = BENEFIT_ICONS[b.icon] ?? Star;

                    return (
                        <div key={i} style={{ textAlign: heading === 'center' ? 'center' : 'start' }}>
                            <Icon size={26} style={{ color: 'var(--w-primary)' }} />
                            <h3 style={{ fontSize: 15, fontWeight: 700, margin: '12px 0 6px' }}>{b.title}</h3>
                            <p style={{ color: 'var(--w-muted)', fontSize: 13, lineHeight: 1.85, margin: 0 }}>{b.text}</p>
                        </div>
                    );
                })}
            </Grid>
        </Band>
    );
}

/**
 * آراء العملاء — بطاقاتٌ أو اقتباساتٌ عريضة.
 *
 * والتحريريُّ لا يضع رأيَ زبونٍ في صندوق: يكتبه بخطّ العناوين كبيرًا في
 * منتصف الصفحة كما تُكتب الشهادةُ في مجلّة. وهو الفرق بين «قرأتُ تقييمًا»
 * و«قرأتُ رأيًا».
 */
function Testimonials({ section: s, doc, mode }: BlockProps) {
    const items = (s.items ?? []) as DocReview[];
    const layout = layoutOf(doc);

    if (items.length === 0) {
        return (
            <Band tone="surface">
                <Heading title={str(s, 'title')} variant={layout.heading} />
                <Empty text="لا تقييمات منشورة بعد — تظهر هنا حين ينشرها زبائنك" mode={mode} />
            </Band>
        );
    }

    if (layout.heading === 'editorial') {
        return (
            <Band>
                <Heading title={str(s, 'title')} variant={layout.heading} />
                <div style={{ display: 'grid', gap: 'clamp(26px, 4vw, 44px)' }}>
                    {items.slice(0, 3).map((r, i) => (
                        <blockquote
                            key={i}
                            style={{
                                margin: 0,
                                maxWidth: '60ch',
                                marginInline: i % 2 === 1 ? 'auto 0' : '0 auto',
                                textAlign: 'start',
                            }}
                        >
                            <Stars rating={r.rating} />
                            <p
                                className="w-display"
                                style={{ margin: 0, fontSize: 'var(--w-h3)', lineHeight: 2, fontWeight: 500 }}
                            >
                                «{r.comment}»
                            </p>
                            <footer style={{ color: 'var(--w-muted)', fontSize: 12.5, marginTop: 12 }}>
                                — {r.author}
                            </footer>
                        </blockquote>
                    ))}
                </div>
            </Band>
        );
    }

    return (
        <Band tone="surface">
            <Heading title={str(s, 'title')} variant={layout.heading} />
            <Grid columns={Math.min(3, items.length)} min={250} center={layout.heading === 'center'}>
                {items.map((r, i) => (
                    <blockquote
                        key={i}
                        style={{
                            margin: 0,
                            background: 'var(--w-bg)',
                            border: '1px solid var(--w-card-border)',
                            borderRadius: 'var(--w-radius)',
                            padding: 20,
                            boxShadow: 'var(--w-card-shadow)',
                        }}
                    >
                        <Stars rating={r.rating} />
                        <p style={{ margin: 0, fontSize: 14, lineHeight: 1.95 }}>{r.comment}</p>
                        <footer style={{ color: 'var(--w-muted)', fontSize: 12, marginTop: 12 }}>{r.author}</footer>
                    </blockquote>
                ))}
            </Grid>
        </Band>
    );
}

/* ------------------------------ التجارة ------------------------------ */

/** صفحةُ المتجر إن كانت في الموقع — رابطُ «عرض الكلّ» لا يُخترع اختراعًا */
function shopHref(doc: SiteDocument): string | null {
    const page = doc.pages?.find((p) => p.slug === '/shop' || p.key === 'shop');

    return page && page.status !== 'draft' ? page.slug : null;
}

function Products({ section: s, doc, mode, first }: BlockProps) {
    const items = (s.items ?? []) as DocProduct[];
    const layout = layoutOf(doc);
    const shape = variant(s, 'grid', layout.grid);
    const columns = Number(str(s, 'columns', '4')) || 4;
    const g = gridOf(shape, columns);
    const ratio = RATIO[layout.ratio];
    const href = shopHref(doc);

    /*
     * ورابطُ «عرض الكلّ» بجانب العنوان لا تحته.
     *
     * وهو لا يُرسم في القوالب التي عنوانُها في الوسط: رابطٌ تحت عنوانٍ
     * موسَّط يصير سطرًا ثالثًا يزاحمه. والتجاريُّ يحتاجه — قسمُ «الأكثر
     * مبيعًا» فيه ثمانيةٌ من مئتين، والزبون يريد الباقي.
     */
    const action =
        href && layout.heading !== 'center' ? (
            <Link
                href={href}
                mode={mode}
                style={{
                    color: 'var(--w-primary)',
                    fontSize: 13.5,
                    fontWeight: 700,
                    minHeight: 40,
                    display: 'inline-flex',
                    alignItems: 'center',
                    whiteSpace: 'nowrap',
                }}
            >
                عرض الكلّ
            </Link>
        ) : null;

    /*
     * وإيقاعُ القسم من شبكته.
     *
     * قسمُ ثمانيةٍ في شبكةٍ كثيفة يريد أن يُمسَح بالعين سريعًا، فحشوٌ واسع
     * فوقه وتحته يقطع المسح. وقسمُ ثلاثةٍ في بطاقاتٍ كبيرة يريد أن يُتأمَّل،
     * فيأخذ فراغَه. وهو ما يجعل صفحتين بالأقسام نفسها تُقرآن مختلفتين.
     */
    return (
        <Band loose={shape === 'large' || shape === 'editorial'}>
            <Heading
                title={str(s, 'title')}
                variant={layout.heading}
                action={action}
                eyebrow={str(s, 'eyebrow') || undefined}
            />
            {items.length === 0 ? (
                <Empty
                    text={
                        s.type === 'best_sellers'
                            ? 'لا مبيعات بعد — سيظهر هنا الأكثر مبيعًا حين تبيع'
                            : 'لا منتجات مفعّلة بعد — أضف منتجاتك وستظهر هنا'
                    }
                    mode={mode}
                />
            ) : (
                <Grid
                    columns={g.columns}
                    min={g.min}
                    center={shape === 'classic' && layout.heading === 'center'}
                    className={shape === 'editorial' ? 'w-grid-editorial' : undefined}
                >
                    {items.map((p, i) => (
                        <ProductCard
                            key={p.id}
                            p={p}
                            doc={doc}
                            mode={mode}
                            card={layout.card}
                            ratio={ratio}
                            eager={first && i < 4}
                        />
                    ))}
                </Grid>
            )}
        </Band>
    );
}

/** صفحةُ المتجر كاملةً — كتالوجٌ يُبحث فيه ويُرشَّح. انظر `Catalog` */
function ProductCatalog({ section: s, doc, mode }: BlockProps) {
    const items = (s.items ?? []) as DocProduct[];
    const layout = layoutOf(doc);

    return (
        <Catalog
            doc={doc}
            mode={mode}
            items={items}
            title={str(s, 'title', 'كلّ المنتجات')}
            card={layout.card}
            grid={variant(s, 'grid', layout.grid)}
            ratio={RATIO[layout.ratio]}
            columns={num(s, 'columns', 4)}
        />
    );
}

/**
 * التصنيفات — خمسةُ أشكال، وغلافٌ حقيقيّ لا بلاطةُ لون.
 *
 * كان القسم شريطًا من الأسماء على مربّعاتٍ ملوّنة: «تسوّق حسب القسم» وتحته
 * أربعةُ مستطيلاتٍ فيها أيقونةُ كيس. وهو أضعفُ ما في الصفحة، وهو في متاجر
 * الورد والهدايا أهمُّ ما فيها — الزبون يدخل باحثًا عن «مناسبات» لا عن صنفٍ
 * بعينه.
 *
 * فصار للتصنيف **غلافٌ من صورة صنفٍ فيه** (`DocCategory.image` — يقرؤه
 * الخادم من أوّل صنفٍ منشورٍ مصوَّر). ولا اختراعَ فيه: هي صورةُ التاجر نفسها،
 * وما لا صنفَ مصوَّرَ فيه يأخذ سطحَ `.w-wash` واسمَه بخطّ العناوين — بلاطةٌ
 * حرفيّةٌ تُقرأ تصميمًا لا نقصًا.
 *
 * ── الخمسة ──
 *
 * `tiles` بلاطاتٌ كبيرة أولاها أعرضُ من جاراتها — عرضُ مجلّة.
 * `covers` بطاقاتُ أغلفةٍ متساوية بحوافَّ دائرية — واجهةٌ عصريّة.
 * `pills` أزرارٌ مستديرة فيها ثمبنيلٌ دائريّ — شريطٌ يُمرَّر في متجرٍ كثيف.
 * `list` فهرسٌ نحيف بخطوطٍ رفيعة وثمبنيلٍ في آخر السطر — أقلُّ ما يكفي.
 * `cards` بطاقاتٌ بأسماءٍ موسّطة — وهو ما كان، ومعه غلافُه الآن.
 *
 * والتصنيف لا يُنقر من الصفحة الرئيسية إلّا إلى صفحة المتجر: لا مسارَ
 * لصفحة تصنيفٍ في هذا العارض. فالرابطُ يذهب إلى `/shop` ويُرشَّح هناك في
 * المتصفّح (انظر `Catalog`) — ورابطٌ يفتح ما وُعد به، لا زرٌّ لا يفعل شيئًا.
 */
function Categories({ section: s, doc, mode }: BlockProps) {
    const items = (s.items ?? []) as DocCategory[];
    const layout = layoutOf(doc);
    const shape = variant(s, 'categories', layout.categories, 'style');
    const heading = layout.heading;
    const shop = shopHref(doc);

    if (items.length === 0) {
        return (
            <Band tone="surface">
                <Heading title={str(s, 'title')} variant={heading} />
                <Empty text="لا تصنيفات بعد — أضف تصنيفاتٍ لمنتجاتك وستظهر هنا" mode={mode} />
            </Band>
        );
    }

    /*
     * ورابطُ التصنيف يذهب إلى صفحة المتجر مرشَّحًا باسمه.
     *
     * و`Catalog` يقرأ `?q=` ويرشّح به الاسمَ والتصنيف، فالتصنيفُ يصل
     * مفتوحًا على أصنافه. وما لا صفحةَ متجرٍ في موقعه يبقى نصًّا لا رابطًا:
     * زرٌّ يبدو قابلًا للضغط ولا يفتح شيئًا أسوأُ من اسمٍ مكتوب.
     */
    const wrap = (c: DocCategory, children: React.ReactNode, className?: string, style?: React.CSSProperties) =>
        shop ? (
            <Link
                key={c.id}
                href={`${shop}?q=${encodeURIComponent(c.name)}`}
                mode={mode}
                className={className}
                style={style}
                ariaLabel={`تصفّح ${c.name}`}
            >
                {children}
            </Link>
        ) : (
            <div key={c.id} className={className} style={style}>
                {children}
            </div>
        );

    /** غلافُ التصنيف — صورتُه أو سطحٌ من لون العلامة، واسمُه فوق الاثنين */
    const cover = (c: DocCategory, ratio: string, size: number) => (
        <div
            className={c.image ? 'w-shot' : 'w-shot w-wash'}
            style={{ aspectRatio: ratio, borderRadius: 'var(--w-radius)', display: 'flex', alignItems: 'flex-end' }}
        >
            {c.image && (
                <>
                    <img
                        src={c.image}
                        alt=""
                        loading="lazy"
                        decoding="async"
                        style={{
                            position: 'absolute',
                            inset: 0,
                            width: '100%',
                            height: '100%',
                            objectFit: 'cover',
                            display: 'block',
                        }}
                    />
                    {/* وتدرّجٌ من الأسفل ليُقرأ الاسم فوق أيّ صورة — لا طبقةٌ تُطفئها كلَّها */}
                    <span
                        aria-hidden
                        style={{
                            position: 'absolute',
                            inset: 0,
                            background: 'linear-gradient(to top, rgba(0,0,0,.72) 0%, rgba(0,0,0,.18) 46%, rgba(0,0,0,0) 78%)',
                        }}
                    />
                </>
            )}
            <span
                className="w-display"
                style={{
                    position: 'relative',
                    padding: 'clamp(14px, 2vw, 22px)',
                    fontSize: size,
                    fontWeight: c.image ? 700 : 600,
                    color: c.image ? '#fff' : 'inherit',
                    lineHeight: 1.4,
                }}
            >
                {c.name}
            </span>
        </div>
    );

    /** ثمبنيلٌ صغير — دائريٌّ في الأزرار، مربّعٌ في الفهرس */
    const thumb = (c: DocCategory, size: number, round: boolean) => (
        <span
            className={c.image ? undefined : 'w-wash'}
            style={{
                width: size,
                height: size,
                flex: 'none',
                borderRadius: round ? 999 : 'var(--w-radius)',
                overflow: 'hidden',
                display: 'block',
                background: c.image ? 'var(--w-surface)' : undefined,
            }}
        >
            {c.image && (
                <img
                    src={c.image}
                    alt=""
                    loading="lazy"
                    decoding="async"
                    style={{ width: '100%', height: '100%', objectFit: 'cover', display: 'block' }}
                />
            )}
        </span>
    );

    /* ------------------------------ أزرار ------------------------------ */

    if (shape === 'pills') {
        return (
            <Band tone="surface" tight>
                <Heading title={str(s, 'title')} variant={heading} />
                <ul
                    className="w-rail"
                    style={{
                        listStyle: 'none',
                        margin: 0,
                        padding: 0,
                        justifyContent: heading === 'center' ? 'center' : undefined,
                    }}
                >
                    {items.map((c) => (
                        <li key={c.id}>
                            {wrap(
                                c,
                                <>
                                    {thumb(c, 34, true)}
                                    {c.name}
                                </>,
                                'w-cover',
                                {
                                    display: 'flex',
                                    alignItems: 'center',
                                    gap: 10,
                                    border: '1px solid var(--w-border)',
                                    borderRadius: 999,
                                    paddingBlock: 6,
                                    paddingInline: '6px 18px',
                                    background: 'var(--w-bg)',
                                    fontSize: 14,
                                    fontWeight: 700,
                                    whiteSpace: 'nowrap',
                                    minHeight: 46,
                                },
                            )}
                        </li>
                    ))}
                </ul>
            </Band>
        );
    }

    /* ------------------------------ فهرس ------------------------------ */

    if (shape === 'list') {
        return (
            <Band>
                <Heading title={str(s, 'title')} variant={heading} />
                <ul
                    style={{
                        listStyle: 'none',
                        margin: 0,
                        padding: 0,
                        display: 'grid',
                        gap: 0,
                        maxWidth: 720,
                        marginInline: heading === 'center' ? 'auto' : undefined,
                        borderTop: '1px solid var(--w-border)',
                    }}
                >
                    {items.map((c) => (
                        <li key={c.id} style={{ borderBottom: '1px solid var(--w-border)' }}>
                            {wrap(
                                c,
                                <>
                                    {thumb(c, 46, false)}
                                    <span
                                        className="w-display"
                                        style={{ flex: 1, fontSize: 17, fontWeight: 600, lineHeight: 1.5 }}
                                    >
                                        {c.name}
                                    </span>
                                    <span aria-hidden style={{ color: 'var(--w-muted)', fontSize: 13 }}>
                                        ↖
                                    </span>
                                </>,
                                'w-cover',
                                {
                                    display: 'flex',
                                    alignItems: 'center',
                                    gap: 16,
                                    padding: '14px 2px',
                                    minHeight: 60,
                                },
                            )}
                        </li>
                    ))}
                </ul>
            </Band>
        );
    }

    /* --------------------------- بلاطاتٌ كبيرة --------------------------- */

    if (shape === 'tiles') {
        return (
            <Band loose>
                <Heading title={str(s, 'title')} variant={heading} eyebrow={heading === 'editorial' ? 'المجموعات' : undefined} />
                <div className="w-cat-tiles">
                    {items.map((c) => wrap(c, cover(c, '4 / 5', 21), 'w-cover'))}
                </div>
            </Band>
        );
    }

    /* ------------------------------ أغلفة ------------------------------ */

    if (shape === 'covers') {
        return (
            <Band>
                <Heading title={str(s, 'title')} variant={heading} />
                <Grid columns={Math.min(4, items.length)} min={200} center={heading === 'center'}>
                    {items.map((c) => wrap(c, cover(c, '4 / 3', 17), 'w-cover'))}
                </Grid>
            </Band>
        );
    }

    /* ------------------------------ بطاقات ------------------------------ */

    /*
     * وهذه افتراضيُّ `Layout` — شكلُ كلّ موقعٍ بُني قبل طبقة البنية.
     *
     * فبقي تركيبُها: بطاقةٌ بحدٍّ واسمٌ موسَّط تحتها. وما تحسّن فيها ما لا
     * يُقرأ تبديلًا: الصورةُ الملوّنة صارت غلافًا حقيقيًّا حين يكون للتصنيف
     * صنفٌ مصوَّر — وهي صورةُ التاجر نفسه لا لونًا يقوم مقامها.
     */
    return (
        <Band tone="surface">
            <Heading title={str(s, 'title')} variant={heading} />
            <Grid columns={Math.min(4, items.length)} min={160} center={heading === 'center'}>
                {items.map((c) =>
                    wrap(
                        c,
                        <>
                            <div
                                className={c.image ? 'w-shot' : 'w-shot w-wash'}
                                style={{ aspectRatio: '4 / 3', borderRadius: 0 }}
                            >
                                {c.image && (
                                    <img
                                        src={c.image}
                                        alt=""
                                        loading="lazy"
                                        decoding="async"
                                        style={{
                                            width: '100%',
                                            height: '100%',
                                            objectFit: 'cover',
                                            display: 'block',
                                        }}
                                    />
                                )}
                            </div>
                            <span style={{ display: 'block', padding: '14px 10px' }}>{c.name}</span>
                        </>,
                        'w-cover',
                        {
                            background: 'var(--w-bg)',
                            border: '1px solid var(--w-card-border)',
                            borderRadius: 'var(--w-radius)',
                            overflow: 'hidden',
                            textAlign: 'center',
                            fontWeight: 700,
                            fontSize: 14,
                            boxShadow: 'var(--w-card-shadow)',
                        },
                    ),
                )}
            </Grid>
        </Band>
    );
}

/**
 * العرض الخاصّ — ولا يُصبغ بلون العلامة في كلّ قالب.
 *
 * كان قسمًا مملوءًا بلون العلامة من حافّة إلى حافّة أيًّا كان القالب. وهو
 * يصلح لقالبٍ تجاريّ يصيح بعروضه، ويُفسد قالبًا بُني على البياض: شريطٌ بنّيٌّ
 * أو ورديٌّ ملء الشاشة وسط صفحةٍ هادئة يُقرأ لافتةً أُلصقت على الصفحة، لا
 * فصلًا منها.
 *
 * فصار يتبع رمزَ الأسطح: القالبُ الذي يحدّ بطاقاته أو يرفعها (التجاريّ
 * والناعم) يملأ، والقالبُ المسطَّح (التحريريّ والبسيط) يأخذ سطحَ `.w-wash`
 * ويترك اللونَ لزرِّه وحده. وهو أيضًا ما يحفظ الافتراضيَّ كما كان: رمزُ
 * الأسطح قبل طبقة البنية `bordered`.
 */
function Promo({ section: s, doc, mode }: BlockProps) {
    const filledBand = layoutOf(doc).surface_style !== 'flat';

    return (
        <Band tone={filledBand ? 'primary' : undefined} loose>
            <div
                className={filledBand ? undefined : 'w-wash'}
                style={{
                    display: 'grid',
                    gap: 26,
                    gridTemplateColumns: 'repeat(auto-fit, minmax(min(240px, 100%), 1fr))',
                    alignItems: 'center',
                    ...(filledBand
                        ? {}
                        : {
                              padding: 'clamp(24px, 4vw, 48px)',
                              borderRadius: 'var(--w-radius)',
                              border: '1px solid var(--w-border)',
                          }),
                }}
            >
                <div>
                    <h2 style={{ fontSize: 'var(--w-h2)', fontWeight: 'var(--w-h2-weight)' as unknown as number, margin: 0, lineHeight: 1.4 }}>
                        {str(s, 'title')}
                    </h2>
                    <p
                        style={{
                            marginTop: 10,
                            opacity: filledBand ? 0.93 : 1,
                            color: filledBand ? undefined : 'var(--w-muted)',
                            lineHeight: 'var(--w-lead-lh)',
                            maxWidth: 'var(--w-lead-measure)',
                        }}
                    >
                        {str(s, 'text')}
                    </p>
                    {str(s, 'cta_label') && (
                        <div style={{ marginTop: 18 }}>
                            <Cta
                                label={str(s, 'cta_label')}
                                href={str(s, 'cta_href', '/')}
                                mode={mode}
                                ghost={filledBand}
                            />
                        </div>
                    )}
                </div>
                {str(s, 'image') && <Media src={str(s, 'image')} alt={str(s, 'title')} ratio="16 / 9" />}
            </div>
        </Band>
    );
}

/* ------------------------------ التواصل ------------------------------ */

function Contact({ section: s, doc, mode }: BlockProps) {
    const brand = doc.brand;
    const phone = str(s, 'phone', brand?.phone ?? '');
    const email = str(s, 'email', brand?.email ?? '');
    const address = str(s, 'address', brand?.address ?? '');
    const wa = whatsappUrl(str(s, 'whatsapp', brand?.whatsapp ?? ''));
    const heading = layoutOf(doc).heading;

    const lines = [
        { Icon: Phone, value: phone, href: phone ? `tel:${phone.replace(/\s+/g, '')}` : null, external: false },
        { Icon: Mail, value: email, href: email ? `mailto:${email}` : null, external: false },
        { Icon: MapPin, value: address, href: null, external: false },
    ].filter((l) => l.value !== '');

    if (lines.length === 0 && !wa) {
        return (
            <Band>
                <Heading title={str(s, 'title')} sub={str(s, 'text')} variant={heading} />
                <Empty text="أضف هاتفك أو بريدك ليصل إليك زبائنك" mode={mode} />
            </Band>
        );
    }

    return (
        <Band>
            <Heading title={str(s, 'title')} sub={str(s, 'text')} variant={heading} />
            <div
                style={{
                    display: 'grid',
                    gap: 14,
                    maxWidth: 460,
                    marginInline: heading === 'center' ? 'auto' : undefined,
                }}
            >
                {lines.map(({ Icon, value, href }, i) => (
                    <p key={i} style={{ display: 'flex', alignItems: 'center', gap: 10, margin: 0, fontSize: 14.5 }}>
                        <Icon size={16} style={{ color: 'var(--w-primary)', flex: 'none' }} />
                        {href ? (
                            <Link href={href} mode={mode} style={{ minHeight: 32, display: 'inline-flex', alignItems: 'center' }}>
                                <span dir="auto">{value}</span>
                            </Link>
                        ) : (
                            <span dir="auto">{value}</span>
                        )}
                    </p>
                ))}

                {/*
                 * و«نموذج رسالة» لا يُرسم نموذجًا.
                 *
                 * لا مسار في أبعاد يستقبل رسالةً من زائر، ولا بريدَ يُرسل إليه.
                 * ونموذجٌ يُملأ ثمّ لا يصل أسوأ من غيابه: التاجر يظنّ أنّ أحدًا
                 * لم يراسله، والزبون يظنّ أنّه راسل ولم يُردّ عليه.
                 */}
                {wa && (
                    <div style={{ marginTop: 6 }}>
                        <Cta label="راسلنا على واتساب" href={wa} mode={mode} external />
                    </div>
                )}
            </div>
        </Band>
    );
}

function MapBlock({ section: s, doc, mode }: BlockProps) {
    const address = str(s, 'address', doc.brand?.address ?? '');
    const url = str(s, 'url');
    const src = mapEmbed(address, url);
    const height = { small: 200, medium: 320, large: 440 }[str(s, 'height', 'medium')] ?? 320;

    return (
        <Band tight>
            <Heading title={str(s, 'title')} variant={layoutOf(doc).heading} />
            {src ? (
                <div style={{ borderRadius: 'var(--w-radius)', overflow: 'hidden', border: '1px solid var(--w-border)' }}>
                    <iframe
                        src={src}
                        title={str(s, 'title', 'موقعنا على الخريطة')}
                        loading="lazy"
                        referrerPolicy="no-referrer-when-downgrade"
                        style={{ width: '100%', height, border: 0, display: 'block' }}
                    />
                </div>
            ) : (
                <Empty text="اكتب عنوان محلّك ليظهر على الخريطة" mode={mode} />
            )}

            {url && (
                <p style={{ textAlign: 'center', marginTop: 12, marginBottom: 0 }}>
                    <Link
                        href={url}
                        mode={mode}
                        external
                        style={{
                            color: 'var(--w-primary)',
                            fontSize: 13.5,
                            fontWeight: 700,
                            display: 'inline-flex',
                            alignItems: 'center',
                            gap: 6,
                            minHeight: 44,
                        }}
                    >
                        <MapPin size={15} />
                        افتح في خرائط غوغل
                    </Link>
                </p>
            )}
        </Band>
    );
}

function Social({ section: s, doc, mode }: BlockProps) {
    /*
     * حسابات القسم أوّلًا، فإن خلا فحساباتُ النشاط.
     *
     * الرابط يُبنى في أبعاد لا هنا (`brand.social[].url`): قاعدةُ كلّ شبكةٍ
     * في موضعٍ واحد. وما لم يصل جاهزًا يُترك اسمًا يُقرأ لا رابطًا مخمَّنًا.
     */
    const own = filled(rows<{ network: string; value: string }>(s, 'accounts'), ['value']);
    const known = doc.brand?.social ?? [];
    const list = own.length
        ? own.map((a) => known.find((k) => k.network === a.network) ?? { ...a, url: '', label: a.network })
        : known;

    return (
        <Band tight>
            <Heading title={str(s, 'title')} variant={layoutOf(doc).heading} />
            {list.length === 0 ? (
                <Empty text="أضف حساباتك على مواقع التواصل" mode={mode} />
            ) : (
                <ul
                    style={{
                        listStyle: 'none',
                        margin: 0,
                        padding: 0,
                        display: 'flex',
                        gap: 12,
                        justifyContent: 'center',
                        flexWrap: 'wrap',
                    }}
                >
                    {list.map((a, i) => {
                        const chip = (
                            <span
                                style={{
                                    display: 'inline-flex',
                                    alignItems: 'center',
                                    gap: 8,
                                    border: '1px solid var(--w-border)',
                                    borderRadius: 'var(--w-radius)',
                                    padding: '10px 14px',
                                    fontSize: 13,
                                    minHeight: 44,
                                }}
                            >
                                <AtSign size={15} style={{ color: 'var(--w-primary)' }} />
                                <span dir="ltr">@{a.value}</span>
                            </span>
                        );

                        return (
                            <li key={i}>
                                {a.url ? (
                                    <Link href={a.url} mode={mode} external ariaLabel={`${a.label} — ${a.value}`}>
                                        {chip}
                                    </Link>
                                ) : (
                                    chip
                                )}
                            </li>
                        );
                    })}
                </ul>
            )}
        </Band>
    );
}

function Whatsapp({ section: s, doc, mode }: BlockProps) {
    const href = whatsappUrl(str(s, 'number', doc.brand?.whatsapp ?? ''), str(s, 'message'));

    if (!href) {
        return (
            <Band tight>
                <Empty text="أضف رقم واتساب ليظهر الزرّ" mode={mode} />
            </Band>
        );
    }

    const pill = (
        <span
            style={{
                display: 'inline-flex',
                alignItems: 'center',
                gap: 10,
                background: 'var(--w-primary)',
                color: 'var(--w-on-primary)',
                borderRadius: 999,
                padding: '14px 22px',
                fontWeight: 700,
                fontSize: 14.5,
                minHeight: 52,
                boxShadow: '0 6px 20px rgba(0,0,0,.18)',
            }}
        >
            <Phone size={17} />
            راسلنا على واتساب
        </span>
    );

    /*
     * عائمٌ في الموقع، وثابتٌ في المعاينة.
     *
     * `fixed` داخل المعاينة يعوم فوق لوحة المحرّر لا فوق الموقع — فيحجب
     * أزرار التاجر وهو يعدّل.
     */
    if (mode === 'edit') {
        return (
            <Band tight>
                <div style={{ display: 'flex', justifyContent: 'center' }}>{pill}</div>
            </Band>
        );
    }

    return (
        <div style={{ position: 'fixed', insetInlineEnd: 18, bottom: 18, zIndex: 60 }}>
            <Link href={href} mode={mode} external ariaLabel="راسلنا على واتساب">
                {pill}
            </Link>
        </div>
    );
}

/* ----------------------------- السجلّ ----------------------------- */

/**
 * النوع ← رسمُه.
 *
 * سجلٌّ لا `switch`: إضافةُ قسمٍ سطرٌ واحد، ونوعٌ لا يعرفه العارض يُقرأ من
 * السجلّ فلا يُوجد — فيُتخطّى بلا انهيار.
 */
export const REGISTRY: Record<string, (props: BlockProps) => React.JSX.Element | null> = {
    hero: Hero,
    image_text: ImageText,
    banner: Banner,
    gallery: Gallery,
    video: Video,
    faq: Faq,
    stats: Stats,
    benefits: Benefits,
    testimonials: Testimonials,
    featured_products: Products,
    latest_products: Products,
    best_sellers: Products,
    product_catalog: ProductCatalog,
    categories: Categories,
    promo: Promo,
    contact: Contact,
    map: MapBlock,
    social: Social,
    whatsapp: Whatsapp,
};

export const KNOWN_TYPES = Object.keys(REGISTRY);

/**
 * قسمٌ واحد — أو لا شيء.
 *
 * ونوعٌ مجهولٌ لا يكسر الصفحة: نسخةٌ نُشرت بقسمٍ أُضيف في أبعاد قبل أن
 * يُنشر العارض تصل إلى هنا، والصواب أن تُعرض الصفحةُ بما تعرفه لا أن تسقط
 * كلُّها لأجل قسمٍ واحد.
 */
export function Block(props: BlockProps) {
    const Renderer = REGISTRY[props.section.type];

    if (!Renderer) {
        if (props.mode === 'edit') {
            return (
                <Band tight>
                    <Empty text="قسمٌ لا يعرفه العارض بعد" mode={props.mode} />
                </Band>
            );
        }

        if (typeof console !== 'undefined') {
            console.warn(`[storefront] نوع قسمٍ غير مدعوم: ${props.section.type}`);
        }

        return null;
    }

    return <Renderer {...props} />;
}

