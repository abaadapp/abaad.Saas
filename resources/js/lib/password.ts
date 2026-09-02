/**
 * كلمة مرورٍ تُملى في الهاتف.
 *
 * بلا الحروف التي تلتبس عند الإملاء (l/1/O/0): من يكتبها على لوحة الموظف
 * يقرؤها من ورقةٍ أو يسمعها، وحرفٌ ملتبسٌ واحد يعني محاولةَ دخولٍ تفشل بلا
 * سبب ظاهر.
 *
 * وعشرةُ محارف من ستٍّ وخمسين: `crypto.getRandomValues` لا `Math.random` —
 * الأخيرة قابلة للتنبّؤ، وكلمةُ مرورٍ يُخمَّن مولّدُها ليست كلمة مرور.
 */
export function randomPassword(): string {
    const chars = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    return Array.from(
        crypto.getRandomValues(new Uint32Array(10)),
        (n) => chars[n % chars.length],
    ).join('');
}
