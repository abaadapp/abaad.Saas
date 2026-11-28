import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { pageProps } from './setup';

/*
 * «من ← إلى» في شاشات التصحيح يتبع اتجاه الصفحة.
 *
 * كان السهمُ ← في العربيّة والإنجليزيّة معًا، فتُقرأ الإنجليزيّة «80 صار 100».
 * وكان المبلغان بلا عزل، فيختلط «ر.ع» بالأرقام والسهم على الهاتف.
 */
const { fromTo } = await import('@/lib/fromTo');
const InvoiceRevisions = (await import('@/Pages/Admin/CustomerInvoices/partials/InvoiceRevisions')).default;

const m = (v: number) => `${v.toFixed(3)} ر.ع`;
const revision = {
    revision: 1, kind: 'issued', basis: 'تصحيح خطأ في الفاتورة', reason: 'كمية أقل', at: '2026-11-28 10:00', by: 'المالك',
    total_before: 100, total_after: 80, tax_before: 0, tax_after: 0, paid_at_edit: 0,
    fields: [], lines: [], customer_credit: 0, documents: [],
};

describe('السهمُ يشير إلى ما صار، باتجاه الصفحة', () => {
    afterEach(() => {
        cleanup();
        delete (pageProps as Record<string, unknown>).dir;
    });

    it('العربيّة: ← وكلُّ مبلغٍ معزول', () => {
        expect(fromTo('100.000 ر.ع', '80.000 ر.ع', 'rtl')).toBe('\u2068100.000 ر.ع\u2069 ← \u206880.000 ر.ع\u2069');
    });

    it('الإنجليزيّة: →', () => {
        expect(fromTo('100.000 OMR', '80.000 OMR', 'ltr')).toBe('\u2068100.000 OMR\u2069 → \u206880.000 OMR\u2069');
    });

    it('سجلُّ التعديلات على صفحةٍ عربيّة', () => {
        Object.assign(pageProps, { dir: 'rtl' });
        render(<InvoiceRevisions revisions={[revision] as never} m={m} />);
        const text = screen.getByTestId('invoice-revisions').textContent ?? '';
        expect(text).toContain('\u2068100.000 ر.ع\u2069 ← \u206880.000 ر.ع\u2069');
        expect(text).not.toContain('→');
    });

    it('وعلى صفحةٍ إنجليزيّة', () => {
        Object.assign(pageProps, { dir: 'ltr' });
        render(<InvoiceRevisions revisions={[revision] as never} m={m} />);
        const text = screen.getByTestId('invoice-revisions').textContent ?? '';
        expect(text).toContain('\u2068100.000 ر.ع\u2069 → \u206880.000 ر.ع\u2069');
        expect(text).not.toContain('←');
    });
});
