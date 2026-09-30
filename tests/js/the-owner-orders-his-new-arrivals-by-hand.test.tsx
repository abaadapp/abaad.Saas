import { fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { router as core } from '@inertiajs/core';

import { pageProps } from './setup';

vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

import type { EditorRow } from '@/Pages/Admin/Website/ThemeEditor';
import { type CatalogTools, NEW_ARRIVALS_MAX } from '@/Pages/Admin/Website/theme/CatalogTools';

const { default: ThemeEditor } = await import('@/Pages/Admin/Website/ThemeEditor');

/**
 * «وصل حديثًا» يدويًّا في محرّر الواجهة — لمن وصلته الطريقةُ في الحمولة.
 *
 * ═══ وما يُحرَس ═══
 *
 * أنّ من لم تصله يرى اللوحةَ عرضًا كما كانت، وأنّ من وصلته يختار تلقائيًّا
 * أو يدويًّا، ويضيف ويُزيل ويرتّب بسهمين حتّى أربعة، ويحفظ المفتاحين وحدهما
 * بالباب القائم (`admin.marketing.store.save`) بالترتيب الذي يراه.
 *
 * وقواعدُ الحفظ نفسُها (المتجرُ والمعروضُ والحدّ) تُحرس في الخادم:
 * `AListedShopChoosesItsNewArrivalsByHandTest`. فالشاشةُ لا تحرس بابًا.
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

const PAGES = [
    { key: 'home', path: '/', fixed: true, on: true, blocked: null },
    { key: 'shop', path: '/shop', fixed: true, on: true, blocked: null },
];

const HERO = row({ key: 'hero', label: 'الواجهة', fixed: true, fields: [{ key: 'store_headline', kind: 'text', label: 'العنوان الكبير' }] });
const NEW = row({ key: 'new', label: 'وصل حديثًا', source: { label: 'الأصناف', route: 'admin.products.index' } });
const FOOT = row({ key: 'foot', label: 'التذييل', fixed: true });

/** المعروضُ من أصناف متجره — ما يجوز اختيارُه */
const PRODUCTS = [
    { id: 36, name: 'بالون', image: null },
    { id: 37, name: 'كرت', image: null },
    { id: 38, name: 'ورد أحمر', image: '/storage/p/38.jpg' },
    { id: 39, name: 'علبة شوكولاتة', image: null },
    { id: 40, name: 'باقة الربيع', image: '/storage/p/40.jpg' },
];

/** ما يعرضه القسمُ الآن — أحدثُ أربعة */
const SHOWN = [40, 39, 38, 37].map((id) => ({ ...PRODUCTS.find((p) => p.id === id)!, category: null }));

const tools = (over: Partial<CatalogTools> = {}): CatalogTools => ({
    categories: [],
    new_arrivals: SHOWN,
    new_arrivals_mode: 'auto',
    new_arrival_ids: [],
    ...over,
});

const draw = (catalogTools: CatalogTools) => {
    for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    Object.assign(pageProps, { translations: {} });

    render(
        <ThemeEditor
            theme="ribbon"
            site={{ name: 'RIBBON', published: true, url: 'https://ribbon.abaadapp.om', slug: 'ribbon', host: 'abaadapp.om' }}
            publishing={null}
            pages={PAGES}
            rows={[HERO, NEW, FOOT]}
            values={{ store_headline: '' }}
            order={['new']}
            maxFeatured={4}
            products={PRODUCTS}
            catalogTools={catalogTools}
        />,
    );

    fireEvent.click(screen.getByText('وصل حديثًا'));

    return screen.getByTestId('catalog-new-arrivals');
};

/** ما في القائمة المختارة بترتيبه */
const chosen = () =>
    within(screen.getByTestId('catalog-arrivals-chosen'))
        .getAllByRole('listitem')
        .map((li) => Number(li.dataset.testid!.replace('catalog-chosen-', '')));

const press = (label: string) => fireEvent.click(screen.getByRole('button', { name: label }));

/** يلتقط الحفظ — ويُجيب بما يُعطى */
const capture = (answer?: (o: { onSuccess?: () => void; onError?: (e: Record<string, string>) => void; onFinish?: () => void }) => void) => {
    const sent: { url: string; data: Record<string, unknown> }[] = [];
    vi.spyOn(core, 'post').mockImplementation(((url: string, data: Record<string, unknown>, options: Record<string, unknown>) => {
        sent.push({ url, data });
        answer?.(options as never);
        (options as { onFinish?: () => void }).onFinish?.();
    }) as never);

    return sent;
};

afterEach(() => vi.restoreAllMocks());

describe('من لم تصله الطريقة', () => {
    it('يرى اللوحةَ عرضًا كما كانت — لا طريقةَ ولا سهمَ ولا حفظ', () => {
        const panel = draw({ categories: [], new_arrivals: SHOWN });

        expect(within(panel).queryByTestId('catalog-arrivals-mode')).toBeNull();
        expect(within(panel).queryByTestId('catalog-arrivals-save')).toBeNull();
        expect(within(panel).getByTestId('catalog-arrival-40')).toBeInTheDocument();
        expect(within(panel).getByText('يظهر هنا تلقائيًا آخر 4 منتجات مفعّلة ومنشورة أضفتها إلى المتجر.')).toBeInTheDocument();
    });
});

describe('تلقائيٌّ أم يدويّ', () => {
    it('التلقائيّ يعرض ما يُعرض الآن، ومفتاحاه ظاهران', () => {
        const panel = draw(tools());

        expect(within(panel).getByText('طريقة العرض')).toBeInTheDocument();
        expect(screen.getByRole('radio', { name: 'تلقائي — آخر 4 منتجات أضفتها' })).toBeChecked();
        expect(screen.getByRole('radio', { name: 'اختيار يدوي' })).not.toBeChecked();
        expect(within(panel).getByTestId('catalog-arrival-40')).toBeInTheDocument();
        expect(within(panel).queryByTestId('catalog-arrivals-chosen')).toBeNull();
    });

    it('ومن انتقل إلى اليدويّ بلا اختيارٍ تبدأ قائمتُه بما يُعرض الآن', () => {
        draw(tools());
        fireEvent.click(screen.getByRole('radio', { name: 'اختيار يدوي' }));

        expect(screen.getByText('اختر حتى 4 منتجات ورتّبها كما تريد أن تظهر في «وصل حديثًا».')).toBeInTheDocument();
        expect(chosen()).toEqual([40, 39, 38, 37]);
        // مرقّمةٌ ١–٤
        expect(within(screen.getByTestId('catalog-chosen-37')).getByText('4')).toBeInTheDocument();
    });

    it('واليدويُّ المحفوظ يُفتح بترتيبه — وما أُخفي بعد اختياره لا يظهر ولا يُرسَل', () => {
        const sent = capture();
        draw(tools({ new_arrivals_mode: 'manual', new_arrival_ids: [38, 99, 40] }));

        expect(screen.getByRole('radio', { name: 'اختيار يدوي' })).toBeChecked();
        expect(chosen()).toEqual([38, 40]);

        fireEvent.click(screen.getByTestId('catalog-arrivals-save'));
        expect(sent[0].data).toEqual({ store_new_arrivals_mode: 'manual', store_new_arrivals: '38,40' });
    });
});

describe('الاختيارُ والترتيب', () => {
    it('يُضاف صنفٌ في آخر القائمة، ويُزال', () => {
        draw(tools({ new_arrivals_mode: 'manual', new_arrival_ids: [38] }));

        press('إضافة إلى وصل حديثًا بالون');
        expect(chosen()).toEqual([38, 36]);

        press('إزالة من وصل حديثًا ورد أحمر');
        expect(chosen()).toEqual([36]);
        // وعاد إلى ما يُضاف
        expect(within(screen.getByTestId('catalog-arrivals-rest')).getByTestId('catalog-rest-38')).toBeInTheDocument();
    });

    it('ولا يُتجاوز أربعة: أزرارُ الإضافة تُطفأ ويُقال لمَ', () => {
        draw(tools({ new_arrivals_mode: 'manual', new_arrival_ids: [40, 39, 38] }));

        press('إضافة إلى وصل حديثًا كرت');
        expect(chosen()).toHaveLength(NEW_ARRIVALS_MAX);
        expect(screen.getByRole('button', { name: 'إضافة إلى وصل حديثًا بالون' })).toBeDisabled();
        expect(screen.getByText('اخترت 4 منتجات — أزل واحدًا لتضيف غيره.')).toBeInTheDocument();

        press('إضافة إلى وصل حديثًا بالون');
        expect(chosen()).toEqual([40, 39, 38, 37]);
    });

    it('والسهمان يبدّلان الترتيب — والأوّلُ لا يصعد والأخيرُ لا ينزل', () => {
        draw(tools({ new_arrivals_mode: 'manual', new_arrival_ids: [40, 39, 38] }));

        expect(screen.getByRole('button', { name: 'تحريك لأعلى باقة الربيع' })).toBeDisabled();
        expect(screen.getByRole('button', { name: 'تحريك لأسفل ورد أحمر' })).toBeDisabled();

        press('تحريك لأعلى ورد أحمر');
        expect(chosen()).toEqual([40, 38, 39]);

        press('تحريك لأسفل باقة الربيع');
        expect(chosen()).toEqual([38, 40, 39]);
    });

    it('وقائمةٌ فارغةٌ في اليدويّ يُقال فيها ما يلزم، ولا تُحفظ', () => {
        draw(tools({ new_arrivals_mode: 'manual', new_arrival_ids: [38] }));

        press('إزالة من وصل حديثًا ورد أحمر');
        expect(screen.getByTestId('catalog-arrivals-none')).toHaveTextContent('اختر منتجًا واحدًا على الأقل أو ارجع إلى الترتيب التلقائي.');
        expect(screen.getByTestId('catalog-arrivals-save')).toBeDisabled();
    });
});

describe('الحفظ', () => {
    it('يرسل المفتاحين وحدهما بالباب القائم وبالترتيب المرئيّ، ويقول «حُفظ»', () => {
        const sent = capture((o) => o.onSuccess?.());
        draw(tools({ new_arrivals_mode: 'manual', new_arrival_ids: [40, 39] }));

        press('تحريك لأسفل باقة الربيع');
        press('إضافة إلى وصل حديثًا بالون');
        fireEvent.click(screen.getByTestId('catalog-arrivals-save'));

        expect(sent).toHaveLength(1);
        expect(sent[0].url).toBe('/admin.marketing.store.save');
        expect(sent[0].data).toEqual({ store_new_arrivals_mode: 'manual', store_new_arrivals: '39,40,36' });
        expect(screen.getByText('حُفظ')).toBeInTheDocument();
        // واللوحةُ باقيةٌ مفتوحة
        expect(screen.getByTestId('catalog-new-arrivals')).toBeInTheDocument();
    });

    it('والعودةُ إلى التلقائيّ تُحفظ ولا تمحو ما اختاره', () => {
        const sent = capture();
        draw(tools({ new_arrivals_mode: 'manual', new_arrival_ids: [38, 40] }));

        fireEvent.click(screen.getByRole('radio', { name: 'تلقائي — آخر 4 منتجات أضفتها' }));
        fireEvent.click(screen.getByTestId('catalog-arrivals-save'));

        expect(sent[0].data).toEqual({ store_new_arrivals_mode: 'auto', store_new_arrivals: '38,40' });
    });

    it('وما يردّه الخادمُ يُقال في اللوحة', () => {
        capture((o) => o.onError?.({ store_new_arrivals: 'منتجٌ في «وصل حديثًا» غير متاح' }));
        draw(tools({ new_arrivals_mode: 'manual', new_arrival_ids: [38] }));

        fireEvent.click(screen.getByTestId('catalog-arrivals-save'));

        expect(screen.getByTestId('catalog-arrivals-error')).toHaveTextContent('منتجٌ في «وصل حديثًا» غير متاح');
        expect(screen.getByText('تعذّر الحفظ')).toBeInTheDocument();
    });
});
