import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import GoldBadge from '@/Components/GoldBadge';
import Topbar from '@/Components/Topbar';
import BusinessForm from '@/Pages/Platform/Businesses/partials/BusinessForm';
import { pageProps } from './setup';

/**
 * العلامةُ الذهبيّة تُرسم لمن فئتُه ذهبيّة وحدَه — في الشريط وفي لوحة المنصّة —
 * ونموذجُ المنصّة يعرض الفئةَ والواجهةَ من قائمةٍ مغلقة.
 */
describe('العلامةُ الذهبيّة', () => {
    it('تُرسم للذهبيّ ولا تُرسم لسواه', () => {
        Object.assign(pageProps, { translations: {} });
        const { rerender } = render(<GoldBadge tier="gold" />);
        expect(screen.getByTestId('gold-badge')).toHaveTextContent('ذهبي');

        rerender(<GoldBadge tier={null} />);
        expect(screen.queryByTestId('gold-badge')).toBeNull();
        rerender(<GoldBadge tier="silver" />);
        expect(screen.queryByTestId('gold-badge')).toBeNull();
    });

    it('والشريطُ العلويّ يحملها من سياق المتجر', () => {
        Object.assign(pageProps, {
            auth: { abilities: ['*'], mayActions: [], isEmployee: false, user: { name: 'سعود', avatar: null, roleLabel: 'مدير نشاط', role: 'admin', businessId: 5 } },
            context: { tier: 'gold', website: null, branches: [], branchId: null, branchName: '', currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 }, currencies: [] },
            notifications: null, reportPages: [], locale: 'ar', csrf: 'x', flash: {}, translations: {},
        });
        const base = globalThis.route as unknown as (...a: unknown[]) => string;
        globalThis.route = ((...a: unknown[]) => (a.length ? base(...a) : { current: () => 'admin.products.index' })) as never;

        render(<Topbar onMenuClick={() => {}} />);
        expect(screen.getByTestId('gold-badge')).toBeInTheDocument();
    });

    it('ونموذجُ المنصّة يعرض الفئةَ والواجهةَ من قائمةٍ مغلقة', () => {
        Object.assign(pageProps, { translations: {} });
        render(
            <BusinessForm
                options={{ types: ['محل ورد'], cities: ['مسقط'], statuses: ['نشط'], plans: [] }}
                initial={{ name: 'RIBBON', type: 'محل ورد', country: '', city: '', address: '', owner_name: '', phone: '', email: '', plan_id: '', status: 'نشط', starts_at: '', ends_at: '', tier: 'gold', storefront_theme: 'ribbon' }}
                businessId={5}
                ownerEmail="ribbon@abaadapp.om"
                action="/x"
                method="put"
                submitLabel="حفظ"
                cancelHref="/"
            />,
        );

        // القائمتان من Radix: الزرُّ يحمل الاسمَ ويعرض المختار، ولا يقبل Radix قيمةً فارغة — فالفراغُ يُعرض placeholder
        expect(screen.getByLabelText('الفئة')).toHaveTextContent('ذهبي — نظام مستقلّ داخل أبعاد');
        expect(screen.getByLabelText('واجهة المتجر الإلكتروني')).toHaveTextContent('RIBBON');
    });

    it('والفراغُ في النموذج يُقرأ «عادي» و«الافتراضية»', () => {
        Object.assign(pageProps, { translations: {} });
        render(
            <BusinessForm
                options={{ types: ['عام'], cities: [], statuses: ['نشط'], plans: [] }}
                initial={{ name: 'عادي', type: 'عام', country: '', city: '', address: '', owner_name: '', phone: '', email: '', plan_id: '', status: 'نشط', starts_at: '', ends_at: '', tier: '', storefront_theme: '' }}
                businessId={4}
                ownerEmail="x@abaad.om"
                action="/x"
                method="put"
                submitLabel="حفظ"
                cancelHref="/"
            />,
        );
        expect(screen.getByLabelText('الفئة')).toHaveTextContent('عادي');
        expect(screen.getByLabelText('واجهة المتجر الإلكتروني')).toHaveTextContent('الافتراضية');
    });
});
