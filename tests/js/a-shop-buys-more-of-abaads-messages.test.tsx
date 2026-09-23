import { router } from '@inertiajs/react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import WhatsappQuotaPack, { type QuotaPack } from '@/Pages/Admin/Integrations/partials/WhatsappQuotaPack';

/**
 * ما يراه التاجر حين تنفد رسائلُه.
 *
 * ═══ ولمَ تُحرَس الحالاتُ الثلاثُ بأعيانها ═══
 *
 * «لا طلبَ لك» غيرُ «طلبُك عندنا» غيرُ «فاتورتُك صدرت». وفي الثالثة وحدها
 * على التاجر فعلٌ — أن يُحوّل. فلو جُمعت الثانيةُ والثالثةُ في «قيد
 * المعالجة» لَجلس ينتظر فاتورةً وصلته، أو حوّل مرّتين.
 *
 * وأشدُّ ما يُحرَس أن **لا يظهر زرُّ الطلب مرّتين**: طلبان يعنيان فاتورتين،
 * وفاتورتان تعنيان مكالمةً مع تاجرٍ يسأل لمَ طُولب بعشرة وقد طلب خمسة.
 */

const pack = (over: Partial<QuotaPack> = {}): QuotaPack => ({
    size: 500,
    price: 5,
    status: null,
    invoice_number: null,
    requested_at: null,
    ...over,
});

beforeEach(() => {
    vi.mocked(router.post).mockClear();
});

describe('حين لا طلبَ مفتوح', () => {
    it('يعرض الحزمة بحجمها وثمنها', () => {
        render(<WhatsappQuotaPack pack={pack()} exhausted={false} mayManage />);

        expect(screen.getByText(/500/)).toBeInTheDocument();
        // والثمنُ بثلاث خاناتٍ كما يُكتب الريال — لا «5»
        expect(screen.getByText(/5\.000/)).toBeInTheDocument();
    });

    /* ومن لم تنفد حصّتُه يُقال له ما يقع لاحقًا، لا «نفدت» */
    it('ولا يقول «نفدت» لمن لم تنفد', () => {
        render(<WhatsappQuotaPack pack={pack()} exhausted={false} mayManage />);

        expect(screen.queryByText(/نفدت رسائل هذا الشهر/)).not.toBeInTheDocument();
        expect(screen.getByText(/ولا تسقط بقيّتُها آخر الشهر/)).toBeInTheDocument();
    });

    it('ومن نفدت حصّتُه يقرأ أنّ الطلبات تعمل ولا رسالةَ تخرج', () => {
        render(<WhatsappQuotaPack pack={pack()} exhausted mayManage />);

        expect(screen.getByText(/الطلبات تعمل كالمعتاد، ولا تخرج رسالة/)).toBeInTheDocument();
    });

    it('والزرُّ يقصد مسار الطلب لا غيره', async () => {
        render(<WhatsappQuotaPack pack={pack()} exhausted mayManage />);

        await userEvent.click(screen.getByRole('button', { name: /اطلب حزمة رسائل/ }));

        expect(router.post).toHaveBeenCalledTimes(1);
        expect(vi.mocked(router.post).mock.calls[0][0]).toContain('admin.integrations.whatsapp.packs.request');
    });

    /*
     * ولا يظهر الزرّ لمن لا يملك الالتزام بمال.
     *
     * والخادمُ يحرس نفسه (انظر `requestPack`) — لكنّ زرًّا يُعرض ثمّ يُردّ
     * بخطأٍ يجعل الكاشير يظنّ العطبَ في النظام فيعيد ويعيد.
     */
    it('ولا زرَّ لمن لا يملك الطلب', () => {
        render(<WhatsappQuotaPack pack={pack()} exhausted mayManage={false} />);

        expect(screen.queryByRole('button')).not.toBeInTheDocument();
        // والعرضُ يبقى مقروءًا: يعرف أنّ الحلّ موجودٌ ومن يطلبه
        expect(screen.getByText(/500/)).toBeInTheDocument();
    });
});

describe('وحين له طلبٌ مفتوح', () => {
    it('«مطلوبة» تقول إنّ لا شيء عليه الآن — ولا زرَّ ثانٍ', () => {
        render(<WhatsappQuotaPack pack={pack({ status: 'مطلوبة' })} exhausted mayManage />);

        expect(screen.getByText(/ولا شيء عليك الآن/)).toBeInTheDocument();
        expect(screen.queryByRole('button')).not.toBeInTheDocument();
    });

    it('و«مصدَّرة» تقول رقمَ فاتورته — ولا زرَّ ثانٍ', () => {
        render(
            <WhatsappQuotaPack
                pack={pack({ status: 'مصدَّرة', invoice_number: 'INV-P-0007' })}
                exhausted
                mayManage
            />,
        );

        expect(screen.getByText(/INV-P-0007/)).toBeInTheDocument();
        expect(screen.queryByRole('button')).not.toBeInTheDocument();
    });

    /* وفاتورةٌ بلا رقمٍ لا تطبع «null» في وجه التاجر */
    it('وبلا رقمٍ تُكتب شرطةً لا «null»', () => {
        render(<WhatsappQuotaPack pack={pack({ status: 'مصدَّرة' })} exhausted mayManage />);

        expect(screen.queryByText(/null/)).not.toBeInTheDocument();
        expect(screen.getByText(/—/)).toBeInTheDocument();
    });
});
