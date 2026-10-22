import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { router } from '@inertiajs/react';

import BoutiqueParts, { boutiqueSplit, suggestedCharge, withDraft, type BoutiquePart } from '@/Pages/Admin/Products/partials/BoutiqueParts';
import ComponentsPanel from '@/Pages/Admin/Boutiques/ComponentsPanel';
import { CancelWithPieces, KeptPieces } from '@/Pages/Admin/Orders/BoutiquePieces';
import { pageProps } from './setup';

/* قائمةُ Radix لا تُختار في jsdom — فتُرسم قائمةً أصليّة بالعقد نفسِه */
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
                {options.map((o) => <option key={o.value} value={o.value} disabled={o.disabled}>{o.label}</option>)}
            </select>
        ),
    };
});

/**
 * واجهةُ مستحقّات مكوّنات البوتيك — ما يراه صاحبُ المتجر قبل أن يحفظ ويبيع.
 *
 * ═══ ما يُحرس ═══
 *
 *   - باقةٌ بعشرين فيها فضّةٌ بسبعة: للبوتيك ٧ (٣٥٪ من البيع)، ويبقى ١٣.
 *     وبوتيكان: ٧ و١٢ من ٣٠. والإضافةُ الاختياريّة: يدفع ٢٧ وللبوتيك ٧.
 *     وسعرٌ لا يسع ما للبوتيكات يُقال إنّه لن يُباع. والحسبةُ بالألف صحيحة.
 *   - متجرٌ يحتسب الضريبة يُقال له ذلك ولا يُعرض له اختيار.
 *   - كشفُ البوتيك يقول الاتّجاهين بلفظهما، ويُرسل سدادَ المكوّنات والاسترداد.
 *   - نافذةُ الإلغاء: ما عاد وما لم يعد لا يتجاوزان كمّيّة القطعة، والباقي
 *     «لم يُتحقَّق منه»، وما بقي بعد الإلغاء يُتمّ من شاشة الطلب.
 *
 * والحسبةُ نفسُها في الخادم (`BoutiqueDues`)، وتحرسها
 * `ASaudLinksABoutiquePieceFromHisOwnProductTest`.
 */

const currency = { code: 'OMR', symbol: 'ر.ع', decimals: 3 };

const silver = (over: Partial<BoutiquePart> = {}): BoutiquePart => ({
    id: 1, kind: 'included', product_id: 3, name: 'قطعة فضّة', boutique_id: 10, boutique: 'بوتيك لمى',
    unit_price: 7, quantity: 1, ...over,
});

describe('ما للبوتيك وما يبقى من الباقة', () => {
    it('باقةٌ بعشرين وفضّةٌ بسبعة: ٧ للبوتيك (٣٥٪) و١٣ للمتجر قبل تكاليفه', () => {
        const s = boutiqueSplit(20, [silver()]);
        expect(s.boutiques).toEqual([{ id: 10, name: 'بوتيك لمى', due: 7, share: 35 }]);
        expect(s.remainder).toBe(13);
    });

    it('بوتيكان في باقةٍ بثلاثين: لكلٍّ مستحقُّه، ويبقى ١١', () => {
        const s = boutiqueSplit(30, [silver(), silver({ id: 2, product_id: 4, name: 'عطر', boutique_id: 11, boutique: 'بوتيك ريم', unit_price: 12 })]);
        expect(s.boutiques.map((b) => b.due)).toEqual([7, 12]);
        expect(s.remainder).toBe(11);
    });

    it('الإضافةُ الاختياريّة لا تُنقص الباقة: يدفع ٢٧ وللبوتيك ٧', () => {
        const s = boutiqueSplit(20, [silver({ kind: 'optional', addon_price: 7 })]);
        expect(s.remainder).toBe(20);
        expect(s.optional).toEqual([{ id: 1, name: 'قطعة فضّة', customer: 27, due: 7, kept: 0 }]);
    });

    it('الكمّيّةُ تضرب المستحقّ، وبالألف صحيحًا', () => {
        expect(boutiqueSplit(0.3, [silver({ unit_price: 0.1, quantity: 2 })]).remainder).toBe(0.1);
        expect(boutiqueSplit(20, [silver({ quantity: 2 })]).remainder).toBe(6);
    });

    it('القسمُ يرسم الأرقامَ ويقول إن لم يسع السعرُ ما للبوتيكات', () => {
        Object.assign(pageProps, { context: { currency } });
        const { rerender } = render(
            <BoutiqueParts productId={7} price="20" currencyLabel="ر.ع"
                data={{ taxed: false, own_boutique: false, options: [], parts: [silver()] }} />,
        );
        expect(screen.getByTestId('boutique-split-due').textContent).toContain('7.000');
        expect(screen.getByTestId('boutique-split-due').textContent).toContain('35%');
        expect(screen.getByTestId('boutique-split-remainder').textContent).toContain('13.000');
        expect(screen.queryByTestId('boutique-split-short')).toBeNull();

        rerender(<BoutiqueParts productId={7} price="6" currencyLabel="ر.ع"
            data={{ taxed: false, own_boutique: false, options: [], parts: [silver()] }} />);
        expect(screen.getByTestId('boutique-split-short')).toBeTruthy();
    });

    it('قطعتان بسبعة: يُقترح ١٤ للعميل وللبوتيك ١٤ — وسعرٌ آخر لا يحرّك مستحقَّه', () => {
        Object.assign(pageProps, { context: { currency } });
        const option = { value: 3, label: 'قطعة فضّة', boutique_id: 10, boutique: 'بوتيك لمى', price: 7, quantity: 10, blocked: null };
        render(<BoutiqueParts productId={7} price="20" currencyLabel="ر.ع"
            data={{ taxed: false, own_boutique: false, options: [option], parts: [] }} />);

        fireEvent.change(screen.getByLabelText('صنف من بوتيك'), { target: { value: '3' } });
        fireEvent.change(screen.getByLabelText('كيف يدخل الباقة؟'), { target: { value: 'optional' } });
        fireEvent.change(screen.getByTestId('boutique-part-qty'), { target: { value: '2' } });
        expect((screen.getByTestId('boutique-part-charge') as HTMLInputElement).value).toBe('14');
        expect(screen.getByTestId('boutique-split-optional').textContent).toContain('34.000');
        expect(screen.getByTestId('boutique-split-optional').textContent).toContain('14.000');

        // يكتب ١٨: يدفع العميل ٣٨، وللبوتيك ١٤ كما هي، ويبقى للمتجر ٤
        fireEvent.change(screen.getByTestId('boutique-part-charge'), { target: { value: '18' } });
        expect(screen.getByTestId('boutique-split-optional').textContent).toContain('38.000');
        expect(screen.getByTestId('boutique-part-charge-effect').textContent).toContain('14.000');
        expect(screen.getByTestId('boutique-part-charge-effect').textContent).toContain('4.000');

        // والكمّيّةُ بعدها لا تمحو ما كتبه — و«أعِد المقترح» تعيده للكمّيّة
        fireEvent.change(screen.getByTestId('boutique-part-qty'), { target: { value: '3' } });
        expect((screen.getByTestId('boutique-part-charge') as HTMLInputElement).value).toBe('18');
        expect(screen.getByTestId('boutique-part-charge-below').textContent).toContain('3.000');
        fireEvent.click(screen.getByTestId('boutique-part-charge-reset'));
        expect((screen.getByTestId('boutique-part-charge') as HTMLInputElement).value).toBe('21');

        const fetcher = vi.fn((_url: string, _init: RequestInit) => Promise.resolve(new Response(JSON.stringify({ ok: true, parts: [] }))));
        vi.stubGlobal('fetch', fetcher);
        fireEvent.click(screen.getByTestId('boutique-part-save'));
        expect(JSON.parse(String(fetcher.mock.calls[0][1].body))).toMatchObject({ mode: 'optional', quantity: 3, addon_price: 21 });
        vi.unstubAllGlobals();
    });

    it('المقترحُ بالألف صحيحًا', () => {
        expect(suggestedCharge(0.1, 3)).toBe(0.3);
        expect(suggestedCharge(7, 2)).toBe(14);
    });

    it('تعديلُ كمّيّة مكوّنٍ مشمولٍ قائم يُعاين الكمّيّةَ التي تُحفظ — لا المجموع', () => {
        const existing = [silver({ quantity: 1 })];
        const next = silver({ id: 0, quantity: 3 });
        expect(withDraft(existing, next)).toEqual([silver({ quantity: 3 })]);
        expect(boutiqueSplit(30, withDraft(existing, next)).boutiques[0].due).toBe(21);

        // واختياريٌّ من الصنف نفسِه إضافةٌ جديدة بجانبه
        expect(withDraft(existing, silver({ id: 0, kind: 'optional', quantity: 2, addon_price: 14 }))).toHaveLength(2);

        Object.assign(pageProps, { context: { currency } });
        const option = { value: 3, label: 'قطعة فضّة', boutique_id: 10, boutique: 'بوتيك لمى', price: 7, quantity: 10, blocked: null };
        render(<BoutiqueParts productId={7} price="30" currencyLabel="ر.ع"
            data={{ taxed: false, own_boutique: false, options: [option], parts: existing }} />);
        fireEvent.change(screen.getByLabelText('صنف من بوتيك'), { target: { value: '3' } });
        fireEvent.change(screen.getByTestId('boutique-part-qty'), { target: { value: '3' } });
        expect(screen.getAllByTestId('boutique-split-due')).toHaveLength(1);
        expect(screen.getByTestId('boutique-split-due').textContent).toContain('21.000');
        expect(screen.getByTestId('boutique-split-remainder').textContent).toContain('9.000');
        expect(screen.getByTestId('boutique-part-replaces').textContent).toContain('3');
    });

    it('ومتجرٌ يحتسب الضريبة يُقال له، ولا اختيارَ يُعرض', () => {
        render(<BoutiqueParts productId={7} price="20" currencyLabel="ر.ع"
            data={{ taxed: true, own_boutique: false, options: [], parts: [] }} />);
        expect(screen.getByTestId('boutique-parts-closed').textContent).toContain('الضريبة');
        expect(screen.queryByTestId('boutique-part-save')).toBeNull();
    });
});

describe('كشفُ البوتيك: المكوّناتُ والرصيدان', () => {
    const ledger = {
        owed_unsettled: 3, owed_issued: 7, owed: 10, standalone_unpaid: 20, credit: 4,
        movements: [{ at: '2027-03-02 10:00', type: 'credit' as const, state: null, product: 'قطعة فضّة', quantity: -1, amount: -7, order: 'INV-1', reason: 'أُعيدت' }],
    };

    it('يقول من على من بلفظه، ويُرسل سدادَ المكوّنات والاسترداد', () => {
        Object.assign(pageProps, { context: { currency }, errors: {} });
        render(<ComponentsPanel boutiqueId={5} currency={currency as never}
            part={{ lines: [{ product_id: 3, name: 'قطعة فضّة', source: 'recipe', unit_price: 7, quantity: 1, amount: 7 }], due: 7, credit: 0, credit_available: 4, net: 7, count: 1 }}
            payable={27} ledger={ledger}
            settlement={{ id: 9, number: 'BQ-2027-02-1', kind: 'settlement', components_due: 7, components_net: 7, components_paid: false }} />);

        expect(screen.getByTestId('boutique-owed').textContent).toContain('10');
        expect(screen.getByTestId('boutique-credit').textContent).toContain('4');
        expect(screen.getByTestId('boutique-payable').textContent).toContain('27');
        expect(screen.getByTestId('boutique-movements').textContent).toContain('رصيدٌ للمتجر');

        fireEvent.click(screen.getByTestId('boutique-pay-components'));
        expect(router.post).toHaveBeenCalledWith('/admin.boutiques.components.pay/5/9', { method: 'نقدي' }, expect.anything());

        fireEvent.click(screen.getByTestId('boutique-refund'));
        expect(router.post).toHaveBeenCalledWith('/admin.boutiques.refund/5', { method: 'نقدي' }, expect.anything());
    });
});

describe('الإلغاءُ قطعةً قطعة', () => {
    const piece = { id: 41, product: 'قطعة فضّة', boutique: 'بوتيك لمى', quantity: 2, unit_price: 7, source: 'recipe', state: null };

    it('ما عاد وما لم يعد لا يتجاوزان القطعة، والباقي لم يُتحقَّق منه', () => {
        let sent: unknown = null;
        render(<CancelWithPieces open pieces={[piece]} onClose={() => {}} onConfirm={(a) => { sent = a; }} />);

        fireEvent.change(screen.getByLabelText('عادت'), { target: { value: '1' } });
        fireEvent.change(screen.getByLabelText('لم تعد'), { target: { value: '5' } });
        expect(screen.getByTestId('cancel-piece-open').textContent).toContain('0');

        fireEvent.click(screen.getByTestId('cancel-confirm'));
        expect(sent).toEqual({ 41: { returned: 1, lost: 1 } });
    });

    it('وما بقي بعد الإلغاء يُتمّ من شاشة الطلب', () => {
        render(<KeptPieces orderNumber="WEB-7" pieces={[{ ...piece, quantity: 1, state: 'unverified' }]} />);
        fireEvent.click(screen.getByText('عادت إلى الرفّ'));
        expect(router.post).toHaveBeenCalledWith('/admin.orders.boutique-pieces/WEB-7', { action: 'returned', pieces: { 41: 1 } }, expect.anything());
    });
});
