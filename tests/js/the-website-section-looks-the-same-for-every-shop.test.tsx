import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { pageProps } from './setup';

vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

const { default: ThemeSite } = await import('@/Pages/Admin/Website/ThemeSite');
const { default: ThemeDomain } = await import('@/Pages/Admin/Website/ThemeDomain');

/**
 * شاشةُ «عام» لصاحب الواجهة الخاصّة — بشكل شاشة جاره.
 *
 * ═══ وما يُحرَس ═══
 *
 * أنّها تقول ما يقع: رابطٌ لا يُعرض على متجرٍ لا يُفتح، وعددُ طرق الدفع
 * يُقرأ من قارئه، وأبوابُها تقود إلى شاشاتٍ لا إلى وعود.
 */

const draw = (over: Record<string, unknown> = {}) => {
    for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];

    Object.assign(pageProps, {
        translations: {},
        auth: { abilities: ['website'], mayActions: [] },
        theme: 'ribbon',
        site: {
            name: 'RIBBON',
            published: true,
            url: 'https://ribbon.abaadapp.om',
            slug: 'ribbon',
            host: 'abaadapp.om',
        },
        readiness: [],
        counts: { sections: 5, allSections: 7, shown: 12, active: 40, payments: 2, pages: 3, allPages: 4, seo: false },
        ...over,
    });

    return render(<ThemeSite />);
};

describe('شاشةُ «عام» للواجهة الخاصّة', () => {
    beforeEach(() => {
        for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    });

    /** والأبوابُ السبعة مرسومةٌ كأبواب جاره */
    it('ترسم أبوابها وتقول ما وراء كلّ باب', () => {
        draw();

        /*
         * والأسماءُ تتكرّر: ثلاثةٌ منها في شريط التبويبات أيضًا — وهو
         * الشكلُ المقصود. فالبابُ يُعرف بوصفه لا باسمه وحده.
         */
        expect(screen.getByText(/رتّب أقسام صفحتك واكتب فيها/)).toBeInTheDocument();
        expect(screen.getByText(/كيف يدفع زبونك وكيف يستلم/)).toBeInTheDocument();
        expect(screen.getByText(/أين يُفتح متجرك/)).toBeInTheDocument();
        expect(screen.getByText(/الأصناف التي يراها زبونك/)).toBeInTheDocument();
        expect(screen.getByText(/صفحاتُ متجرك الأربع/)).toBeInTheDocument();
        expect(screen.getByText(/كما يقرؤهما من يبحث/)).toBeInTheDocument();
        expect(screen.getByText(/ما يراه محرّك البحث/)).toBeInTheDocument();

        expect(screen.getByText('5 قسمًا ظاهرًا من 7')).toBeInTheDocument();
        expect(screen.getByText('3 صفحةً تُفتح من 4')).toBeInTheDocument();
        expect(screen.getByText('محسوبٌ من اسمك ونبذتك')).toBeInTheDocument();
        expect(screen.getByText('12 صنفًا معروضًا من 40')).toBeInTheDocument();
        expect(screen.getByText('ribbon.abaadapp.om')).toBeInTheDocument();
    });

    /**
     * ورابطُ المتجر لا يُعرض إلّا إن كان يُفتح.
     *
     * زرٌّ يردّ «غير موجود» يجعل صاحبَه يظنّ العطبَ في النظام لا في مفتاحٍ
     * أطفأه بيده — ويُشار إلى بابه بدل أن يُترك يبحث.
     */
    it('ولا تعرض رابطًا على متجرٍ لم يُنشر — وتقول أين يُنشر', () => {
        draw({ site: { name: 'RIBBON', published: false, url: null, slug: null, host: 'abaadapp.om' } });

        expect(screen.queryByRole('link', { name: /زيارة المتجر/ })).toBeNull();
        expect(screen.getByText(/متجرك لا يفتحه أحدٌ بعد/)).toBeInTheDocument();
        expect(screen.getByRole('link', { name: /اضبط عنوانه وانشره/ })).toHaveAttribute(
            'href',
            '/admin.website.domain',
        );
        expect(screen.getByText('بلا عنوان بعد')).toBeInTheDocument();
    });

    /** ومتجرٌ بلا طريقة دفعٍ يُقال عنه ذلك — لا يُترك يُكتشف من زبونٍ لم يستطع الطلب */
    it('وتقول إنّ متجرًا بلا طريقة دفعٍ لا يقبل طلبًا', () => {
        draw({ counts: { sections: 5, allSections: 7, shown: 12, active: 40, payments: 0, pages: 3, allPages: 4, seo: false } });

        expect(screen.getByText('لا طريقة دفع — لا يقبل طلبًا')).toBeInTheDocument();
    });

    /**
     * ولا «آخر نشرة» ولا «تغييراتٌ لم تُنشر».
     *
     * تلك تصدق على موقعٍ يُنشر لقطةً. وهذا يقرأ إعداداته كلّما فُتح — فسطرٌ
     * يقول «آخر نشرة» يُقرأ وعدًا بأنّ ما بعدها لم يصل، وقد وصل كلُّه.
     */
    it('ولا تَعِد بنشرٍ لا يقع', () => {
        draw();

        expect(screen.queryByText(/نشر التغييرات/)).toBeNull();
        expect(screen.queryByText(/النسخ السابقة/)).toBeNull();
        expect(screen.queryByText(/وضع الصيانة/)).toBeNull();
        expect(screen.getByText(/ما تحفظه يصل زبونك فورًا/)).toBeInTheDocument();
    });
});

describe('شاشةُ «العنوان والنشر»', () => {
    const drawDomain = (over: Record<string, unknown> = {}) => {
        for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];

        Object.assign(pageProps, {
            translations: {},
            auth: { abilities: ['website'], mayActions: [] },
            theme: 'ribbon',
            site: { name: 'RIBBON', published: true, url: 'https://ribbon.abaadapp.om', slug: 'ribbon', host: 'abaadapp.om' },
            path: 'sub',
            pricing: { currency: 'ر.ع', subdomain: { free: true, monthly: 0, yearly: 0 }, custom: { monthly: 0, yearly: 0 } },
            suggestion: 'ribbon',
            storeOn: true,
            productCount: 12,
            ...over,
        });

        return render(<ThemeDomain />);
    };

    beforeEach(() => {
        for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    });

    /** والعنوانُ يُبنى أمام عينه بقاعدة الخادم نفسِها — لا يُكتب ثمّ يُفاجأ */
    it('تُري العنوان كما سيقرؤه الزبون', () => {
        drawDomain();

        expect(screen.getByText('https://ribbon.abaadapp.om')).toBeInTheDocument();
    });

    /**
     * ولا يُنشر متجرٌ بلا بضاعة بلا كلمة.
     *
     * صفحةٌ فارغةٌ تُفقد الزبونَ ثقتَه ولا يعود إليها بعد أن رآها خالية —
     * والتحذيرُ قبل الضغط أنفعُ من تقريرٍ بعده.
     */
    it('وتحذّر من نشر متجرٍ لا صنفَ معروضًا فيه', () => {
        drawDomain({ productCount: 0 });

        expect(screen.getByTestId('empty-shelf')).toHaveTextContent(/ستُفتح الصفحة خالية/);
    });

    /** ومتجرٌ فيه بضاعةٌ لا يُحذَّر — تحذيرٌ لا محلَّ له يُعلَّم أنّه ضجيج */
    it('ولا تحذّر متجرًا فيه بضاعة', () => {
        drawDomain();

        expect(screen.queryByTestId('empty-shelf')).toBeNull();
    });
});
