import { allowOrders, orderUrl, showPrices } from './commerce';
import { Phone } from './icons';
import type { LayoutTokens } from './layout';
import { Link, Media, Price, Tag } from './primitives';
import type { DocProduct, Mode, SiteDocument } from './types';

/**
 * بطاقة المنتج — أربعةُ أشكالٍ وبيانٌ واحد.
 *
 * وهي أكثر ما يُرسم في متجر: صفحةٌ فيها ثلاثةُ أقسام منتجات ترسمها أربعًا
 * وعشرين مرّة. فشكلُها هو شكلُ المتجر، وبطاقةٌ واحدةٌ لكلّ القوالب تعني
 * متاجرَ تتشابه مهما تبدّلت ألوانُها.
 *
 * `plain` صورةٌ واسمٌ وسعر، بلا إطار — وهو ما كان.
 * `soft` بطاقةٌ على سطحٍ فاتح بحوافَّ دائرية وزرٍّ ممتلئ.
 * `commerce` بطاقةٌ محدَّدة فيها شارةُ الخصم وزرُّ طلبٍ بعرضها — لمن عنده
 *   منتجاتٌ كثيرة يقارن بينها بالنظر.
 * `editorial` لا إطار ولا زرّ: صورةٌ طويلة واسمٌ تحتها في الوسط وسعرٌ خافت،
 *   ورابطُ طلبٍ سطرٌ لا زرّ. الصورةُ هي البطل، وكلُّ ما حولها يصغّرها.
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

    const shot = (square?: boolean) => (
        <div style={{ position: 'relative' }}>
            <Media src={p.image} alt={p.name} ratio={ratio} eager={eager} square={square} />
            {prices && off > 0 && card !== 'editorial' && <Tag>{`${off}%−`}</Tag>}
        </div>
    );

    /* --------------------------- تحريريّة --------------------------- */

    if (card === 'editorial') {
        return (
            <article className="w-card" style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
                {shot()}
                <div style={{ textAlign: 'center', display: 'grid', gap: 6 }}>
                    <h3 className="w-display" style={{ fontSize: 16.5, fontWeight: 500, margin: 0, lineHeight: 1.7 }}>
                        {p.name}
                    </h3>
                    {prices && (
                        <div style={{ display: 'flex', justifyContent: 'center' }}>
                            <Price product={p} currency={doc.currency} size={13.5} tone="text" />
                        </div>
                    )}
                </div>
                {order && (
                    <Link
                        href={order}
                        mode={mode}
                        external
                        ariaLabel={`اطلب ${p.name} عبر واتساب`}
                        style={{
                            alignSelf: 'center',
                            fontSize: 12,
                            fontWeight: 600,
                            letterSpacing: '0.1em',
                            paddingBottom: 3,
                            minHeight: 36,
                            display: 'inline-flex',
                            alignItems: 'center',
                            borderBottom: '1px solid currentColor',
                        }}
                    >
                        اطلب عبر واتساب
                    </Link>
                )}
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
                    height: '100%',
                    background: 'var(--w-bg)',
                    border: '1px solid var(--w-border)',
                    borderRadius: 'var(--w-radius)',
                    overflow: 'hidden',
                    boxShadow: 'var(--w-card-shadow)',
                }}
            >
                {shot(true)}
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
                    {prices && <Price product={p} currency={doc.currency} size={15.5} />}
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
                                borderRadius: 'var(--w-radius)',
                                background: 'var(--w-btn-bg)',
                                color: 'var(--w-btn-fg)',
                                border: '1.5px solid var(--w-btn-border)',
                                fontSize: 13,
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
                    gap: 10,
                    height: '100%',
                    background: 'var(--w-surface)',
                    border: '1px solid var(--w-card-border)',
                    borderRadius: 'var(--w-radius)',
                    padding: 10,
                    boxShadow: 'var(--w-card-shadow)',
                }}
            >
                {shot()}
                <div style={{ display: 'flex', flexDirection: 'column', gap: 6, padding: '0 4px 4px', flex: 1 }}>
                    <h3 style={{ fontSize: 14.5, fontWeight: 700, margin: 0, lineHeight: 1.6 }}>{p.name}</h3>
                    {prices && <Price product={p} currency={doc.currency} size={15} />}
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

    return (
        <article className="w-card" style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
            {shot()}
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
 * مقبولة تختلف بالشكل: الكثيفةُ تقبل ١٥٠ بكسل، والكبيرةُ لا تُرسم تحت ٢٨٠
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
