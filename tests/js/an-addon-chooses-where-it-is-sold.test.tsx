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
 *
 * وما يفعله الخادمُ بهما في `AnAddonIsSoldWhereItsOwnerOpensItTest`.
 */

const shopAddon = (over: Record<string, unknown> = {}) => ({
    value: 7, label: 'شوكولاتة', price: 2, active: true, private: false,
    scope: 'all' as const, inventory_product_id: null, product_ids: [], ...over,
});

function open(addon: ReturnType<typeof shopAddon> | null) {
    const fetchMock = vi.fn().mockResolvedValue({
        ok: true,
        json: async () => ({ addon: shopAddon() }),
    });
    vi.stubGlobal('fetch', fetchMock);
    render(<AddonDialog addon={addon} stockItems={[]} products={[]} onClose={() => {}} onSaved={() => {}} />);

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
});
