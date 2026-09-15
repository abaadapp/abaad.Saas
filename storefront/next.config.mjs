/**
 * إعداد العارض.
 *
 * `standalone` يجعل البناء يخرج خادمًا مكتفيًا بنفسه — يُنسخ إلى السيرفر
 * ويُشغَّل بلا `node_modules` كاملة خلفه.
 */
/** @type {import('next').NextConfig} */
const nextConfig = {
    output: 'standalone',
    // جذرُ المشروع صريحٌ: بلا هذا يصعد البحث عن قفل الحزم فوق المستودع
    turbopack: { root: import.meta.dirname },
    reactStrictMode: true,
    poweredByHeader: false,
    /*
     * ولا مُحسِّن صور: مستضيفُ الصورة غير معروفٍ وقت البناء.
     *
     * صور المستند تأتي من نطاق أبعاد ومن أيّ مستضيفٍ يلصقه التاجر، و
     * `remotePatterns` تحتاج قائمةً بالمضيفين تُكتب قبل أن يُعرف من هم. فترك
     * الصور كما هي أصدق من قائمةٍ ناقصة تحجب صورة تاجرٍ بلا رسالة.
     */
    images: { unoptimized: true },
};

export default nextConfig;
