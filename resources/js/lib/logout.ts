/**
 * تسجيل الخروج — إرسال نموذج حقيقي لا طلب Inertia.
 *
 * `router.post(route('logout'))` كان يفشل بصمت: الخادم يعيد 302 إلى /login،
 * وصفحة الدخول قالب Blade لا استجابة Inertia (بلا ترويسة X-Inertia)، فيعجز
 * عميل Inertia عن استيعابها ويعرضها داخل إطار منبثق فوق اللوحة القديمة —
 * فيبقى شريط المستخدم وقائمته ظاهرين خلف نموذج الدخول والمسار كما هو، مع أن
 * الجلسة انتهت فعلًا على الخادم.
 *
 * الإرسال بنموذج يجعلها ملاحة كاملة: يغادر المتصفح الصفحة إلى /login فعليًا.
 * ويبقى الطلب POST — لا GET — فلا يُخرَج المستخدم برابط أو صورة من موقع آخر.
 */
export function logout(action: string, token: string): void {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = action;

    const field = document.createElement('input');
    field.type = 'hidden';
    field.name = '_token';
    field.value = token;
    form.appendChild(field);

    document.body.appendChild(form);
    form.submit();
}
