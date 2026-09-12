import '@testing-library/jest-dom/vitest';
import { cleanup } from '@testing-library/react';
import { afterEach, vi } from 'vitest';

/**
 * ما يقوم مقام الخادم في المتصفّح.
 *
 * الصفحاتُ تقرأ `route()` من Ziggy و`usePage()` من Inertia — وكلاهما يُحقن
 * وقت التشغيل من ردّ الخادم. فيُبدَّلان هنا بأبسط ما يصدق:
 *
 * `route()` تردّ مسارًا مقروءًا لا رابطًا حقيقيًّا. والاختبار لا يفتحه —
 * يتحقّق أنّ الزرّ يقصد هذا الاسم لا غيره.
 */
globalThis.route = ((name: string, params?: unknown) => {
    const id =
        params && typeof params === 'object'
            ? Object.values(params as Record<string, unknown>).join('/')
            : (params ?? '');

    return id === '' ? `/${name}` : `/${name}/${id}`;
}) as never;

/**
 * والمشترَكُ الذي تقرؤه كلُّ صفحة.
 *
 * `translations` فارغةٌ عمدًا: `useTranslate` تردّ المفتاح نفسه حين لا
 * ترجمة، والمفتاحُ هو النصُّ العربيّ — فالاختبارُ يقرأ ما يقرؤه التاجر.
 */
export const pageProps = {
    translations: {},
    context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 } },
    auth: { abilities: [], mayActions: [] },
};

vi.mock('@inertiajs/react', async () => {
    const actual = await vi.importActual<Record<string, unknown>>('@inertiajs/react');

    return {
        ...actual,
        usePage: () => ({ props: pageProps, url: '/', component: 'Test' }),
        router: { get: vi.fn(), post: vi.fn(), delete: vi.fn(), put: vi.fn(), on: vi.fn() },
    };
});

/*
 * وما لا يملكه jsdom يُسَدّ — لا يُتحايل عليه في الكود.
 *
 * `scrollIntoView` ليست في jsdom أصلًا. وسحبُ الصفّ المضيء إلى داخل الإطار
 * سلوكٌ صحيحٌ في المتصفّح، فلا يُحذف من الشاشة لأنّ بيئة الاختبار تنقصه:
 * يُعطى هنا جسدًا فارغًا. وشاشةٌ تُقصّ لتُرضي مُشغِّل اختباراتٍ عطبٌ لا
 * إصلاح.
 */
Element.prototype.scrollIntoView = function scrollIntoView() {};

/*
 * و`ResizeObserver` ليست فيه كذلك.
 *
 * معاينةُ الموقع تقيس نفسها به: عرضُ الحاوية يحدّد التصغير، والتصغيرُ يحدّد
 * ارتفاعَ الورقة وموضعَ صناديق الإمساك. وإطارُ الورقة (`PaperFrame`) مثلُها:
 * يقيس عرضَ حاويته ليُصغّر ورقةَ A4 إليه، وبلا قياسٍ تخرج بعرضها الحقيقيّ
 * فتتجاوز العمود.
 *
 * وjsdom لا يرسم فلا يقيس — فيُعطى جسدًا لا يفعل شيئًا، ويبقى القياسُ الأوّل
 * (الذي يقع عند التركيب) هو ما يُختبر. والبديلُ أن تسأل الشاشةُ عن وجوده قبل
 * أن تستعمله — حيلةٌ في الكود لأجل مُشغّل اختبارات.
 */
if (!('ResizeObserver' in globalThis)) {
    globalThis.ResizeObserver = class {
        observe() {}
        unobserve() {}
        disconnect() {}
    } as never;
}

/*
 * والشجرةُ تُهدَم بين اختبارٍ وآخر.
 *
 * `@testing-library` لا تنظّف وحدها إلا مع globals في بعض التهيئات، وبقاءُ
 * شجرةٍ يجعل `getByText` تجد عنصرين فتسقط باختبارٍ سليم — أو أسوأ: تجد
 * عنصرَ الاختبار السابق فتمرّ وهي كاذبة.
 */
afterEach(() => cleanup());
