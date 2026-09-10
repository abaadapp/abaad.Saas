import { createRoot } from 'react-dom/client';
import { Site, fontHref } from '@/Pages/Admin/Website/preview/renderer';
import type { SiteDocument } from '@/Pages/Admin/Website/preview/renderer';

/**
 * موقعُ التاجر في متصفّح زبونه — مدخلٌ قائمٌ بذاته.
 *
 * ولا يمرّ بـ`app.tsx`: تلك حزمةُ اللوحة — Inertia وقوائمُها وجداولُها
 * وشاشاتُها كلُّها، وهي ثلث ميغابايت. وزبونٌ يفتح رابطًا من واتساب لينظر
 * إلى منتجٍ لا شأن له بشيءٍ منها، وتحميلُها عليه تأخيرٌ بلا مقابل.
 *
 * فهذا المدخل لا يحمل إلا React وطبقةَ الرسم المشتركة — وهي بلا تبعيّات
 * أصلًا (انظر `renderer/index.ts`).
 *
 * ═══ واللقطة تُقرأ من وسمٍ لا من متغيّرٍ عامّ ═══
 *
 * الخادم يكتبها في `<script type="application/json">`، فمحتواه نصٌّ لا
 * شيفرة مهما كان ما كتبه التاجر في نبذة متجره.
 */

const mount = document.getElementById('site');
const source = document.getElementById('site-doc');

if (mount && source?.textContent) {
    /*
     * وفشلُ القراءة لا يمحو الصفحة.
     *
     * الخادم يكتب نصَّ الموقع داخل الحاوية نفسها، و`createRoot` يفرّغها عند
     * أوّل رسمٍ ناجح. فإن انهار التركيب هنا بقي ما كتبه الخادمُ مقروءًا —
     * ولو مُحي أوّلًا لَبقي الزائر أمام صندوقٍ أبيض.
     */
    try {
        const doc = JSON.parse(source.textContent) as SiteDocument;

        /*
         * وخطُّ الموقع يُطلب من هنا لا من القالب.
         *
         * أسماءُ عائلات غوغل تعيش في `tokens.ts` وحدها. ولو كُتبت في Blade
         * أيضًا لصارتا قائمتين لشيءٍ واحد: يُضاف خطٌّ في الاختيار ولا يُطلب
         * ملفُّه، فيقرأ التاجر اسمَ خطٍّ اختاره ويرى خطَّ النظام.
         */
        const href = fontHref(doc.tokens?.font);

        if (href && !document.querySelector(`link[href="${href}"]`)) {
            const link = document.createElement('link');

            link.rel = 'stylesheet';
            link.href = href;
            document.head.appendChild(link);
        }

        createRoot(mount).render(<Site doc={doc} mode="live" />);
    } catch (error) {
        console.error('تعذّرت قراءة لقطة الموقع', error);
    }
}
