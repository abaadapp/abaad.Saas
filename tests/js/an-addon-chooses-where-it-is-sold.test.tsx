import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import AddonDialog from '@/Pages/Admin/Products/partials/AddonDialog';

/**
 * «أين تُباع الإضافة؟» — نقطةُ البيع، والموقع، أو كلاهما، أو لا شيء.
 *
 * ═══ ما يُحرس ═══
 *
 *   - الجديدةُ في الصندوق وحدَه ما لم يُختر غيرُه — كما كانت كلُّ إضافة.
 *   - القائمةُ تُفتح بقناتيها كما حُفظتا، والحفظُ يرسل القناتين كما اختيرتا.
 *   - والقناتان مطفأتان معًا تُقالان «غير متاحة للبيع حاليًا» — لا حذف.
 *   - وإضافةٌ قائمةٌ مرتبطةٌ بصنف بوتيك يُقال تحتها إنّه لا يُستحقّ للبوتيك
 *     عنها شيءٌ بعد — ولا يُقال لصنفٍ يملكه المتجر.
 *
 * وما يفعله الخادمُ بهما في `AnAddonIsSoldWhereItsOwnerOpensItTest`.
 */

const shopAddon = (over: Record<string, unknown> = {}) => ({
    value: 7, label: 'شوكولاتة', price: 2, active: true, private: false,
    scope: 'all' as const, inventory_product_id: null, product_ids: [], ...over,
});

const stock = [
    { value: 3, label: 'ورق', quantity: 50 },
    { value: 4, label: 'فضّة لمى', quantity: 5, boutique: true },
];

function open(addon: ReturnType<typeof shopAddon> | null, stockItems: typeof stock = []) {
    const fetchMock = vi.fn().mockResolvedValue({
        ok: true,
        json: async () => ({ addon: shopAddon() }),
    });
    vi.stubGlobal('fetch', fetchMock);
    render(<AddonDialog addon={addon} stockItems={stockItems} products={[]} onClose={() => {}} onSaved={() => {}} />);

    return {
        fetchMock,
        pos: screen.getByTestId('addon-channel-pos') as HTMLInputElement,
        web: screen.getByTestId('addon-channel-web') as HTMLInputElement,
        sent: () => JSON.parse(fetchMock.mock.calls[0][1].body as string),
    };
}

afterEach(() => vi.unstubAllGlobals());

describe('أين تُباع الإضافة؟', () => {
    it('الجديدةُ في الصندوق وحدَه ما لم يُختر غيرُه', async () => {
        const { pos, web, sent } = open(null);
        expect(pos.checked).toBe(true);
        expect(web.checked).toBe(false);

        fireEvent.change(screen.getByPlaceholderText('شوكولاتة'), { target: { value: 'بطاقة تهنئة' } });
        fireEvent.click(screen.getByRole('button', { name: 'حفظ' }));

        await waitFor(() => expect(sent()).toMatchObject({ pos_enabled: true, web_enabled: false }));
    });

    it('القائمةُ تُفتح بقناتيها، والحفظُ يرسلهما كما اختيرتا', async () => {
        const { pos, web, sent } = open(shopAddon({ pos_enabled: false, web_enabled: true }));
        expect(pos.checked).toBe(false);
        expect(web.checked).toBe(true);

        fireEvent.click(pos);
        fireEvent.click(screen.getByRole('button', { name: 'حفظ' }));

        await waitFor(() => expect(sent()).toMatchObject({ pos_enabled: true, web_enabled: true }));
    });

    it('القناتان مطفأتان معًا: محفوظةٌ لا تُباع — ويُقال ذلك', () => {
        const { pos } = open(shopAddon());
        expect(screen.getByTestId('addon-channels-hint').textContent).toContain('القناة المختارة');

        fireEvent.click(pos);

        expect(screen.getByTestId('addon-channels-hint').textContent).toContain('غير متاحة للبيع حاليًا');
    });

    it('إضافةٌ قائمةٌ على صنف بوتيك يُقال تحتها إنّه لا مستحقَّ للبوتيك بعد', () => {
        open(shopAddon({ inventory_product_id: 4, inventory_quantity: 1 }), stock);
        expect(screen.getByTestId('addon-boutique-warning').textContent).toContain('لا يُنشأ للبوتيك مستحقٌّ');
    });

    it('ولا يُقال لصنفٍ يملكه المتجر', () => {
        open(shopAddon({ inventory_product_id: 3, inventory_quantity: 1 }), stock);
        expect(screen.queryByTestId('addon-boutique-warning')).toBeNull();
    });
});
