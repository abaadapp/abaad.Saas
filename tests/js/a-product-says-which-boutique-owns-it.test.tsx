import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { router as core } from '@inertiajs/core';

import { pageProps } from './setup';
import { boutiqueMove } from '@/Pages/Admin/Products/partials/boutique';

/*
 * قائمةُ Radix لا تُقاد في jsdom — فتُبدَّل بـ`<select>` أصليّة تحمل العقدَ
 * نفسَه (`value` و`onChange` و`options` و`placeholder`). والحقلُ نفسُه وما
 * حوله يبقيان كما هما.
 */
vi.mock('@/Components/Field', async () => {
    const actual = await vi.importActual<Record<string, unknown>>('@/Components/Field');

    return {
        ...actual,
        Select: ({
            options,
            value,
            onChange,
            placeholder,
            'aria-label': label,
        }: {
            options: { label: string; value: string | number }[];
            value?: string | number | null;
            onChange?: (e: { target: { value: string; name: string } }) => void;
            placeholder?: string;
            'aria-label'?: string;
        }) => (
            <select
                aria-label={label}
                value={value == null ? '' : String(value)}
                onChange={(e) => onChange?.({ target: { value: e.target.value, name: '' } })}
            >
                {placeholder !== undefined && <option value="">{placeholder}</option>}
                {options.map((o) => (
                    <option key={o.value} value={String(o.value)}>
                        {o.label}
                    </option>
                ))}
            </select>
        ),
    };
});

const { default: ProductForm } = await import('@/Pages/Admin/Products/partials/ProductForm');

const BOUTIQUES = [
    { value: 1, label: 'بوتيك لمى', active: true },
    { value: 2, label: 'بوتيك نور', active: true },
];

const PRODUCT = {
    id: 9, name: 'عطر', name_en: null, label: 'عطر', cat: '—', price: 50, cost: 30, qty: 4,
    sku: 'X-1', barcode: '1', image: null, stock_status: 'متوفر', active: true, alert: 1, tax: 0, discount: 0,
};

/** ما وصل إلى الخادم — يُلتقط من موجّه Inertia نفسه قبل أن يغادر */
let sent: Record<string, unknown>[] = [];

beforeEach(() => {
    sent = [];
    vi.spyOn(core, 'post').mockImplementation(((_url: string, data: Record<string, unknown>) => {
        sent.push(data);
    }) as never);
    Object.assign(pageProps, { context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 } } });
});

afterEach(() => vi.restoreAllMocks());

const draw = (props: Record<string, unknown> = {}) =>
    render(<ProductForm categories={[]} currencyLabel="ر.ع" product={PRODUCT as never} {...props} />);

const save = async () => {
    await act(async () => {
        fireEvent.submit(document.querySelector('form')!);
    });
};

describe('حقلُ «البوتيك» في شاشة المنتج', () => {
    it('لا يُرسم لمن لا بوتيكَ عنده — ولا يُرسل منه شيء', async () => {
        draw();

        expect(screen.queryByRole('combobox', { name: 'البوتيك' })).toBeNull();

        await save();

        expect(sent).toHaveLength(1);
        // والفراغُ يصل الخادمَ null فيفكّ الربط — فلا يُرسَل أصلًا
        expect(sent[0]).not.toHaveProperty('boutique_id');
    });

    it('يعرض بوتيكات المتجر و«بدون بوتيك» — والحاليَّ محدَّدًا', () => {
        draw({ boutiques: BOUTIQUES, boutiqueId: 1 });

        const field = screen.getByRole('combobox', { name: 'البوتيك' }) as HTMLSelectElement;
        const labels = Array.from(field.options).map((o) => o.textContent);

        expect(labels).toEqual(['بدون بوتيك', 'بوتيك لمى', 'بوتيك نور']);
        expect(field.value).toBe('1');
    });

    it('النقلُ من بوتيكٍ إلى آخر يُسأل عنه — والإلغاءُ لا يحفظ', async () => {
        draw({ boutiques: BOUTIQUES, boutiqueId: 1 });

        fireEvent.change(screen.getByRole('combobox', { name: 'البوتيك' }), { target: { value: '2' } });
        await save();

        expect(await screen.findByText('هذا المنتج مرتبط حاليًا بـ بوتيك لمى. تغييره سينقله إلى بوتيك نور.')).toBeInTheDocument();
        expect(sent).toHaveLength(0);

        fireEvent.click(screen.getByRole('button', { name: 'إلغاء' }));
        await waitFor(() => expect(sent).toHaveLength(0));
    });

    it('والموافقةُ ترسل البوتيكَ الجديد', async () => {
        draw({ boutiques: BOUTIQUES, boutiqueId: 1 });

        fireEvent.change(screen.getByRole('combobox', { name: 'البوتيك' }), { target: { value: '2' } });
        await save();
        fireEvent.click(await screen.findByRole('button', { name: 'انقله' }));

        await waitFor(() => expect(sent).toHaveLength(1));
        expect(sent[0].boutique_id).toBe('2');
    });

    it('فكُّ الربط لا يُسأل عنه — ويُرسل فراغًا', async () => {
        draw({ boutiques: BOUTIQUES, boutiqueId: 1 });

        fireEvent.change(screen.getByRole('combobox', { name: 'البوتيك' }), { target: { value: '' } });
        await save();

        expect(screen.queryByText(/مرتبط حاليًا/)).toBeNull();
        expect(sent).toHaveLength(1);
        expect(sent[0].boutique_id).toBe('');
    });
});

describe('boutiqueMove — متى يُسأل', () => {
    it('للنقل وحده', () => {
        expect(boutiqueMove('1', '2', BOUTIQUES)).toEqual({ from: 'بوتيك لمى', to: 'بوتيك نور' });
        expect(boutiqueMove('', '2', BOUTIQUES)).toBeNull();
        expect(boutiqueMove('1', '', BOUTIQUES)).toBeNull();
        expect(boutiqueMove('1', '1', BOUTIQUES)).toBeNull();
    });
});
