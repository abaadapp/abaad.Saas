import { act, fireEvent, render, renderHook, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { router as core } from '@inertiajs/core';

import { pageProps } from './setup';
import { CHANNEL_TAG, salesChannels } from '@/Pages/Admin/Products/partials/channels';
import { usePosCart } from '@/hooks/usePosCart';

vi.mock('@/Layouts/PosLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <div>{children}</div> }));
vi.mock('@/Layouts/AdminLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <div>{children}</div> }));
vi.mock('@/hooks/useLiveFeed', () => ({ default: () => ({ data: null }) }));
vi.mock('@/Components/ExportMenu', () => ({ default: () => <div /> }));
vi.mock('@/hooks/useLiveStock', () => ({ default: (_url: string, p: unknown[]) => ({ products: p, updatedAt: null }) }));

const { default: ProductForm } = await import('@/Pages/Admin/Products/partials/ProductForm');

/**
 * الصنفُ يُباع حيث يختار صاحبُه — كما تعرضه الشاشات.
 *
 * ═══ ما يُحرس ═══
 *
 *   - القاعدةُ الواحدة (`salesChannels`): القناةُ لا تبيع والمنتجُ موقوف.
 *   - نموذجُ المنتج: ثلاثةُ مفاتيح، تُفتح بما حُفظ، وتُرسل كما اختيرت، وسطرٌ
 *     يقول أين يُباع فعلًا — والموقوفُ يُقال له إنّ القناة لا تكفي.
 *   - الصندوق: لا يعرض ما أُغلقت عنه نقطةُ البيع، ولا يُدخله الماسح.
 *
 * وما يحرسه الخادمُ لكلّ تركيبة: `AProductSellsOnlyWhereItsOwnerChoosesTest`.
 */

describe('salesChannels — أين يُباع فعلًا', () => {
    it('يقرأ المفاتيحَ الثلاثة كما يقرؤها الخادم', () => {
        expect(salesChannels({ active: true, pos_enabled: true, published: true })).toBe('both');
        expect(salesChannels({ active: true, pos_enabled: true, published: false })).toBe('pos');
        expect(salesChannels({ active: true, pos_enabled: false, published: true })).toBe('online');
        expect(salesChannels({ active: true, pos_enabled: false, published: false })).toBe('none');
        // والموقوفُ لا يُباع في قناةٍ ولو فُتحت كلتاهما
        expect(salesChannels({ active: false, pos_enabled: true, published: true })).toBe('none');
    });

    it('والغائبُ من المفتاحين «نعم» — كما كان كلُّ صنفٍ قبل العمود', () => {
        expect(salesChannels({ active: true })).toBe('both');
        expect(CHANNEL_TAG.online).toBe('المتجر الإلكتروني فقط');
    });
});

/* ═════════════ نموذجُ المنتج ═════════════ */

const PRODUCT = {
    id: 9, name: 'باقة', name_en: null, label: 'باقة', cat: '—', price: 20, cost: 8, qty: 4,
    sku: 'X-1', barcode: '1', image: null, stock_status: 'متوفر', active: true, alert: 1, tax: 0, discount: 0,
};

let sent: Record<string, unknown>[] = [];

describe('مفاتيحُ القنوات في نموذج المنتج', () => {
    beforeEach(() => {
        sent = [];
        vi.spyOn(core, 'post').mockImplementation(((_url: string, data: Record<string, unknown>) => {
            sent.push(data);
        }) as never);
        Object.assign(pageProps, { context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 } } });
    });

    afterEach(() => vi.restoreAllMocks());

    const draw = (product: Record<string, unknown> | null = PRODUCT) =>
        render(<ProductForm categories={[]} currencyLabel="ر.ع" product={product as never} />);
    const toggle = (name: string) => screen.getByRole('switch', { name });
    const summary = () => screen.getByTestId('product-channels-summary').textContent;
    const save = async () => {
        await act(async () => {
            fireEvent.submit(document.querySelector('form')!);
        });
    };

    it('يرسم المفاتيحَ الثلاثة — وصنفٌ جديدٌ يُباع في القناتين', async () => {
        draw(null);

        expect(toggle('المنتج نشط').getAttribute('aria-checked')).toBe('true');
        expect(toggle('متاح للبيع في نقطة البيع (POS)').getAttribute('aria-checked')).toBe('true');
        expect(toggle('متاح للبيع في المتجر الإلكتروني').getAttribute('aria-checked')).toBe('true');
        expect(summary()).toBe('يُباع في نقطة البيع والمتجر الإلكتروني.');
    });

    it('يُفتح بما حُفظ: صنفُ الموقع وحده', () => {
        draw({ ...PRODUCT, pos_enabled: false, published: true });

        expect(toggle('متاح للبيع في نقطة البيع (POS)').getAttribute('aria-checked')).toBe('false');
        expect(summary()).toBe('يُباع في المتجر الإلكتروني فقط — مخفي من نقطة البيع.');
    });

    it('يُرسل ما اختير — نقطةُ البيع وحدها', async () => {
        draw();

        fireEvent.click(toggle('متاح للبيع في المتجر الإلكتروني'));
        expect(summary()).toBe('يُباع في نقطة البيع فقط — مخفي من المتجر الإلكتروني.');
        await save();

        expect(sent).toHaveLength(1);
        expect(sent[0]).toMatchObject({ active: true, pos_enabled: true, published: false });
    });

    it('والموقوفُ يُقال له إنّ القناةَ لا تكفي — واختيارُها يبقى محفوظًا', async () => {
        draw({ ...PRODUCT, active: false, pos_enabled: false, published: true });

        expect(summary()).toBe('لا يُباع في أي قناة الآن.');
        expect(screen.getByText('موقوف بالكامل — لا يُباع في أي قناة حتى تعيد تنشيطه')).toBeInTheDocument();
        expect(screen.getAllByText('لن يُباع ما دام المنتج موقوفًا')).toHaveLength(2);

        fireEvent.click(toggle('المنتج نشط'));
        expect(summary()).toBe('يُباع في المتجر الإلكتروني فقط — مخفي من نقطة البيع.');
        await save();
        expect(sent[0]).toMatchObject({ active: true, pos_enabled: false, published: true });
    });
});

/* ═════════════ الصندوق ═════════════ */

const product = (id: number, name: string, over: Record<string, unknown> = {}) => ({
    id, name, name_en: null, label: name, cat: 'باقات', price: 5, cost: 1, qty: 10,
    sku: `S-${id}`, barcode: `B-${id}`, image: null, stock_status: 'متوفر', tracks_stock: true,
    active: true, published: true, pos_enabled: true, alert: 0, tax: 0, ...over,
});

describe('الصندوقُ يعرض ما يُباع فيه وحده', () => {
    beforeEach(() => {
        for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
        Object.assign(pageProps, {
            translations: {},
            products: [
                product(1, 'باقة الصندوق والموقع'),
                product(2, 'باقة الموقع وحده', { pos_enabled: false }),
                product(3, 'باقة موقوفة', { active: false }),
                product(4, 'باقة قديمة بلا مفتاح', { pos_enabled: undefined }),
            ],
            categories: [{ value: 'الكل', label: 'الكل' }, { value: 'باقات', label: 'باقات' }],
            seasons: [],
            customers: [],
            addons: [],
            addonsLayout: 'strip',
            coupons: [],
            resumeCart: null,
            settings: {},
            orderOptions: undefined,
            customOrder: { templates: [] },
            context: { currency: { code: 'OMR', name: 'ريال', symbol: 'ر.ع', rate: 1, is_base: true, active: true, decimals: 3 } },
        });
        localStorage.clear();
    });

    it('لا يعرض صنفَ الموقع وحده ولا الموقوف — ويعرض القديمَ الذي لا مفتاحَ له', async () => {
        const { default: PosIndex } = await import('@/Pages/Pos/Index');
        render(<PosIndex />);

        expect(screen.getByText('باقة الصندوق والموقع')).toBeInTheDocument();
        expect(screen.getByText('باقة قديمة بلا مفتاح')).toBeInTheDocument();
        expect(screen.queryByText('باقة الموقع وحده')).toBeNull();
        expect(screen.queryByText('باقة موقوفة')).toBeNull();
    });

    it('والماسحُ لا يُدخل السلّةَ صنفًا لا يُباع هنا — من الشاشة نفسِها', async () => {
        const { default: PosIndex } = await import('@/Pages/Pos/Index');
        render(<PosIndex />);
        const scanner = screen.getByPlaceholderText('امسح الباركود');

        const scan = (code: string) => {
            fireEvent.change(scanner, { target: { value: code } });
            fireEvent.keyDown(scanner, { key: 'Enter' });
        };

        scan('B-2');
        scan('B-3');
        // لم يدخلا السلّة: لا يُرسم اسمُهما في أيّ موضع من الشاشة
        expect(screen.queryByText('باقة الموقع وحده')).toBeNull();
        expect(screen.queryByText('باقة موقوفة')).toBeNull();

        // والمتاحُ يدخل — فيُرسم في السلّة فوق بطاقته
        scan('B-1');
        expect(screen.getAllByText('باقة الصندوق والموقع').length).toBeGreaterThan(1);
    });
});

describe('usePosCart.scanBarcode', () => {
    const LOYALTY = { loyaltyEnabled: false } as never;

    it('يردّ باركودَ صنفٍ أُغلقت عنه نقطةُ البيع أو أُوقف، ويُدخل ما يُباع', () => {
        const toast = vi.fn();
        const items = [
            { id: 1, label: 'يُباع', price: 5, image: null, sku: 'S-1', barcode: 'B-1', stock: 10 },
            { id: 2, label: 'للموقع', price: 5, image: null, sku: 'S-2', barcode: 'B-2', stock: 10, pos_enabled: false },
            { id: 3, label: 'موقوف', price: 5, image: null, sku: 'S-3', barcode: 'B-3', stock: 10, active: false },
        ];
        const { result } = renderHook(() =>
            usePosCart({
                products: items, customers: [], coupons: [], loyalty: LOYALTY, resume: null, onToast: toast,
                currency: { code: 'OMR', name: 'ريال', symbol: 'ر.ع', rate: 1, is_base: true, active: true, decimals: 3 },
            } as never),
        );

        act(() => result.current.scanBarcode('B-2'));
        act(() => result.current.scanBarcode('B-3'));
        expect(result.current.items).toHaveLength(0);
        expect(toast).toHaveBeenCalledTimes(2);

        act(() => result.current.scanBarcode('B-1'));
        expect(result.current.items.map((i: { id: number | null }) => i.id)).toEqual([1]);
    });
});

describe('قائمةُ المنتجات تقول أين يُباع الصنف', () => {
    it('وسمٌ تحت حالة الصنف النشط ذي القناة الواحدة — ولا وسمَ للقناتين ولا للموقوف', async () => {
        const row = (id: number, name: string, over: Record<string, unknown>) => ({
            id, name, label: name, cat: '—', price: 5, cost: 1, qty: 4, sku: `S${id}`, barcode: `${id}`, image: null,
            stock_status: 'متوفر', active: true, pos_enabled: true, published: true, alert: 1, tax: 0, discount: 0, ...over,
        });
        for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
        Object.assign(pageProps, {
            translations: {},
            auth: { abilities: ['products'], mayActions: [] },
            context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 } },
            products: [
                row(1, 'القناتان', {}),
                row(2, 'الموقع وحده', { pos_enabled: false }),
                row(3, 'الصندوق وحده', { published: false }),
                row(4, 'بلا قناة', { pos_enabled: false, published: false }),
                row(5, 'موقوف', { active: false, pos_enabled: false }),
            ],
            pagination: { current_page: 1, last_page: 1, per_page: 12, total: 5, from: 1, to: 5 },
            categories: [], filters: {}, sorts: [], branches: [], currentBranchId: null, lastImport: null, boutiques: [],
        });
        const { default: ProductsIndex } = await import('@/Pages/Admin/Products/Index');
        render(<ProductsIndex />);

        expect(screen.queryByTestId('product-channel-1')).toBeNull();
        expect(screen.getByTestId('product-channel-2').textContent).toBe('المتجر الإلكتروني فقط');
        expect(screen.getByTestId('product-channel-3').textContent).toBe('نقطة البيع فقط');
        expect(screen.getByTestId('product-channel-4').textContent).toBe('بلا قناة بيع');
        expect(screen.queryByTestId('product-channel-5')).toBeNull();
    });
});
