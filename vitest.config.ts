import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';
import path from 'node:path';

/**
 * مُشغِّلُ اختبارات الواجهة.
 *
 * ═══ لماذا مُشغِّلٌ ثانٍ ═══
 *
 * اختبارات PHP تصل إلى ما يُرسله الخادم وتقف عنده. وما بعده — أنّ السهم
 * ينقل المؤشّر، وأنّ النافذة تبقى مفتوحة، وأنّ الفراغ لا يُقرأ «لا إيصال»
 * فيُرسم زرُّ محوٍ فوق ورقةٍ موجودة — لا يُشغّله إلّا متصفّح.
 *
 * وكان يُحرَس بقراءة المصدر: اختبارٌ يفتّش عن `e.key === 'ArrowDown'` في
 * الملفّ. وهو حارسٌ يمنع الحذف ولا يثبت السلوك، ونجت تحته مطفرةٌ واحدة على
 * الأقلّ (فصلُ العدّاد عن القائمة في نافذة الكتالوج) لم أستطع قتلها.
 *
 * وملفٌّ منفصلٌ عن `vite.config.js` لا تعديلٌ عليه: بناءُ الإنتاج لا يحمل
 * شيئًا من عدّة الاختبار، وخطأٌ هنا لا يُسقط `npm run build`.
 */
export default defineConfig({
    plugins: [react()],
    resolve: {
        // ‏نفسُ مرسى `@` في vite.config.js — ومرسيان يفترقان يومًا
        alias: { '@': path.resolve(__dirname, 'resources/js') },
    },
    test: {
        environment: 'jsdom',
        globals: true,
        setupFiles: ['./tests/js/setup.ts'],
        include: ['tests/js/**/*.test.tsx', 'tests/js/**/*.test.ts'],
        restoreMocks: true,
    },
});
