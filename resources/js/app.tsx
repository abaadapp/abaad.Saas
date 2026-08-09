import '../css/app.css';

import { createInertiaApp, router } from '@inertiajs/react';
import { MotionConfig } from 'framer-motion';
import { createRoot } from 'react-dom/client';
import type { ComponentType } from 'react';

const appName = import.meta.env.VITE_APP_NAME || 'Abad POS';

/**
 * مزامنة اتجاه الصفحة ولغتها مع ما يقوله الخادم في كل استجابة.
 *
 * `dir` و`lang` يُكتبان في قالب الجذر (app.blade.php)، وهو لا يُعاد بناؤه
 * في تنقّلات Inertia ولا عند تبديل اللغة. فكان الاتجاه يعتمد على
 * window.location.reload() بعد التبديل: إن تعثّر أو سبقته الاستجابة، ظهرت
 * الواجهة عربية داخل تخطيط LTR — فتقع سلة نقطة البيع يمينًا بدل يسارًا،
 * وتنقلب كل الحواف المنطقية (start/end).
 *
 * هنا نجعل الـDOM يتبع خاصية `dir` القادمة مع كل صفحة، فلا يبقى الاتجاه
 * معلّقًا على إعادة تحميل قد لا تحدث.
 */
function syncDirection(props: Record<string, unknown>): void {
    const dir = props.dir === 'ltr' || props.dir === 'rtl' ? props.dir : null;
    const locale = typeof props.locale === 'string' ? props.locale : null;
    const root = document.documentElement;

    if (dir && root.getAttribute('dir') !== dir) root.setAttribute('dir', dir);
    if (locale && root.getAttribute('lang') !== locale) root.setAttribute('lang', locale);
}

// كل صفحات Inertia تُحمّل كسولًا — لا تدخل الحزمة الأولى إلا عند زيارتها
const pages = import.meta.glob<{ default: ComponentType }>('./Pages/**/*.tsx');

createInertiaApp({
    title: (title) => (title ? `${title} — ${appName}` : appName),

    resolve: async (name) => {
        const page = pages[`./Pages/${name}.tsx`];

        if (!page) {
            throw new Error(`صفحة Inertia غير موجودة: ./Pages/${name}.tsx`);
        }

        return (await page()).default;
    },

    setup({ el, App, props }) {
        if (el) {
            syncDirection(props.initialPage.props as Record<string, unknown>);
            // وكل تنقّل أو تبديل لغة بعدها
            router.on('success', (event) => {
                syncDirection(event.detail.page.props as Record<string, unknown>);
            });
            // reducedMotion="user": كل حركات framer-motion تحترم تفضيل «تقليل
            // الحركة» في نظام المستخدم — تُلغى الإزاحة والتحجيم ويبقى التلاشي
            // فقط — في مكان واحد بدل فحصٍ في كل مكوّن.
            createRoot(el).render(
                <MotionConfig reducedMotion="user">
                    <App {...props} />
                </MotionConfig>,
            );
        }
    },

    progress: {
        // أسود أبعاد لا بنفسجي القالب القديم
        color: '#111111',
        showSpinner: false,
    },
});
