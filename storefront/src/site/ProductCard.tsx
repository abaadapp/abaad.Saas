import { allowOrders, orderUrl, showPrices } from './commerce';
import { Phone } from './icons';
import type { LayoutTokens } from './layout';
import { Link, Media, Price, Tag } from './primitives';
import type { DocProduct, Mode, SiteDocument } from './types';

/**
 * بطاقة المنتج — خمسةُ أشكالٍ وبيانٌ واحد.
 *
 * وهي أكثر ما يُرسم في متجر: صفحةٌ فيها ثلاثةُ أقسام منتجات ترسمها أربعًا
 * وعشرين مرّة. فشكلُها هو شكلُ المتجر، وبطاقةٌ واحدةٌ لكلّ القوالب تعني
 * متاجرَ تتشابه مهما تبدّلت ألوانُها.
 *
 * والأشكالُ تفترق في عشرة أشياء لا في حدٍّ ولون: نسبةُ الصورة، وقصُّها،
 * وموضعُ الاسم ووزنُه، وحجمُ السعر ولونُه، وكيف يُقال الخصم، وشكلُ زرّ
 * الطلب — أو أَزرٌّ هو أصلًا أم سطرٌ تحته خطّ.
 *
 * `plain` صورةٌ واسمٌ وسعرٌ وزرٌّ محدَّد، بلا إطار — وهو ما كان، ولمن لم يختر.
 * `bare` أقلُّ ما يكفي: صورةٌ طويلة، واسمٌ صغير، وسعرٌ بلون النصّ، ورابطُ
 *   طلبٍ سطرٌ تحته خطّ. لا إطار ولا زرَّ ولا شارة — للعلامة التي تترك
 *   المنتج يتكلّم.
 * `soft` بطاقةٌ مرفوعة على سطحٍ فاتح، الصورةُ فيها داخل إطارٍ مستدير وزرُّها
 *   حبّة. صورةٌ عريضة يناسبها البوكيه والهديّة المصوَّرة من فوق.
 * `commerce` بطاقةٌ محدَّدة: شارةُ خصمٍ حادّة، وسعرٌ كبيرٌ بلون العلامة وتحته
 *   ما كان مشطوبًا، وزرُّ طلبٍ ممتلئٌ بعرض البطاقة — لمن عنده منتجاتٌ كثيرة
 *   يقارن بينها بالنظر.
 * `editorial` لا إطار ولا زرّ: صورةٌ طويلة واسمٌ تحتها في الوسط بخطّ
 *   العناوين، والخصمُ كلمةٌ متباعدةُ الحروف لا شارةٌ ملوّنة. الصورةُ هي
 *   البطل، وكلُّ ما حولها يصغّرها.
 *
 * وثلاثةٌ لا تتبدّل بتبدّل الشكل، لأنّها ليست زينة:
 *
 * ١) **لا زرَّ سلّةٍ ولا صفحةَ منتج.** المستند يحمل الاسم والسعر والصورة —
 *    ولا يحمل مخزونًا ولا خياراتٍ ولا مسارًا للمنتج. فزرُّ سلّةٍ لا سلّة
 *    خلفه زرٌّ يكذب. والموجود فعلًا واتساب.
 * ٢) **السعر يُخفى ولا يُصفَّر.** من أطفأ الأسعار يريد أن يُسأل عنها، لا أن
 *    تُعرض `0.000` فيظنّ الزبون الصنفَ مجّانيًّا أو المتجرَ معطوبًا.
 * ٣) **نسبةُ الصورة من القالب.** مربّعةٌ في التجاريّ وطوليّةٌ في التحريريّ —
 *    ومحجوزةٌ قبل أن تصل الصورة فلا يقفز ما تحتها عند التحميل.
 */
export function ProductCard({
    p,
    doc,
    mode,
    eager,
    card,
    ratio,
}: {
    p: DocProduct;
    doc: SiteDocument;
    mode: Mode;
    /** أوّلُ صورةٍ في الصفحة تُحمَّل فورًا — تأجيلُها يؤخّر أكبر عنصرٍ يُرسم */
    eager?: boolean;
    card: LayoutTokens['card'];
    ratio: string;
}) {
    const order = allowOrders(doc) ? orderUrl(doc.brand, p, doc.currency, 'أودّ طلب') : null;
    const prices = showPrices(doc);
    const off = p.was !== null && p.was > 0 ? Math.round(((p.was - p.final) / p.was) * 100) : 0;
    const sale = prices && off > 0;

    const shot = (square?: boolean, badge?: React.ReactNode) => (
        <div className="w-frame" style={{ position: 'relative', display: 'flex', flexDirection: 'column' }}>
            <Media src={p.image} alt={p.name} ratio={ratio} eager={eager} square={square} />
            {badge}
        </div>
    );

    /** رابطُ الطلب سطرًا لا زرًّا — في الشكلين اللذين لا زرَّ فيهما */
    const orderLine = (align: 'center' | 'start') =>
        order && (
            <Link
                href={order}
                mode={mode}
                external
                ariaLabel={`اطلب ${p.name} عبر واتساب`}
                style={{
                    alignSelf: align === 'center' ? 'center' : 'flex-start',
                    fontSize: 12.5,
                    fontWeight: 600,
                    paddingBottom: 3,
                    minHeight: 36,
                    display: 'inline-flex',
                    alignItems: 'center',
                    borderBottom: '1px solid currentColor',
                }}
            >
                اطلب عبر واتساب
            </Link>
        );

    /* --------------------------- تحريريّة --------------------------- */

    if (card === 'editorial') {
        return (
            <article className="w-card" style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
                {shot()}
                <div style={{ textAlign: 'center', display: 'grid', gap: 7 }}>
                    {/* والخصمُ كلمةٌ لا شارة: بقعةٌ ملوّنة فوق الصورة تكسر هدوءَ القالب */}
                    {sale && (
                        <span className="w-eyebrow" style={{ color: 'var(--w-primary)' }}>
                            {`خصم ${off}٪`}
                        </span>
                    )}
                    <h3
                        className="w-display"
                        style={{ fontSize: 'var(--w-h3)', fontWeight: 500, margin: 0, lineHeight: 1.7 }}
                    >
                        {p.name}
                    </h3>
                    {prices && (
                        <div style={{ display: 'flex', justifyContent: 'center' }}>
                            <Price product={p} currency={doc.currency} size={13.5} tone="text" />
                        </div>
                    )}
                </div>
                {orderLine('center')}
            </article>
        );
    }

    /* ---------------------------- عاريةٌ ---------------------------- */

    if (card === 'bare') {
        return (
            <article className="w-card" style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
                {shot(true)}
                <div style={{ display: 'grid', gap: 5 }}>
                    <h3 style={{ fontSize: 'var(--w-h3)', fontWeight: 600, margin: 0, lineHeight: 1.6 }}>{p.name}</h3>
                    {prices && <Price product={p} currency={doc.currency} size={13.5} tone="text" />}
                </div>
                {orderLine('start')}
            </article>
        );
    }

    /* --------------------------- تجاريّة --------------------------- */

    if (card === 'commerce') {
        return (
            <article
                className="w-card"
                style={{
                    display: 'flex',
                    flexDirection: 'column',
                    background: 'var(--w-bg)',
                    border: '1px solid var(--w-border)',
                    borderRadius: 'var(--w-radius)',
                    overflow: 'hidden',
                    boxShadow: 'var(--w-card-shadow)',
                }}
            >
                {shot(true, sale ? <Tag>{`${off}٪−`}</Tag> : null)}
                <div style={{ display: 'flex', flexDirection: 'column', gap: 8, padding: '12px 12px 14px', flex: 1 }}>
                    <h3
                        style={{
                            fontSize: 13.5,
                            fontWeight: 700,
                            margin: 0,
                            lineHeight: 1.65,
                            // سطران لا أكثر: اسمٌ من عشر كلمات كان يُطيل بطاقةً ويقصّر جارتها
                            display: '-webkit-box',
                            WebkitLineClamp: 2,
                            WebkitBoxOrient: 'vertical',
                            overflow: 'hidden',
                        }}
                    >
                        {p.name}
                    </h3>
                    {prices && <Price product={p} currency={doc.currency} size={17} stacked />}
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
                                minHeight: 44,
                                borderRadius: 'var(--w-radius)',
                                background: 'var(--w-btn-bg)',
                                color: 'var(--w-btn-fg)',
                                border: '1.5px solid var(--w-btn-border)',
                                fontSize: 13.5,
                                fontWeight: 700,
                            }}
                        >
                            <Phone size={14} />
                            اطلب
                        </Link>
                    )}
                </div>
            </article>
        );
    }

    /* ---------------------------- ناعمة ---------------------------- */

    if (card === 'soft') {
        return (
            <article
                className="w-card"
                style={{
                    display: 'flex',
                    flexDirection: 'column',
                    gap: 12,
                    background: 'var(--w-surface)',
                    border: '1px solid var(--w-card-border)',
                    borderRadius: 'var(--w-radius)',
                    padding: 10,
                    boxShadow: 'var(--w-card-shadow)',
                }}
            >
                {shot(false, sale ? <Tag pill>{`خصم ${off}٪`}</Tag> : null)}
                <div style={{ display: 'flex', flexDirection: 'column', gap: 7, padding: '0 6px 6px', flex: 1 }}>
                    <h3 style={{ fontSize: 15, fontWeight: 700, margin: 0, lineHeight: 1.6 }}>{p.name}</h3>
                    {prices && <Price product={p} currency={doc.currency} size={16} />}
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
                                minHeight: 42,
                                borderRadius: 999,
                                background: 'var(--w-btn-bg)',
                                color: 'var(--w-btn-fg)',
                                border: '1.5px solid var(--w-btn-border)',
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

    /* ---------------------------- بسيطة ---------------------------- */

    /*
     * وهذه افتراضيُّ `Layout` — بطاقةُ كلّ موقعٍ بُني قبل طبقة البنية.
     * فتركيبُها لا يتبدّل: صورةٌ واسمٌ وسعرٌ وزرٌّ محدَّد.
     */
    return (
        <article className="w-card" style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
            {shot(false, sale ? <Tag>{`${off}٪−`}</Tag> : null)}
            <div style={{ display: 'flex', flexDirection: 'column', gap: 6, flex: 1 }}>
                <h3 style={{ fontSize: 14, fontWeight: 700, margin: 0, lineHeight: 1.6 }}>{p.name}</h3>
                {prices && <Price product={p} currency={doc.currency} size={14} />}
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

/**
 * مقاسُ الشبكة من شكلها.
 *
 * عددُ الأعمدة الذي يكتبه التاجر سقفٌ لا أمر (انظر `Grid`)، وأضيقُ بطاقةٍ
 * مقبولة تختلف بالشكل: الكثيفةُ تقبل ١٥٠ بكسل، والكبيرةُ لا تُرسم تحت ٢٩٠
 * وإلّا لم تعد كبيرة.
 */
export function gridOf(shape: LayoutTokens['grid'], columns: number): { columns: number; min: number } {
    switch (shape) {
        case 'dense':
            return { columns: Math.max(columns, 5), min: 152 };
        case 'large':
            return { columns: Math.min(columns, 3), min: 290 };
        case 'editorial':
            return { columns: Math.min(Math.max(columns, 3), 4), min: 220 };
        default:
            return { columns, min: 190 };
    }
}
