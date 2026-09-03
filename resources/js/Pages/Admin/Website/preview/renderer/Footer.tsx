import { AtSign, Mail, MapPin, Phone } from './icons';
import { Link } from './primitives';
import { bool, rows, str } from './read';
import type { DocSection, Mode, SiteDocument } from './types';

/**
 * التذييل — أربعة أعمدةٍ لا يُرسم منها إلا ما له بيانات.
 *
 * وثلاثةُ مفاتيحه («معلومات التواصل» و«حسابات التواصل» و«طرق الدفع») كانت
 * تُحفظ ولا يقرؤها شيء: بياناتُها ليست في القسم، بل في بيانات النشاط وفي
 * إعدادات نقطة البيع. فصارت تُقرأ من هناك عبر `brand` في المستند — ولا
 * يُسأل التاجر عن هاتفه مرّةً ثانية لأنّ التذييل يحتاجه.
 *
 * وعمودٌ بلا بيانات لا يُرسم عنوانًا فارغًا: تاجرٌ بلا حسابات تواصل لا يريد
 * كلمة «تابعنا» تحتها فراغ.
 */
export function Footer({
    section,
    doc,
    mode,
    labels,
}: {
    section: DocSection;
    doc: SiteDocument;
    mode: Mode;
    labels?: { links?: string; contact?: string; social?: string; payments?: string };
}) {
    const t = {
        links: labels?.links ?? 'روابط',
        contact: labels?.contact ?? 'تواصل معنا',
        social: labels?.social ?? 'تابعنا',
        payments: labels?.payments ?? 'طرق الدفع',
    };

    const links = rows<{ label: string; href: string }>(section, 'links').filter((l) => l.label && l.href);
    const about = str(section, 'about');
    const copyright = str(section, 'copyright');
    const brand = doc.brand;
    const name = brand?.name || doc.name;

    const contact = bool(section, 'show_contact', true)
        ? [
              { icon: Phone, value: brand?.phone, href: brand?.phone ? `tel:${brand.phone.replace(/\s+/g, '')}` : null },
              { icon: Mail, value: brand?.email, href: brand?.email ? `mailto:${brand.email}` : null },
              { icon: MapPin, value: brand?.address, href: null },
          ].filter((row) => (row.value ?? '') !== '')
        : [];

    const social = bool(section, 'show_social', true) ? (brand?.social ?? []) : [];
    const payments = bool(section, 'show_payments', true) ? (brand?.payments ?? []) : [];

    return (
        <footer
            style={{
                borderTop: '1px solid var(--w-border)',
                background: 'var(--w-surface)',
                padding: '40px 18px 24px',
                marginTop: 24,
            }}
        >
            <div
                style={{
                    maxWidth: 1120,
                    margin: '0 auto',
                    display: 'grid',
                    gap: 28,
                    gridTemplateColumns: 'repeat(auto-fit, minmax(min(220px, 100%), 1fr))',
                }}
            >
                <div>
                    {brand?.logo ? (
                        <img
                            src={brand.logo}
                            alt={name}
                            loading="lazy"
                            decoding="async"
                            style={{ height: 34, width: 'auto', maxWidth: 150, objectFit: 'contain', display: 'block' }}
                        />
                    ) : (
                        <strong style={{ fontSize: 16, fontWeight: 800 }}>{name}</strong>
                    )}
                    {about && (
                        <p style={{ color: 'var(--w-muted)', fontSize: 13, marginTop: 10, lineHeight: 1.9 }}>{about}</p>
                    )}
                </div>

                {links.length > 0 && (
                    <nav aria-label={t.links}>
                        <h2 style={{ fontSize: 14, fontWeight: 800, margin: '0 0 10px' }}>{t.links}</h2>
                        <ul style={{ listStyle: 'none', margin: 0, padding: 0, display: 'grid', gap: 4, fontSize: 13.5 }}>
                            {links.map((l, i) => (
                                <li key={i}>
                                    <Link
                                        href={l.href}
                                        mode={mode}
                                        style={{
                                            color: 'var(--w-muted)',
                                            display: 'inline-flex',
                                            alignItems: 'center',
                                            minHeight: 32,
                                        }}
                                    >
                                        {l.label}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </nav>
                )}

                {contact.length > 0 && (
                    <div>
                        <h2 style={{ fontSize: 14, fontWeight: 800, margin: '0 0 10px' }}>{t.contact}</h2>
                        <ul style={{ listStyle: 'none', margin: 0, padding: 0, display: 'grid', gap: 8, fontSize: 13.5 }}>
                            {contact.map(({ icon: Icon, value, href }, i) => (
                                <li key={i} style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                                    <Icon size={15} style={{ color: 'var(--w-primary)', flex: 'none' }} />
                                    {href ? (
                                        <Link href={href} mode={mode} style={{ color: 'var(--w-muted)' }}>
                                            <span dir="auto">{value}</span>
                                        </Link>
                                    ) : (
                                        <span dir="auto" style={{ color: 'var(--w-muted)' }}>
                                            {value}
                                        </span>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </div>
                )}

                {(social.length > 0 || payments.length > 0) && (
                    <div style={{ display: 'grid', gap: 18, alignContent: 'start' }}>
                        {social.length > 0 && (
                            <div>
                                <h2 style={{ fontSize: 14, fontWeight: 800, margin: '0 0 10px' }}>{t.social}</h2>
                                <ul
                                    style={{
                                        listStyle: 'none',
                                        margin: 0,
                                        padding: 0,
                                        display: 'flex',
                                        gap: 8,
                                        flexWrap: 'wrap',
                                    }}
                                >
                                    {social.map((s) => (
                                        <li key={s.network}>
                                            <Link
                                                href={s.url}
                                                mode={mode}
                                                external
                                                ariaLabel={`${s.label} — ${s.value}`}
                                                style={{
                                                    display: 'inline-flex',
                                                    alignItems: 'center',
                                                    justifyContent: 'center',
                                                    width: 40,
                                                    height: 40,
                                                    borderRadius: 'var(--w-radius)',
                                                    border: '1px solid var(--w-border)',
                                                    color: 'var(--w-primary)',
                                                }}
                                            >
                                                <AtSign size={17} />
                                            </Link>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}

                        {payments.length > 0 && (
                            <div>
                                <h2 style={{ fontSize: 14, fontWeight: 800, margin: '0 0 10px' }}>{t.payments}</h2>
                                <ul
                                    style={{
                                        listStyle: 'none',
                                        margin: 0,
                                        padding: 0,
                                        display: 'flex',
                                        gap: 6,
                                        flexWrap: 'wrap',
                                    }}
                                >
                                    {payments.map((p) => (
                                        <li
                                            key={p}
                                            style={{
                                                border: '1px solid var(--w-border)',
                                                borderRadius: 'var(--w-radius)',
                                                padding: '5px 10px',
                                                fontSize: 12.5,
                                                color: 'var(--w-muted)',
                                                background: 'var(--w-bg)',
                                            }}
                                        >
                                            {p}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                    </div>
                )}
            </div>

            {copyright && (
                <p
                    style={{
                        maxWidth: 1120,
                        margin: '28px auto 0',
                        paddingTop: 16,
                        borderTop: '1px solid var(--w-border)',
                        color: 'var(--w-muted)',
                        fontSize: 12,
                        textAlign: 'center',
                    }}
                >
                    {copyright}
                </p>
            )}
        </footer>
    );
}
