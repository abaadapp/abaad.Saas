import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import BusinessForm from '@/Pages/Platform/Businesses/partials/BusinessForm';
import { pageProps } from './setup';

vi.mock('@/Layouts/PlatformLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

const { default: BusinessEdit } = await import('@/Pages/Platform/Businesses/Edit');

/**
 * مربّعُ «يُؤوي بوتيكات» يعرض ما هو محفوظ — لا مطفأً دائمًا.
 *
 * ═══ ولمَ حارسٌ لمربّعٍ واحد ═══
 *
 * مربّعٌ يُعرض مطفأً والقاعدةُ تقول «مفتوح» لا يُخطئ شيئًا: يظنّ المشغّل
 * أنّه لم يُفتح فيفتحه ثانيةً، أو يحفظ حقلًا آخر فيُطفئه. وفي الحالتين
 * يفقد التاجرُ تبويبَ «البوتيكات» بلا أن يمسّه أحد.
 *
 * وهو عطبٌ وقع فعلًا: شاشةُ التعديل أسقطت المفتاحَ من قيمها الابتدائيّة،
 * والفئةُ والواجهةُ بقيتا فقُرئ الحفظُ سليمًا.
 */

const base = {
    name: 'RIBBON', type: 'محل ورد', country: '', city: '', address: '',
    owner_name: '', phone: '', email: '', plan_id: '', status: 'نشط',
    starts_at: '', ends_at: '', tier: 'gold', storefront_theme: 'ribbon',
};

const draw = (initial: Record<string, unknown>) =>
    render(
        <BusinessForm
            options={{ types: ['محل ورد'], cities: ['مسقط'], statuses: ['نشط'], plans: [] }}
            initial={{ ...base, ...initial } as never}
            businessId={5}
            ownerEmail="ribbon@abaadapp.om"
            action="/x"
            method="put"
            submitLabel="حفظ"
            cancelHref="/"
        />,
    );

const box = () => screen.getByRole('checkbox', { name: /يُؤوي بوتيكات/ });

describe('مفتاحُ البوتيكات في لوحة المنصّة', () => {
    beforeEach(() => {
        for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
        Object.assign(pageProps, { translations: {} });
    });

    it('يُعرض مفتوحًا حين يكون مفتوحًا', () => {
        draw({ boutiques_enabled: true });

        expect(box()).toBeChecked();
    });

    it('ومطفأً حين يكون مطفأً', () => {
        draw({ boutiques_enabled: false });

        expect(box()).not.toBeChecked();
    });

    /**
     * وغيابُه لا يُقرأ «مفتوحًا» — شركةٌ قديمةٌ بلا العمود تُعرض مطفأةً.
     *
     * وهو الطرفُ الآخر: لو قُرئ الغيابُ «مفتوحًا» لَفُتح لكلّ شركةٍ في
     * أبعاد تبويبٌ لم يطلبه صاحبُها.
     */
    it('وغيابُه يُقرأ مطفأً لا مفتوحًا', () => {
        draw({});

        expect(box()).not.toBeChecked();
    });
});

/**
 * ═══ والشاشةُ نفسُها تُختبر لا النموذجُ وحدَه ═══
 *
 * العطبُ لم يكن في `BusinessForm` — هو يعرض ما يُعطاه. كان في `Edit.tsx`:
 * أسقط المفتاحَ من القيم الابتدائيّة، فلم يصل النموذجَ شيءٌ يعرضه.
 *
 * فحارسٌ يبني القيمَ بيده يمرّ والعطبُ قائم — وقد مرّ فعلًا. والحارسُ
 * يُوضع حيث يقع العطب لا حيث يسهل وضعُه.
 */
describe('شاشةُ تعديل الشركة', () => {
    const shop = (over: Record<string, unknown> = {}) => ({
        id: 5, name: 'RIBBON', type: 'محل ورد', country: 'عُمان', city: 'مسقط',
        address: '', owner: '', phone: '', email: '', plan_id: null, status: 'نشط',
        starts_at: null, ends_at: null, tier: 'gold', storefront_theme: 'ribbon',
        logo_url: null, owner_email: 'ribbon@abaadapp.om', boutiques_enabled: true,
        ...over,
    });

    const open = (over: Record<string, unknown> = {}) => {
        for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
        Object.assign(pageProps, {
            translations: {},
            business: shop(over),
            options: { types: ['محل ورد'], cities: ['مسقط'], statuses: ['نشط'], plans: [] },
        });

        return render(<BusinessEdit />);
    };

    it('تحمل المفتاحَ المحفوظ إلى النموذج', () => {
        open({ boutiques_enabled: true });

        expect(screen.getByRole('checkbox', { name: /يُؤوي بوتيكات/ })).toBeChecked();
    });

    it('ولا تفتحه لمن هو مغلقٌ عنده', () => {
        open({ boutiques_enabled: false });

        expect(screen.getByRole('checkbox', { name: /يُؤوي بوتيكات/ })).not.toBeChecked();
    });
});
