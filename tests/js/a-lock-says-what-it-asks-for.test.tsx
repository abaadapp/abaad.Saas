import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { pageProps } from './setup';

/**
 * القفلُ يقول ما يطلبه — قبل أن يُردّ صاحبُه.
 *
 * الخادمُ صار يشترط في كلمة المرور الجديدة ثمانيةً فيها حرفٌ ورقم، كما
 * يشترط بابُ التسجيل (انظر `OneAccountOneLockTest`). وشرطٌ يُفرض ولا يُقال
 * يجعل صاحبَ المتجر يجرّب ويُردّ ولا يعرف ما ينقص.
 *
 * فهذا يقرأ الشاشةَ كما يقرؤها هو: أتقول الشرطَ قبل الضغط؟
 */

/* القشراتُ الثلاث أجسادٌ فارغة: الصفحةُ تُختبر لا الشريطُ حولها */
vi.mock('@/Layouts/AdminLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <>{children}</> }));
vi.mock('@/Layouts/PlatformLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <>{children}</> }));
vi.mock('@/Layouts/PosLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <>{children}</> }));

const profile = { name: 'سعود', email: 'o@abaad.om', phone: '96899110001', avatar: null, roleLabel: 'صاحب النشاط' };

async function show(limited: boolean) {
    Object.assign(pageProps, { profile, shell: 'admin', limited });
    const { default: ProfileEdit } = await import('@/Pages/Profile/Edit');
    render(<ProfileEdit />);
}

describe('الملفّ الشخصيّ — كلمةُ المرور', () => {
    it('يقول الشرطَ قبل أن يطلبه', async () => {
        await show(false);

        expect(screen.getByText(/ثمانية أحرف على الأقل/)).toBeInTheDocument();
        expect(screen.getByText(/حرف ورقم/)).toBeInTheDocument();
    });

    it('ويبقى يقول إنّ تركها فارغةً لا يغيّرها', async () => {
        await show(false);

        expect(screen.getByText(/اتركها فارغة/)).toBeInTheDocument();
    });

    it('والكاشيرُ لا يُعرض له البابُ أصلًا', async () => {
        await show(true);

        expect(screen.queryByText(/تغيير كلمة المرور/)).not.toBeInTheDocument();
        expect(screen.queryByText(/ثمانية أحرف على الأقل/)).not.toBeInTheDocument();
    });
});
