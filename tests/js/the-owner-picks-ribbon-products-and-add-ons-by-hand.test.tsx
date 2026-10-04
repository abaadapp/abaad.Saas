import { fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { router as core } from '@inertiajs/core';

import { pageProps } from './setup';

vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

import type { EditorRow } from '@/Pages/Admin/Website/ThemeEditor';
import { keepShown, parseIds } from '@/Pages/Admin/Website/theme/ProductListField';

const { default: ThemeEditor } = await import('@/Pages/Admin/Website/ThemeEditor');

/**
 * «اختيارات RIBBON» و«أضف مع طلبك» في محرّر الواجهة — أصنافٌ تُختار بيد.
 *
 * ═══ وما يُحرَس ═══
 *
 * أنّ حقلَ `products` يعرض المختارَ مرقّمًا ويضيف ويُزيل ويرتّب بسهمين حتّى
 * حدّه الآتي من الخادم، ولا يكتب صاحبُ المحلّ رقمًا بيده. وأنّ ما أُخفي بعد
 * اختياره لا يُرسَل. وأنّ عنوانَ الواجهة لكلّ لغةٍ حقلُه، والإنجليزيُّ
 * يُكتب من اليسار. وأنّ «أضف مع طلبك» صفٌّ بعد التذييل لا قسمٌ يُرتَّب.
 *
 * وقواعدُ الحفظ نفسُها (المتجرُ والمعروضُ والحدّ والإذن) تُحرس في الخادم:
 * `RibbonCuratesItsPicksAndAddOnsByHandTest`. فالشاشةُ لا تحرس بابًا.
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

const PAGES = [{ key: 'home', path: '/', fixed: true, on: true, blocked: null }];

const HERO = row({
    key: 'hero',
    label: 'الواجهة',
    fixed: true,
    fields: [
        { key: 'store_hero_title', kind: 'text', label: 'عنوان الواجهة — العربية' },
        { key: 'store_hero_title_en', kind: 'text', label: 'Hero title — English', dir: 'ltr' },
        { key: 'store_hero_sub', kind: 'textarea', label: 'وصف الواجهة — العربية' },
        { key: 'store_hero_sub_en', kind: 'textarea', label: 'Hero description — English', dir: 'ltr' },
    ],
});
const PICKS = row({
    key: 'picks',
    label: 'اختيارات RIBBON',
    on: false,
    fields: [
        { key: 'store_picks_title', kind: 'text', label: 'عنوان القسم — العربية' },
        { key: 'store_picks', kind: 'products', label: 'الأصناف', max: 3 },
    ],
});
const FOOT = row({ key: 'foot', label: 'التذييل', fixed: true });
const UPSELLS = row({
    key: 'upsells',
    label: 'أضف مع طلبك',
    fixed: true,
    fields: [{ key: 'store_ribbon_upsells', kind: 'products', label: 'أضف مع طلبك', max: 6 }],
});

/** المعروضُ من أصناف متجره — ما يجوز اختيارُه */
const PRODUCTS = [
    { id: 36, name: 'بالون', image: null },
    { id: 37, name: 'شوكولاتة', image: null },
    { id: 38, name: 'ورد أحمر', image: '/storage/p/38.jpg' },
    { id: 39, name: 'باقة الربيع', image: null },
];

const draw = (values: Record<string, string> = {}, rows: EditorRow[] = [HERO, PICKS, FOOT, UPSELLS]) => {
    for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    Object.assign(pageProps, { translations: {} });

    render(
        <ThemeEditor
            theme="ribbon"
            site={{ name: 'RIBBON', published: true, url: 'https://ribbon.abaadapp.om', slug: 'ribbon', host: 'abaadapp.om' }}
            publishing={null}
            pages={PAGES}
            rows={rows}
            values={{ store_hero_title: '', store_hero_title_en: '', store_hero_sub: '', store_hero_sub_en: '', store_picks_title: '', store_picks: '', store_ribbon_upsells: '', ...values }}
            order={['cats']}
            maxFeatured={4}
            products={PRODUCTS}
        />,
    );
};

const chosen = (name: string) =>
    within(screen.getByTestId(`list-${name}-chosen`))
        .getAllByRole('listitem')
        .map((li) => Number(li.dataset.testid!.replace(`list-${name}-chosen-`, '')));

const press = (label: string) => fireEvent.click(screen.getByRole('button', { name: label }));

const capture = () => {
    const sent: { url: string; data: Record<string, unknown> }[] = [];
    vi.spyOn(core, 'post').mockImplementation(((url: string, data: Record<string, unknown>, options: Record<string, unknown>) => {
        sent.push({ url, data });
        (options as { onFinish?: () => void }).onFinish?.();
    }) as never);

    return sent;
};

afterEach(() => vi.restoreAllMocks());

describe('قراءةُ القائمة', () => {
    it('معرّفاتٌ موجبةٌ فريدةٌ بترتيبها — والعابثُ يسقط', () => {
        expect(parseIds('12, 7,abc,-3,0,7,30')).toEqual([12, 7, 30]);
        expect(parseIds('')).toEqual([]);
    });

    it('ولا يُرسَل إلّا ما يُعرض الآن', () => {
        expect(keepShown('38,99,36', PRODUCTS)).toBe('38,36');
    });
});

describe('«اختيارات RIBBON»', () => {
    it('يضيف ويرتّب ويُزيل — بلا رقمٍ يُكتب باليد', () => {
        draw();
        fireEvent.click(screen.getByText('اختيارات RIBBON'));

        expect(screen.getByTestId('list-store_picks-none')).toBeTruthy();
        expect(screen.queryByRole('spinbutton')).toBeNull();

        press('إضافة ورد أحمر');
        press('إضافة بالون');
        expect(chosen('store_picks')).toEqual([38, 36]);

        press('تحريك لأعلى بالون');
        expect(chosen('store_picks')).toEqual([36, 38]);

        press('إزالة بالون');
        expect(chosen('store_picks')).toEqual([38]);
    });

    it('يقف عند حدّه الآتي من الخادم', () => {
        draw({ store_picks: '36,37,38' });
        fireEvent.click(screen.getByText('اختيارات RIBBON'));

        expect(chosen('store_picks')).toEqual([36, 37, 38]);
        expect((screen.getByRole('button', { name: 'إضافة باقة الربيع' }) as HTMLButtonElement).disabled).toBe(true);
    });

    it('صنفٌ أُخفي بعد اختياره لا يُعرض ولا يُرسَل — والحفظُ يحمل مفاتيحَ الصفّ وحدها', () => {
        const sent = capture();
        draw({ store_picks: '99,39,36' });
        fireEvent.click(screen.getByText('اختيارات RIBBON'));

        expect(chosen('store_picks')).toEqual([39, 36]);

        press('احفظ');
        expect(sent).toHaveLength(1);
        expect(sent[0].data).toEqual({ store_picks_title: '', store_picks: '39,36' });
    });
});

describe('«أضف مع طلبك»', () => {
    it('صفٌّ بعد التذييل لا قسمٌ يُرتَّب — ويحفظ قائمتَه وحدها', () => {
        const sent = capture();
        draw();

        const rows = screen.getAllByTestId(/^row-/).map((li) => li.dataset.testid);
        expect(rows.indexOf('row-upsells')).toBeGreaterThan(rows.indexOf('row-foot'));
        expect(within(screen.getByTestId('row-upsells')).queryByRole('switch')).toBeNull();

        fireEvent.click(screen.getByText('أضف مع طلبك', { selector: 'span' }));
        press('إضافة شوكولاتة');
        press('إضافة ورد أحمر');
        press('احفظ');

        expect(sent[0].data).toEqual({ store_ribbon_upsells: '37,38' });
    });

    it('ومن لا صفَّ له لا يُرسم له شيء', () => {
        draw({}, [HERO, PICKS, FOOT]);

        expect(screen.queryByTestId('row-upsells')).toBeNull();
    });
});

describe('عنوانُ الواجهة', () => {
    it('لكلّ لغةٍ حقلُه — والإنجليزيُّ يُكتب من اليسار', () => {
        draw({ store_hero_title: 'ورد', store_hero_title_en: 'Flowers' });
        fireEvent.click(screen.getByText('الواجهة'));

        const ar = screen.getByRole('textbox', { name: 'عنوان الواجهة — العربية' }) as HTMLInputElement;
        const en = screen.getByRole('textbox', { name: 'Hero title — English' }) as HTMLInputElement;
        const enSub = screen.getByRole('textbox', { name: 'Hero description — English' }) as HTMLTextAreaElement;

        expect(ar.value).toBe('ورد');
        expect(en.value).toBe('Flowers');
        expect(en.dir).toBe('ltr');
        expect(enSub.dir).toBe('ltr');
    });
});
