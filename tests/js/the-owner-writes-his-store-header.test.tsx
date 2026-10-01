import { fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { router as core } from '@inertiajs/core';

import { pageProps } from './setup';

vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

import type { EditorRow } from '@/Pages/Admin/Website/ThemeEditor';
import { alignOf, shortcutIds } from '@/Pages/Admin/Website/theme/HeaderFields';

const { default: ThemeEditor } = await import('@/Pages/Admin/Website/ThemeEditor');

/**
 * «رأس المتجر» في محرّر الواجهة — صفٌّ واحدٌ فيه الإعلانان والمحاذاةُ
 * واختصاراتُ صفّ «المتجر».
 *
 * ═══ وما يُحرَس ═══
 *
 * أنّ الصفّ أوّلُ الصفوف، وأنّ المحاذاةَ ثلاثةُ أزرارٍ مغلقة (والفراغُ وسط)،
 * وأنّ الاختصاراتِ تُختار من فئات متجره وتُرتَّب وتُزال، وأنّ «احفظ» يُرسل
 * المفاتيحَ الأربعة وحدها بالباب القائم بالترتيب الذي يراه.
 *
 * وقواعدُ الحفظ نفسُها (المتجرُ والحدُّ والمعرّف) تُحرس في الخادم:
 * `TheRibbonHeaderCarriesItsAnnouncementAndShopOptionsTest`.
 */

const HEAD: EditorRow = {
    key: 'head',
    label: 'رأس المتجر',
    hint: 'شريطُ إعلانٍ أعلى كلّ صفحة، واختصاراتُ الفئات في صفحة المتجر',
    fixed: true,
    on: true,
    silent: null,
    source: null,
    fields: [
        { key: 'store_announcement_ar', kind: 'text', label: 'نص الشريط بالعربية' },
        { key: 'store_announcement_en', kind: 'text', label: 'Announcement text in English', dir: 'ltr' },
        { key: 'store_announcement_align', kind: 'align', label: 'محاذاة النص' },
        { key: 'store_shop_nav_categories', kind: 'shortcuts', label: 'اختصارات صفحة المتجر' },
    ],
};

const HERO: EditorRow = {
    key: 'hero', label: 'الواجهة', hint: '', fixed: true, on: true, silent: null, source: null,
    fields: [{ key: 'store_headline', kind: 'text', label: 'العنوان الكبير' }],
};

const FOOT: EditorRow = {
    key: 'foot', label: 'التذييل', hint: '', fixed: true, on: true, silent: null, source: null,
    fields: [{ key: 'store_tagline', kind: 'text', label: 'سطر التذييل' }],
};

const CATS = [
    { id: 11, name: 'باقات', shown: 3 },
    { id: 12, name: 'هدايا', shown: 0 },
    { id: 13, name: 'إضافات', shown: 2 },
];

const draw = (values: Record<string, string> = {}, max = 6) => {
    for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    Object.assign(pageProps, { translations: {} });

    render(
        <ThemeEditor
            theme="ribbon"
            site={{ name: 'RIBBON', published: true, url: 'https://ribbon.abaadapp.om', slug: 'ribbon', host: 'abaadapp.om' }}
            publishing={null}
            pages={[]}
            rows={[HEAD, HERO, FOOT]}
            values={{
                store_announcement_ar: '',
                store_announcement_en: '',
                store_announcement_align: '',
                store_shop_nav_categories: '',
                store_headline: '',
                store_tagline: '',
                ...values,
            }}
            order={[]}
            maxFeatured={4}
            products={[]}
            shortcutCategories={CATS}
            maxShortcuts={max}
        />,
    );

    // يُفتح الصفّ كما يفتحه صاحبُه
    fireEvent.click(within(screen.getByTestId('row-head')).getByText('رأس المتجر'));

    return screen.getByTestId('row-head');
};

const capture = () => {
    const sent: { url: string; data: Record<string, unknown> }[] = [];
    vi.spyOn(core, 'post').mockImplementation(((url: string, data: Record<string, unknown>, options: Record<string, unknown>) => {
        sent.push({ url, data });
        (options as { onFinish?: () => void }).onFinish?.();
    }) as never);

    return sent;
};

const chosen = (row: HTMLElement) =>
    within(row).queryAllByTestId(/^shortcut-\d+$/).map((li) => Number(li.dataset.testid!.replace('shortcut-', '')));

afterEach(() => vi.restoreAllMocks());

describe('رأسُ المتجر في المحرّر', () => {
    it('أوّلُ الصفوف — فوق الواجهة، ولا يُطفأ', () => {
        draw();

        const keys = screen.getAllByTestId(/^row-/).map((el) => el.dataset.testid);

        expect(keys.slice(0, 2)).toEqual(['row-head', 'row-hero']);
        expect(within(screen.getByTestId('row-head')).queryByRole('switch')).toBeNull();
    });

    it('فيه الحقولُ الأربعة بأسمائها', () => {
        const row = draw();

        expect(within(row).getByLabelText('نص الشريط بالعربية')).toBeInTheDocument();
        expect(within(row).getByLabelText('Announcement text in English')).toHaveAttribute('dir', 'ltr');
        expect(within(row).getByRole('radiogroup', { name: 'محاذاة النص' })).toBeInTheDocument();
        expect(within(row).getByTestId('shortcuts-field')).toBeInTheDocument();
    });
});

describe('المحاذاة', () => {
    it('ثلاثةُ أزرارٍ مغلقة — والفراغُ يُقرأ الوسط', () => {
        const row = draw();

        const radios = within(row).getAllByRole('radio');

        expect(radios.map((r) => r.textContent)).toEqual(['يمين', 'في الوسط', 'يسار']);
        expect(within(row).getByTestId('align-center')).toHaveAttribute('aria-checked', 'true');
        expect(alignOf('')).toBe('center');
        expect(alignOf('justify')).toBe('center');
        expect(alignOf('left')).toBe('left');
    });

    it('ويُبدَّل بالضغط — قيمةٌ من الثلاث لا غير', () => {
        const row = draw({ store_announcement_align: 'right' });

        expect(within(row).getByTestId('align-right')).toHaveAttribute('aria-checked', 'true');

        fireEvent.click(within(row).getByTestId('align-left'));

        expect(within(row).getByTestId('align-left')).toHaveAttribute('aria-checked', 'true');
        expect(within(row).getByTestId('align-right')).toHaveAttribute('aria-checked', 'false');
    });
});

describe('اختصاراتُ صفّ المتجر', () => {
    it('تُختار من فئات متجره — والفارغةُ يُقال إنّها لا تظهر', () => {
        const row = draw();
        const add = within(row).getByTestId('shortcut-add') as HTMLSelectElement;

        expect([...add.options].map((o) => o.value)).toEqual(['', '11', '12', '13']);

        fireEvent.change(add, { target: { value: '12' } });
        fireEvent.change(within(row).getByTestId('shortcut-add'), { target: { value: '11' } });

        expect(chosen(row)).toEqual([12, 11]);
        expect(within(within(row).getByTestId('shortcut-12')).getByText('لا تظهر — بلا منتجٍ معروض')).toBeInTheDocument();
        expect(within(within(row).getByTestId('shortcut-11')).queryByText('لا تظهر — بلا منتجٍ معروض')).toBeNull();
        // والمختارُ لا يُعرض للاختيار ثانيةً
        expect([...(within(row).getByTestId('shortcut-add') as HTMLSelectElement).options].map((o) => o.value)).toEqual(['', '13']);
    });

    it('تُرتَّب بسهمين وتُزال', () => {
        const row = draw({ store_shop_nav_categories: '11,12,13' });

        expect(chosen(row)).toEqual([11, 12, 13]);

        fireEvent.click(within(row).getByRole('button', { name: 'أنزل باقات' }));
        expect(chosen(row)).toEqual([12, 11, 13]);

        fireEvent.click(within(row).getByRole('button', { name: 'ارفع إضافات' }));
        expect(chosen(row)).toEqual([12, 13, 11]);

        fireEvent.click(within(row).getByRole('button', { name: 'أزل هدايا' }));
        expect(chosen(row)).toEqual([13, 11]);
    });

    it('وتقف عند الحدّ — لا سابعةَ تُضاف', () => {
        const row = draw({ store_shop_nav_categories: '11,12' }, 2);

        const add = within(row).getByTestId('shortcut-add') as HTMLSelectElement;

        expect(add).toBeDisabled();
        expect(add.options[0].textContent).toBe('اكتملت الاختصارات');
    });

    it('وفئةٌ حُذفت بعد اختيارها تُعرض لتُزال — لا تختفي وتبقى في الإعداد', () => {
        const row = draw({ store_shop_nav_categories: '99,11' });

        expect(chosen(row)).toEqual([99, 11]);
        expect(within(within(row).getByTestId('shortcut-99')).getByText('فئةٌ لم تعد موجودة')).toBeInTheDocument();
    });

    it('والقيمةُ تُقرأ معرّفاتٍ موجبةً فريدةً بترتيبها', () => {
        expect(shortcutIds('3, 1,3,,0,-2,x,7')).toEqual([3, 1, 7]);
        expect(shortcutIds('')).toEqual([]);
    });
});

describe('الحفظ', () => {
    it('«احفظ» يُرسل المفاتيحَ الأربعة وحدها — بالباب القائم وبالترتيب المرئيّ', () => {
        const sent = capture();
        const row = draw({ store_shop_nav_categories: '11' });

        fireEvent.change(within(row).getByLabelText('نص الشريط بالعربية'), { target: { value: 'توصيلٌ اليوم' } });
        fireEvent.change(within(row).getByLabelText('Announcement text in English'), { target: { value: 'Same-day delivery' } });
        fireEvent.click(within(row).getByTestId('align-left'));
        fireEvent.change(within(row).getByTestId('shortcut-add'), { target: { value: '13' } });
        fireEvent.click(within(row).getByRole('button', { name: 'ارفع إضافات' }));

        fireEvent.click(within(row).getByRole('button', { name: 'احفظ' }));

        expect(sent).toHaveLength(1);
        expect(sent[0].url).toContain('admin.marketing.store.save');
        expect(sent[0].data).toEqual({
            store_announcement_ar: 'توصيلٌ اليوم',
            store_announcement_en: 'Same-day delivery',
            store_announcement_align: 'left',
            store_shop_nav_categories: '13,11',
        });
    });
});
