import { usePage } from '@inertiajs/react';

/**
 * «من ← إلى» على اتجاه الصفحة.
 *
 * السهمُ يشير إلى ما صار: ← في العربيّة لأنّها تُقرأ من اليمين، و→ في الإنجليزيّة —
 * وكان ← فيهما معًا، فتُقرأ الإنجليزيّة «80 صار 100». وكلُّ طرفٍ معزول (FSI…PDI)
 * فلا تختلط أرقامُه و«ر.ع» بالسهم وما حوله: كان المبلغان يظهران على الهاتف
 * «ر.ع ← 80.000 ر.ع … 100.000».
 */
export function fromTo(before: string, after: string, dir: 'rtl' | 'ltr'): string {
    return `\u2068${before}\u2069 ${dir === 'ltr' ? '→' : '←'} \u2068${after}\u2069`;
}

/** `fromTo` باتجاه الصفحة الحاليّة — والعربيّةُ حين لا يُعرف */
export function useFromTo(): (before: string, after: string) => string {
    const dir = usePage<{ dir?: 'rtl' | 'ltr' }>().props.dir === 'ltr' ? 'ltr' : 'rtl';

    return (before, after) => fromTo(before, after, dir);
}
