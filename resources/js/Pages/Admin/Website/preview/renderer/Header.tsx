import { whatsappUrl } from './commerce';
import { Phone } from './icons';
import MobileNav from './MobileNav';
import { Link } from './primitives';
import { bool, rows, str } from './read';
import type { DocSection, Mode, SiteDocument } from './types';

/**
 * الترويسة — الشعارُ والقائمةُ وما يعمل فعلًا.
 *
 * ومفتاحان في وصف القسم لا يُرسمان هنا: «إظهار البحث» و«إظهار السلّة».
 * لا بحثَ في عقد المستند — لا مسار يُسأل عن منتجٍ باسمه — ولا سلّةَ ولا
 * دفعًا في هذا العارض. وأيقونةٌ تُعرض ولا تعمل أسوأ من غيابها: من ضغط
 * السلّة مرّتين ولم يحدث شيء يظنّ المتجر معطوبًا فيغادر. فتُترك حتى تُبنى.
 *
 * وزرّ واتساب يُعرض لأنّه يعمل: رقمٌ في إعدادات التاجر يفتح محادثة.
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
    const preset = str(section, 'preset', 'simple');
    const centered = preset === 'centered';
    const sticky = bool(section, 'sticky', true);
    const brand = doc.brand;
    const wa = bool(section, 'show_whatsapp', true) ? whatsappUrl(brand?.whatsapp) : null;
    const name = brand?.name || doc.name;

    const logo = (
        <Link href={homeHref} mode={mode} style={{ display: 'flex', alignItems: 'center', gap: 10, flex: 'none' }}>
            {brand?.logo ? (
                <img
                    src={brand.logo}
                    alt={name}
                    style={{ height: 38, width: 'auto', maxWidth: 160, objectFit: 'contain', display: 'block' }}
                />
            ) : (
                <strong style={{ fontSize: 18, fontWeight: 800 }}>{name}</strong>
            )}
        </Link>
    );

    const nav = links.length > 0 && (
        <nav
            className="w-nav-desktop"
            aria-label={str(section, 'nav_label', 'أقسام الموقع')}
            style={{ gap: 22, flexWrap: 'wrap', fontSize: 15, alignItems: 'center' }}
        >
            {links.map((l, i) => (
                <Link key={i} href={l.href} mode={mode} style={{ color: 'var(--w-muted)', fontWeight: 600 }}>
                    {l.label}
                </Link>
            ))}
        </nav>
    );

    return (
        <header
            style={{
                borderBottom: '1px solid var(--w-border)',
                background: 'var(--w-bg)',
                padding: '14px 18px',
                // والتثبيت لا يعمل في المعاينة: إطارُها يُمرَّر لا الصفحة
                position: sticky && mode === 'live' ? 'sticky' : undefined,
                top: sticky && mode === 'live' ? 0 : undefined,
                zIndex: 40,
            }}
        >
            <div
                style={{
                    maxWidth: 1120,
                    margin: '0 auto',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: centered ? 'center' : 'space-between',
                    flexDirection: centered ? 'column' : 'row',
                    flexWrap: 'wrap',
                    gap: centered ? 12 : 16,
                }}
            >
                {logo}
                {nav}

                <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                    {wa && (
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
                                background: 'var(--w-btn-bg)',
                                color: 'var(--w-btn-fg)',
                                border: '1.5px solid var(--w-btn-border)',
                                fontWeight: 700,
                                fontSize: 14,
                            }}
                        >
                            <Phone size={17} />
                        </Link>
                    )}

                    {!centered && <MobileNav links={links} mode={mode} />}
                </div>

                {/*
                 * والشعارُ في الوسط تحته قائمتُه — لا زرٌّ يفتحها.
                 * القائمة في هذا الشكل تحت الشعار أصلًا، فتظهر كما هي.
                 */}
                {centered && links.length > 0 && (
                    <nav
                        aria-label={str(section, 'nav_label', 'أقسام الموقع')}
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
            </div>
        </header>
    );
}
