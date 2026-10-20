import { fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { router as core } from '@inertiajs/core';

import { pageProps } from './setup';

vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

import type { EditorRow } from '@/Pages/Admin/Website/ThemeEditor';
import { serializeCollections } from '@/Pages/Admin/Website/theme/CollectionsField';
import { defaultFooter, parseFooter, serializeFooter } from '@/Pages/Admin/Website/theme/FooterField';

const { default: ThemeEditor } = await import('@/Pages/Admin/Website/ThemeEditor');

/**
 * «التذييل» في المحرّر — أعمدةٌ تُرتَّب وتُسمّى وتُخفى، وما يظهر فيها يُختار بمفتاح.
 *
 * ═══ وما يُحرَس ═══
 *
 *  • الأصليُّ يُكتب فراغًا، فلا يتبدّل متجرٌ لم يرتّب تذييله.
 *  • الأعمدةُ تُرتَّب بسهمين وتُخفى بعين وتُسمّى بلغتيها، وعمودُ «تواصل معنا» يعرض
 *    ما في «بيانات النشاط» ولا يكتبه، ويُطفأ منه سطرٌ سطر.
 *  • وعمودُ «تسوّق» يختار مجموعاتِه، ويُضاف عمودُ نصّ، ويُكتب سطرُ الحقوق ويُطفأ سطرُ التذييل.
 *  • و«احفظ» يُرسل مفاتيحَ الصفّ وحدها: التذييلَ وما يكتبه في مواضعه (السطر وإنستغرام…).
 *
 * وقواعدُ الحفظ والرسم في الخادم: `TheRibbonFooterIsArrangedByItsOwnerTest`.
 */

const FOOT: EditorRow = {
    key: 'foot', label: 'التذييل', hint: '', fixed: true, group: 'main', on: true, silent: null, source: null,
    fields: [
        { key: 'store_footer', kind: 'footer', label: 'أعمدة التذييل', max: 2, owns: ['store_tagline', 'store_tagline_en', 'store_whatsapp', 'store_instagram', 'store_hours', 'store_hours_en'] },
        { key: 'store_tagline', kind: 'text', label: 'سطر التذييل' },
        { key: 'store_tagline_en', kind: 'text', label: 'سطر التذييل (English)' },
        { key: 'store_whatsapp', kind: 'text', label: 'واتساب' },
        { key: 'store_instagram', kind: 'text', label: 'إنستغرام' },
        { key: 'store_hours', kind: 'text', label: 'ساعات العمل' },
        { key: 'store_hours_en', kind: 'text', label: 'ساعات العمل (English)' },
    ],
};

const draw = (footer = '') => {
    for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    Object.assign(pageProps, { translations: {} });

    render(
        <ThemeEditor
            theme="ribbon"
            site={{ name: 'RIBBON', published: true, url: 'https://ribbon.abaadapp.om', slug: 'ribbon', host: 'abaadapp.om' }}
            publishing={null}
            pages={[{ key: 'home', path: '/', fixed: true, on: true, blocked: null }]}
            rows={[FOOT]}
            values={{
                store_footer: footer, store_tagline: 'سطرٌ قديم', store_tagline_en: '', store_whatsapp: '', store_instagram: '', store_hours: 'يوميًّا', store_hours_en: '',
                store_collections: serializeCollections([{ slug: 'occasions', title_ar: 'المناسبات', title_en: '', desc_ar: '', desc_en: '', image: '', visible: true, in_row: true, products: [] }]),
            }}
            order={[]}
            maxFeatured={4}
            products={[]}
            contact={{ phone: '+968 9000 0000', email: 'hello@ribbon.om', address: 'مسقط' }}
        />,
    );

    fireEvent.click(screen.getByText('التذييل'));
};

const capture = () => {
    const sent: { data: Record<string, unknown> }[] = [];
    vi.spyOn(core, 'post').mockImplementation(((_url: string, data: Record<string, unknown>, options: Record<string, unknown>) => {
        sent.push({ data });
        (options as { onSuccess?: () => void }).onSuccess?.();
        (options as { onFinish?: () => void }).onFinish?.();
    }) as never);

    return sent;
};

const save = () => fireEvent.click(screen.getByTestId('row-foot-save'));

afterEach(() => vi.restoreAllMocks());

describe('قراءةُ التذييل وكتابتُه', () => {
    it('الأصليُّ يُكتب فراغًا — والعابثُ يُقرأ أصليًّا، والعمودُ الثابتُ الغائبُ يُلحق آخرَه', () => {
        expect(serializeFooter(defaultFooter())).toBe('');
        expect(parseFooter('{oops')).toEqual(defaultFooter());
        expect(parseFooter(JSON.stringify({ columns: [{ key: 'contact', visible: true, title_ar: '', title_en: '' }] })).columns.map((c) => c.key))
            .toEqual(['contact', 'pages', 'shop']);
    });
});

describe('«التذييل» في المحرّر', () => {
    it('يُرتَّب ويُسمّى ويُخفى، ويُختار ما في «تواصل معنا» — بلا كتابةِ هاتفٍ ولا بريدٍ ثانيةً', () => {
        const sent = capture();
        draw();

        // «تواصل معنا» أوّلًا، باسمٍ آخر، بلا بريد
        fireEvent.click(screen.getByRole('button', { name: 'تحريك لأعلى تواصل معنا' }));
        fireEvent.click(screen.getByRole('button', { name: 'تحريك لأعلى تواصل معنا' }));
        fireEvent.click(screen.getByTestId('footer-col-0-open'));
        fireEvent.change(screen.getByTestId('footer-col-0-title-ar'), { target: { value: 'كلّمنا' } });
        expect(screen.getByTestId('footer-col-0-phone').textContent).toContain('+968 9000 0000');
        fireEvent.click(within(screen.getByTestId('footer-col-0-email')).getByRole('checkbox'));

        // و«تسوّق» مخفيّ
        fireEvent.click(screen.getByTestId('footer-col-2-visible'));

        save();
        const cfg = parseFooter(String(sent[0].data.store_footer));
        expect(cfg.columns.map((c) => `${c.key}:${c.visible}`)).toEqual(['contact:true', 'pages:true', 'shop:false']);
        expect(cfg.columns[0]).toMatchObject({ title_ar: 'كلّمنا', email: false, phone: true });
        expect(Object.keys(sent[0].data).sort()).toEqual(['store_footer', 'store_hours', 'store_hours_en', 'store_instagram', 'store_tagline', 'store_tagline_en', 'store_whatsapp']);
    });

    it('وعمودُ «تسوّق» يختار مجموعاتِه، ويُضاف عمودُ نصّ', () => {
        const sent = capture();
        draw();

        fireEvent.click(screen.getByTestId('footer-col-1-open'));
        fireEvent.click(within(screen.getByTestId('footer-col-1-collection-occasions')).getByRole('checkbox'));
        fireEvent.click(within(screen.getByTestId('footer-col-1-categories')).getByRole('checkbox'));

        fireEvent.click(screen.getByTestId('footer-add-text'));
        fireEvent.change(screen.getByTestId('footer-col-3-title-ar'), { target: { value: 'التوصيل' } });
        fireEvent.change(screen.getByTestId('footer-col-3-body-ar'), { target: { value: 'نوصل في مسقط' } });

        save();
        const cfg = parseFooter(String(sent[0].data.store_footer));
        expect(cfg.columns[1]).toMatchObject({ key: 'shop', collections: ['occasions'], categories: false, all: true });
        expect(cfg.columns[3]).toMatchObject({ key: 'text', title_ar: 'التوصيل', body_ar: 'نوصل في مسقط' });
    });

    it('وسطرُ الحقوق يُكتب، وسطرُ التذييل يُطفأ — والسطرُ نفسُه يُكتب في موضعه', () => {
        const sent = capture();
        draw();

        fireEvent.change(screen.getByTestId('footer-copyright-ar'), { target: { value: 'جميع الحقوق لـ RIBBON' } });
        fireEvent.change(screen.getByTestId('footer-tagline'), { target: { value: 'باقاتٌ تقول ما لا يُقال' } });
        fireEvent.change(screen.getByTestId('footer-instagram'), { target: { value: 'ribbon.om' } });

        save();
        expect(parseFooter(String(sent[0].data.store_footer)).copyright_ar).toBe('جميع الحقوق لـ RIBBON');
        expect(sent[0].data.store_tagline).toBe('باقاتٌ تقول ما لا يُقال');
        expect(sent[0].data.store_instagram).toBe('ribbon.om');

        fireEvent.click(screen.getByRole('switch', { name: 'سطر التذييل' }));
        expect(screen.queryByTestId('footer-tagline')).toBeNull();
    });
});

