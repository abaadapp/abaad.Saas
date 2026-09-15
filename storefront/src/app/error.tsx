'use client';

import { UnavailableState } from '@/components/States';

/**
 * ما لم يُتوقَّع.
 *
 * الحالات المعروفة تُعالَج قبل الوصول إلى هنا (`api.ts`). وهذا آخر حاجزٍ:
 * عطبٌ في الرسم لا يُظهر للزائر أثرَ استدعاءٍ ولا اسمَ ملفّ — يُظهر جملةً
 * يفهمها.
 */
export default function StorefrontError() {
    return <UnavailableState />;
}
