import { AtSign, Mail, MapPin, Phone } from './icons';
import { layoutOf } from './layout';
import { Link } from './primitives';
import { bool, rows, str } from './read';
import type { DocSection, DocSocial, Mode, SiteDocument } from './types';

/**
 * التذييل — أربعةُ أشكال، ولا يُرسم منها إلّا ما له بيانات.
 *
 * `columns` أربعةُ أعمدة: نبذةٌ وروابطُ وتواصلٌ وحسابات — تذييلُ متجرٍ فيه
 * ما يُبحث عنه. و`minimal` سطرٌ واحد: اسمٌ وروابطُ وحقوق — لا يُثقل صفحةً
 * بُنيت على البياض. و`brand` العلامةُ أوّلًا في الوسط وتحتها ما بقي — لمن
 * موقعُه واجهةُ علامةٍ لا دليلُ متجر. و`split` كتلةُ العلامة إلى جانب
 * عمودَي روابطَ وتواصل — بين هدوء الأوّل وكثافة الثاني.
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

    const shape = layoutOf(doc).footer;
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

    const wordmark = (size: number) =>
        brand?.logo ? (
            <img
                src={brand.logo}
                alt={name}
                loading="lazy"
                decoding="async"
                style={{ height: size, width: 'auto', maxWidth: 170, objectFit: 'contain', display: 'block' }}
            />
        ) : (
            <strong className="w-display" style={{ fontSize: size / 2 + 4, fontWeight: 800 }}>
                {name}
            </strong>
        );

    const linkList = (
        <ul style={{ listStyle: 'none', margin: 0, padding: 0, display: 'grid', gap: 4, fontSize: 13.5 }}>
            {links.map((l, i) => (
                <li key={i}>
                    <Link
                        href={l.href}
                        mode={mode}
                        style={{ color: 'var(--w-muted)', display: 'inline-flex', alignItems: 'center', minHeight: 32 }}
                    >
                        {l.label}
                    </Link>
                </li>
            ))}
        </ul>
    );

    const shell = (children: React.ReactNode) => (
        <footer
            style={{
                borderTop: '1px solid var(--w-border)',
                background: shape === 'columns' ? 'var(--w-surface)' : 'var(--w-bg)',
                padding: shape === 'minimal' ? 'var(--w-pad-tight)' : 'var(--w-pad)',
                marginTop: 24,
            }}
        >
            {children}
        </footer>
    );

    const rights = copyright && (
        <p
            style={{
                maxWidth: 'var(--w-content)',
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
    );

    /* ---------------------------- نحيف ---------------------------- */

    if (shape === 'minimal') {
        return shell(
            <div
                style={{
                    maxWidth: 'var(--w-content)',
                    margin: '0 auto',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'space-between',
                    flexWrap: 'wrap',
                    gap: 16,
                }}
            >
                {wordmark(28)}

                {links.length > 0 && (
                    <nav
                        aria-label={t.links}
                        style={{ display: 'flex', gap: 18, flexWrap: 'wrap', fontSize: 13.5, alignItems: 'center' }}
                    >
                        {links.map((l, i) => (
                            <Link
                                key={i}
                                href={l.href}
                                mode={mode}
                                style={{
                                    color: 'var(--w-muted)',
                                    minHeight: 36,
                                    display: 'inline-flex',
                                    alignItems: 'center',
                                }}
                            >
                                {l.label}
                            </Link>
                        ))}
                    </nav>
                )}

                <SocialRow list={social} mode={mode} bare />

                {copyright && (
                    <p style={{ color: 'var(--w-muted)', fontSize: 12, margin: 0, width: '100%', textAlign: 'center' }}>
                        {copyright}
                    </p>
                )}
            </div>,
        );
    }

    /* --------------------------- العلامة أوّلًا --------------------------- */

    if (shape === 'brand') {
        return shell(
            <>
                <div
                    style={{
                        maxWidth: 620,
                        margin: '0 auto',
                        display: 'grid',
                        justifyItems: 'center',
                        textAlign: 'center',
                        gap: 16,
                    }}
                >
                    <span
                        aria-hidden
                        style={{ display: 'block', width: 52, height: 1, background: 'var(--w-primary)', opacity: 0.7 }}
                    />
                    {wordmark(48)}
                    {about && (
                        <p style={{ color: 'var(--w-muted)', fontSize: 14, margin: 0, lineHeight: 2 }}>{about}</p>
                    )}
                    <SocialRow list={social} mode={mode} />
                </div>

                {(links.length > 0 || contact.length > 0) && (
                    <div
                        style={{
                            maxWidth: 'var(--w-content)',
                            margin: '32px auto 0',
                            display: 'flex',
                            flexWrap: 'wrap',
                            justifyContent: 'center',
                            gap: '10px 24px',
                            fontSize: 13.5,
                        }}
                    >
                        {links.map((l, i) => (
                            <Link
                                key={i}
                                href={l.href}
                                mode={mode}
                                style={{
                                    color: 'var(--w-muted)',
                                    minHeight: 36,
                                    display: 'inline-flex',
                                    alignItems: 'center',
                                }}
                            >
                                {l.label}
                            </Link>
                        ))}
                        {contact.map(({ icon: Icon, value, href }, i) => (
                            <span
                                key={`c${i}`}
                                style={{ display: 'inline-flex', alignItems: 'center', gap: 6, minHeight: 36 }}
                            >
                                <Icon size={14} style={{ color: 'var(--w-primary)', flex: 'none' }} />
                                {href ? (
                                    <Link href={href} mode={mode} style={{ color: 'var(--w-muted)' }}>
                                        <span dir="auto">{value}</span>
                                    </Link>
                                ) : (
                                    <span dir="auto" style={{ color: 'var(--w-muted)' }}>
                                        {value}
                                    </span>
                                )}
                            </span>
                        ))}
                    </div>
                )}

                <PaymentsRow list={payments} label={t.payments} center />
                {rights}
            </>,
        );
    }

    /* ---------------------------- مشطور ---------------------------- */

    if (shape === 'split') {
        return shell(
            <>
                <div
                    className="w-footer-split"
                    style={{ maxWidth: 'var(--w-content)', margin: '0 auto' }}
                >
                    <div style={{ display: 'grid', gap: 14, alignContent: 'start' }}>
                        {wordmark(38)}
                        {about && (
                            <p style={{ color: 'var(--w-muted)', fontSize: 14, margin: 0, lineHeight: 2, maxWidth: '42ch' }}>
                                {about}
                            </p>
                        )}
                        <SocialRow list={social} mode={mode} />
                    </div>

                    <div
                        style={{
                            display: 'grid',
                            gap: 24,
                            gridTemplateColumns: 'repeat(auto-fit, minmax(min(160px, 100%), 1fr))',
                            alignContent: 'start',
                        }}
                    >
                        {links.length > 0 && (
                            <nav aria-label={t.links}>
                                <h2 className="w-eyebrow" style={{ color: 'var(--w-primary)', margin: '0 0 12px' }}>
                                    {t.links}
                                </h2>
                                {linkList}
                            </nav>
                        )}

                        {contact.length > 0 && (
                            <div>
                                <h2 className="w-eyebrow" style={{ color: 'var(--w-primary)', margin: '0 0 12px' }}>
                                    {t.contact}
                                </h2>
                                <ul
                                    style={{
                                        listStyle: 'none',
                                        margin: 0,
                                        padding: 0,
                                        display: 'grid',
                                        gap: 6,
                                        fontSize: 13.5,
                                    }}
                                >
                                    {contact.map(({ value, href }, i) => (
                                        <li key={i}>
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

                        <PaymentsRow list={payments} label={t.payments} />
                    </div>
                </div>

                {rights}
            </>,
        );
    }

    /* ---------------------------- أعمدة ---------------------------- */

    return shell(
        <>
            <div
                style={{
                    maxWidth: 'var(--w-content)',
                    margin: '0 auto',
                    display: 'grid',
                    gap: 28,
                    gridTemplateColumns: 'repeat(auto-fit, minmax(min(220px, 100%), 1fr))',
                }}
            >
                <div>
                    {wordmark(34)}
                    {about && (
                        <p style={{ color: 'var(--w-muted)', fontSize: 13, marginTop: 10, lineHeight: 1.9 }}>{about}</p>
                    )}
                </div>

                {links.length > 0 && (
                    <nav aria-label={t.links}>
                        <h2 className="w-eyebrow" style={{ color: 'var(--w-primary)', margin: '0 0 12px' }}>
                                    {t.links}
                                </h2>
                        {linkList}
                    </nav>
                )}

                {contact.length > 0 && (
                    <div>
                        <h2 className="w-eyebrow" style={{ color: 'var(--w-primary)', margin: '0 0 12px' }}>
                                    {t.contact}
                                </h2>
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
                                <h2 className="w-eyebrow" style={{ color: 'var(--w-primary)', margin: '0 0 12px' }}>
                                    {t.social}
                                </h2>
                                <SocialRow list={social} mode={mode} />
                            </div>
                        )}

                        <PaymentsRow list={payments} label={t.payments} />
                    </div>
                )}
            </div>

            {rights}
        </>,
    );
}

/** حساباتُ التواصل — أيقوناتٌ لكلٍّ منها رابطُه */
function SocialRow({ list, mode, bare }: { list: DocSocial[]; mode: Mode; bare?: boolean }) {
    if (list.length === 0) return null;

    return (
        <ul style={{ listStyle: 'none', margin: 0, padding: 0, display: 'flex', gap: 8, flexWrap: 'wrap' }}>
            {list.map((s) => (
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
                            width: bare ? 36 : 40,
                            height: bare ? 36 : 40,
                            borderRadius: 'var(--w-radius)',
                            border: bare ? 0 : '1px solid var(--w-border)',
                            color: 'var(--w-primary)',
                        }}
                    >
                        <AtSign size={17} />
                    </Link>
                </li>
            ))}
        </ul>
    );
}

/** طرقُ الدفع المفعّلة في نقطة البيع — أسماءٌ لا شعاراتٌ لا نملكها */
function PaymentsRow({ list, label, center }: { list: string[]; label: string; center?: boolean }) {
    if (list.length === 0) return null;

    return (
        <div style={{ marginTop: center ? 26 : 0, textAlign: center ? 'center' : 'start' }}>
            <h2 className="w-eyebrow" style={{ color: 'var(--w-primary)', margin: '0 0 12px' }}>
                                    {label}
                                </h2>
            <ul
                style={{
                    listStyle: 'none',
                    margin: 0,
                    padding: 0,
                    display: 'flex',
                    gap: 6,
                    flexWrap: 'wrap',
                    justifyContent: center ? 'center' : undefined,
                }}
            >
                {list.map((p) => (
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
    );
}
