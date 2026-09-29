import { render, screen } from '@testing-library/react';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { pageProps } from './setup';
import { THEME_SITE } from './theme-form';

vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

const { default: ThemeStore } = await import('@/Pages/Admin/Website/ThemeStore');

/**
 * بطاقةُ «الدفع بالبطاقة» تُرسم لمن في قائمة المالك وحده.
 *
 * والخادمُ هو الحارس (`Paymob::allowed` يردّ الحفظَ بـ403)، والإخفاءُ هنا
 * راحةٌ لا قفل: متجرٌ لا يُفتح له Paymob لا يُعرض عليه حقلٌ يكتب فيه
 * مفاتيحَ لن تُقبل.
 */

const gateway = (over: Record<string, unknown> = {}) => ({
    active: false,
    public_key: '',
    card_integration_id: '',
    has_secret: false,
    has_hmac: false,
    ready: false,
    allowed: true,
    ...over,
});

const draw = (gw: Record<string, unknown>) => {
    for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    Object.assign(pageProps, {
        translations: {},
        dir: 'rtl',
        auth: { abilities: ['website'], mayActions: [] },
        context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 } },
        theme: 'ribbon',
        site: THEME_SITE,
        settings: {},
        fieldStates: {},
        fulfilments: ['delivery', 'pickup'],
        pages: { allowed: 'about,contact' },
        seo: { title: '', desc: '', index: true },
        storeOn: true,
        slug: 'ribbon',
        gateway: gw,
    });

    return render(<ThemeStore />);
};

describe('بطاقةُ Paymob في شاشة المتجر', () => {
    beforeEach(() => {
        for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
    });

    it('تُرسم لمتجرٍ في القائمة', () => {
        draw(gateway({ allowed: true }));

        expect(screen.getByText('الدفع بالبطاقة')).toBeInTheDocument();
        expect(document.getElementById('gateway')).not.toBeNull();
    });

    it('ولا تُرسم لمتجرٍ ليس فيها — ولو اكتملت مفاتيحُه', () => {
        draw(gateway({ allowed: false, has_secret: true, has_hmac: true, public_key: 'pk_live_x', card_integration_id: '1', active: true }));

        expect(screen.queryByText('الدفع بالبطاقة')).not.toBeInTheDocument();
        expect(document.getElementById('gateway')).toBeNull();
    });

    it('وبقيّةُ الشاشة كما هي لمن ليس فيها', () => {
        draw(gateway({ allowed: false }));

        // «الدفع والاستلام» وحقولُ الطلب باقية — المحذوفُ بطاقةُ البوّابة وحدها
        expect(screen.getByText('ما يستطيع زبونك أن يطلبه، وكيف يدفع ويستلم')).toBeInTheDocument();
    });
});

/*
 * وشاشةُ الإعدادات العامّة فيها نسخةٌ ثانيةٌ من البطاقة نفسِها.
 *
 * صفحةٌ بألف سطرٍ لا تُركَّب في اختبارٍ لسؤالٍ واحد — فيُقرأ شرطُها من
 * مصدرها كما تُقرأ صفحاتُ الإعدادات في هذا المستودع. وشرطٌ يسقط منها يُعيد
 * الحقلَ لمتجرٍ لا يُقبل منه ما يكتب.
 */
describe('بطاقةُ Paymob في الإعدادات العامّة', () => {
    const source = readFileSync(path.resolve(__dirname, '../../resources/js/Pages/Admin/Settings/Index.tsx'), 'utf8');

    it('مشروطةٌ بالقائمة مع السلّة', () => {
        const at = source.indexOf('<SettingsGroup title="الدفع بالبطاقة">');
        expect(at).toBeGreaterThan(-1);

        const guard = source.lastIndexOf('{', at);
        expect(source.slice(guard, at)).toContain('store.checkout && store.gateway.allowed &&');
    });
});
