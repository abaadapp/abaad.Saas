import type { Metadata } from 'next';
import { fetchSite } from '@/lib/api';
import { currentHost, currentOrigin } from '@/lib/request';
import { buildMetadata, structuredData } from '@/lib/seo';
import { MaintenanceState, NotFoundState, PageNotFound, UnavailableState } from '@/components/States';
import { pathOf, resolvePage } from '@/lib/routing';
import { Site, findPage } from '@/site/Site';


/**
 * كلُّ صفحات الموقع — مسارٌ واحد.
 *
 * الصفحات في المستند لا في المستودع: التاجر ينشئ «سياسة الاستبدال» في أبعاد
 * فتظهر على `/refunds` بلا نشرةِ كود. ومسارٌ لكلّ صفحةٍ يعني أنّ إضافة صفحةٍ
 * تحتاج مبرمجًا — وهو ما بُني الموقعُ كلُّه لتفاديه.
 */

/** الحالة تُطلب من أبعاد في كلّ طلب — والتخزين في `fetch` لا هنا */
export const dynamic = 'force-dynamic';

interface Props {
    params: Promise<{ slug?: string[] }>;
}

export async function generateMetadata({ params }: Props): Promise<Metadata> {
    const { slug } = await params;
    const host = await currentHost();
    const result = await fetchSite(host);

    if (result.status !== 'ok') {
        return { title: result.status === 'maintenance' ? 'تحت الصيانة' : 'غير متاح', robots: { index: false } };
    }

    const page = resolvePage(result.doc, slug);

    if (!page) {
        return { title: 'الصفحة غير موجودة', robots: { index: false, follow: true } };
    }

    const origin = currentOrigin(host!);

    return buildMetadata(result.doc, page, origin, pathOf(slug));
}

export default async function StorefrontPage({ params }: Props) {
    const { slug } = await params;
    const host = await currentHost();
    const result = await fetchSite(host);

    if (result.status === 'maintenance') {
        return (
            <MaintenanceState
                name={result.info.name}
                message={result.info.message}
                tokens={result.info.tokens}
                brand={result.info.brand}
            />
        );
    }

    if (result.status === 'unavailable') {
        return <UnavailableState />;
    }

    if (result.status === 'missing') {
        return <NotFoundState />;
    }

    const page = resolvePage(result.doc, slug);

    /*
     * صفحةٌ لا وجود لها تُرسم داخل الموقع — والحالة تبقى ٢٠٠.
     *
     * وهذا اختيارٌ لا سهو. `notFound()` في Next تعطي ٤٠٤ صحيحة، لكنّها على
     * مسارٍ ديناميّ كهذا تُفرغ قشرةَ HTML وتترك الرسم للمتصفّح — جُرِّبت،
     * فوصلت الصفحةُ خاليةً من كلّ نصّ لمن لا JavaScript عنده وللزاحف. وما
     * يمنع الفهرسة فعلًا هو `noindex` في الوسوم (انظر `generateMetadata`)،
     * وهي موجودة. فصفحةٌ تُقرأ وتقول «noindex» خيرٌ من ٤٠٤ فارغة.
     */
    if (!page) {
        const home = findPage(result.doc);
        const chrome = { ...result.doc, pages: home ? [{ ...home, sections: [] }] : [] };

        return (
            <Site doc={chrome} mode="live">
                <PageNotFound />
            </Site>
        );
    }

    const origin = currentOrigin(host!);
    const schemas = structuredData(result.doc, page, origin);

    return (
        <>
            {schemas.map((schema, i) => (
                <script
                    key={i}
                    type="application/ld+json"
                    // بياناتٌ نبنيها نحن من المستند لا نصٌّ يصل من زائر
                    dangerouslySetInnerHTML={{ __html: JSON.stringify(schema).replace(/</g, '\\u003c') }}
                />
            ))}
            <Site doc={result.doc} mode="live" page={page.key} />
        </>
    );
}
