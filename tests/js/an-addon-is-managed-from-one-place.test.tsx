import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { pageProps } from './setup';
import AddonDialog from '@/Pages/Admin/Products/partials/AddonDialog';
import ProductAddonsPanel from '@/Pages/Admin/Products/partials/ProductAddonsPanel';
import { appliesTo, type AddonOption } from '@/Pages/Admin/Products/partials/addons';

vi.mock('@/Layouts/AdminLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <div>{children}</div> }));
vi.mock('@/Components/Field', async () => {
    const actual = await vi.importActual<Record<string, unknown>>('@/Components/Field');

    return {
        ...actual,
        Select: ({ options, value, onChange, 'aria-label': label }: {
            options: { label: string; value: string | number; disabled?: boolean }[];
            value?: string | number | null;
            onChange?: (e: { target: { value: string; name: string } }) => void;
            'aria-label'?: string;
        }) => (
            <select aria-label={label} value={value ?? ''} onChange={(e) => onChange?.({ target: { value: e.target.value, name: '' } })}>
                <option value="" />
                {options.map((o) => <option key={o.value} value={o.value} disabled={o.disabled}>{o.label}</option>)}
            </select>
        ),
    };
});

const { default: AddonsIndex } = await import('@/Pages/Admin/Addons/Index');

/**
 * نظامُ إضافاتٍ واحد — كما تعرضه الشاشات.
 *
 * ═══ ما يُحرس ═══
 *
 *   - القاعدةُ الواحدة (`appliesTo` ↔ `ProductAddons::passes`): المملوكةُ مع
 *     صاحبها، والمخصّصةُ مع منتجاتها، والعامّةُ مع الجميع إلّا ما تستثنيه
 *     قائمةُ المنتج القديمة.
 *   - «إضافات هذا المنتج»: مجموعتان لا تتداخلان، وكلُّ إضافةٍ مرّةً واحدة،
 *     والربطُ والفكُّ بطلبٍ صغيرٍ لا يحذف الإضافة.
 *   - النافذة: «منتج من بوتيك» لمن يُؤوي بوتيكات وحده، ومعطّلٌ بسببه حيث لا
 *     يُقبل. وقطعُ البوتيك المختار وحده، ومستحقُّه بجانب سعر الزبون — والخلاصةُ
 *     قبل الحفظ، والحالةُ تُرسَل لكلّ إضافة.
 *   - صفحة الإضافات: مصدرٌ ونطاقٌ وقناةٌ وحالةٌ لكلّ واحدة، والإيقافُ بابٌ
 *     مستقلّ لا يُرسل المدى.
 *
 * وما يحرسه الخادم: `AnAddonIsManagedFromOnePlaceForEveryShopTest`.
 */

const addon = (over: Partial<AddonOption> = {}): AddonOption => ({
    value: 1, label: 'تغليف', price: 1.5, active: true, private: false, scope: 'all',
    inventory_product_id: null, product_ids: [], pos_enabled: true, web_enabled: false, source: 'shop', ...over,
});

const stock = [
    { value: 3, label: 'ورق', quantity: 50, boutique_id: null, price: 0.2 },
    { value: 4, label: 'قطعة فضّة', quantity: 5, boutique_id: 9, boutique_name: 'بوتيك لمى', price: 7 },
    { value: 5, label: 'عطر صغير', quantity: 3, boutique_id: 10, boutique_name: 'بوتيك ريم', price: 12 },
];
const boutiques = [{ value: 9, label: 'بوتيك لمى' }, { value: 10, label: 'بوتيك ريم' }];

function stubFetch(body: unknown = { addon: addon() }, ok = true) {
    const fetchMock = vi.fn().mockResolvedValue({ ok, json: async () => body });
    vi.stubGlobal('fetch', fetchMock);

    return fetchMock;
}

afterEach(() => vi.unstubAllGlobals());

describe('appliesTo — ما يُعرض مع هذا المنتج', () => {
    it('بقاعدة البيع نفسِها', () => {
        expect(appliesTo(addon(), 41, null)).toBe(true);
        expect(appliesTo(addon({ value: 2 }), 41, [1])).toBe(false);
        expect(appliesTo(addon({ scope: 'selected', product_ids: [41] }), 41, null)).toBe(true);
        expect(appliesTo(addon({ scope: 'selected', product_ids: [8] }), 41, null)).toBe(false);
        expect(appliesTo(addon({ private: true, scope: 'product', product_id: 8 }), 41, null)).toBe(false);
        // ومنتجٌ لم يُحفظ: العامّةُ وحدها
        expect(appliesTo(addon({ scope: 'selected', product_ids: [41] }), null, null)).toBe(false);
    });
});

describe('إضافات هذا المنتج', () => {
    const list = [
        addon(),
        addon({ value: 2, label: 'بطاقة', scope: 'selected', product_ids: [41], web_enabled: true }),
        addon({ value: 3, label: 'شوكولاتة', scope: 'selected', product_ids: [8] }),
        addon({ value: 4, label: 'فضّة اختياريّة', scope: 'selected', product_ids: [41], source: 'boutique', boutique_name: 'بوتيك لمى', active: false }),
    ];
    const draw = (onChanged = vi.fn()) => {
        render(<ProductAddonsPanel addons={list} productId={41} legacy={null} onOpen={vi.fn()} onChanged={onChanged} />);

        return onChanged;
    };

    it('مجموعتان لا تتداخلان — وما لغيره لا يُعرض', () => {
        draw();
        const general = screen.getByTestId('product-addons-general');
        const specific = screen.getByTestId('product-addons-specific');

        expect(general.textContent).toContain('تغليف');
        expect(general.textContent).not.toContain('بطاقة');
        expect(specific.textContent).toContain('بطاقة');
        expect(specific.textContent).toContain('بوتيك: بوتيك لمى');
        expect(specific.textContent).toContain('موقوفة');
        // وما لغيره لا يُعرض فيهما — ويُعرض في المكتبة ليُربط
        expect(general.textContent + specific.textContent).not.toContain('شوكولاتة');
        expect(within(screen.getByTestId('product-addons-link')).getByRole('option', { name: /شوكولاتة/ })).toBeInTheDocument();
        expect(screen.getAllByTestId('product-addon')).toHaveLength(3);
    });

    it('يربط مخصّصةً من المكتبة ويفكّها بطلبٍ صغير', async () => {
        const fetchMock = stubFetch({ addon: addon({ value: 3, label: 'شوكولاتة', scope: 'selected', product_ids: [8, 41] }) });
        const onChanged = draw();

        fireEvent.change(screen.getByLabelText('ربط إضافة موجودة'), { target: { value: '3' } });
        fireEvent.click(screen.getByRole('button', { name: /^ربط$/ }));
        await waitFor(() => expect(onChanged).toHaveBeenCalled());
        expect(fetchMock.mock.calls[0][0]).toBe('/admin.products.addons.link/41/3');
        expect(fetchMock.mock.calls[0][1].method).toBe('PUT');

        fireEvent.click(screen.getAllByTestId('product-addon-unlink')[0]);
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
        expect(fetchMock.mock.calls[1][0]).toBe('/admin.products.addons.unlink/41/2');
        expect(fetchMock.mock.calls[1][1].method).toBe('DELETE');
    });

    it('ومنتجٌ لم يُحفظ لا يُربط به شيء', () => {
        render(<ProductAddonsPanel addons={list} productId={null} legacy={null} onOpen={vi.fn()} onChanged={vi.fn()} />);
        expect(screen.queryByTestId('product-addons-link')).toBeNull();
        expect(screen.getByText('احفظ المنتج أولًا لتربط به إضافات مخصصة.')).toBeInTheDocument();
    });
});

describe('نافذةُ الإضافة', () => {
    const open = (props: Partial<React.ComponentProps<typeof AddonDialog>> = {}) => {
        const fetchMock = stubFetch();
        render(
            <AddonDialog addon={null} stockItems={stock} products={[{ value: 41, label: 'باقة' }]} onClose={() => {}} onSaved={() => {}}
                {...props} />,
        );

        return { fetchMock, sent: () => JSON.parse(fetchMock.mock.calls[0][1].body as string) };
    };

    it('لا سؤالَ عن المصدر لمن لا يُؤوي بوتيكات', () => {
        open();
        expect(screen.queryByTestId('addon-source')).toBeNull();
    });

    it('ومعطّلٌ بسببه حيث لا يُقبل ربطُ قطعة بوتيك', () => {
        open({ boutiques, boutiqueLinks: { hosts: true, allowed: false, reason: 'يحتاج تفعيل مستحقّات المكوّنات' } });
        expect(screen.getByTestId('addon-source-boutique')).toBeDisabled();
        expect(screen.getByTestId('addon-source-reason').textContent).toContain('تفعيل');
    });

    it('منتجٌ من بوتيك: قطعُ البوتيك المختار وحده، والمستحقُّ بجانب سعر الزبون', async () => {
        const { sent } = open({ boutiques, boutiqueLinks: { hosts: true, allowed: true } });

        fireEvent.click(screen.getByTestId('addon-source-boutique'));
        fireEvent.change(screen.getByLabelText('البوتيك'), { target: { value: '9' } });
        const pieces = within(screen.getByLabelText('القطعة')).getAllByRole('option').map((o) => o.textContent);
        expect(pieces.join(' ')).toContain('قطعة فضّة');
        expect(pieces.join(' ')).not.toContain('عطر صغير');

        fireEvent.change(screen.getByLabelText('القطعة'), { target: { value: '4' } });
        fireEvent.change(screen.getByDisplayValue('1'), { target: { value: '2' } });
        fireEvent.change(screen.getByPlaceholderText('شوكولاتة'), { target: { value: 'فضّة اختياريّة' } });
        fireEvent.change(screen.getByPlaceholderText('0.000'), { target: { value: '9' } });

        expect(screen.getByTestId('addon-boutique-due').textContent).toContain('14.000');
        expect(screen.getByTestId('addon-boutique-due').textContent).toContain('ليس ربحًا صافيًا');
        expect(screen.getByTestId('addon-summary').textContent).toContain('من بوتيك بوتيك لمى: قطعة فضّة × 2');

        fireEvent.click(screen.getByRole('button', { name: 'حفظ' }));
        await waitFor(() => expect(sent()).toMatchObject({ inventory_product_id: 4, inventory_quantity: '2', price: '9', active: true }));
    });

    it('ونصفُ قطعةٍ يُردّ قبل الإرسال', () => {
        const { fetchMock } = open({ boutiques, boutiqueLinks: { hosts: true, allowed: true } });
        fireEvent.click(screen.getByTestId('addon-source-boutique'));
        fireEvent.change(screen.getByLabelText('البوتيك'), { target: { value: '9' } });
        fireEvent.change(screen.getByLabelText('القطعة'), { target: { value: '4' } });
        fireEvent.change(screen.getByDisplayValue('1'), { target: { value: '1.5' } });
        fireEvent.change(screen.getByPlaceholderText('شوكولاتة'), { target: { value: 'فضّة' } });
        fireEvent.click(screen.getByRole('button', { name: 'حفظ' }));

        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('من شاشة منتج: جديدةٌ مخصّصةٌ له — والخلاصةُ والحالةُ تُرسلان', async () => {
        const { sent } = open({ forProduct: 41 });
        fireEvent.change(screen.getByPlaceholderText('شوكولاتة'), { target: { value: 'بطاقة' } });
        fireEvent.click(screen.getByTestId('addon-channel-web'));
        fireEvent.click(screen.getByTestId('addon-active-off'));

        const summary = screen.getByTestId('addon-summary').textContent ?? '';
        expect(summary).toContain('مخصصة — 1 منتج');
        expect(summary).toContain('نقطة البيع والموقع');
        expect(summary).toContain('موقوفة');

        fireEvent.click(screen.getByRole('button', { name: 'حفظ' }));
        await waitFor(() => expect(sent()).toMatchObject({ scope: 'selected', product_ids: [41], web_enabled: true, active: false }));
    });
});

describe('صفحة الإضافات', () => {
    const draw = () => {
        Object.assign(pageProps, {
            addons: [
                addon(),
                addon({ value: 2, label: 'فضّة اختياريّة', scope: 'selected', product_ids: [41, 42], source: 'boutique', boutique_name: 'بوتيك لمى', stock_name: 'قطعة فضّة', inventory_quantity: 2, boutique_due: 14, web_enabled: true }),
                addon({ value: 3, label: 'شريط', private: true, scope: 'product', product_id: 41, owner_name: 'باقة الربيع', active: false }),
            ],
            stockItems: stock, products: [], boutiques, boutiqueLinks: { hosts: true, allowed: true },
        });
        render(<AddonsIndex />);
    };

    it('لكلّ إضافةٍ مصدرُها ونطاقُها وقناتُها وحالتُها', () => {
        draw();
        const cards = screen.getAllByTestId('addon-card');
        expect(cards).toHaveLength(3);
        expect(within(cards[0]).getByTestId('addon-card-scope').textContent).toBe('عامة — مع كل المنتجات');
        expect(within(cards[0]).getByTestId('addon-card-source').textContent).toContain('من المتجر');
        expect(within(cards[1]).getByTestId('addon-card-scope').textContent).toBe('مخصصة — 2 منتج');
        expect(within(cards[1]).getByTestId('addon-card-source').textContent).toContain('من بوتيك بوتيك لمى: قطعة فضّة × 2');
        expect(within(cards[1]).getByTestId('addon-card-source').textContent).toContain('14.000');
        expect(within(cards[2]).getByTestId('addon-card-scope').textContent).toBe('خاصة بـباقة الربيع');
        expect(within(cards[2]).getByText('موقوفة')).toBeInTheDocument();
    });

    it('والإيقافُ بابٌ مستقلّ لا يُرسل المدى', async () => {
        const fetchMock = stubFetch({ addon: addon({ active: false }) });
        draw();
        fireEvent.click(within(screen.getAllByTestId('addon-card')[0]).getByTestId('addon-card-toggle'));

        await waitFor(() => expect(fetchMock).toHaveBeenCalled());
        expect(fetchMock.mock.calls[0][0]).toBe('/admin.addons.active/1');
        expect(JSON.parse(fetchMock.mock.calls[0][1].body as string)).toEqual({ active: false });
        await waitFor(() => expect(within(screen.getAllByTestId('addon-card')[0]).getByText('موقوفة')).toBeInTheDocument());
    });

    it('ومخصّصةٌ بلا منتجاتٍ تُقال: لا تظهر مع أيّ منتج', () => {
        draw();
        expect(screen.queryAllByTestId('addon-card-orphan')).toHaveLength(0);
        cleanup();
        Object.assign(pageProps, { addons: [addon({ scope: 'selected', product_ids: [] })] });
        render(<AddonsIndex />);
        expect(screen.getByTestId('addon-card-orphan').textContent).toContain('لا تظهر مع أي منتج');
    });

    it('والمرشِّحُ «من بوتيك» يعرض قطعَ البوتيك وحدها', () => {
        draw();
        fireEvent.click(screen.getByRole('button', { name: 'من بوتيك' }));
        expect(screen.getAllByTestId('addon-card')).toHaveLength(1);
    });
});

describe('السعرُ بمنازل العملة — «1.500 ر.ع» في الشاشات كلّها', () => {
    afterEach(() => document.documentElement.setAttribute('lang', 'ar'));

    it('في شرائح المنتج وقائمة الربط — كبطاقات صفحة الإضافات', () => {
        render(
            <ProductAddonsPanel
                addons={[addon(), addon({ value: 3, label: 'شوكولاتة', price: 0.75, scope: 'selected', product_ids: [8] })]}
                productId={41} legacy={null} onOpen={vi.fn()} onChanged={vi.fn()}
            />,
        );
        expect(within(screen.getByTestId('product-addons-general')).getByTestId('product-addon').textContent).toContain('1.500 ر.ع');
        expect(within(screen.getByLabelText('ربط إضافة موجودة')).getByRole('option', { name: 'شوكولاتة — 0.750 ر.ع' })).toBeInTheDocument();

        cleanup();
        Object.assign(pageProps, { addons: [addon()], stockItems: [], products: [], boutiques: [], boutiqueLinks: { hosts: false, allowed: false } });
        render(<AddonsIndex />);
        expect(screen.getByTestId('addon-card').textContent).toContain('1.500 ر.ع');
    });

    it('وفي خلاصة النافذة — وبالرمز الدوليّ في الواجهة الإنجليزيّة', () => {
        document.documentElement.setAttribute('lang', 'en');
        render(<AddonDialog addon={null} stockItems={stock} products={[]} onClose={() => {}} onSaved={() => {}} />);
        fireEvent.change(screen.getByPlaceholderText('0.000'), { target: { value: '1.5' } });

        expect(screen.getByTestId('addon-summary').textContent).toContain('1.500 OMR');
    });
});
