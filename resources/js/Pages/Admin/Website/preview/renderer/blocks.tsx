import { orderUrl, sells, whatsappUrl } from './commerce';
import { mapEmbed, videoEmbed } from './embed';
import { AtSign, Mail, MapPin, Phone, Play, ShoppingBag, Star, BENEFIT_ICONS } from './icons';
import { money } from './money';
import { Band, Cta, Empty, Grid, Heading, Link, Media, Stars } from './primitives';
import { filled, rows, str } from './read';
import type { DocCategory, DocProduct, DocReview, DocSection, Mode, SiteDocument } from './types';

/**
 * رسمُ الأقسام — قسمٌ واحدٌ لكلّ نوع، بلا قالبٍ ولا نسخة.
 *
 * القالب ألوانٌ وخطٌّ لا كود، فرسمُ القسم واحدٌ في القوالب الستّة ويتبدّل
 * بتبدّل الرموز. ولولا ذلك لصار إصلاحُ عطبٍ في بطاقة المنتج ستّةَ إصلاحات
 * يُنسى سادسُها.
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

/* ------------------------------ بطاقاتٌ ------------------------------ */

function ProductCard({
    p,
    doc,
    mode,
    eager,
}: {
    p: DocProduct;
    doc: SiteDocument;
    mode: Mode;
    eager?: boolean;
}) {
    /*
     * ولا زرَّ سلّةٍ ولا صفحةَ منتج.
     *
     * المستند يحمل الاسم والسعر والصورة — ولا يحمل مخزونًا ولا خياراتٍ ولا
     * وصفًا كاملًا ولا مسارًا للمنتج. فصفحةُ منتجٍ تُبنى منه صفحةٌ ناقصة،
     * وزرُّ سلّةٍ لا سلّة خلفه زرٌّ يكذب.
     *
     * والموجود فعلًا واتساب: من له رقمٌ يظهر عنده زرُّ طلبٍ يفتح محادثةً
     * باسم المنتج وسعره.
     */
    const order = sells(doc.goal) ? orderUrl(doc.brand, p, doc.currency, 'أودّ طلب') : null;

    return (
        <article style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
            <Media src={p.image} alt={p.name} ratio="1 / 1" eager={eager} />
            <div style={{ display: 'flex', flexDirection: 'column', gap: 6, flex: 1 }}>
                <h3 style={{ fontSize: 14, fontWeight: 700, margin: 0, lineHeight: 1.6 }}>{p.name}</h3>
                <p style={{ margin: 0, fontSize: 14, fontWeight: 700, color: 'var(--w-primary)' }}>
                    {money(p.final, doc.currency)}
                    {p.was !== null && (
                        <span
                            style={{
                                marginInlineStart: 8,
                                color: 'var(--w-muted)',
                                textDecoration: 'line-through',
                                fontWeight: 400,
                                fontSize: 12,
                            }}
                        >
                            {money(p.was, doc.currency)}
                        </span>
                    )}
                </p>
                {order && (
                    <Link
                        href={order}
                        mode={mode}
                        external
                        ariaLabel={`اطلب ${p.name} عبر واتساب`}
                        style={{
                            marginTop: 'auto',
                            display: 'inline-flex',
                            alignItems: 'center',
                            justifyContent: 'center',
                            gap: 6,
                            minHeight: 40,
                            borderRadius: 'var(--w-radius)',
                            border: '1.5px solid var(--w-primary)',
                            color: 'var(--w-primary)',
                            fontSize: 13,
                            fontWeight: 700,
                        }}
                    >
                        <Phone size={14} />
                        اطلب عبر واتساب
                    </Link>
                )}
            </div>
        </article>
    );
}

/* ----------------------------- الأقسام ----------------------------- */

function Hero({ section: s, doc, mode }: BlockProps) {
    const align = str(s, 'align', 'center');
    const image = str(s, 'image');
    const overlay = { none: 0, light: 0.25, medium: 0.45, strong: 0.65 }[str(s, 'overlay', 'medium')] ?? 0.45;
    const height = `var(--w-hero-${str(s, 'height', 'medium')}, var(--w-hero-medium))`;

    return (
        <section
            style={{
                minHeight: height,
                display: 'flex',
                alignItems: 'center',
                justifyContent: align === 'center' ? 'center' : align === 'end' ? 'flex-end' : 'flex-start',
                padding: 'var(--w-pad)',
                position: 'relative',
                background: image ? `url(${image}) center/cover` : 'var(--w-surface)',
                textAlign: align === 'center' ? 'center' : 'start',
            }}
        >
            {image && <span aria-hidden style={{ position: 'absolute', inset: 0, background: `rgba(0,0,0,${overlay})` }} />}
            <div style={{ position: 'relative', maxWidth: 640, color: image ? '#fff' : 'inherit' }}>
                <h1 style={{ fontSize: 'var(--w-h1)', fontWeight: 800, margin: 0, lineHeight: 1.3 }}>
                    {str(s, 'title', doc.brand?.name || doc.name)}
                </h1>
                {str(s, 'subtitle') && (
                    <p style={{ fontSize: 17, marginTop: 14, opacity: 0.92, lineHeight: 1.85 }}>{str(s, 'subtitle')}</p>
                )}
                {str(s, 'cta_label') && (
                    <div style={{ marginTop: 22 }}>
                        <Cta label={str(s, 'cta_label')} href={str(s, 'cta_href', '/')} mode={mode} />
                    </div>
                )}
            </div>
        </section>
    );
}

function ImageText({ section: s, mode, first }: BlockProps) {
    const start = str(s, 'side', 'start') === 'start';
    const media = <Media src={str(s, 'image') || null} alt={str(s, 'title')} eager={first} />;
    const text = (
        <div>
            <h2 style={{ fontSize: 'var(--w-h2)', fontWeight: 800, margin: 0, lineHeight: 1.4 }}>{str(s, 'title')}</h2>
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

function Gallery({ section: s, mode, first }: BlockProps) {
    const list = filled(rows<{ src: string; alt: string }>(s, 'images'), ['src']);
    const columns = Number(str(s, 'columns', '3')) || 3;

    if (list.length === 0) {
        return (
            <Band>
                <Heading title={str(s, 'title')} />
                <Empty text="لا صور بعد — أضف صورًا من محلّك أو من أعمالك" mode={mode} />
            </Band>
        );
    }

    return (
        <Band>
            <Heading title={str(s, 'title')} />
            <Grid columns={columns} min={200}>
                {list.map((g, i) => (
                    <Media key={i} src={g.src} alt={g.alt} ratio="1 / 1" eager={first && i === 0} />
                ))}
            </Grid>
        </Band>
    );
}

function Video({ section: s, mode }: BlockProps) {
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
            <Heading title={title} />
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

function Faq({ section: s, mode }: BlockProps) {
    const list = filled(rows<{ q: string; a: string }>(s, 'items'), ['q', 'a']);

    return (
        <Band>
            <Heading title={str(s, 'title')} />
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

function Benefits({ section: s, mode }: BlockProps) {
    const list = filled(rows<{ icon: string; title: string; text: string }>(s, 'items'), ['title', 'text']);

    if (list.length === 0) {
        return (
            <Band>
                <Heading title={str(s, 'title')} />
                <Empty text="أضف ما يميّزك — توصيلٌ سريع، ضمان، خدمةٌ قريبة" mode={mode} />
            </Band>
        );
    }

    return (
        <Band>
            <Heading title={str(s, 'title')} />
            <Grid columns={Math.min(3, list.length)} min={220}>
                {list.map((b, i) => {
                    const Icon = BENEFIT_ICONS[b.icon] ?? Star;

                    return (
                        <div key={i} style={{ textAlign: 'center' }}>
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

function Testimonials({ section: s, mode }: BlockProps) {
    const items = (s.items ?? []) as DocReview[];

    return (
        <Band tone="surface">
            <Heading title={str(s, 'title')} />
            {items.length === 0 ? (
                <Empty text="لا تقييمات منشورة بعد — تظهر هنا حين ينشرها زبائنك" mode={mode} />
            ) : (
                <Grid columns={Math.min(3, items.length)} min={250}>
                    {items.map((r, i) => (
                        <blockquote
                            key={i}
                            style={{
                                margin: 0,
                                background: 'var(--w-bg)',
                                border: '1px solid var(--w-border)',
                                borderRadius: 'var(--w-radius)',
                                padding: 20,
                            }}
                        >
                            <Stars rating={r.rating} />
                            <p style={{ margin: 0, fontSize: 14, lineHeight: 1.95 }}>{r.comment}</p>
                            <footer style={{ color: 'var(--w-muted)', fontSize: 12, marginTop: 12 }}>{r.author}</footer>
                        </blockquote>
                    ))}
                </Grid>
            )}
        </Band>
    );
}

function Products({ section: s, doc, mode, first }: BlockProps) {
    const items = (s.items ?? []) as DocProduct[];
    const columns = Number(str(s, 'columns', '4')) || 4;

    return (
        <Band>
            <Heading title={str(s, 'title')} />
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
                <Grid columns={columns} min={190}>
                    {items.map((p, i) => (
                        <ProductCard key={p.id} p={p} doc={doc} mode={mode} eager={first && i < 4} />
                    ))}
                </Grid>
            )}
        </Band>
    );
}

function Categories({ section: s, mode }: BlockProps) {
    const items = (s.items ?? []) as DocCategory[];
    const style = str(s, 'style', 'cards');

    if (items.length === 0) {
        return (
            <Band tone="surface">
                <Heading title={str(s, 'title')} />
                <Empty text="لا تصنيفات بعد — أضف تصنيفاتٍ لمنتجاتك وستظهر هنا" mode={mode} />
            </Band>
        );
    }

    /*
     * والتصنيف لا يُنقر: لا مسارَ لصفحة تصنيفٍ في هذا العارض بعد.
     * فهي أسماءٌ تُقرأ — وزرٌّ يبدو قابلًا للضغط ولا يفتح شيئًا يُغضب.
     */
    if (style === 'pills') {
        return (
            <Band tone="surface" tight>
                <Heading title={str(s, 'title')} />
                <ul
                    style={{
                        listStyle: 'none',
                        margin: 0,
                        padding: 0,
                        display: 'flex',
                        gap: 10,
                        flexWrap: 'wrap',
                        justifyContent: 'center',
                    }}
                >
                    {items.map((c) => (
                        <li
                            key={c.id}
                            style={{
                                border: '1px solid var(--w-border)',
                                borderRadius: 999,
                                padding: '10px 18px',
                                background: 'var(--w-bg)',
                                fontSize: 14,
                                fontWeight: 700,
                            }}
                        >
                            {c.name}
                        </li>
                    ))}
                </ul>
            </Band>
        );
    }

    return (
        <Band tone="surface">
            <Heading title={str(s, 'title')} />
            <Grid columns={Math.min(4, items.length)} min={160}>
                {items.map((c) => (
                    <div
                        key={c.id}
                        style={{
                            background: 'var(--w-bg)',
                            border: '1px solid var(--w-border)',
                            borderRadius: 'var(--w-radius)',
                            padding: style === 'grid' ? '0' : '22px 14px',
                            overflow: 'hidden',
                            textAlign: 'center',
                            fontWeight: 700,
                            fontSize: 14,
                        }}
                    >
                        {style === 'grid' && (
                            <div
                                aria-hidden
                                style={{
                                    aspectRatio: '4 / 3',
                                    background: c.color || 'var(--w-surface)',
                                    display: 'flex',
                                    alignItems: 'center',
                                    justifyContent: 'center',
                                    color: 'var(--w-muted)',
                                }}
                            >
                                <ShoppingBag size={26} />
                            </div>
                        )}
                        <span style={{ display: 'block', padding: style === 'grid' ? '14px 10px' : 0 }}>{c.name}</span>
                    </div>
                ))}
            </Grid>
        </Band>
    );
}

function Promo({ section: s, mode }: BlockProps) {
    return (
        <Band tone="primary">
            <div
                style={{
                    display: 'grid',
                    gap: 26,
                    gridTemplateColumns: 'repeat(auto-fit, minmax(min(240px, 100%), 1fr))',
                    alignItems: 'center',
                }}
            >
                <div>
                    <h2 style={{ fontSize: 'var(--w-h2)', fontWeight: 800, margin: 0, lineHeight: 1.4 }}>
                        {str(s, 'title')}
                    </h2>
                    <p style={{ marginTop: 10, opacity: 0.93, lineHeight: 1.9 }}>{str(s, 'text')}</p>
                    {str(s, 'cta_label') && (
                        <div style={{ marginTop: 18 }}>
                            <Cta label={str(s, 'cta_label')} href={str(s, 'cta_href', '/')} mode={mode} ghost />
                        </div>
                    )}
                </div>
                {str(s, 'image') && <Media src={str(s, 'image')} alt={str(s, 'title')} ratio="16 / 9" />}
            </div>
        </Band>
    );
}

function Contact({ section: s, doc, mode }: BlockProps) {
    const brand = doc.brand;
    const phone = str(s, 'phone', brand?.phone ?? '');
    const email = str(s, 'email', brand?.email ?? '');
    const address = str(s, 'address', brand?.address ?? '');
    const wa = whatsappUrl(str(s, 'whatsapp', brand?.whatsapp ?? ''));

    const lines = [
        { Icon: Phone, value: phone, href: phone ? `tel:${phone.replace(/\s+/g, '')}` : null, external: false },
        { Icon: Mail, value: email, href: email ? `mailto:${email}` : null, external: false },
        { Icon: MapPin, value: address, href: null, external: false },
    ].filter((l) => l.value !== '');

    if (lines.length === 0 && !wa) {
        return (
            <Band>
                <Heading title={str(s, 'title')} sub={str(s, 'text')} />
                <Empty text="أضف هاتفك أو بريدك ليصل إليك زبائنك" mode={mode} />
            </Band>
        );
    }

    return (
        <Band>
            <Heading title={str(s, 'title')} sub={str(s, 'text')} />
            <div style={{ display: 'grid', gap: 14, maxWidth: 460, margin: '0 auto' }}>
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
            <Heading title={str(s, 'title')} />
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
            <Heading title={str(s, 'title')} />
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
