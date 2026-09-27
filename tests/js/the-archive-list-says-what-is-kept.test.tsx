import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { pageProps } from './setup';

/* القشرةُ جسدٌ فارغ: الصفحةُ تُختبر لا الشريطُ حولها */
vi.mock('@/Layouts/PlatformLayout', () => ({ default: ({ children }: { children: React.ReactNode }) => <>{children}</> }));

/**
 * قائمةُ أرشيفات الحذف — تقول ما بقي، ولا تقول أين هو.
 *
 * ═══ وما يُحرَس ═══
 *
 * أنّ بابَ التنزيل يُعرض لما له نسخةٌ ويُمنع لما ليس له، وأنّ سقوطًا مسّ
 * البياناتَ يُفرَّق عن سقوطٍ لم يمسّها — وهو الفرقُ الذي يقرّر أتُعاد
 * المحاولةُ أم يُنظر أوّلًا — وأنّ الصفحةَ لا تحمل مسارًا داخليًّا.
 *
 * والصلاحيّةُ ليست هنا: المسارُ نفسُه يردّ ٤٠٤/٤٠٣، وله حرّاسه في PHP.
 */
const row = (over: Partial<Record<string, unknown>> = {}) => ({
    id: 1,
    business_id: 42,
    business_name: 'متجر الورد',
    by: 'مدير المنصة',
    status: 'done',
    label: 'مكتملة',
    touched: true,
    rows: 120,
    users: 2,
    files: 3,
    failures: 0,
    verified: true,
    sha: 'a1b2c3d4e5f60718',
    bytes: 2048,
    available: true,
    error: null,
    at: '2026-09-27 01:00',
    ...over,
});

const show = async (rows: unknown[]) => {
    Object.assign(pageProps, { translations: {}, runs: rows });
    const { default: BusinessPurges } = await import('@/Pages/Platform/Businesses/Purges');
    render(<BusinessPurges />);
};

describe('قائمةُ أرشيفات الحذف', () => {
    it('تقول ما مُحي ومن محاه وأنّ النسخة استُعيدت', async () => {
        await show([row()]);

        expect(screen.getByText('متجر الورد')).toBeInTheDocument();
        expect(screen.getByText(/مدير المنصة/)).toBeInTheDocument();
        expect(screen.getByText(/استُعيد وتُحقق منه/)).toBeInTheDocument();
        expect(screen.getByRole('link', { name: /تنزيل/ })).toBeInTheDocument();
    });

    it('وما لا نسخةَ له لا يُعرض له بابُ تنزيل', async () => {
        await show([row({ available: false })]);

        expect(screen.queryByRole('link', { name: /تنزيل/ })).not.toBeInTheDocument();
        expect(screen.getByText('لا نسخة')).toBeInTheDocument();
    });

    it('وسقوطٌ قبل المحو يُفرَّق عن سقوطٍ بعده', async () => {
        await show([
            row({ id: 1, status: 'failed', label: 'فشلت عند: رفع النسخة المشفَّرة', touched: false }),
            row({ id: 2, status: 'failed', label: 'فشلت عند: حذف البيانات', touched: true, business_name: 'متجر الجار' }),
        ]);

        expect(screen.getByText(/سقط قبل أن يُمسّ شيء/)).toBeInTheDocument();
        expect(screen.getByText(/بدأ المحو قبل السقوط/)).toBeInTheDocument();
    });

    it('وما تعذّر حذفُه من ملفّاتٍ يُقال ولا يُبتلع', async () => {
        await show([row({ failures: 3 })]);

        expect(screen.getByText(/تعذّر حذف 3/)).toBeInTheDocument();
    });

    it('ولا تحمل الصفحةُ مسارًا داخليًّا ولا اسمَ دلو', async () => {
        await show([row()]);

        const body = document.body.textContent ?? '';

        for (const secret of ['business-purges', 'storage/app', 'spaces', '.zip.enc', 'AWS']) {
            expect(body).not.toContain(secret);
        }
    });

    it('وفراغُ القائمة يُقال بكلمة', async () => {
        await show([]);

        expect(screen.getByText(/لا شركة حُذفت نهائيًا بعد/)).toBeInTheDocument();
    });
});
