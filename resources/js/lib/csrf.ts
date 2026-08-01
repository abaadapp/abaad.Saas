/**
 * ترويسة CSRF لطلبات fetch اليدوية.
 *
 * المشكلة: وسم <meta name="csrf-token"> يُطبع مرّة عند أول تحميل ولا يتغيّر،
 * بينما تسجيل الدخول يجدّد رمز الجلسة (session()->regenerate تستدعي
 * regenerateToken داخليًا). ولأن الدخول يجري عبر طلب Inertia لا إعادة تحميل،
 * يبقى الوسم على رمز مُبطَل — وكل طلب يقرأه بعدها يُردّ بـ419.
 *
 * كوكي XSRF-TOKEN بالمقابل يعيد الخادم كتابته مع كل استجابة، فهو الطازج دائمًا.
 * لكنه **مشفَّر**: لارافيل يفكّه في ترويسة X-XSRF-TOKEN وحدها
 * (PreventRequestForgery::getTokenFromRequest)، ولا يقبله في _token ولا في
 * X-CSRF-TOKEN — فهاتان تنتظران الرمز الخام.
 *
 * لذلك: الترويسة من الكوكي هنا، والنماذج التي ترسل _token تأخذ الرمز الخام من
 * خصائص Inertia المشتركة (props.csrf) التي تتجدّد مع كل استجابة.
 */
export function csrfHeaders(): Record<string, string> {
    const cookie = document.cookie
        .split('; ')
        .find((c) => c.startsWith('XSRF-TOKEN='))
        ?.slice('XSRF-TOKEN='.length);

    if (cookie) {
        return { 'X-XSRF-TOKEN': decodeURIComponent(cookie) };
    }

    // احتياط لأول طلب قبل وصول الكوكي — الوسم صالح ما لم تُجدَّد الجلسة بعد
    const meta = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;

    return meta ? { 'X-CSRF-TOKEN': meta } : {};
}
