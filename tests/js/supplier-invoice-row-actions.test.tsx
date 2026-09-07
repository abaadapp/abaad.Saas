import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { InvoiceRowActions } from '@/Pages/Admin/Purchases/Invoices';

/**
 * أزرارُ صفّ سند المورّد — أربعةٌ لكلٍّ شرطُه.
 *
 * وخطأُ هذه الأزرار لا تراه اختباراتُ الخادم: زرٌّ يُرسم لمن يردّه الخادمُ
 * عند ضغطه يمرّ من كلّ اختبارٍ يسأل الخادم وحدَه. وكان «حذف» يُرسم على سندٍ
 * معتمَد — والخادمُ يردّه بـ«سندٌ معتمَد لا يُحذف».
 */

const ALL = { approve: true, reject: true, create: true, pay: true };

// ‏سندٌ بأقلّ ما يلزم لرسم أزراره — والباقي لا تقرؤه هذه الخانة
const invoice = (over: Record<string, unknown> = {}) =>
    ({
        id: 4,
        reference: 'S-1001',
        approval_status: 'بانتظار الاعتماد',
        paid: 0,
        outstanding: 900,
        ...over,
    }) as never;

/** أسماءُ ما رُسم من أزرار — الترتيبُ لا يعني شيئًا هنا */
const drawn = () => screen.getAllByRole('button').map((b) => b.getAttribute('aria-label') ?? b.textContent);

function draw(inv: Record<string, unknown>, may = ALL) {
    const on = { onReview: vi.fn(), onPay: vi.fn(), onCancel: vi.fn(), onDelete: vi.fn() };
    render(<InvoiceRowActions invoice={invoice(inv)} may={may} {...on} />);

    return { ...on, user: userEvent.setup() };
}

describe('سندٌ ينتظر الاعتماد', () => {
    it('يُراجَع ويُحذف — ولا يُسدَّد ولا يُلغى', () => {
        draw({});

        expect(drawn()).toEqual(['مراجعة', 'حذف']);
    });

    it('و«مراجعة» تفتح نافذة القرار', async () => {
        const { onReview, user } = draw({});
        await user.click(screen.getByRole('button', { name: 'مراجعة' }));

        expect(onReview).toHaveBeenCalledTimes(1);
    });

    it('ولا «مراجعة» لمن لا يعتمد ولا يرفض', () => {
        draw({}, { ...ALL, approve: false, reject: false });

        expect(screen.queryByRole('button', { name: 'مراجعة' })).not.toBeInTheDocument();
    });

    it('ويكفي أن يملك الرفضَ وحده ليراجع', () => {
        draw({}, { ...ALL, approve: false, reject: true });

        expect(screen.getByRole('button', { name: 'مراجعة' })).toBeInTheDocument();
    });
});

describe('سندٌ معتمَد لم يُدفع منه شيء', () => {
    const approved = { approval_status: 'معتمد', paid: 0, outstanding: 900 };

    /* وهذا هو المقبضُ الذي كان غائبًا: المسارُ قائمٌ ولا زرَّ له */
    it('يُسدَّد ويُلغى', () => {
        draw(approved);

        expect(drawn()).toEqual(['سداد', 'إلغاء']);
    });

    /* «سندٌ معتمَد لا يُحذف — أَلغِه فيُعكس قيدُه» يقولها الخادم */
    it('ولا يُعرض عليه «حذف» — والخادمُ يردّه', () => {
        draw(approved);

        expect(screen.queryByRole('button', { name: 'حذف' })).not.toBeInTheDocument();
    });

    it('و«إلغاء» تفتح نافذة السبب', async () => {
        const { onCancel, user } = draw(approved);
        await user.click(screen.getByRole('button', { name: 'إلغاء' }));

        expect(onCancel).toHaveBeenCalledTimes(1);
    });

    it('ولا «إلغاء» لمن لا يملك الاعتماد', () => {
        draw(approved, { ...ALL, approve: false });

        expect(screen.queryByRole('button', { name: 'إلغاء' })).not.toBeInTheDocument();
    });

    it('ولا «سداد» لمن لا يملك السداد', () => {
        draw(approved, { ...ALL, pay: false });

        expect(screen.queryByRole('button', { name: 'سداد' })).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'إلغاء' })).toBeInTheDocument();
    });
});

describe('سندٌ خرج مقابله مال', () => {
    /*
     * عكسُ الذمّة وحدها يترك قيدَ السداد يتيمًا: نقدٌ خرج مقابل دَينٍ لا
     * وجود له. و`SupplierInvoices::cancel` ترفضه — فلا يُعرض بابُه.
     */
    it('لا يُلغى ولو كان معتمَدًا', () => {
        draw({ approval_status: 'معتمد', paid: 500, outstanding: 400 });

        expect(screen.queryByRole('button', { name: 'إلغاء' })).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'سداد' })).toBeInTheDocument();
    });

    it('ولا يُحذف ولو لم يُعتمد', () => {
        draw({ approval_status: 'بانتظار الاعتماد', paid: 500, outstanding: 400 });

        expect(screen.queryByRole('button', { name: 'حذف' })).not.toBeInTheDocument();
    });
});

describe('سندٌ سُدّد كاملًا', () => {
    it('لا يُعرض عليه «سداد» — ولا باقيَ عليه', () => {
        draw({ approval_status: 'معتمد', paid: 900, outstanding: 0 });

        expect(screen.queryByRole('button', { name: 'سداد' })).not.toBeInTheDocument();
    });
});

describe('سندٌ مرفوض', () => {
    it('يُحذف ولا يُلغى: الإلغاءُ نقضٌ لتوقيعٍ لم يقع', () => {
        draw({ approval_status: 'مرفوض', paid: 0, outstanding: 0 });

        expect(drawn()).toEqual(['حذف']);
    });
});

describe('الإلغاءُ والحذفُ لا يجتمعان', () => {
    /* أحدُهما لما قبل التوقيع والآخر لما بعده — ولا حالَ ثالثة */
    it('في كلّ الحالات', () => {
        for (const status of ['بانتظار الاعتماد', 'معتمد', 'مرفوض', 'ملغاة']) {
            for (const paid of [0, 500]) {
                const { unmount } = render(
                    <InvoiceRowActions
                        invoice={invoice({ approval_status: status, paid, outstanding: 900 - paid })}
                        may={ALL}
                        onReview={vi.fn()}
                        onPay={vi.fn()}
                        onCancel={vi.fn()}
                        onDelete={vi.fn()}
                    />,
                );

                const both =
                    screen.queryByRole('button', { name: 'إلغاء' }) &&
                    screen.queryByRole('button', { name: 'حذف' });

                expect(both, `${status} / ${paid}`).toBeNull();
                unmount();
            }
        }
    });
});
