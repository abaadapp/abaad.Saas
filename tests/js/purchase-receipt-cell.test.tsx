import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { ReceiptCell } from '@/Pages/Admin/Purchases/Index';
import type { PurchaseOrder } from '@/types/models';

/**
 * خانةُ إيصال الدفع — ثلاثُ حالاتٍ لا حالتان.
 *
 * والثانيةُ كانت تُقرأ كالثالثة، فيُرسم زرُّ «رفع» فوق إيصالٍ موجود — ورفعُ
 * بديلٍ يحذف الأوّل من القرص.
 */

const order = (over: Partial<PurchaseOrder> = {}): PurchaseOrder =>
    ({
        id: 7,
        number: 'PO-000125',
        branch: 'الرئيسي',
        supplier: 'ورد الخليج',
        status: 'مُرسل',
        total: 900,
        items_count: 1,
        items: [],
        receipt: null,
        receipt_name: null,
        has_receipt: false,
        ordered: '2026-09-08',
        received: null,
        ...over,
    }) as PurchaseOrder;

describe('إيصالٌ يُقرأ', () => {
    it('يُعرض رابطًا يفتحه', () => {
        render(<ReceiptCell order={order({ receipt: '/x/7', receipt_name: 'إيصال.pdf', has_receipt: true })} onUpload={vi.fn()} />);

        const link = screen.getByRole('link', { name: 'عرض' });
        expect(link).toHaveAttribute('href', '/x/7');
        expect(link).toHaveAttribute('title', 'إيصال.pdf');
    });

    it('ولا يُعرض معه بابُ رفعٍ يستبدله', () => {
        render(<ReceiptCell order={order({ receipt: '/x/7', has_receipt: true })} onUpload={vi.fn()} />);

        expect(screen.queryByRole('button', { name: 'رفع' })).not.toBeInTheDocument();
    });
});

describe('إيصالٌ موجودٌ لا يُقرأ', () => {
    /* وهو العطبُ الذي كُشف في تدقيق المشتريات */
    it('يقول إنّه مرفوع', () => {
        render(<ReceiptCell order={order({ receipt: null, has_receipt: true })} onUpload={vi.fn()} />);

        expect(screen.getByText('مرفوع')).toBeInTheDocument();
    });

    it('ولا يعرض زرَّ رفعٍ يمحوه', () => {
        render(<ReceiptCell order={order({ receipt: null, has_receipt: true })} onUpload={vi.fn()} />);

        expect(screen.queryByRole('button', { name: 'رفع' })).not.toBeInTheDocument();
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
    });
});

describe('لا إيصال', () => {
    it('يُعرض زرُّ الرفع', () => {
        render(<ReceiptCell order={order()} onUpload={vi.fn()} />);

        expect(screen.getByRole('button', { name: 'رفع' })).toBeInTheDocument();
        expect(screen.queryByText('مرفوع')).not.toBeInTheDocument();
    });

    it('واختيارُ ملفٍّ يرفعه', async () => {
        const onUpload = vi.fn();
        render(<ReceiptCell order={order()} onUpload={onUpload} />);

        const file = new File(['%PDF'], 'إيصال.pdf', { type: 'application/pdf' });
        await userEvent.upload(screen.getByLabelText('إيصال الدفع'), file);

        expect(onUpload).toHaveBeenCalledTimes(1);
        expect(onUpload.mock.calls[0][0].name).toBe('إيصال.pdf');
    });
});
