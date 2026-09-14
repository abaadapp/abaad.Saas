import { sells, whatsappUrl } from './commerce';
import { Phone, Search } from './icons';
import { layoutOf, variant } from './layout';
import MobileNav from './MobileNav';
import { Link } from './primitives';
import { bool, rows, str } from './read';
import type { DocSection, Mode, SiteDocument } from './types';

/**
 * الترويسة — أربعةُ أشكالٍ لا شكلٌ واحد بلونين.
 *
 * الترويسةُ أوّلُ ما يُقرأ من الموقع وآخرُ ما يُنسى منه، وهي أظهرُ ما يفرّق
 * متجرًا عن متجر: متجرٌ فيه ألفُ صنف يحتاج بحثًا وأقسامًا في الوجه، ومحلُّ
 * ورودٍ يحتاج اسمَه وثلاثةَ روابط وبياضًا حولها. فترويسةٌ واحدة للاثنين
 * تعني أنّ أحدهما يلبس ثوبَ الآخر.
 *
 * ── الأربعة ──
 *
 * `minimal` شعارٌ وقائمةٌ في سطرٍ واحد — وهو ما كان.
 * `centered` الشعارُ في الوسط وتحته قائمتُه.
 * `commerce` سطران: شعارٌ وبحثٌ وأزرار، ثمّ شريطُ أقسامٍ تحته.
 * `editorial` سطرٌ عالٍ متباعدُ الحروف بلا خلفية — للقوالب التحريرية.
 *
 * ── ما لا يُرسم ──
 *
 * «إظهار السلّة» يُحفظ ولا يُقرأ: لا سلّةَ في هذا العارض ولا دفعًا. وأيقونةٌ
 * تُعرض ولا تعمل أسوأ من غيابها — من ضغط السلّة مرّتين ولم يحدث شيء يظنّ
 * المتجر معطوبًا فيغادر. فتُترك حتى تُبنى.
 *
 * وزرّ واتساب يُعرض لأنّه يعمل، والبحث يُعرض لأنّه صار يعمل: يُرسل إلى صفحة
 * المتجر فترشّح `Catalog` قائمتَها به. ولولا ذلك لبقي صندوقًا للزينة.
 */
export function Header({
    section,
    doc,
    mode,
    homeHref = '/',
}: {
    section: DocSection;
    doc: SiteDocument;
    mode: Mode;
    homeHref?: string;
}) {
    const links = rows<{ label: string; href: string }>(section, 'links').filter((l) => l.label && l.href);
    const shape = variant(section, 'header', layoutOf(doc).header, 'preset');
    const sticky = bool(section, 'sticky', true);
    const brand = doc.brand;
    const wa = bool(section, 'show_whatsapp', true) ? whatsappUrl(brand?.whatsapp) : null;
    const name = brand?.name || doc.name;
    const search = bool(section, 'show_search', false) && sells(doc.goal);
    const navLabel = str(section, 'nav_label', 'أقسام الموقع');
    const editorial = shape === 'editorial';

    const logo = (
        <Link
            href={homeHref}
            mode={mode}
            style={{ display: 'flex', alignItems: 'center', gap: 10, flex: 'none', minWidth: 0 }}
        >
            {brand?.logo ? (
                <img
                    src={brand.logo}
                    alt={name}
                    style={{
                        height: editorial ? 44 : 38,
                        width: 'auto',
                        maxWidth: 180,
                        objectFit: 'contain',
                        display: 'block',
                    }}
                />
            ) : (
                <strong
                    className="w-display"
                    style={{
                        fontSize: editorial ? 22 : 18,
                        fontWeight: editorial ? 500 : 800,
                        letterSpacing: editorial ? '0.14em' : undefined,
                    }}
                >
                    {name}
                </strong>
            )}
        </Link>
    );

    const nav = (extra?: React.CSSProperties) =>
        links.length > 0 && (
            <nav
                className="w-nav-desktop"
                aria-label={navLabel}
                style={{
                    gap: editorial ? 30 : 22,
                    flexWrap: 'wrap',
                    fontSize: editorial ? 13.5 : 15,
                    alignItems: 'center',
                    ...extra,
                }}
            >
                {links.map((l, i) => (
                    <Link
                        key={i}
                        href={l.href}
                        mode={mode}
                        style={{
                            color: 'var(--w-muted)',
                            fontWeight: editorial ? 500 : 600,
                            letterSpacing: editorial ? '0.1em' : undefined,
                        }}
                    >
                        {l.label}
                    </Link>
                ))}
            </nav>
        );

    const phone = wa && (
        <Link
            href={wa}
            mode={mode}
            external
            ariaLabel={str(section, 'wa_label', 'تواصل عبر واتساب')}
            style={{
                display: 'inline-flex',
                alignItems: 'center',
                justifyContent: 'center',
                gap: 8,
                minHeight: 44,
                minWidth: 44,
                padding: '0 14px',
                borderRadius: 'var(--w-radius)',
                background: editorial ? 'transparent' : 'var(--w-btn-bg)',
                color: editorial ? 'inherit' : 'var(--w-btn-fg)',
                border: `1.5px solid ${editorial ? 'var(--w-border)' : 'var(--w-btn-border)'}`,
                fontWeight: 700,
                fontSize: 14,
            }}
        >
            <Phone size={17} />
        </Link>
    );

    const bar = (children: React.ReactNode, extra?: React.CSSProperties) => (
        <header
            style={{
                borderBottom: '1px solid var(--w-border)',
                background: 'var(--w-bg)',
                // والتثبيت لا يعمل في المعاينة: إطارُها يُمرَّر لا الصفحة
                position: sticky && mode === 'live' ? 'sticky' : undefined,
                top: sticky && mode === 'live' ? 0 : undefined,
                zIndex: 40,
                ...extra,
            }}
        >
            {children}
        </header>
    );

    const shell = (children: React.ReactNode, pad: string) => (
        <div style={{ padding: pad }}>
            <div style={{ maxWidth: 'var(--w-content)', margin: '0 auto' }}>{children}</div>
        </div>
    );

    /* ---------------------------- تجاريّة ---------------------------- */

    if (shape === 'commerce') {
        return bar(
            <>
                {shell(
                    <div style={{ display: 'flex', alignItems: 'center', gap: 16 }}>
                        {logo}
                        {search && (
                            <div className="w-desk" style={{ flex: 1, maxWidth: 520, marginInline: 'auto' }}>
                                <SearchBox mode={mode} />
                            </div>
                        )}
                        <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginInlineStart: 'auto' }}>
                            {phone}
                            <MobileNav links={links} mode={mode} />
                        </div>
                    </div>,
                    '12px 18px',
                )}

                {/*
                 * شريطُ الأقسام سطرٌ ثانٍ — وعلى الهاتف يُمرَّر بالإصبع.
                 *
                 * متجرٌ بثمانية أقسام في شريطٍ يلتفّ يصير ثلاثة أسطرٍ تدفع
                 * المحتوى إلى أسفل الشاشة. والتمرير الأفقيّ هنا مقصودٌ
                 * محصورٌ في الشريط — لا في الصفحة.
                 */}
                {links.length > 0 && (
                    <div style={{ borderTop: '1px solid var(--w-border)', background: 'var(--w-surface)' }}>
                        {shell(
                            <nav aria-label={navLabel} className="w-rail" style={{ gap: 4 }}>
                                {links.map((l, i) => (
                                    <Link
                                        key={i}
                                        href={l.href}
                                        mode={mode}
                                        style={{
                                            display: 'inline-flex',
                                            alignItems: 'center',
                                            minHeight: 42,
                                            padding: '0 12px',
                                            fontSize: 14,
                                            fontWeight: 700,
                                            whiteSpace: 'nowrap',
                                        }}
                                    >
                                        {l.label}
                                    </Link>
                                ))}
                            </nav>,
                            '0 18px',
                        )}
                    </div>
                )}

                {search && (
                    <div className="w-mob" style={{ borderTop: '1px solid var(--w-border)' }}>
                        {shell(<SearchBox mode={mode} />, '10px 18px')}
                    </div>
                )}
            </>,
        );
    }

    /* ---------------------------- وسطيّة ---------------------------- */

    if (shape === 'centered') {
        return bar(
            shell(
                <div style={{ display: 'grid', justifyItems: 'center', gap: 12 }}>
                    {logo}
                    {search && <div style={{ width: '100%', maxWidth: 460 }}>{<SearchBox mode={mode} />}</div>}
                    {/*
                     * والشعارُ في الوسط تحته قائمتُه — لا زرٌّ يفتحها.
                     * القائمة في هذا الشكل تحت الشعار أصلًا، فتظهر كما هي.
                     */}
                    {links.length > 0 && (
                        <nav
                            aria-label={navLabel}
                            style={{
                                display: 'flex',
                                gap: 18,
                                flexWrap: 'wrap',
                                justifyContent: 'center',
                                fontSize: 14.5,
                            }}
                        >
                            {links.map((l, i) => (
                                <Link
                                    key={i}
                                    href={l.href}
                                    mode={mode}
                                    style={{
                                        color: 'var(--w-muted)',
                                        fontWeight: 600,
                                        minHeight: 44,
                                        display: 'inline-flex',
                                        alignItems: 'center',
                                    }}
                                >
                                    {l.label}
                                </Link>
                            ))}
                        </nav>
                    )}
                    {phone}
                </div>,
                '14px 18px',
            ),
        );
    }

    /* -------------------- تحريريّة وبسيطة -------------------- */

    return bar(
        shell(
            <div
                style={{
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'space-between',
                    gap: 16,
                    flexWrap: 'wrap',
                }}
            >
                {logo}
                {nav(editorial ? { marginInline: 'auto' } : undefined)}

                <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                    {search && (
                        <div className="w-desk" style={{ width: 210 }}>
                            <SearchBox mode={mode} />
                        </div>
                    )}
                    {phone}
                    <MobileNav links={links} mode={mode} />
                </div>
            </div>,
            editorial ? '26px 18px' : '14px 18px',
        ),
        editorial ? { borderBottomColor: 'var(--w-card-border)' } : undefined,
    );
}

/**
 * صندوق البحث — نموذجٌ يذهب إلى صفحة المتجر.
 *
 * ولا JavaScript فيه ولا مسارَ بحثٍ في الخادم: يُرسل `?q=` إلى `/shop`،
 * وقسمُ «كلّ المنتجات» هناك يقرؤه فيرشّح ما يعرضه. فهو بحثٌ يعمل بلا طلبٍ
 * ثانٍ وبلا فهرس — ومداه ما في تلك الصفحة، وهو كلّ الكتالوج.
 *
 * وفي المعاينة ليس نموذجًا أصلًا بل صندوقٌ يشبهه: نموذجٌ يُرسل من داخل لوحة
 * أبعاد يخرج التاجر من محرّره. ولا حارسَ بـ`onSubmit` — مُعالجُ حدثٍ في
 * مكوّنٍ يُرسم على الخادم لا يُسمح به أصلًا في Next، والفرقُ في الوسم أصدق
 * منه وأرخص.
 */
function SearchBox({ mode }: { mode: Mode }) {
    const Box = mode === 'live' ? 'form' : 'div';

    return (
        <Box
            {...(mode === 'live' ? { action: '/shop', method: 'get', role: 'search' } : {})}
            style={{
                display: 'flex',
                alignItems: 'center',
                gap: 8,
                border: '1px solid var(--w-border)',
                borderRadius: 'var(--w-radius)',
                background: 'var(--w-bg)',
                paddingInline: 12,
                minHeight: 44,
            }}
        >
            <Search size={17} style={{ color: 'var(--w-muted)', flex: 'none' }} />
            <input
                type="search"
                name="q"
                placeholder="ابحث عن منتج"
                aria-label="ابحث عن منتج"
                style={{
                    flex: 1,
                    minWidth: 0,
                    border: 0,
                    outline: 'none',
                    background: 'transparent',
                    color: 'inherit',
                    fontFamily: 'inherit',
                    fontSize: 14,
                    padding: '10px 0',
                }}
            />
        </Box>
    );
}
