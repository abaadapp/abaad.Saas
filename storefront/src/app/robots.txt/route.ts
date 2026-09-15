import { fetchSite } from '@/lib/api';
import { currentHost, currentOrigin } from '@/lib/request';

/**
 * robots للنطاق الذي طُلب — لا ملفٌّ واحد لكلّ المتاجر.
 *
 * الخادم واحدٌ ويخدم نطاقاتٍ كثيرة، وملفٌّ ثابت في المستودع يعني أنّ تاجرًا
 * أطفأ «الظهور في نتائج البحث» في أبعاد ويبقى موقعه مفهرَسًا. فالإعداد
 * يُقرأ من مستنده هو.
 */
export const dynamic = 'force-dynamic';

export async function GET(): Promise<Response> {
    const host = await currentHost();
    const result = await fetchSite(host);

    // لا موقعَ أو لا تُقرأ حالُه: لا يُفتح الباب على مصراعيه في الشكّ
    if (result.status !== 'ok' || result.doc.seo?.index === false) {
        return text('User-agent: *\nDisallow: /\n');
    }

    const origin = currentOrigin(host!);

    return text(`User-agent: *\nAllow: /\n\nSitemap: ${origin}/sitemap.xml\n`);
}

function text(body: string): Response {
    return new Response(body, {
        headers: {
            'Content-Type': 'text/plain; charset=utf-8',
            'Cache-Control': 'public, max-age=300',
        },
    });
}
