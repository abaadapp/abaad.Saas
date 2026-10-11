import { render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { pageProps } from './setup';

vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

const { default: ReportsCustomers } = await import('@/Pages/Admin/Reports/Customers');

/**
 * «العملاء الأكثر إنفاقًا» تعدّ مشترياتٍ لا طلبات.
 *
 * الفاتورةُ اليدويّة شراءٌ بلا طلب (`Demo::customerSpend`)، فثلاثةُ طلباتٍ
 * وفاتورةٌ يدويّة أربعُ مشتريات — و«عدد الطلبات: 4» يكذب. والملفُّ يسمّيها
 * باسم الشاشة نفسه (`ReportColumns`) — يحرسه `AManualInvoiceIsSoldOnceInEveryReportTest`.
 */
describe('تقرير العملاء', () => {
    it('يسمّي العدد «عدد المشتريات» والمتوسّط «متوسّط قيمة الشراء» — لا «طلبات»', () => {
        for (const k of Object.keys(pageProps)) delete (pageProps as Record<string, unknown>)[k];
        Object.assign(pageProps, {
            translations: {},
            context: { currency: { code: 'OMR', symbol: 'ر.ع', decimals: 3 } },
            auth: { abilities: ['reports'], mayActions: [], planFeatures: { reports_advanced: true } },
            rows: [{ id: 1, name: 'وزارة الثقافة', orders: 4, total: 105 }],
            limit: 50,
            summary: { total: 105, customers: 1, orders: 4, average: 26.25 },
            rangeLabel: '',
            period: null,
        });

        render(<ReportsCustomers />);

        expect(screen.getByText('عدد المشتريات', { selector: 'th' })).toBeInTheDocument();
        expect(screen.getAllByText('عدد المشتريات').length).toBeGreaterThanOrEqual(2); // البطاقة والجدول
        expect(screen.getByText('متوسّط قيمة الشراء')).toBeInTheDocument();
        expect(screen.getByText('من يشتري أكثر، وكم مرّةً اشترى وكم أنفق')).toBeInTheDocument();

        expect(document.body.textContent).not.toMatch(/عدد الطلبات|متوسّط قيمة الطلب|كم طلبًا/);

        const row = screen.getByText('وزارة الثقافة').closest('tr')!;
        expect(within(row).getByText('4')).toBeInTheDocument();
    });
});
