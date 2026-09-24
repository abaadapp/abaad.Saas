import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it } from 'vitest';

import PagesSection from '@/Pages/Admin/Website/theme/sections/Pages';
import SeoSection from '@/Pages/Admin/Website/theme/sections/Seo';
import { pageProps } from './setup';
import { THEME_SITE, themeForm } from './theme-form';

/**
 * قسمُ «الصفحات» وقسمُ «الظهور في البحث» من صفحة ضبط الواجهة الخاصّة.
 *
 * ═══ وكانا شاشتين ═══
 *
 * فصارا قسمين في صفحةٍ واحدة حين قِيس ضبطُ المتجر فوُجد ستَّ شاشاتٍ فيها
 * ثلاثةُ مقابض لكلٍّ من ثلاثٍ، واثنان وثلاثون في واحدة. وما يُحرَس لم
 * يتغيّر: أن يقولا ما يقع.
 */

const FALLBACK = { title: 'RIBBON', description: 'محلُّ وردٍ في الخوير.' };
const LIMITS = { title: 60, desc: 160 };

const reset = () => {
    for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    Object.assign(pageProps, {
        translations: {},
        auth: { abilities: ['website'], mayActions: [] },
        context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 } },
    });
};

describe('قسمُ «الصفحات»', () => {
    const row = (key: string, over: Record<string, unknown> = {}) => ({
        key,
        path: key === 'home' ? '/' : '/' + key,
        fixed: key === 'home' || key === 'shop',
        on: true,
        blocked: null,
        ...over,
    });

    const draw = (rows: unknown[], over: Record<string, unknown> = {}, site = THEME_SITE) =>
        render(
            <PagesSection
                form={themeForm(over)}
                site={site}
                rows={rows as never}
                optional={['about', 'contact']}
            />,
        );

    beforeEach(reset);

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

    /**
     * ولا رابطَ يُعرض على صفحةٍ لا تُفتح — زرٌّ يردّ «غير موجود» يُقرأ عطبًا.
     *
     * والإطفاءُ يُقرأ من **النموذج** لا من صفّ الخادم: القلبةُ تنتظر شريطَ
     * الحفظ، فلو قُرئ الصفُّ لَبقي الرابطُ معروضًا على صفحةٍ أطفأها للتوّ.
     */
    it('ولا تعرض رابطًا إلى صفحةٍ مطفأةٍ أو فارغة', () => {
        draw(
            [row('home'), row('shop', { blocked: 'لا صنفَ معروضًا' }), row('about'), row('contact')],
            { store_pages: 'contact' },
        );

        const links = screen.getAllByRole('link', { name: /افتحها/ });

        expect(links.map((a) => a.getAttribute('href'))).toEqual([
            'https://ribbon.abaadapp.om',
            'https://ribbon.abaadapp.om/contact',
        ]);
    });

    /** ومتجرٌ لم يُنشر لا يُعرض له رابطٌ أصلًا */
    it('ولا رابطَ على متجرٍ لم يُنشر', () => {
        draw(['home', 'shop', 'about', 'contact'].map((k) => row(k)), {}, {
            ...THEME_SITE,
            published: false,
            url: null as unknown as string,
        });

        expect(screen.queryByRole('link', { name: /افتحها/ })).toBeNull();
    });

    /** ولا «صفحة جديدة»: صفحاتُ هذه الواجهة قوالبُ مكتوبة لا صفوفٌ في جدول */
    it('ولا تَعِد بصفحةٍ تُضاف', () => {
        draw(['home', 'shop', 'about', 'contact'].map((k) => row(k)));

        expect(screen.queryByText(/صفحة جديدة/)).toBeNull();
        expect(screen.queryByText(/حذف/)).toBeNull();
    });

    /**
     * وحقلُ الصورة يُرسَم ولا يُترك للمتصفّح.
     *
     * `<input type=file>` يرسمه المتصفّحُ «Choose File · No file chosen»
     * بالإنجليزية مهما كانت لغةُ اللوحة — سطرٌ إنجليزيٌّ واحد في لوحةٍ
     * عربيّةٍ كلُّها، في موضعٍ يرفع فيه التاجرُ صورةَ متجره.
     */
    it('وحقلُ الصورة عربيٌّ لا «Choose File»', () => {
        const { container } = draw(['home', 'shop', 'about', 'contact'].map((k) => row(k)));

        expect(screen.getByRole('button', { name: /اختر صورة/ })).toBeInTheDocument();
        expect(container.querySelector('input[type=file]')).toHaveClass('sr-only');
    });
});

describe('قسمُ «الظهور في البحث»', () => {
    const draw = (over: Record<string, unknown> = {}) =>
        render(<SeoSection form={themeForm(over)} site={THEME_SITE} fallback={FALLBACK} limits={LIMITS} />);

    beforeEach(reset);

    /** والمعاينةُ تقول ما سيُكتب فعلًا — لا ما في الحقل وحده */
    it('تعرض المحسوب حين لا يكتب شيئًا', () => {
        draw();

        const box = screen.getByTestId('seo-preview');

        expect(box).toHaveTextContent('RIBBON');
        expect(box).toHaveTextContent('محلُّ وردٍ في الخوير.');
        expect(box).toHaveTextContent('https://ribbon.abaadapp.om');
    });

    it('وتعرض ما كتبه حين يكتب', () => {
        draw({ store_seo_title: 'ورد وهدايا بمسقط', store_seo_desc: 'توصيلٌ في اليوم نفسه.' });

        const box = screen.getByTestId('seo-preview');

        expect(box).toHaveTextContent('ورد وهدايا بمسقط');
        expect(box).toHaveTextContent('توصيلٌ في اليوم نفسه.');
    });

    /** والعدّادُ يقول كم كتب — ويحمرّ بعد الحدّ ولا يمنع الحفظ */
    it('وتعدّ حروفه وتحمرّ بعد الحدّ', () => {
        draw({ store_seo_title: 'ا'.repeat(61) });

        const count = screen.getByTestId('count-title');

        expect(count).toHaveTextContent('61 / 60');
        expect(count.querySelector('span')).toHaveClass('text-[#b91c1c]');
    });

    /**
     * وإطفاءُ الفهرسة ليس إغلاقًا — ولا يُترك يُخمَّن.
     *
     * من ظنّها مفتاحَ نشرٍ أطفأها ليُغلق متجره، وهو مفتوحٌ لكلّ من يملك
     * الرابط. ويُشار إلى المفتاح الذي يُغلق — وهو الآن قسمٌ في الصفحة
     * نفسِها لا شاشةٌ أخرى.
     */
    it('وتقول إنّ إطفاء الفهرسة لا يُغلق المتجر', () => {
        draw({ store_seo_index: false });

        expect(screen.getByTestId('seo-not-closed')).toHaveTextContent(/العنوان والنشر/);
    });

    it('ولا تقول ذلك حين تكون الفهرسة مفتوحة', () => {
        draw();

        expect(screen.queryByTestId('seo-not-closed')).toBeNull();
    });
});
