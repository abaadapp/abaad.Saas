import type { CSSProperties, ReactNode } from 'react';
import { whatsappUrl } from '@/site/commerce';
import { Mail, Phone } from '@/site/icons';
import '@/site/site.css';
import { cssVars } from '@/site/tokens';
import type { DocBrand, Tokens } from '@/site/types';

/**
 * الحالات التي ليست موقعًا — وهي أيضًا صفحاتٌ يراها بشر.
 *
 * زائرٌ يفتح نطاقًا فيرى `502 Bad Gateway` أو نصًّا إنجليزيًّا عن مهلةٍ
 * انتهت يظنّ المتجر مغلقًا إلى الأبد. فلكلّ حالةٍ صفحةٌ تقول ما حدث بلغةٍ
 * يفهمها، وبهويّة المتجر إن كانت معروفة، وبوسيلةِ تواصلٍ إن وُجدت.
 */

function Shell({
    tokens,
    brand,
    children,
}: {
    tokens?: Tokens;
    brand?: DocBrand;
    children: ReactNode;
}) {
    const vars = cssVars({ tokens: tokens as Tokens, theme: {} });

    return (
        <div
            dir="rtl"
            lang="ar"
            className="w-site"
            style={{
                ...vars,
                minHeight: '100dvh',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                padding: '32px 20px',
                textAlign: 'center',
            }}
        >
            <div style={{ maxWidth: 460, display: 'grid', gap: 18, justifyItems: 'center' }}>
                {brand?.logo && (
                    <img
                        src={brand.logo}
                        alt={brand.name}
                        style={{ height: 46, width: 'auto', maxWidth: 200, objectFit: 'contain' }}
                    />
                )}
                {children}
            </div>
        </div>
    );
}

const titleStyle: CSSProperties = { fontSize: 'var(--w-h2)', fontWeight: 800, margin: 0, lineHeight: 1.5 };
const bodyStyle: CSSProperties = { color: 'var(--w-muted)', fontSize: 15, lineHeight: 1.9, margin: 0 };

function Reach({ brand }: { brand?: DocBrand }) {
    const wa = whatsappUrl(brand?.whatsapp);
    const links = [
        wa ? { href: wa, label: 'واتساب', Icon: Phone, external: true } : null,
        brand?.phone ? { href: `tel:${brand.phone.replace(/\s+/g, '')}`, label: brand.phone, Icon: Phone, external: false } : null,
        brand?.email ? { href: `mailto:${brand.email}`, label: brand.email, Icon: Mail, external: false } : null,
    ].filter(Boolean) as { href: string; label: string; Icon: typeof Phone; external: boolean }[];

    if (links.length === 0) return null;

    return (
        <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap', justifyContent: 'center', marginTop: 4 }}>
            {links.map(({ href, label, Icon, external }) => (
                <a
                    key={href}
                    href={href}
                    rel={external ? 'noopener noreferrer' : undefined}
                    target={external ? '_blank' : undefined}
                    style={{
                        display: 'inline-flex',
                        alignItems: 'center',
                        gap: 8,
                        minHeight: 44,
                        padding: '0 16px',
                        borderRadius: 'var(--w-radius)',
                        border: '1px solid var(--w-border)',
                        fontSize: 14,
                        fontWeight: 700,
                        textDecoration: 'none',
                        color: 'inherit',
                    }}
                >
                    <Icon size={16} />
                    <span dir="auto">{label}</span>
                </a>
            ))}
        </div>
    );
}

/** الموقع في الصيانة — بهويّته وبوسيلةِ الوصول إليه */
export function MaintenanceState({
    name,
    message,
    tokens,
    brand,
}: {
    name: string;
    message: string;
    tokens?: Tokens;
    brand?: DocBrand;
}) {
    return (
        <Shell tokens={tokens} brand={brand}>
            {!brand?.logo && name && <strong style={{ fontSize: 20, fontWeight: 800 }}>{name}</strong>}
            <h1 style={titleStyle}>الموقع تحت الصيانة</h1>
            <p style={bodyStyle}>{message}</p>
            <Reach brand={brand} />
        </Shell>
    );
}

const backHome = {
    display: 'inline-flex',
    alignItems: 'center',
    minHeight: 44,
    padding: '0 22px',
    borderRadius: 'var(--w-radius)',
    background: 'var(--w-btn-bg)',
    color: 'var(--w-btn-fg)',
    border: '1.5px solid var(--w-btn-border)',
    fontWeight: 700,
    fontSize: 15,
    textDecoration: 'none',
} satisfies CSSProperties;

/** لا موقع على هذا النطاق — أو لم يُنشر بعد */
export function NotFoundState() {
    return (
        <Shell>
            <h1 style={titleStyle}>لا يوجد موقع على هذا العنوان</h1>
            <p style={bodyStyle}>تحقّق من العنوان — أو أنّ صاحب الموقع لم ينشره بعد.</p>
        </Shell>
    );
}

/**
 * صفحةٌ لا وجود لها — داخل الموقع لا خارجه.
 *
 * زائرٌ أخطأ في رابطٍ فرأى صفحةً بيضاء بلا ترويسةٍ ولا تذييل يظنّ أنّه غادر
 * المتجر إلى مكانٍ آخر. فيبقى في الموقع، بترويسته ولونه، ويجد طريق الرجوع.
 */
export function PageNotFound() {
    return (
        <div style={{ padding: '80px 20px', textAlign: 'center', display: 'grid', gap: 16, justifyItems: 'center' }}>
            <h1 style={titleStyle}>الصفحة غير موجودة</h1>
            <p style={{ ...bodyStyle, maxWidth: 420 }}>
                ربّما غُيّر عنوان الصفحة أو حُذفت. عُد إلى الرئيسية لتجد ما تبحث عنه.
            </p>
            <a href="/" style={backHome}>
                العودة إلى الرئيسية
            </a>
        </div>
    );
}

/**
 * أبعادُ لا تردّ — والزائر لا يُقال له ذلك.
 *
 * «تعذّر الاتصال بالخادم» جملةٌ تخصّنا نحن. وما يخصّ الزائر أنّ الموقع
 * سيعود، وأنّ بإمكانه المحاولة الآن.
 */
export function UnavailableState() {
    return (
        <Shell>
            <h1 style={titleStyle}>الموقع غير متاح مؤقّتًا</h1>
            <p style={bodyStyle}>نعمل على إعادته حالًا. حاول تحديث الصفحة بعد قليل.</p>
        </Shell>
    );
}
