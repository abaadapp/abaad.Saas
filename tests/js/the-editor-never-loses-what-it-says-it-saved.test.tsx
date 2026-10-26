import { act, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { router as core } from '@inertiajs/core';

import { router } from '@inertiajs/react';

import { pageProps } from './setup';

vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

import type { EditorRow } from '@/Pages/Admin/Website/ThemeEditor';
import { type MenuItem, parseMenu, serializeMenu } from '@/Pages/Admin/Website/theme/MenuField';
import { type CustomPage, parsePages, serializePages } from '@/Pages/Admin/Website/theme/pages';

const { default: ThemeEditor } = await import('@/Pages/Admin/Website/ThemeEditor');

/**
 * المحرّرُ لا يُضيّع ما قال إنّه حفظه — ولا ما لم يُحفظ بعد.
 *
 * ═══ الحادثة (RIBBON، ٢٦ أكتوبر ٢٠٢٦، Chrome على iPhone) ═══
 *
 * رفع صاحبُ المتجر صورًا في صفحته فلم تُحفظ، وأضاف وحداتٍ حتّى «بلغت الحدّ —
 * ٢٠ وحدة» ثمّ حفظ فعادت الصفحةُ بخمس عشرة، وانهار المتصفّحُ («Can't open this
 * page») فذهب ما لم يُحفظ. والأدلّة: الصورةُ على القرص (17:40:37) ولا تذكرها صفحة.
 *
 * ═══ وما يُحرَس ═══
 *
 *  • صورةٌ تُرفع إلى وحدةٍ تبقى فيها — كانت «الصورة كاملة» تُكتب بعدها من نسخةٍ
 *    بلا صورة فتمحوها، فتصير الوحدةُ فارغةً تُطرح عند الحفظ.
 *  • وما يُكتب أثناء الرفع لا يمحوه الرفعُ حين يعود.
 *  • ورفعٌ يفشل يقول لماذا، ويُعاد من الحقل نفسِه.
 *  • والحفظُ والصورةُ تُرفع ينتظرها ثمّ يُرسل الصفحةَ بصورتها.
 *  • وحفظٌ واحدٌ في الطريق: الثاني ينتظر ويُرسَل بآخر ما في الشاشة، وما يُعدّ
 *    محفوظًا هو ما أُرسل — وما كُتب أثناءه يبقى غيرَ محفوظ.
 *  • وفشلُ الحفظ (حقلٌ مرفوض، انقطاعٌ، جلسةٌ انتهت) يُقال ويُبقي ما كُتب.
 *  • ونسخةٌ على الجهاز تُسترجع بعد انهيار المتصفّح أو إعادة فتحه.
 *  • وعشرون وحدةً تُحفظ كلُّها، والحادية والعشرون لا تُضاف ولا تمحو شيئًا.
 *  • وقائمةٌ بصفحتين — الخدمات وخدمات الشركات — تُحفظ في حفظٍ واحد.
 *
 * وما يحفظه الخادمُ ويُعيده: `WhatTheEditorSavesIsWhatItReopensWithTest`.
 */

const row = (over: Partial<EditorRow> & { key: string }): EditorRow => ({
    label: over.key, hint: '', fixed: true, group: 'main', on: true, silent: null, source: null, fields: [], ...over,
});

const ROWS = [
    row({
        key: 'menu',
        label: 'القائمة الرئيسية والصفحات',
        fields: [
            { key: 'store_menu', kind: 'menu', label: 'عناصر القائمة', max: 10, max_pages: 12, max_blocks: 20, owns: ['store_custom_pages'] },
            { key: 'store_custom_pages', kind: 'pages', label: 'الصفحات' },
        ],
    }),
    // وقسمان يُرتَّبان بالسهم — يُحفظ ترتيبُهما بالضغطة
    row({ key: 'cats', label: 'تسوّق حسب الفئة', fixed: false }),
    row({ key: 'best', label: 'الأكثر مبيعًا', fixed: false }),
];

const page = (over: Partial<CustomPage> = {}): CustomPage => ({
    slug: 'services', kind: 'mixed', title_ar: 'خدماتنا', title_en: 'Services', intro_ar: '', intro_en: '', blocks: [], visible: true, ...over,
});

const linkTo = (slug: string, title: string): MenuItem => ({ type: 'link', id: 'l1', title_ar: title, title_en: '', target: { type: 'page', value: slug }, visible: true });

const URL_A = '/storage/website/1/a.jpg';

const draw = (menu = serializeMenu([...parseMenu(''), linkTo('services', 'خدماتنا')]), pages = serializePages([page()])) => {
    for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    Object.assign(pageProps, { translations: {}, auth: { user: { id: 7, businessId: 5 }, abilities: [], mayActions: [] } });

    const view = render(
        <ThemeEditor
            theme="ribbon"
            site={{ name: 'RIBBON', published: true, url: 'https://ribbon.abaadapp.om', slug: 'ribbon', host: 'abaadapp.om' }}
            publishing={null}
            pages={[
                { key: 'home', path: '/', fixed: true, on: true, blocked: null },
                { key: 'shop', path: '/shop', fixed: true, on: true, blocked: null },
                { key: 'about', path: '/about', fixed: false, on: true, blocked: null },
                { key: 'contact', path: '/contact', fixed: false, on: true, blocked: null },
            ]}
            rows={ROWS}
            values={{ store_menu: menu, store_custom_pages: pages }}
            order={['cats', 'best']}
            maxFeatured={4}
            products={[]}
            shortcutCategories={[]}
        />,
    );

    fireEvent.click(screen.getByText('القائمة الرئيسية والصفحات'));

    return view;
};

/** ما يُحفظ — يُسجَّل، ويُجاب حين يُطلب (`answer`) لا في الحال */
const capture = () => {
    const sent: { data: Record<string, unknown>; options: Record<string, (...a: unknown[]) => unknown> }[] = [];
    vi.spyOn(core, 'post').mockImplementation(((_url: string, data: Record<string, unknown>, options: Record<string, (...a: unknown[]) => unknown>) => {
        sent.push({ data, options });
    }) as never);

    const answer = (i: number, how: 'ok' | ((o: Record<string, (...a: unknown[]) => unknown>) => void) = 'ok') =>
        act(() => {
            const o = sent[i].options;
            if (how === 'ok') o.onSuccess?.({ props: {} });
            else how(o);
            o.onFinish?.();
        });

    return { sent, answer };
};

/** رفعٌ يُجاب حين يُطلب — كما يعود على شبكة هاتف */
const uploadsAnswered = () => {
    const pending: Array<(r: Response) => void> = [];
    const fetched = vi.spyOn(globalThis, 'fetch').mockImplementation(() => new Promise<Response>((resolve) => pending.push(resolve)));
    const reply = async (i: number, status: number, body: unknown) => {
        await act(async () => {
            pending[i](new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }));
            await Promise.resolve();
        });
    };

    return { fetched, pending, reply };
};

const openServices = () => fireEvent.click(screen.getByTestId('menu-item-4-open'));
const P = 'menu-item-4-page';
const pagesOf = (data: Record<string, unknown>) => parsePages(String(data.store_custom_pages));
const pick = (testid: string) => {
    const input = within(screen.getByTestId(testid)).getByLabelText('الصورة', { selector: 'input' });
    fireEvent.change(input, { target: { files: [new File(['x'], 'p.jpg', { type: 'image/jpeg' })] } });
};

beforeEach(() => window.localStorage.clear());
afterEach(() => {
    vi.restoreAllMocks();
    vi.useRealTimers();
});

describe('صورةُ الوحدة', () => {
    it('تبقى في وحدتها بعد الرفع وتُحفظ معها — بطريقة «الصورة كاملة»', async () => {
        const { reply } = uploadsAnswered();
        const { sent, answer } = capture();
        draw();
        openServices();
        fireEvent.click(screen.getByTestId(`${P}-add-image`));

        pick(`${P}-block-0`);
        await reply(0, 200, { url: URL_A, meta: { w: 1200, h: 800 } });

        expect(screen.queryByTestId(`${P}-block-0-empty`)).toBeNull();
        fireEvent.click(screen.getByTestId('row-menu-save'));
        expect(pagesOf(sent[0].data)[0].blocks).toEqual([{ type: 'image', image: URL_A, fit: 'contain' }]);
        answer(0);
    });

    it('وما يُكتب أثناء الرفع لا يمحوه الرفعُ حين يعود', async () => {
        const { reply } = uploadsAnswered();
        const { sent } = capture();
        draw();
        openServices();
        fireEvent.click(screen.getByTestId(`${P}-add-image`));
        fireEvent.click(screen.getByTestId(`${P}-add-heading`));

        pick(`${P}-block-0`);
        // والصورةُ في الطريق: يكتب العنوانَ واسمَ الصفحة
        fireEvent.change(screen.getByTestId(`${P}-block-1-text`), { target: { value: 'Strategic Business Collaborations' } });
        fireEvent.change(screen.getByTestId(`${P}-title-ar`), { target: { value: 'خدمات الشركات' } });
        await reply(0, 200, { url: URL_A, meta: null });

        fireEvent.click(screen.getByTestId('row-menu-save'));
        const saved = pagesOf(sent[0].data)[0];
        expect(saved.title_ar).toBe('خدمات الشركات');
        expect(saved.blocks).toEqual([
            { type: 'image', image: URL_A, fit: 'contain' },
            { type: 'heading', text_ar: 'Strategic Business Collaborations', text_en: '' },
        ]);
    });

    it('ورفعٌ يفشل يقول لماذا — ويُعاد من الحقل نفسِه فيبقى', async () => {
        const { reply } = uploadsAnswered();
        draw();
        openServices();
        fireEvent.click(screen.getByTestId(`${P}-add-image`));

        pick(`${P}-block-0`);
        await reply(0, 422, { message: 'x', errors: { image: ['الملف ليس صورة.'] } });
        expect(screen.getByText('الملف ليس صورة.')).toBeInTheDocument();

        pick(`${P}-block-0`);
        await reply(1, 419, {});
        expect(screen.getByText(/انتهت جلستك/)).toBeInTheDocument();

        pick(`${P}-block-0`);
        await reply(2, 200, { url: URL_A, meta: null });
        expect(screen.queryByTestId(`${P}-block-0-empty`)).toBeNull();
        expect(screen.queryByText(/انتهت جلستك/)).toBeNull();
    });

    it('والحفظُ والصورةُ تُرفع: ينتظرها — ثمّ يُرسل الصفحةَ بصورتها', async () => {
        const { reply } = uploadsAnswered();
        const { sent } = capture();
        draw();
        openServices();
        fireEvent.click(screen.getByTestId(`${P}-add-image`));

        pick(`${P}-block-0`);
        fireEvent.click(screen.getByTestId('row-menu-save'));
        expect(sent).toHaveLength(0);
        expect(screen.getByTestId('row-menu-save-status')).toHaveTextContent('ينتظر اكتمال رفع الصورة');

        await reply(0, 200, { url: URL_A, meta: null });
        expect(sent).toHaveLength(1);
        expect(pagesOf(sent[0].data)[0].blocks[0]).toEqual({ type: 'image', image: URL_A, fit: 'contain' });
    });
});

describe('الحفظ', () => {
    it('ضغطتان سريعتان على «احفظ»: طلبٌ واحد — والزرُّ لا يُضغط ثانيةً والحفظُ في الطريق', () => {
        const { sent, answer } = capture();
        draw();
        openServices();

        fireEvent.change(screen.getByTestId(`${P}-title-ar`), { target: { value: 'الأولى' } });
        fireEvent.click(screen.getByTestId('row-menu-save'));
        fireEvent.click(screen.getByTestId('row-menu-save'));
        expect(sent).toHaveLength(1);

        fireEvent.change(screen.getByTestId(`${P}-title-ar`), { target: { value: 'الثانية' } });
        answer(0);
        expect(screen.getByTestId('row-menu-save')).not.toBeDisabled();

        fireEvent.click(screen.getByTestId('row-menu-save'));
        expect(sent).toHaveLength(2);
        expect(pagesOf(sent[0].data)[0].title_ar).toBe('الأولى');
        expect(pagesOf(sent[1].data)[0].title_ar).toBe('الثانية');
    });

    it('وترتيبٌ يُضغط والصفحاتُ تُحفظ: لا يقطعها — يُرسَل بعدها، فلا يصل جوابٌ قديمٌ بعد جديد', () => {
        const { sent, answer } = capture();
        const order = vi.mocked(router.post);
        order.mockClear();
        draw();
        openServices();

        fireEvent.change(screen.getByTestId(`${P}-title-ar`), { target: { value: 'خدمات الشركات' } });
        fireEvent.click(screen.getByTestId('row-menu-save'));
        fireEvent.click(screen.getByRole('button', { name: 'أنزل تسوّق حسب الفئة' }));

        // حفظُ الصفحات في الطريق — والترتيبُ ينتظره، لا يقطعه
        expect(sent).toHaveLength(1);
        expect(order).not.toHaveBeenCalled();

        answer(0);
        expect(order).toHaveBeenCalledTimes(1);
        expect(order.mock.calls[0][1]).toEqual({ store_sections: 'best,cats' });
        act(() => {
            const o = order.mock.calls[0][2] as { onSuccess: () => void; onFinish: () => void };
            o.onSuccess();
            o.onFinish();
        });
        expect(screen.getByText('حُفظ')).toBeInTheDocument();
    });

    it('وما كُتب والحفظُ في الطريق يبقى «غير محفوظ» — ويُرسله الحفظُ التالي', () => {
        const { sent, answer } = capture();
        draw();
        openServices();

        fireEvent.change(screen.getByTestId(`${P}-title-ar`), { target: { value: 'قبل الحفظ' } });
        fireEvent.click(screen.getByTestId('row-menu-save'));
        fireEvent.change(screen.getByTestId(`${P}-intro-ar`), { target: { value: 'كُتب والحفظُ في الطريق' } });
        answer(0);

        // ما أُرسل هو ما حُفظ — والنبذةُ لم تُرسَل فما زالت غيرَ محفوظة
        expect(pagesOf(sent[0].data)[0].intro_ar).toBe('');
        const leave = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(leave);
        expect(leave.defaultPrevented).toBe(true);

        fireEvent.click(screen.getByTestId('row-menu-save'));
        expect(pagesOf(sent[1].data)[0].intro_ar).toBe('كُتب والحفظُ في الطريق');
    });

    it('وحقلٌ يرفضه الخادم: يُقال، وما كُتب باقٍ في الشاشة', () => {
        const { sent, answer } = capture();
        draw();
        openServices();

        fireEvent.change(screen.getByTestId(`${P}-title-ar`), { target: { value: 'خدمات الشركات' } });
        fireEvent.click(screen.getByTestId('row-menu-save'));
        answer(0, (o) => o.onError?.({ store_custom_pages: 'أضف حتى 20 وحدة في صفحة «خدمات الشركات».' }));

        expect(screen.getByText('تعذّر الحفظ')).toBeInTheDocument();
        expect(screen.getByText('أضف حتى 20 وحدة في صفحة «خدمات الشركات».')).toBeInTheDocument();
        expect(screen.getByTestId(`${P}-title-ar`)).toHaveValue('خدمات الشركات');
        expect(sent).toHaveLength(1);
    });

    it('وانقطاعُ الاتّصال أو انتهاءُ الجلسة: يُقال بسببه، وما كُتب باقٍ ويُعاد حفظُه', () => {
        const { sent, answer } = capture();
        draw();
        openServices();

        fireEvent.change(screen.getByTestId(`${P}-title-ar`), { target: { value: 'لم يُحفظ بعد' } });
        fireEvent.click(screen.getByTestId('row-menu-save'));
        let shown: unknown;
        answer(0, (o) => { shown = o.onNetworkError?.(new Error('offline')); });
        expect(shown).toBe(false);
        expect(screen.getByTestId('row-menu-save-status')).toHaveTextContent('تعذّر الاتصال');

        fireEvent.click(screen.getByTestId('row-menu-save'));
        answer(1, (o) => { shown = o.onHttpException?.({ status: 419 }); });
        expect(shown).toBe(false);
        expect(screen.getByTestId('row-menu-save-status')).toHaveTextContent('انتهت جلستك');
        expect(screen.getByTestId(`${P}-title-ar`)).toHaveValue('لم يُحفظ بعد');

        fireEvent.click(screen.getByTestId('row-menu-save'));
        expect(pagesOf(sent[2].data)[0].title_ar).toBe('لم يُحفظ بعد');
    });
});

describe('نسخةُ الجهاز', () => {
    it('ما لم يُحفظ يُسترجع بعد انهيار المتصفّح — ولا يُحفظ وحده', async () => {
        const first = draw();
        openServices();
        fireEvent.change(screen.getByTestId(`${P}-title-ar`), { target: { value: 'كُتب ثمّ انهار المتصفّح' } });
        await act(async () => { await new Promise((r) => setTimeout(r, 800)); });
        first.unmount();

        const { sent } = capture();
        draw();
        const banner = screen.getByTestId('editor-unsaved-backup');
        expect(banner).toHaveTextContent('تعديلاتٌ لم تُحفظ');
        expect(sent).toHaveLength(0);

        fireEvent.click(within(banner).getByTestId('editor-unsaved-restore'));
        openServices();
        expect(screen.getByTestId(`${P}-title-ar`)).toHaveValue('كُتب ثمّ انهار المتصفّح');
        expect(sent).toHaveLength(0);

        fireEvent.click(screen.getByTestId('row-menu-save'));
        expect(pagesOf(sent[0].data)[0].title_ar).toBe('كُتب ثمّ انهار المتصفّح');
    });

    it('و«تجاهلها» تمحوها — ونشاطٌ آخر على الجهاز نفسه لا يراها', async () => {
        const first = draw();
        openServices();
        fireEvent.change(screen.getByTestId(`${P}-title-ar`), { target: { value: 'لنشاطٍ واحد' } });
        await act(async () => { await new Promise((r) => setTimeout(r, 800)); });
        first.unmount();

        // نشاطٌ آخر (businessId 6) على الجهاز نفسه
        for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
        const keys = Object.keys(window.localStorage);
        expect(keys).toEqual(['abaad.editor.unsaved.5.7']);

        const second = draw();
        fireEvent.click(screen.getByTestId('editor-unsaved-discard'));
        expect(screen.queryByTestId('editor-unsaved-backup')).toBeNull();
        second.unmount();
        draw();
        expect(screen.queryByTestId('editor-unsaved-backup')).toBeNull();
    });
});

describe('حدُّ الصفحة', () => {
    it('عشرون وحدةً تُحفظ كلُّها — والحادية والعشرون لا تُضاف ولا يُحذف شيء، والحفظُ متاح', () => {
        const { sent } = capture();
        draw();
        openServices();

        for (let i = 0; i < 20; i++) {
            fireEvent.click(screen.getByTestId(`${P}-add-heading`));
            fireEvent.change(screen.getByTestId(`${P}-block-${i}-text`), { target: { value: `عنوان ${i + 1}` } });
        }

        expect(screen.getByTestId(`${P}-add-heading`)).toBeDisabled();
        expect(screen.getByTestId(`${P}-blocks-limit`)).toHaveTextContent('بلغت هذه الصفحة حدَّها — 20 وحدة');
        expect(screen.getByTestId(`${P}-blocks-limit`)).toHaveTextContent('أنشئ صفحةً أخرى');
        fireEvent.click(screen.getByTestId(`${P}-add-heading`));

        fireEvent.click(screen.getByTestId('row-menu-save'));
        const blocks = pagesOf(sent[0].data)[0].blocks;
        expect(blocks).toHaveLength(20);
        expect(blocks[19]).toEqual({ type: 'heading', text_ar: 'عنوان 20', text_en: '' });
    });
});

describe('قائمةٌ بصفحتين', () => {
    it('صفحةُ الخدمات وصفحةُ خدمات الشركات وعنصراهما — في حفظٍ واحد', () => {
        const { sent } = capture();
        draw(serializeMenu([...parseMenu(''), linkTo('services', 'خدماتنا')]), serializePages([page({ blocks: [{ type: 'heading', text_ar: 'خدماتنا', text_en: '' }] })]));

        fireEvent.click(screen.getByTestId('menu-add'));
        fireEvent.click(screen.getByTestId('menu-type-mixed'));
        fireEvent.change(screen.getByTestId('menu-new-name-ar'), { target: { value: 'خدمات الشركات' } });
        fireEvent.click(screen.getByTestId('menu-new-add'));

        fireEvent.click(screen.getByTestId('row-menu-save'));
        const pages = pagesOf(sent[0].data);
        const menu = parseMenu(String(sent[0].data.store_menu));
        expect(pages.map((p) => p.title_ar)).toEqual(['خدماتنا', 'خدمات الشركات']);
        expect(pages[0].blocks).toEqual([{ type: 'heading', text_ar: 'خدماتنا', text_en: '' }]);
        const links = menu.filter((i): i is Extract<MenuItem, { type: 'link' }> => i.type === 'link');
        // وكلُّ عنصرٍ يقود إلى صفحته — لا صفحةٌ بلا عنصر ولا عنصرٌ بلا صفحة
        expect(Object.fromEntries(links.map((l) => [l.title_ar, l.target.value]))).toEqual({ 'خدماتنا': 'services', 'خدمات الشركات': pages[1].slug });
    });
});
