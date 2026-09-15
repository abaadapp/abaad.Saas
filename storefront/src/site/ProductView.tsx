import { allowOrders, orderUrl, showPrices } from './commerce';
import { Phone } from './icons';
import { layoutOf, RATIO } from './layout';
import { gridOf, ProductCard } from './ProductCard';
import ProductGallery from './ProductGallery';
import { Cta, Grid, Heading, Link, Media, Price } from './primitives';
import type { DocProduct, Mode, SiteDocument } from './types';

/**
 * صفحة المنتج — تصميمُها جاهز، ومسارُها ليس بعد.
 *
 * ولا تُستدعى اليوم من مسار: لا `/product/{id}` في العارض، ولا سلّةَ ولا
 * دفعَ ولا مخزونًا في العقد. وبناؤها الآن ليس استباقًا بلا داعٍ — هو الفرق
 * بين نظامِ تصميمٍ وبين شاشاتٍ متفرّقة: يوم يصل المسار لا يُعاد تصميمُ
 * الصفحة ولا تُخترع لها بطاقةٌ سادسة، بل تُوصَل بهذا.
 *
 * وما تعرضه هو ما في العقد فعلًا — اسمٌ وسعرٌ وسعرٌ قبل الخصم ونبذةٌ وصورة.
 * ولا «متوفّرٌ في المخزون» ولا «المقاس» ولا «اللون»: ليست في المنتج، وسطرٌ
 * يقول «متوفّر» بلا مخزونٍ يقرؤه كذبةٌ تُقال لكلّ زائر. ويوم تدخل العقد
 * تدخل هنا مكانَها.
 *
 * ── البناء ──
 *
 * على الحاسوب: معرضٌ إلى جانب بيان. وعلى الهاتف: معرضٌ ثمّ بيان — وهو
 * الترتيب الذي يشتري به الناس من هواتفهم: يرى الصورة، فيقرأ السعر، فيطلب.
 */
export function ProductView({
    product,
    doc,
    mode,
    images,
    related = [],
    relatedTitle = 'قد يعجبك أيضًا',
    backHref,
    backLabel = 'كلّ المنتجات',
}: {
    product: DocProduct;
    doc: SiteDocument;
    mode: Mode;
    /** صورُ المنتج — وبلا شيء: صورتُه الواحدة في العقد */
    images?: string[];
    related?: DocProduct[];
    relatedTitle?: string;
    backHref?: string;
    backLabel?: string;
}) {
    const layout = layoutOf(doc);
    const ratio = RATIO[layout.ratio];
    const shots = (images ?? [product.image]).filter((src): src is string => !!src);
    const prices = showPrices(doc);
    const order = allowOrders(doc) ? orderUrl(doc.brand, product, doc.currency, 'أودّ طلب') : null;
    const shape = gridOf(layout.grid, 4);

    return (
        <>
            <section style={{ padding: 'var(--w-pad)' }}>
                <div style={{ maxWidth: 'var(--w-content)', margin: '0 auto' }}>
                    {backHref && (
                        <p style={{ margin: '0 0 18px', fontSize: 13.5 }}>
                            <Link
                                href={backHref}
                                mode={mode}
                                style={{
                                    color: 'var(--w-muted)',
                                    display: 'inline-flex',
                                    alignItems: 'center',
                                    minHeight: 36,
                                }}
                            >
                                {backLabel}
                            </Link>
                        </p>
                    )}

                    <div
                        style={{
                            display: 'grid',
                            gap: 'clamp(20px, 4vw, 48px)',
                            // والصورةُ أوسعُ من البيان على الحاسوب، وفوقه على الهاتف
                            gridTemplateColumns: 'repeat(auto-fit, minmax(min(320px, 100%), 1fr))',
                            alignItems: 'start',
                        }}
                    >
                        {shots.length > 1 ? (
                            <ProductGallery images={shots} alt={product.name} ratio={ratio} />
                        ) : (
                            <Media src={shots[0] ?? null} alt={product.name} ratio={ratio} eager />
                        )}

                        <div style={{ display: 'grid', gap: 18, alignContent: 'start' }}>
                            <h1 style={{ fontSize: 'var(--w-h1)', fontWeight: 800, margin: 0, lineHeight: 1.3 }}>
                                {product.name}
                            </h1>

                            {prices && <Price product={product} currency={doc.currency} size={22} />}

                            {product.excerpt && (
                                <p style={{ color: 'var(--w-muted)', fontSize: 15, lineHeight: 2, margin: 0 }}>
                                    {product.excerpt}
                                </p>
                            )}

                            {order && (
                                <div style={{ display: 'grid', gap: 10, maxWidth: 340 }}>
                                    <Cta label="اطلب عبر واتساب" href={order} mode={mode} external block />
                                    <p
                                        style={{
                                            color: 'var(--w-muted)',
                                            fontSize: 12.5,
                                            margin: 0,
                                            display: 'flex',
                                            alignItems: 'center',
                                            gap: 6,
                                        }}
                                    >
                                        <Phone size={14} style={{ flex: 'none' }} />
                                        تفتح محادثةً باسم المنتج وسعره
                                    </p>
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </section>

            {related.length > 0 && (
                <section style={{ padding: 'var(--w-pad)' }}>
                    <div style={{ maxWidth: 'var(--w-content)', margin: '0 auto' }}>
                        <Heading title={relatedTitle} variant={layout.heading} />
                        <Grid
                            columns={shape.columns}
                            min={shape.min}
                            center={false}
                            className={layout.grid === 'editorial' ? 'w-grid-editorial' : undefined}
                        >
                            {related.map((p) => (
                                <ProductCard key={p.id} p={p} doc={doc} mode={mode} card={layout.card} ratio={ratio} />
                            ))}
                        </Grid>
                    </div>
                </section>
            )}
        </>
    );
}
