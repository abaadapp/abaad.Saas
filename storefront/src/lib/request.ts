import { headers } from 'next/headers';
import { normalizeHost, siteOrigin } from './host';

/**
 * ما يعرفه الخادم عن هذا الطلب — المضيفُ وأصلُه.
 *
 * وخلف nginx تصل الترويسات معدَّلة: `x-forwarded-host` هو ما كتبه الزائر،
 * و`host` قد يصير عنوان الخادم الداخليّ. فتُقرأ المحوَّلة أوّلًا.
 */
export async function currentHost(): Promise<string | null> {
    const h = await headers();

    return normalizeHost(h.get('x-forwarded-host') ?? h.get('host'));
}

export function currentOrigin(host: string): string {
    return siteOrigin(host);
}
