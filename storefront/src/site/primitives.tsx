import type { CSSProperties, ReactNode } from 'react';
import { Star } from './icons';
import { useText } from './i18n';
import type { LayoutTokens } from './layout';
import { money } from './money';
import type { Currency, DocProduct, Mode } from './types';

/**
 * اللبنات المشتركة — إطارٌ وعنوانٌ وزرٌّ وصورةٌ وشبكةٌ وسعر.
 *
 * لولا هذه لتكرّر الحشوُ والعرضُ الأقصى في عشرين قسمًا، فتفاوت الموقعُ من
 * قسمٍ لآخر بمقدار ما نُسي في أحدها. وهي أيضًا موضعُ اختلاف القوالب: تبدّلُ
 * رمزٍ هنا يتبدّل به عشرون قسمًا دفعةً واحدة، وهو الفرق بين قالبٍ إعدادًا
 * وقالبٍ نسخةً من الموقع.
 */

/* ------------------------------ الإطار ------------------------------ */

export function Band({
    children,
    tone,
    tight,
    loose,
    id,
    /** قسمٌ يملأ الشاشة عرضًا — الواجهةُ والصورُ الكبيرة */
    bleed,
}: {
    children: ReactNode;
    tone?: 'surface' | 'primary';
    tight?: boolean;
    /**
     * قسمٌ يتنفّس أكثر ممّا حوله.
     *
     * صفحةٌ كلُّ أقسامها بحشوٍ واحد تُقرأ قائمةً لا صفحة: العينُ تنزل بإيقاعٍ
     * واحد فلا تعرف أين ينتهي فصلٌ ويبدأ آخر. فما يقول «قِف» — العرضُ
     * والدعوةُ وصفُّ الآراء — يأخذ فراغًا أوسع، والشريطُ الملاصقُ للواجهة
     * يأخذ أضيق. والثلاثةُ من متغيّرٍ واحدٍ يتبع كثافةَ القالب، لا أرقامًا
     * مكتوبةً في عشرين قسمًا.
     */
    loose?: boolean;
    id?: string;
    bleed?: boolean;
}) {
    return (
        <section
            id={id}
            style={{
                background:
                    tone === 'primary' ? 'var(--w-primary)' : tone === 'surface' ? 'var(--w-surface)' : 'transparent',
                color: tone === 'primary' ? 'var(--w-on-primary)' : 'inherit',
                padding: tight ? 'var(--w-pad-tight)' : loose ? 'var(--w-pad-loose)' : 'var(--w-pad)',
            }}
        >
            <div style={{ maxWidth: bleed ? undefined : 'var(--w-content)', margin: '0 auto' }}>{children}</div>
        </section>
    );
}

/**
 * عنوان القسم — ثلاثةُ أشكالٍ لا شكلٌ واحد.
 *
 * عنوانٌ في الوسط في كلّ قسمٍ من كلّ قالب هو أوّل ما يجعل المواقعَ تتشابه:
 * إيقاعُ الصفحة يُقرأ من موضع عناوينها قبل أن يُقرأ من ألوانها. فالتحريريُّ
 * يبدأ من الحافّة بخطٍّ فوقه، والتجاريُّ يبدأ من الحافّة ومعه رابطٌ إلى
 * الكلّ، والبسيطُ يبقى في الوسط.
 *
 * و`h2` دائمًا: `h1` واحدةٌ في الصفحة وهي في الواجهة الرئيسية أو في عنوان
 * الصفحة. وترتيبُ العناوين ليس زينةً — قارئ الشاشة يتنقّل به.
 */
export function Heading({
    title,
    sub,
    variant = 'center',
    action,
    eyebrow,
}: {
    title?: string;
    sub?: string;
    variant?: LayoutTokens['heading'];
    /** رابطٌ بجانب العنوان — «كلّ المنتجات» في القوالب التجارية */
    action?: ReactNode;
    /** كلمةٌ صغيرةٌ فوق العنوان تسمّي نوعَ القسم — في القوالب التحريرية */
    eyebrow?: string;
}) {
    if (!title && !sub) return null;

    const centered = variant === 'center';

    return (
        <div
            style={{
                marginBottom: centered ? 28 : 22,
                textAlign: centered ? 'center' : 'start',
                display: 'flex',
                flexDirection: centered ? 'column' : 'row',
                alignItems: centered ? 'stretch' : 'flex-end',
                justifyContent: 'space-between',
                gap: 14,
                flexWrap: 'wrap',
            }}
        >
            <div style={{ minWidth: 0 }}>
                {/* والخطُّ فوق العنوان علامةُ القالب التحريريّ — لا زينةٌ في كلّ قالب */}
                {variant === 'editorial' && !eyebrow && (
                    <span
                        aria-hidden
                        style={{
                            display: 'block',
                            width: 46,
                            height: 1,
                            background: 'var(--w-primary)',
                            marginBottom: 18,
                            opacity: 0.8,
                        }}
                    />
                )}
                {eyebrow && (
                    <span className="w-eyebrow" style={{ color: 'var(--w-primary)', marginBottom: 12 }}>
                        {eyebrow}
                    </span>
                )}
                {title && (
                    <h2
                        style={{
                            fontSize: 'var(--w-h2)',
                            fontWeight: variant === 'editorial' ? 'var(--w-h2-weight)' : 800,
                            margin: 0,
                            lineHeight: 1.35,
                        }}
                    >
                        {title}
                    </h2>
                )}
                {sub && (
                    <p
                        style={{
                            color: 'var(--w-muted)',
                            margin: '10px 0 0',
                            fontSize: 15,
                            lineHeight: 1.8,
                            maxWidth: 'var(--w-lead-measure)',
                            marginInline: centered ? 'auto' : undefined,
                        }}
                    >
                        {sub}
                    </p>
                )}
            </div>
            {action}
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
    className,
}: {
    href: string;
    mode: Mode;
    children: ReactNode;
    style?: CSSProperties;
    ariaLabel?: string;
    external?: boolean;
    className?: string;
}) {
    const dead = mode === 'edit';

    return (
        <a
            href={dead ? undefined : href}
            aria-label={ariaLabel}
            aria-disabled={dead || undefined}
            rel={external ? 'noopener noreferrer' : undefined}
            target={external ? '_blank' : undefined}
            className={className}
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
    block,
}: {
    label: string;
    href?: string;
    mode: Mode;
    ghost?: boolean;
    external?: boolean;
    /** زرٌّ بعرض ما يحويه — في بطاقة المنتج وفي الهاتف */
    block?: boolean;
}) {
    if (!label) return null;

    const box: CSSProperties = {
        display: block ? 'flex' : 'inline-flex',
        alignItems: 'center',
        justifyContent: 'center',
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
        textAlign: 'center',
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
 *
 * وحدُّها من رمز القالب لا مكتوبًا: القالبُ التحريريّ بلا حدودٍ حول صوره —
 * الصورةُ فيه هي البطل، والإطارُ حولها يصغّرها.
 */
export function Media({
    src,
    alt,
    ratio = '4 / 3',
    eager,
    /** حوافُّ حادّة — الصورةُ الملاصقةُ لحافّة القسم لا تُدوَّر */
    square,
    className,
}: {
    src?: string | null;
    alt?: string;
    ratio?: string;
    /** أوّلُ صورةٍ في الصفحة تُحمَّل فورًا — تأجيلُها يؤخّر أكبر عنصرٍ يُرسم */
    eager?: boolean;
    square?: boolean;
    className?: string;
}) {
    return (
        <div
            className={['w-shot', src ? null : 'w-wash', className].filter(Boolean).join(' ')}
            style={{
                aspectRatio: ratio,
                borderRadius: square ? 0 : 'var(--w-radius)',
                border: '1px solid var(--w-card-border)',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
            }}
        >
            {src && (
                <img
                    src={src}
                    alt={alt ?? ''}
                    loading={eager ? 'eager' : 'lazy'}
                    decoding="async"
                    style={{ width: '100%', height: '100%', objectFit: 'cover', display: 'block' }}
                />
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
 *
 * والفجوةُ من كثافة القالب لا رقمًا هنا: الفسيحُ يتنفّس بين بطاقاته كما
 * يتنفّس بين أقسامه.
 */
export function Grid({
    columns,
    min = 190,
    children,
    className,
    center = true,
}: {
    columns: number;
    min?: number;
    children: ReactNode;
    className?: string;
    /** شبكةٌ أضيقُ من قسمها تُوسَّط — إلّا حين يكون محاذاةُ القسم للحافّة */
    center?: boolean;
}) {
    const max = Math.max(1, Math.min(columns, 6));

    return (
        <div
            className={['w-grid', className].filter(Boolean).join(' ')}
            style={{
                gridTemplateColumns: `repeat(auto-fit, minmax(min(${min}px, 100%), 1fr))`,
                maxWidth: center ? max * (min + 60) : undefined,
                marginInline: center ? 'auto' : undefined,
            }}
        >
            {children}
        </div>
    );
}

/* ------------------------------ السعر ------------------------------ */

/**
 * السعر — رقمٌ واحدٌ أو رقمان.
 *
 * وموضعُه واحدٌ في الموقع كلِّه: البطاقةُ وصفحةُ المنتج والقائمةُ تكتبه
 * بالقاعدة نفسها. وسعرٌ قبل الخصم يُشطب ويصغر ويخفت — ثلاثتُها معًا، لأنّ
 * الشطبَ وحده يُقرأ في عجلةٍ رقمًا ثانيًا لا رقمًا ملغى.
 */
export function Price({
    product,
    currency,
    size = 14,
    tone = 'primary',
    stacked,
}: {
    product: Pick<DocProduct, 'final' | 'was'>;
    currency: Currency | undefined;
    size?: number;
    /** في القالب البسيط السعرُ بلون النصّ لا بلون العلامة */
    tone?: 'primary' | 'text';
    /**
     * الملغى فوق والساري تحته — في البطاقة التجارية.
     *
     * رقمان في سطرٍ واحد يُقرآن في عجلةٍ رقمًا واحدًا طويلًا. وفصلُهما سطرين
     * يجعل الكبيرَ تحت الصغير هو الذي تقع عليه العين — وهو الذي يُدفع.
     */
    stacked?: boolean;
}) {
    const was = product.was !== null && (
        <span
            style={{
                color: 'var(--w-muted)',
                textDecoration: 'line-through',
                fontWeight: 400,
                fontSize: size - (stacked ? 4 : 2),
                marginInlineStart: stacked ? 0 : 8,
            }}
        >
            {money(product.was, currency)}
        </span>
    );

    if (stacked) {
        return (
            <p style={{ margin: 0, display: 'grid', gap: 1, lineHeight: 1.45 }}>
                {was}
                <span style={{ fontSize: size, fontWeight: 800, color: tone === 'primary' ? 'var(--w-primary)' : 'inherit' }}>
                    {money(product.final, currency)}
                </span>
            </p>
        );
    }

    return (
        <p
            style={{
                margin: 0,
                fontSize: size,
                fontWeight: 700,
                color: tone === 'primary' ? 'var(--w-primary)' : 'inherit',
            }}
        >
            {money(product.final, currency)}
            {was}
        </p>
    );
}

/** شارةٌ فوق الصورة — «خصم ٢٠٪» وما يشبهها */
export function Tag({
    children,
    tone = 'primary',
    pill,
}: {
    children: ReactNode;
    tone?: 'primary' | 'dark';
    /** حبّةٌ مستديرة — في القالب الناعم حيث كلُّ شيءٍ مستدير */
    pill?: boolean;
}) {
    return (
        <span
            style={{
                position: 'absolute',
                insetInlineStart: pill ? 16 : 10,
                top: pill ? 16 : 10,
                zIndex: 1,
                background: tone === 'primary' ? 'var(--w-primary)' : 'rgba(0,0,0,.78)',
                color: tone === 'primary' ? 'var(--w-on-primary)' : '#fff',
                borderRadius: pill ? 999 : 'var(--w-radius)',
                padding: pill ? '5px 12px' : '4px 9px',
                fontSize: 11.5,
                fontWeight: 800,
                lineHeight: 1.6,
            }}
        >
            {children}
        </span>
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
