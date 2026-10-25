import { render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { pageProps } from './setup';

vi.mock('@/Layouts/AdminLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

const { default: Sales } = await import('@/Pages/Admin/CustomerInvoices/Sales');
type SaleRow = import('@/Pages/Admin/CustomerInvoices/Sales').SaleRow;

/**
 * «جميع عمليات البيع» — ما يقرؤه صاحبُ المتجر في الصفّ.
 *
 * ═══ ما يُحرس ═══
 *
 * ١) بيعةُ الكاشير المختار باسمه، والحسابُ المسجَّل يُقال تحته لا بدلًا منه.
 * ٢) طلبُ الموقع بلا بائعٍ مختلَق، وما لا يُعرف بائعُه «غير محدد».
 * ٣) الطلبُ المفوتَر طلبٌ يُذكر رقمُ فاتورته — لا يُسمّى فاتورة، ورابطُه طلبُه.
 * ٤) الفرعُ المختار يُقال فوق الجدول، ومعه بابُ «كل الفروع».
 * ٥) التبويباتُ الأربعة لمن يملك المبيعات.
 */

const base: SaleRow = {
    key: 'order:1', kind: 'order', id: 1, number: 'INV-1', type: 'بيع نقطة البيع', source: 'pos', source_label: 'نقطة البيع',
    customer: 'عميل نقدي', seller_kind: 'person', seller: 'سارة', seller_id: 7, account: null, branch: 'مسقط',
    date: '2026-10-20 10:00', amount: 10.5, credited: null, pay_state: 'مدفوع', cover: null, prep: null, prep_stage: 'none',
    status: 'مكتمل', cancelled: false, prep_id: null,
};

const props = (rows: SaleRow[], extra: Record<string, unknown> = {}) => ({
    rows,
    pagination: {
        current_page: 1, last_page: 1, per_page: 20, from: rows.length ? 1 : null, to: rows.length || null, total: rows.length,
        prev_page_url: null, next_page_url: null,
    },
    filters: {},
    source: null as 'pos' | 'website' | null,
    totals: { count: rows.length, sales: 10.5, collected: 10.5, outstanding: 0, cancelled: 0 },
    sellers: [{ value: '7', label: 'سارة' }],
    paymentStates: ['مدفوع', 'مدفوع جزئيًا', 'غير مدفوع', 'ملغى'],
    prepStages: { none: 'بلا تجهيز', waiting: 'بانتظار التجهيز' },
    branch: null,
    mayPrepare: false,
    unknownCount: null as number | null,
    ...extra,
});

const setAuth = (abilities: string[]) => {
    (pageProps as Record<string, unknown>).auth = { abilities, mayActions: [] };
};

afterEach(() => setAuth([]));

describe('جميع عمليات البيع', () => {
    it('الكاشيرُ المختار بائعُ البيعة، والحسابُ المسجَّل يُقال تحته', () => {
        render(<Sales {...props([{ ...base, account: 'صاحب ورود' }])} />);

        const row = screen.getByText('INV-1').closest('tr') as HTMLElement;
        expect(within(row).getByText('سارة')).toBeInTheDocument();
        expect(row).toHaveTextContent('سُجّلت من حساب: صاحب ورود');
    });

    it('طلبُ الموقع بلا بائع، وما لا يُعرف بائعُه «غير محدد»', () => {
        render(
            <Sales
                {...props([
                    { ...base, key: 'order:2', id: 2, number: 'WEB-2', type: 'طلب موقع', source: 'website', seller_kind: 'website', seller: null, seller_id: null },
                    { ...base, key: 'order:3', id: 3, number: 'OLD-3', seller_kind: 'unknown', seller: null, seller_id: null },
                ])}
            />,
        );

        const web = screen.getByText('WEB-2').closest('tr') as HTMLElement;
        expect(web).toHaveTextContent('الموقع الإلكتروني');
        expect(web).not.toHaveTextContent('سارة');
        expect(screen.getByText('OLD-3').closest('tr')).toHaveTextContent('غير محدد');
    });

    it('الطلبُ المفوتَر يبقى طلبًا يقود إلى طلبه ويُذكر رقمُ فاتورته', () => {
        render(<Sales {...props([{ ...base, cover: { id: 9, number: 'CI-9' }, pay_state: 'مدفوع جزئيًا' }])} />);

        const row = screen.getByText('INV-1').closest('tr') as HTMLElement;
        expect(row).toHaveTextContent('بيع نقطة البيع');
        expect(row).not.toHaveTextContent('فاتورة عميل');
        expect(screen.getByText('INV-1').closest('a')).toHaveAttribute('href', '/admin.orders.show/INV-1');
        expect(within(row).getByText('CI-9').closest('a')).toHaveAttribute('href', '/admin.customerInvoices.show/9');
        expect(row).toHaveTextContent('عبر الفاتورة');
    });

    it('والفاتورةُ المستقلّة تقود إلى صفحتها', () => {
        render(<Sales {...props([{ ...base, key: 'invoice:4', kind: 'invoice', id: 4, number: 'CI-4', type: 'فاتورة عميل', source: 'invoice' }])} />);

        expect(screen.getByText('CI-4').closest('a')).toHaveAttribute('href', '/admin.customerInvoices.show/4');
    });

    it('الفرعُ المختار يُقال، ومعه بابُ «كل الفروع»', () => {
        render(<Sales {...props([base], { branch: 'صحار' })} />);

        const notice = screen.getByTestId('branch-notice');
        expect(notice).toHaveTextContent('صحار');
        expect(within(notice).getByText('عرض كل الفروع').closest('a')).toHaveAttribute('href', '/admin.branch.switch/all');
    });

    it('ولا تنبيهَ حين «كل الفروع»', () => {
        render(<Sales {...props([base])} />);

        expect(screen.queryByTestId('branch-notice')).toBeNull();
    });

    it('تبويبُ الصندوق يقول عددَ البيعات التي لا يُعرف مصدرُها ويقود إليها', () => {
        render(<Sales {...props([], { source: 'pos', unknownCount: 3 })} />);

        const notice = screen.getByTestId('unknown-source-notice');
        expect(notice).toHaveTextContent('3');
        expect(within(notice).getByText('عرضها').closest('a')).toHaveAttribute('href', '/admin.customerInvoices.sales/unknown');
    });

    it('ولا تنبيهَ في «جميع عمليات البيع» — هي فيها', () => {
        render(<Sales {...props([base], { unknownCount: 3 })} />);

        expect(screen.queryByTestId('unknown-source-notice')).toBeNull();
    });

    it('التبويباتُ الأربعة لمن يملك المبيعات', () => {
        setAuth(['orders']);
        render(<Sales {...props([base])} />);

        for (const tab of ['جميع عمليات البيع', 'فواتير العملاء', 'مبيعات نقطة البيع', 'طلبات الموقع الإلكتروني']) {
            expect(screen.getAllByText(tab).length).toBeGreaterThan(0);
        }
    });
});
