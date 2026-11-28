import { allowOrders, orderUrl, showPrices } from './commerce';
import { Phone } from './icons';
import { WIDTH_PX, type LayoutTokens } from './layout';
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
    sizes,
}: {
    p: DocProduct;
    doc: SiteDocument;
    mode: Mode;
    /** أوّلُ صورةٍ في الصفحة تُحمَّل فورًا — تأجيلُها يؤخّر أكبر عنصرٍ يُرسم */
    eager?: boolean;
    card: LayoutTokens['card'];
    ratio: string;
    /** عرضُ البطاقة في شبكتها (`cardSizes`) — وبلا `sizes` تُرسم المصغّرةُ وحدها */
    sizes?: string;
}) {
    const order = allowOrders(doc) ? orderUrl(doc.brand, p, doc.currency, 'أودّ طلب') : null;
    const prices = showPrices(doc);
    const off = p.was !== null && p.was > 0 ? Math.round(((p.was - p.final) / p.was) * 100) : 0;
    const sale = prices && off > 0;

    const shot = (square?: boolean, badge?: React.ReactNode) => (
        <div className="w-frame" style={{ position: 'relative', display: 'flex', flexDirection: 'column' }}>
            {/* المصغّرةُ للبطاقة إن صُنعت، وأحجامُها ليختار المتصفّحُ بعرض البطاقة وكثافة الشاشة */}
            <Media
                src={p.thumb || p.image}
                srcSet={p.srcset}
                sizes={sizes}
                alt={p.name}
                ratio={ratio}
                eager={eager}
                square={square}
            />
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

/**
 * حشوةُ القسم الأفقيّة وفجوةُ الشبكة لكلّ كثافة — دون 640 ومن 640 (`site.css`،
 * و`a-builder-card-asks-for-its-true-width` يقرؤها من هناك فلا تفترقان).
 */
export const SPACING: Record<LayoutTokens['density'], [{ pad: number; gap: number }, { pad: number; gap: number }]> = {
    balanced: [
        { pad: 18, gap: 18 },
        { pad: 24, gap: 18 },
    ],
    compact: [
        { pad: 16, gap: 12 },
        { pad: 20, gap: 12 },
    ],
    spacious: [
        { pad: 20, gap: 24 },
        { pad: 28, gap: 28 },
    ],
};

/**
 * عرضُ بطاقةٍ في `Grid` لـ`sizes` — كما يرسمه CSS، ولا يقلّ عنه.
 *
 * الشبكةُ `auto-fit` بأعمدةٍ لا تضيق عن `min`، داخل حاويةٍ بعرض القالب
 * (`WIDTH_PX`) وحشوةِ كثافته (`SPACING`)، والمتوسّطةُ لا تتّسع لأكثر من
 * `columns` (`Grid`). فيُكتب لكلّ مدى من عرض الشاشة عرضُ بطاقته، والكسرُ
 * يُقرَّب صعودًا: تقديرٌ أصغرُ يجلب المصغّرةَ إلى شاشة 3x فتتشوّش.
 *
 * و`count` عددُ البطاقات: `auto-fit` يطوي الأعمدةَ الفارغة فتتّسع بطاقتان
 * في شبكةٍ تتّسع لأربع. و`span` للبطاقة الأولى في الشبكة التحريريّة: عمودان
 * من 760 (`site.css`).
 */
export function cardSizes(
    g: { columns: number; min: number },
    layout: Pick<LayoutTokens, 'width' | 'density'>,
    center: boolean,
    count: number,
    span: 1 | 2 = 1,
): string {
    const content = WIDTH_PX[layout.width] === '100%' ? Infinity : parseInt(WIDTH_PX[layout.width], 10);
    const cap = center ? Math.min(content, Math.max(1, Math.min(g.columns, 6)) * (g.min + 60)) : content;
    // ‏والعريضةُ بلا حدٍّ تتّسع لأعمدةٍ أكثر من ستّة — يحدّها عددُ البطاقات وحده
    const most = Math.max(1, count);
    const space = (vw: number) => SPACING[layout.density][vw < 640 ? 0 : 1];
    const columns = (vw: number) => {
        const { pad, gap } = space(vw);
        return Math.max(1, Math.min(most, Math.floor((Math.min(vw - 2 * pad, cap) + gap) / (g.min + gap))));
    };
    // ‏حيث يتبدّل شيء: عمودٌ يُضاف، أو حشوةٌ تتّسع، أو حاويةٌ تبلغ حدَّها، أو البطاقةُ الأولى تتّسع
    const points = new Set<number>([640, 760]);
    for (const { pad, gap } of SPACING[layout.density]) {
        for (let n = 2; n <= most && n * g.min + (n - 1) * gap + 2 * pad <= 3840; n++) points.add(n * g.min + (n - 1) * gap + 2 * pad);
        if (cap !== Infinity) points.add(cap + 2 * pad);
    }

    const at = (vw: number) => {
        const { pad, gap } = space(vw);
        const n = columns(vw);
        const wide = span === 2 && n >= 2 && vw >= 760;
        if (vw - 2 * pad >= cap) {
            const card = (cap - (n - 1) * gap) / n;
            return `${Math.ceil(wide ? 2 * card + gap : card)}px`;
        }
        const k = wide ? 2 / n : 1 / n;
        const vwPart = Math.ceil(100 * k * 100) / 100;
        const px = Math.floor((2 * pad + (n - 1) * gap) * k - (wide ? gap : 0));
        return px >= 0 ? `calc(${vwPart}vw - ${px}px)` : `calc(${vwPart}vw + ${-px}px)`;
    };

    const list: string[] = [];
    let last = at(0);
    for (const x of [...points].sort((a, b) => a - b)) {
        const v = at(x);
        if (v !== last) list.unshift(`(min-width: ${x}px) ${v}`);
        last = v;
    }
    return [...list, at(0)].join(', ');
}
