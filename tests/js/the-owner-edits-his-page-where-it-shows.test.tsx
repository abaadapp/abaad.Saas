import { fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { pageProps } from './setup';

vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

import type { EditorRow } from '@/Pages/Admin/Website/ThemeEditor';

const { default: ThemeEditor } = await import('@/Pages/Admin/Website/ThemeEditor');

/**
 * محرّرُ صفحة الواجهة الخاصّة — الصفوفُ بترتيب ما يُرى، وكلُّ حقلٍ في صفّه.
 *
 * ═══ وما يُحرَس ═══
 *
 * أنّ الشاشة تقول ما يقع: صفٌّ مُشغَّلٌ ولا يظهر يقول لماذا، وآخرُ قسمٍ لا
 * يُطفأ لأنّ إطفاءه يُعيد السبعةَ كلَّها (انظر `StorePage::order` — الفراغُ
 * يعني «ما كان»)، وما لا يُكتب هنا يُقال أين يُكتب.
 */

const row = (over: Partial<EditorRow> & { key: string }): EditorRow => ({
    label: over.key,
    hint: 'وصفُ الصفّ',
    fixed: false,
    on: true,
    silent: null,
    source: null,
    fields: [],
    ...over,
});

const HERO = row({
    key: 'hero',
    label: 'الواجهة',
    fixed: true,
    fields: [{ key: 'store_headline', kind: 'text', label: 'العنوان الكبير' }],
});

const FOOT = row({
    key: 'foot',
    label: 'التذييل',
    fixed: true,
    fields: [{ key: 'store_tagline', kind: 'text', label: 'سطر التذييل' }],
});

const draw = (rows: EditorRow[], over: Record<string, unknown> = {}) => {
    for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    Object.assign(pageProps, { translations: {} });

    const order = rows.filter((r) => ! r.fixed && r.on).map((r) => r.key);

    return render(
        <ThemeEditor
            theme="ribbon"
            site={{ name: 'RIBBON', published: true, url: 'https://ribbon.abaadapp.om', slug: 'ribbon', host: 'abaadapp.om' }}
            /* والافتراضُ «لم يُفتح له النشر» — وهي حالُ كلّ متجرٍ قبل ترحيله */
            publishing={null}
            rows={rows}
            values={{ store_headline: '', store_tagline: '', store_banner_image: '' }}
            order={order}
            maxFeatured={4}
            products={[]}
            {...over}
        />,
    );
};

describe('محرّرُ صفحة المتجر', () => {
    beforeEach(() => {
        for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    });

    /** الواجهةُ أوّلًا والتذييلُ آخرًا — وبينهما ترتيبُ صاحبه */
    it('يعرض الصفوف بترتيب ما يراه الزبون', () => {
        draw([HERO, row({ key: 'about', label: 'عنّا' }), row({ key: 'cats', label: 'الفئات' }), FOOT]);

        const keys = Array.from(document.querySelectorAll('[data-testid^="row-"]')).map((el) =>
            el.getAttribute('data-testid'),
        );

        expect(keys).toEqual(['row-hero', 'row-about', 'row-cats', 'row-foot']);
    });

    /**
     * وحقولُ الصفّ تُفتح بالضغط عليه — لا تُعرض كلُّها معًا.
     *
     * ثلاثون مقبضًا معروضةً في شاشةٍ واحدة هي الحالُ التي بُني هذا المحرّر
     * لأجلها: من أراد أن يبدّل سطرًا في تذييله لم يكن له موضعٌ يبحث فيه.
     */
    it('ولا يفتح إلّا حقولَ الصفّ الذي ضُغط', () => {
        draw([HERO, FOOT]);

        expect(screen.queryByLabelText('العنوان الكبير')).toBeNull();

        fireEvent.click(screen.getByText('الواجهة'));

        expect(screen.getByLabelText('العنوان الكبير')).toBeInTheDocument();
        expect(screen.queryByLabelText('سطر التذييل')).toBeNull();
    });

    /**
     * و«مُشغَّلٌ ولا يظهر» يُقال في صفّه.
     *
     * من شغّل «آراء الزبائن» ثمّ فتح متجره فلم يجدها يظنّ العطبَ في النظام.
     */
    it('ويقول للقسم المشتغل الذي لا يظهر لماذا', () => {
        draw([
            HERO,
            row({ key: 'reviews', label: 'آراء الزبائن', silent: 'لا رأيَ معروضًا بعد.' }),
            FOOT,
        ]);

        expect(screen.getByTestId('silent-reviews')).toHaveTextContent('لا رأيَ معروضًا بعد.');
    });

    /** ولا يُحذَّر عن صفٍّ أطفأه صاحبُه — هو يعرف لمَ أطفأه */
    it('ولا يحذّر عن قسمٍ مطفأ', () => {
        draw([
            HERO,
            row({ key: 'cats', label: 'الفئات' }),
            row({ key: 'reviews', label: 'آراء الزبائن', on: false, silent: null }),
            FOOT,
        ]);

        expect(screen.queryByTestId('silent-reviews')).toBeNull();
    });

    /**
     * ═══ ولا يُطفأ آخرُ قسم ═══
     *
     * قائمةٌ فارغةٌ تُقرأ «ما كان» في `StorePage::order` فتعود السبعةُ كلُّها.
     * فمن أطفأ آخرَ قسمٍ يراها ترجع — والمنعُ مع سببه أصدقُ من تركه يُجرّب.
     */
    it('ويمنع إطفاء آخر قسمٍ ويقول لماذا', () => {
        draw([HERO, row({ key: 'cats', label: 'الفئات' }), row({ key: 'about', label: 'عنّا', on: false }), FOOT]);

        expect(screen.getByRole('checkbox', { name: 'الفئات' })).toBeDisabled();
        expect(screen.getByTestId('last-section')).toBeInTheDocument();
    });

    /** واثنان مشتغلان يُطفأ أيُّهما شاء */
    it('واثنان مشتغلان يُطفأ أيُّهما', () => {
        draw([HERO, row({ key: 'cats', label: 'الفئات' }), row({ key: 'about', label: 'عنّا' }), FOOT]);

        expect(screen.getByRole('checkbox', { name: 'الفئات' })).toBeEnabled();
        expect(screen.queryByTestId('last-section')).toBeNull();
    });

    /** والواجهةُ والتذييلُ هويّةُ الصفحة لا قسمًا يُطفأ — فلا مربّعَ لهما */
    it('ولا يُطفئ الواجهة ولا التذييل', () => {
        draw([HERO, row({ key: 'cats', label: 'الفئات' }), FOOT]);

        expect(within(screen.getByTestId('row-hero')).queryByRole('checkbox')).toBeNull();
        expect(within(screen.getByTestId('row-foot')).queryByRole('checkbox')).toBeNull();
    });

    /**
     * وما لا يُكتب هنا يُقال أين يُكتب.
     *
     * وحقلٌ يُنسخ إلى المحرّر يعني عمودًا يُكتب من بابين — فيُشار إلى بابه.
     */
    it('ويُشير إلى باب المحتوى الذي لا يُكتب فيه', () => {
        draw([
            HERO,
            row({ key: 'cats', label: 'الفئات', source: { label: 'الأصناف والفئات', route: 'admin.products.index' } }),
            FOOT,
        ]);

        fireEvent.click(screen.getByText('الفئات'));

        expect(screen.getByRole('link', { name: /الأصناف والفئات/ })).toHaveAttribute(
            'href',
            '/admin.products.index',
        );
    });

    /** ومتجرٌ لم يُنشر يُحرَّر — فمن يجهّزه يرتّب صفحتَه قبل أن يفتحها */
    it('ويُفتح لمتجرٍ لم يُنشر بعد', () => {
        draw([HERO, FOOT], { site: { name: 'RIBBON', published: false, url: null, slug: null, host: 'abaadapp.om' } });

        expect(screen.getByText(/متجرك لا يفتحه أحدٌ بعد/)).toBeInTheDocument();
        expect(screen.getByText('الواجهة')).toBeInTheDocument();
    });
});
