import { fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { pageProps } from './setup';

vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

const posted: { url: string; body: Record<string, unknown> }[] = [];

vi.mock('@inertiajs/react', async () => {
    const actual = await vi.importActual<Record<string, unknown>>('@inertiajs/react');

    return {
        ...actual,
        usePage: () => ({ props: pageProps, url: '/', component: 'Test' }),
        router: {
            get: vi.fn(),
            post: (url: string, body: Record<string, unknown>) => posted.push({ url, body }),
            visit: vi.fn(), patch: vi.fn(), delete: vi.fn(), put: vi.fn(), on: vi.fn(),
        },
        Head: () => null,
    };
});

const { default: BoutiqueShow } = await import('@/Pages/Admin/Boutiques/Show');

/**
 * شاشةُ البوتيك تُدار منها بضاعتُه — لا من بطاقة كلّ صنف.
 *
 * ═══ وما يُحرَس هنا ═══
 *
 * أنّ القسم يعرض ما يحمله البوتيك اليوم (لا ما بِيع منه في شهر)، وأنّ
 * النزعَ يُرسل `detach` على صنفٍ واحد — فهو نزعٌ لا حذف.
 *
 * وأنّ نافذةَ الاختيار لا تسمح بنقلِ صنفٍ عند بوتيكٍ آخر: تقول أين هو
 * وتمنع اختياره. فلو تُرك لصار كشفُ بوتيكٍ ينقص صنفًا ولا أحد سأله.
 * (والخادمُ يردّه أيضًا — انظر `ABoutiquePicksItsGoodsFromItsOwnScreenTest`.)
 */

const OURS = 3;

const attached = (over: Record<string, unknown> = {}) => ({
    id: 11, name: 'عطر لمى', sku: 'SKU-1', active: true, quantity: 7, stock: 'متوفر', ...over,
});

const pick = (over: Record<string, unknown> = {}) => ({
    id: 21, name: 'شمعة', sku: 'SKU-2', boutique_id: null, boutique_name: null, ...over,
});

const draw = (over: Record<string, unknown> = {}) => {
    Object.assign(pageProps, {
        translations: {},
        context: { currency: { code: 'OMR', name: 'ريال', symbol: 'ر.ع', rate: 1, is_base: true, active: true } },
        errors: {},
        boutique: {
            id: OURS, name: 'بوتيك لمى', name_en: null, phone: null,
            contact_person: null, rate: 20, active: true, notes: null,
        },
        statement: {
            partial: false, period: '2027-02', from: '2027-02-01', to: '2027-02-28',
            lines: [], quantity: 0, gross: 0, commission: 0, net: 0, lines_count: 0,
        },
        period: '2027-02',
        periods: [{ value: '2027-02', label: 'فبراير 2027' }],
        settlement: null,
        history: [],
        products: [attached()],
        catalog: { rows: [pick()], more: false },
        q: '',
        ...over,
    });

    return render(<BoutiqueShow />);
};

const openPicker = () => fireEvent.click(screen.getByRole('button', { name: /إضافة منتجات/ }));

describe('قسمُ منتجات البوتيك', () => {
    beforeEach(() => {
        posted.length = 0;
        for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    });

    it('يعرض ما يحمله البوتيك اليوم برمزه ومخزونه وحالته', () => {
        draw();

        expect(screen.getByText('منتجات البوتيك')).toBeInTheDocument();
        expect(screen.getByText('عطر لمى')).toBeInTheDocument();
        expect(screen.getByText('SKU-1')).toBeInTheDocument();
        expect(screen.getByText('متوفر')).toBeInTheDocument();
        expect(screen.getByText('مفعّل')).toBeInTheDocument();
    });

    it('ويقول حين لا أصناف', () => {
        draw({ products: [] });

        expect(screen.getByText('لا أصناف في هذا البوتيك بعد')).toBeInTheDocument();
    });

    /* الصنفُ الذي لا يمسك جردًا لا رقمَ له: صفرٌ يُقرأ «نفد» وهو لم ينفد */
    it('ولا يخترع مخزونًا لصنفٍ لا يمسك جردًا', () => {
        draw({ products: [attached({ quantity: null, stock: null })] });

        expect(screen.getByText('—')).toBeInTheDocument();
    });

    it('والنزعُ يُرسل صنفًا واحدًا بـdetach — لا حذفًا', () => {
        draw();

        fireEvent.click(screen.getByRole('button', { name: 'إزالة من البوتيك' }));

        expect(posted).toHaveLength(1);
        expect(posted[0].body).toEqual({ product_ids: [11], detach: true });
    });
});

describe('ونافذةُ إضافة المنتجات', () => {
    beforeEach(() => {
        posted.length = 0;
        for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    });

    it('تُختار منها الأصناف الحرّة وتُرسل معًا', () => {
        draw({ catalog: { rows: [pick(), pick({ id: 22, name: 'بخور', sku: null })], more: false } });
        openPicker();

        fireEvent.click(screen.getByRole('checkbox', { name: 'شمعة' }));
        fireEvent.click(screen.getByRole('checkbox', { name: 'بخور' }));
        fireEvent.click(screen.getByRole('button', { name: /إضافة ٢|إضافة 2/ }));

        expect(posted).toHaveLength(1);
        expect(posted[0].body).toEqual({ product_ids: [21, 22] });
    });

    /*
     * وهذا موضعُ الخطأ المحتمل: صندوقٌ مفتوحٌ على صنفٍ عند بوتيكٍ آخر
     * ينقله بضغطةٍ لا تقول ذلك — فينقص كشفُ صاحبه صنفًا كان يبيعه.
     */
    it('ولا تُتيح نقلَ صنفٍ عند بوتيكٍ آخر — وتقول أين هو', () => {
        draw({ catalog: { rows: [pick({ boutique_id: 9, boutique_name: 'بوتيك ريم' })], more: false } });
        openPicker();

        expect(screen.getByText('مرتبط بـ بوتيك ريم')).toBeInTheDocument();
        expect(screen.getByRole('checkbox', { name: 'شمعة' })).toBeDisabled();
    });

    it('وصنفُ هذا البوتيك يُقال إنّه فيه ولا يُعاد اختياره', () => {
        draw({ catalog: { rows: [pick({ boutique_id: OURS, boutique_name: 'بوتيك لمى' })], more: false } });
        openPicker();

        expect(screen.getByText('في هذا البوتيك')).toBeInTheDocument();
        expect(screen.getByRole('checkbox', { name: 'شمعة' })).toBeDisabled();
    });

    it('ولا يُحفظ شيءٌ قبل اختيار صنف', () => {
        draw();
        openPicker();

        expect(screen.getByRole('button', { name: 'إضافة' })).toBeDisabled();
        expect(posted).toHaveLength(0);
    });

    it('وتقول حين قُطعت القائمة عند حدّها', () => {
        draw({ catalog: { rows: [pick()], more: true } });
        openPicker();

        expect(screen.getByText(/اكتب للبحث عن غيرها/)).toBeInTheDocument();
    });

    it('ولا نتائج تُقال كما هي', () => {
        draw({ catalog: { rows: [], more: false } });
        openPicker();

        const dialog = screen.getByRole('dialog');
        expect(within(dialog).getByText('لا نتائج')).toBeInTheDocument();
    });
});
