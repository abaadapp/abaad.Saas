import { cache } from 'react';
import type { SiteDocument, Tokens, DocBrand } from '@/site/types';

/**
 * قراءة المستند من أبعاد.
 *
 * وهذا هو الوصل كلُّه: طلبٌ واحدٌ إلى `GET /site/{host}` يردّ الموقع المنشور
 * ومعه ما تعرضه أقسامُه. ولا قاعدةَ بيانات هنا ولا نسخةَ ثانية من الكتالوج —
 * فلا يفترق ما يراه الزائر عمّا في أبعاد.
 *
 * وأربعُ حالاتٍ لا ثلاث: موجودٌ منشور، وغيرُ موجود، وفي صيانة، وأبعادُ لا
 * تردّ. والرابعة هي التي تُنسى عادةً — فتظهر للزائر رسالةُ خطأٍ تقنيّة أو
 * صفحةٌ بيضاء، وكلاهما يقول له إنّ المتجر معطوب وهو ليس كذلك.
 */

export interface MaintenanceInfo {
    name: string;
    message: string;
    tokens: Tokens;
    brand?: DocBrand;
    locale?: string;
    dir?: 'rtl' | 'ltr';
}

export type SiteResult =
    | { status: 'ok'; doc: SiteDocument; version: number; publishedAt: string | null }
    | { status: 'missing'; reason: 'not_found' | 'not_published' | 'bad_host' }
    | { status: 'maintenance'; info: MaintenanceInfo }
    | { status: 'unavailable'; reason: 'timeout' | 'network' | 'server' | 'bad_payload' };

function apiBase(): string {
    return (process.env.ABAAD_API_URL ?? 'https://app.abaadapp.om').replace(/\/+$/, '');
}

function timeoutMs(): number {
    const raw = Number(process.env.ABAAD_API_TIMEOUT_MS);

    return Number.isFinite(raw) && raw > 0 ? raw : 6000;
}

function revalidate(): number {
    const raw = Number(process.env.SITE_REVALIDATE_SECONDS);

    return Number.isFinite(raw) && raw >= 0 ? raw : 60;
}

/**
 * والطلب مرّةً واحدة في الطلب الواحد.
 *
 * التخطيط يحتاج المستند ليعرف الاتجاه والخطّ، والوسومُ تحتاجه للعنوان
 * والوصف، والصفحةُ تحتاجه لترسم. وثلاثةُ طلباتٍ لشيءٍ واحد تُثقل أبعاد
 * بثلاثة أضعاف زوّار متاجرها كلّها.
 */
export const fetchSite = cache(async (host: string | null): Promise<SiteResult> => {
    if (!host) {
        return { status: 'missing', reason: 'bad_host' };
    }

    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), timeoutMs());

    let response: Response;

    try {
        response = await fetch(`${apiBase()}/site/${encodeURIComponent(host)}`, {
            signal: controller.signal,
            headers: { Accept: 'application/json' },
            next: { revalidate: revalidate(), tags: [`site:${host}`] },
        });
    } catch (error) {
        const aborted = error instanceof Error && error.name === 'AbortError';

        return { status: 'unavailable', reason: aborted ? 'timeout' : 'network' };
    } finally {
        clearTimeout(timer);
    }

    if (response.status === 503) {
        const info = await safeJson<MaintenanceInfo & { maintenance?: boolean }>(response);

        // صيانةٌ بلا جسمٍ مفهوم تبقى صيانة — الحالة تكفي
        return {
            status: 'maintenance',
            info: {
                name: info?.name ?? '',
                message: info?.message ?? 'نعود قريبًا',
                tokens: info?.tokens as Tokens,
                brand: info?.brand,
                locale: info?.locale,
                dir: info?.dir,
            },
        };
    }

    if (response.status === 404) {
        const body = await safeJson<{ error?: string }>(response);

        return { status: 'missing', reason: body?.error === 'not_published' ? 'not_published' : 'not_found' };
    }

    if (!response.ok) {
        return { status: 'unavailable', reason: 'server' };
    }

    const body = await safeJson<{ site?: SiteDocument; version?: number; published_at?: string | null }>(response);

    // مستندٌ بلا صفحات ليس موقعًا — وأفضلُ من رسمِ فراغٍ أن يُقال «غير متاح»
    if (!body?.site || !Array.isArray(body.site.pages)) {
        return { status: 'unavailable', reason: 'bad_payload' };
    }

    return {
        status: 'ok',
        doc: body.site,
        version: body.version ?? 0,
        publishedAt: body.published_at ?? null,
    };
});

async function safeJson<T>(response: Response): Promise<T | null> {
    try {
        return (await response.json()) as T;
    } catch {
        return null;
    }
}
