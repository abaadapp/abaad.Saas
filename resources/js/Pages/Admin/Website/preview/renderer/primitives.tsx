import type { CSSProperties, ReactNode } from 'react';
import { ShoppingBag, Star } from './icons';
import { useText } from './i18n';
import type { Mode } from './types';

/**
 * اللبنات المشتركة — إطارٌ وعنوانٌ وزرٌّ وصورةٌ وشبكة.
 *
 * لولا هذه لتكرّر الحشوُ والعرضُ الأقصى في عشرين قسمًا، فتفاوت الموقعُ من
 * قسمٍ لآخر بمقدار ما نُسي في أحدها.
 */

/* ------------------------------ الإطار ------------------------------ */

export function Band({
    children,
    tone,
    tight,
    id,
}: {
    children: ReactNode;
    tone?: 'surface' | 'primary';
    tight?: boolean;
    id?: string;
}) {
    return (
        <section
            id={id}
            style={{
                background:
                    tone === 'primary' ? 'var(--w-primary)' : tone === 'surface' ? 'var(--w-surface)' : 'transparent',
                color: tone === 'primary' ? 'var(--w-on-primary)' : 'inherit',
                padding: tight ? 'var(--w-pad-tight)' : 'var(--w-pad)',
            }}
        >
            <div style={{ maxWidth: 1120, margin: '0 auto' }}>{children}</div>
        </section>
    );
}

/**
 * عنوان القسم.
 *
 * و`h2` دائمًا: `h1` واحدةٌ في الصفحة وهي في الواجهة الرئيسية أو في عنوان
 * الصفحة. وترتيبُ العناوين ليس زينةً — قارئ الشاشة يتنقّل به.
 */
export function Heading({ title, sub }: { title?: string; sub?: string }) {
    if (!title && !sub) return null;

    return (
        <div style={{ marginBottom: 28, textAlign: 'center' }}>
            {title && <h2 style={{ fontSize: 'var(--w-h2)', fontWeight: 800, margin: 0, lineHeight: 1.35 }}>{title}</h2>}
            {sub && <p style={{ color: 'var(--w-muted)', marginTop: 8, fontSize: 15, lineHeight: 1.8 }}>{sub}</p>}
        </div>
    );
}

/* ------------------------------ الروابط ------------------------------ */

/**
 * رابطٌ يعمل في الموقع ولا ينقل في المعاينة.
 *
 * المعاينة داخل لوحةٍ لها تنقّلها؛ نقرةٌ تخرج التاجر من محرّره إلى صفحةٍ
 * أخرى تُفقده ما لم يُحفظ بعد. وفي الموقع هو رابطٌ كامل: `<a>` لا `div`
 * يُنقر، فيفتحه من يتصفّح بلوحة المفاتيح ومن يقرأ بقارئ شاشة.
 */
export function Link({
    href,
    mode,
    children,
    style,
    ariaLabel,
    external,
}: {
    href: string;
    mode: Mode;
    children: ReactNode;
    style?: CSSProperties;
    ariaLabel?: string;
    external?: boolean;
}) {
    const dead = mode === 'edit';

    return (
        <a
            href={dead ? undefined : href}
            aria-label={ariaLabel}
            aria-disabled={dead || undefined}
            rel={external ? 'noopener noreferrer' : undefined}
            target={external ? '_blank' : undefined}
            style={{ color: 'inherit', textDecoration: 'none', ...style }}
        >
            {children}
        </a>
    );
}

/** الزرّ — شكلٌ واحدٌ تتبدّل قيمُه بتبدّل الرموز */
export function Cta({
    label,
    href,
    mode,
    ghost,
    external,
}: {
    label: string;
    href?: string;
    mode: Mode;
    ghost?: boolean;
    external?: boolean;
}) {
    if (!label) return null;

    const box: CSSProperties = {
        display: 'inline-block',
        padding: '12px 26px',
        borderRadius: 'var(--w-radius)',
        fontWeight: 700,
        fontSize: 15,
        background: ghost ? 'transparent' : 'var(--w-btn-bg)',
        color: ghost ? 'inherit' : 'var(--w-btn-fg)',
        border: `1.5px solid ${ghost ? 'currentColor' : 'var(--w-btn-border)'}`,
        // زرٌّ أصغر من ٤٤ بكسل لا يُصاب بالإبهام
        minHeight: 44,
        lineHeight: '20px',
    };

    if (!href) {
        return <span style={box}>{label}</span>;
    }

    return (
        <Link href={href} mode={mode} style={box} external={external}>
            {label}
        </Link>
    );
}

/* ------------------------------ الصور ------------------------------ */

/**
 * صورةٌ أو مكانُها.
 *
 * والنسبةُ محجوزةٌ قبل أن تصل الصورة: بلا ذلك يقفز ما تحتها حين تُحمَّل،
 * فيضغط الزائر على رابطٍ غير الذي قصده. والغائبةُ تُرسم مكانًا محايدًا لا
 * فراغًا — الموقع لا يُرى ناقصًا لأنّ التاجر لم يرفع صورة بعد.
 */
export function Media({
    src,
    alt,
    ratio = '4 / 3',
    eager,
}: {
    src?: string | null;
    alt?: string;
    ratio?: string;
    /** أوّلُ صورةٍ في الصفحة تُحمَّل فورًا — تأجيلُها يؤخّر أكبر عنصرٍ يُرسم */
    eager?: boolean;
}) {
    return (
        <div
            style={{
                aspectRatio: ratio,
                borderRadius: 'var(--w-radius)',
                overflow: 'hidden',
                background: 'var(--w-surface)',
                border: '1px solid var(--w-border)',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
            }}
        >
            {src ? (
                <img
                    src={src}
                    alt={alt ?? ''}
                    loading={eager ? 'eager' : 'lazy'}
                    decoding="async"
                    style={{ width: '100%', height: '100%', objectFit: 'cover', display: 'block' }}
                />
            ) : (
                <ShoppingBag size={28} style={{ color: 'var(--w-muted)' }} />
            )}
        </div>
    );
}

/* ------------------------------ الشبكة ------------------------------ */

/**
 * شبكةٌ تتقلّص وحدها.
 *
 * عددُ الأعمدة الذي يختاره التاجر هو أقصى ما يُعرض على الشاشة الواسعة، وما
 * دونها يُحسب من `auto-fit`. ورقمٌ ثابت يعني أربعة أعمدةٍ في هاتفٍ عرضُه
 * ٣٢٠ بكسل — بطاقاتٌ بعرض إصبع.
 */
export function Grid({ columns, min = 190, children }: { columns: number; min?: number; children: ReactNode }) {
    const max = Math.max(1, Math.min(columns, 6));

    return (
        <div
            style={{
                display: 'grid',
                gap: 18,
                gridTemplateColumns: `repeat(auto-fit, minmax(min(${min}px, 100%), 1fr))`,
                maxWidth: max * (min + 60),
                marginInline: 'auto',
            }}
        >
            {children}
        </div>
    );
}

/* ------------------------------ الفراغ ------------------------------ */

/**
 * القسم الفارغ يُقال عنه في المعاينة، ولا يُرسم في الموقع.
 *
 * التاجر يضيف «معرض صور» ولا يرفع صورةً بعد، فيرى مساحةً بيضاء ويظنّ القسم
 * معطوبًا — فيُقال له ما ينقص. أمّا زائرُ الموقع فلا يعنيه أنّ هناك قسمًا
 * ينتظر صورًا: يُتخطّى القسم كأنّه ليس فيها.
 */
export function Empty({ text, mode }: { text: string; mode: Mode }) {
    const t = useText();

    if (mode === 'live') return null;

    return (
        <p
            style={{
                border: '1px dashed var(--w-border)',
                borderRadius: 'var(--w-radius)',
                padding: '22px 16px',
                textAlign: 'center',
                color: 'var(--w-muted)',
                fontSize: 13,
                margin: 0,
            }}
        >
            {t(text)}
        </p>
    );
}

/** نجومُ التقييم — رقمٌ يُقرأ، لا خمسُ صورٍ يُخمَّن معناها */
export function Stars({ rating }: { rating: number }) {
    const value = Math.max(0, Math.min(5, Math.round(rating)));

    return (
        <div style={{ display: 'flex', gap: 2, marginBottom: 10 }} role="img" aria-label={`${value} من 5`}>
            {Array.from({ length: 5 }).map((_, n) => (
                <Star
                    key={n}
                    size={14}
                    filled={n < value}
                    style={{ color: n < value ? 'var(--w-primary)' : 'var(--w-border)' }}
                />
            ))}
        </div>
    );
}
