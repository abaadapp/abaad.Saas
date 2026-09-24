import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { pageProps } from './setup';

vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

const { default: ThemePages } = await import('@/Pages/Admin/Website/ThemePages');
const { default: ThemeSeo } = await import('@/Pages/Admin/Website/ThemeSeo');

const SITE = {
    name: 'RIBBON',
    published: true,
    url: 'https://ribbon.abaadapp.om',
    slug: 'ribbon',
    host: 'abaadapp.om',
};

const BASE = {
    translations: {},
    auth: { abilities: ['website'], mayActions: [] },
    theme: 'ribbon',
    site: SITE,
};

const clear = () => {
    for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
};

/**
 * «الصفحات» — أربعُ صفحاتٍ كصفحات كلّ متجرٍ في أبعاد.
 *
 * ═══ وما يُحرَس ═══
 *
 * أنّها تقول ما يقع: صفحةٌ مُشغَّلةٌ ولا تظهر يُقال سببُها، ورابطٌ لا يُعرض
 * إلّا إن كان يُفتح، والرئيسيةُ والمتجرُ بلا مفتاحِ إطفاء.
 */
describe('شاشةُ «الصفحات» للواجهة الخاصّة', () => {
    const draw = (rows: unknown[]) => {
        clear();
        Object.assign(pageProps, {
            ...BASE,
            rows,
            optional: ['about', 'contact'],
            aboutImage: '',
        });

        return render(<ThemePages />);
    };

    const row = (key: string, over: Record<string, unknown> = {}) => ({
        key,
        path: key === 'home' ? '/' : '/' + key,
        fixed: key === 'home' || key === 'shop',
        on: true,
        blocked: null,
        ...over,
    });

    beforeEach(clear);

    it('ترسم الصفحات الأربع بمساراتها', () => {
        draw(['home', 'shop', 'about', 'contact'].map((k) => row(k)));

        for (const k of ['home', 'shop', 'about', 'contact']) {
            expect(screen.getByTestId(`page-${k}`)).toBeInTheDocument();
        }

        expect(screen.getByText('/about')).toBeInTheDocument();
        expect(screen.getByText('/contact')).toBeInTheDocument();
    });

    /** والرئيسيةُ والمتجرُ لا يُطفآن — ومفتاحٌ لا يُدير شيئًا أسوأُ من غيابه */
    it('ولا مفتاحَ إطفاءٍ للرئيسية والمتجر', () => {
        draw(['home', 'shop', 'about', 'contact'].map((k) => row(k)));

        expect(screen.queryByTestId('toggle-home')).toBeNull();
        expect(screen.queryByTestId('toggle-shop')).toBeNull();
        expect(screen.getByTestId('toggle-about')).toBeInTheDocument();
        expect(screen.getByTestId('toggle-contact')).toBeInTheDocument();
    });

    /**
     * و«مُشغَّلةٌ ولا تظهر» تُقال بسببها.
     *
     * صاحبُها يفتح متجره فلا يجد «من نحن» وقد شغّلها بيده — فيظنّ العطبَ
     * في النظام. والسببُ عندنا مكتوب فلا يُترك يُخمَّن.
     */
    it('وتقول لمَ لا تظهر صفحةٌ شغّلها', () => {
        draw([
            row('home'),
            row('shop'),
            row('about', { blocked: 'اكتب نبذتك لتُفتح الصفحة' }),
            row('contact'),
        ]);

        expect(screen.getByTestId('blocked-about')).toHaveTextContent('اكتب نبذتك لتُفتح الصفحة');
        expect(screen.queryByTestId('blocked-contact')).toBeNull();
    });

    /** ولا رابطَ يُعرض على صفحةٍ لا تُفتح — زرٌّ يردّ «غير موجود» يُقرأ عطبًا */
    it('ولا تعرض رابطًا إلى صفحةٍ مطفأةٍ أو فارغة', () => {
        draw([
            row('home'),
            row('shop', { blocked: 'لا صنفَ معروضًا' }),
            row('about', { on: false }),
            row('contact'),
        ]);

        const links = screen.getAllByRole('link', { name: /افتحها/ });

        expect(links.map((a) => a.getAttribute('href'))).toEqual([
            'https://ribbon.abaadapp.om',
            'https://ribbon.abaadapp.om/contact',
        ]);
    });

    /** ومتجرٌ لم يُنشر لا يُعرض له رابطٌ أصلًا */
    it('ولا رابطَ على متجرٍ لم يُنشر', () => {
        clear();
        Object.assign(pageProps, {
            ...BASE,
            site: { ...SITE, published: false, url: null },
            rows: ['home', 'shop', 'about', 'contact'].map((k) => row(k)),
            optional: ['about', 'contact'],
            aboutImage: '',
        });
        render(<ThemePages />);

        expect(screen.queryByRole('link', { name: /افتحها/ })).toBeNull();
    });

    /** ولا «صفحة جديدة»: صفحاتُ هذه الواجهة قوالبُ مكتوبة لا صفوفٌ في جدول */
    it('ولا تَعِد بصفحةٍ تُضاف', () => {
        draw(['home', 'shop', 'about', 'contact'].map((k) => row(k)));

        expect(screen.queryByText(/صفحة جديدة/)).toBeNull();
        expect(screen.queryByText(/حذف/)).toBeNull();
    });
});

/**
 * «الظهور في البحث» — ما يُعرض في نتيجة غوغل قبل أن يُفتح المتجر.
 */
describe('شاشةُ «الظهور في البحث»', () => {
    const draw = (over: Record<string, unknown> = {}) => {
        clear();
        Object.assign(pageProps, {
            ...BASE,
            seo: { title: '', desc: '', index: true },
            fallback: { title: 'RIBBON', description: 'محلُّ وردٍ في الخوير.' },
            limits: { title: 60, desc: 160 },
            ...over,
        });

        return render(<ThemeSeo />);
    };

    beforeEach(clear);

    /** والمعاينةُ تقول ما سيُكتب فعلًا — لا ما في الحقل وحده */
    it('تعرض المحسوب حين لا يكتب شيئًا', () => {
        draw();

        const box = screen.getByTestId('seo-preview');

        expect(box).toHaveTextContent('RIBBON');
        expect(box).toHaveTextContent('محلُّ وردٍ في الخوير.');
        expect(box).toHaveTextContent('https://ribbon.abaadapp.om');
    });

    it('وتعرض ما كتبه حين يكتب', () => {
        draw({ seo: { title: 'ورد وهدايا بمسقط', desc: 'توصيلٌ في اليوم نفسه.', index: true } });

        const box = screen.getByTestId('seo-preview');

        expect(box).toHaveTextContent('ورد وهدايا بمسقط');
        expect(box).toHaveTextContent('توصيلٌ في اليوم نفسه.');
    });

    /** والعدّادُ يقول كم كتب — ويحمرّ بعد الحدّ ولا يمنع الحفظ */
    it('وتعدّ حروفه وتحمرّ بعد الحدّ', () => {
        draw({ seo: { title: 'ا'.repeat(61), desc: '', index: true } });

        const count = screen.getByTestId('count-title');

        expect(count).toHaveTextContent('61 / 60');
        expect(count.querySelector('span')).toHaveClass('text-[#b91c1c]');
    });

    /**
     * وإطفاءُ الفهرسة ليس إغلاقًا — ولا يُترك يُخمَّن.
     *
     * من ظنّها مفتاحَ نشرٍ أطفأها ليُغلق متجره، وهو مفتوحٌ لكلّ من يملك
     * الرابط.
     */
    it('وتقول إنّ إطفاء الفهرسة لا يُغلق المتجر', () => {
        draw();
        expect(screen.queryByTestId('seo-not-closed')).toBeNull();

        draw({ seo: { title: '', desc: '', index: false } });
        expect(screen.getByTestId('seo-not-closed')).toHaveTextContent('هذا لا يُغلق متجرك');
    });
});
