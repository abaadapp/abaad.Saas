import { fetchSite } from '@/lib/api';
import { currentHost, currentOrigin } from '@/lib/request';
import type { DocPage } from '@/site/types';

/**
 * خريطة الموقع — صفحاتُ المستند المنشورة وحدها.
 *
 * والمسوّدة لا تُدرج: إدراجُها يدلّ غوغل على صفحةٍ تردّ ٤٠٤، ويُحسب ذلك على
 * الموقع كلّه لا على الصفحة.
 */
export const dynamic = 'force-dynamic';

export async function GET(): Promise<Response> {
    const host = await currentHost();
    const result = await fetchSite(host);

    if (result.status !== 'ok' || result.doc.seo?.index === false) {
        return xml('<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>');
    }

    const origin = currentOrigin(host!);
    const lastmod = result.publishedAt ? result.publishedAt.slice(0, 10) : null;

    const urls = result.doc.pages
        .filter((p: DocPage) => p.status === 'published')
        .map((p: DocPage) => {
            const slug = p.slug === '/' ? '' : `/${p.slug.replace(/^\/+|\/+$/g, '')}`;

            return [
                '  <url>',
                `    <loc>${escapeXml(`${origin}${slug}`)}</loc>`,
                lastmod ? `    <lastmod>${lastmod}</lastmod>` : '',
                `    <priority>${p.is_home ? '1.0' : '0.7'}</priority>`,
                '  </url>',
            ]
                .filter(Boolean)
                .join('\n');
        })
        .join('\n');

    return xml(
        `<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n${urls}\n</urlset>`,
    );
}

function escapeXml(value: string): string {
    return value.replace(/[<>&'"]/g, (c) => ({ '<': '&lt;', '>': '&gt;', '&': '&amp;', "'": '&apos;', '"': '&quot;' })[c]!);
}

function xml(body: string): Response {
    return new Response(body, {
        headers: {
            'Content-Type': 'application/xml; charset=utf-8',
            'Cache-Control': 'public, max-age=300',
        },
    });
}
